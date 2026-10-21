<?php

declare(strict_types=1);

namespace FinePrint\Writer\Layout;

/** A horizontal rule across the column. */
final class RuleFlow extends Flowable
{
    public function __construct(private readonly float $width) {}

    public function height(): float
    {
        return 1.0;
    }

    public function draw(Canvas $c, float $x, float $top): void
    {
        $c->line($x, $top + 0.5, $x + $this->width, $top + 0.5, 0.75, [0.0, 0.0, 0.0]);
    }
}
