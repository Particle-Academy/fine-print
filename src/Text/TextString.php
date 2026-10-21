<?php

declare(strict_types=1);

namespace FinePrint\Text;

/**
 * Decodes a PDF "text string" (ISO 32000-1 7.9.2.2) — metadata, outline
 * titles, annotation contents — to UTF-8.
 *
 * UTF-16BE with a BOM, UTF-8 with a BOM (PDF 2.0), otherwise PDFDocEncoding.
 * Invisible characters are KEPT: removing them here would hide them from the
 * agent-read guard, which is the layer that decides what to do about them.
 */
final class TextString
{
    public static function decode(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return self::scrub((string) @mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE'));
        }
        if (str_starts_with($bytes, "\xFF\xFE")) {
            // Not allowed by the spec, written by real producers anyway.
            return self::scrub((string) @mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE'));
        }
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return self::scrub(substr($bytes, 3));
        }

        $map = Encodings::map('PDFDocEncoding') ?? [];
        $out = '';
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $out .= $map[ord($bytes[$i])] ?? "\u{FFFD}";
        }

        return $out;
    }

    public static function scrub(string $s): string
    {
        return mb_scrub($s, 'UTF-8');
    }
}
