<?php

declare(strict_types=1);

use FinePrint\Render\Rasterizer;
use FinePrint\Render\Stroker;

/*
 * Strokes become polygons, filled nonzero. The tests measure the AREA a
 * stroke covers, which pins width, caps, joins and dashes without depending on
 * how the polygons happen to be cut.
 */

/** @param list<array{points: list<array{0: float, 1: float}>, closed: bool}> $subpaths */
function fpStrokeArea(array $subpaths, array $style, int $w = 200, int $h = 200): float
{
    $polys = Stroker::stroke(
        $subpaths,
        $style['width'] ?? 10.0,
        $style['cap'] ?? 0,
        $style['join'] ?? 0,
        $style['miter'] ?? 10.0,
        $style['dash'] ?? [],
        $style['phase'] ?? 0.0,
        0.01,
    );
    $r = new Rasterizer($w, $h);
    foreach ($polys as $p) {
        $r->addPolygon($p);
    }
    $t = 0.0;
    foreach ($r->spans(false) as [, , $len, $cov]) {
        $t += $len * $cov;
    }

    return $t;
}

function fpOpen(array ...$pts): array
{
    return [['points' => array_map(fn ($p) => [(float) $p[0], (float) $p[1]], $pts), 'closed' => false]];
}

it('strokes a line with butt caps to exactly length x width', function () {
    expect(fpStrokeArea(fpOpen([50, 100], [150, 100]), ['width' => 10]))->toEqualWithDelta(1000.0, 1e-6);
});

it('extends a square cap by half the width at each end', function () {
    expect(fpStrokeArea(fpOpen([50, 100], [150, 100]), ['width' => 10, 'cap' => 2]))->toEqualWithDelta(1100.0, 1e-6);
});

it('adds a half disc at each end for a round cap', function () {
    expect(fpStrokeArea(fpOpen([50, 100], [150, 100]), ['width' => 10, 'cap' => 1]))
        ->toEqualWithDelta(1000.0 + M_PI * 25, 0.5);
});

it('strokes a sloped line to the same area as a level one', function () {
    $len = 100.0;
    $a = M_PI / 7;
    expect(fpStrokeArea(fpOpen([40, 40], [40 + $len * cos($a), 40 + $len * sin($a)]), ['width' => 7]))
        ->toEqualWithDelta(700.0, 1e-6);
});

it('joins a right angle by miter, bevel and round', function () {
    // An L: (50,50) -> (150,50) -> (150,150), width 10, butt caps.
    // Two 100 x 10 rectangles overlap in a 5 x 5 square on the inside corner;
    // the outer corner adds a 5 x 5 square (miter), half of it (bevel), or a quarter disc (round).
    $l = fpOpen([50, 50], [150, 50], [150, 150]);
    $base = 2000.0 - 25.0;

    expect(fpStrokeArea($l, ['width' => 10, 'join' => 0]))->toEqualWithDelta($base + 25.0, 1e-6)
        ->and(fpStrokeArea($l, ['width' => 10, 'join' => 2]))->toEqualWithDelta($base + 12.5, 1e-6)
        ->and(fpStrokeArea($l, ['width' => 10, 'join' => 1]))->toEqualWithDelta($base + M_PI * 25 / 4, 0.3);
});

it('falls back to a bevel past the miter limit', function () {
    // A right angle's miter ratio is sqrt(2) = 1.414: a limit of 1.4 must bevel.
    $l = fpOpen([50, 50], [150, 50], [150, 150]);

    expect(fpStrokeArea($l, ['width' => 10, 'join' => 0, 'miter' => 1.4]))->toEqualWithDelta(2000.0 - 25.0 + 12.5, 1e-6)
        ->and(fpStrokeArea($l, ['width' => 10, 'join' => 0, 'miter' => 1.42]))->toEqualWithDelta(2000.0, 1e-6);
});

it('closes a closed path with a join, not caps', function () {
    // A 100 x 100 square outline, width 10, miter joins: outer 110^2 minus inner 90^2.
    $sq = [['points' => [[50.0, 50.0], [150.0, 50.0], [150.0, 150.0], [50.0, 150.0]], 'closed' => true]];

    expect(fpStrokeArea($sq, ['width' => 10, 'join' => 0, 'cap' => 1]))->toEqualWithDelta(110.0 ** 2 - 90.0 ** 2, 1e-6);
});

it('dashes: on 10, off 10 over 100 units is five dashes', function () {
    expect(fpStrokeArea(fpOpen([50, 100], [150, 100]), ['width' => 4, 'dash' => [10, 10]]))->toEqualWithDelta(200.0, 1e-6);
});

it('dashes with a phase', function () {
    // Phase 5 starts half way through the first dash: 5 on, then 10 off / 10 on ... = 5 + 4*10 + 5 = 50 on.
    expect(fpStrokeArea(fpOpen([50, 100], [150, 100]), ['width' => 4, 'dash' => [10, 10], 'phase' => 5]))->toEqualWithDelta(200.0, 1e-6)
        // Phase 15 starts half way through the gap: dashes at [5,15], [25,35], [45,55], [65,75], [85,95] = 50 on.
        ->and(fpStrokeArea(fpOpen([50, 100], [150, 100]), ['width' => 4, 'dash' => [10, 10], 'phase' => 15]))->toEqualWithDelta(200.0, 1e-6)
        ->and(fpStrokeArea(fpOpen([50, 100], [150, 100]), ['width' => 4, 'dash' => [30, 10], 'phase' => 0]))->toEqualWithDelta(4 * (30 + 30 + 20), 1e-6);
});

it('shifts the dashes by the phase', function () {
    // Over 95 units, [10 10]: phase 0 = 50 on, phase 5 = 45 on, phase 12 = 47 on.
    $line = fpOpen([50, 100], [145, 100]);
    expect(fpStrokeArea($line, ['width' => 4, 'dash' => [10, 10], 'phase' => 0]))->toEqualWithDelta(200.0, 1e-6)
        ->and(fpStrokeArea($line, ['width' => 4, 'dash' => [10, 10], 'phase' => 5]))->toEqualWithDelta(180.0, 1e-6)
        ->and(fpStrokeArea($line, ['width' => 4, 'dash' => [10, 10], 'phase' => 12]))->toEqualWithDelta(188.0, 1e-6)
        // A phase of a whole period changes nothing; a negative one wraps.
        ->and(fpStrokeArea($line, ['width' => 4, 'dash' => [10, 10], 'phase' => 25]))->toEqualWithDelta(180.0, 1e-6)
        ->and(fpStrokeArea($line, ['width' => 4, 'dash' => [10, 10], 'phase' => -15]))->toEqualWithDelta(180.0, 1e-6);
});

it('carries a dash around a corner', function () {
    // Legs of 50 and 100 units. One 70-unit dash: 50 on the first leg and 20
    // on the second, mitred at the bend (inner overlap -25, outer miter +25).
    $l = fpOpen([50, 50], [100, 50], [100, 150]);
    expect(fpStrokeArea($l, ['width' => 10, 'dash' => [70, 1000], 'join' => 0]))->toEqualWithDelta(700.0, 1e-6);
});

it('draws a dot that falls exactly on the end of the path', function () {
    // [0 12] with round caps over 120 units: dots at 0, 12, ..., 120 = 11 dots, the last ON the end point.
    expect(fpStrokeArea(fpOpen([40, 100], [160, 100]), ['width' => 6, 'cap' => 1, 'dash' => [0, 12]]))
        // Each disc is an inscribed polygon (about 0.12 short); one missing dot is 28.3.
        ->toEqualWithDelta(11 * M_PI * 9, 2.0);
});

it('treats an all-zero dash array as solid', function () {
    expect(fpStrokeArea(fpOpen([50, 100], [150, 100]), ['width' => 4, 'dash' => [0, 0]]))->toEqualWithDelta(400.0, 1e-6);
});

it('draws a dot for a zero-length subpath with round caps, and nothing with butt caps', function () {
    $dot = [['points' => [[100.0, 100.0]], 'closed' => false]];

    expect(fpStrokeArea($dot, ['width' => 10, 'cap' => 1]))->toEqualWithDelta(M_PI * 25, 0.5)
        ->and(fpStrokeArea($dot, ['width' => 10, 'cap' => 2]))->toEqualWithDelta(100.0, 1e-6)
        ->and(fpStrokeArea($dot, ['width' => 10, 'cap' => 0]))->toEqualWithDelta(0.0, 1e-9);
});

it('keeps a dense hostile dash pattern bounded', function () {
    $t = hrtime(true);
    $polys = Stroker::stroke(fpOpen([0, 100], [1e6, 100]), 1.0, 0, 0, 10.0, [1e-6, 1e-6], 0.0, 0.01);

    expect((hrtime(true) - $t) / 1e9)->toBeLessThan(1.0)
        ->and(count($polys))->toBeLessThanOrEqual(Stroker::MAX_DASHES + 4);
});
