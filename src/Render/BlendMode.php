<?php

declare(strict_types=1);

namespace FinePrint\Render;

/** ISO 32000-1 tables 136/137. All work is bounded by four colour components. */
final class BlendMode
{
    public const NAMES = ['Normal', 'Compatible', 'Multiply', 'Screen', 'Overlay', 'Darken', 'Lighten', 'ColorDodge', 'ColorBurn', 'HardLight', 'SoftLight', 'Difference', 'Exclusion', 'Hue', 'Saturation', 'Color', 'Luminosity'];

    public static function apply(array $back, array $source, string $mode, string $space = 'rgb'): array
    {
        if ($mode === 'Normal' || $mode === 'Compatible') {
            return $source;
        }
        // PDF's blend functions operate on complementary values in subtractive spaces.
        if ($space === 'cmyk') {
            $back = array_map(static fn ($v) => 1 - $v, $back);
            $source = array_map(static fn ($v) => 1 - $v, $source);
        }
        if (in_array($mode, ['Hue', 'Saturation', 'Color', 'Luminosity'], true)) {
            if ($space === 'gray') {
                $result = $mode === 'Luminosity' ? $source : $back;
            } else {
                $b = array_slice($back, 0, 3);
                $s = array_slice($source, 0, 3);
                $result = match ($mode) {
                    'Hue' => self::setLum(self::setSat($s, max($b) - min($b)), self::lum($b)),
                    'Saturation' => self::setLum(self::setSat($b, max($s) - min($s)), self::lum($b)),
                    'Color' => self::setLum($s, self::lum($b)),
                    default => self::setLum($b, self::lum($s)),
                };
                if ($space === 'cmyk') {
                    $result[] = $mode === 'Luminosity' ? $source[3] : $back[3];
                }
            }
        } else {
            $result = [];
            foreach ($source as $i => $s) {
                $b = $back[$i];
                $result[] = match ($mode) {
                    'Multiply' => $b * $s,
                    'Screen' => $b + $s - $b * $s,
                    'Overlay' => $b <= 0.5 ? 2 * $b * $s : 1 - 2 * (1 - $b) * (1 - $s),
                    'Darken' => min($b, $s),
                    'Lighten' => max($b, $s),
                    'ColorDodge' => $b <= 0 ? 0 : ($s >= 1 ? 1 : min(1, $b / (1 - $s))),
                    'ColorBurn' => $b >= 1 ? 1 : ($s <= 0 ? 0 : 1 - min(1, (1 - $b) / $s)),
                    'HardLight' => $s <= 0.5 ? 2 * $b * $s : 1 - 2 * (1 - $b) * (1 - $s),
                    'SoftLight' => $s <= 0.5 ? $b - (1 - 2 * $s) * $b * (1 - $b)
                        : $b + (2 * $s - 1) * (($b <= 0.25 ? ((16 * $b - 12) * $b + 4) * $b : sqrt($b)) - $b),
                    'Difference' => abs($b - $s),
                    'Exclusion' => $b + $s - 2 * $b * $s,
                    default => $s,
                };
            }
        }

        return array_map(static fn ($v) => max(0.0, min(1.0, $space === 'cmyk' ? 1 - $v : $v)), $result);
    }

    public static function lum(array $c): float
    {
        return .30 * $c[0] + .59 * $c[1] + .11 * $c[2];
    }

    private static function setLum(array $c, float $lum): array
    {
        $d = $lum - self::lum($c);
        $c = array_map(static fn ($v) => $v + $d, $c);
        $n = min($c);
        $x = max($c);
        if ($n < 0) {
            $c = array_map(static fn ($v) => $lum + ($v - $lum) * $lum / ($lum - $n), $c);
        }
        if ($x > 1) {
            $c = array_map(static fn ($v) => $lum + ($v - $lum) * (1 - $lum) / ($x - $lum), $c);
        }

        return $c;
    }

    private static function setSat(array $c, float $sat): array
    {
        $indices = [0, 1, 2];
        usort($indices, static fn ($a, $b) => $c[$a] <=> $c[$b]);
        [$lo, $mid, $hi] = $indices;
        if ($c[$hi] > $c[$lo]) {
            $c[$mid] = ($c[$mid] - $c[$lo]) * $sat / ($c[$hi] - $c[$lo]);
            $c[$hi] = $sat;
        } else {
            $c[$mid] = $c[$hi] = 0.0;
        }
        $c[$lo] = 0.0;

        return $c;
    }
}
