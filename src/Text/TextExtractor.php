<?php

declare(strict_types=1);

namespace FinePrint\Text;

use FinePrint\Budget;
use FinePrint\Document;
use FinePrint\Exceptions\RefusedException;
use FinePrint\Objects\PdfDict;
use FinePrint\Objects\PdfName;
use FinePrint\Objects\PdfRef;
use FinePrint\Objects\PdfStream;
use FinePrint\Objects\PdfString;
use FinePrint\Parser\InlineImage;
use FinePrint\Parser\Keyword;
use FinePrint\Parser\Parser;
use FinePrint\Parser\PdfSyntaxException;

/**
 * The text subset of the content-stream interpreter (ISO 32000-1 9.4, 8.4).
 *
 * Tracks exactly what text extraction needs — the CTM, the text and line
 * matrices, the text state, and the fill colour — and emits one run per
 * shown string with its device-space position and size.
 *
 * It interprets; it never executes anything. There is nothing executable in a
 * content stream, and the operators it does not understand are skipped.
 */
final class TextExtractor
{
    /** @var array<string, Font> */
    private array $fonts = [];

    /** @var list<array{text: string, x0: float, y: float, x1: float, size: float, flags: list<string>}> */
    private array $runs = [];

    /** @var array<string, mixed> */
    private array $gs;

    /** @var list<array<string, mixed>> */
    private array $stack = [];

    /** @var list<float> */
    private array $tm = [1, 0, 0, 1, 0, 0];

    /** @var list<float> */
    private array $tlm = [1, 0, 0, 1, 0, 0];

    /** @var ?array{x0: float, y0: float, x1: float, y1: float} the current path, device space */
    private ?array $path = null;

    /** @var list<array{x0: float, y0: float, x1: float, y1: float, white: bool}> painted areas, device space */
    private array $regions = [];

    private readonly Budget $budget;

    private int $operators = 0;

    /** @var list<string> form XObjects currently being drawn, outermost first */
    private array $forms = [];

    /** @param list<float> $cropBox */
    public function __construct(
        private readonly Document $doc,
        private readonly array $cropBox,
    ) {
        $this->gs = self::initialState();
        $this->budget = $doc->budget();
    }

    /**
     * @return list<array{text: string, x0: float, y: float, x1: float, size: float, flags: list<string>}>
     */
    public function extract(string $content, ?PdfDict $resources): array
    {
        $this->runs = [];
        $this->interpret($content, $resources, 0);

        return $this->runs;
    }

    /** @return array<string, mixed> */
    private static function initialState(): array
    {
        return [
            'ctm' => [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
            'font' => null,
            'size' => 0.0,
            'tc' => 0.0,
            'tw' => 0.0,
            'th' => 1.0,
            'tl' => 0.0,
            'ts' => 0.0,
            'tr' => 0,
            'fill' => [0.0],
            'stroke' => [0.0],
        ];
    }

    private function interpret(string $content, ?PdfDict $resources, int $depth): void
    {
        $p = new Parser($content, refs: false, budget: $this->budget);
        $ops = [];
        $max = $this->budget->limits->maxOperators;

        while (true) {
            try {
                if ($p->atEnd()) {
                    break;
                }
                $v = $p->parseValue();
            } catch (PdfSyntaxException) {
                // A stray delimiter; skip past it and carry on.
                if ($p->atEnd()) {
                    break;
                }
                $ops = [];

                continue;
            }

            if (! $v instanceof Keyword) {
                $ops[] = $v;

                continue;
            }

            if (++$this->operators > $max) {
                throw new RefusedException('too_many_operators', 'A page has more than '.$max.' content-stream operators.');
            }
            $this->op($v->word, $ops, $p, $resources, $depth);
            $ops = [];
        }
    }

    /** @param list<mixed> $o */
    private function op(string $op, array $o, Parser $p, ?PdfDict $resources, int $depth): void
    {
        switch ($op) {
            case 'q':
                $this->stack[] = $this->gs;

                return;
            case 'Q':
                if ($this->stack !== []) {
                    $this->gs = array_pop($this->stack);
                }

                return;
            case 'cm':
                if (count($o) >= 6) {
                    $this->gs['ctm'] = self::mul(self::nums($o, 6), $this->gs['ctm']);
                }

                return;
            case 'BT':
                $this->tm = $this->tlm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

                return;
            case 'Tf':
                if (count($o) >= 2 && $o[0] instanceof PdfName) {
                    $this->gs['font'] = $this->font($o[0]->value, $resources);
                    $this->gs['size'] = self::num($o[1]);
                }

                return;
            case 'Tc': $this->gs['tc'] = self::num($o[0] ?? 0);

                return;
            case 'Tw': $this->gs['tw'] = self::num($o[0] ?? 0);

                return;
            case 'Tz': $this->gs['th'] = self::num($o[0] ?? 100) / 100;

                return;
            case 'TL': $this->gs['tl'] = self::num($o[0] ?? 0);

                return;
            case 'Ts': $this->gs['ts'] = self::num($o[0] ?? 0);

                return;
            case 'Tr': $this->gs['tr'] = (int) self::num($o[0] ?? 0);

                return;
            case 'Td':
                $this->moveLine(self::num($o[0] ?? 0), self::num($o[1] ?? 0));

                return;
            case 'TD':
                $this->gs['tl'] = -self::num($o[1] ?? 0);
                $this->moveLine(self::num($o[0] ?? 0), self::num($o[1] ?? 0));

                return;
            case 'Tm':
                if (count($o) >= 6) {
                    $this->tm = $this->tlm = self::nums($o, 6);
                }

                return;
            case 'T*':
                $this->moveLine(0, -$this->gs['tl']);

                return;
            case 'Tj':
                $this->show($o[0] ?? null);

                return;
            case "'":
                $this->moveLine(0, -$this->gs['tl']);
                $this->show($o[0] ?? null);

                return;
            case '"':
                $this->gs['tw'] = self::num($o[0] ?? 0);
                $this->gs['tc'] = self::num($o[1] ?? 0);
                $this->moveLine(0, -$this->gs['tl']);
                $this->show($o[2] ?? null);

                return;
            case 'TJ':
                foreach (is_array($o[0] ?? null) ? $o[0] : [] as $item) {
                    if ($item instanceof PdfString) {
                        $this->show($item);
                    } elseif (is_int($item) || is_float($item)) {
                        $tx = -$item / 1000 * $this->gs['size'] * $this->gs['th'];
                        $this->tm = self::mul([1, 0, 0, 1, $tx, 0], $this->tm);
                    }
                }

                return;
            case 'g': $this->gs['fill'] = self::nums($o, 1);

                return;
            case 'G': $this->gs['stroke'] = self::nums($o, 1);

                return;
            case 'rg': $this->gs['fill'] = self::nums($o, 3);

                return;
            case 'RG': $this->gs['stroke'] = self::nums($o, 3);

                return;
            case 'k': $this->gs['fill'] = self::cmyk(self::nums($o, 4));

                return;
            case 'K': $this->gs['stroke'] = self::cmyk(self::nums($o, 4));

                return;
            case 'sc': case 'scn':
                $this->gs['fill'] = self::colour($o);

                return;
            case 'SC': case 'SCN':
                $this->gs['stroke'] = self::colour($o);

                return;
            case 'cs':
                $this->gs['fill'] = [0.0];

                return;
            case 'CS':
                $this->gs['stroke'] = [0.0];

                return;
            case 'Do':
                if (($o[0] ?? null) instanceof PdfName) {
                    $this->xobject($o[0]->value, $resources, $depth);
                }

                return;
            case 'BI':
                InlineImage::skip($p);

                return;
            case 're':
                if (count($o) >= 4) {
                    [$x, $y, $w, $h] = self::nums($o, 4);
                    foreach ([[$x, $y], [$x + $w, $y], [$x, $y + $h], [$x + $w, $y + $h]] as [$px, $py]) {
                        $this->pathPoint($px, $py);
                    }
                }

                return;
            case 'm': case 'l':
                $n = self::nums($o, 2);
                $this->pathPoint($n[0], $n[1]);

                return;
            case 'c': case 'v': case 'y':
                $n = self::nums($o, $op === 'c' ? 6 : 4);
                for ($i = 0; $i + 1 < count($n); $i += 2) {
                    $this->pathPoint($n[$i], $n[$i + 1]);
                }

                return;
            case 'f': case 'F': case 'f*': case 'B': case 'B*': case 'b': case 'b*':
                if ($this->path !== null) {
                    $this->regions[] = $this->path + ['white' => self::isWhite($this->gs['fill'])];
                }
                $this->path = null;

                return;
            case 'S': case 's': case 'n':
                $this->path = null;

                return;
        }
    }

    /** Extend the current path's device-space bounding box. */
    private function pathPoint(float $x, float $y): void
    {
        $c = $this->gs['ctm'];
        $dx = $x * $c[0] + $y * $c[2] + $c[4];
        $dy = $x * $c[1] + $y * $c[3] + $c[5];
        $this->path = $this->path === null
            ? ['x0' => $dx, 'y0' => $dy, 'x1' => $dx, 'y1' => $dy]
            : [
                'x0' => min($this->path['x0'], $dx), 'y0' => min($this->path['y0'], $dy),
                'x1' => max($this->path['x1'], $dx), 'y1' => max($this->path['y1'], $dy),
            ];
    }

    /**
     * Why a run would not be seen by a human reading the rendered page.
     *
     * - `invisible`: render mode 3 (neither fill nor stroke) or 7 (clip only)
     * - `white`: white or near-white paint, unless something non-white was
     *   painted under it first (a filled shape or an image). The page itself is
     *   assumed white, which is what every viewer shows.
     * - `tiny`: under one point tall on the page
     * - `off-page`: entirely outside the visible (crop) box
     *
     * @return list<string>
     */
    private function hiddenBecause(float $x0, float $y, float $x1, float $size): array
    {
        $flags = [];
        $tr = $this->gs['tr'];
        if ($tr === 3 || $tr === 7) {
            $flags[] = 'invisible';
        } else {
            $paint = in_array($tr, [1, 5], true) ? $this->gs['stroke'] : $this->gs['fill'];
            if (self::isWhite($paint) && ! $this->onPaintedGround(($x0 + $x1) / 2, $y)) {
                $flags[] = 'white';
            }
        }
        if ($size < 1.0) {
            $flags[] = 'tiny';
        }
        [$bx0, $by0, $bx1, $by1] = $this->cropBox;
        if ($y < $by0 - 1 || $y > $by1 + 1 || max($x0, $x1) < $bx0 - 1 || min($x0, $x1) > $bx1 + 1) {
            $flags[] = 'off-page';
        }

        return $flags;
    }

    private function onPaintedGround(float $x, float $y): bool
    {
        foreach ($this->regions as $r) {
            if (! $r['white'] && $x >= $r['x0'] && $x <= $r['x1'] && $y >= $r['y0'] && $y <= $r['y1']) {
                return true;
            }
        }

        return false;
    }

    /** @param list<float> $c */
    private static function isWhite(array $c): bool
    {
        if ($c === []) {
            return false;
        }
        foreach ($c as $v) {
            if ($v < 0.95) {
                return false;
            }
        }

        return true;
    }

    private function moveLine(float $tx, float $ty): void
    {
        $this->tlm = self::mul([1, 0, 0, 1, $tx, $ty], $this->tlm);
        $this->tm = $this->tlm;
    }

    private function show(mixed $s): void
    {
        $font = $this->gs['font'];
        if (! $s instanceof PdfString || ! $font instanceof Font) {
            return;
        }
        $size = $this->gs['size'];
        $th = $this->gs['th'];

        $start = $this->renderMatrix();
        $text = '';
        // Streamed, and every code ticks the budget: one show string can hold
        // millions of codes, and only the time and memory budgets bound it.
        // Each code is weighed by what its ToUnicode lookup may cost.
        $cost = $font->codeCost();
        foreach ($font->decode($s->bytes) as [$u, $w, $isSpace]) {
            $this->budget->tick($cost);
            $text .= $u;
            $tx = ($w / 1000 * $size + $this->gs['tc'] + ($isSpace ? $this->gs['tw'] : 0)) * $th;
            $this->tm = self::mul([1, 0, 0, 1, $tx, 0], $this->tm);
        }
        $end = $this->renderMatrix();
        $height = hypot($start[2], $start[3]);

        $this->runs[] = [
            'text' => $text,
            'x0' => $start[4],
            'y' => $start[5],
            'x1' => $end[4],
            'size' => $height,
            'flags' => $this->hiddenBecause($start[4], $start[5], $end[4], $height),
        ];
    }

    /** @return list<float> */
    private function renderMatrix(): array
    {
        $g = $this->gs;

        return self::mul(
            [$g['size'] * $g['th'], 0, 0, $g['size'], 0, $g['ts']],
            self::mul($this->tm, $g['ctm']),
        );
    }

    private function font(string $name, ?PdfDict $resources): ?Font
    {
        $fonts = $resources !== null ? $this->doc->resolve($resources->get('Font')) : null;
        if (! $fonts instanceof PdfDict) {
            return null;
        }
        $ref = $fonts->get($name);
        $dict = $this->doc->resolve($ref);
        if (! $dict instanceof PdfDict) {
            return null;
        }
        $key = $ref instanceof PdfRef ? $ref->key() : 'd'.spl_object_id($dict);

        return $this->fonts[$key] ??= Font::fromDict($dict, $this->doc);
    }

    private function xobject(string $name, ?PdfDict $resources, int $depth): void
    {
        $xobjects = $resources !== null ? $this->doc->resolve($resources->get('XObject')) : null;
        if (! $xobjects instanceof PdfDict) {
            return;
        }
        $ref = $xobjects->get($name);
        $x = $this->doc->resolve($ref);
        if ($x instanceof PdfStream && $x->dict->name('Subtype') === 'Image') {
            // An image covers the unit square; text over it is not "on white".
            $saved = $this->path;
            $this->path = null;
            foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$ux, $uy]) {
                $this->pathPoint($ux, $uy);
            }
            $this->regions[] = $this->path + ['white' => false];
            $this->path = $saved;

            return;
        }
        if (! $x instanceof PdfStream || $x->dict->name('Subtype') !== 'Form') {
            return;
        }

        $key = $ref instanceof PdfRef ? $ref->key() : 'd'.spl_object_id($x);
        if (in_array($key, $this->forms, true)) {
            $this->doc->warn('form XObject '.$key.' draws itself; the inner draw was skipped');

            return;
        }
        if (count($this->forms) >= $this->budget->limits->maxFormDepth) {
            $this->doc->warn('form XObjects nest deeper than '.$this->budget->limits->maxFormDepth.'; the rest were skipped');

            return;
        }

        $content = $this->doc->streamData($x);
        if ($content === null) {
            return;
        }
        $formResources = $this->doc->resolve($x->dict->get('Resources'));
        $saved = [$this->gs, $this->stack, $this->tm, $this->tlm];
        $matrix = $this->doc->resolve($x->dict->get('Matrix'));
        if (is_array($matrix) && count($matrix) >= 6) {
            $this->gs['ctm'] = self::mul(self::nums(array_map(fn ($v) => $this->doc->resolve($v), $matrix), 6), $this->gs['ctm']);
        }
        $this->stack = [];
        $this->forms[] = $key;
        try {
            $this->interpret($content, $formResources instanceof PdfDict ? $formResources : $resources, $depth + 1);
        } finally {
            array_pop($this->forms);
            [$this->gs, $this->stack, $this->tm, $this->tlm] = $saved;
        }
    }

    /** @param list<mixed> $o @return list<float> */
    private static function colour(array $o): array
    {
        $n = array_values(array_filter($o, fn ($v) => is_int($v) || is_float($v)));

        return match (count($n)) {
            1, 3 => array_map('floatval', $n),
            4 => self::cmyk(array_map('floatval', $n)),
            default => [0.0],
        };
    }

    /** @param list<float> $c @return list<float> */
    private static function cmyk(array $c): array
    {
        [$cy, $m, $y, $k] = array_pad($c, 4, 0.0);

        return [(1 - $cy) * (1 - $k), (1 - $m) * (1 - $k), (1 - $y) * (1 - $k)];
    }

    private static function num(mixed $v): float
    {
        return is_int($v) || is_float($v) ? (float) $v : 0.0;
    }

    /** @param list<mixed> $o @return list<float> */
    private static function nums(array $o, int $n): array
    {
        $o = array_slice(array_values($o), -$n);

        return array_map(fn ($v) => self::num($v), array_pad($o, $n, 0));
    }

    /**
     * a × b for PDF's 3x3 affine matrices written [a b c d e f].
     *
     * @param  list<float|int>  $a
     * @param  list<float|int>  $b
     * @return list<float>
     */
    public static function mul(array $a, array $b): array
    {
        return [
            $a[0] * $b[0] + $a[1] * $b[2],
            $a[0] * $b[1] + $a[1] * $b[3],
            $a[2] * $b[0] + $a[3] * $b[2],
            $a[2] * $b[1] + $a[3] * $b[3],
            $a[4] * $b[0] + $a[5] * $b[2] + $b[4],
            $a[4] * $b[1] + $a[5] * $b[3] + $b[5],
        ];
    }
}
