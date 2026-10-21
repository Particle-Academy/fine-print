<?php

declare(strict_types=1);

use FinePrint\Render\PngEncoder;
use FinePrint\Render\Renderer;
use FinePrint\Tests\Support\ImageDiff;
use FinePrint\Tests\Support\PngReader;
use FinePrint\Tests\Support\RenderFixtures;

/*
 * Golden comparisons against Chromium (PDFium), the reference P2 already found
 * renders PDF bytes exactly. Chromium is a TEST ORACLE only: the goldens were
 * rendered once by tests/oracle and committed, so this suite needs no browser.
 *
 * Tolerances are fixed in advance rather than fitted to the result: mean
 * absolute error at most 2.0 per channel (of 255), and at most 1% of pixels
 * differing by more than 64 in any channel. Anti-aliasing differs at every
 * edge between any two rasterisers, so zero is not the target; a shape in the
 * wrong place, the wrong width or the wrong colour blows through both.
 *
 * The numbers for every golden are written to tests/.out/golden-report.json,
 * with a diff image per golden beside it.
 */

const FP_GOLDEN_MAE = 2.0;
const FP_GOLDEN_MISMATCH = 0.01;

/** @return array{mae: float, mismatch: float, max: int, inkMismatch: float, pixels: int} */
function fpGolden(string $name, int $scale): array
{
    $dir = dirname(__DIR__, 2).'/golden';
    $pdf = RenderFixtures::pdf($name);
    expect(file_exists("{$dir}/{$name}.pdf"))->toBeTrue("{$name}.pdf is not committed")
        ->and(hash('sha256', $pdf))->toBe(hash_file('sha256', "{$dir}/{$name}.pdf"), "{$name}: the fixture no longer matches its committed PDF; regenerate the golden");

    $golden = PngReader::read((string) file_get_contents("{$dir}/{$name}@{$scale}x.png"));
    $r = Renderer::render($pdf, 1, (float) $scale);
    expect([$r->width, $r->height])->toBe([$golden['width'], $golden['height']]);

    $d = ImageDiff::compare($r->rows(), $golden['rgb'], $r->width, $r->height);

    $out = dirname(__DIR__, 2).'/.out';
    is_dir($out) || mkdir($out, 0777, true);
    file_put_contents("{$out}/{$name}@{$scale}x.ours.png", $r->png());
    file_put_contents("{$out}/{$name}@{$scale}x.diff.png", PngEncoder::encode($r->width, $r->height, $d['diff']));
    $reportFile = "{$out}/golden-report.json";
    $report = is_file($reportFile) ? (json_decode((string) file_get_contents($reportFile), true) ?: []) : [];
    unset($d['diff']);
    $report["{$name}@{$scale}x"] = $d + ['skipped' => $r->skipped];
    ksort($report);
    file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));

    return $d;
}

$cases = [];
foreach (RenderFixtures::SCALES as $name => $scales) {
    foreach ($scales as $s) {
        if ($name !== 'cmyk') {
            $cases["{$name}@{$s}x"] = [$name, $s];
        }
    }
}

it('matches Chromium within tolerance', function (string $name, int $scale) {
    $d = fpGolden($name, $scale);

    expect($d['mae'])->toBeLessThanOrEqual(FP_GOLDEN_MAE, sprintf('%s@%dx MAE %.3f', $name, $scale, $d['mae']))
        ->and($d['mismatch'])->toBeLessThanOrEqual(FP_GOLDEN_MISMATCH, sprintf('%s@%dx mismatched %.3f%%', $name, $scale, $d['mismatch'] * 100));
})->with($cases);

it('matches Chromium\'s geometry for CMYK, whose colours are expected to differ', function () {
    // Our CMYK is the naive formula (plan, P3); PDFium approximates a press
    // profile. Shapes must agree; colour error is reported, not asserted.
    $d = fpGolden('cmyk', 1);

    expect($d['inkMismatch'])->toBeLessThanOrEqual(FP_GOLDEN_MISMATCH);
});

it('rejects a wrong render: the comparison has teeth', function () {
    // A positive control for the instrument: shift our render by three pixels
    // and the same metric must fail, or the tolerances prove nothing.
    $golden = PngReader::read((string) file_get_contents(dirname(__DIR__, 2).'/golden/rects@1x.png'));
    $r = Renderer::render(RenderFixtures::pdf('rects'), 1, 1.0);
    $shifted = array_map(fn (string $row) => str_repeat("\xFF", 9).substr($row, 0, -9), $r->rows());
    $d = ImageDiff::compare($shifted, $golden['rgb'], $r->width, $r->height);

    expect($d['mismatch'] > FP_GOLDEN_MISMATCH || $d['mae'] > FP_GOLDEN_MAE)->toBeTrue();
});
