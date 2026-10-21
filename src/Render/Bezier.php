<?php

declare(strict_types=1);

namespace FinePrint\Render;

/**
 * Cubic Bézier flattening.
 *
 * The segment count comes from Wang's formula: n = sqrt(3/4 * M / tol), where
 * M is the largest second difference of the control points, guarantees the
 * polyline stays within `tol` of the curve. It is capped, because a hostile
 * curve's control points can be 1e12 apart and the formula would happily
 * answer "a billion".
 */
final class Bezier
{
    public const MAX_SEGMENTS = 256;

    /**
     * Points along the curve from p0 to p3, EXCLUDING p0 and ending exactly on p3.
     *
     * @param  array{0: float|int, 1: float|int}  $p0
     * @param  array{0: float|int, 1: float|int}  $p1
     * @param  array{0: float|int, 1: float|int}  $p2
     * @param  array{0: float|int, 1: float|int}  $p3
     * @return list<array{0: float, 1: float}>
     */
    public static function flatten(array $p0, array $p1, array $p2, array $p3, float $tol): array
    {
        $flat = self::flattenFlat((float) $p0[0], (float) $p0[1], (float) $p1[0], (float) $p1[1], (float) $p2[0], (float) $p2[1], (float) $p3[0], (float) $p3[1], $tol);
        $out = [];
        for ($i = 0, $n = count($flat); $i < $n; $i += 2) {
            $out[] = [$flat[$i], $flat[$i + 1]];
        }

        return $out;
    }

    /**
     * As {@see flatten()}, as flat x, y pairs (the renderer's own form).
     *
     * @return list<float>
     */
    public static function flattenFlat(float $x0, float $y0, float $x1, float $y1, float $x2, float $y2, float $x3, float $y3, float $tol): array
    {
        $ddx = max(abs($x0 - 2 * $x1 + $x2), abs($x1 - 2 * $x2 + $x3));
        $ddy = max(abs($y0 - 2 * $y1 + $y2), abs($y1 - 2 * $y2 + $y3));
        $m = sqrt($ddx * $ddx + $ddy * $ddy);
        if (! is_finite($m) || $tol <= 0.0) {
            return [$x3, $y3];
        }
        $n = (int) ceil(sqrt(0.75 * $m / $tol));
        $n = max(1, min(self::MAX_SEGMENTS, $n));

        $out = [];
        for ($i = 1; $i < $n; $i++) {
            $t = $i / $n;
            $u = 1 - $t;
            $a = $u * $u * $u;
            $b = 3 * $u * $u * $t;
            $c = 3 * $u * $t * $t;
            $d = $t * $t * $t;
            $out[] = $a * $x0 + $b * $x1 + $c * $x2 + $d * $x3;
            $out[] = $a * $y0 + $b * $y1 + $c * $y2 + $d * $y3;
        }
        $out[] = $x3;
        $out[] = $y3;

        return $out;
    }
}
