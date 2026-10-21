<?php

declare(strict_types=1);

namespace FinePrint\Tests\Support;

/**
 * A deliberately small TrueType WRITER, for fixtures only.
 *
 * The writer's font tests need a TrueType file, and the rule for this suite is
 * that every byte a test reads is made by our own code. So this builds one:
 * square glyphs with chosen advance widths, a format-4 cmap (plus format 12
 * when an astral character is asked for), and the tables a PDF embedder reads.
 *
 * Units per em is 1000, so an advance IS its width in PDF glyph space.
 *
 * Glyph 0 is `.notdef`. Mapped glyphs follow in code-point order. When `Ä`
 * (U+00C4) is mapped it is a COMPOSITE of `A` plus an unmapped dieresis glyph,
 * which is what a subsetter's component closure is tested against.
 */
final class FontBuilder
{
    /** @var array<int, int> code point => advance width */
    public const LATIN = [
        0x20 => 250, 0x41 => 700, 0x42 => 650, 0x43 => 640, 0x48 => 720, 0x49 => 300, 0x57 => 900,
        0x61 => 500, 0x62 => 520, 0x63 => 450, 0x64 => 520, 0x65 => 480, 0x66 => 300, 0x67 => 500,
        0x68 => 520, 0x69 => 240, 0x6C => 240, 0x6E => 520, 0x6F => 510, 0x70 => 520, 0x72 => 340,
        0x73 => 420, 0x74 => 320, 0x75 => 520, 0x77 => 720, 0x79 => 480, 0x2E => 250, 0x2C => 250,
        0x00C4 => 700, 0x0416 => 880, 0x1F600 => 1000,
    ];

    /**
     * @param  array<int, int>|null  $glyphs  code point => advance
     */
    public static function make(?array $glyphs = null, int $fsType = 0, string $postScriptName = 'FpTest-Regular', bool $fixedPitch = false, float $italicAngle = 0.0): string
    {
        $glyphs ??= self::LATIN;
        ksort($glyphs);

        // gid 0 .notdef, then one glyph per code point, then the dieresis.
        $advances = [500];
        $outlines = [self::square(500)];
        $cmap = [];
        $gidOf = [];
        foreach ($glyphs as $cp => $adv) {
            $gid = count($advances);
            $gidOf[$cp] = $gid;
            $cmap[$cp] = $gid;
            $advances[] = $adv;
            $outlines[] = $cp === 0x20 ? '' : self::square($adv);
        }
        $dieresis = count($advances);
        $advances[] = 0;
        $outlines[] = self::square(300);
        if (isset($gidOf[0xC4], $gidOf[0x41])) {
            $outlines[$gidOf[0xC4]] = self::composite([$gidOf[0x41], $dieresis], $glyphs[0xC4]);
        }
        $numGlyphs = count($advances);

        $glyf = '';
        $loca = [];
        foreach ($outlines as $o) {
            $loca[] = strlen($glyf);
            $glyf .= $o;
            if (strlen($glyf) % 4 !== 0) {
                $glyf .= str_repeat("\0", 4 - strlen($glyf) % 4);
            }
        }
        $loca[] = strlen($glyf);

        $tables = [
            'head' => pack('NN', 0x00010000, 0x00010000).pack('N', 0).pack('N', 0x5F0F3CF5)
                .pack('n', 0b1011).pack('n', 1000).str_repeat("\0", 16)
                .self::s16(0).self::s16(-200).self::s16(1000).self::s16(800)
                .pack('nn', $italicAngle !== 0.0 ? 2 : 0, 8).self::s16(2).self::s16(1).self::s16(0),
            'hhea' => pack('N', 0x00010000).self::s16(800).self::s16(-200).self::s16(0)
                .pack('n', max($advances)).self::s16(0).self::s16(0).self::s16(1000)
                .self::s16(1).self::s16(0).self::s16(0).str_repeat("\0", 8).self::s16(0).pack('n', $numGlyphs),
            'maxp' => pack('N', 0x00010000).pack('n', $numGlyphs).pack('n*', 8, 1, 8, 2, 2, 0, 0, 0, 0, 0, 0, 2, 1),
            'hmtx' => implode('', array_map(fn (int $a) => pack('n', $a).self::s16(0), $advances)),
            'loca' => pack('N*', ...$loca),
            'glyf' => $glyf,
            'cmap' => self::cmap($cmap),
            'post' => pack('N', 0x00030000).pack('N', (int) round($italicAngle * 65536) & 0xFFFFFFFF)
                .self::s16(-100).self::s16(50).pack('N', $fixedPitch ? 1 : 0).str_repeat("\0", 16),
            'OS/2' => self::os2($fsType),
            'name' => self::name($postScriptName),
        ];

        return self::sfnt($tables);
    }

    // ------------------------------------------------------------------
    // General builder (P3b): any glyphs, any cmaps. For the renderer's glyph
    // tests, its goldens and its hostile fonts.

    /**
     * A TrueType file from explicit glyphs.
     *
     * @param  list<array{data: string, advance: int, lsb?: int}>  $glyphs  glyph 0 first; `lsb` defaults to the glyph's xMin
     * @param  list<array{0: int, 1: int, 2: int, 3: array<int, int>}>  $cmaps  [platform, encoding, format (0, 4, 6 or 12), code => gid]
     * @param  array<string, string>  $extra  more tables, or replacements (e.g. a hostile `maxp`)
     */
    public static function build(array $glyphs, array $cmaps = [], int $unitsPerEm = 1000, string $postScriptName = 'FpOutline-Regular', array $extra = []): string
    {
        $glyf = '';
        $loca = [];
        $hmtx = '';
        $maxAdvance = 0;
        foreach ($glyphs as $g) {
            $loca[] = strlen($glyf);
            $glyf .= $g['data'];
            if (strlen($glyf) % 4 !== 0) {
                $glyf .= str_repeat("\0", 4 - strlen($glyf) % 4);
            }
            $lsb = $g['lsb'] ?? (strlen($g['data']) >= 10 ? unpack('n', substr($g['data'], 2, 2))[1] : 0);
            $hmtx .= pack('n', $g['advance']).self::s16($lsb >= 0x8000 ? $lsb - 0x10000 : $lsb);
            $maxAdvance = max($maxAdvance, $g['advance']);
        }
        $loca[] = strlen($glyf);
        $n = count($glyphs);

        $tables = [
            'head' => pack('NN', 0x00010000, 0x00010000).pack('N', 0).pack('N', 0x5F0F3CF5)
                .pack('n', 0b1011).pack('n', $unitsPerEm).str_repeat("\0", 16)
                .self::s16(-200).self::s16(-300).self::s16(1200).self::s16(1000)
                .pack('nn', 0, 8).self::s16(2).self::s16(1).self::s16(0),
            'hhea' => pack('N', 0x00010000).self::s16(800).self::s16(-200).self::s16(0)
                .pack('n', $maxAdvance).self::s16(0).self::s16(0).self::s16(1000)
                .self::s16(1).self::s16(0).self::s16(0).str_repeat("\0", 8).self::s16(0).pack('n', $n),
            'maxp' => pack('N', 0x00010000).pack('n', $n).pack('n*', 64, 8, 64, 8, 2, 0, 0, 0, 0, 0, 0, 4, 2),
            'hmtx' => $hmtx,
            'loca' => pack('N*', ...$loca),
            'glyf' => $glyf,
            'post' => pack('N', 0x00030000).pack('N', 0).self::s16(-100).self::s16(50).pack('N', 0).str_repeat("\0", 16),
            'OS/2' => self::os2(0),
            'name' => self::name($postScriptName),
        ];
        if ($cmaps !== []) {
            $tables['cmap'] = self::cmapTables($cmaps);
        }

        return self::sfnt(array_merge($tables, $extra));
    }

    /**
     * A simple glyph, encoded the way real fonts are: short vectors, the
     * "same as before" bits and repeated flags, so a parser meets every path.
     *
     * @param  list<list<array{0: int, 1: int, 2?: bool}>>  $contours  points [x, y, onCurve = true]
     */
    public static function simpleGlyph(array $contours, string $instructions = '', bool $compress = true): string
    {
        $xs = $ys = $on = $ends = [];
        foreach ($contours as $c) {
            foreach ($c as $p) {
                $xs[] = $p[0];
                $ys[] = $p[1];
                $on[] = $p[2] ?? true;
            }
            $ends[] = count($xs) - 1;
        }
        if ($xs === []) {
            return '';
        }
        $flags = [];
        $xb = $yb = '';
        $px = $py = 0;
        foreach ($xs as $i => $x) {
            $f = $on[$i] ? 0x01 : 0x00;
            $dx = $x - $px;
            $dy = $ys[$i] - $py;
            if ($compress && $dx === 0) {
                $f |= 0x10;
            } elseif ($compress && abs($dx) < 256) {
                $f |= 0x02 | ($dx > 0 ? 0x10 : 0);
                $xb .= chr(abs($dx));
            } else {
                $xb .= self::s16($dx);
            }
            if ($compress && $dy === 0) {
                $f |= 0x20;
            } elseif ($compress && abs($dy) < 256) {
                $f |= 0x04 | ($dy > 0 ? 0x20 : 0);
                $yb .= chr(abs($dy));
            } else {
                $yb .= self::s16($dy);
            }
            $flags[] = $f;
            $px = $x;
            $py = $ys[$i];
        }
        $fb = '';
        for ($i = 0, $n = count($flags); $i < $n;) {
            $run = 1;
            while ($compress && $i + $run < $n && $flags[$i + $run] === $flags[$i] && $run < 256) {
                $run++;
            }
            if ($run > 1) {
                $fb .= chr($flags[$i] | 0x08).chr($run - 1);
            } else {
                $fb .= chr($flags[$i]);
            }
            $i += $run;
        }

        return self::s16(count($contours)).self::s16(min($xs)).self::s16(min($ys)).self::s16(max($xs)).self::s16(max($ys))
            .implode('', array_map(fn (int $e) => pack('n', $e), $ends))
            .pack('n', strlen($instructions)).$instructions
            .$fb.$xb.$yb;
    }

    /**
     * A composite glyph.
     *
     * Each component: `gid`, then either `dx`/`dy` (offsets) or `points`
     * [parent point, child point] (anchor matching); optional `scale`
     * (uniform), `xy` [sx, sy], `matrix` [a, b, c, d] (F2Dot14), `words`
     * (16-bit arguments), `scaledOffset` (SCALED_COMPONENT_OFFSET).
     *
     * @param  list<array<string, mixed>>  $components
     * @param  array{0: int, 1: int, 2: int, 3: int}  $bbox
     */
    public static function compositeGlyph(array $components, array $bbox = [0, 0, 1000, 1000], string $instructions = ''): string
    {
        $out = self::s16(-1).self::s16($bbox[0]).self::s16($bbox[1]).self::s16($bbox[2]).self::s16($bbox[3]);
        $last = count($components) - 1;
        foreach ($components as $i => $c) {
            $xy = ! isset($c['points']);
            $a1 = $xy ? ($c['dx'] ?? 0) : $c['points'][0];
            $a2 = $xy ? ($c['dy'] ?? 0) : $c['points'][1];
            $words = ($c['words'] ?? false) || ($xy ? (abs($a1) > 127 || abs($a2) > 127) : ($a1 > 255 || $a2 > 255));
            $f = ($words ? 0x0001 : 0) | ($xy ? 0x0002 : 0) | ($i < $last ? 0x0020 : 0)
                | (($c['scaledOffset'] ?? false) ? 0x0800 : 0)
                | ($i === $last && $instructions !== '' ? 0x0100 : 0);
            $tail = '';
            if (isset($c['matrix'])) {
                $f |= 0x0080;
                $tail = implode('', array_map(fn (float $v) => self::f2dot14($v), $c['matrix']));
            } elseif (isset($c['xy'])) {
                $f |= 0x0040;
                $tail = self::f2dot14($c['xy'][0]).self::f2dot14($c['xy'][1]);
            } elseif (isset($c['scale'])) {
                $f |= 0x0008;
                $tail = self::f2dot14($c['scale']);
            }
            $args = $words
                ? ($xy ? self::s16($a1).self::s16($a2) : pack('nn', $a1, $a2))
                : ($xy ? pack('cc', $a1, $a2) : pack('CC', $a1, $a2));
            $out .= pack('nn', $f, $c['gid']).$args.$tail;
        }
        if ($instructions !== '') {
            $out .= pack('n', strlen($instructions)).$instructions;
        }

        return $out;
    }

    /**
     * The renderer's golden font: glyphs with real outlines, all drawn here.
     *
     * Every advance is a multiple of 50 units, so at font sizes that are
     * multiples of 10 each glyph origin lands on a half point: a whole pixel
     * at the oracle's 2x. PDFium snaps glyph origins to whole pixels
     * (measured 2026-10-21), so a golden whose origins are already on the
     * grid compares outlines, not snapping. lsb equals xMin throughout.
     *
     * Glyphs, by code point (and gid):
     *   space (1) empty; O (2) ring of off-curve points, inner contour reversed;
     *   D (3) lines + quadratics; A (4) triangle with a hole; S (5) a wave
     *   whose contour STARTS off-curve; o (6) a contour of off-curve points
     *   only; x (7) two overlapping bars (nonzero union); dot (8, unmapped);
     *   Ä (9) composite A + two dots by offset, 16-bit args; É (10) composite
     *   D + a dot scaled by 0.5 with x/y scale; Z (11) composite of A turned
     *   90 degrees by a 2x2 matrix; Q (12) composite of a composite (Ä) +
     *   a dot by POINT MATCHING; I (13) a bar with garbage instruction bytes
     *   (never executed); W (14) a uncompressed-coordinate zig-zag.
     */
    public static function outlined(): string
    {
        $ring = function (int $cx, int $cy, int $r, bool $reverse): array {
            $pts = [];
            for ($i = 0; $i < 8; $i++) {
                $a = M_PI / 4 * $i;
                // off-curve control points of a quadratic circle approximation
                $k = $r / cos(M_PI / 8);
                $pts[] = [(int) round($cx + $k * cos($a + M_PI / 8)), (int) round($cy + $k * sin($a + M_PI / 8)), false];
            }

            return $reverse ? $pts : array_reverse($pts);   // TrueType outer contours run clockwise
        };
        $glyphs = [];
        $glyphs[0] = ['data' => self::simpleGlyph([[[50, 0], [50, 700], [450, 700], [450, 0]], [[100, 50], [400, 50], [400, 650], [100, 650]]]), 'advance' => 500];
        $glyphs[1] = ['data' => '', 'advance' => 250, 'lsb' => 0];
        $glyphs[2] = ['data' => self::simpleGlyph([$ring(350, 350, 300, false), $ring(350, 350, 180, true)]), 'advance' => 700];
        $glyphs[3] = ['data' => self::simpleGlyph([[
            [60, 0], [60, 700], [300, 700], [620, 700, false], [620, 350], [620, 0, false], [300, 0],
        ], [
            [180, 120], [300, 120], [500, 120, false], [500, 350], [500, 580, false], [300, 580], [180, 580],
        ]]), 'advance' => 700];
        $glyphs[4] = ['data' => self::simpleGlyph([[[20, 0], [340, 700], [660, 0]], [[200, 120], [480, 120], [340, 430]]]), 'advance' => 700];
        $glyphs[5] = ['data' => self::simpleGlyph([[
            [100, 600, false], [300, 700], [500, 600, false], [500, 400, false], [300, 350], [100, 300, false],
            [100, 100, false], [300, 0], [500, 100, false], [500, 30], [300, -80, false], [40, 60, false],
            [40, 340, false], [300, 420], [400, 460, false], [400, 540, false], [300, 580], [60, 540, false],
        ]]), 'advance' => 600];
        $glyphs[6] = ['data' => self::simpleGlyph([[[80, 250, false], [250, 520, false], [420, 250, false], [250, -20, false]]]), 'advance' => 500];
        $glyphs[7] = ['data' => self::simpleGlyph([[[40, 0], [120, 0], [560, 500], [480, 500]], [[480, 0], [560, 0], [120, 500], [40, 500]]]), 'advance' => 600];
        $glyphs[8] = ['data' => self::simpleGlyph([[[0, 0], [0, 100], [100, 100], [100, 0]]]), 'advance' => 0];
        $glyphs[9] = ['data' => self::compositeGlyph([
            ['gid' => 4],
            ['gid' => 8, 'dx' => 180, 'dy' => 780, 'words' => true],
            ['gid' => 8, 'dx' => 400, 'dy' => 780, 'words' => true],
        ], [20, 0, 660, 880]), 'advance' => 700];
        $glyphs[10] = ['data' => self::compositeGlyph([
            ['gid' => 3],
            ['gid' => 8, 'dx' => 300, 'dy' => 760, 'xy' => [1.5, 0.5], 'words' => true],
        ], [60, 0, 620, 810]), 'advance' => 700];
        $glyphs[11] = ['data' => self::compositeGlyph([
            ['gid' => 4, 'dx' => 700, 'dy' => 0, 'matrix' => [0.0, 1.0, -1.0, 0.0], 'words' => true],
        ], [0, 20, 700, 660]), 'advance' => 750];
        $glyphs[12] = ['data' => self::compositeGlyph([
            ['gid' => 9],
            // parent point 1 is A's apex (340, 700); child point 0 is the dot's (0, 0)
            ['gid' => 8, 'points' => [1, 0]],
        ], [20, 0, 660, 880]), 'advance' => 700];
        $glyphs[13] = ['data' => self::simpleGlyph([[[150, 0], [150, 700], [350, 700], [350, 0]]], "\xB0\x01\x2B\xFF\x8D\x8D\x00\x00\x1C"), 'advance' => 500];
        $glyphs[14] = ['data' => self::simpleGlyph([[[30, 0], [230, 700], [450, 200], [670, 700], [870, 0], [670, 0], [450, 450], [230, 0]]], '', compress: false), 'advance' => 900];
        $map = [0x20 => 1, 0x4F => 2, 0x44 => 3, 0x41 => 4, 0x53 => 5, 0x6F => 6, 0x78 => 7, 0xC4 => 9, 0xC9 => 10, 0x5A => 11, 0x51 => 12, 0x49 => 13, 0x57 => 14];

        return self::build($glyphs, [[3, 1, 4, $map]]);
    }

    /** @param list<array{0: int, 1: int, 2: int, 3: array<int, int>}> $cmaps */
    private static function cmapTables(array $cmaps): string
    {
        $subtables = [];
        foreach ($cmaps as [$p, $e, $format, $map]) {
            ksort($map);
            $subtables[] = [$p, $e, match ($format) {
                0 => self::cmap0($map),
                4 => self::cmap4($map),
                6 => self::cmap6($map),
                12 => self::cmap12($map),
            }];
        }
        $head = pack('nn', 0, count($subtables));
        $off = 4 + 8 * count($subtables);
        $body = '';
        foreach ($subtables as [$p, $e, $data]) {
            $head .= pack('nnN', $p, $e, $off + strlen($body));
            $body .= $data;
        }

        return $head.$body;
    }

    /** @param array<int, int> $map */
    private static function cmap0(array $map): string
    {
        $ids = '';
        for ($c = 0; $c < 256; $c++) {
            $ids .= chr($map[$c] ?? 0);
        }

        return pack('nnn', 0, 262, 0).$ids;
    }

    /** @param array<int, int> $map */
    private static function cmap4(array $map): string
    {
        $segs = [];
        foreach ($map as $cp => $gid) {
            $segs[] = [$cp, $cp, ($gid - $cp) & 0xFFFF];
        }
        $segs[] = [0xFFFF, 0xFFFF, 1];
        $n = count($segs);
        $sr = 2 * (1 << self::log2($n));
        $f4 = pack('nnnn', $n * 2, $sr, self::log2($n), $n * 2 - $sr);
        $f4 .= implode('', array_map(fn ($s) => pack('n', $s[1]), $segs)).pack('n', 0);
        $f4 .= implode('', array_map(fn ($s) => pack('n', $s[0]), $segs));
        $f4 .= implode('', array_map(fn ($s) => pack('n', $s[2]), $segs));
        $f4 .= str_repeat("\0\0", $n);

        return pack('nnn', 4, 6 + strlen($f4), 0).$f4;
    }

    /** @param array<int, int> $map */
    private static function cmap6(array $map): string
    {
        $first = min(array_keys($map));
        $last = max(array_keys($map));
        $ids = '';
        for ($c = $first; $c <= $last; $c++) {
            $ids .= pack('n', $map[$c] ?? 0);
        }

        return pack('nnnnn', 6, 10 + strlen($ids), 0, $first, $last - $first + 1).$ids;
    }

    /** @param array<int, int> $map */
    private static function cmap12(array $map): string
    {
        $groups = '';
        foreach ($map as $cp => $gid) {
            $groups .= pack('NNN', $cp, $cp, $gid);
        }

        return pack('nnNNN', 12, 0, 16 + strlen($groups), 0, count($map)).$groups;
    }

    private static function f2dot14(float $v): string
    {
        return self::s16((int) round($v * 16384));
    }

    /** A one-contour square glyph spanning the advance. */
    private static function square(int $advance): string
    {
        $x0 = 50;
        $x1 = max(60, $advance - 50);

        return self::s16(1).self::s16($x0).self::s16(0).self::s16($x1).self::s16(700)
            .pack('n', 3)            // endPtsOfContours
            .pack('n', 0)            // no instructions
            .str_repeat("\x01", 4)   // four on-curve points, long coordinates
            .self::s16($x0).self::s16($x1 - $x0).self::s16(0).self::s16($x0 - $x1)
            .self::s16(0).self::s16(0).self::s16(700).self::s16(0);
    }

    /** @param list<int> $components */
    private static function composite(array $components, int $advance): string
    {
        $out = self::s16(-1).self::s16(0).self::s16(0).self::s16($advance).self::s16(900);
        foreach ($components as $i => $gid) {
            $more = $i < count($components) - 1 ? 0x0020 : 0;
            // ARGS_ARE_XY_VALUES (0x0002), byte args
            $out .= pack('nn', 0x0002 | $more, $gid).pack('cc', 0, $i === 0 ? 0 : 100);
        }

        return $out;
    }

    /** @param array<int, int> $map */
    private static function cmap(array $map): string
    {
        $bmp = array_filter($map, fn (int $cp) => $cp <= 0xFFFF, ARRAY_FILTER_USE_KEY);
        // Format 4, one segment per code point (simple and valid), plus 0xFFFF.
        $segs = [];
        foreach ($bmp as $cp => $gid) {
            $segs[] = [$cp, $cp, ($gid - $cp) & 0xFFFF];
        }
        $segs[] = [0xFFFF, 0xFFFF, 1];
        $n = count($segs);
        $sr = 2 * (1 << self::log2($n));
        $f4 = pack('nnnn', $n * 2, $sr, self::log2($n), $n * 2 - $sr);
        $f4 .= implode('', array_map(fn ($s) => pack('n', $s[1]), $segs)).pack('n', 0);
        $f4 .= implode('', array_map(fn ($s) => pack('n', $s[0]), $segs));
        $f4 .= implode('', array_map(fn ($s) => pack('n', $s[2]), $segs));
        $f4 .= str_repeat("\0\0", $n);
        $f4 = pack('nnn', 4, 6 + strlen($f4), 0).$f4;

        $groups = '';
        $count = 0;
        foreach ($map as $cp => $gid) {
            $groups .= pack('NNN', $cp, $cp, $gid);
            $count++;
        }
        $f12 = pack('nnNNN', 12, 0, 16 + strlen($groups), 0, $count).$groups;

        $hasAstral = max(array_keys($map)) > 0xFFFF;
        $subtables = [[3, 1, $f4]];
        if ($hasAstral) {
            $subtables[] = [3, 10, $f12];
        }
        $head = pack('nn', 0, count($subtables));
        $off = 4 + 8 * count($subtables);
        $body = '';
        foreach ($subtables as [$p, $e, $data]) {
            $head .= pack('nnN', $p, $e, $off + strlen($body));
            $body .= $data;
        }

        return $head.$body;
    }

    private static function os2(int $fsType): string
    {
        return pack('n', 4).self::s16(500).pack('n', 400).pack('n', 5).pack('n', $fsType)
            .str_repeat("\0", 20)                       // subscript/superscript/strikeout
            .self::s16(0)                               // sFamilyClass
            .str_repeat("\0", 10)                       // panose
            .str_repeat("\0", 16)                       // unicode ranges
            .'FPTS'                                     // vendor
            .pack('nnn', 0x40, 0x20, 0xFFFF)            // fsSelection, first, last char
            .self::s16(800).self::s16(-200).self::s16(0)   // typo asc/desc/gap
            .pack('nn', 800, 200)                       // win ascent/descent
            .str_repeat("\0", 8)                        // code page ranges
            .self::s16(480).self::s16(700)              // xHeight, capHeight
            .pack('nnn', 0, 0x20, 1);
    }

    private static function name(string $ps): string
    {
        $utf16 = mb_convert_encoding($ps, 'UTF-16BE', 'UTF-8');

        return pack('nnn', 0, 1, 6 + 12).pack('nnnnnn', 3, 1, 0x409, 6, strlen($utf16), 0).$utf16;
    }

    /** @param array<string, string> $tables */
    private static function sfnt(array $tables): string
    {
        ksort($tables, SORT_STRING);
        $n = count($tables);
        $sr = 16 * (1 << self::log2($n));
        $out = pack('Nnnnn', 0x00010000, $n, $sr, self::log2($n), $n * 16 - $sr);
        $offset = 12 + 16 * $n;
        $body = '';
        foreach ($tables as $tag => $data) {
            $out .= str_pad($tag, 4).pack('NNN', 0, $offset + strlen($body), strlen($data));
            $body .= $data.str_repeat("\0", (4 - strlen($data) % 4) % 4);
        }

        return $out.$body;
    }

    private static function log2(int $n): int
    {
        $e = 0;
        while ((2 << $e) <= $n) {
            $e++;
        }

        return $e;
    }

    private static function s16(int $v): string
    {
        return pack('n', $v & 0xFFFF);
    }
}
