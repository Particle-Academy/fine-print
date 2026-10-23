<?php

declare(strict_types=1);

/*
 * Golden oracle, step 1 of 3. A TEST ORACLE ONLY: nothing here is part of the
 * package, and the suite never runs it. It needs Chromium + playwright-core
 * installed on the machine; the committed goldens mean nobody else does.
 *
 *   php tests/oracle/build.php                 writes tests/golden/*.pdf and the job list
 *   PLAYWRIGHT_CORE=<dir> CHROMIUM=<exe> node tests/oracle/chromium.cjs
 *   php tests/oracle/crop.php                  calibrates and writes tests/golden/*@Nx.png
 *
 * Chromium's PDF viewer (PDFium) is driven at zoom 150 with device scale 1,
 * where one PDF point is EXACTLY two device pixels in both axes (measured
 * 2026-10-21 with anti-aliased edges at 10 and 190 pt: 1.00000 x and y).
 * Zoom 75 is NOT exact: it stretches the page 0.5% horizontally (a 200 pt
 * page draws 201 px wide) and sits 0.043 px off the pixel grid, so a
 * "scale 1" golden taken there compares us with a different transform. The
 * scale-1 goldens are therefore Chromium's exact scale-2 render averaged 2 x 2,
 * which for exact-area anti-aliasing IS the scale-1 coverage.
 *
 * Where the page lands in the screenshot is measured from a CALIBRATION page
 * of the same displayed size: a blue triangle with a vertical anti-aliased
 * edge at x = 10 pt and a red one with a horizontal edge 10 pt from the top.
 * Triangles, not bars: Chromium snaps axis-aligned RECTANGLES to whole
 * pixels, which would quantise the measurement. Never searched for by best
 * fit, which would hide an offset bug in the renderer under test.
 */

require __DIR__.'/../../vendor/autoload.php';

use FinePrint\Tests\Support\PdfBuilder;
use FinePrint\Tests\Support\RenderFixtures;
use FinePrint\Tests\Support\TransparencyFixtures;

$golden = dirname(__DIR__).'/golden';
$work = dirname(__DIR__).'/.out/oracle';
@mkdir($golden, 0777, true);
@mkdir($work, 0777, true);

/** The zoom Chromium is driven at, and the factor the screenshot is averaged down by. */
const ORACLE_ZOOM_PER_SCALE = 75;
const ORACLE_RENDER_SCALE = 2;

// ONLY=name,name renders just those fixtures (the others' goldens are left alone).
$only = getenv('ONLY') ? explode(',', (string) getenv('ONLY')) : null;
$all = RenderFixtures::SCALES + RenderFixtures::TEXT_SCALES + RenderFixtures::LOCAL_SCALES + TransparencyFixtures::scales();

$jobs = [];
foreach ($all as $name => $scales) {
    if ($only !== null && ! in_array($name, $only, true)) {
        continue;
    }
    if (isset(RenderFixtures::LOCAL_SCALES[$name])) {
        // Built from a font installed here: the PDF stays in .out, only its hash is committed.
        $pdf = RenderFixtures::localPdf($name);
        if ($pdf === null) {
            fwrite(STDERR, "{$name}: its font is not installed, skipped\n");

            continue;
        }
        file_put_contents("{$work}/{$name}.pdf", $pdf);
        file_put_contents("{$golden}/{$name}.pdf.sha256", hash('sha256', $pdf)."\n");
        $pdfPath = realpath("{$work}/{$name}.pdf");
    } else {
        file_put_contents("{$golden}/{$name}.pdf", RenderFixtures::pdf($name));
        $pdfPath = realpath("{$golden}/{$name}.pdf");
    }
    [$w, $h] = RenderFixtures::displaySize($name);
    $cal = PdfBuilder::textPdf([
        sprintf("0 0 1 rg 10 %.3F m 10 %.3F l 40 %.3F l h f\n", 0.2 * $h, 0.8 * $h, 0.5 * $h)
        .sprintf("1 0 0 rg %.3F %.3F m %.3F %.3F l %.3F %.3F l h f\n", 0.2 * $w, $h - 10, 0.8 * $w, $h - 10, 0.5 * $w, $h - 40),
    ], ['mediaBox' => "[0 0 {$w} {$h}]"])->build();
    file_put_contents("{$work}/cal-{$name}.pdf", $cal);

    foreach ($scales as $s) {
        $render = max($s, ORACLE_RENDER_SCALE);
        $jobs[] = [
            'name' => $name,
            'scale' => $s,
            'render' => $render,
            'down' => intdiv($render, $s),
            'pdf' => $pdfPath,
            'cal' => realpath("{$work}/cal-{$name}.pdf"),
            'shot' => "{$work}/{$name}@{$s}x.full.png",
            'calShot' => "{$work}/cal-{$name}@{$s}x.full.png",
            'zoom' => ORACLE_ZOOM_PER_SCALE * $render,
            'viewport' => ['width' => (int) ceil($w * $render) + 200, 'height' => (int) ceil($h * $render) + 60],
            'size' => [(int) round($w * $s), (int) round($h * $s)],
            'calibration' => ['w' => $w, 'h' => $h],
        ];
    }
}
file_put_contents("{$work}/jobs.json", json_encode($jobs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo count($jobs)." jobs written to {$work}/jobs.json\n";
