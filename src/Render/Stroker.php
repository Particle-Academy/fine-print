<?php

declare(strict_types=1);

namespace FinePrint\Render;

use FinePrint\Budget;

/**
 * Turns stroked polylines into polygons that are filled nonzero.
 *
 * Works in USER space, so a non-uniform CTM gives the elliptical pen PDF
 * specifies: the caller flattens curves, strokes here, then transforms the
 * polygons to device space. Each segment becomes a quadrilateral, each join
 * a wedge on the outer side (miter, falling back to bevel past the limit, or
 * a disc for round), each cap a rectangle or a disc. Overlap is harmless
 * because every polygon is emitted with the SAME orientation, so nonzero
 * filling unions them instead of cancelling.
 *
 * Dashing happens first, along the polyline, restarting at each subpath
 * (ISO 32000-1 8.4.3.6). A pattern so dense that it would produce more than
 * MAX_DASHES dashes is drawn solid: a hostile `[1e-6 1e-6] 0 d` must not be
 * an unbounded loop, and at that density the eye cannot tell.
 */
final class Stroker
{
    public const MAX_DASHES = 50_000;

    public const BUTT = 0;

    public const ROUND = 1;

    public const SQUARE = 2;

    public const MITER = 0;

    public const BEVEL = 2;

    /**
     * @param  list<array{points: list<array{0: float, 1: float}>, closed: bool}>  $subpaths  user space
     * @param  list<float>  $dash
     * @param  float  $tolerance  arc flattening tolerance, user space
     * @param  ?callable(list<array{0: float, 1: float}>): void  $emit  receives each polygon; without it they are returned
     * @return list<list<array{0: float, 1: float}>>
     */
    public static function stroke(
        array $subpaths,
        float $width,
        int $cap,
        int $join,
        float $miterLimit,
        array $dash = [],
        float $phase = 0.0,
        float $tolerance = 0.1,
        ?callable $emit = null,
        ?Budget $budget = null,
    ): array {
        $out = [];
        $emit ??= function (array $poly) use (&$out): void {
            $out[] = $poly;
        };
        if (! ($width > 0.0) || ! is_finite($width)) {
            return [];
        }
        $hw = $width / 2;
        $tolerance = max($tolerance, $hw * 1e-6, 1e-9);
        $send = function (array $poly) use ($emit): void {
            if (self::signedArea($poly) < 0) {
                $poly = array_reverse($poly);
            }
            $emit($poly);
        };

        $pattern = self::pattern($dash);
        if ($pattern !== null && self::dashCount($subpaths, $pattern) > self::MAX_DASHES) {
            $pattern = null;
        }

        foreach ($subpaths as $sp) {
            $pts = self::dedupe($sp['points']);
            if ($pts === []) {
                continue;
            }
            if ($pattern === null) {
                self::polyline($pts, $sp['closed'], $hw, $cap, $join, $miterLimit, $tolerance, $send, $budget);

                continue;
            }
            if ($sp['closed'] && count($pts) > 1) {
                $pts[] = $pts[0];
            }
            foreach (self::dashes($pts, $pattern, $phase) as $piece) {
                self::polyline($piece, false, $hw, $cap, $join, $miterLimit, $tolerance, $send, $budget);
            }
        }

        return $out;
    }

    /**
     * A usable dash pattern, or null for solid: empty, all zero, a negative
     * or non-finite entry (invalid, ignored as viewers do).
     *
     * @param  list<float>  $dash
     * @return ?list<float>
     */
    private static function pattern(array $dash): ?array
    {
        if ($dash === []) {
            return null;
        }
        $sum = 0.0;
        foreach ($dash as $d) {
            if (! is_finite($d) || $d < 0) {
                return null;
            }
            $sum += $d;
        }
        if ($sum <= 0.0) {
            return null;
        }
        $dash = array_values($dash);

        return count($dash) % 2 === 1 ? [...$dash, ...$dash] : $dash;
    }

    /** @param list<array{points: list<array{0: float, 1: float}>, closed: bool}> $subpaths */
    private static function dashCount(array $subpaths, array $pattern): float
    {
        $len = 0.0;
        foreach ($subpaths as $sp) {
            $p = $sp['points'];
            $n = count($p);
            for ($i = 1; $i < $n; $i++) {
                $len += hypot($p[$i][0] - $p[$i - 1][0], $p[$i][1] - $p[$i - 1][1]);
            }
            if ($sp['closed'] && $n > 1) {
                $len += hypot($p[0][0] - $p[$n - 1][0], $p[0][1] - $p[$n - 1][1]);
            }
        }

        return $len / array_sum($pattern) * (count($pattern) / 2) + count($subpaths);
    }

    /**
     * Cut a polyline into its "on" pieces.
     *
     * @param  list<array{0: float, 1: float}>  $pts
     * @param  list<float>  $pattern
     * @return list<list<array{0: float, 1: float}>>
     */
    private static function dashes(array $pts, array $pattern, float $phase): array
    {
        $period = array_sum($pattern);
        $phase = fmod($phase, $period);
        if ($phase < 0) {
            $phase += $period;
        }
        $i = 0;
        $on = true;
        $left = $pattern[0];
        while ($phase > 0) {
            if ($phase >= $left) {
                $phase -= $left;
                $i = ($i + 1) % count($pattern);
                $on = ! $on;
                $left = $pattern[$i];
            } else {
                $left -= $phase;
                $phase = 0;
            }
        }

        $pieces = [];
        $cur = $on ? [$pts[0]] : null;
        $n = count($pts);
        for ($k = 1; $k < $n; $k++) {
            [$ax, $ay] = $pts[$k - 1];
            [$bx, $by] = $pts[$k];
            $seg = hypot($bx - $ax, $by - $ay);
            $pos = 0.0;
            // `>=` (with a hair of slack for rounding): a dash boundary that
            // lands exactly on the end still toggles, so a dot due there is drawn.
            $eps = 1e-9 * max(1.0, $seg);
            while ($seg - $pos >= $left - $eps) {
                $pos += $left;
                $t = $pos / $seg;
                $p = [$ax + ($bx - $ax) * $t, $ay + ($by - $ay) * $t];
                if ($on) {
                    $cur[] = $p;
                    $pieces[] = $cur;
                    $cur = null;
                } else {
                    $cur = [$p];
                }
                $on = ! $on;
                $i = ($i + 1) % count($pattern);
                $left = $pattern[$i];
            }
            $left -= $seg - $pos;
            if ($on) {
                $cur[] = [$bx, $by];
            }
        }
        if ($on && $cur !== null) {
            $pieces[] = $cur;
        }

        return array_map(fn (array $p) => self::dedupe($p), $pieces);
    }

    /**
     * @param  list<array{0: float, 1: float}>  $pts
     * @return list<array{0: float, 1: float}>
     */
    private static function dedupe(array $pts): array
    {
        $out = [];
        foreach ($pts as $p) {
            if (! is_finite($p[0]) || ! is_finite($p[1])) {
                continue;
            }
            $last = end($out);
            if ($last === false || abs($last[0] - $p[0]) > 1e-12 || abs($last[1] - $p[1]) > 1e-12) {
                $out[] = [(float) $p[0], (float) $p[1]];
            }
        }

        return $out;
    }

    /** @param list<array{0: float, 1: float}> $pts */
    private static function polyline(array $pts, bool $closed, float $hw, int $cap, int $join, float $miterLimit, float $tol, callable $send, ?Budget $budget): void
    {
        $n = count($pts);
        if ($closed && $n > 1 && abs($pts[0][0] - $pts[$n - 1][0]) <= 1e-12 && abs($pts[0][1] - $pts[$n - 1][1]) <= 1e-12) {
            array_pop($pts);
            $n--;
        }
        if ($n === 1) {
            // A degenerate subpath is painted only with round or square caps.
            [$x, $y] = $pts[0];
            if ($cap === self::ROUND) {
                $send(self::disc($x, $y, $hw, $tol));
            } elseif ($cap === self::SQUARE) {
                $send([[$x - $hw, $y - $hw], [$x + $hw, $y - $hw], [$x + $hw, $y + $hw], [$x - $hw, $y + $hw]]);
            }

            return;
        }

        $segs = $closed ? $n : $n - 1;
        $dirs = [];
        for ($i = 0; $i < $segs; $i++) {
            [$ax, $ay] = $pts[$i];
            [$bx, $by] = $pts[($i + 1) % $n];
            $len = hypot($bx - $ax, $by - $ay);
            $dx = ($bx - $ax) / $len;
            $dy = ($by - $ay) / $len;
            $dirs[$i] = [$dx, $dy];
            $nx = -$dy * $hw;
            $ny = $dx * $hw;
            $send([[$ax + $nx, $ay + $ny], [$bx + $nx, $by + $ny], [$bx - $nx, $by - $ny], [$ax - $nx, $ay - $ny]]);
            $budget?->tick();
        }

        // Joins: every interior vertex, and every vertex of a closed path.
        $from = $closed ? 0 : 1;
        $to = $closed ? $n : $n - 1;
        for ($v = $from; $v < $to; $v++) {
            $d0 = $dirs[($v - 1 + $segs) % $segs];
            $d1 = $dirs[$v % $segs];
            self::join($pts[$v], $d0, $d1, $hw, $join, $miterLimit, $tol, $send);
        }

        if (! $closed) {
            self::cap($pts[0], [-$dirs[0][0], -$dirs[0][1]], $hw, $cap, $tol, $send);
            self::cap($pts[$n - 1], $dirs[$segs - 1], $hw, $cap, $tol, $send);
        }
    }

    /**
     * @param  array{0: float, 1: float}  $p
     * @param  array{0: float, 1: float}  $d0  incoming direction
     * @param  array{0: float, 1: float}  $d1  outgoing direction
     */
    private static function join(array $p, array $d0, array $d1, float $hw, int $join, float $miterLimit, float $tol, callable $send): void
    {
        $cross = $d0[0] * $d1[1] - $d0[1] * $d1[0];
        $dot = $d0[0] * $d1[0] + $d0[1] * $d1[1];
        if (abs($cross) < 1e-12 && $dot > 0) {
            return;
        }
        if ($join === self::ROUND) {
            $send(self::disc($p[0], $p[1], $hw, $tol));

            return;
        }
        // The outer side is opposite the turn.
        $s = $cross > 0 ? -1.0 : 1.0;
        $a = [$p[0] - $d0[1] * $hw * $s, $p[1] + $d0[0] * $hw * $s];
        $b = [$p[0] - $d1[1] * $hw * $s, $p[1] + $d1[0] * $hw * $s];
        if ($join === self::MITER && $dot > -1 + 1e-12) {
            $ratio = sqrt(2 / (1 + $dot));
            if ($ratio <= $miterLimit) {
                $k = $hw * $s / (1 + $dot);
                $tip = [$p[0] + (-$d0[1] - $d1[1]) * $k, $p[1] + ($d0[0] + $d1[0]) * $k];
                $send([$p, $a, $tip, $b]);

                return;
            }
        }
        $send([$p, $a, $b]);
    }

    /**
     * @param  array{0: float, 1: float}  $p
     * @param  array{0: float, 1: float}  $d  pointing away from the line
     */
    private static function cap(array $p, array $d, float $hw, int $cap, float $tol, callable $send): void
    {
        if ($cap === self::ROUND) {
            $send(self::disc($p[0], $p[1], $hw, $tol));
        } elseif ($cap === self::SQUARE) {
            $nx = -$d[1] * $hw;
            $ny = $d[0] * $hw;
            $ex = $d[0] * $hw;
            $ey = $d[1] * $hw;
            $send([
                [$p[0] + $nx, $p[1] + $ny],
                [$p[0] + $nx + $ex, $p[1] + $ny + $ey],
                [$p[0] - $nx + $ex, $p[1] - $ny + $ey],
                [$p[0] - $nx, $p[1] - $ny],
            ]);
        }
    }

    /** @return list<array{0: float, 1: float}> */
    private static function disc(float $cx, float $cy, float $r, float $tol): array
    {
        $step = $tol < $r ? 2 * acos(1 - $tol / $r) : M_PI / 4;
        $n = (int) ceil(2 * M_PI / max($step, 1e-9));
        $n = max(8, min(256, $n));
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $a = 2 * M_PI * $i / $n;
            $out[] = [$cx + $r * cos($a), $cy + $r * sin($a)];
        }

        return $out;
    }

    /** @param list<array{0: float, 1: float}> $poly */
    private static function signedArea(array $poly): float
    {
        $a = 0.0;
        $n = count($poly);
        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            $a += $poly[$i][0] * $poly[$j][1] - $poly[$j][0] * $poly[$i][1];
        }

        return $a / 2;
    }
}
