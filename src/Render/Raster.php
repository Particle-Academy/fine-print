<?php

declare(strict_types=1);

namespace FinePrint\Render;

use FinePrint\Budget;
use InvalidArgumentException;

/**
 * An opaque RGB canvas, one byte string per row (3 bytes per pixel), white
 * to start with — the page as every viewer shows it.
 *
 * Strings rather than arrays: an A4 page at scale 2 is 5.8 MB this way and
 * around 30 MB as a PHP int array, and a solid span is one substr_replace.
 */
final class Raster
{
    /** @var list<string> */
    private array $rows;

    public function __construct(public readonly int $width, public readonly int $height, ?Budget $budget = null, ?array $rows = null)
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('A raster must have positive dimensions.');
        }
        if ($rows !== null) {
            if (count($rows) !== $height) {
                throw new InvalidArgumentException('Raster row count does not match its height.');
            }
            foreach ($rows as $row) {
                $budget?->tick();
                if (! is_string($row) || strlen($row) !== $width * 3) {
                    throw new InvalidArgumentException('Invalid RGB raster row.');
                }
            }
            $this->rows = array_values($rows);
            $budget?->check();

            return;
        }
        // Worst-case painted rows include string/array overhead and a
        // temporary row. Pixel count alone misses a 1 x 4,000,000 page.
        $budget?->allocation((float) $width * $height * 3 + (float) $height * 96 + (float) $width * 3);
        $blank = str_repeat("\xFF", $width * 3);
        $this->rows = array_fill(0, $height, $blank);
    }

    /**
     * Paint `len` pixels of row y from x, in colour (r, g, b) at coverage a.
     * Assumes 0 <= x, x + len <= width, 0 <= y < height (the rasteriser's spans are).
     */
    public function span(int $y, int $x, int $len, int $r, int $g, int $b, float $a, ?CoverageMask $clip = null, ?Budget $budget = null): void
    {
        $a = max(0.0, min(1.0, $a));
        if ($a <= 0.0) {
            return;
        }
        if ($clip !== null) {
            $mask = $clip->row($y);
            if (strspn($mask, "\0", $x, $len) === $len) {
                return;
            }
            if (strspn($mask, "\xFF", $x, $len) !== $len) {
                for ($i = $x; $i < $x + $len; $i++) {
                    $budget?->tick();
                    $this->span($y, $i, 1, $r, $g, $b, $a * ord($mask[$i]) / 255);
                }

                return;
            }
        }
        $row = &$this->rows[$y];
        if ($a >= 0.998) {
            if ($len >= 16) {
                $row = substr_replace($row, str_repeat(chr($r).chr($g).chr($b), $len), $x * 3, $len * 3);

                return;
            }
            $cr = chr($r);
            $cg = chr($g);
            $cb = chr($b);
            for ($i = $x * 3, $end = ($x + $len) * 3; $i < $end; $i += 3) {
                $row[$i] = $cr;
                $row[$i + 1] = $cg;
                $row[$i + 2] = $cb;
            }

            return;
        }
        for ($i = $x * 3, $end = ($x + $len) * 3; $i < $end; $i += 3) {
            $o = ord($row[$i]);
            $row[$i] = chr((int) ($o + ($r - $o) * $a + 0.5));
            $o = ord($row[$i + 1]);
            $row[$i + 1] = chr((int) ($o + ($g - $o) * $a + 0.5));
            $o = ord($row[$i + 2]);
            $row[$i + 2] = chr((int) ($o + ($b - $o) * $a + 0.5));
        }
    }

    /** @return array{0: int, 1: int, 2: int} */
    public function pixel(int $x, int $y): array
    {
        $i = $x * 3;
        $row = $this->rows[$y];

        return [ord($row[$i]), ord($row[$i + 1]), ord($row[$i + 2])];
    }

    /** @return list<string> */
    public function rows(): array
    {
        return $this->rows;
    }
}
