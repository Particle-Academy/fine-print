<?php

declare(strict_types=1);

namespace FinePrint\Render;

use FinePrint\Budget;

/** Immutable byte coverage, shared by saved graphics states without deep copies. */
final class CoverageMask
{
    private function __construct(private readonly array $rows) {}

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
        return ord($this->rows[$y][$x]) / 255;
    }

    public function row(int $y): string
    {
        return $this->rows[$y];
    }
}
