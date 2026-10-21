<?php

declare(strict_types=1);

namespace FinePrint\Render\Glyphs;

/**
 * One glyph's outline in font units, in the renderer's path model: subpaths
 * of flat x, y floats from their start point, one letter per segment ('l' a
 * line, 'c' a cubic), each closed. A closing LINE is implied by `closed`; a
 * closing curve is written out.
 */
final class Outline
{
    /**
     * @param  list<array{xy: list<float>, seg: string, closed: bool}>  $subpaths
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox  of every point, off-curve included (so it bounds the curves)
     */
    public function __construct(
        public readonly array $subpaths,
        public readonly array $bbox,
        public readonly int $points,
    ) {}

    public static function empty(): self
    {
        return new self([], [0.0, 0.0, 0.0, 0.0], 0);
    }
}
