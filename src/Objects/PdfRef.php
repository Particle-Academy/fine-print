<?php

declare(strict_types=1);

namespace FinePrint\Objects;

/** An indirect reference, `num gen R`. */
final class PdfRef
{
    public function __construct(
        public readonly int $num,
        public readonly int $gen,
    ) {}

    public function key(): string
    {
        return $this->num.' '.$this->gen;
    }
}
