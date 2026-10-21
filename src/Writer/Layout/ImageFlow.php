<?php

declare(strict_types=1);

namespace FinePrint\Writer\Layout;

/** An image, drawn at a fixed size. */
final class ImageFlow extends Flowable
{
    public function __construct(
        public readonly string $name,
        private readonly float $width,
        private readonly float $heightPt,
    ) {}

    public function height(): float
    {
        return $this->heightPt;
    }

    public function draw(Canvas $c, float $x, float $top): void
    {
        $c->image($this->name, $x, $top, $this->width, $this->heightPt);
    }
}
