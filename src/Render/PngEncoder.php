<?php

declare(strict_types=1);

namespace FinePrint\Render;

use InvalidArgumentException;

/**
 * A PNG encoder on PHP's bundled zlib: 8-bit RGB or RGBA, non-interlaced,
 * one IDAT, CRC-32 per chunk (ISO/IEC 15948).
 *
 * Every row carries a filter type. `adaptive` picks, per row, the filter with
 * the smallest sum of absolute signed bytes (the specification's recommended
 * heuristic), and the five fixed filters can be asked for by name.
 *
 * The DEFAULT is `none`, by measurement (2026-10-21, a 612 x 792 vector page,
 * PHP 8.4 without opcache): at scale 1, `none` encoded in 11 ms to 33 KB and
 * `adaptive` in 516 ms to 33 KB; at scale 2, 52 ms / 81 KB against
 * 1712 ms / 73 KB. A rendered page is large runs of flat colour, which zlib
 * already compresses well; filtering byte by byte in PHP costs thirty times
 * the time for at most a tenth of the size. Photographic content (P3c) may
 * change that, and the option stays for it.
 */
final class PngEncoder
{
    public const FILTERS = ['none' => 0, 'sub' => 1, 'up' => 2, 'average' => 3, 'paeth' => 4];

    /**
     * @param  list<string>  $rows  one string of width * channels bytes per row
     * @param  3|4  $channels  3 = RGB, 4 = RGBA
     * @param  'none'|'sub'|'up'|'average'|'paeth'|'adaptive'  $filter
     */
    public static function encode(int $width, int $height, array $rows, int $channels = 3, string $filter = 'none', int $level = 6): string
    {
        if ($width < 1 || $height < 1 || $width > 0x7FFFFFFF || $height > 0x7FFFFFFF) {
            throw new InvalidArgumentException('A PNG must be at least 1 x 1.');
        }
        if ($channels !== 3 && $channels !== 4) {
            throw new InvalidArgumentException('Only RGB (3) and RGBA (4) are written.');
        }
        if ($filter !== 'adaptive' && ! isset(self::FILTERS[$filter])) {
            throw new InvalidArgumentException('Unknown PNG filter "'.$filter.'".');
        }
        if (count($rows) !== $height) {
            throw new InvalidArgumentException('Expected '.$height.' rows, got '.count($rows).'.');
        }
        $stride = $width * $channels;

        $raw = '';
        $prev = str_repeat("\0", $stride);
        foreach (array_values($rows) as $y => $row) {
            if (strlen($row) !== $stride) {
                throw new InvalidArgumentException('Row '.$y.' is '.strlen($row).' bytes, expected '.$stride.'.');
            }
            $raw .= $filter === 'adaptive'
                ? self::adaptive($row, $prev, $channels)
                : chr(self::FILTERS[$filter]).self::filter(self::FILTERS[$filter], $row, $prev, $channels);
            $prev = $row;
        }

        $ihdr = pack('NNCCCCC', $width, $height, 8, $channels === 3 ? 2 : 6, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            .self::chunk('IHDR', $ihdr)
            .self::chunk('IDAT', (string) gzcompress($raw, $level))
            .self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    /** The cheapest filter by the sum-of-absolute-differences heuristic, with its type byte. */
    private static function adaptive(string $row, string $prev, int $bpp): string
    {
        // A row identical to the one above (blank page, solid band) is all
        // zeros under Up: the best possible, and found without arithmetic.
        if ($row === $prev) {
            return "\x02".str_repeat("\0", strlen($row));
        }
        // A uniform row (a run of one colour) is all zeros after its first
        // pixel under Sub.
        $first = substr($row, 0, $bpp);
        if ($row === str_repeat($first, intdiv(strlen($row), $bpp))) {
            return "\x01".$first.str_repeat("\0", strlen($row) - $bpp);
        }

        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ([0, 1, 2, 3, 4] as $type) {
            $f = $type === 0 ? $row : self::filter($type, $row, $prev, $bpp);
            $score = 0;
            foreach (count_chars($f, 1) as $byte => $n) {
                $score += ($byte < 128 ? $byte : 256 - $byte) * $n;
            }
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = chr($type).$f;
            }
        }

        return (string) $best;
    }

    private static function filter(int $type, string $row, string $prev, int $bpp): string
    {
        $n = strlen($row);
        if ($type === 0) {
            return $row;
        }
        $out = $row;
        for ($i = 0; $i < $n; $i++) {
            $x = ord($row[$i]);
            $a = $i >= $bpp ? ord($row[$i - $bpp]) : 0;
            $b = ord($prev[$i]);
            switch ($type) {
                case 1:
                    $v = $x - $a;
                    break;
                case 2:
                    $v = $x - $b;
                    break;
                case 3:
                    $v = $x - (($a + $b) >> 1);
                    break;
                default:
                    $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
                    $p = $a + $b - $c;
                    $pa = abs($p - $a);
                    $pb = abs($p - $b);
                    $pc = abs($p - $c);
                    $v = $x - ($pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c));
            }
            $out[$i] = chr($v & 0xFF);
        }

        return $out;
    }
}
