<?php

declare(strict_types=1);

namespace FinePrint\Writer\Layout;

/** A forced page break. */
final class PageBreakFlow extends Flowable
{
    public function height(): float
    {
        return 0.0;
    }

    public function draw(Canvas $c, float $x, float $top): void {}
}
