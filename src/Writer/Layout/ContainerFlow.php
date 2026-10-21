<?php

declare(strict_types=1);

namespace FinePrint\Writer\Layout;

/**
 * A stack of blocks: the content of a table cell. The first block's space
 * before and the last block's space after are dropped, so a cell is as tall
 * as its content plus its padding.
 */
final class ContainerFlow extends Flowable
{
    /** @param list<Flowable> $children */
    public function __construct(private readonly array $children) {}

    public function height(): float
    {
        $h = 0.0;
        foreach ($this->children as $i => $child) {
            if ($i > 0) {
                $h += $this->children[$i - 1]->spaceAfter + $child->spaceBefore;
            }
            $h += $child->height();
        }

        return $h;
    }

    public function draw(Canvas $c, float $x, float $top): void
    {
        $y = $top;
        foreach ($this->children as $i => $child) {
            if ($i > 0) {
                $y += $this->children[$i - 1]->spaceAfter + $child->spaceBefore;
            }
            $child->draw($c, $x + $child->indent, $y);
            $y += $child->height();
        }
    }

    public function split(float $avail): ?array
    {
        return $this->splitWith($avail, false);
    }

    public function forceSplit(float $avail): ?array
    {
        return $this->splitWith($avail, true);
    }

    /** @return array{0: Flowable, 1: Flowable}|null */
    private function splitWith(float $avail, bool $force): ?array
    {
        $y = 0.0;
        foreach ($this->children as $i => $child) {
            $gap = $i > 0 ? $this->children[$i - 1]->spaceAfter + $child->spaceBefore : 0.0;
            if ($y + $gap + $child->height() <= $avail + 0.001) {
                $y += $gap + $child->height();

                continue;
            }
            $room = $avail - $y - $gap;
            $parts = $force ? $child->forceSplit($room) : $child->split($room);
            if ($parts === null) {
                if ($i === 0) {
                    return null;
                }
                $head = array_slice($this->children, 0, $i);
                $tail = array_slice($this->children, $i);
            } else {
                $head = [...array_slice($this->children, 0, $i), $parts[0]];
                $tail = [$parts[1], ...array_slice($this->children, $i + 1)];
            }

            return [$this->inherit(new self($head), true, false), $this->inherit(new self($tail), false, true)];
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->children === [];
    }
}
