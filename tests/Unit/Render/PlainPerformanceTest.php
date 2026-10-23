<?php

declare(strict_types=1);

use FinePrint\Budget;
use FinePrint\Exceptions\RefusedException;
use FinePrint\Limits;
use FinePrint\Render\Raster;
use FinePrint\Render\Rasterizer;
use FinePrint\Render\Renderer;
use FinePrint\Tests\Support\PdfBuilder;
use FinePrint\Tests\Support\PlainFixtures;

it('keeps opaque fixture pixels byte-identical to 0.2.0 without alpha or offscreen planes', function (string $name, string $hash) {
    // Exactly the page area: any transient offscreen/shape canvas must refuse.
    $r = Renderer::render(PlainFixtures::pdf($name), limits: ['maxRenderPixels' => 612 * 792]);
    $rgb = hash_init('sha256');
    foreach ($r->rows() as $row) {
        hash_update($rgb, $row);
    }
    expect($r->complete())->toBeTrue()->and(hash_final($rgb))->toBe($hash);
    $raster = (new ReflectionProperty($r, 'raster'))->getValue($r);
    expect($raster->surface)->toBeNull()
        ->and((new ReflectionProperty($raster, 'alphaRows'))->getValue($raster))->toBeNull();
    // Pixel accounting alone cannot see a hidden alpha plane on the page.
    // Inspecting the storage is intentional: this is the deterministic guard.
})->with(array_map(static fn ($name, $hash) => [$name, $hash], array_keys(PlainFixtures::RGB_020), array_values(PlainFixtures::RGB_020)));

/** Same rasteriser and span workload, driven directly without PDF interpretation. */
function fpPlainReference(): array
{
    $budget = new Budget(new Limits);
    $rows = array_fill(0, 792, str_repeat("\xFF", 612 * 3));
    foreach (PlainFixtures::rectangles() as [$x, $y, $w, $h, $r, $g, $b]) {
        $shape = new Rasterizer(612, 792, $budget);
        $shape->addFlat([$x, 792 - $y, $x + $w, 792 - $y, $x + $w, 792 - $y - $h, $x, 792 - $y - $h]);
        [$red, $green, $blue] = [(int) round($r * 255), (int) round($g * 255), (int) round($b * 255)];
        foreach ($shape->spans(false) as [$row, $left, $len, $coverage]) {
            // Independent v0.1-style RGB kernel. Integer rectangles have full
            // coverage; it deliberately does not use production Raster, whose
            // bookkeeping was part of the regression being measured.
            $rows[$row] = substr_replace($rows[$row], str_repeat(chr($red).chr($green).chr($blue), $len), $left * 3, $len * 3);
        }
    }

    return $rows;
}

it('keeps a plain page within a ratio budget against an in-run raster reference', function () {
    // Fixed BEFORE the optimisation: generous 75% overhead for parsing, page
    // mapping and interpretation above the identical direct raster workload.
    // No absolute milliseconds or git checkout/history is required in CI.
    $limit = 1.75;
    $pdf = PlainFixtures::pdf('opaque-blocks');
    $actual = Renderer::render($pdf);
    $reference = fpPlainReference();
    expect($actual->rows())->toBe($reference);
    $time = static function (callable $render): float {
        $start = hrtime(true);
        $value = $render();
        $elapsed = (hrtime(true) - $start) / 1e6;
        unset($value);

        return $elapsed;
    };
    $times = $refs = [];
    for ($i = 0; $i < 5; $i++) {
        $refs[] = $time(static fn () => fpPlainReference());
        $times[] = $time(static fn () => Renderer::render($pdf));
    }
    sort($times);
    sort($refs);
    $ratio = $times[2] / $refs[2];
    $dir = dirname(__DIR__, 2).'/.out';
    is_dir($dir) || mkdir($dir, 0777, true);
    file_put_contents($dir.'/plain-performance.json', json_encode(['render_ms' => $times[2], 'reference_ms' => $refs[2], 'ratio' => $ratio, 'limit' => $limit], JSON_PRETTY_PRINT));
    expect($ratio)->toBeLessThan($limit, sprintf('plain %.2fms / reference %.2fms = %.2fx', $times[2], $refs[2], $ratio));
});

it('replays earlier normal opacity when a late blend needs true page alpha', function () {
    $pdf = fprPage('/H gs 0 0 1 rg 0 0 50 100 re f /S gs 1 0 0 rg 0 0 100 100 re f', resources: '/ExtGState << /H << /ca 0.4 >> /S << /BM /Screen /ca 0.5 >> >>');
    $r = Renderer::render($pdf);
    expect($r->complete())->toBeTrue()->and($r->pixel(25, 50))->toBe([204, 77, 179])
        ->and($r->pixel(75, 50))->toBe([255, 128, 128]);
});

it('counts the discarded prefix against the same operator budget', function () {
    $pdf = fprPage(str_repeat('q Q ', 4).'/S gs 1 0 0 rg 0 0 100 100 re f', resources: '/ExtGState << /S << /BM /Screen >> >>');
    try {
        Renderer::render($pdf, limits: ['maxOperators' => 20]);
        test()->fail('The 12-operator prefix plus replay must exceed the 20-operator cap.');
    } catch (RefusedException $e) {
        expect($e->reason)->toBe('too_many_operators');
    }
});

it('keeps the 0.2.0 image component clamping in the plain path', function (string $space, string $data, string $decode, array $expected) {
    $pdf = fprPage('100 0 0 100 0 0 cm /Im Do', resources: '/XObject << /Im 6 0 R >>', extra: fn (PdfBuilder $b) => $b->stream($data, '/Subtype /Image /Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace '.$space.' /Decode '.$decode));
    expect(Renderer::render($pdf)->pixel(50, 50))->toBe($expected);
})->with([
    'CMYK Decode below zero' => ['/DeviceCMYK', "\0\x80\xFF\x80", '[-1 1 0 1 0 1 0 1]', [127, 63, 0]],
    'tint Decode above one' => ['[/Separation /Spot /DeviceCMYK << /FunctionType 2 /Domain [0 2] /C0 [0 0 0 0] /C1 [0.5 0 0 0] /N 1 >>]', "\xFF", '[0 2]', [128, 255, 255]],
]);

it('keeps the 0.2.0 stencil fill component clamping in the plain path', function () {
    $pdf = fprPage('-1 0.5 1 0.5 k 100 0 0 100 0 0 cm /Im Do', resources: '/XObject << /Im 6 0 R >>', extra: fn (PdfBuilder $b) => $b->stream("\0", '/Subtype /Image /Width 1 /Height 1 /BitsPerComponent 1 /ImageMask true'));
    expect(Renderer::render($pdf)->pixel(50, 50))->toBe([128, 64, 0]);
});
