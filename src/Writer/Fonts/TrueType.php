<?php

declare(strict_types=1);

namespace FinePrint\Writer\Fonts;

use FinePrint\Exceptions\FontException;

/**
 * A TrueType font file: the metrics a PDF needs, the cmap, and a subsetter.
 *
 * The file is untrusted. A host passes the writer a font that may itself have
 * been uploaded by a user, so every read is bounds-checked and every loop the
 * file can lengthen has a cap. A bad file is a {@see FontException}, never a
 * PHP warning, a hang or a read past the end.
 *
 * Subsetting KEEPS GLYPH IDS: unused glyphs become empty (zero-length in
 * `loca`) rather than being renumbered. The PDF side then uses
 * `/CIDToGIDMap /Identity`, so a glyph id is the character code, and nothing
 * has to be rewritten inside composite glyphs.
 *
 * Not supported yet, refused with a reason: CFF outlines (`OTTO`), font
 * collections (`ttcf`), and fonts whose `fsType` forbids embedding.
 */
final class TrueType
{
    private const MAX_TABLES = 64;

    private const MAX_COMPONENT_DEPTH = 16;

    /** Tables a subset carries: what a PDF TrueType consumer reads. */
    private const SUBSET_TABLES = ['cvt ', 'fpgm', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp', 'prep'];

    public readonly int $unitsPerEm;

    public readonly int $numGlyphs;

    public readonly int $ascent;

    public readonly int $descent;

    public readonly int $capHeight;

    /** @var array{0: int, 1: int, 2: int, 3: int} */
    public readonly array $bbox;

    public readonly float $italicAngle;

    public readonly bool $isFixedPitch;

    public readonly int $weightClass;

    public readonly int $fsType;

    public readonly string $postScriptName;

    /** @var array<string, array{offset: int, length: int}> */
    private array $tables = [];

    private int $numberOfHMetrics;

    private bool $longLoca;

    /** @var list<array{0: int, 1: int, 2: int, 3: int}> format 4 segments: start, end, delta, rangeOffsetPos (0 = none) */
    private array $format4 = [];

    /** @var list<array{0: int, 1: int, 2: int}> format 12 groups: start, end, startGlyph */
    private array $format12 = [];

    private function __construct(private readonly string $data) {}

    public static function parse(string $bytes): self
    {
        $tt = new self($bytes);
        $tt->read();

        return $tt;
    }

    /** Whether the font's licence bits allow embedding it in a document. */
    public function embeddable(): bool
    {
        // 0x0002 restricted-licence embedding; 0x0200 bitmap embedding only.
        return ($this->fsType & 0x0002) === 0 && ($this->fsType & 0x0200) === 0;
    }

    /** The glyph for a Unicode code point, or 0 (.notdef) when there is none. */
    public function glyphFor(int $cp): int
    {
        if ($this->format12 !== []) {
            $lo = 0;
            $hi = count($this->format12) - 1;
            while ($lo <= $hi) {
                $mid = intdiv($lo + $hi, 2);
                [$s, $e, $g] = $this->format12[$mid];
                if ($cp < $s) {
                    $hi = $mid - 1;
                } elseif ($cp > $e) {
                    $lo = $mid + 1;
                } else {
                    $gid = $g + ($cp - $s);

                    return $gid < $this->numGlyphs ? $gid : 0;
                }
            }

            return 0;
        }
        if ($cp > 0xFFFF) {
            return 0;
        }
        foreach ($this->format4 as [$start, $end, $delta, $rangePos]) {
            if ($cp > $end) {
                continue;
            }
            if ($cp < $start) {
                return 0;
            }
            if ($rangePos === 0) {
                $gid = ($cp + $delta) & 0xFFFF;
            } else {
                $addr = $rangePos + 2 * ($cp - $start);
                $g = $this->u16At($addr);
                $gid = $g === 0 ? 0 : (($g + $delta) & 0xFFFF);
            }

            return $gid < $this->numGlyphs ? $gid : 0;
        }

        return 0;
    }

    /** A glyph's advance width in font units. */
    public function advance(int $gid): int
    {
        if ($gid < 0 || $gid >= $this->numGlyphs) {
            return 0;
        }
        $hmtx = $this->tables['hmtx']['offset'];
        $i = min($gid, $this->numberOfHMetrics - 1);

        return $this->u16At($hmtx + 4 * $i);
    }

    /** Byte length of a glyph's outline in `glyf` (0 for an empty glyph). */
    public function glyphLength(int $gid): int
    {
        [$start, $end] = $this->glyphRange($gid);

        return $end - $start;
    }

    /** A glyph's offset inside the `glyf` table. */
    public function glyphOffset(int $gid): int
    {
        return $this->glyphRange($gid)[0];
    }

    /** A table's offset in the file (for tests that patch a fixture in place). */
    public function tableOffset(string $tag): int
    {
        return $this->tables[$tag]['offset'] ?? throw new FontException('The font has no '.$tag.' table.');
    }

    /**
     * A font program containing only the given glyphs (plus .notdef and the
     * components composite glyphs refer to), with glyph ids unchanged.
     *
     * Deterministic: the same set of glyphs gives the same bytes, whatever
     * order or repetition it was passed in.
     *
     * @param  list<int>  $gids
     */
    public function subset(array $gids): string
    {
        $keep = [0 => true];
        foreach ($gids as $g) {
            $this->closure((int) $g, $keep, 0);
        }

        $glyf = '';
        $loca = [];
        for ($g = 0; $g < $this->numGlyphs; $g++) {
            $loca[] = strlen($glyf);
            if (isset($keep[$g])) {
                [$s, $e] = $this->glyphRange($g);
                if ($e > $s) {
                    $glyf .= substr($this->data, $this->tables['glyf']['offset'] + $s, $e - $s);
                    $glyf .= str_repeat("\0", (4 - strlen($glyf) % 4) % 4);
                }
            }
        }
        $loca[] = strlen($glyf);

        $out = [];
        foreach (self::SUBSET_TABLES as $tag) {
            if ($tag === 'glyf') {
                $out[$tag] = $glyf;
            } elseif ($tag === 'loca') {
                $out[$tag] = pack('N*', ...$loca);
            } elseif (isset($this->tables[$tag])) {
                $out[$tag] = substr($this->data, $this->tables[$tag]['offset'], $this->tables[$tag]['length']);
            }
        }
        // head: zero checkSumAdjustment, long loca offsets.
        $head = $out['head'];
        $head = substr_replace($head, "\0\0\0\0", 8, 4);
        $head = substr_replace($head, pack('n', 1), 50, 2);
        $out['head'] = $head;

        $font = self::assemble($out);
        $adjust = (0xB1B0AFBA - self::checksum($font)) & 0xFFFFFFFF;
        $headAt = self::directoryOffset($font, 'head');

        return substr_replace($font, pack('N', $adjust), $headAt + 8, 4);
    }

    // ------------------------------------------------------------------
    // Parsing

    private function read(): void
    {
        $tag = substr($this->data, 0, 4);
        if ($tag === 'OTTO') {
            throw new FontException('This is an OpenType font with CFF outlines; only TrueType (glyf) outlines can be embedded so far.');
        }
        if ($tag === 'ttcf') {
            throw new FontException('This is a font collection (.ttc); pass a single .ttf instead.');
        }
        if (strlen($this->data) < 12 || ! in_array($tag, ["\x00\x01\x00\x00", 'true'], true)) {
            throw new FontException('This is not a TrueType font file.');
        }

        $num = $this->u16At(4);
        if ($num === 0 || $num > self::MAX_TABLES) {
            throw new FontException('The font declares '.$num.' tables, which is not a usable TrueType file.');
        }
        $len = strlen($this->data);
        for ($i = 0; $i < $num; $i++) {
            $rec = 12 + 16 * $i;
            $t = $this->bytesAt($rec, 4);
            $off = $this->u32At($rec + 8);
            $length = $this->u32At($rec + 12);
            if ($off > $len || $length > $len - $off) {
                throw new FontException('The font\'s "'.self::printable($t).'" table lies outside the file.');
            }
            $this->tables[$t] = ['offset' => $off, 'length' => $length];
        }
        foreach (['head', 'hhea', 'maxp', 'hmtx'] as $required) {
            if (! isset($this->tables[$required])) {
                throw new FontException('The font has no '.$required.' table.');
            }
        }
        if (! isset($this->tables['glyf'], $this->tables['loca'])) {
            throw new FontException('The font has no glyf/loca outlines; only TrueType outlines can be embedded so far.');
        }

        $head = $this->table('head', 54);
        $this->unitsPerEm = $this->u16At($head + 18);
        if ($this->unitsPerEm < 16 || $this->unitsPerEm > 16384) {
            throw new FontException('The font\'s unitsPerEm is out of range.');
        }
        $this->bbox = [$this->s16At($head + 36), $this->s16At($head + 38), $this->s16At($head + 40), $this->s16At($head + 42)];
        $this->longLoca = $this->s16At($head + 50) === 1;

        $maxp = $this->table('maxp', 6);
        $this->numGlyphs = $this->u16At($maxp + 4);
        if ($this->numGlyphs < 1) {
            throw new FontException('The font has no glyphs.');
        }

        $hhea = $this->table('hhea', 36);
        $this->ascent = $this->s16At($hhea + 4);
        $this->descent = $this->s16At($hhea + 6);
        $this->numberOfHMetrics = $this->u16At($hhea + 34);
        if ($this->numberOfHMetrics < 1 || $this->numberOfHMetrics > $this->numGlyphs) {
            throw new FontException('The font\'s horizontal metrics count is out of range.');
        }
        $this->table('hmtx', 4 * $this->numberOfHMetrics + 2 * ($this->numGlyphs - $this->numberOfHMetrics));
        $this->table('loca', ($this->longLoca ? 4 : 2) * ($this->numGlyphs + 1));

        $capHeight = (int) round($this->ascent * 0.7);
        $weight = 400;
        $fsType = 0;
        if (isset($this->tables['OS/2']) && $this->tables['OS/2']['length'] >= 10) {
            $os2 = $this->tables['OS/2']['offset'];
            $weight = $this->u16At($os2 + 4);
            $fsType = $this->u16At($os2 + 8);
            if ($this->u16At($os2) >= 2 && $this->tables['OS/2']['length'] >= 90) {
                $capHeight = $this->s16At($os2 + 88);
            }
        }
        $this->capHeight = $capHeight;
        $this->weightClass = $weight;
        $this->fsType = $fsType;

        $italic = 0.0;
        $fixed = false;
        if (isset($this->tables['post']) && $this->tables['post']['length'] >= 16) {
            $post = $this->tables['post']['offset'];
            $raw = $this->u32At($post + 4);
            if ($raw >= 0x80000000) {
                $raw -= 0x100000000;
            }
            $italic = round($raw / 65536, 3);
            $fixed = $this->u32At($post + 12) !== 0;
        }
        $this->italicAngle = $italic;
        $this->isFixedPitch = $fixed;

        $this->postScriptName = $this->readPostScriptName();
        // A subset this class wrote has no cmap (the PDF maps glyph ids
        // directly), so a missing cmap maps nothing rather than failing.
        if (isset($this->tables['cmap'])) {
            $this->readCmap();
        }
    }

    private function readPostScriptName(): string
    {
        $fallback = 'Embedded';
        if (! isset($this->tables['name']) || $this->tables['name']['length'] < 6) {
            return $fallback;
        }
        $base = $this->tables['name']['offset'];
        $count = min($this->u16At($base + 2), 1024);
        $strings = $base + $this->u16At($base + 4);
        $end = $base + $this->tables['name']['length'];
        for ($i = 0; $i < $count; $i++) {
            $rec = $base + 6 + 12 * $i;
            if ($rec + 12 > $end) {
                break;
            }
            [$platform, $encoding, , $nameId, $length, $offset] = array_values(unpack('n6', $this->bytesAt($rec, 12)));
            if ($nameId !== 6 || $strings + $offset + $length > $end) {
                continue;
            }
            $raw = $this->bytesAt($strings + $offset, $length);
            $name = ($platform === 3 || $platform === 0) ? (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE') : $raw;
            // A PostScript name is printable ASCII without delimiters; anything
            // else is dropped rather than carried into a PDF name.
            $name = (string) preg_replace('/[^A-Za-z0-9_.\-]/', '', $name);
            if ($name !== '') {
                return substr($name, 0, 63);
            }
        }

        return $fallback;
    }

    private function readCmap(): void
    {
        $base = $this->table('cmap', 4);
        $end = $base + $this->tables['cmap']['length'];
        $count = min($this->u16At($base + 2), 64);
        $best4 = null;
        $best12 = null;
        for ($i = 0; $i < $count; $i++) {
            $rec = $base + 4 + 8 * $i;
            if ($rec + 8 > $end) {
                break;
            }
            $platform = $this->u16At($rec);
            $encoding = $this->u16At($rec + 2);
            $off = $base + $this->u32At($rec + 4);
            if ($off + 2 > $end) {
                continue;
            }
            $format = $this->u16At($off);
            $unicode = $platform === 0 || ($platform === 3 && in_array($encoding, [1, 10], true));
            if (! $unicode) {
                continue;
            }
            if ($format === 12) {
                $best12 ??= $off;
            } elseif ($format === 4) {
                $best4 ??= $off;
            }
        }
        if ($best12 !== null) {
            $this->readFormat12($best12, $end);
        } elseif ($best4 !== null) {
            $this->readFormat4($best4, $end);
        } else {
            throw new FontException('The font has no Unicode cmap (format 4 or 12).');
        }
    }

    private function readFormat4(int $off, int $tableEnd): void
    {
        $length = $this->u16At($off + 2);
        $end = min($tableEnd, $off + $length);
        $segX2 = $this->u16At($off + 6);
        $seg = intdiv($segX2, 2);
        if ($off + 16 + 4 * $segX2 > $end) {
            throw new FontException('The font\'s cmap is truncated.');
        }
        $ends = $off + 14;
        $starts = $ends + $segX2 + 2;
        $deltas = $starts + $segX2;
        $ranges = $deltas + $segX2;
        for ($i = 0; $i < $seg; $i++) {
            $e = $this->u16At($ends + 2 * $i);
            $s = $this->u16At($starts + 2 * $i);
            $d = $this->u16At($deltas + 2 * $i);
            $rPos = $ranges + 2 * $i;
            $r = $this->u16At($rPos);
            if ($s > $e) {
                continue;
            }
            $pos = 0;
            if ($r !== 0) {
                $pos = $rPos + $r;
                if ($pos + 2 * ($e - $s) + 2 > $tableEnd) {
                    continue;   // a range pointing outside the table maps nothing
                }
            }
            $this->format4[] = [$s, $e, $d, $pos];
        }
    }

    private function readFormat12(int $off, int $tableEnd): void
    {
        if ($off + 16 > $tableEnd) {
            throw new FontException('The font\'s cmap is truncated.');
        }
        $n = $this->u32At($off + 12);
        if ($n > 100_000 || $off + 16 + 12 * $n > $tableEnd) {
            throw new FontException('The font\'s cmap declares more groups than it holds.');
        }
        $groups = [];
        for ($i = 0; $i < $n; $i++) {
            $g = $off + 16 + 12 * $i;
            $s = $this->u32At($g);
            $e = $this->u32At($g + 4);
            if ($s > $e || $e > 0x10FFFF) {
                continue;
            }
            $groups[] = [$s, $e, $this->u32At($g + 8)];
        }
        usort($groups, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $this->format12 = $groups;
    }

    /** @return array{0: int, 1: int} start and end of a glyph inside `glyf` */
    private function glyphRange(int $gid): array
    {
        if ($gid < 0 || $gid >= $this->numGlyphs) {
            return [0, 0];
        }
        $loca = $this->tables['loca']['offset'];
        if ($this->longLoca) {
            $s = $this->u32At($loca + 4 * $gid);
            $e = $this->u32At($loca + 4 * $gid + 4);
        } else {
            $s = 2 * $this->u16At($loca + 2 * $gid);
            $e = 2 * $this->u16At($loca + 2 * $gid + 2);
        }
        $glyfLen = $this->tables['glyf']['length'];
        if ($e < $s || $e > $glyfLen) {
            return [0, 0];   // a damaged entry is an empty glyph
        }

        return [$s, $e];
    }

    /** @param array<int, true> $keep */
    private function closure(int $gid, array &$keep, int $depth): void
    {
        if ($gid < 0 || $gid >= $this->numGlyphs || $depth > self::MAX_COMPONENT_DEPTH) {
            return;
        }
        if (isset($keep[$gid]) && $depth > 0) {
            return;
        }
        $keep[$gid] = true;
        [$s, $e] = $this->glyphRange($gid);
        if ($e - $s < 10) {
            return;
        }
        $at = $this->tables['glyf']['offset'] + $s;
        $end = $this->tables['glyf']['offset'] + $e;
        if ($this->s16At($at) >= 0) {
            return;   // simple glyph
        }
        $p = $at + 10;
        for ($n = 0; $n < 256 && $p + 4 <= $end; $n++) {
            $flags = $this->u16At($p);
            $component = $this->u16At($p + 2);
            if (! isset($keep[$component])) {
                $this->closure($component, $keep, $depth + 1);
            }
            $p += 4 + (($flags & 0x0001) !== 0 ? 4 : 2);
            if (($flags & 0x0008) !== 0) {
                $p += 2;
            } elseif (($flags & 0x0040) !== 0) {
                $p += 4;
            } elseif (($flags & 0x0080) !== 0) {
                $p += 8;
            }
            if (($flags & 0x0020) === 0) {
                break;
            }
        }
    }

    // ------------------------------------------------------------------
    // Writing

    /** @param array<string, string> $tables */
    private static function assemble(array $tables): string
    {
        ksort($tables, SORT_STRING);
        $n = count($tables);
        $e = 0;
        while ((2 << $e) <= $n) {
            $e++;
        }
        $sr = 16 * (1 << $e);
        $dir = pack('Nnnnn', 0x00010000, $n, $sr, $e, $n * 16 - $sr);
        $offset = 12 + 16 * $n;
        $body = '';
        foreach ($tables as $tag => $data) {
            $dir .= $tag.pack('NNN', self::checksum($data), $offset + strlen($body), strlen($data));
            $body .= $data.str_repeat("\0", (4 - strlen($data) % 4) % 4);
        }

        return $dir.$body;
    }

    private static function checksum(string $data): int
    {
        $data .= str_repeat("\0", (4 - strlen($data) % 4) % 4);
        $sum = 0;
        foreach (unpack('N*', $data) ?: [] as $word) {
            $sum = ($sum + $word) & 0xFFFFFFFF;
        }

        return $sum;
    }

    private static function directoryOffset(string $font, string $tag): int
    {
        $n = unpack('n', substr($font, 4, 2))[1];
        for ($i = 0; $i < $n; $i++) {
            if (substr($font, 12 + 16 * $i, 4) === $tag) {
                return unpack('N', substr($font, 12 + 16 * $i + 8, 4))[1];
            }
        }

        throw new FontException('Subset has no '.$tag.' table.');
    }

    // ------------------------------------------------------------------
    // Bounds-checked reads

    private function table(string $tag, int $minLength): int
    {
        $t = $this->tables[$tag] ?? throw new FontException('The font has no '.$tag.' table.');
        if ($t['length'] < $minLength) {
            throw new FontException('The font\'s '.$tag.' table is truncated.');
        }

        return $t['offset'];
    }

    private function bytesAt(int $at, int $n): string
    {
        if ($at < 0 || $n < 0 || $at + $n > strlen($this->data)) {
            throw new FontException('The font file is truncated.');
        }

        return substr($this->data, $at, $n);
    }

    private function u16At(int $at): int
    {
        return unpack('n', $this->bytesAt($at, 2))[1];
    }

    private function s16At(int $at): int
    {
        $v = $this->u16At($at);

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function u32At(int $at): int
    {
        return unpack('N', $this->bytesAt($at, 4))[1];
    }

    private static function printable(string $tag): string
    {
        return (string) preg_replace('/[^A-Za-z0-9\/ ]/', '?', $tag);
    }
}
