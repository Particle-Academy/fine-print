<?php

declare(strict_types=1);

namespace FinePrint\Writer\Layout;

/**
 * One laid-out line: a sequence of items, each a piece of text in one style.
 * `glue` items are the breakable spaces justification may widen.
 */
final class Line
{
    /**
     * @param  list<array{text: string, style: TextStyle, width: float, glue: bool}>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly float $width,
        public readonly float $height,
        public readonly float $baseline,
        public readonly bool $last,
    ) {}

    public function glueCount(): int
    {
        return count(array_filter($this->items, fn (array $i) => $i['glue']));
    }
}
