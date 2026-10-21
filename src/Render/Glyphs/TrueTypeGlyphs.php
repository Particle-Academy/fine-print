<?php

declare(strict_types=1);

namespace FinePrint\Render\Glyphs;

use FinePrint\Exceptions\FontException;
use FinePrint\Writer\Fonts\TrueType;

/**
 * Glyph outlines from a TrueType `glyf` table, for the renderer.
 *
 * Reuses P2's {@see TrueType} reader for the tables (loca, hmtx, cmap); this
 * class only turns one glyph's bytes into contours. The font is hostile
 * input: point and contour counts, composite depth and component counts are
 * capped, every read is bounds-checked, and a glyph that breaks any of it is
 * a {@see FontException} (the caller skips that glyph and reports it).
 *
 * Hinting instructions are SKIPPED by their length and never executed, and
 * neither are `fpgm` or `prep`. Outlines are drawn unhinted.
 *
 * Placement follows FreeType (and, by its account, Acrobat): the glyph
 * origin is the phantom point pp1 = xMin - lsb, so the outline is moved by
 * lsb - xMin. In a consistent font that is zero.
 *
 * Quadratic B-spline contours become lines and EXACT cubics (a quadratic
 * raised a degree), with the implied on-curve point between two off-curve
 * points, and a contour starting off-curve begins as FreeType does: at the
 * last point if that is on-curve, else at the midpoint of the last and first.
 */
final class TrueTypeGlyphs
{
    public const MAX_CONTOURS = 1024;

    /** Points in one glyph, composite components multiplied out. */
    public const MAX_POINTS = 16384;

    public const MAX_COMPONENTS = 128;

    /** Composite nesting (a component of a component ...). */
    public const MAX_DEPTH = 8;

    /** Points kept in the outline cache, over all glyphs. */
    private const CACHE_POINTS = 1_000_000;

    /** @var array<int, Outline|string> gid => outline, or the reason it was refused */
    private array $cache = [];

    private int $cachedPoints = 0;

    public function __construct(public readonly TrueType $font) {}

    /**
     * @throws FontException for a glyph that is malformed or over a limit
     */
    public function outline(int $gid): Outline
    {
        $hit = $this->cache[$gid] ?? null;
        if ($hit instanceof Outline) {
            return $hit;
        }
        if (is_string($hit)) {
            throw new FontException($hit);
        }
        try {
            $o = $this->build($gid);
        } catch (FontException $e) {
            if (count($this->cache) < 65536) {
                $this->cache[$gid] = $e->getMessage();
            }

            throw $e;
        }
        if ($this->cachedPoints + $o->points <= self::CACHE_POINTS && count($this->cache) < 65536) {
            $this->cache[$gid] = $o;
            $this->cachedPoints += $o->points;
        }

        return $o;
    }

    private function build(int $gid): Outline
    {
        $data = $this->font->glyphData($gid);
        if (strlen($data) < 10) {
            return Outline::empty();
        }
        $total = 0;
        [$xs, $ys, $on, $ends] = $this->points($gid, 0, [], $total);
        if ($xs === []) {
            return Outline::empty();
        }
        $shift = (float) ($this->font->leftSideBearing($gid) - self::s16($data, 2));

        $subpaths = [];
        $x0 = $y0 = INF;
        $x1 = $y1 = -INF;
        $start = 0;
        foreach ($ends as $end) {
            $n = $end - $start + 1;
            if ($n >= 2) {
                $pts = [];
                for ($i = $start; $i <= $end; $i++) {
                    $x = $xs[$i] + $shift;
                    $y = (float) $ys[$i];
                    $pts[] = [$x, $y, $on[$i]];
                    $x0 = min($x0, $x);
                    $x1 = max($x1, $x);
                    $y0 = min($y0, $y);
                    $y1 = max($y1, $y);
                }
                $sp = self::contour($pts);
                if ($sp !== null) {
                    $subpaths[] = $sp;
                }
            }
            $start = $end + 1;
        }
        if ($subpaths === []) {
            return Outline::empty();
        }

        return new Outline($subpaths, [$x0, $y0, $x1, $y1], count($xs));
    }

    /**
     * One contour of [x, y, onCurve] points to a closed subpath.
     *
     * @param  list<array{0: float, 1: float, 2: bool}>  $pts
     * @return ?array{xy: list<float>, seg: string, closed: bool}
     */
    private static function contour(array $pts): ?array
    {
        $n = count($pts);
        $first = $pts[0];
        $last = $pts[$n - 1];
        if ($first[2]) {
            $start = $first;
            $seq = array_slice($pts, 1);
        } elseif ($last[2]) {
            $start = $last;
            $seq = array_slice($pts, 0, $n - 1);
        } else {
            $start = [($first[0] + $last[0]) / 2, ($first[1] + $last[1]) / 2, true];
            $seq = $pts;
        }
        $seq[] = $start;

        $xy = [$start[0], $start[1]];
        $seg = '';
        $cx = $start[0];
        $cy = $start[1];
        $ctrl = null;
        $count = count($seq);
        foreach ($seq as $k => $p) {
            if ($p[2]) {
                if ($ctrl === null) {
                    if ($k === $count - 1) {
                        break;   // the closing line is implied by `closed`
                    }
                    $xy[] = $p[0];
                    $xy[] = $p[1];
                    $seg .= 'l';
                } else {
                    self::quad($xy, $seg, $cx, $cy, $ctrl[0], $ctrl[1], $p[0], $p[1]);
                    $ctrl = null;
                }
                $cx = $p[0];
                $cy = $p[1];

                continue;
            }
            if ($ctrl !== null) {
                $mx = ($ctrl[0] + $p[0]) / 2;
                $my = ($ctrl[1] + $p[1]) / 2;
                self::quad($xy, $seg, $cx, $cy, $ctrl[0], $ctrl[1], $mx, $my);
                $cx = $mx;
                $cy = $my;
            }
            $ctrl = $p;
        }

        return $seg === '' ? null : ['xy' => $xy, 'seg' => $seg, 'closed' => true];
    }

    /** A quadratic from (x0, y0) through control (qx, qy) to (x, y), as an exact cubic. */
    private static function quad(array &$xy, string &$seg, float $x0, float $y0, float $qx, float $qy, float $x, float $y): void
    {
        array_push(
            $xy,
            $x0 + 2 / 3 * ($qx - $x0), $y0 + 2 / 3 * ($qy - $y0),
            $x + 2 / 3 * ($qx - $x), $y + 2 / 3 * ($qy - $y),
            $x, $y,
        );
        $seg .= 'c';
    }

    /**
     * A glyph's points, composites assembled: [xs, ys, onCurve, contour end indices].
     *
     * @param  list<int>  $stack  glyph ids being assembled above this one
     * @return array{0: list<float>, 1: list<float>, 2: list<bool>, 3: list<int>}
     */
    private function points(int $gid, int $depth, array $stack, int &$total): array
    {
        $d = $this->font->glyphData($gid);
        $len = strlen($d);
        if ($len < 10) {
            return [[], [], [], []];
        }
        $contours = self::s16($d, 0);
        if ($contours >= 0) {
            return $this->simple($d, $len, $contours, $total);
        }

        $xs = $ys = $on = $ends = [];
        $p = 10;
        $components = 0;
        do {
            if ($p + 4 > $len) {
                throw new FontException('A composite glyph is truncated.');
            }
            $flags = self::u16($d, $p);
            $child = self::u16($d, $p + 2);
            $p += 4;
            if (++$components > self::MAX_COMPONENTS) {
                throw new FontException('A composite glyph has more than '.self::MAX_COMPONENTS.' components.');
            }
            $words = ($flags & 0x0001) !== 0;
            $isXY = ($flags & 0x0002) !== 0;
            $need = $words ? 4 : 2;
            if ($p + $need > $len) {
                throw new FontException('A composite glyph is truncated.');
            }
            if ($words) {
                $a1 = $isXY ? self::s16($d, $p) : self::u16($d, $p);
                $a2 = $isXY ? self::s16($d, $p + 2) : self::u16($d, $p + 2);
            } else {
                $a1 = ord($d[$p]);
                $a2 = ord($d[$p + 1]);
                if ($isXY) {
                    $a1 = $a1 >= 128 ? $a1 - 256 : $a1;
                    $a2 = $a2 >= 128 ? $a2 - 256 : $a2;
                }
            }
            $p += $need;
            $a = 1.0;
            $b = 0.0;
            $c = 0.0;
            $dd = 1.0;
            if (($flags & 0x0008) !== 0) {
                self::need($p, 2, $len);
                $a = $dd = self::f2dot14($d, $p);
                $p += 2;
            } elseif (($flags & 0x0040) !== 0) {
                self::need($p, 4, $len);
                $a = self::f2dot14($d, $p);
                $dd = self::f2dot14($d, $p + 2);
                $p += 4;
            } elseif (($flags & 0x0080) !== 0) {
                self::need($p, 8, $len);
                $a = self::f2dot14($d, $p);
                $b = self::f2dot14($d, $p + 2);
                $c = self::f2dot14($d, $p + 4);
                $dd = self::f2dot14($d, $p + 6);
                $p += 8;
            }

            if ($child === $gid || in_array($child, $stack, true)) {
                throw new FontException('A composite glyph contains itself.');
            }
            if ($depth + 1 > self::MAX_DEPTH) {
                throw new FontException('Composite glyphs are nested too deep (over '.self::MAX_DEPTH.').');
            }
            [$cx, $cy, $con, $cends] = $this->points($child, $depth + 1, [...$stack, $gid], $total);
            $n = count($cx);
            $tx = [];
            $ty = [];
            for ($i = 0; $i < $n; $i++) {
                $tx[] = $a * $cx[$i] + $c * $cy[$i];
                $ty[] = $b * $cx[$i] + $dd * $cy[$i];
            }
            if ($isXY) {
                $ox = (float) $a1;
                $oy = (float) $a2;
                if (($flags & 0x0800) !== 0 && ($flags & 0x1000) === 0) {
                    [$ox, $oy] = [$a * $ox + $c * $oy, $b * $ox + $dd * $oy];
                }
            } else {
                if ($a1 >= count($xs) || $a2 >= $n) {
                    throw new FontException('A composite glyph matches a point that does not exist.');
                }
                $ox = $xs[$a1] - $tx[$a2];
                $oy = $ys[$a1] - $ty[$a2];
            }
            $base = count($xs);
            for ($i = 0; $i < $n; $i++) {
                $xs[] = $tx[$i] + $ox;
                $ys[] = $ty[$i] + $oy;
                $on[] = $con[$i];
            }
            foreach ($cends as $e) {
                $ends[] = $e + $base;
            }
        } while (($flags & 0x0020) !== 0);
        // Composite instructions (WE_HAVE_INSTRUCTIONS) follow; never read, never run.

        return [$xs, $ys, $on, $ends];
    }

    /** @return array{0: list<float>, 1: list<float>, 2: list<bool>, 3: list<int>} */
    private function simple(string $d, int $len, int $contours, int &$total): array
    {
        if ($contours === 0) {
            return [[], [], [], []];
        }
        if ($contours > self::MAX_CONTOURS) {
            throw new FontException('A glyph has '.$contours.' contours, over the '.self::MAX_CONTOURS.' limit.');
        }
        $p = 10;
        self::need($p, 2 * $contours + 2, $len);
        $ends = [];
        $prev = -1;
        for ($i = 0; $i < $contours; $i++) {
            $e = self::u16($d, $p + 2 * $i);
            if ($e <= $prev) {
                throw new FontException('A glyph\'s contour end points run backwards.');
            }
            $ends[] = $e;
            $prev = $e;
        }
        $n = $prev + 1;
        if ($total + $n > self::MAX_POINTS) {
            throw new FontException('A glyph has more than '.self::MAX_POINTS.' points.');
        }
        $total += $n;
        $p += 2 * $contours;
        $instructions = self::u16($d, $p);
        $p += 2 + $instructions;
        if ($p > $len) {
            throw new FontException('A glyph\'s hinting instructions run past its end.');
        }

        // Flags, with repeats; a repeat past the point count is cut there.
        $flags = [];
        while (count($flags) < $n) {
            self::need($p, 1, $len);
            $f = ord($d[$p++]);
            $flags[] = $f;
            if (($f & 0x08) !== 0) {
                self::need($p, 1, $len);
                $r = min(ord($d[$p++]), $n - count($flags));
                for ($k = 0; $k < $r; $k++) {
                    $flags[] = $f;
                }
            }
        }

        $xs = [];
        $v = 0;
        foreach ($flags as $f) {
            if (($f & 0x02) !== 0) {
                self::need($p, 1, $len);
                $dv = ord($d[$p++]);
                $v += ($f & 0x10) !== 0 ? $dv : -$dv;
            } elseif (($f & 0x10) === 0) {
                self::need($p, 2, $len);
                $v += self::s16($d, $p);
                $p += 2;
            }
            $xs[] = (float) $v;
        }
        $ys = [];
        $v = 0;
        foreach ($flags as $f) {
            if (($f & 0x04) !== 0) {
                self::need($p, 1, $len);
                $dv = ord($d[$p++]);
                $v += ($f & 0x20) !== 0 ? $dv : -$dv;
            } elseif (($f & 0x20) === 0) {
                self::need($p, 2, $len);
                $v += self::s16($d, $p);
                $p += 2;
            }
            $ys[] = (float) $v;
        }
        $on = array_map(fn (int $f) => ($f & 0x01) !== 0, $flags);

        return [$xs, $ys, $on, $ends];
    }

    private static function need(int $at, int $n, int $len): void
    {
        if ($at + $n > $len) {
            throw new FontException('A glyph is truncated.');
        }
    }

    private static function u16(string $d, int $at): int
    {
        return (ord($d[$at]) << 8) | ord($d[$at + 1]);
    }

    private static function s16(string $d, int $at): int
    {
        $v = (ord($d[$at]) << 8) | ord($d[$at + 1]);

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private static function f2dot14(string $d, int $at): float
    {
        return self::s16($d, $at) / 16384;
    }
}
