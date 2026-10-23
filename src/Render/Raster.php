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

    /** Page alpha alongside the RGB display result, recovered for later blends. */
    private ?array $alphaRows = null;

    private string $paintedRows = '';

    /** One bounded opaque span result, shared only when its WHOLE input row matches. */
    private int $opaqueX = -1;

    private int $opaqueLength = 0;

    private string $opaqueColour = '';

    private ?string $opaqueInput = null;

    private ?string $opaqueOutput = null;

    public function __construct(public readonly int $width, public readonly int $height, private readonly ?Budget $budget = null, ?array $rows = null, public readonly ?GroupSurface $surface = null, bool $transparentPage = false)
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('A raster must have positive dimensions.');
        }
        if ($surface !== null) {
            $this->rows = [];

            return;
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
            $budget?->allocation($height + 64);
            $this->paintedRows = str_repeat("\0", $height);
            $budget?->check();

            return;
        }
        // Worst-case painted rows include string/array overhead and a
        // temporary row. Pixel count alone misses a 1 x 4,000,000 page.
        $budget?->allocation((float) $width * $height * ($transparentPage ? 7 : 3) + (float) $height * ($transparentPage ? 193 : 97) + (float) $width * 7);
        $blank = str_repeat("\xFF", $width * 3);
        $this->rows = array_fill(0, $height, $blank);
        $this->paintedRows = str_repeat("\0", $height);
        if ($transparentPage) {
            $this->alphaRows = array_fill(0, $height, str_repeat("\0", $width * 4));
        }
    }

    /**
     * Paint `len` pixels of row y from x, in colour (r, g, b) at coverage a.
     * Assumes 0 <= x, x + len <= width, 0 <= y < height (the rasteriser's spans are).
     */
    public function span(int $y, int $x, int $len, int $r, int $g, int $b, float $a, ?CoverageMask $clip = null, ?Budget $budget = null): void
    {
        if ($this->surface !== null) {
            $this->paint($y, $x, $len, [$r / 255, $g / 255, $b / 255], ColourSpace::rgb(), 1.0, $a, $budget, $clip);

            return;
        }
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
        if ($len >= 64 && $a >= 0.998 && $this->alphaRows === null) {
            $colour = chr($r).chr($g).chr($b);
            if ($x === $this->opaqueX && $len === $this->opaqueLength && $colour === $this->opaqueColour
                && $this->rows[$y] === $this->opaqueInput) {
                // Identical rectangle rows need no new allocation or preflight.
                $this->rows[$y] = $this->opaqueOutput;
            } else {
                $this->guardRow($y, true, $budget);
                $input = $this->rows[$y];
                $output = substr_replace($input, str_repeat($colour, $len), $x * 3, $len * 3);
                $this->rows[$y] = $output;
                $this->opaqueX = $x;
                $this->opaqueLength = $len;
                $this->opaqueColour = $colour;
                $this->opaqueInput = $input;
                $this->opaqueOutput = $output;
            }
            // The result is shared with the cache/other rows. A later pixel
            // write MUST preflight its copy, even though this row was painted.
            $this->paintedRows[$y] = "\0";

            return;
        }
        if ($this->paintedRows[$y] === "\0" || ($a >= 0.998 && $len >= 16)) {
            $this->guardRow($y, $a >= 0.998 && $len >= 16, $budget);
        }
        if ($this->alphaRows !== null) {
            $this->accumulateAlpha($y, $x, $len, $a, $budget);
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
        if ($this->surface !== null) {
            [$colour] = $this->surface->sample($x, $y);

            return $this->surface->space->toRgb($colour);
        }
        $i = $x * 3;
        $row = $this->rows[$y];

        return [ord($row[$i]), ord($row[$i + 1]), ord($row[$i + 2])];
    }

    /** @return list<string> */
    public function rows(): array
    {
        return $this->rows;
    }

    public function hasPageAlpha(): bool
    {
        return $this->alphaRows !== null;
    }

    /** Straight native colour and alpha, including a group's initial backdrop. */
    public function sample(int $x, int $y, ColourSpace $space): array
    {
        if ($this->surface !== null) {
            [$c, $a] = $this->surface->sample($x, $y);

            return [$this->surface->space->convertTo($c, $space), $a];
        }

        $alpha = $this->alphaRows !== null ? unpack('f', $this->alphaRows[$y], $x * 4)[1] : 1.0;
        $rgb = array_map(static fn ($v) => $alpha > 0 ? max(0.0, min(1.0, ($v / 255 - (1 - $alpha)) / $alpha)) : 0.0, $this->pixel($x, $y));

        return [ColourSpace::rgb()->convertTo($rgb, $space), $alpha];
    }

    public static function group(self $parent, ColourSpace $space, bool $isolated, bool $knockout, Budget $budget, ?array $backdrop = null, ?array $region = null): self
    {
        // A subgroup of a knockout group sees that group's INITIAL backdrop.
        $initial = $parent->surface?->knockout ? $parent->surface->backdrop : $parent;
        if (! $isolated && $backdrop === null && $parent->surface?->knockout) {
            $backdrop = $parent->surface->constantBackdrop;
        }
        $surface = new GroupSurface($parent->width, $parent->height, $space, $isolated ? null : $initial, $knockout, $budget, $backdrop, $region);

        return new self($parent->width, $parent->height, surface: $surface);
    }

    /**
     * Coverage, intrinsic alpha, constant alpha and mask remain separate until
     * compositing: an invisible object can still knock out a preceding object.
     */
    public function paint(int $y, int $x, int $len, array $colour, ColourSpace $space, float $opacity, float $coverage, ?Budget $budget = null, ?CoverageMask $clip = null, ?CoverageMask $mask = null, string $mode = 'Normal', bool $alphaIsShape = false, float $intrinsicAlpha = 1.0, bool $intrinsicShape = false): void
    {
        if ($this->surface === null && ($region = $mask?->zeroOutsideBounds()) !== null) {
            [$left, $top, $right, $bottom] = $region;
            if ($y < $top || $y >= $bottom) {
                return;
            }
            $end = min($x + $len, $right);
            $x = max($x, $left);
            $len = max(0, $end - $x);
        }
        if ($this->surface === null && in_array($mode, ['Normal', 'Compatible'], true) && $mask === null) {
            [$r, $g, $b] = $space->toRgb($colour);
            $this->span($y, $x, $len, $r, $g, $b, $coverage * $opacity * $intrinsicAlpha, $clip, $budget);

            return;
        }
        $target = $this->surface?->space ?? ColourSpace::rgb();
        $colour = $space->convertTo($colour, $target);
        for ($i = $x; $i < $x + $len; $i++) {
            $budget?->tick();
            $shape = $coverage * ($clip?->at($i, $y) ?? 1.0);
            $factor = $opacity * ($mask?->at($i, $y) ?? 1.0);
            $a = $shape * $intrinsicAlpha * $factor;
            if ($alphaIsShape) {
                $shape *= $factor;
            }
            if ($alphaIsShape || $intrinsicShape) {
                $shape *= $intrinsicAlpha;
            }
            $this->compositePixel($i, $y, $colour, $a, $shape, $mode);
        }
    }

    private function compositePixel(int $x, int $y, array $colour, float $alpha, float $shape, string $mode): void
    {
        if ($shape <= 0) {
            return;
        }
        if ($this->surface !== null) {
            $this->surface->paint($x, $y, $colour, $alpha, $shape, $mode);

            return;
        }
        if ($alpha > 0) {
            $this->guardRow($y);
            [$back, $backAlpha] = $this->sample($x, $y, ColourSpace::rgb());
            $blend = BlendMode::apply($back, $colour, $mode);
            $display = $this->pixel($x, $y);
            $offset = $x * 3;
            foreach ($blend as $i => $v) {
                $source = max(0.0, min(1.0, (1 - $backAlpha) * $colour[$i] + $backAlpha * $v));
                $this->rows[$y][$offset + $i] = chr((int) round($display[$i] + ($source * 255 - $display[$i]) * $alpha));
            }
            $this->accumulateAlpha($y, $x, 1, $alpha, null);
        }
    }

    public function composite(self $group, GraphicsState $state, Budget $budget): void
    {
        $target = $this->surface?->space ?? ColourSpace::rgb();
        [$left, $top, $right, $bottom] = $group->surface->bounds();
        for ($y = $top; $y < $bottom; $y++) {
            for ($x = $left; $x < $right; $x++) {
                $budget->tick();
                [$c, $a, $shape] = $group->surface->source($x, $y);
                if ($shape <= 0) {
                    continue;
                }
                $factor = $state->fillAlpha * ($state->softMask?->at($x, $y) ?? 1.0);
                $a *= $factor;
                if ($state->alphaIsShape) {
                    $shape *= $factor;
                }
                $this->compositePixel($x, $y, $group->surface->space->convertTo($c, $target), $a, $shape, $state->blendMode);
            }
        }
    }

    private function accumulateAlpha(int $y, int $x, int $len, float $alpha, ?Budget $budget): void
    {
        if ($this->alphaRows === null) {
            return;
        }
        if ($alpha >= 0.998) {
            $one = pack('f', 1.0);
            if ($len >= 16) {
                $this->alphaRows[$y] = substr_replace($this->alphaRows[$y], str_repeat($one, $len), $x * 4, $len * 4);
            } else {
                for ($i = $x; $i < $x + $len; $i++) {
                    for ($j = 0; $j < 4; $j++) {
                        $this->alphaRows[$y][$i * 4 + $j] = $one[$j];
                    }
                }
            }

            return;
        }
        for ($i = $x; $i < $x + $len; $i++) {
            $budget?->tick();
            $prior = unpack('f', $this->alphaRows[$y], $i * 4)[1];
            $value = pack('f', $prior + $alpha * (1 - $prior));
            for ($j = 0; $j < 4; $j++) {
                $this->alphaRows[$y][$i * 4 + $j] = $value[$j];
            }
        }
    }

    private function guardRow(int $y, bool $temporary = false, ?Budget $budget = null): void
    {
        $first = $this->paintedRows[$y] === "\0";
        if ($first || $temporary) {
            // Both row materialisation and substr_replace's temporary rows
            // can occur long after the initial canvas capacity check.
            $factor = $this->alphaRows !== null ? ($first ? 11 : 8) : 6;
            ($this->budget ?? $budget)?->allocation((float) $this->width * $factor + 4096);
            $this->paintedRows[$y] = "\1";
        }
    }
}
