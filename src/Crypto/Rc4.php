<?php

declare(strict_types=1);

namespace FinePrint\Crypto;

/**
 * RC4, for PDF's standard security handler (R2-R4). Pure PHP.
 *
 * RC4 is broken as a cipher. It is here only to OPEN files that already use
 * it, never to protect anything.
 */
final class Rc4
{
    public static function crypt(string $key, string $data): string
    {
        $kl = strlen($key);
        if ($kl === 0) {
            return $data;
        }
        $s = range(0, 255);
        $j = 0;
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $kl])) & 0xFF;
            $t = $s[$i];
            $s[$i] = $s[$j];
            $s[$j] = $t;
        }
        $i = 0;
        $j = 0;
        $n = strlen($data);
        $out = '';
        for ($k = 0; $k < $n; $k++) {
            $i = ($i + 1) & 0xFF;
            $j = ($j + $s[$i]) & 0xFF;
            $t = $s[$i];
            $s[$i] = $s[$j];
            $s[$j] = $t;
            $out .= chr(ord($data[$k]) ^ $s[($s[$i] + $s[$j]) & 0xFF]);
        }

        return $out;
    }
}
