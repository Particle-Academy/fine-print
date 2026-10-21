<?php

declare(strict_types=1);

use FinePrint\Render\Bezier;
use FinePrint\Render\Rasterizer;

/*
 * The scanline rasteriser: exact-area coverage per pixel, nonzero and
 * even-odd. Known shapes have known answers, so most of these are exact.
 */

/**
 * Coverage map from the rasteriser's spans.
 *
 * @param  list<list<array{0: float, 1: float}>>  $polygons
 * @return array<int, array<int, float>>
 */
function fpCoverage(int $w, int $h, array $polygons, bool $evenOdd = false): array
{
    $r = new Rasterizer($w, $h);
    foreach ($polygons as $poly) {
        $r->addPolygon($poly);
    }
    $map = [];
    foreach ($r->spans($evenOdd) as [$y, $x, $len, $cov]) {
        for ($i = 0; $i < $len; $i++) {
            $map[$y][$x + $i] = ($map[$y][$x + $i] ?? 0.0) + $cov;
        }
    }

    return $map;
}

function fpCov(array $map, int $x, int $y): float
{
    return $map[$y][$x] ?? 0.0;
}

function fpTotal(array $map): float
{
    $t = 0.0;
    foreach ($map as $row) {
        $t += array_sum($row);
    }

    return $t;
}

function fpRect(float $x0, float $y0, float $x1, float $y1, bool $clockwise = true): array
{
    $p = [[$x0, $y0], [$x1, $y0], [$x1, $y1], [$x0, $y1]];

    return $clockwise ? $p : array_reverse($p);
}

it('fills a pixel-aligned rectangle exactly', function () {
    $m = fpCoverage(10, 10, [fpRect(2, 3, 6, 8)]);

    for ($y = 0; $y < 10; $y++) {
        for ($x = 0; $x < 10; $x++) {
            $inside = $x >= 2 && $x < 6 && $y >= 3 && $y < 8;
            expect(fpCov($m, $x, $y))->toEqualWithDelta($inside ? 1.0 : 0.0, 1e-9);
        }
    }
});

it('gives a half-covered edge pixel half coverage', function () {
    $m = fpCoverage(10, 10, [fpRect(2.5, 2, 6.5, 4)]);

    expect(fpCov($m, 2, 2))->toEqualWithDelta(0.5, 1e-9)
        ->and(fpCov($m, 3, 2))->toEqualWithDelta(1.0, 1e-9)
        ->and(fpCov($m, 6, 3))->toEqualWithDelta(0.5, 1e-9)
        ->and(fpCov($m, 7, 3))->toEqualWithDelta(0.0, 1e-9)
        ->and(fpCov($m, 1, 2))->toEqualWithDelta(0.0, 1e-9);
});

it('gives a quarter-covered corner pixel a quarter', function () {
    $m = fpCoverage(10, 10, [fpRect(2.5, 2.5, 5, 5)]);

    expect(fpCov($m, 2, 2))->toEqualWithDelta(0.25, 1e-9)
        ->and(fpCov($m, 3, 2))->toEqualWithDelta(0.5, 1e-9)
        ->and(fpCov($m, 3, 3))->toEqualWithDelta(1.0, 1e-9);
});

it('conserves area: total coverage equals the polygon area', function () {
    // A triangle with sloped edges crossing many cells: area = 1/2 * 37 * 23.
    $m = fpCoverage(64, 64, [[[3.3, 5.1], [40.3, 9.7], [12.9, 28.1]]]);
    $area = abs((40.3 - 3.3) * (28.1 - 5.1) - (12.9 - 3.3) * (9.7 - 5.1)) / 2;

    expect(fpTotal($m))->toEqualWithDelta($area, 1e-6);
    foreach ($m as $row) {
        foreach ($row as $c) {
            expect($c)->toBeGreaterThanOrEqual(-1e-9)->toBeLessThanOrEqual(1 + 1e-9);
        }
    }
});

it('does not care which way a polygon winds', function () {
    $cw = fpCoverage(32, 32, [[[3.3, 5.1], [20.3, 9.7], [12.9, 28.1]]]);
    $ccw = fpCoverage(32, 32, [[[12.9, 28.1], [20.3, 9.7], [3.3, 5.1]]]);

    expect(fpTotal($ccw))->toEqualWithDelta(fpTotal($cw), 1e-9);
    foreach ($cw as $y => $row) {
        foreach ($row as $x => $c) {
            expect(fpCov($ccw, $x, $y))->toEqualWithDelta($c, 1e-9);
        }
    }
});

it('fills nested same-direction squares solid under nonzero and with a hole under even-odd', function () {
    $outer = fpRect(2, 2, 18, 18);
    $inner = fpRect(6, 6, 14, 14);

    $nz = fpCoverage(20, 20, [$outer, $inner]);
    $eo = fpCoverage(20, 20, [$outer, $inner], true);

    expect(fpCov($nz, 10, 10))->toEqualWithDelta(1.0, 1e-9)
        ->and(fpCov($eo, 10, 10))->toEqualWithDelta(0.0, 1e-9)
        ->and(fpCov($eo, 3, 3))->toEqualWithDelta(1.0, 1e-9)
        ->and(fpTotal($nz))->toEqualWithDelta(256.0, 1e-6)
        ->and(fpTotal($eo))->toEqualWithDelta(256.0 - 64.0, 1e-6);
});

it('cuts a hole under nonzero when the inner square winds the other way', function () {
    $m = fpCoverage(20, 20, [fpRect(2, 2, 18, 18), fpRect(6, 6, 14, 14, false)]);

    expect(fpCov($m, 10, 10))->toEqualWithDelta(0.0, 1e-9)
        ->and(fpTotal($m))->toEqualWithDelta(192.0, 1e-6);
});

it('fills a self-intersecting star by rule: solid centre for nonzero, hollow for even-odd', function () {
    $star = [];
    for ($i = 0; $i < 5; $i++) {
        $a = -M_PI / 2 + $i * 4 * M_PI / 5;
        $star[] = [50 + 40 * cos($a), 50 + 40 * sin($a)];
    }

    expect(fpCov(fpCoverage(100, 100, [$star]), 50, 50))->toEqualWithDelta(1.0, 1e-9)
        ->and(fpCov(fpCoverage(100, 100, [$star], true), 50, 50))->toEqualWithDelta(0.0, 1e-9);
});

it('clips to the canvas and keeps the visible part exact', function () {
    // Half of this rectangle hangs off the left, top and right.
    $m = fpCoverage(10, 10, [fpRect(-5, -5, 15, 4.5)]);

    expect(fpCov($m, 0, 0))->toEqualWithDelta(1.0, 1e-9)
        ->and(fpCov($m, 9, 0))->toEqualWithDelta(1.0, 1e-9)
        ->and(fpCov($m, 5, 4))->toEqualWithDelta(0.5, 1e-9)
        ->and(fpTotal($m))->toEqualWithDelta(45.0, 1e-6);
});

it('keeps coverage exact where a sloped edge leaves the canvas on the left', function () {
    // Triangle whose left vertex is far off-canvas: visible area by geometry.
    $m = fpCoverage(10, 10, [[[-10, 0], [10, 0], [10, 10]]]);

    // The edge from (-10,0) to (10,10) has y = (x + 10) / 2; area under it, inside x in [0,10], y in [0,10]:
    // integral over x of y(x) = [(x+10)^2/4] from 0..10 = (400 - 100) / 4 = 75 (bounded by y <= 10).
    expect(fpTotal($m))->toEqualWithDelta(75.0, 1e-6);
});

it('stays bounded on absurd coordinates', function () {
    $t = hrtime(true);
    $m = fpCoverage(50, 50, [[[-1e30, -1e30], [1e30, -1e29], [3, 1e30]], fpRect(1e15, 1e15, 1e16, 1e16)]);

    expect((hrtime(true) - $t) / 1e9)->toBeLessThan(1.0)
        ->and(fpTotal($m))->toBeLessThanOrEqual(2500.0 + 1e-6);
});

it('ignores non-finite coordinates rather than looping on them', function () {
    $m = fpCoverage(10, 10, [[[NAN, 1], [5, INF], [2, 3]], fpRect(1, 1, 3, 3)]);

    expect(fpCov($m, 2, 2))->toEqualWithDelta(1.0, 1e-9);
});

// --- Bézier flattening -------------------------------------------------------

it('flattens a cubic to within tolerance and ends on its end point', function () {
    $pts = Bezier::flatten([0, 0], [0, 50], [100, 50], [100, 0], 0.1);

    expect(end($pts))->toBe([100.0, 0.0])
        ->and(count($pts))->toBeGreaterThan(8)->toBeLessThan(200);
    // The curve's true midpoint (t = 0.5) is (50, 37.5); the polyline passes within tolerance of it.
    $best = INF;
    $prev = [0.0, 0.0];
    foreach ($pts as $p) {
        $best = min($best, fpDistToSegment([50, 37.5], $prev, $p));
        $prev = $p;
    }
    expect($best)->toBeLessThan(0.1);
});

it('fills a Bézier circle to within half a percent of pi r squared', function () {
    $k = 0.5522847498 * 40;
    $cx = 50;
    $cy = 50;
    $r = 40;
    $poly = [[$cx + $r, $cy]];
    $arcs = [
        [[$cx + $r, $cy + $k], [$cx + $k, $cy + $r], [$cx, $cy + $r]],
        [[$cx - $k, $cy + $r], [$cx - $r, $cy + $k], [$cx - $r, $cy]],
        [[$cx - $r, $cy - $k], [$cx - $k, $cy - $r], [$cx, $cy - $r]],
        [[$cx + $k, $cy - $r], [$cx + $r, $cy - $k], [$cx + $r, $cy]],
    ];
    $from = [$cx + $r, $cy];
    foreach ($arcs as [$c1, $c2, $to]) {
        array_push($poly, ...Bezier::flatten($from, $c1, $c2, $to, 0.1));
        $from = $to;
    }

    expect(fpTotal(fpCoverage(100, 100, [$poly])))->toEqualWithDelta(M_PI * 1600, M_PI * 1600 * 0.005);
});

it('caps the segment count of a hostile curve', function () {
    $pts = Bezier::flatten([0, 0], [1e12, -1e12], [-1e12, 1e12], [1, 1], 0.01);

    expect(count($pts))->toBeLessThanOrEqual(Bezier::MAX_SEGMENTS);
});

function fpDistToSegment(array $p, array $a, array $b): float
{
    $dx = $b[0] - $a[0];
    $dy = $b[1] - $a[1];
    $len = $dx * $dx + $dy * $dy;
    $t = $len > 0 ? max(0, min(1, (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / $len)) : 0;

    return hypot($p[0] - ($a[0] + $t * $dx), $p[1] - ($a[1] + $t * $dy));
}
