<?php

declare(strict_types=1);

namespace FinePrint\Render\Images;

use FinePrint\Budget;
use FinePrint\Render\ColourSpace;
use FinePrint\Render\CoverageMask;
use FinePrint\Render\GraphicsState;
use FinePrint\Render\Raster;

/** Inverse-map device pixel centres into the image's PDF unit square. */
final class ImagePainter
{
    public static function paint(SampledImage $image, Raster $raster, array $m, array $fill, Budget $budget, ?CoverageMask $clip = null, float $alpha = 1.0, ?GraphicsState $state = null): void
    {
        $mask = $image->hasMask() ? null : $state?->softMask;
        $mode = $state?->blendMode ?? 'Normal';
        $simple = $raster->surface === null && $mask === null && in_array($mode, ['Normal', 'Compatible'], true);
        $direct = $simple && ! $image->stencil && $image->canPaintRgbDirectly();
        $target = $direct ? null : ($raster->surface?->space ?? ColourSpace::rgb());
        if ($simple && $image->stencil && $state !== null) {
            $fill = $target->toRgb($state->fillSpace->convertTo($state->fill, $target));
        }
        [$a, $b, $c, $d, $e, $f] = $m;
        $det = $a * $d - $b * $c;
        if (! is_finite($det) || abs($det) < 1e-20 || ! is_finite($e) || ! is_finite($f)) {
            return;
        }
        $xs = [$e, $e + $a, $e + $c, $e + $a + $c];
        $ys = [$f, $f + $b, $f + $d, $f + $b + $d];
        $x0 = (int) max(0.0, min((float) $raster->width, floor(min($xs))));
        $x1 = (int) max(0.0, min((float) $raster->width, ceil(max($xs))));
        $y0 = (int) max(0.0, min((float) $raster->height, floor(min($ys))));
        $y1 = (int) max(0.0, min((float) $raster->height, ceil(max($ys))));
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = $x0; $x < $x1; $x++) {
                $budget->tick();
                $dx = $x + 0.5 - $e;
                $dy = $y + 0.5 - $f;
                $u = ($d * $dx - $c * $dy) / $det;
                $v = (-$b * $dx + $a * $dy) / $det;
                if ($u < 0 || $u >= 1 || $v <= 0 || $v > 1) {
                    continue;
                }
                $sx = min($image->width - 1, (int) floor($u * $image->width));
                $sy = min($image->height - 1, (int) floor((1 - $v) * $image->height));
                $rgb = $image->pixel($sx, $sy, $fill, $target);
                if ($rgb !== null) {
                    if ($simple) {
                        if (! $direct && ! $image->stencil) {
                            $rgb = $target->toRgb($rgb);
                        }
                        $raster->span($y, $x, 1, $rgb[0], $rgb[1], $rgb[2], $alpha * $image->opacity($u, 1 - $v), $clip, $budget);

                        continue;
                    }
                    if ($image->stencil) {
                        $rgb = $state !== null ? $state->fillSpace->convertTo($state->fill, $target)
                            : ColourSpace::rgb()->convertTo(array_map(static fn ($v) => $v / 255, $rgb), $target);
                    }
                    $raster->paint($y, $x, 1, $rgb, $target, $alpha, 1.0, $budget, $clip, $mask, $mode, $state?->alphaIsShape ?? false, $image->opacity($u, 1 - $v), $image->maskIsShape());
                }
            }
        }
    }
}
