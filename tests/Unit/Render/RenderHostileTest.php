<?php

declare(strict_types=1);

use FinePrint\Exceptions\RefusedException;
use FinePrint\Limits;
use FinePrint\Render\Renderer;
use FinePrint\Tests\Support\PdfBuilder;

/*
 * Pages built to hurt a renderer. Each ends cleanly: refused with a stable
 * reason, or drawn within budget with what was cut listed. Never a fatal
 * error, a hang, or an allocation the size of the attacker's choosing.
 */

function fpRenderRefusal(callable $fn): ?string
{
    try {
        $fn();
    } catch (RefusedException $e) {
        return $e->reason;
    }

    return null;
}

/** Run, returning [seconds, bytes of peak memory growth, result-or-reason]. */
function fpMeasure(callable $fn): array
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $base = memory_get_usage();
    $t = hrtime(true);
    $reason = fpRenderRefusal(function () use ($fn, &$out) {
        $out = $fn();
    });

    return [(hrtime(true) - $t) / 1e9, memory_get_peak_usage() - $base, $reason ?? $out];
}

it('refuses a huge MediaBox before allocating anything', function () {
    $pdf = fprPage('0 0 10 10 re f', '[0 0 14400000 14400000]');
    [$s, $mem, $out] = fpMeasure(fn () => Renderer::render($pdf, 1, 1.0));

    expect($out)->toBe('too_many_pixels')
        ->and($s)->toBeLessThan(1.0)
        ->and($mem)->toBeLessThan(8 * 1024 * 1024);
});

it('refuses a scale that would blow the pixel cap', function () {
    $pdf = fprPage('', '[0 0 612 792]');

    expect(fpRenderRefusal(fn () => Renderer::render($pdf, 1, 100.0)))->toBe('too_many_pixels')
        ->and(fpRenderRefusal(fn () => Renderer::render($pdf, 1, 2.0, ['maxRenderPixels' => 100_000])))->toBe('too_many_pixels')
        ->and(Renderer::render($pdf, 1, 0.5, ['maxRenderPixels' => 200_000])->width)->toBe(306);
});

it('refuses a flat stream with more operators than the cap', function () {
    // 300,000 path operators against a cap of 100,000. (The default cap is
    // 2,000,000; a smaller one keeps the test quick and proves the same bound.)
    $content = '';
    for ($i = 0; $i < 300_000; $i++) {
        $content .= ($i % 97).' '.($i % 89)." m\n";
    }
    $b = PdfBuilder::textPdf(['placeholder'], ['mediaBox' => '[0 0 100 100]']);
    $b->stream($content, '', false, 4);
    [$s, , $out] = fpMeasure(fn () => Renderer::render($b->build(), 1, 1.0, ['maxOperators' => 100_000]));

    expect($out)->toBe('too_many_operators')
        ->and($s)->toBeLessThan(5.0);
});

it('refuses a billion-operator page built from fanned-out forms', function () {
    // Nine levels of forms, each drawing the next ten times: 10^8 leaves and
    // over 10^9 operators nominally, from a file of a few hundred bytes. The
    // leaf builds a path and discards it, so the cap is what stops it rather
    // than the time budget (that has its own test below).
    $b = PdfBuilder::textPdf(['/X0 Do'], ['mediaBox' => '[0 0 100 100]', 'resources' => '/XObject << /X0 6 0 R >>']);
    for ($level = 0; $level < 9; $level++) {
        $next = 6 + $level + 1;
        $body = $level < 8
            ? str_repeat('/X Do ', 10)
            : '0 0 m 1 1 l 2 0 l 3 1 l 4 0 l 5 1 l 6 0 l 7 1 l 8 0 l 9 1 l n';
        $b->stream($body, '/Type /XObject /Subtype /Form /BBox [0 0 100 100]'.($level < 8 ? ' /Resources << /XObject << /X '.$next.' 0 R >> >>' : ''));
    }
    [$s, , $out] = fpMeasure(fn () => Renderer::render($b->build(), 1, 1.0));

    expect($out)->toBe('too_many_operators')
        ->and($s)->toBeLessThan(30.0);
});

it('stops a deep chain of nested forms at the depth limit and reports it', function () {
    $depth = 200;
    $b = PdfBuilder::textPdf(['/X Do'], ['mediaBox' => '[0 0 100 100]', 'resources' => '/XObject << /X 6 0 R >>']);
    for ($i = 0; $i < $depth; $i++) {
        $b->stream('0 0 1 1 re f /X Do', '/Type /XObject /Subtype /Form /BBox [0 0 100 100] /Resources << /XObject << /X '.(7 + $i).' 0 R >> >>');
    }
    [$s, , $r] = fpMeasure(fn () => Renderer::render($b->build(), 1, 1.0));

    $skipped = array_column($r->skipped, 'count', 'kind');
    expect($s)->toBeLessThan(5.0)
        ->and($skipped['form-depth'] ?? 0)->toBe(1)
        ->and($r->warnings)->not->toBe([]);
});

it('does not recurse into a form that draws itself', function () {
    $b = PdfBuilder::textPdf(['/X Do'], ['mediaBox' => '[0 0 100 100]', 'resources' => '/XObject << /X 6 0 R >>']);
    $b->stream('0 0 1 1 re f /X Do', '/Type /XObject /Subtype /Form /BBox [0 0 100 100] /Resources << /XObject << /X 6 0 R >> >>');
    [$s, , $r] = fpMeasure(fn () => Renderer::render($b->build(), 1, 1.0));

    // Caught as a cycle on the first repeat, not merely by the depth cap twelve levels later.
    expect($s)->toBeLessThan(2.0)
        ->and(array_column($r->skipped, 'count', 'kind'))->toBe(['form-depth' => 1])
        ->and(implode("\n", $r->warnings))->toContain('draws itself')
        ->and(implode("\n", $r->warnings))->not->toContain('nest deeper');
});

it('refuses a page that outruns the render time budget', function () {
    // 20,000 full-page fills at scale 2: legitimate operators, too much work.
    $content = str_repeat("0 0 612 792 re f\n", 20_000);
    $pdf = fprPage($content, '[0 0 612 792]');
    [$s, , $out] = fpMeasure(fn () => Renderer::render($pdf, 1, 2.0, ['renderTimeSeconds' => 0.5]));

    expect($out)->toBe('time_budget')
        ->and($s)->toBeLessThan(3.0);
});

it('survives absurd and non-finite numbers in paths and matrices', function () {
    $content = "1e400 0 0 1e400 0 0 cm 0 0 10 10 re f\n"
        ."Q q 1 0 0 1 0 0 cm 99999999999999999999999 -99999999999999999999 m 1 1 l 5 5 l h f\n"
        ."0 0 99999999999999999999 99999999999999999999 re S\n"
        ."0.000000000000000001 0 0 0.000000000000000001 0 0 cm 0 0 10 10 re f\n";
    [$s, , $r] = fpMeasure(fn () => Renderer::render(fprPage($content), 1, 1.0));

    expect($s)->toBeLessThan(5.0)
        ->and($r->width)->toBe(100);
});

it('keeps a hostile dash and a huge line width within budget', function () {
    $content = "[0.000001 0.000001] 0 d 1 w 0 50 m 100 50 l S\n"
        ."[] 0 d 100000000 w 0 0 m 100 100 l S\n"
        ."[1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1] 0 d 0 0 m 100 0 l S\n";
    [$s, , $r] = fpMeasure(fn () => Renderer::render(fprPage($content), 1, 1.0));

    expect($s)->toBeLessThan(5.0)
        ->and($r->pixel(50, 50))->toBe([0, 0, 0]);
});

it('keeps a page with a million-segment path inside budget', function () {
    $content = '0 0 m '.str_repeat('100 100 l 0 1 l ', 300_000).'f';
    [$s, , $out] = fpMeasure(fn () => Renderer::render(fprPage($content), 1, 1.0, ['renderTimeSeconds' => 5.0]));

    // Either drawn or refused for time, but cleanly and on time.
    expect($s)->toBeLessThan(10.0)
        ->and(is_string($out) ? $out : 'drawn')->toBeIn(['drawn', 'time_budget', 'memory_budget']);
});

it('refuses cleanly when the file itself is refused', function () {
    expect(fpRenderRefusal(fn () => Renderer::render('not a pdf at all', 1, 1.0)))->toBe('not_pdf');
});

it('validates its new limits like the old ones', function () {
    expect(Limits::from(['maxRenderPixels' => 10, 'renderTimeSeconds' => 1.5]))->toBeInstanceOf(Limits::class)
        ->and(in_array('too_many_pixels', RefusedException::REASONS, true))->toBeTrue();
});
