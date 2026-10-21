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
