<?php

declare(strict_types=1);

namespace FinePrint\Writer\Layout;

/**
 * A blocks band, composed: a stack of flowables with every block's own spacing
 * kept, first and last included. That is what last-word's `BlockMeasurer::band`
 * adds up and what a Word header part holds, so a band's height here is the one
 * the viewer's `bodyBox` works from.
 */
final class BandBox
{
    /** @param list<Flowable> $children */
    public function __construct(private readonly array $children) {}

    public function height(): float
    {
        $h = 0.0;
        foreach ($this->children as $child) {
            $h += $child->spaceBefore + $child->height() + $child->spaceAfter;
        }

        return $h;
    }

    public function draw(Canvas $c, float $x, float $top): void
    {
        $y = $top;
        foreach ($this->children as $child) {
            $y += $child->spaceBefore;
            $child->draw($c, $x + $child->indent, $y);
            $y += $child->height() + $child->spaceAfter;
        }
    }
}
