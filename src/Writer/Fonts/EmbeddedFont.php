<?php

declare(strict_types=1);

namespace FinePrint\Writer\Fonts;

use FinePrint\Writer\Pdf\PdfFile;
use FinePrint\Writer\Pdf\Syntax;

/**
 * A host-supplied TrueType font, embedded as a subset.
 *
 * Written as a Type0 font with `/Identity-H` encoding over a CIDFontType2
 * whose CIDToGIDMap is `/Identity`: the two bytes shown for a character ARE
 * its glyph id. A `/ToUnicode` CMap maps every glyph used back to its text,
 * which is what lets a reader (P1 included) extract the words exactly.
 *
 * The subset tag (`ABCDEF+`) is derived from the glyph set, so the same text
 * gives the same bytes.
 */
final class EmbeddedFont implements Font
{
    /** @var array<int, string> glyph id => the text it was used for */
    private array $used = [];

    /** @var array<string, int> */
    private array $gidCache = [];

    public function __construct(private readonly TrueType $tt, private readonly string $key) {}

    public function id(): string
    {
        return 'tt:'.$this->key;
    }

    public function canEncode(string $char): bool
    {
        return $this->gid($char) !== 0;
    }

    public function width(string $utf8): float
    {
        $w = 0.0;
        foreach (mb_str_split($utf8, 1, 'UTF-8') as $ch) {
            $w += $this->tt->advance($this->gid($ch)) * 1000 / $this->tt->unitsPerEm;
        }

        return $w;
    }

    public function encode(string $utf8): string
    {
        $out = '';
        foreach (mb_str_split($utf8, 1, 'UTF-8') as $ch) {
            $gid = $this->gid($ch);
            if ($gid !== 0) {
                $this->used[$gid] ??= $ch;
            }
            $out .= pack('n', $gid);
        }

        return $out;
    }

    public function ascent(): float
    {
        return $this->tt->ascent * 1000 / $this->tt->unitsPerEm;
    }

    public function descent(): float
    {
        return $this->tt->descent * 1000 / $this->tt->unitsPerEm;
    }

    public function emit(PdfFile $file): int
    {
        $gids = array_keys($this->used);
        sort($gids);
        $tag = self::tag($this->tt->postScriptName, $gids);
        $base = Syntax::name($tag.'+'.$this->tt->postScriptName);
        $scale = 1000 / $this->tt->unitsPerEm;

        $program = $this->tt->subset($gids);
        $fontFile = $file->stream('/Length1 '.strlen($program), $program);

        $flags = 4 | ($this->tt->isFixedPitch ? 1 : 0) | ($this->tt->italicAngle !== 0.0 ? 64 : 0);
        $bbox = array_map(fn (int $v) => Syntax::num(round($v * $scale)), $this->tt->bbox);
        $descriptor = $file->add('<< /Type /FontDescriptor /FontName '.$base.' /Flags '.$flags
            .' /FontBBox ['.implode(' ', $bbox).'] /ItalicAngle '.Syntax::num($this->tt->italicAngle)
            .' /Ascent '.Syntax::num(round($this->ascent())).' /Descent '.Syntax::num(round($this->descent()))
            .' /CapHeight '.Syntax::num(round($this->tt->capHeight * $scale))
            .' /StemV '.($this->tt->weightClass >= 600 ? 120 : 80).' /FontFile2 '.$fontFile.' 0 R >>');

        $cid = $file->add('<< /Type /Font /Subtype /CIDFontType2 /BaseFont '.$base
            .' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>'
            .' /FontDescriptor '.$descriptor.' 0 R /DW 1000 /W ['.$this->widthArray($gids, $scale).'] /CIDToGIDMap /Identity >>');

        $toUnicode = $file->stream('', $this->toUnicode($gids));

        return $file->add('<< /Type /Font /Subtype /Type0 /BaseFont '.$base.' /Encoding /Identity-H'
            .' /DescendantFonts ['.$cid.' 0 R] /ToUnicode '.$toUnicode.' 0 R >>');
    }

    private function gid(string $ch): int
    {
        return $this->gidCache[$ch] ??= $this->tt->glyphFor(mb_ord($ch, 'UTF-8') ?: 0);
    }

    /** @param list<int> $gids */
    private function widthArray(array $gids, float $scale): string
    {
        $out = [];
        $run = [];
        $start = null;
        $prev = null;
        foreach ($gids as $g) {
            if ($prev !== null && $g !== $prev + 1) {
                $out[] = $start.' ['.implode(' ', $run).']';
                $run = [];
                $start = null;
            }
            $start ??= $g;
            $run[] = Syntax::num(round($this->tt->advance($g) * $scale));
            $prev = $g;
        }
        if ($start !== null) {
            $out[] = $start.' ['.implode(' ', $run).']';
        }

        return implode(' ', $out);
    }

    /** @param list<int> $gids */
    private function toUnicode(array $gids): string
    {
        $entries = [];
        foreach ($gids as $g) {
            $entries[] = sprintf('<%04X> <%s>', $g, strtoupper(bin2hex(mb_convert_encoding($this->used[$g], 'UTF-16BE', 'UTF-8'))));
        }
        $body = '';
        foreach (array_chunk($entries, 100) as $chunk) {
            $body .= count($chunk)." beginbfchar\n".implode("\n", $chunk)."\nendbfchar\n";
        }

        return "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            ."/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            ."/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            ."1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            .$body
            ."endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    }

    /** @param list<int> $gids */
    private static function tag(string $name, array $gids): string
    {
        $h = hash('sha256', $name.':'.implode(',', $gids));
        $tag = '';
        for ($i = 0; $i < 6; $i++) {
            $tag .= chr(ord('A') + hexdec(substr($h, 2 * $i, 2)) % 26);
        }

        return $tag;
    }
}
