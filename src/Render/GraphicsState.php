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

    // --- text state (ISO 32000-1 9.3), saved by q like the rest ------------

    /** The current font, or null when none is set or the resource is missing. */
    public ?Glyphs\RenderFont $font = null;

    /** A Tf was given but its resource could not be found. */
    public bool $fontMissing = false;

    public float $fontSize = 0.0;

    public float $charSpacing = 0.0;

    public float $wordSpacing = 0.0;

    /** Tz / 100. */
    public float $hScale = 1.0;

    public float $leading = 0.0;

    public int $textRender = 0;

    public float $rise = 0.0;

    public function __construct()
    {
        $this->fillSpace = ColourSpace::gray();
        $this->strokeSpace = ColourSpace::gray();
    }
}
