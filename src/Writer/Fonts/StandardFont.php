<?php

declare(strict_types=1);

namespace FinePrint\Writer\Fonts;

use FinePrint\Text\Encodings;
use FinePrint\Writer\Pdf\PdfFile;
use FinePrint\Writer\Pdf\Syntax;

/**
 * One of the standard 14 fonts' text faces, in WinAnsiEncoding.
 *
 * Not embedded: every PDF viewer carries these faces (or metric-compatible
 * substitutes). The font dictionary still lists `/Widths`, so every viewer and
 * every reader, P1 included, positions glyphs exactly where layout put them.
 *
 * A character outside WinAnsi cannot be drawn and is written as `?`; the
 * composer reports each one, and {@see \FinePrint\Agent::lint()} says so
 * before the document is written. A host font ({@see EmbeddedFont}) draws the
 * full Unicode range its cmap covers.
 */
final class StandardFont implements Font
{
    /** @var list<string> */
    public const FACES = [
        'Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique',
        'Times-Roman', 'Times-Bold', 'Times-Italic', 'Times-BoldItalic',
        'Courier', 'Courier-Bold', 'Courier-Oblique', 'Courier-BoldOblique',
    ];

    /** Look-alikes outside WinAnsi, drawn as the WinAnsi character. */
    private const FOLD = [
        "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2212}" => '-',
        "\u{2002}" => ' ', "\u{2003}" => ' ', "\u{2009}" => ' ', "\u{200A}" => ' ', "\u{202F}" => "\u{A0}",
    ];

    /** @var array<string, int>|null UTF-8 character => WinAnsi code */
    private static ?array $codes = null;

    /** @var list<int> */
    private readonly array $widths;

    /** @var array{ascent: int, descent: int, capHeight: int} */
    private readonly array $vertical;

    public function __construct(public readonly string $face)
    {
        if (! in_array($face, self::FACES, true)) {
            throw new \InvalidArgumentException('Not a standard text face: '.$face);
        }
        $this->widths = StandardMetrics::widths($face);
        $this->vertical = StandardMetrics::vertical($face);
    }

    public function id(): string
    {
        return 'std:'.$this->face;
    }

    public function canEncode(string $char): bool
    {
        return self::code($char) !== null;
    }

    public function width(string $utf8): float
    {
        $w = 0;
        foreach (mb_str_split($utf8, 1, 'UTF-8') as $ch) {
            $code = self::code($ch) ?? 0x3F;
            $w += $this->widths[$code - 32] ?? 0;
        }

        return (float) $w;
    }

    public function encode(string $utf8): string
    {
        $out = '';
        foreach (mb_str_split($utf8, 1, 'UTF-8') as $ch) {
            $out .= chr(self::code($ch) ?? 0x3F);
        }

        return $out;
    }

    public function ascent(): float
    {
        return (float) $this->vertical['ascent'];
    }

    public function descent(): float
    {
        return (float) $this->vertical['descent'];
    }

    public function emit(PdfFile $file): int
    {
        return $file->add('<< /Type /Font /Subtype /Type1 /BaseFont '.Syntax::name($this->face)
            .' /Encoding /WinAnsiEncoding /FirstChar 32 /LastChar 255 /Widths ['.implode(' ', $this->widths).'] >>');
    }

    /** The WinAnsi code for one character, or null when it has none. */
    private static function code(string $ch): ?int
    {
        if (self::$codes === null) {
            $codes = [];
            foreach (Encodings::map('WinAnsiEncoding') ?? [] as $code => $u) {
                $codes[$u] ??= $code;
            }
            $codes[' '] = 0x20;
            $codes["\u{A0}"] = 0xA0;
            $codes["\u{AD}"] = 0x2D;
            self::$codes = $codes;
        }
        if (isset(self::FOLD[$ch])) {
            $ch = self::FOLD[$ch];
        }

        return self::$codes[$ch] ?? null;
    }
}
