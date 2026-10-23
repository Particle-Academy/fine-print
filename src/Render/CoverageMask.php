<?php

declare(strict_types=1);

namespace FinePrint\Render;

use FinePrint\Budget;

/** Immutable byte clips or sparse float soft masks, shared by saved states. */
final class CoverageMask
{
    private function __construct(private readonly array $rows, private readonly ?Budget $pixelBudget = null, private readonly int $pixels = 0, private readonly bool $floatCoverage = false, private readonly array $region = [], private readonly float $outside = 0.0) {}

    public function __destruct()
    {
        $this->pixelBudget?->releasePixels($this->pixels);
    }

    /** Generated device-space soft mask, bounded before any row is allocated. */
    public static function generate(int $width, int $height, Budget $budget, callable $value, ?array $region = null, float $outside = 0.0): self
    {
        [$left, $top, $right, $bottom] = $region ?? [0, 0, $width, $height];
        $width = max(0, $right - $left);
        $height = max(0, $bottom - $top);
        $pixels = $width * $height;
        $budget->reservePixels($pixels);
        try {
            $budget->allocation((float) $pixels * 4 + (float) $height * 96 + $width * 4 + 4096);
            $rows = [];
            for ($y = $top; $y < $bottom; $y++) {
                $row = '';
                for ($x = $left; $x < $right; $x++) {
                    $budget->tick();
                    $row .= pack('f', max(0.0, min(1.0, $value($x, $y))));
                }
                $rows[] = $row;
            }

            return new self($rows, $budget, $pixels, true, [$left, $top, $right, $bottom], $outside);
        } catch (\Throwable $e) {
            $budget->releasePixels($pixels);
            throw $e;
        }
    }

    public static function fromRasterizer(Rasterizer $r, int $width, int $height, bool $evenOdd, Budget $budget): self
    {
        $budget->allocation((float) $width * $height + (float) $height * 96 + $width);
        $rows = array_fill(0, $height, str_repeat("\0", $width));
        foreach ($r->spans($evenOdd) as [$y, $x, $len, $a]) {
            $budget->tick($len);
            $rows[$y] = substr_replace($rows[$y], str_repeat(chr((int) round($a * 255)), $len), $x, $len);
        }
        $budget->check();

        return new self($rows);
    }

    public function intersect(?self $previous, Budget $budget): self
    {
        if ($previous === null) {
            return $this;
        }
        $height = count($this->rows);
        $width = strlen($this->rows[0] ?? '');
        $budget->allocation((float) $width * $height + (float) $height * 96);
        $rows = $this->rows;
        foreach ($rows as $y => $row) {
            $budget->tick();
            $prior = $previous->rows[$y];
            if ($row === $prior) {
                continue;
            }
            $len = strlen($row);
            if (strspn($row, "\xFF") === $len) {
                $rows[$y] = $prior;

                continue;
            }
            if (strspn($prior, "\xFF") === $len || strspn($row, "\0") === $len) {
                continue;
            }
            for ($x = 0; $x < $len; $x++) {
                $budget->tick();
                $row[$x] = chr(intdiv(ord($row[$x]) * ord($prior[$x]) + 127, 255));
            }
            $rows[$y] = $row;
        }
        $budget->check();

        return new self($rows);
    }

    public function at(int $x, int $y): float
    {
        if ($this->floatCoverage) {
            [$left, $top, $right, $bottom] = $this->region;
            if ($x < $left || $y < $top || $x >= $right || $y >= $bottom) {
                return $this->outside;
            }

            return unpack('f', $this->rows[$y - $top], ($x - $left) * 4)[1];
        }

        return ord($this->rows[$y][$x]) / 255;
    }

    public function row(int $y): string
    {
        return $this->rows[$y];
    }

    /** Only a page (whose shape is not used by a knockout parent) may cull this way. */
    public function zeroOutsideBounds(): ?array
    {
        return $this->floatCoverage && $this->outside === 0.0 ? $this->region : null;
    }
}
