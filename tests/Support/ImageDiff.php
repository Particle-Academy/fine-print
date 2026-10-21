<?php

declare(strict_types=1);

namespace FinePrint\Tests\Support;

use InvalidArgumentException;

/**
 * Compares two RGB rasters (one string of width*3 bytes per row) with a
 * per-pixel tolerance, for the Chromium golden tests.
 *
 * - `mae`: mean absolute error per channel, 0..255, over the compared area.
 * - `mismatch`: the share of pixels (0..1) whose largest channel difference
 *   exceeds `threshold` — the "how much of the picture is visibly different"
 *   number, which a low MAE on a mostly-white page can hide.
 * - `max`: the largest channel difference anywhere.
 * - `inkMismatch`: the share of pixels where one image has ink (any channel
 *   below 250) and the other does not. Colour-blind on purpose: it measures
 *   GEOMETRY, for pages whose colours are expected to differ (CMYK).
 *
 * A `border` band is excluded: Chromium's PDF viewer draws its page frame
 * over the outermost pixel or two of the page, so those are not the page.
 */
final class ImageDiff
{
    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return array{mae: float, mismatch: float, max: int, inkMismatch: float, pixels: int, diff: list<string>}
     */
    public static function compare(array $a, array $b, int $width, int $height, int $border = 2, int $threshold = 64): array
    {
        if (count($a) !== $height || count($b) !== $height) {
            throw new InvalidArgumentException('row count differs: '.count($a).' / '.count($b).' / '.$height);
        }
        $sum = 0;
        $bad = 0;
        $ink = 0;
        $max = 0;
        $pixels = 0;
        $diff = [];
        for ($y = 0; $y < $height; $y++) {
            if (strlen($a[$y]) !== $width * 3 || strlen($b[$y]) !== $width * 3) {
                throw new InvalidArgumentException('row '.$y.' has the wrong length');
            }
            $drow = str_repeat("\xFF", $width * 3);
            if ($y < $border || $y >= $height - $border) {
                $diff[] = $drow;

                continue;
            }
            $ra = $a[$y];
            $rb = $b[$y];
            if ($ra === $rb) {
                $pixels += $width - 2 * $border;
                $diff[] = $drow;

                continue;
            }
            for ($x = $border; $x < $width - $border; $x++) {
                $i = $x * 3;
                $d0 = abs(ord($ra[$i]) - ord($rb[$i]));
                $d1 = abs(ord($ra[$i + 1]) - ord($rb[$i + 1]));
                $d2 = abs(ord($ra[$i + 2]) - ord($rb[$i + 2]));
                $sum += $d0 + $d1 + $d2;
                $m = max($d0, $d1, $d2);
                $max = max($max, $m);
                if ($m > $threshold) {
                    $bad++;
                }
                $inkA = min(ord($ra[$i]), ord($ra[$i + 1]), ord($ra[$i + 2])) < 250;
                $inkB = min(ord($rb[$i]), ord($rb[$i + 1]), ord($rb[$i + 2])) < 250;
                if ($inkA !== $inkB) {
                    $ink++;
                }
                $pixels++;
                // Diff image: white where equal, darker red the larger the difference.
                $v = 255 - min(255, $m * 2);
                $drow[$i] = "\xFF";
                $drow[$i + 1] = chr($v);
                $drow[$i + 2] = chr($v);
            }
            $diff[] = $drow;
        }

        return [
            'mae' => $pixels > 0 ? $sum / ($pixels * 3) : 0.0,
            'mismatch' => $pixels > 0 ? $bad / $pixels : 0.0,
            'max' => $max,
            'inkMismatch' => $pixels > 0 ? $ink / $pixels : 0.0,
            'pixels' => $pixels,
            'diff' => $diff,
        ];
    }
}
