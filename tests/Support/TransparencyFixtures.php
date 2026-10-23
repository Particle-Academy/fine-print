<?php

declare(strict_types=1);

namespace FinePrint\Tests\Support;

/** Own PDFs, oracle bounds fixed before the first Chromium render (see GoldenTest). */
final class TransparencyFixtures
{
    public const MODES = ['Normal', 'Multiply', 'Screen', 'Overlay', 'Darken', 'Lighten', 'ColorDodge', 'ColorBurn', 'HardLight', 'SoftLight', 'Difference', 'Exclusion', 'Hue', 'Saturation', 'Color', 'Luminosity'];

    public static function scales(): array
    {
        $scales = [];
        foreach (self::MODES as $mode) {
            $scales['blend-'.$mode] = [1, 2];
        }
        $scales['transparency-groups'] = [1, 2];
        $scales['transparency-masks'] = [1, 2];

        return $scales;
    }

    public static function all(): array
    {
        $all = [];
        foreach (self::MODES as $mode) {
            $ops = '0.2 0.4 0.8 rg 0 0 100 200 re f 0.8 0.3 0.1 rg 100 0 100 100 re f ';
            $states = '';
            foreach ([1, .75, .5, .25] as $row => $alpha) {
                $states .= '/A'.$row.' << /BM /'.$mode.' /ca '.$alpha.' >> ';
                foreach (['0.8 0.3 0.1', '0.1 0.7 0.4', '0.9 0.9 0.9', '0.05 0.2 0.5'] as $col => $rgb) {
                    $x = 5 + 50 * $col;
                    $y = 5 + 50 * $row;
                    $ops .= '/A'.$row.' gs '.$rgb.' rg '.$x.' '.$y.' 40 40 re f ';
                }
            }
            $all['blend-'.$mode] = ['mediaBox' => '[0 0 200 200]', 'content' => $ops, 'resources' => '/ExtGState << '.$states.' >>'];
        }
        $forms = [];
        $ops = '0.2 0.4 0.8 rg 0 0 200 200 re f ';
        $xobjects = '';
        foreach ([[false, false], [true, false], [false, true], [true, true]] as $i => [$isolated, $knockout]) {
            $ref = 6 + $i;
            $xobjects .= '/G'.$i.' '.$ref.' 0 R ';
            $ops .= 'q 1 0 0 1 '.(100 * ($i % 2)).' '.(100 * intdiv($i, 2)).' cm /Invoke gs /G'.$i.' Do Q ';
            $forms[] = [
                'dict' => '/BBox [0 0 100 100] /Group << /S /Transparency /I '.($isolated ? 'true' : 'false').' /K '.($knockout ? 'true' : 'false').' >> /Resources << /ExtGState << /M << /ca 0.6 /BM /Multiply >> /S << /ca 0.4 /BM /Screen >> >> >>',
                'content' => '/M gs 1 0.2 0 rg 5 5 65 65 re f /S gs 0.1 0.9 0.2 rg 30 30 65 65 re f',
            ];
        }
        $all['transparency-groups'] = ['mediaBox' => '[0 0 200 200]', 'content' => $ops,
            'resources' => '/ExtGState << /Invoke << /ca 0.7 /BM /HardLight >> >> /XObject << '.$xobjects.' >>', 'forms' => $forms];
        $forms = [];
        $states = '';
        $ops = '';
        foreach (['Alpha', 'Luminosity', 'Alpha', 'Luminosity'] as $i => $subtype) {
            $ref = 6 + $i;
            $transfer = $i >= 2 ? '/TR << /FunctionType 2 /Domain [0 1] /C0 [1] /C1 [0] /N 1 >>' : '';
            $states .= '/M'.$i.' << /ca 0.8 /SMask << /S /'.$subtype.' /G '.$ref.' 0 R /BC [0.2 0.2 0.2] '.$transfer.' >> >> ';
            $ops .= 'q 1 0 0 1 '.(100 * ($i % 2)).' '.(100 * intdiv($i, 2)).' cm /M'.$i.' gs 1 0.2 0 rg 0 0 100 100 re f Q ';
            $forms[] = ['dict' => '/BBox [0 0 100 100] /Group << /S /Transparency /CS /DeviceRGB >> /Resources << /ExtGState << /Half << /ca 0.5 >> >> >>',
                'content' => '/Half gs 1 1 1 rg 0 0 70 100 re f 0.1 0.4 0.8 rg 30 30 65 65 re f'];
        }
        $all['transparency-masks'] = ['mediaBox' => '[0 0 200 200]', 'content' => $ops, 'resources' => '/ExtGState << '.$states.' >>', 'forms' => $forms];

        return $all;
    }
}
