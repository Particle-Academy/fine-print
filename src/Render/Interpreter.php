<?php

declare(strict_types=1);

namespace FinePrint\Render;

use FinePrint\Budget;
use FinePrint\Document;
use FinePrint\Exceptions\FontException;
use FinePrint\Exceptions\ImageException;
use FinePrint\Exceptions\RefusedException;
use FinePrint\Objects\PdfDict;
use FinePrint\Objects\PdfName;
use FinePrint\Objects\PdfRef;
use FinePrint\Objects\PdfStream;
use FinePrint\Objects\PdfString;
use FinePrint\Render\Glyphs\RenderFont;
use FinePrint\Render\Images\ImagePainter;
use FinePrint\Render\Images\InlineImageReader;
use FinePrint\Render\Images\SampledImage;
use FinePrint\Parser\Keyword;
use FinePrint\Parser\Parser;
use FinePrint\Parser\PdfSyntaxException;
use FinePrint\Text\TextExtractor;
use Generator;

/**
 * The P3a content-stream interpreter: the full graphics state, paths and
 * painting, and form XObjects, drawn onto a {@see Raster}.
 *
 * It interprets; nothing in a content stream executes. What P3a does not
 * draw yet — other font types, clipping, shading, patterns, transparency — is
 * counted into `skipped` so a caller knows the picture is incomplete.
 *
 * Bounded like P1's text interpreter: every operator, page and forms
 * together, counts against `maxOperators`; forms nest at most `maxFormDepth`
 * and never into themselves; the `q` stack is capped; and the render budget
 * is ticked by every loop that a file can lengthen.
 */
final class Interpreter
{
    /** Flattening tolerance in device pixels. */
    private const TOLERANCE = 0.2;

    /** Saved states beyond this are counted, not stored (`q` x 10^6 is not 10^6 clones). */
    private const MAX_STACK = 1024;

    /** Forms whose decoded content is at most this are parsed once and replayed. */
    private const FORM_CACHE_BYTES = 256 * 1024;

    private GraphicsState $gs;

    /** @var list<GraphicsState> */
    private array $stack = [];

    private int $stackOverflow = 0;

    private Path $path;

    private int $operators = 0;

    /** @var array<string, int> */
    private array $skipped = [];

    /** @var list<string> */
    private array $forms = [];

    /** @var array<string, list<array{0: string, 1: list<mixed>}>> */
    private array $formOps = [];

    private int $cachedOps = 0;

    /** Fonts kept loaded at once, and their programs' bytes, before the cache starts over. */
    private const MAX_FONTS = 256;

    private const FONT_CACHE_BYTES = 64 * 1024 * 1024;

    /** Font names listed per skipped kind. */
    private const MAX_FONT_NAMES = 16;

    /** @var list<float> the text matrix and the text line matrix (not part of the graphics state) */
    private array $tm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

    private array $tlm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

    /** @var array<string, RenderFont> */
    private array $fonts = [];

    private int $fontBytes = 0;

    /** @var array<string, array<string, true>> */
    private array $skippedFonts = [];

    /** @param list<float> $baseMatrix default user space to device pixels */
    public function __construct(
        private readonly Document $doc,
        private readonly Raster $raster,
        private readonly Budget $budget,
        array $baseMatrix,
    ) {
        $this->gs = new GraphicsState();
        $this->gs->ctm = $baseMatrix;
        $this->path = new Path();
    }

    public function run(string $content, ?PdfDict $resources): void
    {
        $this->execute($this->operations($content), $resources);
    }

    public function operators(): int
    {
        return $this->operators;
    }

    /** @return array<string, int> */
    public function skipped(): array
    {
        return $this->skipped;
    }

    public function skip(string $kind, int $n = 1): void
    {
        $this->skipped[$kind] = ($this->skipped[$kind] ?? 0) + $n;
    }

    /**
     * Operators and operands, one at a time. Inline images are read here,
     * where the parser is, and travel as a PdfStream operand of `BI`.
     *
     * @return Generator<int, array{0: string, 1: list<mixed>}>
     */
    private function operations(string $content): Generator
    {
        $p = new Parser($content, refs: false, budget: $this->budget);
        $ops = [];
        while (true) {
            try {
                if ($p->atEnd()) {
                    return;
                }
                $v = $p->parseValue();
            } catch (PdfSyntaxException) {
                if ($p->atEnd()) {
                    return;
                }
                $ops = [];

                continue;
            }
            if (! $v instanceof Keyword) {
                if (count($ops) < 64) {
                    $ops[] = $v;
                }

                continue;
            }
            if ($v->word === 'BI') {
                $image = InlineImageReader::read($p, $this->budget);
                yield ['BI', $image !== null ? [$image] : []];
                $ops = [];

                continue;
            }
            yield [$v->word, $ops];
            $ops = [];
        }
    }

    /** @param iterable<array{0: string, 1: list<mixed>}> $ops */
    private function execute(iterable $ops, ?PdfDict $resources): void
    {
        $max = $this->budget->limits->maxOperators;
        foreach ($ops as [$op, $o]) {
            if (++$this->operators > $max) {
                throw new RefusedException('too_many_operators', 'A page has more than '.$max.' content-stream operators.');
            }
            $this->budget->tick();
            $this->op($op, $o, $resources);
        }
    }

    /** @param list<mixed> $o */
    private function op(string $op, array $o, ?PdfDict $resources): void
    {
        $gs = $this->gs;
        switch ($op) {
            // --- graphics state ---------------------------------------------
            case 'q':
                if (count($this->stack) < self::MAX_STACK) {
                    $this->stack[] = clone $gs;
                } else {
                    $this->stackOverflow++;
                }

                return;
            case 'Q':
                if ($this->stackOverflow > 0) {
                    $this->stackOverflow--;
                } elseif ($this->stack !== []) {
                    $this->gs = array_pop($this->stack);
                }

                return;
            case 'cm':
                if (($m = self::numbers($o, 6)) !== null) {
                    $gs->ctm = TextExtractor::mul($m, $gs->ctm);
                }

                return;
            case 'w':
                if (($n = self::numbers($o, 1)) !== null) {
                    $gs->lineWidth = abs($n[0]);
                }

                return;
            case 'J':
                if (($n = self::numbers($o, 1)) !== null) {
                    $gs->cap = max(0, min(2, (int) $n[0]));
                }

                return;
            case 'j':
                if (($n = self::numbers($o, 1)) !== null) {
                    $gs->join = max(0, min(2, (int) $n[0]));
                }

                return;
            case 'M':
                if (($n = self::numbers($o, 1)) !== null) {
                    $gs->miterLimit = max(1.0, $n[0]);
                }

                return;
            case 'd':
                if (count($o) >= 2 && is_array($o[count($o) - 2])) {
                    $this->setDash($o[count($o) - 2], $o[count($o) - 1]);
                }

                return;
            case 'gs':
                if (($o[0] ?? null) instanceof PdfName) {
                    $this->extGState($o[0]->value, $resources);
                }

                return;

            // --- colour -------------------------------------------------------
            case 'g': case 'G': case 'rg': case 'RG': case 'k': case 'K':
                $space = match (strtolower($op)) {
                    'g' => ColourSpace::gray(),
                    'rg' => ColourSpace::rgb(),
                    default => ColourSpace::cmyk(),
                };
                if (($n = self::numbers($o, $space->components)) !== null) {
                    $this->setColour(ctype_upper($op[0]), $space, $n);
                }

                return;
            case 'cs': case 'CS':
                if (($o[0] ?? null) instanceof PdfName) {
                    $space = ColourSpace::resolve($o[0]->value, $resources, $this->doc);
                    $this->setColour($op === 'CS', $space, $space->initial());
                }

                return;
            case 'sc': case 'scn': case 'SC': case 'SCN':
                $stroke = ctype_upper($op[0]);
                $space = $stroke ? $gs->strokeSpace : $gs->fillSpace;
                $nums = array_values(array_filter($o, fn ($v) => is_int($v) || is_float($v)));
                if ($space->components > 0 && count($nums) >= $space->components) {
                    $this->setColour($stroke, $space, array_map(
                        fn ($v) => self::finite((float) $v),
                        array_slice($nums, -$space->components),
                    ));
                }

                return;

            // --- path construction --------------------------------------------
            case 'm':
                if (($n = self::numbers($o, 2)) !== null) {
                    $this->path->moveTo($n[0], $n[1]);
                }

                return;
            case 'l':
                if (($n = self::numbers($o, 2)) !== null) {
                    $this->path->lineTo($n[0], $n[1]);
                }

                return;
            case 'c':
                if (($n = self::numbers($o, 6)) !== null) {
                    $this->path->curveTo(...$n);
                }

                return;
            case 'v':
                if (($n = self::numbers($o, 4)) !== null && ($cur = $this->path->current()) !== null) {
                    $this->path->curveTo($cur[0], $cur[1], ...$n);
                }

                return;
            case 'y':
                if (($n = self::numbers($o, 4)) !== null) {
                    $this->path->curveTo($n[0], $n[1], $n[2], $n[3], $n[2], $n[3]);
                }

                return;
            case 'h':
                $this->path->close();

                return;
            case 're':
                if (($n = self::numbers($o, 4)) !== null) {
                    $this->path->rect(...$n);
                }

                return;

            // --- painting -------------------------------------------------------
            case 'f': case 'F': case 'f*':
                $this->fill($op === 'f*');
                $this->path = new Path();

                return;
            case 'S':
                $this->stroke();
                $this->path = new Path();

                return;
            case 's':
                $this->path->close();
                $this->stroke();
                $this->path = new Path();

                return;
            case 'B': case 'B*':
                $this->fill($op === 'B*');
                $this->stroke();
                $this->path = new Path();

                return;
            case 'b': case 'b*':
                $this->path->close();
                $this->fill($op === 'b*');
                $this->stroke();
                $this->path = new Path();

                return;
            case 'n':
                $this->path = new Path();

                return;
            case 'W': case 'W*':
                $this->skip('clip');

                return;

            // --- text (P3b) ----------------------------------------------------
            case 'BT':
                $this->tm = $this->tlm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

                return;
            case 'Tf':
                if (count($o) >= 2 && $o[count($o) - 2] instanceof PdfName && ($n = self::numbers($o, 1)) !== null) {
                    $gs->fontSize = $n[0];
                    $gs->font = $this->font($o[count($o) - 2]->value, $resources);
                    $gs->fontMissing = $gs->font === null;
                }

                return;
            case 'Tc':
                if (($n = self::numbers($o, 1)) !== null) {
                    $gs->charSpacing = $n[0];
                }

                return;
            case 'Tw':
                if (($n = self::numbers($o, 1)) !== null) {
                    $gs->wordSpacing = $n[0];
                }

                return;
            case 'Tz':
                if (($n = self::numbers($o, 1)) !== null) {
                    $gs->hScale = $n[0] / 100;
                }

                return;
            case 'TL':
                if (($n = self::numbers($o, 1)) !== null) {
                    $gs->leading = $n[0];
                }

                return;
            case 'Ts':
                if (($n = self::numbers($o, 1)) !== null) {
                    $gs->rise = $n[0];
                }

                return;
            case 'Tr':
                if (($n = self::numbers($o, 1)) !== null) {
                    $gs->textRender = max(0, min(7, (int) $n[0]));
                }

                return;
            case 'Td': case 'TD':
                if (($n = self::numbers($o, 2)) !== null) {
                    if ($op === 'TD') {
                        $gs->leading = -$n[1];
                    }
                    $this->nextLine($n[0], $n[1]);
                }

                return;
            case 'Tm':
                if (($m = self::numbers($o, 6)) !== null) {
                    $this->tm = $this->tlm = $m;
                }

                return;
            case 'T*':
                $this->nextLine(0.0, -$gs->leading);

                return;
            case 'Tj':
                if (($s = end($o)) instanceof PdfString) {
                    $this->show([$s]);
                }

                return;
            case 'TJ':
                if (is_array($a = end($o))) {
                    $this->show($a);
                }

                return;
            case "'":
                $this->nextLine(0.0, -$gs->leading);
                if (($s = end($o)) instanceof PdfString) {
                    $this->show([$s]);
                }

                return;
            case '"':
                if (count($o) >= 3 && ($s = $o[count($o) - 1]) instanceof PdfString && ($n = self::numbers(array_slice($o, 0, -1), 2)) !== null) {
                    $gs->wordSpacing = $n[0];
                    $gs->charSpacing = $n[1];
                    $this->nextLine(0.0, -$gs->leading);
                    $this->show([$s]);
                }

                return;

            // --- everything not drawn yet --------------------------------------
            case 'sh':
                $this->skip('shading');

                return;
            case 'BI':
                if (($o[0] ?? null) instanceof PdfStream) {
                    $this->image($o[0], $resources, 'inline-image');
                } else {
                    $this->skip('inline-image');
                }

                return;
            case 'BDC':
                if (($o[0] ?? null) instanceof PdfName && $o[0]->value === 'OC') {
                    $this->skip('optional-content');
                }

                return;
            case 'Do':
                if (($o[0] ?? null) instanceof PdfName) {
                    $this->xobject($o[0]->value, $resources);
                }

                return;
        }
        // Text state and positioning, marked content, compatibility sections,
        // rendering intent, flatness, Type 3 glyph metrics: nothing to draw.
    }

    /** @param list<float> $c */
    private function setColour(bool $stroke, ColourSpace $space, array $c): void
    {
        if ($stroke) {
            $this->gs->strokeSpace = $space;
            $this->gs->stroke = $c;
        } else {
            $this->gs->fillSpace = $space;
            $this->gs->fill = $c;
        }
    }

    private function setDash(array $arr, mixed $phase): void
    {
        $dash = [];
        foreach (array_slice($arr, 0, 64) as $v) {
            $v = $this->doc->resolve($v);
            if (! is_int($v) && ! is_float($v)) {
                return;
            }
            $dash[] = self::finite((float) $v);
        }
        $phase = $this->doc->resolve($phase);
        $this->gs->dash = $dash;
        $this->gs->dashPhase = is_int($phase) || is_float($phase) ? self::finite((float) $phase) : 0.0;
    }

    private function extGState(string $name, ?PdfDict $resources): void
    {
        $all = $resources !== null ? $this->doc->resolve($resources->get('ExtGState')) : null;
        $gsDict = $all instanceof PdfDict ? $this->doc->resolve($all->get($name)) : null;
        if (! $gsDict instanceof PdfDict) {
            return;
        }
        $num = function (string $key) use ($gsDict): ?float {
            $v = $this->doc->resolve($gsDict->get($key));

            return is_int($v) || is_float($v) ? self::finite((float) $v) : null;
        };
        $gs = $this->gs;
        if (($v = $num('LW')) !== null) {
            $gs->lineWidth = abs($v);
        }
        if (($v = $num('LC')) !== null) {
            $gs->cap = max(0, min(2, (int) $v));
        }
        if (($v = $num('LJ')) !== null) {
            $gs->join = max(0, min(2, (int) $v));
        }
        if (($v = $num('ML')) !== null) {
            $gs->miterLimit = max(1.0, $v);
        }
        $d = $this->doc->resolve($gsDict->get('D'));
        if (is_array($d) && count($d) >= 2 && is_array($this->doc->resolve($d[0]))) {
            $this->setDash($this->doc->resolve($d[0]), $d[1]);
        }
        if (($v = $num('CA')) !== null) {
            $gs->strokeAlpha = $v;
        }
        if (($v = $num('ca')) !== null) {
            $gs->fillAlpha = $v;
        }
        if ($gsDict->has('SMask')) {
            $mask = $this->doc->resolve($gsDict->get('SMask'));
            $gs->maskOrBlend = ! ($mask instanceof PdfName && $mask->value === 'None');
        }
        if ($gsDict->has('BM')) {
            $bm = $this->doc->resolve($gsDict->get('BM'));
            $bm = is_array($bm) ? $this->doc->resolve($bm[0] ?? null) : $bm;
            $gs->maskOrBlend = $gs->maskOrBlend || ! ($bm instanceof PdfName && in_array($bm->value, ['Normal', 'Compatible'], true));
        }
    }

    // ------------------------------------------------------------------
    // Painting
    // ------------------------------------------------------------------

    /** The paint colour, or null when the paint is skipped; reports what it approximates. */
    private function paint(bool $stroke): ?array
    {
        $gs = $this->gs;
        $space = $stroke ? $gs->strokeSpace : $gs->fillSpace;
        if ($space->kind === 'pattern') {
            $this->skip('pattern');

            return null;
        }
        if ($space->kind === 'approx' || $space->kind === 'unsupported') {
            $this->skip('colorspace');
        }
        if (($stroke ? $gs->strokeAlpha : $gs->fillAlpha) < 1.0 || $gs->maskOrBlend) {
            $this->skip('transparency');
        }

        return $space->toRgb($stroke ? $gs->stroke : $gs->fill);
    }

    private function fill(bool $evenOdd): void
    {
        if ($this->path->isEmpty()) {
            return;
        }
        $rgb = $this->paint(false);
        if ($rgb === null) {
            return;
        }
        $r = new Rasterizer($this->raster->width, $this->raster->height, $this->budget);
        foreach ($this->path->devicePolygons($this->gs->ctm, self::TOLERANCE) as $poly) {
            $r->addFlat($poly);
        }
        $this->blit($r, $evenOdd, $rgb);
    }

    private function stroke(?Path $path = null, ?array $rgb = null, float $deviceYShift = 0.0): void
    {
        $path ??= $this->path;
        if ($path->isEmpty()) {
            return;
        }
        $rgb ??= $this->paint(true);
        if ($rgb === null) {
            return;
        }
        $gs = $this->gs;
        $m = $gs->ctm;
        $m[5] += $deviceYShift;
        // How far one user unit can stretch in device space: sets the
        // flattening tolerance in user space, and the hairline width.
        $stretch = sqrt(max($m[0] * $m[0] + $m[1] * $m[1], $m[2] * $m[2] + $m[3] * $m[3]));
        if (! is_finite($stretch) || $stretch < 1e-12) {
            return;
        }
        $width = $gs->lineWidth > 0 ? $gs->lineWidth : 1.0 / $stretch;
        $tol = self::TOLERANCE / $stretch;

        $r = new Rasterizer($this->raster->width, $this->raster->height, $this->budget);
        Stroker::stroke(
            $path->userPolylines($tol),
            $width,
            $gs->cap,
            $gs->join,
            $gs->miterLimit,
            $gs->dash,
            $gs->dashPhase,
            $tol,
            function (array $poly) use ($r, $m): void {
                $flat = [];
                foreach ($poly as [$x, $y]) {
                    $flat[] = $x * $m[0] + $y * $m[2] + $m[4];
                    $flat[] = $x * $m[1] + $y * $m[3] + $m[5];
                }
                $r->addFlat($flat);
            },
            $this->budget,
        );
        $this->blit($r, false, $rgb);
    }

    /** @param array{0: int, 1: int, 2: int} $rgb */
    private function blit(Rasterizer $r, bool $evenOdd, array $rgb): void
    {
        [$cr, $cg, $cb] = $rgb;
        foreach ($r->spans($evenOdd) as [$y, $x, $len, $a]) {
            $this->raster->span($y, $x, $len, $cr, $cg, $cb, $a);
        }
    }

    // ------------------------------------------------------------------
    // Text (P3b)
    // ------------------------------------------------------------------

    /** The font named by a Tf, loaded once per render (by object). */
    private function font(string $name, ?PdfDict $resources): ?RenderFont
    {
        $all = $resources !== null ? $this->doc->resolve($resources->get('Font')) : null;
        if (! $all instanceof PdfDict) {
            return null;
        }
        $ref = $all->get($name);
        $dict = $this->doc->resolve($ref);
        if (! $dict instanceof PdfDict) {
            return null;
        }
        $key = $ref instanceof PdfRef ? $ref->key() : 'd'.spl_object_id($dict);
        if (isset($this->fonts[$key])) {
            return $this->fonts[$key];
        }
        $f = RenderFont::load($dict, $this->doc);
        if (count($this->fonts) >= self::MAX_FONTS || $this->fontBytes + $f->programBytes > self::FONT_CACHE_BYTES) {
            // A page naming a thousand large fonts costs re-reads, not memory.
            $this->fonts = [];
            $this->fontBytes = 0;
        }
        $this->fonts[$key] = $f;
        $this->fontBytes += $f->programBytes;

        return $f;
    }

    private function nextLine(float $tx, float $ty): void
    {
        $this->tlm = TextExtractor::mul([1.0, 0.0, 0.0, 1.0, $tx, $ty], $this->tlm);
        $this->tm = $this->tlm;
    }

    /**
     * One show operator (Tj, TJ, ', "): strings and TJ adjustments.
     * Counted in `skipped` once per operator, as everything else is.
     *
     * @param  list<mixed>  $items
     */
    private function show(array $items): void
    {
        $gs = $this->gs;
        $font = $gs->font;
        if ($font === null) {
            $this->skipFont('text-font-invalid', null);

            return;
        }
        $mode = $gs->textRender;
        if ($mode >= 4) {
            $this->skip('clip');    // the clip itself waits for P3d; the paint is drawn
        }
        $fillOn = $mode === 0 || $mode === 2 || $mode === 4 || $mode === 6;
        $strokeOn = $mode === 1 || $mode === 2 || $mode === 5 || $mode === 6;
        if (! $font->drawable()) {
            $this->skipFont((string) $font->skip, $font->name);
        }
        $draw = $font->drawable() && ($fillOn || $strokeOn);
        $fillRgb = $draw && $fillOn ? $this->paint(false) : null;
        $strokeRgb = $draw && $strokeOn ? $this->paint(true) : null;
        $draw = $fillRgb !== null || $strokeRgb !== null;

        $fs = $gs->fontSize;
        $th = $gs->hScale;
        $upem = $font->unitsPerEm();
        foreach ($items as $it) {
            if (is_int($it) || is_float($it)) {
                $this->advance(-self::finite((float) $it) / 1000 * $fs * $th);

                continue;
            }
            if (! $it instanceof PdfString) {
                continue;
            }
            foreach ($font->glyphs($it->bytes) as [$gid, $w, $space]) {
                $this->budget->tick();
                if ($draw) {
                    $this->glyph($font, $gid, $fs, $th, $upem, $fillRgb, $strokeRgb);
                }
                $this->advance(($w / 1000 * $fs + $gs->charSpacing + ($space ? $gs->wordSpacing : 0.0)) * $th);
            }
        }
    }

    private function advance(float $tx): void
    {
        $this->tm[4] += $tx * $this->tm[0];
        $this->tm[5] += $tx * $this->tm[1];
    }

    /**
     * Draw one glyph at the current text matrix.
     *
     * @param  ?array{0: int, 1: int, 2: int}  $fillRgb
     * @param  ?array{0: int, 1: int, 2: int}  $strokeRgb
     */
    private function glyph(RenderFont $font, int $gid, float $fs, float $th, int $upem, ?array $fillRgb, ?array $strokeRgb): void
    {
        $gs = $this->gs;
        $trm = TextExtractor::mul([$fs * $th, 0.0, 0.0, $fs, 0.0, $gs->rise], $this->tm);
        $k = 1.0 / $upem;
        $g = [$trm[0] * $k, $trm[1] * $k, $trm[2] * $k, $trm[3] * $k, $trm[4], $trm[5]];   // glyph space -> user
        $m = TextExtractor::mul($g, $gs->ctm);                                               // glyph space -> device
        foreach ($m as $v) {
            if (! is_finite($v)) {
                return;
            }
        }
        // Deliberate placement policy (owner ruling 2026-10-22): move the
        // glyph origin DOWN to the next whole device pixel. Aligned origins
        // stay aligned; x and the text matrix's advances remain exact.
        $deviceYShift = ceil($m[5]) - $m[5];
        $m[5] = ceil($m[5]);
        try {
            $o = $font->outline($gid);
        } catch (FontException) {
            $this->skipFont('text-glyph', $font->name);

            return;
        }
        if ($o->subpaths === []) {
            return;
        }

        // Cull: a glyph wholly off the canvas costs four corner transforms.
        [$bx0, $by0, $bx1, $by1] = $o->bbox;
        $xs = [];
        $ys = [];
        foreach ([[$bx0, $by0], [$bx1, $by0], [$bx0, $by1], [$bx1, $by1]] as [$x, $y]) {
            $xs[] = $x * $m[0] + $y * $m[2] + $m[4];
            $ys[] = $x * $m[1] + $y * $m[3] + $m[5];
        }
        $margin = 1.0;
        if ($strokeRgb !== null) {
            $c = $gs->ctm;
            $margin += $gs->lineWidth * sqrt(max($c[0] * $c[0] + $c[1] * $c[1], $c[2] * $c[2] + $c[3] * $c[3])) + $gs->miterLimit;
        }
        if (max($xs) < -$margin || min($xs) > $this->raster->width + $margin
            || max($ys) < -$margin || min($ys) > $this->raster->height + $margin) {
            return;
        }

        if ($fillRgb !== null) {
            $r = new Rasterizer($this->raster->width, $this->raster->height, $this->budget);
            foreach (Path::flattenSubpaths($o->subpaths, $m, self::TOLERANCE) as $poly) {
                $r->addFlat($poly);
            }
            $this->blit($r, false, $fillRgb);
        }
        if ($strokeRgb !== null) {
            // Translate the stroker's device matrix, preserving the user-space
            // pen shape even under rotated, skewed or singular CTMs.
            $this->stroke(Path::fromSubpaths($o->subpaths, $g), $strokeRgb, $deviceYShift);
        }
    }

    /** Count a skip, remembering which fonts it was for (a few, by clean name). */
    private function skipFont(string $kind, ?string $name): void
    {
        $this->skip($kind);
        if ($name !== null && $name !== '' && count($this->skippedFonts[$kind] ?? []) < self::MAX_FONT_NAMES) {
            $this->skippedFonts[$kind][$name] = true;
        }
    }

    /** @return array<string, list<string>> kind => font names, sorted */
    public function skippedFonts(): array
    {
        $out = [];
        foreach ($this->skippedFonts as $kind => $names) {
            $list = array_keys($names);
            sort($list, SORT_STRING);
            $out[$kind] = array_map('strval', $list);
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // XObjects
    // ------------------------------------------------------------------

    private function xobject(string $name, ?PdfDict $resources): void
    {
        $all = $resources !== null ? $this->doc->resolve($resources->get('XObject')) : null;
        if (! $all instanceof PdfDict) {
            return;
        }
        $ref = $all->get($name);
        $x = $this->doc->resolve($ref);
        if (! $x instanceof PdfStream) {
            return;
        }
        $subtype = $x->dict->name('Subtype');
        if ($subtype === 'Image') {
            $this->image($x, $resources, 'image');

            return;
        }
        if ($subtype !== 'Form') {
            return;
        }

        $key = $ref instanceof PdfRef ? $ref->key() : 'd'.spl_object_id($x);
        if (in_array($key, $this->forms, true)) {
            $this->doc->warn('form XObject '.$key.' draws itself; the inner draw was skipped');
            $this->skip('form-depth');

            return;
        }
        if (count($this->forms) >= $this->budget->limits->maxFormDepth) {
            $this->doc->warn('form XObjects nest deeper than '.$this->budget->limits->maxFormDepth.'; the rest were skipped');
            $this->skip('form-depth');

            return;
        }

        $ops = $this->formOps[$key] ?? null;
        if ($ops === null) {
            $content = $this->doc->streamData($x);
            if ($content === null) {
                return;
            }
            if (strlen($content) <= self::FORM_CACHE_BYTES && $this->cachedOps < 500_000) {
                $ops = iterator_to_array($this->operations($content), false);
                $this->cachedOps += count($ops);
                $this->formOps[$key] = $ops;
            } else {
                $ops = $this->operations($content);
            }
        }

        $formResources = $this->doc->resolve($x->dict->get('Resources'));
        $saved = [$this->gs, $this->stack, $this->stackOverflow, $this->path];
        $this->gs = clone $this->gs;
        $matrix = $this->doc->resolve($x->dict->get('Matrix'));
        if (is_array($matrix) && ($m = self::numbers(array_map(fn ($v) => $this->doc->resolve($v), $matrix), 6)) !== null) {
            $this->gs->ctm = TextExtractor::mul($m, $this->gs->ctm);
        }
        $this->stack = [];
        $this->stackOverflow = 0;
        $this->path = new Path();
        $this->forms[] = $key;
        try {
            $this->execute($ops, $formResources instanceof PdfDict ? $formResources : $resources);
        } finally {
            array_pop($this->forms);
            [$this->gs, $this->stack, $this->stackOverflow, $this->path] = $saved;
        }
    }

    // ------------------------------------------------------------------

    private function image(PdfStream $stream, ?PdfDict $resources, string $kind): void
    {
        try {
            $image = SampledImage::load($stream, $this->doc, $this->budget, $resources);
            if ($stream->dict->has('SMask') || $stream->dict->has('Mask')) {
                $this->skip('transparency');
            }
            $fill = $image->stencil ? $this->paint(false) : [0, 0, 0];
            if (! $image->stencil && ($this->gs->fillAlpha < 1.0 || $this->gs->maskOrBlend)) {
                $this->skip('transparency');
            }
            if ($fill !== null) {
                ImagePainter::paint($image, $this->raster, $this->gs->ctm, $fill, $this->budget);
            }
        } catch (ImageException) {
            $this->skip($kind);
            ImagePainter::paint(SampledImage::placeholder(), $this->raster, $this->gs->ctm, [0, 0, 0], $this->budget);
        }
    }

    /**
     * The last $n operands as finite floats, or null when there are too few
     * or one is not a number. Non-finite values (an overflowing literal)
     * become 0, so nothing downstream ever sees INF or NAN.
     *
     * @param  list<mixed>  $o
     * @return ?list<float>
     */
    private static function numbers(array $o, int $n): ?array
    {
        if (count($o) < $n) {
            return null;
        }
        $out = [];
        foreach (array_slice($o, -$n) as $v) {
            if (! is_int($v) && ! is_float($v)) {
                return null;
            }
            $out[] = self::finite((float) $v);
        }

        return $out;
    }

    private static function finite(float $v): float
    {
        return is_finite($v) ? $v : 0.0;
    }
}
