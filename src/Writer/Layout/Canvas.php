<?php

declare(strict_types=1);

namespace FinePrint\Writer\Layout;

use FinePrint\Writer\Fonts\FontBook;
use FinePrint\Writer\Pdf\Syntax;

/**
 * One page's content stream, drawn in TOP-DOWN coordinates (y grows down the
 * page, as layout thinks) and written in PDF's bottom-up ones.
 *
 * Paint order matters to readers: backgrounds (cell fills, shading,
 * highlights) are drawn before the text over them, which is also what tells
 * a reader's hidden-text check that white text on a dark fill is visible.
 *
 * The only things a page can carry besides its drawing are link annotations,
 * and only with a URI the schema already allowed.
 */
final class Canvas
{
    private string $ops = '';

    /** @var list<array{rect: array{0: float, 1: float, 2: float, 3: float}, uri: string}> */
    private array $links = [];

    /** @var array<string, true> */
    private array $images = [];

    /**
     * Character spacing (Tc) in effect in the stream. It is graphics state,
     * so it outlives BT/ET and is only restored by Q; it must be tracked per
     * page, never per text object, or one tracked run leaks into every later
     * line (whose width layout measured without it). No Tc is ever emitted
     * inside a q/Q pair, so the page-level value is always the live one.
     */
    private float $tc = 0.0;

    /** Lowest point anything was drawn in the body, top-down. */
    public float $bodyBottom = 0.0;

    public function __construct(
        public readonly float $width,
        public readonly float $height,
        private readonly FontBook $fonts,
    ) {}

    /** @param array{0: float, 1: float, 2: float} $rgb */
    public function rect(float $x, float $top, float $w, float $h, array $rgb): void
    {
        if ($w <= 0 || $h <= 0) {
            return;
        }
        $this->ops .= Syntax::fill($rgb)."\n"
            .Syntax::num($x).' '.Syntax::num($this->height - $top - $h).' '.Syntax::num($w).' '.Syntax::num($h)." re f\n";
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $rgb
     */
    public function line(float $x1, float $top1, float $x2, float $top2, float $width, array $rgb, string $style = 'single'): void
    {
        if ($style === 'none' || $width <= 0) {
            return;
        }
        if ($style === 'double') {
            $t = max(0.25, $width / 3);
            $dx = $top1 === $top2 ? 0.0 : $t;
            $dy = $top1 === $top2 ? $t : 0.0;
            $this->line($x1 - $dx, $top1 - $dy, $x2 - $dx, $top2 - $dy, $t, $rgb);
            $this->line($x1 + $dx, $top1 + $dy, $x2 + $dx, $top2 + $dy, $t, $rgb);

            return;
        }
        $this->ops .= 'q '.Syntax::num($width).' w '.Syntax::stroke($rgb).' '.self::dash($style, $width)."\n"
            .Syntax::num($x1).' '.Syntax::num($this->height - $top1).' m '
            .Syntax::num($x2).' '.Syntax::num($this->height - $top2)." l S Q\n";
    }

    /**
     * A frame around the whole page, `$inset` points from every edge to its
     * outside, `$width` points thick. Drawn as closed rectangles rather than
     * four lines, so the corners meet; a double frame is two rectangles, each
     * a third of the width, with a third between them.
     *
     * @param  array{0: float, 1: float, 2: float}  $rgb
     */
    public function frame(float $inset, float $width, array $rgb, string $style = 'single'): void
    {
        if ($style === 'none' || $width <= 0) {
            return;
        }
        $rules = $style === 'double'
            ? [[$inset + $width / 6, $width / 3], [$inset + $width - $width / 6, $width / 3]]
            : [[$inset + $width / 2, $width]];
        foreach ($rules as [$c, $t]) {
            $w = $this->width - 2 * $c;
            $h = $this->height - 2 * $c;
            if ($w <= 0 || $h <= 0) {
                continue;
            }
            $this->ops .= 'q '.Syntax::num($t).' w '.Syntax::stroke($rgb).' '.self::dash($style === 'double' ? 'single' : $style, $t)." 0 j\n"
                .Syntax::num($c).' '.Syntax::num($c).' '.Syntax::num($w).' '.Syntax::num($h)." re S Q\n";
        }
    }

    private static function dash(string $style, float $width): string
    {
        return match ($style) {
            'dashed' => '['.Syntax::num(max(2.0, 3 * $width)).' '.Syntax::num(max(1.5, 2 * $width)).'] 0 d 0 J',
            'dotted' => '[0 '.Syntax::num(max(1.0, 2 * $width)).'] 0 d 1 J',
            default => '[] 0 d 0 J',
        };
    }

    public function image(string $name, float $x, float $top, float $w, float $h): void
    {
        $this->images[$name] = true;
        $this->ops .= 'q '.Syntax::num($w).' 0 0 '.Syntax::num($h).' '.Syntax::num($x).' '
            .Syntax::num($this->height - $top - $h).' cm '.Syntax::name($name)." Do Q\n";
    }

    /**
     * Draw one line of text with its top at `$top`, aligned in `$avail`.
     */
    public function text(Line $line, float $x, float $top, float $avail, string $align): void
    {
        if ($line->items === []) {
            return;
        }
        $extra = $avail - $line->width;
        $dx = 0.0;
        $spread = 0.0;
        if ($extra > 0) {
            if ($align === 'center') {
                $dx = $extra / 2;
            } elseif ($align === 'right') {
                $dx = $extra;
            } elseif ($align === 'justify' && ! $line->last && $line->glueCount() > 0) {
                $spread = $extra / $line->glueCount();
            }
        }
        $base = $this->height - ($top + $line->baseline);

        $cx = $x + $dx;
        $placed = [];
        foreach ($line->items as $item) {
            $w = $item['width'] + ($item['glue'] ? $spread : 0.0);
            $placed[] = $item + ['x' => $cx, 'w' => $w];
            $cx += $w;
        }

        foreach ($placed as $p) {
            $st = $p['style'];
            if ($st->highlight !== null) {
                $this->ops .= Syntax::fill($st->highlight)."\n".Syntax::num($p['x']).' '.Syntax::num($base - 0.22 * $st->size)
                    .' '.Syntax::num($p['w']).' '.Syntax::num($st->size)." re f\n";
            }
        }

        $ops = "BT\n1 0 0 1 ".Syntax::num($x + $dx).' '.Syntax::num($base)." Tm\n";
        $key = null;
        $tj = '';
        foreach ($placed as $p) {
            $st = $p['style'];
            if ($st->textKey() !== $key) {
                if ($tj !== '') {
                    $ops .= '['.$tj."] TJ\n";
                    $tj = '';
                }
                $ops .= '/'.$this->fonts->resourceName($st->font).' '.Syntax::num($st->size).' Tf '.Syntax::fill($st->color);
                if ($st->letterSpacing !== $this->tc) {
                    $ops .= ' '.Syntax::num($st->letterSpacing).' Tc';
                    $this->tc = $st->letterSpacing;
                }
                $ops .= "\n";
                $key = $st->textKey();
            }
            $tj .= ($tj === '' ? '' : ' ').Syntax::hex($st->font->encode($p['text']));
            // Justification widens each space with a TJ adjustment (thousandths
            // of the font size; negative moves right). Works for single-byte and
            // two-byte fonts alike, where Tw would only reach byte 32.
            if ($p['glue'] && $spread > 0) {
                $tj .= ' '.Syntax::num(-$spread * 1000 / $st->size);
            }
        }
        if ($tj !== '') {
            $ops .= '['.$tj."] TJ\n";
        }
        $this->ops .= $ops."ET\n";

        foreach ($placed as $i => $p) {
            $st = $p['style'];
            $t = max(0.5, 0.05 * $st->size);
            // A space is decorated only between two decorated pieces.
            $between = fn (string $flag) => ! $p['glue']
                || (($placed[$i - 1]['style']->{$flag} ?? false) && ($placed[$i + 1]['style']->{$flag} ?? false));
            if ($st->underline && $between('underline')) {
                $this->ops .= Syntax::fill($st->color)."\n".Syntax::num($p['x']).' '.Syntax::num($base - 0.1 * $st->size - $t / 2)
                    .' '.Syntax::num($p['w']).' '.Syntax::num($t)." re f\n";
            }
            if ($st->strike && $between('strike')) {
                $this->ops .= Syntax::fill($st->color)."\n".Syntax::num($p['x']).' '.Syntax::num($base + 0.28 * $st->size - $t / 2)
                    .' '.Syntax::num($p['w']).' '.Syntax::num($t)." re f\n";
            }
        }

        $this->collectLinks($placed, $base);
    }

    /** @param list<array<string, mixed>> $placed */
    private function collectLinks(array $placed, float $base): void
    {
        $open = null;
        foreach ($placed as $p) {
            $link = $p['style']->link;
            if ($open !== null && $link === $open['uri']) {
                $open['x1'] = $p['x'] + $p['w'];
                $open['asc'] = max($open['asc'], $p['style']->ascent());
                $open['desc'] = max($open['desc'], $p['style']->descent());

                continue;
            }
            if ($open !== null) {
                $this->addLink($open, $base);
            }
            $open = $link === null || $p['glue'] ? null : [
                'uri' => $link, 'x0' => $p['x'], 'x1' => $p['x'] + $p['w'],
                'asc' => $p['style']->ascent(), 'desc' => $p['style']->descent(),
            ];
        }
        if ($open !== null) {
            $this->addLink($open, $base);
        }
    }

    /** @param array{uri: string, x0: float, x1: float, asc: float, desc: float} $open */
    private function addLink(array $open, float $base): void
    {
        $this->links[] = ['rect' => [$open['x0'], $base - $open['desc'], $open['x1'], $base + $open['asc']], 'uri' => $open['uri']];
    }

    public function content(): string
    {
        return $this->ops;
    }

    /** @return list<array{rect: array{0: float, 1: float, 2: float, 3: float}, uri: string}> */
    public function links(): array
    {
        return $this->links;
    }

    /** @return list<string> */
    public function images(): array
    {
        return array_keys($this->images);
    }
}
