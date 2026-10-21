<?php

declare(strict_types=1);

namespace FinePrint\Security;

use FinePrint\Budget;
use FinePrint\Crypto\Aes;
use FinePrint\Crypto\Rc4;
use FinePrint\Exceptions\RefusedException;
use FinePrint\Objects\PdfDict;
use FinePrint\Objects\PdfName;
use FinePrint\Objects\PdfString;

/**
 * PDF's standard security handler, for files that open with an EMPTY user
 * password (owner ruling, 2026-10-21: "open empty-password files").
 *
 * Many producers "encrypt" a PDF with an empty user password only to set
 * permission flags. Such a file opens in every viewer without a prompt, so
 * refusing it read as a bug. This opens it; a file that needs a real password
 * is still refused as `encrypted`, and nothing here ever tries a password
 * other than "".
 *
 * Supported: V1/R2 (RC4 40-bit), V2/R3 (RC4 40-128-bit), V4/R4 (crypt
 * filters: RC4 or AES-128), V5/R5 and V5/R6 (AES-256). RC4 and AES are this
 * package's own (src/Crypto); md5 and sha-2 are PHP core.
 *
 * The /Encrypt dictionary is attacker-authored like everything else. Every
 * field is type- and length-checked before use: a malformed dictionary is
 * `corrupt`, an unsupported one (another handler, an unknown V, R or crypt
 * method) is `encrypted`. Neither the key nor anything derived from it ever
 * appears in a report.
 */
final class StandardSecurity
{
    private const PAD = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    /** Decrypt in slices this large, checking the time budget between them. */
    private const SLICE = 65536;

    /** Permission bits (ISO 32000-1 Table 22), by name. */
    private const PERMISSIONS = [
        'print' => 3, 'modify' => 4, 'copy' => 5, 'annotate' => 6,
        'fillForms' => 9, 'accessibility' => 10, 'assemble' => 11, 'printHighQuality' => 12,
    ];

    private string $key;

    /** Method for streams and for strings: 'identity', 'rc4', 'aes128' or 'aes256'. */
    private string $streams;

    private string $strings;

    private function __construct(
        private readonly int $v,
        private readonly int $r,
        private readonly int $p,
        public readonly bool $encryptMetadata,
        private readonly Budget $budget,
    ) {}

    /**
     * Authenticate with the empty user password and derive the file key.
     *
     * @throws RefusedException `encrypted` when a password is needed or the
     *                          handler is unsupported; `corrupt` when the
     *                          dictionary is malformed
     */
    public static function open(PdfDict $enc, string $id0, callable $resolve, Budget $budget): self
    {
        $filter = $resolve($enc->get('Filter'));
        if (! $filter instanceof PdfName) {
            throw self::corrupt('has no /Filter naming its security handler');
        }
        if ($filter->value !== 'Standard') {
            throw new RefusedException('encrypted', 'The file is encrypted with a security handler other than the standard one (certificates, or a vendor handler), which is not supported.');
        }
        $v = self::int($resolve($enc->get('V')) ?? 0, 'V');
        $r = self::int($resolve($enc->get('R')), 'R');
        $p = self::int($resolve($enc->get('P')), 'P') & 0xFFFFFFFF;
        $p = $p >= 0x80000000 ? $p - 0x100000000 : $p;
        if (! in_array($v, [1, 2, 4, 5], true) || ! in_array($r, [2, 3, 4, 5, 6], true)
            || ($v <= 2 && $r > 3) || ($v === 4 && $r !== 4) || ($v === 5 && $r < 5)) {
            throw new RefusedException('encrypted', 'The file uses an encryption version that is not supported (V '.$v.', R '.$r.').');
        }
        $meta = $resolve($enc->get('EncryptMetadata'));
        $s = new self($v, $r, $p, $meta !== false, $budget);

        // Methods, and the key length in bytes for RC4.
        $bits = $resolve($enc->get('Length'));
        if ($bits !== null && (! is_int($bits) || $bits % 8 !== 0 || $bits < 40 || ($v < 5 && $bits > 128) || $bits > 256)) {
            throw self::corrupt('declares a key length that is not a multiple of 8 between 40 and 128 bits');
        }
        $n = $v === 1 ? 5 : intdiv($bits ?? ($v >= 4 ? 128 : 40), 8);
        if ($v <= 2) {
            $s->streams = $s->strings = 'rc4';
        } else {
            $cf = $resolve($enc->get('CF'));
            $s->streams = self::method($resolve($enc->get('StmF')), $cf, $v, $resolve);
            $s->strings = self::method($resolve($enc->get('StrF')), $cf, $v, $resolve);
        }

        if ($r <= 4) {
            $o = self::bytes($resolve($enc->get('O')), 'O', 32);
            $u = self::bytes($resolve($enc->get('U')), 'U', 32);
            $key = self::keyR4($o, $p, $id0, $r, $n, $s->encryptMetadata);
            if (! self::userMatches($key, $u, $id0, $r)) {
                throw self::needsPassword();
            }
        } else {
            $o = self::bytes($resolve($enc->get('O')), 'O', 48);
            $u = self::bytes($resolve($enc->get('U')), 'U', 48);
            $ue = self::bytes($resolve($enc->get('UE')), 'UE', 32);
            if (strlen($ue) !== 32 || strlen(self::bytes($resolve($enc->get('OE')), 'OE', 32)) !== 32) {
                throw self::corrupt('has /OE or /UE of the wrong length');
            }
            $vs = substr($u, 32, 8);
            $ks = substr($u, 40, 8);
            if (! hash_equals(substr($u, 0, 32), self::hashR6('', $vs, '', $r, $budget))) {
                throw self::needsPassword();
            }
            $key = Aes::cbcDecrypt(self::hashR6('', $ks, '', $r, $budget), str_repeat("\0", 16), $ue);
        }
        $s->key = $key;

        return $s;
    }

    /** Decrypt a string of object `num gen`. Null when it cannot be decrypted. */
    public function string(string $bytes, int $num, int $gen): ?string
    {
        return $this->decrypt($this->strings, $bytes, $num, $gen);
    }

    /** Decrypt a stream of object `num gen`. Null when it cannot be decrypted. */
    public function stream(string $bytes, int $num, int $gen): ?string
    {
        return $this->decrypt($this->streams, $bytes, $num, $gen);
    }

    /**
     * What a host and an agent may know: never the key, never a password.
     *
     * @return array{encrypted: true, handler: string, version: int, revision: int, algorithm: string, encryptMetadata: bool, permissions: array<string, bool>}
     */
    public function report(): array
    {
        $perms = [];
        foreach (self::PERMISSIONS as $name => $bit) {
            $perms[$name] = ($this->p & (1 << ($bit - 1))) !== 0;
        }
        $method = $this->streams === 'identity' ? $this->strings : $this->streams;

        return [
            'encrypted' => true,
            'handler' => 'Standard',
            'version' => $this->v,
            'revision' => $this->r,
            'algorithm' => match ($method) {
                'aes128' => 'AES-128',
                'aes256' => 'AES-256',
                'rc4' => 'RC4-'.(8 * strlen($this->key)),
                default => 'none',
            },
            'encryptMetadata' => $this->encryptMetadata,
            'permissions' => $perms,
        ];
    }

    private function decrypt(string $method, string $data, int $num, int $gen): ?string
    {
        if ($method === 'identity' || $data === '') {
            return $data;
        }
        if ($method === 'rc4') {
            $out = '';
            $k = $this->objectKey($num, $gen, false);
            // RC4 is a stream cipher: slicing it would restart the keystream,
            // so the whole string goes through at once, budget-checked after.
            $out = Rc4::crypt($k, $data);
            $this->budget->check();

            return $out;
        }
        if (strlen($data) < 32 || strlen($data) % 16 !== 0) {
            return null;
        }
        $key = $method === 'aes256' ? $this->key : $this->objectKey($num, $gen, true);
        $iv = substr($data, 0, 16);
        $body = substr($data, 16);
        $out = '';
        for ($i = 0, $len = strlen($body); $i < $len; $i += self::SLICE) {
            $slice = substr($body, $i, self::SLICE);
            $out .= Aes::cbcDecrypt($key, $iv, $slice);
            $iv = substr($slice, -16);
            $this->budget->check();
        }
        $pad = ord($out[strlen($out) - 1]);
        if ($pad >= 1 && $pad <= 16 && substr($out, -$pad) === str_repeat(chr($pad), $pad)) {
            $out = substr($out, 0, -$pad);
        }

        return $out;
    }

    /** Algorithm 1: the per-object key for R2-R4. */
    private function objectKey(int $num, int $gen, bool $aes): string
    {
        $k = $this->key.substr(pack('V', $num), 0, 3).substr(pack('V', $gen), 0, 2).($aes ? 'sAlT' : '');

        return substr(md5($k, true), 0, min(strlen($this->key) + 5, 16));
    }

    /** Algorithm 2, with the empty user password. */
    private static function keyR4(string $o, int $p, string $id0, int $r, int $n, bool $encryptMetadata): string
    {
        $in = self::PAD.substr($o, 0, 32).pack('V', $p & 0xFFFFFFFF).$id0;
        if ($r >= 4 && ! $encryptMetadata) {
            $in .= "\xFF\xFF\xFF\xFF";
        }
        $h = md5($in, true);
        if ($r >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $h = md5(substr($h, 0, $n), true);
            }
        }

        return substr($h, 0, $r === 2 ? 5 : $n);
    }

    /** Algorithms 6, 4 and 5: does the empty password open this file? */
    private static function userMatches(string $key, string $u, string $id0, int $r): bool
    {
        if ($r === 2) {
            return hash_equals(substr($u, 0, 32), Rc4::crypt($key, self::PAD));
        }
        $x = Rc4::crypt($key, md5(self::PAD.$id0, true));
        for ($i = 1; $i <= 19; $i++) {
            $k = '';
            for ($j = 0, $l = strlen($key); $j < $l; $j++) {
                $k .= chr(ord($key[$j]) ^ $i);
            }
            $x = Rc4::crypt($k, $x);
        }

        return hash_equals(substr($u, 0, 16), $x);
    }

    /**
     * R5: SHA-256. R6: Algorithm 2.B. Bounded: the loop ends by round 288 at
     * the latest, because a byte cannot exceed 255.
     */
    private static function hashR6(string $password, string $salt, string $udata, int $r, Budget $budget): string
    {
        $k = hash('sha256', $password.$salt.$udata, true);
        if ($r === 5) {
            return $k;
        }
        for ($i = 0; $i < 300; $i++) {
            $e = Aes::cbcEncrypt(substr($k, 0, 16), substr($k, 16, 16), str_repeat($password.$k.$udata, 64));
            $sum = 0;
            for ($j = 0; $j < 16; $j++) {
                $sum += ord($e[$j]);
            }
            $k = hash(['sha256', 'sha384', 'sha512'][$sum % 3], $e, true);
            $budget->tick(64);
            if ($i >= 63 && ord($e[strlen($e) - 1]) <= $i - 31) {
                break;
            }
        }

        return substr($k, 0, 32);
    }

    private static function method(mixed $name, mixed $cf, int $v, callable $resolve): string
    {
        if ($name === null) {
            return 'identity';
        }
        if (! $name instanceof PdfName) {
            throw self::corrupt('names its crypt filter with something that is not a name');
        }
        if ($name->value === 'Identity') {
            return 'identity';
        }
        $filter = $cf instanceof PdfDict ? $resolve($cf->get($name->value)) : null;
        if (! $filter instanceof PdfDict) {
            throw self::corrupt('names a crypt filter it does not define');
        }
        $cfm = $resolve($filter->get('CFM'));
        $cfm = $cfm instanceof PdfName ? $cfm->value : 'None';

        return match (true) {
            $cfm === 'None' => 'identity',
            $cfm === 'V2' && $v === 4 => 'rc4',
            $cfm === 'AESV2' && $v === 4 => 'aes128',
            $cfm === 'AESV3' && $v === 5 => 'aes256',
            default => throw new RefusedException('encrypted', 'The file uses a crypt method that is not supported.'),
        };
    }

    private static function int(mixed $v, string $key): int
    {
        if (! is_int($v)) {
            throw self::corrupt('has a /'.$key.' that is not an integer');
        }

        return $v;
    }

    private static function bytes(mixed $v, string $key, int $min): string
    {
        if (! $v instanceof PdfString || strlen($v->bytes) < $min) {
            throw self::corrupt('has a missing or short /'.$key);
        }

        return $v->bytes;
    }

    private static function corrupt(string $what): RefusedException
    {
        return new RefusedException('corrupt', 'The file\'s encryption dictionary '.$what.'.');
    }

    private static function needsPassword(): RefusedException
    {
        return new RefusedException('encrypted', 'The file needs a password to open. Only files that open with an empty password are read.');
    }
}
