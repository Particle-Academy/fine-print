<?php

declare(strict_types=1);

namespace FinePrint\Render\Images;

use FinePrint\Budget;
use FinePrint\Exceptions\ImageException;
use FinePrint\Objects\PdfDict;
use FinePrint\Objects\PdfName;
use FinePrint\Objects\PdfStream;
use FinePrint\Parser\Keyword;
use FinePrint\Parser\Parser;
use FinePrint\Parser\PdfSyntaxException;

/** Inline dictionaries and binary boundaries, without interpreting sample bytes. */
final class InlineImageReader
{
    private const KEYS = ['W' => 'Width', 'H' => 'Height', 'BPC' => 'BitsPerComponent', 'CS' => 'ColorSpace',
        'D' => 'Decode', 'DP' => 'DecodeParms', 'F' => 'Filter', 'IM' => 'ImageMask', 'I' => 'Interpolate', 'L' => 'Length'];

    private const SPACES = ['G' => 'DeviceGray', 'RGB' => 'DeviceRGB', 'CMYK' => 'DeviceCMYK', 'I' => 'Indexed'];

    public static function read(Parser $p, Budget $budget): ?PdfStream
    {
        $entries = [];
        try {
            for ($pairs = 0; $pairs < 256; $pairs++) {
                $key = $p->parseValue();
                if ($key instanceof Keyword && $key->word === 'ID') {
                    break;
                }
                if (! $key instanceof PdfName) {
                    $p->seek(strlen($p->data()));

                    return null;
                }
                $entries[self::KEYS[$key->value] ?? $key->value] = $p->parseValue();
            }
        } catch (PdfSyntaxException) {
            $p->seek(strlen($p->data()));

            return null;
        }
        if ($pairs >= 256) {
            $p->seek(strlen($p->data()));

            return null;
        }
        try {
            SampledImage::dimensions($entries['Width'] ?? null, $entries['Height'] ?? null, $budget);
        } catch (ImageException) {
            $p->seek(strlen($p->data()));

            return null;
        }
        $space = $entries['ColorSpace'] ?? null;
        if ($space instanceof PdfName) {
            $entries['ColorSpace'] = new PdfName(self::SPACES[$space->value] ?? $space->value);
        } elseif (is_array($space)) {
            foreach ([0, 1] as $i) {
                if (($space[$i] ?? null) instanceof PdfName) {
                    $space[$i] = new PdfName(self::SPACES[$space[$i]->value] ?? $space[$i]->value);
                }
            }
            $entries['ColorSpace'] = $space;
        }
        $data = $p->data();
        $start = $p->position();
        if (! isset($data[$start]) || ! str_contains(" \t\r\n\f\0", $data[$start])) {
            $p->seek(strlen($data));

            return null;
        }
        $start += substr($data, $start, 2) === "\r\n" ? 2 : 1;
        $filter = $entries['Filter'] ?? null;
        $first = is_array($filter) ? ($filter[0] ?? null) : $filter;
        $name = $first instanceof PdfName ? $first->value : '';
        $end = null;
        $length = $entries['Length'] ?? null;
        if (is_int($length) && $length >= 0 && $length <= strlen($data) - $start) {
            $end = $start + $length;
        } elseif ($filter === null || $filter === []) {
            $w = $entries['Width'] ?? null;
            $h = $entries['Height'] ?? null;
            $mask = ($entries['ImageMask'] ?? null) === true;
            $bits = $entries['BitsPerComponent'] ?? ($mask ? 1 : null);
            $cs = $entries['ColorSpace'] ?? null;
            $channels = $mask ? 1 : ($cs instanceof PdfName ? match ($cs->value) {
                'DeviceGray' => 1, 'DeviceRGB' => 3, 'DeviceCMYK' => 4, default => 0,
            } : (is_array($cs) ? 1 : 0));
            if (is_int($w) && $w > 0 && is_int($h) && $h > 0 && in_array($bits, [1, 2, 4, 8, 16], true) && $channels > 0) {
                $size = ceil((float) $w * $channels * $bits / 8) * $h;
                if ($size <= strlen($data) - $start) {
                    $end = $start + (int) $size;
                }
            }
        } elseif (in_array($name, ['Fl', 'FlateDecode'], true)) {
            $end = self::flateEnd($data, $start, $budget);
        } elseif (in_array($name, ['DCT', 'DCTDecode'], true)) {
            $end = self::jpegEnd($data, $start, $budget);
        } elseif (in_array($name, ['LZW', 'LZWDecode'], true)) {
            $params = $entries['DecodeParms'] ?? null;
            $params = is_array($params) ? ($params[0] ?? null) : $params;
            $early = $params instanceof PdfDict && $params->get('EarlyChange') === 0 ? 0 : 1;
            $end = self::lzwEnd($data, $start, $budget, $early);
        } elseif (in_array($name, ['AHx', 'ASCIIHexDecode', 'A85', 'ASCII85Decode'], true)) {
            $marker = in_array($name, ['AHx', 'ASCIIHexDecode'], true) ? '>' : '~>';
            $at = strpos($data, $marker, $start);
            $end = $at === false ? null : $at + strlen($marker);
        } elseif (in_array($name, ['RL', 'RunLengthDecode'], true)) {
            for ($i = $start, $len = strlen($data); $i < $len;) {
                $budget->tick();
                $n = ord($data[$i++]);
                if ($n === 128) {
                    $end = $i;
                    break;
                }
                $i += $n < 128 ? $n + 1 : 1;
            }
        }
        if ($end !== null) {
            $after = $end + strspn($data, " \t\r\n\f\0", $end);
            if (substr($data, $after, 2) === 'EI' && self::boundary($data, $after + 2)) {
                $p->seek($after + 2);

                return new PdfStream(new PdfDict($entries), substr($data, $start, $end - $start));
            }
        }
        // Unknown filters and damaged boundaries use the traditional EI
        // delimiter scan, but are still validated by the sample/codec reader.
        $pos = $start;
        while (($at = strpos($data, 'EI', $pos)) !== false) {
            $budget->tick();
            if ($at > $start && str_contains(" \t\r\n\f\0", $data[$at - 1]) && self::boundary($data, $at + 2)) {
                $p->seek($at + 2);

                return new PdfStream(new PdfDict($entries), substr($data, $start, $at - 1 - $start));
            }
            $pos = $at + 2;
        }
        $p->seek(strlen($data));

        return null;
    }

    private static function boundary(string $data, int $at): bool
    {
        return ! isset($data[$at]) || str_contains(" \t\r\n\f\0/[<(", $data[$at]);
    }

    private static function flateEnd(string $data, int $start, Budget $budget): ?int
    {
        foreach ([ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_RAW] as $encoding) {
            $ctx = @inflate_init($encoding);
            if ($ctx === false) {
                continue;
            }
            $out = 0;
            for ($i = $start, $len = strlen($data); $i < $len; $i += 4096) {
                $piece = @inflate_add($ctx, substr($data, $i, 4096), ZLIB_SYNC_FLUSH);
                if ($piece === false) {
                    break;
                }
                $out += strlen($piece);
                $budget->decoding($out, max(1, inflate_get_read_len($ctx)));
                if (inflate_get_status($ctx) === ZLIB_STREAM_END) {
                    return $start + inflate_get_read_len($ctx);
                }
            }
        }

        return null;
    }

    /** Skip marker segments by length; EOI bytes inside APP/COM are data. */
    private static function jpegEnd(string $data, int $start, Budget $budget): ?int
    {
        if (substr($data, $start, 2) !== "\xFF\xD8") {
            return null;
        }
        $pos = $start + 2;
        $entropy = false;
        $len = strlen($data);
        while ($pos < $len) {
            $budget->tick();
            if ($entropy) {
                $next = strpos($data, "\xFF", $pos);
                if ($next === false) {
                    return null;
                }
                $pos = $next;
            }
            if ($data[$pos++] !== "\xFF") {
                return null;
            }
            while ($pos < $len && $data[$pos] === "\xFF") {
                $budget->tick();
                $pos++;
            }
            if ($pos >= $len) {
                return null;
            }
            $code = ord($data[$pos++]);
            if ($entropy && ($code === 0 || ($code >= 0xD0 && $code <= 0xD7))) {
                continue;
            }
            if ($code === 0xD9) {
                return $pos;
            }
            if ($len - $pos < 2 || $code === 0xD8 || $code === 0) {
                return null;
            }
            $size = (ord($data[$pos]) << 8) | ord($data[$pos + 1]);
            if ($size < 2 || $size > $len - $pos) {
                return null;
            }
            $pos += $size;
            $entropy = $code === 0xDA;
        }

        return null;
    }

    /** Locate EOD from code widths without allocating a decoded dictionary. */
    private static function lzwEnd(string $data, int $start, Budget $budget, int $early): ?int
    {
        $pos = $start;
        $bits = $buffer = 0;
        $width = 9;
        $next = 258;
        $previous = false;
        $len = strlen($data);
        while ($pos < $len || $bits >= $width) {
            $budget->tick();
            while ($bits < $width) {
                if ($pos >= $len) {
                    return null;
                }
                $buffer = ($buffer << 8) | ord($data[$pos++]);
                $bits += 8;
            }
            $code = ($buffer >> ($bits - $width)) & ((1 << $width) - 1);
            $bits -= $width;
            $buffer &= (1 << $bits) - 1;
            if ($code === 256) {
                $next = 258;
                $width = 9;
                $previous = false;
                continue;
            }
            if ($code === 257) {
                return $pos;
            }
            if ((! $previous && $code >= 256) || $code > $next) {
                return null;
            }
            if ($previous && $next < 4096) {
                $next++;
            }
            $previous = true;
            if ($next + $early >= (1 << $width) && $width < 12) {
                $width++;
            }
        }

        return null;
    }
}
