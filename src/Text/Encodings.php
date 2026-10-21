<?php

declare(strict_types=1);

namespace FinePrint\Text;

/**
 * The single-byte encodings of ISO 32000-1 Annex D, as code -> Unicode code
 * point, plus the glyph-name list `/Differences` arrays are written in.
 *
 * Typed in from the specification's tables. Only the codes that differ from
 * identity are listed for each table; {@see map()} fills in the rest.
 */
final class Encodings
{
    /** StandardEncoding: ASCII except two quotes, plus the upper half. */
    private const STANDARD_UPPER = [
        0x27 => 0x2019, 0x60 => 0x2018,
        0xA1 => 0x00A1, 0xA2 => 0x00A2, 0xA3 => 0x00A3, 0xA4 => 0x2044, 0xA5 => 0x00A5, 0xA6 => 0x0192,
        0xA7 => 0x00A7, 0xA8 => 0x00A4, 0xA9 => 0x0027, 0xAA => 0x201C, 0xAB => 0x00AB, 0xAC => 0x2039,
        0xAD => 0x203A, 0xAE => 0xFB01, 0xAF => 0xFB02, 0xB1 => 0x2013, 0xB2 => 0x2020, 0xB3 => 0x2021,
        0xB4 => 0x00B7, 0xB6 => 0x00B6, 0xB7 => 0x2022, 0xB8 => 0x201A, 0xB9 => 0x201E, 0xBA => 0x201D,
        0xBB => 0x00BB, 0xBC => 0x2026, 0xBD => 0x2030, 0xBF => 0x00BF, 0xC1 => 0x0060, 0xC2 => 0x00B4,
        0xC3 => 0x02C6, 0xC4 => 0x02DC, 0xC5 => 0x00AF, 0xC6 => 0x02D8, 0xC7 => 0x02D9, 0xC8 => 0x00A8,
        0xCA => 0x02DA, 0xCB => 0x00B8, 0xCD => 0x02DD, 0xCE => 0x02DB, 0xCF => 0x02C7, 0xD0 => 0x2014,
        0xE1 => 0x00C6, 0xE3 => 0x00AA, 0xE8 => 0x0141, 0xE9 => 0x00D8, 0xEA => 0x0152, 0xEB => 0x00BA,
        0xF1 => 0x00E6, 0xF5 => 0x0131, 0xF8 => 0x0142, 0xF9 => 0x00F8, 0xFA => 0x0153, 0xFB => 0x00DF,
    ];

    /** WinAnsiEncoding (Windows-1252) where it is not Latin-1. */
    private const WIN_ANSI = [
        0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020,
        0x87 => 0x2021, 0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152,
        0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022,
        0x96 => 0x2013, 0x97 => 0x2014, 0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A,
        0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178, 0xA0 => 0x0020,
    ];

    /** MacRomanEncoding, upper half. */
    private const MAC_ROMAN = [
        0xC4, 0xC5, 0xC7, 0xC9, 0xD1, 0xD6, 0xDC, 0xE1, 0xE0, 0xE2, 0xE4, 0xE3, 0xE5, 0xE7, 0xE9, 0xE8,
        0xEA, 0xEB, 0xED, 0xEC, 0xEE, 0xEF, 0xF1, 0xF3, 0xF2, 0xF4, 0xF6, 0xF5, 0xFA, 0xF9, 0xFB, 0xFC,
        0x2020, 0xB0, 0xA2, 0xA3, 0xA7, 0x2022, 0xB6, 0xDF, 0xAE, 0xA9, 0x2122, 0xB4, 0xA8, 0x2260, 0xC6, 0xD8,
        0x221E, 0xB1, 0x2264, 0x2265, 0xA5, 0xB5, 0x2202, 0x2211, 0x220F, 0x3C0, 0x222B, 0xAA, 0xBA, 0x3A9, 0xE6, 0xF8,
        0xBF, 0xA1, 0xAC, 0x221A, 0x192, 0x2248, 0x2206, 0xAB, 0xBB, 0x2026, 0xA0, 0xC0, 0xC3, 0xD5, 0x152, 0x153,
        0x2013, 0x2014, 0x201C, 0x201D, 0x2018, 0x2019, 0xF7, 0x25CA, 0xFF, 0x178, 0x2044, 0xA4, 0x2039, 0x203A, 0xFB01, 0xFB02,
        0x2021, 0xB7, 0x201A, 0x201E, 0x2030, 0xC2, 0xCA, 0xC1, 0xCB, 0xC8, 0xCD, 0xCE, 0xCF, 0xCC, 0xD3, 0xD4,
        0xF8FF, 0xD2, 0xDA, 0xDB, 0xD9, 0x131, 0x2C6, 0x2DC, 0xAF, 0x2D8, 0x2D9, 0x2DA, 0xB8, 0x2DD, 0x2DB, 0x2C7,
    ];

    /** PDFDocEncoding where it is not Latin-1. */
    private const PDF_DOC = [
        0x18 => 0x02D8, 0x19 => 0x02C7, 0x1A => 0x02C6, 0x1B => 0x02D9, 0x1C => 0x02DD, 0x1D => 0x02DB,
        0x1E => 0x02DA, 0x1F => 0x02DC, 0x80 => 0x2022, 0x81 => 0x2020, 0x82 => 0x2021, 0x83 => 0x2026,
        0x84 => 0x2014, 0x85 => 0x2013, 0x86 => 0x0192, 0x87 => 0x2044, 0x88 => 0x2039, 0x89 => 0x203A,
        0x8A => 0x2212, 0x8B => 0x2030, 0x8C => 0x201E, 0x8D => 0x201C, 0x8E => 0x201D, 0x8F => 0x2018,
        0x90 => 0x2019, 0x91 => 0x201A, 0x92 => 0x2122, 0x93 => 0xFB01, 0x94 => 0xFB02, 0x95 => 0x0141,
        0x96 => 0x0152, 0x97 => 0x0160, 0x98 => 0x0178, 0x99 => 0x017D, 0x9A => 0x0131, 0x9B => 0x0142,
        0x9C => 0x0153, 0x9D => 0x0161, 0x9E => 0x017E, 0xA0 => 0x20AC,
    ];

    /** Latin-1 glyph names for 0xA1..0xFF, in code order. */
    private const LATIN1_NAMES = [
        'exclamdown', 'cent', 'sterling', 'currency', 'yen', 'brokenbar', 'section', 'dieresis', 'copyright',
        'ordfeminine', 'guillemotleft', 'logicalnot', 'sfthyphen', 'registered', 'macron', 'degree', 'plusminus',
        'twosuperior', 'threesuperior', 'acute', 'mu', 'paragraph', 'periodcentered', 'cedilla', 'onesuperior',
        'ordmasculine', 'guillemotright', 'onequarter', 'onehalf', 'threequarters', 'questiondown', 'Agrave',
        'Aacute', 'Acircumflex', 'Atilde', 'Adieresis', 'Aring', 'AE', 'Ccedilla', 'Egrave', 'Eacute',
        'Ecircumflex', 'Edieresis', 'Igrave', 'Iacute', 'Icircumflex', 'Idieresis', 'Eth', 'Ntilde', 'Ograve',
        'Oacute', 'Ocircumflex', 'Otilde', 'Odieresis', 'multiply', 'Oslash', 'Ugrave', 'Uacute', 'Ucircumflex',
        'Udieresis', 'Yacute', 'Thorn', 'germandbls', 'agrave', 'aacute', 'acircumflex', 'atilde', 'adieresis',
        'aring', 'ae', 'ccedilla', 'egrave', 'eacute', 'ecircumflex', 'edieresis', 'igrave', 'iacute',
        'icircumflex', 'idieresis', 'eth', 'ntilde', 'ograve', 'oacute', 'ocircumflex', 'otilde', 'odieresis',
        'divide', 'oslash', 'ugrave', 'uacute', 'ucircumflex', 'udieresis', 'yacute', 'thorn', 'ydieresis',
    ];

    /** ASCII glyph names for 0x20..0x7E, in code order. */
    private const ASCII_NAMES = [
        'space', 'exclam', 'quotedbl', 'numbersign', 'dollar', 'percent', 'ampersand', 'quotesingle',
        'parenleft', 'parenright', 'asterisk', 'plus', 'comma', 'hyphen', 'period', 'slash', 'zero', 'one',
        'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'colon', 'semicolon', 'less', 'equal',
        'greater', 'question', 'at',
    ];

    private const ASCII_NAMES_2 = [
        'bracketleft', 'backslash', 'bracketright', 'asciicircum', 'underscore', 'grave',
    ];

    private const ASCII_NAMES_3 = ['braceleft', 'bar', 'braceright', 'asciitilde'];

    /** Every other glyph name we map. */
    private const OTHER_NAMES = [
        'Euro' => 0x20AC, 'quotesinglbase' => 0x201A, 'florin' => 0x0192, 'quotedblbase' => 0x201E,
        'ellipsis' => 0x2026, 'dagger' => 0x2020, 'daggerdbl' => 0x2021, 'circumflex' => 0x02C6,
        'perthousand' => 0x2030, 'Scaron' => 0x0160, 'guilsinglleft' => 0x2039, 'OE' => 0x0152,
        'Zcaron' => 0x017D, 'quoteleft' => 0x2018, 'quoteright' => 0x2019, 'quotedblleft' => 0x201C,
        'quotedblright' => 0x201D, 'bullet' => 0x2022, 'endash' => 0x2013, 'emdash' => 0x2014,
        'tilde' => 0x02DC, 'trademark' => 0x2122, 'scaron' => 0x0161, 'guilsinglright' => 0x203A,
        'oe' => 0x0153, 'zcaron' => 0x017E, 'Ydieresis' => 0x0178, 'fi' => 0xFB01, 'fl' => 0xFB02,
        'ff' => 0xFB00, 'ffi' => 0xFB03, 'ffl' => 0xFB04, 'fraction' => 0x2044, 'dotlessi' => 0x0131,
        'Lslash' => 0x0141, 'lslash' => 0x0142, 'breve' => 0x02D8, 'caron' => 0x02C7, 'dotaccent' => 0x02D9,
        'hungarumlaut' => 0x02DD, 'ogonek' => 0x02DB, 'ring' => 0x02DA, 'minus' => 0x2212,
        'nbspace' => 0x00A0, 'nonbreakingspace' => 0x00A0, 'softhyphen' => 0x00AD, 'Delta' => 0x2206,
        'Omega' => 0x2126, 'pi' => 0x03C0, 'notequal' => 0x2260, 'infinity' => 0x221E,
        'lessequal' => 0x2264, 'greaterequal' => 0x2265, 'partialdiff' => 0x2202, 'summation' => 0x2211,
        'product' => 0x220F, 'integral' => 0x222B, 'radical' => 0x221A, 'approxequal' => 0x2248,
        'lozenge' => 0x25CA, 'apple' => 0xF8FF, 'mu1' => 0x00B5, 'middot' => 0x00B7,
    ];

    /** @var array<string, array<int, string>> */
    private static array $maps = [];

    /** @var ?array<string, int> */
    private static ?array $glyphs = null;

    /**
     * A named base encoding as code -> UTF-8, or null if the name is unknown.
     *
     * @return ?array<int, string>
     */
    public static function map(string $name): ?array
    {
        if (isset(self::$maps[$name])) {
            return self::$maps[$name];
        }

        $m = [];
        switch ($name) {
            case 'StandardEncoding':
                for ($c = 0x20; $c <= 0x7E; $c++) {
                    $m[$c] = $c;
                }
                $m = self::STANDARD_UPPER + $m;

                break;
            case 'WinAnsiEncoding':
                for ($c = 0x20; $c <= 0xFF; $c++) {
                    $m[$c] = $c;
                }
                foreach ([0x7F, 0x81, 0x8D, 0x8F, 0x90, 0x9D] as $undef) {
                    unset($m[$undef]);
                }
                $m = self::WIN_ANSI + $m;

                break;
            case 'MacRomanEncoding':
                for ($c = 0x20; $c <= 0x7E; $c++) {
                    $m[$c] = $c;
                }
                foreach (self::MAC_ROMAN as $i => $cp) {
                    $m[0x80 + $i] = $cp;
                }

                break;
            case 'PDFDocEncoding':
                for ($c = 0x00; $c <= 0xFF; $c++) {
                    $m[$c] = $c;
                }
                unset($m[0x7F], $m[0x9F], $m[0xAD]);
                $m = self::PDF_DOC + $m;

                break;
            default:
                return null;
        }

        $utf8 = [];
        foreach ($m as $code => $cp) {
            $utf8[$code] = mb_chr($cp, 'UTF-8');
        }

        return self::$maps[$name] = $utf8;
    }

    /**
     * A glyph name as UTF-8, or null when it is not one we can map.
     *
     * Handles the Adobe Glyph List forms that matter in practice: plain names,
     * `uniXXXX` (one or more code units), `uXXXX[XX]`, suffixed variants
     * (`a.sc`, `one.oldstyle`) and underscore ligatures (`f_f_i`).
     */
    public static function glyph(string $name): ?string
    {
        $glyphs = self::glyphs();
        if (isset($glyphs[$name])) {
            return mb_chr($glyphs[$name], 'UTF-8');
        }

        $dot = strpos($name, '.');
        if ($dot !== false && $dot > 0) {
            return self::glyph(substr($name, 0, $dot));
        }

        if (str_contains($name, '_')) {
            $out = '';
            foreach (explode('_', $name) as $part) {
                $g = self::glyph($part);
                if ($g === null) {
                    return null;
                }
                $out .= $g;
            }

            return $out;
        }

        if (preg_match('/^uni((?:[0-9A-F]{4})+)$/', $name, $m)) {
            $s = (string) @mb_convert_encoding((string) hex2bin($m[1]), 'UTF-8', 'UTF-16BE');

            return $s === '' ? null : $s;
        }

        if (preg_match('/^u([0-9A-F]{4,6})$/', $name, $m)) {
            $cp = (int) hexdec($m[1]);
            if ($cp <= 0x10FFFF && ($cp < 0xD800 || $cp > 0xDFFF)) {
                return mb_chr($cp, 'UTF-8') ?: null;
            }
        }

        return null;
    }

    /** @return array<string, int> */
    private static function glyphs(): array
    {
        if (self::$glyphs !== null) {
            return self::$glyphs;
        }

        $g = [];
        foreach (self::ASCII_NAMES as $i => $n) {
            $g[$n] = 0x20 + $i;
        }
        for ($c = ord('A'); $c <= ord('Z'); $c++) {
            $g[chr($c)] = $c;
        }
        foreach (self::ASCII_NAMES_2 as $i => $n) {
            $g[$n] = 0x5B + $i;
        }
        for ($c = ord('a'); $c <= ord('z'); $c++) {
            $g[chr($c)] = $c;
        }
        foreach (self::ASCII_NAMES_3 as $i => $n) {
            $g[$n] = 0x7B + $i;
        }
        foreach (self::LATIN1_NAMES as $i => $n) {
            $g[$n] = 0xA1 + $i;
        }

        return self::$glyphs = self::OTHER_NAMES + $g;
    }
}
