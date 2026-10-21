<?php

declare(strict_types=1);

namespace FinePrint\Filters;

use FinePrint\Budget;
use FinePrint\Limits;
use FinePrint\Objects\PdfDict;

/**
 * The stream filters, ISO 32000-1 7.4: FlateDecode, LZWDecode, ASCII85Decode,
 * ASCIIHexDecode and RunLengthDecode, plus the PNG and TIFF predictors.
 *
 * Image codecs are NOT decoded. DCT, JPX, CCITT and JBIG2 throw
 * {@see OpaqueStreamException}; P1 never needs their pixels.
 *
 * Decoders are lenient the way viewers are: a truncated or damaged stream
 * yields what could be decoded before the damage rather than nothing.
 */
final class Filters
{
    private const ALIASES = [
        'Fl' => 'FlateDecode',
        'LZW' => 'LZWDecode',
        'A85' => 'ASCII85Decode',
        'AHx' => 'ASCIIHexDecode',
        'RL' => 'RunLengthDecode',
        'DCT' => 'DCTDecode',
        'CCF' => 'CCITTFaxDecode',
    ];

    private const OPAQUE = ['DCTDecode', 'JPXDecode', 'CCITTFaxDecode', 'JBIG2Decode', 'Crypt'];

    /**
     * @param  list<string>  $names  filter names, in application order
     * @param  list<?PdfDict>  $parms  decode parameters, aligned with $names
     *
     * @throws OpaqueStreamException
     */
    public static function decodeChain(array $names, string $data, array $parms = [], ?Budget $budget = null): string
    {
        $budget ??= new Budget(new Limits());

        foreach (array_values($names) as $i => $name) {
            $name = self::ALIASES[$name] ?? $name;
            $p = $parms[$i] ?? null;
            $p = $p instanceof PdfDict ? $p : null;

            if (in_array($name, self::OPAQUE, true)) {
                throw new OpaqueStreamException($name);
            }

            $in = strlen($data);
            $data = match ($name) {
                'FlateDecode' => self::predict(self::flate($data, $budget), $p),
                'LZWDecode' => self::predict(self::lzw($data, self::early($p), $budget), $p),
                'ASCII85Decode' => self::ascii85($data),
                'ASCIIHexDecode' => self::asciiHex($data),
                'RunLengthDecode' => self::runLength($data, $budget),
                default => throw new OpaqueStreamException($name),
            };
            $budget->decoding(strlen($data), $in);
            $budget->decoded(strlen($data));
        }

        return $data;
    }

    private static function flate(string $data, Budget $budget): string
    {
        $ended = false;
        $out = self::inflate($data, ZLIB_ENCODING_DEFLATE, $budget, $ended);
        if ($out === '' && $data !== '' && ! $ended) {
            // Some producers omit the zlib header and write raw deflate. A
            // zlib stream that ENDED with no output is simply empty (a blank
            // page's content), not a candidate for the retry.
            $out = self::inflate($data, ZLIB_ENCODING_RAW, $budget, $ended);
        }

        return $out;
    }

    private static function inflate(string $data, int $encoding, Budget $budget, bool &$ended = false): string
    {
        $ended = false;
        $ctx = @inflate_init($encoding);
        if ($ctx === false) {
            return '';
        }
        $out = '';
        $len = strlen($data);
        // Small input chunks bound how much ONE call can expand: deflate's
        // ceiling is about 1032:1, so 4 KB in is at most ~4 MB out.
        for ($i = 0; $i < $len; $i += 4096) {
            $piece = @inflate_add($ctx, substr($data, $i, 4096), ZLIB_SYNC_FLUSH);
            if ($piece === false) {
                break;
            }
            $out .= $piece;
            // Judged on the input consumed SO FAR, so a bomb is stopped after
            // a few megabytes, not after it has finished expanding.
            $budget->decoding(strlen($out), min($len, $i + 4096));
            if (inflate_get_status($ctx) === ZLIB_STREAM_END) {
                $ended = true;

                break;
            }
        }

        return $out;
    }

    private static function early(?PdfDict $p): int
    {
        $v = $p?->get('EarlyChange');

        return is_int($v) ? $v : 1;
    }

    private static function lzw(string $data, int $early, Budget $budget): string
    {
        $out = '';
        $len = strlen($data);
        $dict = self::lzwTable();
        $next = 258;
        $width = 9;
        $prev = null;
        $buf = 0;
        $bits = 0;
        $i = 0;
        $codes = 0;

        while (true) {
            while ($bits < $width) {
                if ($i >= $len) {
                    break 2;
                }
                $buf = ($buf << 8) | ord($data[$i++]);
                $bits += 8;
            }
            $code = ($buf >> ($bits - $width)) & ((1 << $width) - 1);
            $bits -= $width;
            $buf &= (1 << $bits) - 1;

            if ($code === 256) {
                $dict = self::lzwTable();
                $next = 258;
                $width = 9;
                $prev = null;

                continue;
            }
            if ($code === 257) {
                break;
            }
            if ($prev === null) {
                if (! isset($dict[$code])) {
                    break;
                }
                $out .= $dict[$code];
                $prev = $dict[$code];

                continue;
            }
            if (isset($dict[$code])) {
                $entry = $dict[$code];
            } elseif ($code === $next) {
                $entry = $prev.$prev[0];
            } else {
                break; // damaged
            }
            $out .= $entry;
            if ((++$codes & 0x3FF) === 0) {
                $budget->decoding(strlen($out), $i);
            }
            if ($next < 4096) {
                $dict[$next++] = $prev.$entry[0];
            }
            $prev = $entry;
            if ($next + $early >= (1 << $width) && $width < 12) {
                $width++;
            }
        }

        return $out;
    }

    /** @return array<int, string> */
    private static function lzwTable(): array
    {
        $t = [];
        for ($c = 0; $c < 256; $c++) {
            $t[$c] = chr($c);
        }

        return $t;
    }

    private static function ascii85(string $data): string
    {
        $data = (string) preg_replace('/\s+/', '', $data);
        if (str_starts_with($data, '<~')) {
            $data = substr($data, 2);
        }
        $end = strpos($data, '~');
        if ($end !== false) {
            $data = substr($data, 0, $end);
        }

        $out = '';
        $group = [];
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $c = $data[$i];
            if ($c === 'z' && $group === []) {
                $out .= "\x00\x00\x00\x00";

                continue;
            }
            $o = ord($c);
            if ($o < 33 || $o > 117) {
                continue;
            }
            $group[] = $o - 33;
            if (count($group) === 5) {
                $out .= self::a85Group($group, 4);
                $group = [];
            }
        }
        if ($group !== []) {
            $n = count($group);
            if ($n > 1) {
                $out .= self::a85Group(array_pad($group, 5, 84), $n - 1);
            }
        }

        return $out;
    }

    /** @param list<int> $g */
    private static function a85Group(array $g, int $bytes): string
    {
        $v = 0;
        foreach ($g as $d) {
            $v = $v * 85 + $d;
        }

        return substr(pack('N', $v & 0xFFFFFFFF), 0, $bytes);
    }

    private static function asciiHex(string $data): string
    {
        $end = strpos($data, '>');
        if ($end !== false) {
            $data = substr($data, 0, $end);
        }
        $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', $data);
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }

        return (string) hex2bin($hex);
    }

    private static function runLength(string $data, Budget $budget): string
    {
        $out = '';
        $len = strlen($data);
        $i = 0;
        $runs = 0;
        while ($i < $len) {
            if ((++$runs & 0x3FF) === 0) {
                $budget->decoding(strlen($out), $i);
            }
            $n = ord($data[$i++]);
            if ($n === 128) {
                break;
            }
            if ($n < 128) {
                $out .= substr($data, $i, $n + 1);
                $i += $n + 1;
            } else {
                if ($i >= $len) {
                    break;
                }
                $out .= str_repeat($data[$i++], 257 - $n);
            }
        }

        return $out;
    }

    /** Undo a PNG (>= 10) or TIFF (2) predictor. */
    private static function predict(string $data, ?PdfDict $p): string
    {
        $predictor = $p?->get('Predictor');
        if (! is_int($predictor) || $predictor < 2) {
            return $data;
        }
        $colors = max(1, (int) ($p->get('Colors') ?? 1));
        $bpc = max(1, (int) ($p->get('BitsPerComponent') ?? 8));
        $columns = max(1, (int) ($p->get('Columns') ?? 1));
        $bpp = max(1, intdiv($colors * $bpc + 7, 8));
        $rowLen = intdiv($colors * $bpc * $columns + 7, 8);

        if ($predictor === 2) {
            if ($bpc !== 8) {
                return $data;
            }
            $out = '';
            foreach (str_split($data, $rowLen) as $row) {
                $r = array_values(unpack('C*', $row) ?: []);
                for ($i = $bpp, $n = count($r); $i < $n; $i++) {
                    $r[$i] = ($r[$i] + $r[$i - $bpp]) & 0xFF;
                }
                $out .= pack('C*', ...$r);
            }

            return $out;
        }

        $out = '';
        $prior = array_fill(0, $rowLen, 0);
        $len = strlen($data);
        for ($pos = 0; $pos + 1 <= $len; $pos += $rowLen + 1) {
            $type = ord($data[$pos]);
            $row = substr($data, $pos + 1, $rowLen);
            if ($row === '') {
                break;
            }
            $cur = array_values(unpack('C*', $row) ?: []);
            $n = count($cur);
            for ($i = 0; $i < $n; $i++) {
                $left = $i >= $bpp ? $cur[$i - $bpp] : 0;
                $up = $prior[$i];
                $upLeft = $i >= $bpp ? $prior[$i - $bpp] : 0;
                $v = match ($type) {
                    1 => $cur[$i] + $left,
                    2 => $cur[$i] + $up,
                    3 => $cur[$i] + intdiv($left + $up, 2),
                    4 => $cur[$i] + self::paeth($left, $up, $upLeft),
                    default => $cur[$i],
                };
                $cur[$i] = $v & 0xFF;
            }
            $out .= pack('C*', ...$cur);
            $prior = array_pad($cur, $rowLen, 0);
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
}
