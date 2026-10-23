<?php

declare(strict_types=1);

namespace FinePrint\Render;

use FinePrint\Budget;

/**
 * One rendered page: the raster, its size, and what was NOT drawn.
 *
 * `skipped` is the honest part. Anything not drawn faithfully
 * on the page is counted here by kind, so a caller can tell a complete
 * picture from a partial one instead of trusting pixels that are missing
 * content. Kinds, in the order they are listed (alphabetical):
 *
 * | kind | what was not drawn |
 * |---|---|
 * | `annotation` | annotations with an appearance stream (viewers draw them) |
 * | `clip` | clipping from undrawable text or an invalid/missing Form BBox |
 * | `colorspace` | unsupported Separation/DeviceN tint functions (grey), Lab/Indexed vector paint or unknown spaces (black) |
 * | `form-depth` | form XObjects nested past `maxFormDepth`, or drawing themselves |
 * | `image` | damaged or unsupported image XObjects |
 * | `inline-image` | damaged or unsupported inline images |
 * | `optional-content` | optional-content sections, drawn regardless of their visibility |
 * | `pattern` | paint with a pattern (not drawn) |
 * | `shading` | `sh` shadings |
 * | `text-cff` | text in an embedded CFF font (Type1C, CIDFontType0C, OpenType CFF) |
 * | `text-cmap` | text in a Type0 font whose CMap is predefined (other than Identity-H) or maps no CIDs |
 * | `text-font-invalid` | text with no font set, a font resource that is missing, or a font program that cannot be read |
 * | `text-glyph` | glyphs whose outline is malformed or over a limit (per glyph; the rest of the text is drawn) |
 * | `text-type1` | text in an embedded Type 1 font |
 * | `text-type3` | text in a Type 3 font |
 * | `text-unembedded` | text in a font with no program in the file that no bundled font stands in for (Symbol, ZapfDingbats, any non-standard face) |
 * | `text-vertical` | vertical text (Identity-V, WMode 1) |
 * | `transparency` | unknown group spaces/blends, unsupported mask functions, calibrated luminosity masks or TK=false text |
 *
 * Counts are per operator (or per annotation): `text-unembedded: 12` is
 * twelve show operators, not twelve characters. `text-glyph` counts glyphs.
 * The `text-*` kinds also carry `fonts`: the fonts concerned (at most 16),
 * by BaseFont name stripped to PostScript name characters.
 *
 * Supported text in modes 4-6 is painted and accumulated as a clip until ET;
 * mode 7 only clips. Invisible mode 3 correctly paints nothing, without a skip.
 */
final class Rendering
{
    public const KINDS = [
        'annotation', 'clip', 'colorspace', 'form-depth', 'image', 'inline-image',
        'optional-content', 'pattern', 'shading',
        'text-cff', 'text-cmap', 'text-font-invalid', 'text-glyph', 'text-type1', 'text-type3',
        'text-unembedded', 'text-vertical',
        'transparency',
    ];

    /**
     * @param  list<array{kind: string, count: int, fonts?: list<string>}>  $skipped
     * @param  list<string>  $warnings
     */
    public function __construct(
        private readonly Raster $raster,
        public readonly int $page,
        public readonly float $scale,
        public readonly int $rotate,
        public readonly array $skipped,
        public readonly array $warnings,
        public readonly int $operators,
        public readonly int $width,
        public readonly int $height,
    ) {}

    /** Everything on the page was drawn. */
    public function complete(): bool
    {
        return $this->skipped === [];
    }

    /**
     * The page as PNG bytes (RGB, 8-bit).
     *
     * @param  'none'|'sub'|'up'|'average'|'paeth'|'adaptive'  $filter
     */
    public function png(string $filter = 'none', int $level = 6, ?Budget $budget = null): string
    {
        return PngEncoder::encode($this->width, $this->height, $this->raster->rows(), 3, $filter, $level, $budget);
    }

    /** @return array{0: int, 1: int, 2: int} [r, g, b] at (x, y), top-left origin */
    public function pixel(int $x, int $y): array
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            throw new \OutOfRangeException('No pixel ('.$x.', '.$y.')');
        }

        return $this->raster->pixel($x, $y);
    }

    /** @return list<string> one RGB string (width * 3 bytes) per row, top first */
    public function rows(): array
    {
        return $this->raster->rows();
    }
}
