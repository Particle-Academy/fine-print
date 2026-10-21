<?php

declare(strict_types=1);

namespace FinePrint\Writer\Layout;

/**
 * A block laid out for a fixed width, ready to be placed on pages.
 *
 * `split()` cuts it to fit the space left on a page, or returns null when it
 * must move to the next page whole. `minHeight()` is the smallest first piece
 * a keep-with-next block above it must have on the same page.
 */
abstract class Flowable
{
    public float $spaceBefore = 0.0;

    public float $spaceAfter = 0.0;

    public bool $keepNext = false;

    /** Offset from the frame's left edge (quotes and lists indent this way). */
    public float $indent = 0.0;

    public string $path = '';

    abstract public function height(): float;

    abstract public function draw(Canvas $c, float $x, float $top): void;

    public function minHeight(): float
    {
        return $this->height();
    }

    /** @return array{0: Flowable, 1: Flowable}|null */
    public function split(float $avail): ?array
    {
        return null;
    }

    /**
     * Split even where the rules say not to, because the block does not fit
     * on an empty page either. Null means it cannot be split at all.
     *
     * @return array{0: Flowable, 1: Flowable}|null
     */
    public function forceSplit(float $avail): ?array
    {
        return $this->split($avail);
    }

    /** Copy the placement properties onto a piece of this block. */
    protected function inherit(Flowable $piece, bool $first, bool $last): Flowable
    {
        $piece->indent = $this->indent;
        $piece->path = $this->path;
        $piece->spaceBefore = $first ? $this->spaceBefore : 0.0;
        $piece->spaceAfter = $last ? $this->spaceAfter : 0.0;
        $piece->keepNext = $last && $this->keepNext;

        return $piece;
    }
}
