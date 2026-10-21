<?php

declare(strict_types=1);

namespace FinePrint\Writer\Fonts;

use FinePrint\Writer\Pdf\PdfFile;

/**
 * A font the writer can measure text in and write text with.
 *
 * Widths are glyph-space units (1/1000 em), so a run's width in points is
 * `width($text) * size / 1000`. `encode()` returns the bytes a content stream
 * shows, and records what was used so an embedded font can be subset.
 */
interface Font
{
    /** Stable identity, used to share one font object between runs. */
    public function id(): string;

    /** Whether the font can draw this one character. */
    public function canEncode(string $char): bool;

    /** Total advance of a string, in 1/1000 em. */
    public function width(string $utf8): float;

    /** The bytes to show for a string. */
    public function encode(string $utf8): string;

    /** Ascender, 1/1000 em, positive. */
    public function ascent(): float;

    /** Descender, 1/1000 em, negative. */
    public function descent(): float;

    /** Write the font's objects; returns the font dictionary's object number. */
    public function emit(PdfFile $file): int;
}
