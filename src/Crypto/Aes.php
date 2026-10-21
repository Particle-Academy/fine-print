<?php

declare(strict_types=1);

namespace FinePrint\Crypto;

use InvalidArgumentException;

/**
 * AES (FIPS-197), 128/192/256-bit keys, in pure PHP: no openssl, nothing
 * third-party (owner ruling). Block encryption and decryption, plus CBC and
 * the single-block ECB that PDF's standard security handler needs.
 *
 * Table-driven (the usual T-table construction), because R6's key derivation
 * encrypts thousands of blocks per open. The S-box is GENERATED from its
 * definition (multiplicative inverse in GF(2^8), then the affine map) rather
 * than typed in, and the FIPS-197 and SP 800-38A known answers pin the result.
 *
 * Not constant-time. It decrypts files a user handed us with an empty
 * password; it is not a general-purpose cipher and must not become one.
 */
final class Aes
{
    /** @var list<int> */
    private static array $sbox = [];

    /** @var list<int> */
    private static array $inv = [];

    /** @var array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<int>} */
    private static array $te = [[], [], [], []];

    /** @var array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<int>} */
    private static array $td = [[], [], [], []];

    /** @var list<int> encryption round keys */
    private array $ek = [];

    /** @var list<int> decryption round keys (equivalent inverse cipher) */
    private array $dk = [];

    private int $rounds;

    public function __construct(string $key)
    {
        $len = strlen($key);
        if (! in_array($len, [16, 24, 32], true)) {
            throw new InvalidArgumentException('An AES key is 16, 24 or 32 bytes.');
        }
        self::tables();
        $nk = intdiv($len, 4);
        $this->rounds = $nk + 6;
        $total = 4 * ($this->rounds + 1);
        $w = array_values(unpack('N*', $key));
        $rcon = 1;
        $s = self::$sbox;
        for ($i = $nk; $i < $total; $i++) {
            $t = $w[$i - 1];
            if ($i % $nk === 0) {
                $t = (($t << 8) & 0xFFFFFFFF) | ($t >> 24);
                $t = ($s[$t >> 24] << 24) | ($s[($t >> 16) & 0xFF] << 16) | ($s[($t >> 8) & 0xFF] << 8) | $s[$t & 0xFF];
                $t ^= $rcon << 24;
                $rcon = self::xtime($rcon);
            } elseif ($nk > 6 && $i % $nk === 4) {
                $t = ($s[$t >> 24] << 24) | ($s[($t >> 16) & 0xFF] << 16) | ($s[($t >> 8) & 0xFF] << 8) | $s[$t & 0xFF];
            }
            $w[$i] = $w[$i - $nk] ^ $t;
        }
        $this->ek = $w;

        // Decryption keys: rounds reversed, InvMixColumns on the middle ones.
        [$td0, $td1, $td2, $td3] = self::$td;
        $dk = [];
        for ($r = 0; $r <= $this->rounds; $r++) {
            for ($c = 0; $c < 4; $c++) {
                $k = $w[4 * ($this->rounds - $r) + $c];
                if ($r > 0 && $r < $this->rounds) {
                    $k = $td0[$s[$k >> 24]] ^ $td1[$s[($k >> 16) & 0xFF]] ^ $td2[$s[($k >> 8) & 0xFF]] ^ $td3[$s[$k & 0xFF]];
                }
                $dk[] = $k;
            }
        }
        $this->dk = $dk;
    }

    public function encryptBlock(string $block): string
    {
        [$te0, $te1, $te2, $te3] = self::$te;
        $s = self::$sbox;
        $k = $this->ek;
        [, $s0, $s1, $s2, $s3] = unpack('N4', $block);
        $s0 ^= $k[0];
        $s1 ^= $k[1];
        $s2 ^= $k[2];
        $s3 ^= $k[3];
        for ($r = 1; $r < $this->rounds; $r++) {
            $o = 4 * $r;
            $t0 = $te0[$s0 >> 24] ^ $te1[($s1 >> 16) & 0xFF] ^ $te2[($s2 >> 8) & 0xFF] ^ $te3[$s3 & 0xFF] ^ $k[$o];
            $t1 = $te0[$s1 >> 24] ^ $te1[($s2 >> 16) & 0xFF] ^ $te2[($s3 >> 8) & 0xFF] ^ $te3[$s0 & 0xFF] ^ $k[$o + 1];
            $t2 = $te0[$s2 >> 24] ^ $te1[($s3 >> 16) & 0xFF] ^ $te2[($s0 >> 8) & 0xFF] ^ $te3[$s1 & 0xFF] ^ $k[$o + 2];
            $t3 = $te0[$s3 >> 24] ^ $te1[($s0 >> 16) & 0xFF] ^ $te2[($s1 >> 8) & 0xFF] ^ $te3[$s2 & 0xFF] ^ $k[$o + 3];
            [$s0, $s1, $s2, $s3] = [$t0, $t1, $t2, $t3];
        }
        $o = 4 * $this->rounds;

        return pack('N4',
            (($s[$s0 >> 24] << 24) | ($s[($s1 >> 16) & 0xFF] << 16) | ($s[($s2 >> 8) & 0xFF] << 8) | $s[$s3 & 0xFF]) ^ $k[$o],
            (($s[$s1 >> 24] << 24) | ($s[($s2 >> 16) & 0xFF] << 16) | ($s[($s3 >> 8) & 0xFF] << 8) | $s[$s0 & 0xFF]) ^ $k[$o + 1],
            (($s[$s2 >> 24] << 24) | ($s[($s3 >> 16) & 0xFF] << 16) | ($s[($s0 >> 8) & 0xFF] << 8) | $s[$s1 & 0xFF]) ^ $k[$o + 2],
            (($s[$s3 >> 24] << 24) | ($s[($s0 >> 16) & 0xFF] << 16) | ($s[($s1 >> 8) & 0xFF] << 8) | $s[$s2 & 0xFF]) ^ $k[$o + 3],
        );
    }

    public function decryptBlock(string $block): string
    {
        [$td0, $td1, $td2, $td3] = self::$td;
        $si = self::$inv;
        $k = $this->dk;
        [, $s0, $s1, $s2, $s3] = unpack('N4', $block);
        $s0 ^= $k[0];
        $s1 ^= $k[1];
        $s2 ^= $k[2];
        $s3 ^= $k[3];
        for ($r = 1; $r < $this->rounds; $r++) {
            $o = 4 * $r;
            $t0 = $td0[$s0 >> 24] ^ $td1[($s3 >> 16) & 0xFF] ^ $td2[($s2 >> 8) & 0xFF] ^ $td3[$s1 & 0xFF] ^ $k[$o];
            $t1 = $td0[$s1 >> 24] ^ $td1[($s0 >> 16) & 0xFF] ^ $td2[($s3 >> 8) & 0xFF] ^ $td3[$s2 & 0xFF] ^ $k[$o + 1];
            $t2 = $td0[$s2 >> 24] ^ $td1[($s1 >> 16) & 0xFF] ^ $td2[($s0 >> 8) & 0xFF] ^ $td3[$s3 & 0xFF] ^ $k[$o + 2];
            $t3 = $td0[$s3 >> 24] ^ $td1[($s2 >> 16) & 0xFF] ^ $td2[($s1 >> 8) & 0xFF] ^ $td3[$s0 & 0xFF] ^ $k[$o + 3];
            [$s0, $s1, $s2, $s3] = [$t0, $t1, $t2, $t3];
        }
        $o = 4 * $this->rounds;

        return pack('N4',
            (($si[$s0 >> 24] << 24) | ($si[($s3 >> 16) & 0xFF] << 16) | ($si[($s2 >> 8) & 0xFF] << 8) | $si[$s1 & 0xFF]) ^ $k[$o],
            (($si[$s1 >> 24] << 24) | ($si[($s0 >> 16) & 0xFF] << 16) | ($si[($s3 >> 8) & 0xFF] << 8) | $si[$s2 & 0xFF]) ^ $k[$o + 1],
            (($si[$s2 >> 24] << 24) | ($si[($s1 >> 16) & 0xFF] << 16) | ($si[($s0 >> 8) & 0xFF] << 8) | $si[$s3 & 0xFF]) ^ $k[$o + 2],
            (($si[$s3 >> 24] << 24) | ($si[($s2 >> 16) & 0xFF] << 16) | ($si[($s1 >> 8) & 0xFF] << 8) | $si[$s0 & 0xFF]) ^ $k[$o + 3],
        );
    }

    /** CBC encryption, no padding: `$data` must be whole blocks. */
    public static function cbcEncrypt(string $key, string $iv, string $data): string
    {
        if (strlen($iv) !== 16 || strlen($data) % 16 !== 0) {
            throw new InvalidArgumentException('CBC needs a 16-byte IV and whole 16-byte blocks.');
        }
        $aes = new self($key);
        $out = '';
        $prev = $iv;
        for ($i = 0, $n = strlen($data); $i < $n; $i += 16) {
            $prev = $aes->encryptBlock(substr($data, $i, 16) ^ $prev);
            $out .= $prev;
        }

        return $out;
    }

    /** CBC decryption, no padding removed: `$data` must be whole blocks. */
    public static function cbcDecrypt(string $key, string $iv, string $data): string
    {
        if (strlen($iv) !== 16 || strlen($data) % 16 !== 0) {
            throw new InvalidArgumentException('CBC needs a 16-byte IV and whole 16-byte blocks.');
        }
        $aes = new self($key);
        $out = '';
        $prev = $iv;
        for ($i = 0, $n = strlen($data); $i < $n; $i += 16) {
            $block = substr($data, $i, 16);
            $out .= $aes->decryptBlock($block) ^ $prev;
            $prev = $block;
        }

        return $out;
    }

    private static function xtime(int $b): int
    {
        return (($b << 1) ^ (($b & 0x80) !== 0 ? 0x1B : 0)) & 0xFF;
    }

    private static function mul(int $a, int $b): int
    {
        $p = 0;
        while ($b > 0) {
            if (($b & 1) !== 0) {
                $p ^= $a;
            }
            $a = self::xtime($a);
            $b >>= 1;
        }

        return $p;
    }

    private static function tables(): void
    {
        if (self::$sbox !== []) {
            return;
        }
        // S-box from its definition: walk GF(2^8) by powers of 3, so q is the
        // inverse of p, then apply the affine transformation.
        $sbox = array_fill(0, 256, 0);
        $p = 1;
        $q = 1;
        do {
            $p = $p ^ (($p << 1) & 0xFF) ^ (($p & 0x80) !== 0 ? 0x1B : 0);
            $q ^= $q << 1;
            $q ^= $q << 2;
            $q ^= $q << 4;
            $q &= 0xFF;
            if (($q & 0x80) !== 0) {
                $q ^= 0x09;
            }
            $rot = fn (int $x, int $n) => (($x << $n) | ($x >> (8 - $n))) & 0xFF;
            $sbox[$p] = ($q ^ $rot($q, 1) ^ $rot($q, 2) ^ $rot($q, 3) ^ $rot($q, 4) ^ 0x63) & 0xFF;
        } while ($p !== 1);
        $sbox[0] = 0x63;

        $inv = array_fill(0, 256, 0);
        foreach ($sbox as $i => $v) {
            $inv[$v] = $i;
        }

        $te = [[], [], [], []];
        $td = [[], [], [], []];
        for ($x = 0; $x < 256; $x++) {
            $s = $sbox[$x];
            $e = (self::mul($s, 2) << 24) | ($s << 16) | ($s << 8) | self::mul($s, 3);
            $i = $inv[$x];
            $d = (self::mul($i, 14) << 24) | (self::mul($i, 9) << 16) | (self::mul($i, 13) << 8) | self::mul($i, 11);
            for ($r = 0; $r < 4; $r++) {
                $te[$r][$x] = $e;
                $td[$r][$x] = $d;
                $e = (($e >> 8) | ($e << 24)) & 0xFFFFFFFF;
                $d = (($d >> 8) | ($d << 24)) & 0xFFFFFFFF;
            }
        }
        self::$sbox = $sbox;
        self::$inv = $inv;
        self::$te = $te;
        self::$td = $td;
    }
}
