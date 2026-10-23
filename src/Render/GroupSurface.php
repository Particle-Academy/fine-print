<?php

declare(strict_types=1);

namespace FinePrint\Render;

use FinePrint\Budget;

/**
 * ISO 32000-1 11.4: associated group colour, alpha and shape, excluding the
 * initial backdrop. Packed floats avoid unstable backdrop removal at low alpha.
 * The backdrop is borrowed read-only while this group is being rendered.
 */
final class GroupSurface
{
    private array $rows;

    private readonly int $stride;

    private int $left = PHP_INT_MAX;

    private int $top = PHP_INT_MAX;

    private int $right = 0;

    private int $bottom = 0;

    private readonly int $offsetX;

    private readonly int $offsetY;

    private readonly int $bufferWidth;

    private readonly int $bufferHeight;

    private string $paintedRows;

    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly ColourSpace $space,
        public readonly ?Raster $backdrop,
        public readonly bool $knockout,
        private readonly Budget $budget,
        public readonly ?array $constantBackdrop = null,
        ?array $region = null,
    ) {
        $this->stride = ($space->components + 2) * 4;
        [$this->offsetX, $this->offsetY, $right, $bottom] = $region ?? [0, 0, $width, $height];
        $this->bufferWidth = max(0, $right - $this->offsetX);
        $this->bufferHeight = max(0, $bottom - $this->offsetY);
        $pixels = $this->bufferWidth * $this->bufferHeight;
        $budget->reservePixels($pixels);
        try {
            $budget->allocation((float) $pixels * $this->stride + (float) $this->bufferHeight * 97 + $this->bufferWidth * $this->stride + 4096);
            $this->rows = array_fill(0, $this->bufferHeight, str_repeat("\0", $this->bufferWidth * $this->stride));
            $this->paintedRows = str_repeat("\0", $this->bufferHeight);
        } catch (\Throwable $e) {
            $budget->releasePixels($pixels);
            throw $e;
        }
    }

    public function __destruct()
    {
        $this->budget->releasePixels($this->bufferWidth * $this->bufferHeight);
    }

    /** @return array{0: array, 1: float} straight colour and alpha of initial backdrop */
    public function initial(int $x, int $y): array
    {
        return $this->constantBackdrop !== null ? [$this->constantBackdrop, 1.0]
            : ($this->backdrop?->sample($x, $y, $this->space) ?? [array_fill(0, $this->space->components, 0.0), 0.0]);
    }

    /** Group colour with its backdrop factored out, group alpha and group shape. */
    public function source(int $x, int $y): array
    {
        $x -= $this->offsetX;
        $y -= $this->offsetY;
        if ($x < 0 || $y < 0 || $x >= $this->bufferWidth || $y >= $this->bufferHeight) {
            return [array_fill(0, $this->space->components, 0.0), 0.0, 0.0];
        }
        $v = array_values(unpack('f'.($this->space->components + 2), $this->rows[$y], $x * $this->stride));
        $a = $v[$this->space->components];
        $shape = $v[$this->space->components + 1];
        $c = [];
        for ($i = 0; $i < $this->space->components; $i++) {
            $c[] = $a > 0 ? max(0.0, min(1.0, $v[$i] / $a)) : 0.0;
        }

        return [$c, $a, $shape];
    }

    public function sample(int $x, int $y): array
    {
        [$c, $a] = $this->source($x, $y);
        [$b, $ba] = $this->initial($x, $y);
        $out = $a + $ba * (1 - $a);
        foreach ($c as $i => &$v) {
            $v = $out > 0 ? ($a * $v + (1 - $a) * $ba * $b[$i]) / $out : 0.0;
        }

        return [$c, $out];
    }

    public function paint(int $x, int $y, array $source, float $alpha, float $shape, string $mode): void
    {
        if ($x < $this->offsetX || $y < $this->offsetY || $x >= $this->offsetX + $this->bufferWidth || $y >= $this->offsetY + $this->bufferHeight) {
            return;
        }
        $this->left = min($this->left, $x);
        $this->top = min($this->top, $y);
        $this->right = max($this->right, $x + 1);
        $this->bottom = max($this->bottom, $y + 1);
        $offset = ($x - $this->offsetX) * $this->stride;
        $rowY = $y - $this->offsetY;
        if ($this->paintedRows[$rowY] === "\0") {
            // Blank rows share one string. Other planes may have consumed
            // headroom since construction; copying it must be judged NOW.
            $this->budget->allocation((float) $this->bufferWidth * $this->stride + 128);
            $this->paintedRows[$rowY] = "\1";
        }
        $v = array_values(unpack('f'.($this->space->components + 2), $this->rows[$rowY], $offset));
        $oldAlpha = $v[$this->space->components];
        [$back, $backAlpha] = $this->knockout ? $this->initial($x, $y) : $this->sample($x, $y);
        $blend = BlendMode::apply($back, $source, $mode, $this->space->kind);
        $keep = 1 - ($this->knockout ? $shape : $alpha);
        for ($i = 0; $i < $this->space->components; $i++) {
            $v[$i] = $keep * $v[$i] + $alpha * ((1 - $backAlpha) * $source[$i] + $backAlpha * $blend[$i]);
        }
        $v[$this->space->components] = $keep * $oldAlpha + $alpha;
        $v[$this->space->components + 1] += $shape * (1 - $v[$this->space->components + 1]);
        $packed = pack('f*', ...$v);
        // Direct byte assignment avoids copying an entire scanline for each pixel.
        for ($i = 0; $i < $this->stride; $i++) {
            $this->rows[$rowY][$offset + $i] = $packed[$i];
        }
    }

    /** Conservative bounds of painted shape, including zero-opacity knockout objects. */
    public function bounds(): array
    {
        return [$this->left, $this->top, $this->right, $this->bottom];
    }
}
