<?php

declare(strict_types=1);

namespace FinePrint\Render;

/**
 * The graphics state the P3a renderer tracks (ISO 32000-1 8.4). Cloned on
 * `q`, restored on `Q`.
 *
 * Colours are kept as the operands the file gave, with their space, and
 * converted to RGB only at paint time: `cs` resets the colour to the space's
 * initial value, which needs the space, not the RGB it maps to.
 */
final class GraphicsState
{
    /** @var list<float> user space to DEVICE pixels (the page mapping is folded in) */
    public array $ctm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

    public ColourSpace $fillSpace;

    public ColourSpace $strokeSpace;

    /** @var list<float> */
    public array $fill = [0.0];

    /** @var list<float> */
    public array $stroke = [0.0];

    public float $lineWidth = 1.0;

    public int $cap = 0;

    public int $join = 0;

    public float $miterLimit = 10.0;

    /** @var list<float> */
    public array $dash = [];

    public float $dashPhase = 0.0;

    /** Constant alpha (CA / ca) and soft masks / blend modes: P3d. Tracked so a paint can say it ignored them. */
    public float $strokeAlpha = 1.0;

    public float $fillAlpha = 1.0;

    public bool $maskOrBlend = false;

    public function __construct()
    {
        $this->fillSpace = ColourSpace::gray();
        $this->strokeSpace = ColourSpace::gray();
    }
}
