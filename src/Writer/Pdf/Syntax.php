<?php

declare(strict_types=1);

namespace FinePrint\Writer\Pdf;

/**
 * PDF object syntax, written so that model text can never become syntax.
 *
 * Every string the model supplies is written as a HEX string (`<...>`): there
 * is no delimiter inside one to break out of, so a title such as
 * `) /OpenAction << /S /JavaScript ... >> (` stays a title. Names are only
 * ever written from this package's own constants, and are escaped anyway.
 */
final class Syntax
{
    /** A number: at most four decimals, no exponent, no negative zero. */
    public static function num(float|int $n): string
    {
        if (is_int($n)) {
            return (string) $n;
        }
        if (! is_finite($n)) {
            return '0';
        }
        $s = rtrim(rtrim(sprintf('%.4F', $n), '0'), '.');

        return $s === '-0' || $s === '' ? '0' : $s;
    }

    /** A name, `#xx`-escaping anything outside the regular characters. */
    public static function name(string $name): string
    {
        $out = '/';
        $n = strlen($name);
        for ($i = 0; $i < $n; $i++) {
            $c = $name[$i];
            $o = ord($c);
            if ($o < 0x21 || $o > 0x7E || str_contains('#()<>[]{}/%', $c)) {
                $out .= sprintf('#%02X', $o);
            } else {
                $out .= $c;
            }
        }

        return $out;
    }

    /** Raw bytes as a hex string. */
    public static function hex(string $bytes): string
    {
        return '<'.strtoupper(bin2hex($bytes)).'>';
    }

    /**
     * A text string (metadata, titles): UTF-16BE with a byte-order mark,
     * hex-encoded. C0 controls other than tab and newline are dropped.
     */
    public static function text(string $utf8): string
    {
        $clean = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', self::validUtf8($utf8));

        return self::hex("\xFE\xFF".mb_convert_encoding($clean, 'UTF-16BE', 'UTF-8'));
    }

    /** A plain ASCII string (already validated), hex-encoded. */
    public static function ascii(string $s): string
    {
        return self::hex($s);
    }

    /**
     * `#RRGGBB` as three operands for `rg`/`RG`.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (preg_match('/^[0-9A-Fa-f]{6}$/', $hex) !== 1) {
            return [0.0, 0.0, 0.0];
        }

        return [
            round(hexdec(substr($hex, 0, 2)) / 255, 4),
            round(hexdec(substr($hex, 2, 2)) / 255, 4),
            round(hexdec(substr($hex, 4, 2)) / 255, 4),
        ];
    }

    /** @param array{0: float, 1: float, 2: float} $rgb */
    public static function fill(array $rgb): string
    {
        return self::num($rgb[0]).' '.self::num($rgb[1]).' '.self::num($rgb[2]).' rg';
    }

    /** @param array{0: float, 1: float, 2: float} $rgb */
    public static function stroke(array $rgb): string
    {
        return self::num($rgb[0]).' '.self::num($rgb[1]).' '.self::num($rgb[2]).' RG';
    }

    public static function validUtf8(string $s): string
    {
        return mb_check_encoding($s, 'UTF-8') ? $s : (string) mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }
}
