<?php

declare(strict_types=1);

/*
 * Golden oracle, step 3 of 3: find the page in each screenshot from its
 * calibration twin, crop it out, average it down to the golden's scale, and
 * write tests/golden/<name>@<scale>x.png. TEST ORACLE ONLY; see build.php.
 *
 * Calibration reads two anti-aliased edges to sub-pixel precision (a pixel
 * on an edge is the covered fraction of white-to-colour): the blue
 * triangle's vertical edge at x = 10 pt and the red one's horizontal edge
 * 10 pt from the top. The page origin is those minus 10 pt at the render
 * scale, and it must land on the pixel grid (within 0.05 px) or the job
 * FAILS: a golden taken off-grid would charge Chromium's offset to us.
 */

require __DIR__.'/../../vendor/autoload.php';

use FinePrint\Render\PngEncoder;
use FinePrint\Tests\Support\PngReader;

$work = dirname(__DIR__).'/.out/oracle';
$golden = getenv('ORACLE_GOLDEN_DIR') ?: dirname(__DIR__).'/golden';
is_dir($golden) || mkdir($golden, 0777, true);
$jobs = json_decode((string) file_get_contents("{$work}/jobs.json"), true);
$failed = false;

foreach ($jobs as $job) {
    $label = "{$job['name']}@{$job['scale']}x";
    $s = $job['render'];
    $cal = PngReader::read((string) file_get_contents($job['calShot']));
    $px = fn (int $x, int $y): array => array_map('ord', str_split(substr($cal['rgb'][$y], $x * 3, 3)));

    // Blue triangle: scan a row through its middle for the first blue-tinted pixel.
    $edgeX = $edgeY = null;
    $rowBlue = null;
    foreach ($cal['rgb'] as $y => $row) {
        for ($x = 0; $x < $cal['width']; $x++) {
            [$r, $g, $b] = $px($x, $y);
            if ($b > 240 && $r < 20 && $g < 20) {
                $rowBlue ??= [];
                $rowBlue[] = $y;
                break;
            }
        }
    }
    $redCols = [];
    for ($x = 0; $x < $cal['width']; $x++) {
        for ($y = 0; $y < $cal['height']; $y++) {
            [$r, $g, $b] = $px($x, $y);
            if ($r > 240 && $g < 20 && $b < 20) {
                $redCols[] = $x;
                break;
            }
        }
    }
    if ($rowBlue === null || $redCols === []) {
        fwrite(STDERR, "{$label}: calibration triangles not found\n");
        exit(1);
    }
    $y = $rowBlue[intdiv(count($rowBlue), 2)];
    for ($x = 0; $x < $cal['width']; $x++) {
        [$r, $g, $b] = $px($x, $y);
        if ($b > 200 && $r < 250 && $r === $g) {
            $edgeX = $x + 1 - (1 - $r / 255);
            break;
        }
    }
    $x = $redCols[intdiv(count($redCols), 2)];
    for ($yy = 0; $yy < $cal['height']; $yy++) {
        [$r, $g, $b] = $px($x, $yy);
        if ($r > 200 && $g < 250 && $g === $b) {
            $edgeY = $yy + 1 - (1 - $g / 255);
            break;
        }
    }
    $ox = $edgeX - 10 * $s;
    $oy = $edgeY - 10 * $s;
    if (abs($ox - round($ox)) > 0.05 || abs($oy - round($oy)) > 0.05) {
        fwrite(STDERR, sprintf("%s: page origin (%.3f, %.3f) is off the pixel grid\n", $label, $ox, $oy));
        $failed = true;

        continue;
    }
    $ox = (int) round($ox);
    $oy = (int) round($oy);

    [$w, $h] = $job['size'];
    $d = $job['down'];
    $full = PngReader::read((string) file_get_contents($job['shot']));
    if ($ox < 0 || $oy < 0 || $ox + $w * $d > $full['width'] || $oy + $h * $d > $full['height']) {
        fwrite(STDERR, "{$label}: page does not fit the screenshot\n");
        exit(1);
    }
    $rows = [];
    for ($yy = 0; $yy < $h; $yy++) {
        if ($d === 1) {
            $rows[] = substr($full['rgb'][$oy + $yy], $ox * 3, $w * 3);

            continue;
        }
        $row = '';
        for ($xx = 0; $xx < $w; $xx++) {
            for ($c = 0; $c < 3; $c++) {
                $sum = 0;
                for ($j = 0; $j < $d; $j++) {
                    $src = $full['rgb'][$oy + $yy * $d + $j];
                    for ($i = 0; $i < $d; $i++) {
                        $sum += ord($src[($ox + $xx * $d + $i) * 3 + $c]);
                    }
                }
                $row .= chr((int) round($sum / ($d * $d)));
            }
        }
        $rows[] = $row;
    }
    file_put_contents("{$golden}/{$label}.png", PngEncoder::encode($w, $h, $rows));
    printf("%s: %dx%d from a %dx render at (%d, %d), averaged %dx%d\n", $label, $w, $h, $s, $ox, $oy, $d, $d);
}
exit($failed ? 1 : 0);
