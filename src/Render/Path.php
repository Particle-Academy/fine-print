<?php

declare(strict_types=1);

namespace FinePrint\Render;

/**
 * The current path, in USER space (ISO 32000-1 8.5.2).
 *
 * Each subpath keeps its points flat (x0, y0, x1, y1, ...) starting at its
 * `m`, with one letter per segment: `l` takes one point, `c` three. Flat
 * float lists, because a path is the one structure a file can make huge
 * cheaply, and nested [x, y] arrays cost several times the memory.
 */
final class Path
{
    /** @var list<array{xy: list<float>, seg: string, closed: bool}> */
    private array $subpaths = [];

    /** Index of the open subpath, or null when there is no current point. */
    private ?int $open = null;

    /** The start of the last closed subpath: where a segment after `h` begins. */
    private ?array $restart = null;

    public function moveTo(float $x, float $y): void
    {
        // A lone `m` followed by another `m` is dropped, as viewers do.
        if ($this->open !== null && $this->subpaths[$this->open]['seg'] === '' && ! $this->subpaths[$this->open]['closed']) {
            array_pop($this->subpaths);
        }
        $this->subpaths[] = ['xy' => [$x, $y], 'seg' => '', 'closed' => false];
        $this->open = count($this->subpaths) - 1;
        $this->restart = null;
    }

    public function lineTo(float $x, float $y): void
    {
        if (! $this->ensureOpen()) {
            return;
        }
        $sp = &$this->subpaths[$this->open];
        $sp['xy'][] = $x;
        $sp['xy'][] = $y;
        $sp['seg'] .= 'l';
    }

    public function curveTo(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): void
    {
        if (! $this->ensureOpen()) {
            return;
        }
        $sp = &$this->subpaths[$this->open];
        array_push($sp['xy'], $x1, $y1, $x2, $y2, $x3, $y3);
        $sp['seg'] .= 'c';
    }

    public function close(): void
    {
        if ($this->open === null) {
            return;
        }
        $sp = &$this->subpaths[$this->open];
        $sp['closed'] = true;
        $this->restart = [$sp['xy'][0], $sp['xy'][1]];
        $this->open = null;
    }

    public function rect(float $x, float $y, float $w, float $h): void
    {
        $this->moveTo($x, $y);
        $this->lineTo($x + $w, $y);
        $this->lineTo($x + $w, $y + $h);
        $this->lineTo($x, $y + $h);
        $this->close();
    }

    /** @return ?array{0: float, 1: float} */
    public function current(): ?array
    {
        if ($this->open !== null) {
            $xy = $this->subpaths[$this->open]['xy'];
            $n = count($xy);

            return [$xy[$n - 2], $xy[$n - 1]];
        }

        return $this->restart;
    }

    public function isEmpty(): bool
    {
        foreach ($this->subpaths as $sp) {
            if ($sp['seg'] !== '' || $sp['closed']) {
                return false;
            }
        }

        return true;
    }

    /** A segment straight after `h` starts a new subpath at the closed one's start. */
    private function ensureOpen(): bool
    {
        if ($this->open !== null) {
            return true;
        }
        if ($this->restart === null) {
            return false;
        }
        $this->subpaths[] = ['xy' => $this->restart, 'seg' => '', 'closed' => false];
        $this->open = count($this->subpaths) - 1;

        return true;
    }

    /**
     * Every subpath with a segment, transformed to device space and flattened,
     * as flat closed polygons — what a fill needs.
     *
     * @param  list<float>  $m
     * @return list<list<float>>
     */
    public function devicePolygons(array $m, float $tol): array
    {
        $out = [];
        foreach ($this->subpaths as $sp) {
            if ($sp['seg'] === '') {
                continue;
            }
            $dev = [];
            $xy = $sp['xy'];
            for ($i = 0, $n = count($xy); $i < $n; $i += 2) {
                $dev[] = $xy[$i] * $m[0] + $xy[$i + 1] * $m[2] + $m[4];
                $dev[] = $xy[$i] * $m[1] + $xy[$i + 1] * $m[3] + $m[5];
            }
            $out[] = self::flatten($dev, $sp['seg'], $tol);
        }

        return $out;
    }

    /**
     * Every paintable subpath, flattened in user space, as [x, y] polylines —
     * what the stroker needs. A lone `m` is not paintable; `m h` is (a dot,
     * with round or square caps).
     *
     * @return list<array{points: list<array{0: float, 1: float}>, closed: bool}>
     */
    public function userPolylines(float $tol): array
    {
        $out = [];
        foreach ($this->subpaths as $sp) {
            if ($sp['seg'] === '' && ! $sp['closed']) {
                continue;
            }
            $flat = self::flatten($sp['xy'], $sp['seg'], $tol);
            $pts = [];
            for ($i = 0, $n = count($flat); $i < $n; $i += 2) {
                $pts[] = [$flat[$i], $flat[$i + 1]];
            }
            $out[] = ['points' => $pts, 'closed' => $sp['closed']];
        }

        return $out;
    }

    /**
     * @param  list<float>  $xy
     * @return list<float>
     */
    private static function flatten(array $xy, string $seg, float $tol): array
    {
        if (! str_contains($seg, 'c')) {
            return $xy;
        }
        $out = [$xy[0], $xy[1]];
        $i = 2;
        for ($s = 0, $n = strlen($seg); $s < $n; $s++) {
            if ($seg[$s] === 'l') {
                $out[] = $xy[$i];
                $out[] = $xy[$i + 1];
                $i += 2;

                continue;
            }
            array_push($out, ...Bezier::flattenFlat(
                $xy[$i - 2], $xy[$i - 1], $xy[$i], $xy[$i + 1], $xy[$i + 2], $xy[$i + 3], $xy[$i + 4], $xy[$i + 5], $tol,
            ));
            $i += 6;
        }

        return $out;
    }
}
