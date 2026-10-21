<?php

declare(strict_types=1);

namespace FinePrint\Tests\Support;

/**
 * PNG and JPEG files for the writer's image tests, made by our own code.
 *
 * `png()` writes any colour type, bit depth, filter and interlacing from a
 * pixel callback, so a test knows every sample it put in. `jpeg()` writes a
 * real, decodable baseline JPEG of a flat mid-grey: every DCT block has a zero
 * DC difference and an immediate end-of-block, coded with one-symbol Huffman
 * tables. It is the smallest file a JPEG decoder will accept, and it is all the
 * writer needs, since JPEG is embedded as-is.
 */
final class ImageBuilder
{
    /**
     * @param  callable(int $x, int $y): list<int>  $pixel  samples for one pixel, at the bit depth (palette index for type 3)
     * @param  list<array{0: int, 1: int, 2: int}>|null  $palette
     */
    public static function png(int $w, int $h, int $colorType, int $bitDepth, callable $pixel, ?array $palette = null, ?string $trns = null, bool $interlace = false, ?int $filter = null): string
    {
        $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$colorType];
        $bpp = max(1, intdiv($channels * $bitDepth, 8));

        $raw = '';
        $passes = $interlace
            ? [[0, 0, 8, 8], [4, 0, 8, 8], [0, 4, 4, 8], [2, 0, 4, 4], [0, 2, 2, 4], [1, 0, 2, 2], [0, 1, 1, 2]]
            : [[0, 0, 1, 1]];
        $row = 0;
        foreach ($passes as [$x0, $y0, $dx, $dy]) {
            for ($y = $y0; $y < $h; $y += $dy) {
                $samples = [];
                for ($x = $x0; $x < $w; $x += $dx) {
                    array_push($samples, ...$pixel($x, $y));
                }
                if ($samples === []) {
                    continue;
                }
                $line = self::pack($samples, $bitDepth);
                $type = $filter ?? ($row % 5);
                $prev = $row > 0 && isset($prevLine) && strlen($prevLine) === strlen($line) ? $prevLine : str_repeat("\0", strlen($line));
                $raw .= chr($type).self::filter($type, $line, $prev, $bpp);
                $prevLine = $line;
                $row++;
            }
            unset($prevLine);
            $row = 0;
        }

        $png = "\x89PNG\r\n\x1A\n";
        $png .= self::chunk('IHDR', pack('NNCCCCC', $w, $h, $bitDepth, $colorType, 0, 0, $interlace ? 1 : 0));
        if ($palette !== null) {
            $png .= self::chunk('PLTE', implode('', array_map(fn (array $c) => pack('CCC', ...$c), $palette)));
        }
        if ($trns !== null) {
            $png .= self::chunk('tRNS', $trns);
        }
        $z = (string) gzcompress($raw, 9);
        // Two IDAT chunks, because real encoders split and readers must join.
        $half = intdiv(strlen($z), 2);
        $png .= self::chunk('IDAT', substr($z, 0, $half)).self::chunk('IDAT', substr($z, $half));

        return $png.self::chunk('IEND', '');
    }

    /** A flat mid-grey baseline JPEG with 1 (grey) or 3 (YCbCr) components. */
    public static function jpeg(int $w, int $h, int $components = 3): string
    {
        $out = "\xFF\xD8";
        $out .= "\xFF\xE0".pack('n', 16)."JFIF\0".pack('nCnnCC', 0x0101, 0, 1, 1, 0, 0);
        // One quantisation table of ones.
        $out .= "\xFF\xDB".pack('n', 67)."\x00".str_repeat("\x01", 64);
        // SOF0
        $sof = pack('CnnC', 8, $h, $w, $components);
        for ($c = 1; $c <= $components; $c++) {
            $sof .= pack('CCC', $c, 0x11, 0);
        }
        $out .= "\xFF\xC0".pack('n', 2 + strlen($sof)).$sof;
        // DHT: DC table 0 and AC table 0, each one symbol with a 1-bit code.
        $one = "\x01".str_repeat("\x00", 15);
        $out .= "\xFF\xC4".pack('n', 2 + 17 + 1)."\x00".$one."\x00";   // DC: category 0
        $out .= "\xFF\xC4".pack('n', 2 + 17 + 1)."\x10".$one."\x00";   // AC: EOB
        // SOS
        $sos = pack('C', $components);
        for ($c = 1; $c <= $components; $c++) {
            $sos .= pack('CC', $c, 0x00);
        }
        $sos .= pack('CCC', 0, 63, 0);
        $out .= "\xFF\xDA".pack('n', 2 + strlen($sos)).$sos;
        // Every block is two zero bits (DC diff 0, EOB); pad with ones.
        $blocks = (int) (ceil($w / 8) * ceil($h / 8)) * $components;
        $bits = 2 * $blocks;
        $bytes = intdiv($bits + 7, 8);
        $data = str_repeat("\x00", $bytes);
        $pad = $bytes * 8 - $bits;
        if ($pad > 0) {
            $data[$bytes - 1] = chr((1 << $pad) - 1);
        }

        return $out.$data."\xFF\xD9";
    }

    /** @return string a data URL */
    public static function dataUrl(string $bytes, string $mime): string
    {
        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /** @param list<int> $samples */
    private static function pack(array $samples, int $bitDepth): string
    {
        if ($bitDepth === 8) {
            return pack('C*', ...$samples);
        }
        if ($bitDepth === 16) {
            return pack('n*', ...$samples);
        }
        $out = '';
        $acc = 0;
        $n = 0;
        foreach ($samples as $s) {
            $acc = ($acc << $bitDepth) | $s;
            $n += $bitDepth;
            if ($n === 8) {
                $out .= chr($acc);
                $acc = 0;
                $n = 0;
            }
        }
        if ($n > 0) {
            $out .= chr($acc << (8 - $n));
        }

        return $out;
    }

    private static function filter(int $type, string $line, string $prev, int $bpp): string
    {
        $out = '';
        $n = strlen($line);
        for ($i = 0; $i < $n; $i++) {
            $x = ord($line[$i]);
            $a = $i >= $bpp ? ord($line[$i - $bpp]) : 0;
            $b = ord($prev[$i]);
            $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
            $p = match ($type) {
                0 => 0,
                1 => $a,
                2 => $b,
                3 => intdiv($a + $b, 2),
                4 => self::paeth($a, $b, $c),
            };
            $out .= chr(($x - $p) & 0xFF);
        }

        return $out;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
