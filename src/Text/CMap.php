<?php

declare(strict_types=1);

namespace FinePrint\Text;

use FinePrint\Objects\PdfName;
use FinePrint\Objects\PdfString;
use FinePrint\Parser\Keyword;
use FinePrint\Parser\Parser;
use FinePrint\Parser\PdfSyntaxException;

/**
 * A ToUnicode CMap (ISO 32000-1 9.10.3): codespace ranges, bfchar and bfrange
 * (both the incrementing form and the array form).
 *
 * A CMap is attacker-authored like everything else in the file, so ranges are
 * NEVER expanded into a table — a `<0000> <FFFF>` range is one row, looked up
 * arithmetically — and the number of rows read is capped.
 */
final class CMap
{
    /** @var list<array{len: int, lo: int, hi: int}> */
    private array $codespace = [];

    /** @var array<string, string> raw code bytes => UTF-8 */
    private array $chars = [];

    /** @var list<array{len: int, lo: int, hi: int, dst: string|list<string>}> */
    private array $ranges = [];

    /** @var array<string, int> raw code bytes => CID (encoding CMaps) */
    private array $cidChars = [];

    /** @var list<array{len: int, lo: int, hi: int, cid: int}> */
    private array $cidRanges = [];

    public function __construct(string $source, int $maxEntries = 100_000)
    {
        $p = new Parser($source, refs: false);
        $operands = [];
        $entries = 0;
        try {
            while (! $p->atEnd()) {
                $v = $p->parseValue();
                if (! $v instanceof Keyword) {
                    $operands[] = $v;
                    if (count($operands) > 3 * $maxEntries) {
                        break;
                    }

                    continue;
                }
                switch ($v->word) {
                    case 'endcodespacerange':
                        for ($i = 0; $i + 1 < count($operands); $i += 2) {
                            if ($operands[$i] instanceof PdfString && $operands[$i + 1] instanceof PdfString) {
                                $lo = $operands[$i]->bytes;
                                $this->codespace[] = ['len' => strlen($lo), 'lo' => self::int($lo), 'hi' => self::int($operands[$i + 1]->bytes)];
                            }
                        }

                        break;
                    case 'endbfchar':
                        for ($i = 0; $i + 1 < count($operands) && $entries < $maxEntries; $i += 2, $entries++) {
                            $src = $operands[$i];
                            $dst = $operands[$i + 1];
                            if ($src instanceof PdfString) {
                                $this->chars[$src->bytes] = $dst instanceof PdfName
                                    ? (Encodings::glyph($dst->value) ?? '')
                                    : self::utf16($dst instanceof PdfString ? $dst->bytes : '');
                            }
                        }

                        break;
                    case 'endcidchar':
                        for ($i = 0; $i + 1 < count($operands) && $entries < $maxEntries; $i += 2, $entries++) {
                            if ($operands[$i] instanceof PdfString && is_int($operands[$i + 1]) && $operands[$i + 1] >= 0) {
                                $this->cidChars[$operands[$i]->bytes] = $operands[$i + 1];
                            }
                        }

                        break;
                    case 'endcidrange':
                        for ($i = 0; $i + 2 < count($operands) && $entries < $maxEntries; $i += 3, $entries++) {
                            [$lo, $hi, $dst] = [$operands[$i], $operands[$i + 1], $operands[$i + 2]];
                            if ($lo instanceof PdfString && $hi instanceof PdfString && is_int($dst) && $dst >= 0) {
                                $this->cidRanges[] = ['len' => strlen($lo->bytes), 'lo' => self::int($lo->bytes), 'hi' => self::int($hi->bytes), 'cid' => $dst];
                            }
                        }

                        break;
                    case 'endbfrange':
                        for ($i = 0; $i + 2 < count($operands) && $entries < $maxEntries; $i += 3, $entries++) {
                            [$lo, $hi, $dst] = [$operands[$i], $operands[$i + 1], $operands[$i + 2]];
                            if (! $lo instanceof PdfString || ! $hi instanceof PdfString) {
                                continue;
                            }
                            if (is_array($dst)) {
                                $dst = array_map(fn ($d) => $d instanceof PdfString ? self::utf16($d->bytes) : '', $dst);
                            } elseif ($dst instanceof PdfString) {
                                $dst = $dst->bytes;
                            } else {
                                continue;
                            }
                            $this->ranges[] = ['len' => strlen($lo->bytes), 'lo' => self::int($lo->bytes), 'hi' => self::int($hi->bytes), 'dst' => $dst];
                        }

                        break;
                }
                $operands = [];
            }
        } catch (PdfSyntaxException) {
            // A damaged CMap keeps whatever it defined before the damage.
        }
    }

    public function hasCodespace(): bool
    {
        return $this->codespace !== [];
    }

    /**
     * How many bfranges {@see lookup()} may scan for one code. A lookup is
     * linear in them (up to 100 000), so a caller budgeting per code must
     * weigh each code by this, or a million cheap-looking codes are hours.
     */
    public function rangeCount(): int
    {
        return count($this->ranges);
    }

    /** Whether this is an encoding CMap (it maps codes to CIDs). */
    public function hasCids(): bool
    {
        return $this->cidChars !== [] || $this->cidRanges !== [];
    }

    /** The CID for one code of an encoding CMap (cidchar, then cidrange), or null. */
    public function cid(string $code): ?int
    {
        if (isset($this->cidChars[$code])) {
            return $this->cidChars[$code];
        }
        $len = strlen($code);
        $v = self::int($code);
        foreach ($this->cidRanges as $r) {
            if ($r['len'] === $len && $v >= $r['lo'] && $v <= $r['hi']) {
                return $r['cid'] + ($v - $r['lo']);
            }
        }

        return null;
    }

    /**
     * Split a string of character codes per the codespace.
     *
     * @return list<string>
     */
    public function split(string $bytes, int $fallbackLength): array
    {
        $codes = [];
        $len = strlen($bytes);
        $i = 0;
        while ($i < $len) {
            $take = 0;
            foreach ($this->codespace as $cs) {
                if ($i + $cs['len'] <= $len) {
                    $v = self::int(substr($bytes, $i, $cs['len']));
                    if ($v >= $cs['lo'] && $v <= $cs['hi']) {
                        $take = $cs['len'];

                        break;
                    }
                }
            }
            if ($take === 0) {
                $take = min($fallbackLength, $len - $i);
            }
            $codes[] = substr($bytes, $i, $take);
            $i += $take;
        }

        return $codes;
    }

    /**
     * As {@see split()}, one code at a time: a show string can be megabytes,
     * and the renderer must not hold a list of every code in it.
     *
     * @return \Generator<int, string>
     */
    public function each(string $bytes, int $fallbackLength): \Generator
    {
        $len = strlen($bytes);
        $i = 0;
        while ($i < $len) {
            $take = 0;
            foreach ($this->codespace as $cs) {
                if ($i + $cs['len'] <= $len) {
                    $v = self::int(substr($bytes, $i, $cs['len']));
                    if ($v >= $cs['lo'] && $v <= $cs['hi']) {
                        $take = $cs['len'];

                        break;
                    }
                }
            }
            if ($take === 0) {
                $take = min($fallbackLength, $len - $i);
            }
            yield substr($bytes, $i, $take);
            $i += $take;
        }
    }

    /** The UTF-8 for one code, or null when the CMap does not map it. */
    public function lookup(string $code): ?string
    {
        if (isset($this->chars[$code])) {
            return $this->chars[$code];
        }
        $len = strlen($code);
        $v = self::int($code);
        foreach ($this->ranges as $r) {
            if ($r['len'] !== $len || $v < $r['lo'] || $v > $r['hi']) {
                continue;
            }
            $off = $v - $r['lo'];
            if (is_array($r['dst'])) {
                return $r['dst'][$off] ?? null;
            }
            $dst = $r['dst'];
            if ($dst === '') {
                return null;
            }
            // Increment the last code unit of the destination.
            if (strlen($dst) >= 2) {
                $head = substr($dst, 0, -2);
                $last = self::int(substr($dst, -2)) + $off;

                return self::utf16($head.pack('n', $last & 0xFFFF));
            }

            return self::utf16(chr((ord($dst) + $off) & 0xFF));
        }

        return null;
    }

    private static function int(string $bytes): int
    {
        $v = 0;
        $n = min(strlen($bytes), 7);
        for ($i = 0; $i < $n; $i++) {
            $v = ($v << 8) | ord($bytes[$i]);
        }

        return $v;
    }

    private static function utf16(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }
        if (strlen($bytes) % 2 === 1) {
            $bytes = "\x00".$bytes;
        }

        return mb_scrub((string) @mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE'), 'UTF-8');
    }
}
