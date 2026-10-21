<?php

declare(strict_types=1);

namespace FinePrint\Render\Glyphs;

use FinePrint\Document;
use FinePrint\Exceptions\FontException;
use FinePrint\Objects\PdfDict;
use FinePrint\Objects\PdfName;
use FinePrint\Objects\PdfStream;
use FinePrint\Text\CMap;
use FinePrint\Text\Encodings;
use FinePrint\Text\Font;
use FinePrint\Writer\Fonts\TrueType;

/**
 * A PDF font as the renderer needs it: character codes to glyph ids and
 * advance widths, and glyph outlines. Widths and code splitting come from
 * P1's {@see Font}; the font program from P2's {@see TrueType} reader.
 *
 * Drawn: embedded TrueType (FontFile2, or FontFile3 /OpenType holding glyf
 * outlines), as a simple font or as a Type0 CIDFontType2 with Identity-H or
 * an embedded encoding CMap and a CIDToGIDMap. Everything else is not drawn
 * and says why in `skip`, a `skipped` kind:
 *
 *   text-unembedded   no font program in the file, and no bundled stand-in
 *                     (the twelve standard text faces draw from {@see StandardFonts})
 *   text-cff          CFF programs (Type1C, CIDFontType0C, OpenType CFF)
 *   text-type1        Type 1 programs (FontFile)
 *   text-type3        Type 3 fonts
 *   text-vertical     vertical writing (Identity-V, WMode 1)
 *   text-cmap         a predefined CMap other than Identity-H
 *   text-font-invalid a font that is missing or cannot be read
 *
 * Simple TrueType glyph selection follows ISO 32000-1 9.6.6.4: a
 * non-symbolic font (or one with an /Encoding) goes code -> encoding ->
 * Unicode -> the (3,1) cmap; a symbolic one looks the code up in (3,0), also
 * at 0xF000 + code; (1,0) is used by code, or by the Mac OS Roman code of the
 * encoded character. A code nothing maps draws glyph 0 (.notdef).
 */
final class RenderFont
{
    /** @var array<string, int> code bytes => gid */
    private array $gids = [];

    private ?TrueTypeGlyphs $glyphs = null;

    private ?Font $text = null;

    private bool $symbolic = false;

    /** null: Identity; else the CIDToGIDMap bytes */
    private ?string $cidToGid = null;

    private ?CMap $encoding = null;

    /** @var ?array<string, int> UTF-8 => width, for a standard-14 face drawn from a bundled font */
    private ?array $standardWidths = null;

    /** @var ?array<string, int> UTF-8 => Mac OS Roman code */
    private static ?array $macRoman = null;

    private function __construct(
        public readonly string $name,
        public readonly ?string $skip,
        public readonly int $programBytes = 0,
    ) {}

    public static function load(PdfDict $dict, Document $doc): self
    {
        $name = self::cleanName($dict->name('BaseFont') ?? '');
        $subtype = $dict->name('Subtype');
        // Widths and codes even for a font that is not drawn: the text after
        // it on the same line still has to land in the right place.
        $text = Font::fromDict($dict, $doc);
        if ($subtype === 'Type3') {
            return self::skipped($name, $text, 'text-type3');
        }
        $descriptorOwner = $dict;
        $cidToGid = null;
        $encoding = null;
        if ($subtype === 'Type0') {
            $descendants = $doc->resolve($dict->get('DescendantFonts'));
            $desc = is_array($descendants) ? $doc->resolve($descendants[0] ?? null) : null;
            if (! $desc instanceof PdfDict) {
                return self::skipped($name, $text, 'text-font-invalid');
            }
            $enc = $doc->resolve($dict->get('Encoding'));
            if ($enc instanceof PdfName) {
                if ($enc->value === 'Identity-V') {
                    return self::skipped($name, $text, 'text-vertical');
                }
                if ($enc->value !== 'Identity-H') {
                    return self::skipped($name, $text, 'text-cmap');
                }
            } elseif ($enc instanceof PdfStream) {
                $wmode = $doc->resolve($enc->dict->get('WMode'));
                if ($wmode === 1) {
                    return self::skipped($name, $text, 'text-vertical');
                }
                $encoding = $text->encodingCMap();
                if ($encoding === null || ! $encoding->hasCids()) {
                    return self::skipped($name, $text, 'text-cmap');
                }
            } else {
                return self::skipped($name, $text, 'text-font-invalid');
            }
            // A CIDFontType0 (CFF) carries FontFile3, refused below as text-cff.
            $map = $doc->resolve($desc->get('CIDToGIDMap'));
            if ($map instanceof PdfStream) {
                $cidToGid = $doc->streamData($map) ?? '';
            }
            $descriptorOwner = $desc;
        }

        $fd = $doc->resolve($descriptorOwner->get('FontDescriptor'));
        if (! $fd instanceof PdfDict) {
            return self::standard($dict, $subtype, $name, $text) ?? self::skipped($name, $text, 'text-unembedded');
        }
        $flags = $doc->resolve($fd->get('Flags'));
        $program = null;
        foreach (['FontFile2', 'FontFile3', 'FontFile'] as $key) {
            $s = $doc->resolve($fd->get($key));
            if (! $s instanceof PdfStream) {
                continue;
            }
            if ($key === 'FontFile') {
                return self::skipped($name, $text, 'text-type1');
            }
            if ($key === 'FontFile3' && $s->dict->name('Subtype') !== 'OpenType') {
                return self::skipped($name, $text, 'text-cff');
            }
            $program = $doc->streamData($s);
            if ($program === null) {
                return self::skipped($name, $text, 'text-font-invalid');
            }

            break;
        }
        if ($program === null) {
            return self::standard($dict, $subtype, $name, $text) ?? self::skipped($name, $text, 'text-unembedded');
        }
        if (str_starts_with($program, 'OTTO')) {
            return self::skipped($name, $text, 'text-cff');
        }
        try {
            $tt = TrueType::parse($program, requireUnicodeCmap: false);
        } catch (FontException) {
            return self::skipped($name, $text, 'text-font-invalid');
        }

        $f = new self($name, null, strlen($program));
        $f->glyphs = new TrueTypeGlyphs($tt);
        $f->text = $text;
        $f->symbolic = is_int($flags) && ($flags & 4) !== 0;
        $f->cidToGid = $cidToGid;
        $f->encoding = $encoding;

        return $f;
    }

    /**
     * A simple font with no program that names a standard-14 text face (or
     * Arial / Times New Roman / Courier New): drawn from the bundled outline
     * ({@see StandardFonts}), advancing by the standard widths where the PDF
     * gives none. Null for anything else, which stays text-unembedded.
     */
    private static function standard(PdfDict $dict, ?string $subtype, string $name, Font $text): ?self
    {
        if (! in_array($subtype, ['Type1', 'TrueType', 'MMType1'], true)) {
            return null;
        }
        $face = StandardFonts::match($dict->name('BaseFont') ?? '');
        $tt = $face !== null ? StandardFonts::font($face) : null;
        if ($face === null || $tt === null) {
            return null;
        }
        $f = new self($name, null, (int) @filesize(StandardFonts::file($face)));
        $f->glyphs = new TrueTypeGlyphs($tt);
        $f->text = $text;
        // The bundled font is a Unicode font: select glyphs by the code's
        // character, whatever the PDF's symbolic flag says.
        $f->symbolic = false;
        $f->standardWidths = StandardFonts::widths($face);

        return $f;
    }

    private static function skipped(string $name, Font $text, string $kind): self
    {
        $f = new self($name, $kind);
        $f->text = $text;

        return $f;
    }

    public function drawable(): bool
    {
        return $this->skip === null;
    }

    public function unitsPerEm(): int
    {
        return $this->glyphs?->font->unitsPerEm ?? 1000;
    }

    /**
     * A show string as glyphs, one at a time: [gid, width in 1/1000 text
     * space, is a single-byte code 32 (gets word spacing)]. A font that is
     * not drawn still yields its advances (gid 0), so text after it lands
     * where it should.
     *
     * A generator, never a list: a show string can hold millions of codes,
     * and a list of them all is a memory exhaustion waiting for a hostile
     * file (found by measurement: 3 million glyphs passed PHP's 512 MB limit).
     *
     * @return \Generator<int, array{0: int, 1: float, 2: bool}>
     */
    public function glyphs(string $bytes): \Generator
    {
        $text = $this->text;
        if ($text === null) {
            return;
        }
        $tt = $this->glyphs?->font;
        $composite = $text->composite();
        foreach ($text->codes($bytes) as $code) {
            if ($composite) {
                $cid = $this->encoding !== null ? ($this->encoding->cid($code) ?? 0) : self::int($code);
                $gid = $tt === null ? 0 : ($this->gids[$code] ?? $this->remember($code, $this->cidGlyph($cid, $tt)));
                yield [$gid, $text->explicitWidth($cid) ?? $text->defaultWidth(), $code === ' '];

                continue;
            }
            $c = ord($code);
            if ($tt === null) {
                yield [0, $text->explicitWidth($c) ?? $text->defaultWidth(), $c === 32];

                continue;
            }
            $gid = $this->gids[$code] ?? $this->remember($code, $this->simpleGlyph($c, $tt));
            $w = $text->explicitWidth($c);
            if ($w === null && $this->standardWidths !== null) {
                $u = $text->encodingUnicode($c);
                $w = $u !== null && isset($this->standardWidths[$u]) ? (float) $this->standardWidths[$u] : null;
            }
            yield [$gid, $w ?? $tt->advance($gid) * 1000 / $tt->unitsPerEm, $c === 32];
        }
    }

    /** Cache a code's glyph; codes can be four bytes wide, so the cache is capped. */
    private function remember(string $code, int $gid): int
    {
        if (count($this->gids) < 65536) {
            $this->gids[$code] = $gid;
        }

        return $gid;
    }

    /** @throws FontException for a glyph that cannot be drawn */
    public function outline(int $gid): Outline
    {
        return $this->glyphs?->outline($gid) ?? Outline::empty();
    }

    private function cidGlyph(int $cid, TrueType $tt): int
    {
        if ($this->cidToGid === null) {
            $gid = $cid;
        } else {
            $at = 2 * $cid;
            $gid = $at + 2 <= strlen($this->cidToGid)
                ? (ord($this->cidToGid[$at]) << 8) | ord($this->cidToGid[$at + 1])
                : 0;
        }

        return $gid < $tt->numGlyphs ? $gid : 0;
    }

    private function simpleGlyph(int $code, TrueType $tt): int
    {
        $text = $this->text;
        $named = $text?->hasEncoding() || ! $this->symbolic;
        $u = $named ? $text?->encodingUnicode($code) : null;
        $cp = $u !== null && $u !== '' ? mb_ord($u, 'UTF-8') : false;

        if ($cp !== false && ($g = $tt->cmapGlyph(3, 1, $cp)) !== null && $g > 0) {
            return $g;
        }
        if ($tt->hasCmap(3, 0)) {
            foreach ([$code, 0xF000 + $code, 0xF100 + $code, 0xF200 + $code] as $c) {
                if (($g = $tt->cmapGlyph(3, 0, $c)) > 0) {
                    return $g;
                }
            }
        }
        if ($tt->hasCmap(1, 0)) {
            if ($u !== null && ($mac = self::macRoman()[$u] ?? null) !== null && ($g = $tt->cmapGlyph(1, 0, $mac)) > 0) {
                return $g;
            }
            if (($g = $tt->cmapGlyph(1, 0, $code)) > 0) {
                return $g;
            }
        }
        if (($g = $tt->cmapGlyph(3, 1, $code)) !== null && $g > 0) {
            return $g;
        }

        return 0;
    }

    /** @return array<string, int> */
    private static function macRoman(): array
    {
        return self::$macRoman ??= array_flip(Encodings::map('MacRomanEncoding') ?? []);
    }

    /** A font name fit for a report: PostScript characters only, at most 63. */
    public static function cleanName(string $name): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9+_.\-]/', '', $name), 0, 63);
    }

    private static function int(string $bytes): int
    {
        $v = 0;
        $n = min(strlen($bytes), 4);
        for ($i = 0; $i < $n; $i++) {
            $v = ($v << 8) | ord($bytes[$i]);
        }

        return $v;
    }
}
