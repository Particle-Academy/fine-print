<?php

declare(strict_types=1);

namespace FinePrint\Tests\Support;

/** Independent tiny baseline encoder: custom canonical tables, known coefficients. */
final class JpegBuilder
{
    public static function make(int $w, int $h, array $levels = [128], array $options = []): string
    {
        $n = count($levels);
        $quant = $options['quant'] ?? 1;
        $sampling = $options['sampling'] ?? array_fill(0, $n, [1, 1]);
        $maxH = max(array_column($sampling, 0));
        $maxV = max(array_column($sampling, 1));
        $sof = pack('CnnC', 8, $h, $w, $n);
        $sos = chr($n);
        for ($i = 0; $i < $n; $i++) {
            $sof .= pack('CCC', $i + 1, $sampling[$i][0] * 16 + $sampling[$i][1], 0);
            $sos .= pack('CC', $i + 1, 0);
        }
        $out = "\xFF\xD8";
        if (isset($options['adobe'])) {
            $out .= self::segment(0xEE, 'Adobe'.pack('nnnC', 100, 0, 0, $options['adobe']));
        }
        $out .= self::segment(0xDB, "\0".str_repeat(chr($quant), 64));
        $out .= self::segment(0xC0, $sof);
        // Categories 0..11, all four-bit canonical codes.
        $out .= self::segment(0xC4, "\0\0\0\0\x0C".str_repeat("\0", 12).pack('C*', ...range(0, 11)));
        $ac = [0, 0xF0];
        for ($run = 0; $run < 16; $run++) {
            for ($cat = 1; $cat <= 10; $cat++) {
                $ac[] = $run * 16 + $cat;
            }
        }
        $codes = array_flip($ac);
        $out .= self::segment(0xC4, "\x10".str_repeat("\0", 7).chr(count($ac)).str_repeat("\0", 8).pack('C*', ...$ac));
        $interval = $options['restart'] ?? 0;
        if ($interval > 0) {
            $out .= self::segment(0xDD, pack('n', $interval));
        }
        $out .= self::segment(0xDA, $sos."\0\x3F\0");
        $cols = (int) ceil($w / (8 * $maxH));
        $rows = (int) ceil($h / (8 * $maxV));
        $pred = array_fill(0, $n, 0);
        $bits = '';
        $mcu = 0;
        $restart = 0;
        // Derive the zigzag independently rather than sharing decoder data.
        $zigzag = [];
        for ($diagonal = 0; $diagonal <= 14; $diagonal++) {
            $line = [];
            for ($y = max(0, $diagonal - 7); $y <= min(7, $diagonal); $y++) {
                $line[] = $y * 8 + ($diagonal - $y);
            }
            array_push($zigzag, ...($diagonal % 2 === 0 ? array_reverse($line) : $line));
        }
        for ($my = 0; $my < $rows; $my++) {
            for ($mx = 0; $mx < $cols; $mx++) {
                foreach ($levels as $c => $level) {
                    for ($vy = 0; $vy < $sampling[$c][1]; $vy++) {
                        for ($hx = 0; $hx < $sampling[$c][0]; $hx++) {
                            $v = is_callable($level) ? $level($mx * $sampling[$c][0] + $hx, $my * $sampling[$c][1] + $vy) : $level;
                            $dc = (int) round(($v - 128) * 8 / $quant);
                            [$cat, $amplitude] = self::amplitude($dc - $pred[$c]);
                            $bits .= self::bits($cat, 4).$amplitude;
                            $pred[$c] = $dc;
                            $run = 0;
                            for ($k = 1; $k < 64; $k++) {
                                $coefficient = $options['ac'][$zigzag[$k]] ?? 0;
                                if ($coefficient === 0) {
                                    $run++;
                                    continue;
                                }
                                while ($run >= 16) {
                                    $bits .= self::bits($codes[0xF0], 8);
                                    $run -= 16;
                                }
                                [$cat, $amplitude] = self::amplitude($coefficient);
                                $bits .= self::bits($codes[$run * 16 + $cat], 8).$amplitude;
                                $run = 0;
                            }
                            if ($run > 0) {
                                $bits .= self::bits($codes[0], 8);
                            }
                        }
                    }
                }
                $mcu++;
                if ($interval > 0 && $mcu % $interval === 0 && $mcu < $cols * $rows) {
                    $out .= self::entropy($bits)."\xFF".chr(0xD0 + ($restart++ % 8));
                    $bits = '';
                    $pred = array_fill(0, $n, 0);
                }
            }
        }

        return $out.self::entropy($bits)."\xFF\xD9";
    }

    private static function segment(int $marker, string $body): string
    {
        return "\xFF".chr($marker).pack('n', strlen($body) + 2).$body;
    }

    /** Separate sequential scans, each carrying one unsubsampled component. */
    public static function separate(int $w, int $h, array $levels): string
    {
        $first = self::make($w, $h, [$levels[0]]);
        $sos = strpos($first, "\xFF\xDA");
        $header = substr($first, 0, $sos);
        $sof = pack('CnnC', 8, $h, $w, count($levels));
        foreach ($levels as $i => $_) {
            $sof .= pack('CCC', $i + 1, 0x11, 0);
        }
        $at = strpos($header, "\xFF\xC0");
        $header = substr_replace($header, self::segment(0xC0, $sof), $at, 13);
        foreach ($levels as $i => $level) {
            $mono = self::make($w, $h, [$level]);
            $scan = substr($mono, strpos($mono, "\xFF\xDA"), -2);
            $scan[5] = chr($i + 1);
            $header .= $scan;
        }

        return $header."\xFF\xD9";
    }

    private static function bits(int $value, int $size): string
    {
        return $size === 0 ? '' : str_pad(decbin($value), $size, '0', STR_PAD_LEFT);
    }

    private static function amplitude(int $value): array
    {
        if ($value === 0) {
            return [0, ''];
        }
        $size = strlen(decbin(abs($value)));

        return [$size, self::bits($value >= 0 ? $value : $value + (1 << $size) - 1, $size)];
    }

    private static function entropy(string $bits): string
    {
        $bits = str_pad($bits, (int) (ceil(strlen($bits) / 8) * 8), '1');
        $out = '';
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $byte = chr(bindec(substr($bits, $i, 8)));
            $out .= $byte.($byte === "\xFF" ? "\0" : '');
        }

        return $out;
    }
}
