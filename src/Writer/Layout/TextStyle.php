<?php

declare(strict_types=1);

namespace FinePrint\Writer\Layout;

use FinePrint\Writer\Fonts\Font;

/** A run's resolved look. Immutable; compared by {@see key()}. */
final class TextStyle
{
    /**
     * @param  array{0: float, 1: float, 2: float}  $color
     * @param  array{0: float, 1: float, 2: float}|null  $highlight
     */
    public function __construct(
        public readonly Font $font,
        public readonly float $size,
        public readonly array $color = [0.0, 0.0, 0.0],
        public readonly bool $underline = false,
        public readonly bool $strike = false,
        public readonly ?array $highlight = null,
        public readonly ?string $link = null,
        public readonly float $letterSpacing = 0.0,
    ) {}

    /** Width of a string in points, letter spacing included. */
    public function measure(string $text): float
    {
        return $this->font->width($text) * $this->size / 1000 + $this->letterSpacing * mb_strlen($text, 'UTF-8');
    }

    public function ascent(): float
    {
        return $this->font->ascent() * $this->size / 1000;
    }

    public function descent(): float
    {
        return -$this->font->descent() * $this->size / 1000;
    }

    /** What the text operators care about: font, size, colour, spacing. */
    public function textKey(): string
    {
        return $this->font->id().'|'.$this->size.'|'.implode(',', $this->color).'|'.$this->letterSpacing;
    }
}
