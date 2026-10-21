<?php

declare(strict_types=1);

namespace FinePrint\Tests\Support;

use FinePrint\Crypto\Aes;

/**
 * Encrypted PDFs for the decryption tests, written by our own code from the
 * specification (ISO 32000-1 7.6.3, ISO 32000-2 for R6).
 *
 * Independent of src/ wherever that is cheap: RC4, the password padding, and
 * Algorithms 2, 3, 4, 5 and 2.B are written here again. AES itself comes from
 * src/Crypto/Aes, which the FIPS-197 and SP 800-38A known-answer tests pin, and
 * LibreOffice's encrypted exports are the outside oracle (envelope sandbox).
 *
 * Deterministic: fixed IVs, salts and file keys, so a fixture never changes.
 */
final class Encryptor
{
    private const PAD = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    private const ID = "\x5A\x1B\x77\x10\x9C\xE2\x04\x3F\xA8\x61\x2D\xC0\x0E\x95\x47\xB3";

    /**
     * @param  list<string>  $pages  one line of text per page
     * @param  array{revision: int, cfm?: string, keyBits?: int, userPassword?: string, ownerPassword?: string, permissions?: int,
     *               encryptMetadata?: bool, title?: string, javascript?: string, xmp?: string, tamper?: callable(string): string,
     *               trailer?: string, damageStream?: bool}  $o
     */
    public static function pdf(array $pages, array $o): string
    {
        $r = $o['revision'];
        $user = $o['userPassword'] ?? '';
        $owner = $o['ownerPassword'] ?? 'owner';
        $p = $o['permissions'] ?? -4;
        $encMeta = $o['encryptMetadata'] ?? true;
        $aes = $r >= 5 || ($r === 4 && ($o['cfm'] ?? 'AESV2') === 'AESV2');
        $bits = match (true) {
            $r === 2 => 40,
            $r >= 5 => 256,
            $r === 4 => 128,
            default => $o['keyBits'] ?? 128,
        };

        // --- the encryption dictionary and the file key ---------------------
        if ($r <= 4) {
            $n = intdiv($bits, 8);
            $O = self::ownerEntry($owner, $user, $r, $n);
            $key = self::fileKey($user, $O, $p, $r, $n, $encMeta);
            $U = self::userEntry($key, $r);
            $dict = '/Filter /Standard /V '.($r === 2 ? 1 : ($r === 3 ? 2 : 4)).' /R '.$r.' /Length '.$bits
                .' /O <'.strtoupper(bin2hex($O)).'> /U <'.strtoupper(bin2hex($U)).'> /P '.$p;
            if ($r === 4) {
                $dict .= ' /CF << /StdCF << /CFM /'.($aes ? 'AESV2' : 'V2').' /AuthEvent /DocOpen /Length 16 >> >> /StmF /StdCF /StrF /StdCF';
                if (! $encMeta) {
                    $dict .= ' /EncryptMetadata false';
                }
            }
        } else {
            $key = hash('sha256', 'fine-print test file key', true);
            [$uvs, $uks, $ovs, $oks] = ['UVSALT01', 'UKSALT02', 'OVSALT03', 'OKSALT04'];
            $U = self::hash($r, $user, $uvs, '').$uvs.$uks;
            $UE = Aes::cbcEncrypt(self::hash($r, $user, $uks, ''), str_repeat("\0", 16), $key);
            $O = self::hash($r, $owner, $ovs, $U).$ovs.$oks;
            $OE = Aes::cbcEncrypt(self::hash($r, $owner, $oks, $U), str_repeat("\0", 16), $key);
            $perms = (new Aes($key))->encryptBlock(pack('V', $p)."\xFF\xFF\xFF\xFF".($encMeta ? 'T' : 'F').'adbWXYZ');
            $dict = '/Filter /Standard /V 5 /R '.$r.' /Length 256'
                .' /CF << /StdCF << /CFM /AESV3 /AuthEvent /DocOpen /Length 32 >> >> /StmF /StdCF /StrF /StdCF'
                .' /O <'.strtoupper(bin2hex($O)).'> /U <'.strtoupper(bin2hex($U)).'>'
                .' /OE <'.strtoupper(bin2hex($OE)).'> /UE <'.strtoupper(bin2hex($UE)).'>'
                .' /P '.$p.' /Perms <'.strtoupper(bin2hex($perms)).'>';
            if (! $encMeta) {
                $dict .= ' /EncryptMetadata false';
            }
        }

        $enc = fn (string $data, int $num) => self::encrypt($data, $num, $key, $r, $aes);

        // --- the document ---------------------------------------------------
        $b = new PdfBuilder();
        $catalog = $b->reserve();
        $tree = $b->reserve();
        $font = $b->object('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $kids = [];
        foreach ($pages as $i => $line) {
            $content = $b->reserve();
            $text = 'BT /F1 12 Tf 72 720 Td ('.addcslashes($line, '()\\').") Tj ET\n";
            $data = $enc($text, $content);
            if (($o['damageStream'] ?? false) && $i === 0) {
                $data = substr($data, 0, 21);   // not a whole number of AES blocks
            }
            $b->rawStream($data, '', $content);
            $kids[] = $b->object('<< /Type /Page /Parent '.$tree.' 0 R /Contents '.$content.' 0 R /Resources << /Font << /F1 '.$font.' 0 R >> >> >>');
        }
        $b->object('<< /Type /Pages /Kids ['.implode(' ', array_map(fn ($k) => $k.' 0 R', $kids)).'] /Count '.count($kids).' /MediaBox [0 0 612 792] >>', $tree);

        $info = $b->reserve();
        $b->object('<< /Title <'.bin2hex($enc($o['title'] ?? 'Encrypted fixture', $info)).'> /Producer <'.bin2hex($enc('fine-print Encryptor', $info)).'> >>', $info);

        $extra = '';
        if (isset($o['javascript'])) {
            $js = $b->reserve();
            $b->object('<< /S /JavaScript /JS <'.bin2hex($enc($o['javascript'], $js)).'> >>', $js);
            $extra .= ' /OpenAction '.$js.' 0 R';
        }
        if (isset($o['xmp'])) {
            $meta = $b->reserve();
            $packet = $o['xmp'];
            $b->rawStream($encMeta ? $enc($packet, $meta) : $packet, '/Type /Metadata /Subtype /XML', $meta);
            $extra .= ' /Metadata '.$meta.' 0 R';
        }
        $b->object('<< /Type /Catalog /Pages '.$tree.' 0 R'.$extra.' >>', $catalog);

        $encrypt = $b->reserve();
        $body = '<< '.$dict.' >>';
        if (isset($o['tamper'])) {
            $body = ($o['tamper'])($body);
        }
        $b->object(str_replace('{self}', (string) $encrypt, $body), $encrypt);

        $id = '<'.strtoupper(bin2hex(self::ID)).'>';

        return $b->build(['info' => $info, 'trailer' => ' '.($o['trailer'] ?? '/Encrypt '.$encrypt.' 0 R').' /ID ['.$id.' '.$id.']']);
    }

    private static function encrypt(string $data, int $num, string $fileKey, int $r, bool $aes): string
    {
        if ($r >= 5) {
            $iv = substr(md5('iv'.$num, true), 0, 16);

            return $iv.Aes::cbcEncrypt($fileKey, $iv, self::pkcs7($data));
        }
        $n = strlen($fileKey);
        $k = substr(md5($fileKey.substr(pack('V', $num), 0, 3)."\x00\x00".($aes ? 'sAlT' : ''), true), 0, min($n + 5, 16));
        if ($aes) {
            $iv = substr(md5('iv'.$num, true), 0, 16);

            return $iv.Aes::cbcEncrypt($k, $iv, self::pkcs7($data));
        }

        return self::rc4($k, $data);
    }

    private static function pkcs7(string $data): string
    {
        $pad = 16 - strlen($data) % 16;

        return $data.str_repeat(chr($pad), $pad);
    }

    /** Algorithm 3. */
    private static function ownerEntry(string $owner, string $user, int $r, int $n): string
    {
        $h = md5(self::padded($owner === '' ? $user : $owner), true);
        if ($r >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $h = md5($h, true);
            }
        }
        $k = substr($h, 0, $r === 2 ? 5 : $n);
        $out = self::rc4($k, self::padded($user));
        if ($r >= 3) {
            for ($i = 1; $i <= 19; $i++) {
                $out = self::rc4(self::xorKey($k, $i), $out);
            }
        }

        return $out;
    }

    /** Algorithm 2. */
    private static function fileKey(string $user, string $O, int $p, int $r, int $n, bool $encMeta): string
    {
        $in = self::padded($user).$O.pack('V', $p & 0xFFFFFFFF).self::ID;
        if ($r >= 4 && ! $encMeta) {
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

    /** Algorithms 4 and 5. */
    private static function userEntry(string $key, int $r): string
    {
        if ($r === 2) {
            return self::rc4($key, self::PAD);
        }
        $out = self::rc4($key, md5(self::PAD.self::ID, true));
        for ($i = 1; $i <= 19; $i++) {
            $out = self::rc4(self::xorKey($key, $i), $out);
        }

        return $out.str_repeat("\0", 16);
    }

    /** R5: SHA-256; R6: Algorithm 2.B. */
    private static function hash(int $r, string $password, string $salt, string $udata): string
    {
        $password = substr($password, 0, 127);
        $k = hash('sha256', $password.$salt.$udata, true);
        if ($r === 5) {
            return $k;
        }
        for ($i = 0; ; $i++) {
            $k1 = str_repeat($password.$k.$udata, 64);
            $e = Aes::cbcEncrypt(substr($k, 0, 16), substr($k, 16, 16), $k1);
            $sum = 0;
            for ($j = 0; $j < 16; $j++) {
                $sum += ord($e[$j]);
            }
            $k = hash(['sha256', 'sha384', 'sha512'][$sum % 3], $e, true);
            if ($i >= 63 && ord($e[strlen($e) - 1]) <= $i - 31) {
                break;
            }
        }

        return substr($k, 0, 32);
    }

    private static function padded(string $password): string
    {
        return substr($password.self::PAD, 0, 32);
    }

    private static function xorKey(string $key, int $i): string
    {
        $out = '';
        for ($j = 0; $j < strlen($key); $j++) {
            $out .= chr(ord($key[$j]) ^ $i);
        }

        return $out;
    }

    /** RC4, written here independently of src/Crypto/Rc4. */
    private static function rc4(string $key, string $data): string
    {
        $s = range(0, 255);
        $j = 0;
        $kl = strlen($key);
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $kl])) & 0xFF;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }
        $i = $j = 0;
        $out = '';
        for ($n = 0; $n < strlen($data); $n++) {
            $i = ($i + 1) & 0xFF;
            $j = ($j + $s[$i]) & 0xFF;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $out .= chr(ord($data[$n]) ^ $s[($s[$i] + $s[$j]) & 0xFF]);
        }

        return $out;
    }
}
