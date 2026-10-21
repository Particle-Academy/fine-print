<?php

declare(strict_types=1);

use FinePrint\Exceptions\FontException;
use FinePrint\Render\Glyphs\Outline;
use FinePrint\Render\Glyphs\TrueTypeGlyphs;
use FinePrint\Tests\Support\FontBuilder;
use FinePrint\Writer\Fonts\TrueType;

/*
 * TrueType outlines (glyf + loca + hmtx), parsed for the renderer. The font
 * is a hostile input like the rest of the file: every limit here has a test
 * that crosses it, and a bad glyph is a FontException, never a warning, a
 * hang or a read past the end.
 *
 * Outlines come out in font units as the renderer's path model: subpaths of
 * flat x, y floats with one letter per segment ('l' a line, 'c' a cubic).
 * Quadratic segments are raised to cubics exactly.
 */

function fpgGlyphs(string $font): TrueTypeGlyphs
{
    return new TrueTypeGlyphs(TrueType::parse($font, requireUnicodeCmap: false));
}

/** The outlined golden font, gids as documented in FontBuilder::outlined(). */
function fpgOutlined(int $gid): Outline
{
    return fpgGlyphs(FontBuilder::outlined())->outline($gid);
}

/** Points of a subpath as [x, y] pairs. */
function fpgPoints(array $subpath): array
{
    return array_chunk($subpath['xy'], 2);
}

/** A font of one glyph (plus an empty .notdef), for hostile shapes. */
function fpgOne(string $glyph, int $advance = 500, ?int $lsb = null, array $more = []): TrueTypeGlyphs
{
    $glyphs = [['data' => '', 'advance' => 500, 'lsb' => 0], ['data' => $glyph, 'advance' => $advance] + ($lsb === null ? [] : ['lsb' => $lsb])];
    foreach ($more as $m) {
        $glyphs[] = ['data' => $m, 'advance' => 500];
    }

    return fpgGlyphs(FontBuilder::build($glyphs));
}

// --- simple glyphs ------------------------------------------------------------

it('reads a one-contour glyph of lines, closed', function () {
    $o = fpgOutlined(13);   // I: a bar from (150, 0) to (350, 700)

    expect($o->subpaths)->toHaveCount(1)
        ->and($o->subpaths[0]['seg'])->toBe('lll')
        ->and($o->subpaths[0]['closed'])->toBeTrue()
        ->and(fpgPoints($o->subpaths[0]))->toBe([[150.0, 0.0], [150.0, 700.0], [350.0, 700.0], [350.0, 0.0]])
        ->and($o->bbox)->toBe([150.0, 0.0, 350.0, 700.0]);
});

it('skips hinting instructions without executing them', function () {
    // Glyph 13 carries nine bytes of instructions; it reads as the bare bar.
    expect(fpgOutlined(13)->points)->toBe(4);
});

it('reads coordinates stored long and uncompressed the same as short ones', function () {
    $pts = [[30, 0], [230, 700], [450, 200], [670, 700], [870, 0], [670, 0], [450, 450], [230, 0]];
    $short = fpgOne(FontBuilder::simpleGlyph([$pts]))->outline(1);
    $long = fpgOne(FontBuilder::simpleGlyph([$pts], '', compress: false))->outline(1);

    expect($long->subpaths)->toBe($short->subpaths)
        ->and(fpgPoints($short->subpaths[0]))->toBe(array_map(fn ($p) => [(float) $p[0], (float) $p[1]], $pts));
});

it('reads repeated flags', function () {
    // Many points with the same flag byte: the builder writes them as one repeat.
    $pts = [];
    for ($i = 0; $i < 40; $i++) {
        $pts[] = [10 * $i, 5 * $i];
    }
    $pts[] = [400, 500];
    $o = fpgOne(FontBuilder::simpleGlyph([$pts]))->outline(1);

    expect(fpgPoints($o->subpaths[0]))->toHaveCount(41)
        ->and(fpgPoints($o->subpaths[0])[39])->toBe([390.0, 195.0]);
});

it('raises quadratic segments to exact cubics, with implied on-curve midpoints', function () {
    $o = fpgOutlined(2);    // O: two contours of eight off-curve points each
    expect($o->subpaths)->toHaveCount(2)
        ->and($o->subpaths[0]['seg'])->toBe('cccccccc')
        ->and($o->subpaths[1]['seg'])->toBe('cccccccc');

    // Segment 1 of a contour of off-curve points q0, q1, ... runs from
    // mid(q0, q1) through control q1 to mid(q1, q2); cubic controls are the
    // ends moved two thirds of the way to the quadratic control.
    $xy = $o->subpaths[0]['xy'];
    [$sx, $sy, $c1x, $c1y, $c2x, $c2y, $ex, $ey] = array_slice($xy, 0, 8);
    $qx = $sx + 1.5 * ($c1x - $sx);
    $qy = $sy + 1.5 * ($c1y - $sy);
    expect($ex + 1.5 * ($c2x - $ex))->toEqualWithDelta($qx, 1e-9)
        ->and($ey + 1.5 * ($c2y - $ey))->toEqualWithDelta($qy, 1e-9);
});

it('mixes lines and quadratics in one contour', function () {
    $o = fpgOutlined(3);    // D: on, on, on, off, on, off, on (then closes to the start)

    // The closing segment is written only when it is a curve; a closing line is implied by 'closed'.
    expect($o->subpaths[0]['seg'])->toBe('llcc')
        ->and(fpgPoints($o->subpaths[0])[0])->toBe([60.0, 0.0]);
});

it('starts a contour whose first point is off-curve at an on-curve point', function () {
    // S starts and ends off-curve: the contour starts at their midpoint.
    $s = fpgOutlined(5);
    // o is ALL off-curve: the same rule, every segment a curve.
    $o = fpgOutlined(6);

    expect(fpgPoints($s->subpaths[0])[0])->toBe([80.0, 570.0])
        ->and(fpgPoints($o->subpaths[0])[0])->toBe([165.0, 115.0])
        ->and($o->subpaths[0]['seg'])->toBe('cccc');
});

it('starts a contour whose first point is off-curve and last on-curve at that last point', function () {
    // off (0, 100), on (100, 100), on (100, 0), on (0, 0): FreeType starts at (0, 0).
    $g = FontBuilder::simpleGlyph([[[0, 100, false], [100, 100], [100, 0], [0, 0]]]);
    $o = fpgOne($g)->outline(1);

    expect(fpgPoints($o->subpaths[0])[0])->toBe([0.0, 0.0])
        ->and($o->subpaths[0]['seg'])->toBe('cl');
});

it('gives an empty glyph no subpaths, and a missing glyph id an empty outline', function () {
    expect(fpgOutlined(1)->subpaths)->toBe([])
        ->and(fpgOutlined(60000)->subpaths)->toBe([]);
});

it('places the outline by its left side bearing, as FreeType and Acrobat do', function () {
    // The origin is xMin - lsb (the phantom point pp1): a glyph whose
    // coordinates start at x = 50 with an lsb of 30 is drawn 20 units left.
    $square = FontBuilder::simpleGlyph([[[50, 0], [50, 100], [150, 100], [150, 0]]]);
    $o = fpgOne($square, 300, 30)->outline(1);

    expect(fpgPoints($o->subpaths[0])[0])->toBe([30.0, 0.0])
        ->and($o->bbox)->toBe([30.0, 0.0, 130.0, 100.0]);
});

// --- composite glyphs ------------------------------------------------------------

it('assembles a composite from components moved by offsets', function () {
    $o = fpgOutlined(9);    // Ä: A + dot at (180, 780) + dot at (400, 780)

    expect($o->subpaths)->toHaveCount(4)
        ->and(fpgPoints($o->subpaths[2])[0])->toBe([180.0, 780.0])
        ->and(fpgPoints($o->subpaths[3])[0])->toBe([400.0, 780.0]);
});

it('scales a component by an x and y scale', function () {
    $o = fpgOutlined(10);   // É: D + dot scaled 1.5 x 0.5 at (300, 760)

    expect(fpgPoints($o->subpaths[2]))->toBe([[300.0, 760.0], [300.0, 810.0], [450.0, 810.0], [450.0, 760.0]]);
});

it('transforms a component by a 2x2 matrix', function () {
    $o = fpgOutlined(11);   // Z: A turned 90 degrees, x' = 700 - y, y' = x

    expect(fpgPoints($o->subpaths[0])[0])->toBe([700.0, 20.0])
        ->and(fpgPoints($o->subpaths[0])[1])->toBe([0.0, 340.0]);
});

it('places a component by matching points, inside a nested composite', function () {
    $o = fpgOutlined(12);   // Q: composite Ä, then a dot whose point 0 meets parent point 1 (A's apex)

    expect($o->subpaths)->toHaveCount(5)
        ->and(fpgPoints($o->subpaths[4])[0])->toBe([340.0, 700.0]);
});

it('scales a component offset only when the glyph asks for it', function () {
    $dot = FontBuilder::simpleGlyph([[[0, 0], [0, 100], [100, 100], [100, 0]]]);
    $plain = FontBuilder::compositeGlyph([['gid' => 2, 'dx' => 100, 'dy' => 0, 'scale' => 0.5]], [100, 0, 150, 50]);
    $scaled = FontBuilder::compositeGlyph([['gid' => 2, 'dx' => 100, 'dy' => 0, 'scale' => 0.5, 'scaledOffset' => true]], [50, 0, 100, 50]);

    expect(fpgPoints(fpgOne($plain, more: [$dot])->outline(1)->subpaths[0])[0])->toBe([100.0, 0.0])
        ->and(fpgPoints(fpgOne($scaled, more: [$dot])->outline(1)->subpaths[0])[0])->toBe([50.0, 0.0]);
});

it('treats a component naming a glyph that does not exist as empty', function () {
    $c = FontBuilder::compositeGlyph([['gid' => 999, 'dx' => 0, 'dy' => 0], ['gid' => 2, 'dx' => 0, 'dy' => 0]]);
    $dot = FontBuilder::simpleGlyph([[[0, 0], [0, 100], [100, 100], [100, 0]]]);

    expect(fpgOne($c, more: [$dot])->outline(1)->subpaths)->toHaveCount(1);
});

// --- hostile glyphs ------------------------------------------------------------

it('refuses a glyph with more contours than the limit', function () {
    $contours = [];
    for ($i = 0; $i <= TrueTypeGlyphs::MAX_CONTOURS; $i++) {
        $contours[] = [[$i, 0], [$i, 1], [$i + 1, 1]];
    }

    expect(fn () => fpgOne(FontBuilder::simpleGlyph($contours))->outline(1))
        ->toThrow(FontException::class, 'contours');
});

it('refuses a glyph with more points than the limit', function () {
    $pts = [];
    for ($i = 0; $i <= TrueTypeGlyphs::MAX_POINTS; $i++) {
        $pts[] = [$i % 1000, intdiv($i, 1000)];
    }

    expect(fn () => fpgOne(FontBuilder::simpleGlyph([$pts]))->outline(1))
        ->toThrow(FontException::class, 'points');
});

it('refuses a glyph whose point count is declared but not present, without reading past it', function () {
    // endPtsOfContours says 60000 points; the glyph holds three.
    $g = pack('n', 1).str_repeat("\0", 8).pack('n', 60000).pack('n', 0)."\x01\x01\x01";

    expect(fn () => fpgOne($g)->outline(1))->toThrow(FontException::class);
});

it('refuses contour ends that run backwards', function () {
    $g = pack('n', 2).str_repeat("\0", 8).pack('nn', 5, 2).pack('n', 0).str_repeat("\x37", 6);

    expect(fn () => fpgOne($g)->outline(1))->toThrow(FontException::class, 'contour');
});

it('refuses instructions that run past the glyph', function () {
    $g = pack('n', 1).str_repeat("\0", 8).pack('n', 2).pack('n', 0xFFFF)."\x37\x37\x37";

    expect(fn () => fpgOne($g)->outline(1))->toThrow(FontException::class, 'instructions');
});

it('refuses a truncated coordinate array', function () {
    $full = FontBuilder::simpleGlyph([[[0, 0], [0, 1000], [1000, 1000], [1000, 0]]], '', compress: false);

    expect(fn () => fpgOne(substr($full, 0, -3))->outline(1))->toThrow(FontException::class);
});

it('stops a repeat count that claims more flags than points', function () {
    // One contour of 3 points; the flag byte repeats 255 times. Bounded either way.
    $g = pack('n', 1).str_repeat("\0", 8).pack('n', 2).pack('n', 0)."\x3F\xFF".str_repeat("\x05", 300);
    $t = microtime(true);
    try {
        fpgOne($g)->outline(1);
    } catch (FontException) {
    }

    expect(microtime(true) - $t)->toBeLessThan(0.5);
});

it('refuses a composite that contains itself', function () {
    $self = FontBuilder::compositeGlyph([['gid' => 1, 'dx' => 10, 'dy' => 0]]);

    // Refused for what it is, not merely when the depth limit runs out.
    expect(fn () => fpgOne($self)->outline(1))->toThrow(FontException::class, 'itself');
});

it('refuses composites nested deeper than the limit', function () {
    // gid k (1..n) contains gid k + 1; the last is a dot.
    $n = TrueTypeGlyphs::MAX_DEPTH + 3;
    $glyphs = [['data' => '', 'advance' => 0, 'lsb' => 0]];
    for ($k = 1; $k <= $n; $k++) {
        $glyphs[] = ['data' => FontBuilder::compositeGlyph([['gid' => $k + 1, 'dx' => 1, 'dy' => 0]]), 'advance' => 500];
    }
    $glyphs[] = ['data' => FontBuilder::simpleGlyph([[[0, 0], [0, 10], [10, 10]]]), 'advance' => 500];
    $g = fpgGlyphs(FontBuilder::build($glyphs));

    expect(fn () => $g->outline(1))->toThrow(FontException::class, 'deep')
        ->and($g->outline($n - TrueTypeGlyphs::MAX_DEPTH + 2)->subpaths)->toHaveCount(1);
});

it('refuses a composite with more components than the limit', function () {
    $components = array_fill(0, TrueTypeGlyphs::MAX_COMPONENTS + 1, ['gid' => 2, 'dx' => 0, 'dy' => 0]);
    $dot = FontBuilder::simpleGlyph([[[0, 0], [0, 10], [10, 10]]]);

    expect(fn () => fpgOne(FontBuilder::compositeGlyph($components), more: [$dot])->outline(1))
        ->toThrow(FontException::class, 'components');
});

it('bounds the points a composite multiplies out to', function () {
    // 100 components of a 1000-point glyph: 100 000 points from a few bytes.
    $pts = [];
    for ($i = 0; $i < 1000; $i++) {
        $pts[] = [$i, $i % 7];
    }
    $big = FontBuilder::simpleGlyph([$pts]);
    $components = array_fill(0, 100, ['gid' => 2, 'dx' => 0, 'dy' => 0]);

    expect(fn () => fpgOne(FontBuilder::compositeGlyph($components), more: [$big])->outline(1))
        ->toThrow(FontException::class, 'points');
});

it('refuses a point-matching index outside the glyph', function () {
    $dot = FontBuilder::simpleGlyph([[[0, 0], [0, 10], [10, 10]]]);
    $c = FontBuilder::compositeGlyph([['gid' => 2, 'dx' => 0, 'dy' => 0], ['gid' => 2, 'points' => [200, 0]]]);

    expect(fn () => fpgOne($c, more: [$dot])->outline(1))->toThrow(FontException::class, 'point');
});

it('refuses a truncated composite record', function () {
    $c = FontBuilder::compositeGlyph([['gid' => 2, 'dx' => 0, 'dy' => 0, 'matrix' => [1.0, 0.0, 0.0, 1.0]]]);
    $dot = FontBuilder::simpleGlyph([[[0, 0], [0, 10], [10, 10]]]);

    expect(fn () => fpgOne(substr($c, 0, -4), more: [$dot])->outline(1))->toThrow(FontException::class);
});
