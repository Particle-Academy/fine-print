<?php

declare(strict_types=1);

namespace FinePrint\Render;

use FinePrint\Budget;
use Generator;

/**
 * An anti-aliasing scanline rasteriser with EXACT area coverage.
 *
 * Edges are accumulated into sparse per-pixel cells, the approach of
 * FreeType's "gray" rasteriser and font-rs: for the part of an edge inside a
 * pixel, `cover` gets its signed height and `area` its signed height times
 * its mean x within the pixel. Sweeping a row left to right, a pixel's winding
 * is the cover of every cell to its left plus its own cover minus its own
 * area — the exact signed area of the shape inside it. Nonzero takes that
 * magnitude (capped at 1); even-odd folds it modulo 2.
 *
 * Coordinates are device pixels, y down. Anything left of the canvas is
 * projected onto its left edge (it still covers what is to its right);
 * anything right of it or above or below it contributes nothing. So the
 * work for one edge is bounded by the canvas, whatever its coordinates say.
 */
final class Rasterizer
{
    /** @var array<int, array<int, float>> row => x => cover */
    private array $cover = [];

    /** @var array<int, array<int, float>> row => x => area */
    private array $area = [];

    public function __construct(
        private readonly int $width,
        private readonly int $height,
        private readonly ?Budget $budget = null,
    ) {}

    /**
     * A closed polygon from [x, y] points.
     *
     * @param  list<array{0: float|int, 1: float|int}>  $points
     */
    public function addPolygon(array $points): void
    {
        $n = count($points);
        if ($n < 2) {
            return;
        }
        for ($i = 0; $i < $n; $i++) {
            $a = $points[$i];
            $b = $points[($i + 1) % $n];
            $this->addLine((float) $a[0], (float) $a[1], (float) $b[0], (float) $b[1]);
        }
    }

    /**
     * A closed polygon from flat coordinates x0, y0, x1, y1, ...
     *
     * @param  list<float>  $xy
     */
    public function addFlat(array $xy): void
    {
        $n = count($xy);
        if ($n < 4) {
            return;
        }
        for ($i = 0; $i < $n; $i += 2) {
            $j = ($i + 2) % $n;
            $this->addLine($xy[$i], $xy[$i + 1], $xy[$j], $xy[$j + 1]);
        }
    }

    public function addLine(float $x0, float $y0, float $x1, float $y1): void
    {
        if (! is_finite($x0) || ! is_finite($y0) || ! is_finite($x1) || ! is_finite($y1) || $y0 === $y1) {
            return;
        }
        $dir = 1.0;
        if ($y0 > $y1) {
            [$x0, $y0, $x1, $y1] = [$x1, $y1, $x0, $y0];
            $dir = -1.0;
        }
        if ($y1 <= 0.0 || $y0 >= $this->height) {
            return;
        }
        $dxdy = ($x1 - $x0) / ($y1 - $y0);
        $ya = max($y0, 0.0);
        $yb = min($y1, (float) $this->height);
        $first = (int) floor($ya);
        $last = (int) ceil($yb) - 1;
        for ($r = $first; $r <= $last; $r++) {
            $ry0 = $ya > $r ? $ya : (float) $r;
            $ry1 = $yb < $r + 1 ? $yb : (float) ($r + 1);
            if ($ry1 <= $ry0) {
                continue;
            }
            if ($this->height > 8192 && ! isset($this->cover[$r])) {
                $rows = count($this->cover);
                if ($rows >= 1024 && ($rows & ($rows - 1)) === 0) {
                    // Both sparse outer tables can grow before the next tick.
                    $this->budget?->allocation($rows * 256 + 1024 * 1024);
                }
            }
            $cells = $this->rowLine($r, $x0 + ($ry0 - $y0) * $dxdy, $ry0, $x0 + ($ry1 - $y0) * $dxdy, $ry1, $dir);
            $this->budget?->tick($cells);
        }
    }

    /** One edge piece inside row $r, from (xa, ya) to (xb, yb). */
    private function rowLine(int $r, float $xa, float $ya, float $xb, float $yb, float $dir): int
    {
        $w = (float) $this->width;
        if ($xa > $xb) {
            [$xa, $ya, $xb, $yb] = [$xb, $yb, $xa, $ya];
            $dir = -$dir;
        }
        // Now xa <= xb. Cut off what lies right of the canvas.
        if ($xa >= $w) {
            return 1;
        }
        if ($xb > $w) {
            $yb = $ya + ($yb - $ya) * ($w - $xa) / ($xb - $xa);
            $xb = $w;
        }
        // Project what lies left of the canvas onto its left edge.
        if ($xa < 0.0) {
            if ($xb <= 0.0) {
                $this->cell($r, 0, ($yb - $ya) * $dir, 0.0);

                return 1;
            }
            $ym = $ya + ($yb - $ya) * (0.0 - $xa) / ($xb - $xa);
            $this->cell($r, 0, ($ym - $ya) * $dir, 0.0);
            $xa = 0.0;
            $ya = $ym;
        }

        $cx0 = (int) floor($xa);
        $cx1 = (int) floor($xb);
        if ($cx1 > $cx0 && $xb === (float) $cx1) {
            $cx1--;
        }
        if ($cx1 >= $this->width) {
            $cx1 = $this->width - 1;
        }
        if ($cx0 >= $this->width) {
            return 1;
        }
        $cells = $cx1 - $cx0 + 1;
        if ($cells >= 512) {
            // A single shallow edge can allocate a huge sparse row before
            // returning to addLine. Preflight both hash tables and growth.
            $this->budget?->allocation((count($this->cover[$r] ?? []) + $cells) * 256 + 1024 * 1024);
        }
        if ($cx0 === $cx1) {
            $this->cell($r, $cx0, ($yb - $ya) * $dir, ($xa + $xb) / 2 - $cx0);

            return 1;
        }
        $slope = ($yb - $ya) / ($xb - $xa);
        $xn = (float) ($cx0 + 1);
        $yn = $ya + ($xn - $xa) * $slope;
        $this->cell($r, $cx0, ($yn - $ya) * $dir, ($xa + $xn) / 2 - $cx0);
        $step = $slope * $dir;
        for ($x = $cx0 + 1; $x < $cx1; $x++) {
            if (($x & 511) === 0) {
                $this->budget?->tick(512);
            }
            $this->cell($r, $x, $step, 0.5);
            $yn += $slope;
        }
        $this->cell($r, $cx1, ($yb - $yn) * $dir, ($cx1 + $xb) / 2 - $cx1);

        return $cells < 512 ? $cells : 1;
    }

    private function cell(int $r, int $x, float $dy, float $fx): void
    {
        if (isset($this->cover[$r][$x])) {
            $this->cover[$r][$x] += $dy;
            $this->area[$r][$x] += $dy * $fx;
        } else {
            $this->cover[$r][$x] = $dy;
            $this->area[$r][$x] = $dy * $fx;
        }
    }

    /**
     * Covered runs, row by row, top to bottom: [y, x, length, coverage 0..1].
     * Interior runs come out as one span each, so a solid shape costs its
     * edges, not its area.
     *
     * @return Generator<int, array{0: int, 1: int, 2: int, 3: float}>
     */
    public function spans(bool $evenOdd): Generator
    {
        $rows = array_keys($this->cover);
        sort($rows);
        $w = $this->width;
        foreach ($rows as $y) {
            $cover = $this->cover[$y];
            $area = $this->area[$y];
            ksort($cover);
            $acc = 0.0;
            $prev = -1;
            foreach ($cover as $x => $c) {
                if ($x > $prev + 1 && ($acc > 1e-9 || $acc < -1e-9)) {
                    $a = self::fold($acc, $evenOdd);
                    if ($a > 1e-9) {
                        yield [$y, $prev + 1, $x - $prev - 1, $a];
                    }
                }
                $a = self::fold($acc + $c - $area[$x], $evenOdd);
                if ($a > 1e-9) {
                    yield [$y, $x, 1, $a];
                }
                $acc += $c;
                $prev = $x;
            }
            if ($prev < $w - 1 && ($acc > 1e-9 || $acc < -1e-9)) {
                $a = self::fold($acc, $evenOdd);
                if ($a > 1e-9) {
                    yield [$y, $prev + 1, $w - 1 - $prev, $a];
                }
            }
            $this->budget?->tick();
        }
    }

    /** Winding to coverage. */
    private static function fold(float $v, bool $evenOdd): float
    {
        $v = abs($v);
        if (! $evenOdd) {
            return $v >= 1.0 - 1e-9 ? 1.0 : $v;
        }
        $v = fmod($v, 2.0);
        if ($v > 1.0) {
            $v = 2.0 - $v;
        }

        return $v >= 1.0 - 1e-9 ? 1.0 : $v;
    }
}
