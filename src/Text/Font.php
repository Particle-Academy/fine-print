<?php

declare(strict_types=1);

namespace FinePrint\Text;

use FinePrint\Document;
use FinePrint\Objects\PdfDict;
use FinePrint\Objects\PdfName;
use FinePrint\Objects\PdfStream;

/**
 * Turns the bytes of a text-showing operator into Unicode and advance widths.
 *
 * Order of authority, per ISO 32000-1 9.10.2: the ToUnicode CMap, then the
 * font's encoding (a named base encoding plus `/Differences`), then — for
 * the printable ASCII range of a simple font only — the byte itself. A code
 * none of those can name becomes U+FFFD, so an unreadable glyph is visible as
 * unreadable rather than silently dropped.
 */
final class Font
{
    private bool $composite = false;

    private ?CMap $toUnicode = null;

    private ?CMap $encodingCMap = null;

    private bool $ucs2 = false;

    /** @var array<int, string> code => UTF-8 */
    private array $encoding = [];

    /** @var array<int, float> code (simple) or CID (composite) => width in 1/1000 text space */
    private array $widths = [];

    private float $defaultWidth = 500.0;

    private float $widthScale = 1.0;

    public static function fromDict(PdfDict $dict, Document $doc): self
    {
        $f = new self();
        $subtype = $dict->name('Subtype');

        $tu = $doc->resolve($dict->get('ToUnicode'));
        if ($tu instanceof PdfStream) {
            $f->toUnicode = new CMap($doc->streamData($tu) ?? '');
        }

        if ($subtype === 'Type0') {
            $f->composite = true;
            $f->loadComposite($dict, $doc);
        } else {
            $f->loadSimple($dict, $doc, $subtype);
        }

        return $f;
    }

    /**
     * @return list<array{0: string, 1: float, 2: bool}> [UTF-8, width in 1/1000 text space, is single-byte code 32]
     */
    public function decode(string $bytes): array
    {
        $out = [];
        if ($this->composite) {
            $cmap = $this->encodingCMap ?? $this->toUnicode;
            $codes = $cmap !== null && $cmap->hasCodespace()
                ? $cmap->split($bytes, 2)
                : str_split($bytes, 2);
            foreach ($codes as $code) {
                if ($code === '') {
                    continue;
                }
                $cid = self::int($code);
                $u = $this->toUnicode?->lookup($code);
                if ($u === null) {
                    $u = $this->ucs2 ? TextString::scrub((string) @mb_convert_encoding($code, 'UTF-8', 'UTF-16BE')) : "\u{FFFD}";
                }
                $out[] = [$u, ($this->widths[$cid] ?? $this->defaultWidth) * $this->widthScale, false];
            }

            return $out;
        }

        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($bytes[$i]);
            $u = $this->toUnicode?->lookup($bytes[$i]);
            if ($u === null) {
                $u = $this->encoding[$c] ?? (($c >= 0x20 && $c < 0x7F) ? chr($c) : "\u{FFFD}");
            }
            $out[] = [$u, ($this->widths[$c] ?? $this->defaultWidth) * $this->widthScale, $c === 32];
        }

        return $out;
    }

    private function loadComposite(PdfDict $dict, Document $doc): void
    {
        $enc = $doc->resolve($dict->get('Encoding'));
        if ($enc instanceof PdfName) {
            $this->ucs2 = (bool) preg_match('/^Uni.*-(UCS2|UTF16)-[HV]$/', $enc->value);
        } elseif ($enc instanceof PdfStream) {
            $this->encodingCMap = new CMap($doc->streamData($enc) ?? '');
        }

        $descendants = $doc->resolve($dict->get('DescendantFonts'));
        $desc = is_array($descendants) ? $doc->resolve($descendants[0] ?? null) : null;
        $this->defaultWidth = 1000.0;
        if (! $desc instanceof PdfDict) {
            return;
        }
        $dw = $doc->resolve($desc->get('DW'));
        if (is_int($dw) || is_float($dw)) {
            $this->defaultWidth = (float) $dw;
        }
        $w = $doc->resolve($desc->get('W'));
        if (! is_array($w)) {
            return;
        }
        $n = count($w);
        $budget = 200_000;
        for ($i = 0; $i < $n && $budget > 0;) {
            $first = $doc->resolve($w[$i]);
            $second = $doc->resolve($w[$i + 1] ?? null);
            if (! is_int($first)) {
                $i++;

                continue;
            }
            if (is_array($second)) {
                foreach ($second as $k => $width) {
                    $width = $doc->resolve($width);
                    if (is_int($width) || is_float($width)) {
                        $this->widths[$first + $k] = (float) $width;
                    }
                    $budget--;
                }
                $i += 2;
            } else {
                $width = $doc->resolve($w[$i + 2] ?? null);
                if (is_int($second) && (is_int($width) || is_float($width))) {
                    // A range: never expand more than the budget allows.
                    $last = min($second, $first + $budget);
                    for ($c = $first; $c <= $last; $c++) {
                        $this->widths[$c] = (float) $width;
                    }
                    $budget -= max(0, $last - $first + 1);
                }
                $i += 3;
            }
        }
    }

    private function loadSimple(PdfDict $dict, Document $doc, ?string $subtype): void
    {
        $base = $dict->name('BaseFont') ?? '';
        $symbolic = in_array($base, ['Symbol', 'ZapfDingbats'], true);
        if (str_starts_with(preg_replace('/^[A-Z]{6}\+/', '', $base) ?? '', 'Courier')) {
            $this->defaultWidth = 600.0;
        }

        $first = $doc->resolve($dict->get('FirstChar'));
        $widths = $doc->resolve($dict->get('Widths'));
        if (is_int($first) && is_array($widths)) {
            foreach (array_slice($widths, 0, 256) as $k => $w) {
                $w = $doc->resolve($w);
                if (is_int($w) || is_float($w)) {
                    $this->widths[$first + $k] = (float) $w;
                }
            }
        }
        $desc = $doc->resolve($dict->get('FontDescriptor'));
        if ($desc instanceof PdfDict) {
            $mw = $doc->resolve($desc->get('MissingWidth'));
            if ((is_int($mw) || is_float($mw)) && $mw > 0) {
                $this->defaultWidth = (float) $mw;
            }
        }

        if ($subtype === 'Type3') {
            $matrix = $doc->resolve($dict->get('FontMatrix'));
            $a = is_array($matrix) ? $doc->resolve($matrix[0] ?? null) : null;
            $this->widthScale = (is_int($a) || is_float($a)) ? (float) $a * 1000 : 1.0;
        }

        $enc = $doc->resolve($dict->get('Encoding'));
        $baseName = $symbolic ? null : ($subtype === 'TrueType' ? 'WinAnsiEncoding' : 'StandardEncoding');
        $differences = null;
        if ($enc instanceof PdfName) {
            $baseName = $enc->value;
        } elseif ($enc instanceof PdfDict) {
            $baseName = $enc->name('BaseEncoding') ?? $baseName;
            $differences = $doc->resolve($enc->get('Differences'));
        }
        $this->encoding = ($baseName !== null ? Encodings::map($baseName) : null) ?? [];

        if (is_array($differences)) {
            $code = 0;
            foreach ($differences as $item) {
                $item = $doc->resolve($item);
                if (is_int($item)) {
                    $code = $item;
                } elseif ($item instanceof PdfName) {
                    if ($code >= 0 && $code <= 255) {
                        $g = Encodings::glyph($item->value);
                        if ($g !== null) {
                            $this->encoding[$code] = $g;
                        } else {
                            unset($this->encoding[$code]);
                        }
                    }
                    $code++;
                }
            }
        }
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
}
