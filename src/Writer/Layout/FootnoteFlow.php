<?php

declare(strict_types=1);

namespace FinePrint\Writer\Layout;

/**
 * One footnote at the foot of a page, as last-word-ui's `FootnoteView` draws
 * it: its number at 6.5pt, 2pt down, then 3pt, then its blocks at 10pt. A piece
 * continued from an earlier page has no number.
 */
final class FootnoteFlow extends Flowable
{
    private const NUMBER_DROP = 2.0;

    private const NUMBER_GAP = 3.0;

    public function __construct(
        public readonly int $id,
        private readonly Line $number,
        private readonly ContainerFlow $content,
        private readonly bool $continued = false,
    ) {}

    public function height(): float
    {
        return max($this->content->height(), $this->continued ? 0.0 : $this->number->height + self::NUMBER_DROP);
    }

    public function forceSplit(float $avail): ?array
    {
        $parts = $this->content->forceSplit($avail);
        if ($parts === null) {
            return null;
        }

        return [
            $this->inherit(new self($this->id, $this->number, $parts[0], $this->continued), true, false),
            $this->inherit(new self($this->id, $this->number, $parts[1], true), false, true),
        ];
    }

    public function split(float $avail): ?array
    {
        return $this->forceSplit($avail);
    }

    public function draw(Canvas $c, float $x, float $top): void
    {
        $indent = $this->number->width + self::NUMBER_GAP;
        if (! $this->continued) {
            $c->text($this->number, $x, $top + self::NUMBER_DROP, $this->number->width + 1, 'left');
        }
        $this->content->draw($c, $x + $indent, $top);
    }
}
