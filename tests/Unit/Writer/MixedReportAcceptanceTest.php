<?php

declare(strict_types=1);

use FinePrint\Agent;
use FinePrint\Render\Renderer;
use FinePrint\Tests\Support\MixedReportSample;

/*
 * The acceptance document, through the surface a host calls: validate, lint and
 * write, then read back with this package's own reader. All of its data is invented.
 *
 * Text through a real host font needs DejaVu on the machine (the `local` group);
 * everything else runs anywhere, with that one run's font removed.
 */

/** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
function fpoAcceptance(): array
{
    if (is_file('C:/Windows/Fonts/DejaVuSans.ttf')) {
        return [MixedReportSample::doc(), ['fonts' => [MixedReportSample::FONT => MixedReportSample::fontFaces()]]];
    }

    return [MixedReportSample::doc(), []];
}

function fpoAcceptancePdf(): string
{
    static $pdf = null;
    if ($pdf === null) {
        [$doc, $options] = fpoAcceptance();
        $pdf = Agent::toBytes($doc, $options);
    }

    return $pdf;
}

it('validates clean, and lint has no error or warning to report', function () {
    [$doc, $options] = fpoAcceptance();

    expect(Agent::validate($doc))->toBe([]);
    $serious = array_values(array_filter(Agent::lint($doc, $options), fn (array $f) => in_array($f['severity'], ['error', 'warning'], true) && $f['rule'] !== 'sparse-page' && $f['rule'] !== 'font-substituted' && $f['rule'] !== 'unencodable'));

    expect($serious)->toBe([]);
});

it('write returns the path, the page count and what it could not honour', function () {
    [$doc, $options] = fpoAcceptance();
    $path = tempnam(sys_get_temp_dir(), 'fpo');
    try {
        $out = Agent::write($doc, $path, $options);
    } finally {
        unlink($path);
    }

    expect($out['pages'])->toBeGreaterThan(6)
        ->and(array_unique(array_column($out['warnings'], 'rule')))->toContain('image-alt');
});

it('reads back portrait and landscape pages, at their own margins', function () {
    $pdf = fpoAcceptancePdf();
    $pages = fpwRead($pdf)['pages'];
    $shapes = array_map(fn (array $p) => (int) $p['width'] === 792 ? 'L' : 'P', $pages);

    expect(implode('', $shapes))->toMatch('/^P+L+P+L+$/');
    foreach ($pages as $i => $p) {
        $left = $shapes[$i] === 'L' ? 28.8 : 43.2;
        // The header sits on the margin of its own page; nothing is left of it.
        $runs = fpwRuns($pdf, $i + 1);
        expect(min(array_column($runs, 'x0')))->toBeGreaterThanOrEqual($left - 0.01)
            ->and(max(array_column($runs, 'x1')))->toBeLessThanOrEqual($p['width'] - $left + 0.01);
    }
});

it('has no running header on the cover and the same header everywhere else', function () {
    $pages = fpwRead(fpoAcceptancePdf())['pages'];

    expect($pages[0]['text'])->not->toContain('Well 7-Alpha ·');
    foreach (array_slice($pages, 1) as $p) {
        expect($p['text'])->toContain('Well 7-Alpha ·');
    }
});

it('resolves "Page N" in the footer table cell on every page, and drops the centre on the cover', function () {
    $pages = fpwRead(fpoAcceptancePdf())['pages'];

    foreach ($pages as $i => $p) {
        expect($p['text'])->toContain('Page '.($i + 1))->not->toContain('{page');
    }
    expect($pages[0]['text'])->not->toContain('Field Performance Report Page');
    expect(substr_count($pages[0]['text'], 'Field Performance Report'))->toBe(1);
    expect($pages[1]['text'])->toContain('Field Performance Report');
});

it('draws the logo in the footer on every page', function () {
    $pdf = fpoAcceptancePdf();
    foreach (fpwRead($pdf)['pages'] as $i => $p) {
        $left = (int) $p['width'] === 792 ? 28.8 : 43.2;
        $r = Renderer::render($pdf, $i + 1);
        // 4pt into the logo, along its height, 10pt + 8pt spacing + half the 18pt logo up from the edge.
        expect($r->pixel((int) ($left + 8), (int) ($p['height'] - 27)))->toBe([24, 72, 140]);
    }
});

it('repeats the 17-column table\'s two header rows on each landscape page', function () {
    $pages = fpwRead(fpoAcceptancePdf())['pages'];
    $landscape = array_values(array_filter($pages, fn (array $p) => (int) $p['width'] === 792));

    expect(count($landscape))->toBeGreaterThanOrEqual(3);
    foreach ($landscape as $p) {
        expect($p['text'])->toContain('Production')->toContain('Pressure (psi)')->toContain('Out')->toContain('Amb');
    }
});

it('keeps each heading with its chart and caption', function () {
    $pages = fpwRead(fpoAcceptancePdf())['pages'];
    foreach (range(1, 7) as $n) {
        $hits = array_values(array_filter($pages, fn (array $p) => str_contains($p['text'], "Figure {$n}:")));

        expect($hits)->toHaveCount(1)
            ->and(substr_count($hits[0]['text'], "Figure {$n}:"))->toBe(2);
    }
    [$doc, $options] = fpoAcceptance();
    expect(array_column(Agent::lint($doc, $options), 'rule'))->not->toContain('stranded-heading');
});

it('moves a result card whole, with no keep word', function () {
    $pages = fpwRead(fpoAcceptancePdf())['pages'];

    foreach (['output uplift' => '+148 units', 'efficiency' => '0.82 ratio', 'peak pressure' => '1,040 psi'] as $label => $value) {
        $hit = array_values(array_filter($pages, fn (array $p) => str_contains($p['text'], "Result: {$label}")));
        expect($hit)->toHaveCount(1)
            ->and($hit[0]['text'])->toContain($value);
    }
});

it('prints delta, >= and an arrow through the registered font', function () {
    if (! is_file('C:/Windows/Fonts/DejaVuSans.ttf')) {
        $this->markTestSkipped('DejaVuSans is not installed here');
    }
    expect(fpwRead(fpoAcceptancePdf())['pages'][1]['text'])->toContain('Δ output ≥ 12% → the target was met');
})->group('local');

it('places every footnote at the foot of the page holding its mark, across a landscape stretch', function () {
    $pdf = fpoAcceptancePdf();
    $pages = fpwRead($pdf)['pages'];

    expect($pages[1]['text'])->toContain('Rates are allocated from the test meter');
    $landscape = array_values(array_keys(array_filter($pages, fn (array $p) => (int) $p['width'] === 792)));
    expect($pages[$landscape[0]]['text'])->toContain('Ambient reading taken from the nearest station');
    // The appendix's table marks the same row: its own number, its own foot.
    $last = array_key_last($pages);
    expect($pages[$last]['text'])->toContain('Ambient reading taken from the nearest station');
    $numbers = array_values(array_filter(fpwRuns($pdf, $last + 1), fn ($r) => abs($r['size'] - 6.5) < 0.01));
    expect(trim($numbers[0]['text']))->toBe('4');
});

it('is deterministic', function () {
    [$doc, $options] = fpoAcceptance();

    expect(Agent::toBytes($doc, $options))->toBe(fpoAcceptancePdf());
});
