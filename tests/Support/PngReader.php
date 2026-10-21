<?php

declare(strict_types=1);

namespace FinePrint\Tests\Support;

use RuntimeException;

/**
 * A PNG DECODER for tests: reads the renderer's own output and the committed
 * Chromium goldens back into pixels.
 *
 * Deliberately independent of `src/Render/PngEncoder`. A test that decoded
 * the encoder's output with the encoder's own assumptions would prove only
 * that the two agree, so this is written from the PNG specification and
 * checks every chunk CRC itself.
 *
 * 8-bit greyscale, grey+alpha, RGB and RGBA, non-interlaced, all five filters.
 * That covers our encoder and every screenshot Chromium writes.
 */
final class PngReader
{
    /**
     * @return array{width: int, height: int, channels: int, rgb: list<string>, filters: list<int>}
     *               rgb: one string of width*3 bytes per row, alpha composited on white
     */
    public static function read(string $png): array
    {
        if (substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new RuntimeException('not a PNG');
        }
        $pos = 8;
        $idat = '';
        $ihdr = null;
        $sawEnd = false;
        while ($pos + 12 <= strlen($png)) {
            $len = unpack('N', substr($png, $pos, 4))[1];
            $type = substr($png, $pos + 4, 4);
            $data = substr($png, $pos + 8, $len);
            $crc = unpack('N', substr($png, $pos + 8 + $len, 4))[1];
            if ((crc32($type.$data) & 0xFFFFFFFF) !== $crc) {
                throw new RuntimeException('bad CRC in '.$type);
            }
            if ($type === 'IHDR') {
                $ihdr = unpack('Nw/Nh/Cdepth/Ccolour/Ccomp/Cfilter/Cinterlace', $data);
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                $sawEnd = true;
                break;
            }
            $pos += 12 + $len;
        }
        if ($ihdr === null || ! $sawEnd) {
            throw new RuntimeException('missing IHDR or IEND');
        }
        if ($ihdr['depth'] !== 8 || $ihdr['interlace'] !== 0) {
            throw new RuntimeException('only 8-bit non-interlaced');
        }
        $channels = match ($ihdr['colour']) {
            0 => 1, 4 => 2, 2 => 3, 6 => 4,
            default => throw new RuntimeException('colour type '.$ihdr['colour']),
        };
        $w = $ihdr['w'];
        $h = $ihdr['h'];
        $raw = gzuncompress($idat);
        if ($raw === false) {
            throw new RuntimeException('bad zlib data');
        }
        $stride = $w * $channels;
        if (strlen($raw) !== ($stride + 1) * $h) {
            throw new RuntimeException('IDAT is '.strlen($raw).' bytes, expected '.(($stride + 1) * $h));
        }

        $rows = [];
        $filters = [];
        $prev = str_repeat("\0", $stride);
        for ($y = 0; $y < $h; $y++) {
            $f = ord($raw[$y * ($stride + 1)]);
            $line = substr($raw, $y * ($stride + 1) + 1, $stride);
            $out = self::unfilter($f, $line, $prev, $channels);
            $filters[] = $f;
            $prev = $out;
            $rows[] = self::toRgb($out, $w, $channels);
        }

        return ['width' => $w, 'height' => $h, 'channels' => $channels, 'rgb' => $rows, 'filters' => $filters];
    }

    private static function unfilter(int $f, string $line, string $prev, int $bpp): string
    {
        $n = strlen($line);
        $out = $line;
        for ($i = 0; $i < $n; $i++) {
            $a = $i >= $bpp ? ord($out[$i - $bpp]) : 0;
            $b = ord($prev[$i]);
            $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
            $x = ord($line[$i]);
            $v = match ($f) {
                0 => $x,
                1 => $x + $a,
                2 => $x + $b,
                3 => $x + intdiv($a + $b, 2),
                4 => $x + self::paeth($a, $b, $c),
                default => throw new RuntimeException('filter '.$f),
            };
            $out[$i] = chr($v & 0xFF);
        }

        return $out;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }

        return $pb <= $pc ? $b : $c;
    }

    private static function toRgb(string $row, int $w, int $channels): string
    {
        if ($channels === 3) {
            return $row;
        }
        $out = '';
        for ($x = 0; $x < $w; $x++) {
            $p = substr($row, $x * $channels, $channels);
            if ($channels === 1 || $channels === 2) {
                $v = [ord($p[0]), ord($p[0]), ord($p[0])];
                $alpha = $channels === 2 ? ord($p[1]) : 255;
            } else {
                $v = [ord($p[0]), ord($p[1]), ord($p[2])];
                $alpha = ord($p[3]);
            }
            foreach ($v as $c) {
                $out .= chr((int) round(($c * $alpha + 255 * (255 - $alpha)) / 255));
            }
        }

        return $out;
    }
}
