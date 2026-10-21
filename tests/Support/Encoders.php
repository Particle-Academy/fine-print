<?php

declare(strict_types=1);

namespace FinePrint\Tests\Support;

/**
 * Encoders for the filters the reader decodes, so filter fixtures are made by
 * our own code. Written from the PDF specification (ISO 32000-1 7.4), not
 * from any decoder in src/, so a shared misreading cannot pass both sides.
 */
final class Encoders
{
    public static function asciiHex(string $data): string
    {
        // Lower-case, wrapped, with whitespace: the decoder must ignore it.
        return trim(chunk_split(bin2hex($data), 16, "\n")).'>';
    }

    public static function ascii85(string $data): string
    {
        $out = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i += 4) {
            $chunk = substr($data, $i, 4);
            $n = strlen($chunk);
            $chunk = str_pad($chunk, 4, "\x00");
            $v = unpack('N', $chunk)[1];
            if ($n === 4 && $v === 0) {
                $out .= 'z';

                continue;
            }
            $digits = '';
            for ($k = 0; $k < 5; $k++) {
                $digits = chr(33 + ($v % 85)).$digits;
                $v = intdiv($v, 85);
            }
            $out .= substr($digits, 0, $n + 1);
        }

        return $out.'~>';
    }

    public static function runLength(string $data): string
    {
        $out = '';
        $len = strlen($data);
        $i = 0;
        while ($i < $len) {
            // A run of identical bytes?
            $j = $i + 1;
            while ($j < $len && $j - $i < 128 && $data[$j] === $data[$i]) {
                $j++;
            }
            if ($j - $i >= 2) {
                $out .= chr(257 - ($j - $i)).$data[$i];
                $i = $j;

                continue;
            }
            // Literal run.
            $j = $i;
            while ($j < $len && $j - $i < 128 && ! ($j + 1 < $len && $data[$j] === $data[$j + 1])) {
                $j++;
            }
            if ($j === $i) {
                $j = $i + 1;
            }
            $out .= chr($j - $i - 1).substr($data, $i, $j - $i);
            $i = $j;
        }

        return $out."\x80";
    }

    /** LZW with EarlyChange = 1, the PDF default. */
    public static function lzw(string $data): string
    {
        $dict = [];
        for ($i = 0; $i < 256; $i++) {
            $dict[chr($i)] = $i;
        }
        $next = 258;
        $width = 9;
        $bits = '';
        $emit = function (int $code) use (&$bits, &$width): void {
            $bits .= str_pad(decbin($code), $width, '0', STR_PAD_LEFT);
        };
        $emit(256); // clear table

        $w = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $c = $data[$i];
            if (isset($dict[$w.$c])) {
                $w .= $c;

                continue;
            }
            $emit($dict[$w]);
            $dict[$w.$c] = $next++;
            // Early change: widen one code early.
            if ($next + 1 > (1 << $width) && $width < 12) {
                $width++;
            }
            if ($next >= 4094) {
                $emit(256);
                $dict = [];
                for ($k = 0; $k < 256; $k++) {
                    $dict[chr($k)] = $k;
                }
                $next = 258;
                $width = 9;
            }
            $w = $c;
        }
        if ($w !== '') {
            $emit($dict[$w]);
            // The decoder adds one more entry on reading that code, and may
            // widen before reading EOD; mirror it.
            $next++;
            if ($next + 1 > (1 << $width) && $width < 12) {
                $width++;
            }
        }
        $emit(257); // EOD

        $bits = str_pad($bits, (int) ceil(strlen($bits) / 8) * 8, '0');
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            $out .= chr(bindec($byte));
        }

        return $out;
    }

    /**
     * Raw deflate of a large run of one byte, built incrementally so the test
     * process never holds the expanded form: a decompression bomb.
     */
    public static function bomb(int $expandedBytes, string $byte = ' '): string
    {
        $ctx = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
        $chunk = str_repeat($byte, 1 << 20);
        $out = '';
        for ($done = 0; $done < $expandedBytes; $done += strlen($chunk)) {
            $out .= deflate_add($ctx, $chunk, ZLIB_NO_FLUSH);
        }

        return $out.deflate_add($ctx, '', ZLIB_FINISH);
    }
}
