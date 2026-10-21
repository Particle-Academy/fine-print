<?php

declare(strict_types=1);

namespace FinePrint\Render;

/**
 * One rendered page: the raster, its size, and what was NOT drawn.
 *
 * `skipped` is the honest part. P3a draws vector paths only; anything else
 * on the page is counted here by kind, so a caller can tell a complete
 * picture from a partial one instead of trusting pixels that are missing
 * content. Kinds, in the order they are listed (alphabetical):
 *
 * | kind | what was not drawn |
 * |---|---|
 * | `annotation` | annotations with an appearance stream (viewers draw them) |
 * | `clip` | clipping (`W` / `W*`); the paint after it was drawn unclipped |
 * | `colorspace` | paint in Separation/DeviceN (drawn as grey from the tint), Lab, Indexed or unknown spaces (drawn black) |
 * | `form-depth` | form XObjects nested past `maxFormDepth`, or drawing themselves |
 * | `image` | image XObjects |
 * | `inline-image` | inline images |
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
 * | `transparency` | paint under constant alpha, a soft mask or a blend mode (drawn opaque) |
 *
 * Counts are per operator (or per annotation): `text-unembedded: 12` is
 * twelve show operators, not twelve characters. `text-glyph` counts glyphs.
 * The `text-*` kinds also carry `fonts`: the fonts concerned (at most 16),
 * by BaseFont name stripped to PostScript name characters.
 *
 * Text in clipping render modes 4-6 is painted and its clip counted under
 * `clip`; mode 7 paints nothing and counts `clip`. Mode 3 (invisible) is
 * drawn correctly by drawing nothing, and is not a skip.
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
    public function png(string $filter = 'none', int $level = 6): string
    {
        return PngEncoder::encode($this->width, $this->height, $this->raster->rows(), 3, $filter, $level);
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
