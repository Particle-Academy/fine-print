<?php

declare(strict_types=1);

namespace FinePrint\Writer\Layout;

/**
 * A paragraph (or heading, list item, code block): lines, an alignment,
 * insets, and an optional box (shading, borders) with a list marker.
 *
 * It splits between lines and never leaves fewer than two lines on either
 * side of a page break, so no line is orphaned at the foot of a page or
 * widowed at the head of the next. A paragraph of three lines or fewer moves
 * whole.
 */
final class ParagraphFlow extends Flowable
{
    private const ORPHANS = 2;

    /** How far a shaded or bordered box reaches past the text, horizontally. */
    private const BOX_REACH = 4.0;

    /**
     * @param  list<Line>  $lines
     * @param  array{0: float, 1: float, 2: float}|null  $shading
     * @param  array<string, array{style: string, width: float, color: array{0: float, 1: float, 2: float}}>  $borders
     * @param  array{line: Line, x: float}|null  $marker  x is relative to the paragraph's left edge
     */
    public function __construct(
        private readonly array $lines,
        private readonly float $width,
        private readonly string $align = 'left',
        private readonly float $insetLeft = 0.0,
        private readonly float $insetRight = 0.0,
        private readonly ?array $shading = null,
        private readonly array $borders = [],
        private readonly ?array $marker = null,
        private readonly float $padV = 2.0,
        private readonly bool $openTop = false,
        private readonly bool $openBottom = false,
    ) {}

    public function height(): float
    {
        return $this->pad() * 2 + $this->linesHeight($this->lines);
    }

    public function minHeight(): float
    {
        if (count($this->lines) <= 3) {
            return $this->height();
        }

        return $this->pad() * 2 + $this->linesHeight(array_slice($this->lines, 0, self::ORPHANS));
    }

    public function split(float $avail): ?array
    {
        $total = count($this->lines);
        if ($total <= 3) {
            return null;
        }
        $n = min($this->fitting($avail), $total - self::ORPHANS);

        return $n < self::ORPHANS ? null : $this->cut($n);
    }

    public function forceSplit(float $avail): ?array
    {
        $n = min($this->fitting($avail), count($this->lines) - 1);

        return $n < 1 ? null : $this->cut($n);
    }

    /** The lines, for lint (which looks for headings stranded at a page foot). */
    public function lineCount(): int
    {
        return count($this->lines);
    }

    public function draw(Canvas $c, float $x, float $top): void
    {
        $pad = $this->pad();
        $h = $this->height();
        if ($this->boxed()) {
            $x0 = $x + $this->insetLeft - self::BOX_REACH;
            $x1 = $x + $this->width - $this->insetRight + self::BOX_REACH;
            if ($this->shading !== null) {
                $c->rect($x0, $top, $x1 - $x0, $h, $this->shading);
            }
            foreach ($this->borders as $edge => $b) {
                match ($edge) {
                    'top' => $this->openTop ? null : $c->line($x0, $top, $x1, $top, $b['width'], $b['color'], $b['style']),
                    'bottom' => $this->openBottom ? null : $c->line($x0, $top + $h, $x1, $top + $h, $b['width'], $b['color'], $b['style']),
                    'left' => $c->line($x0, $top, $x0, $top + $h, $b['width'], $b['color'], $b['style']),
                    'right' => $c->line($x1, $top, $x1, $top + $h, $b['width'], $b['color'], $b['style']),
                    default => null,
                };
            }
        }

        $textX = $x + $this->insetLeft;
        $avail = $this->width - $this->insetLeft - $this->insetRight;
        $y = $top + $pad;
        if ($this->marker !== null && $this->lines !== []) {
            $m = $this->marker['line'];
            $c->text($m, $x + $this->marker['x'], $y + $this->lines[0]->baseline - $m->baseline, $m->width + 1, 'left');
        }
        foreach ($this->lines as $line) {
            $c->text($line, $textX, $y, $avail, $this->align);
            $y += $line->height;
        }
    }

    private function boxed(): bool
    {
        return $this->shading !== null || $this->borders !== [];
    }

    private function pad(): float
    {
        return $this->boxed() ? $this->padV : 0.0;
    }

    /** @param list<Line> $lines */
    private function linesHeight(array $lines): float
    {
        $h = 0.0;
        foreach ($lines as $l) {
            $h += $l->height;
        }

        return $h;
    }

    private function fitting(float $avail): int
    {
        $h = $this->pad() * 2;
        $n = 0;
        foreach ($this->lines as $l) {
            if ($h + $l->height > $avail + 0.001) {
                break;
            }
            $h += $l->height;
            $n++;
        }

        return $n;
    }

    /** @return array{0: Flowable, 1: Flowable} */
    private function cut(int $n): array
    {
        $head = new self(array_slice($this->lines, 0, $n), $this->width, $this->align, $this->insetLeft, $this->insetRight,
            $this->shading, $this->borders, $this->marker, $this->padV, $this->openTop, true);
        $tail = new self(array_slice($this->lines, $n), $this->width, $this->align, $this->insetLeft, $this->insetRight,
            $this->shading, $this->borders, null, $this->padV, true, $this->openBottom);

        return [$this->inherit($head, true, false), $this->inherit($tail, false, true)];
    }
}
