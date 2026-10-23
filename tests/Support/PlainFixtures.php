<?php

declare(strict_types=1);

namespace FinePrint\Tests\Support;

use FinePrint\Agent;

/** Owned opaque pages used for cross-version pixel and performance checks. */
final class PlainFixtures
{
    public const RGB_020 = [
        'opaque-blocks' => '5d66de31d86562f27d938b7449c315596a685ac052416c5d7be8ff027e5c1241',
        'opaque-deck' => 'd499bc4183843f4ea0c16f26683db609fc67d85c27ee6945c955c072f210b7bd',
        'opaque-image' => '1b55b18d6e0e974a8a772d2e0389e29d97f23ae55ba868e9e343134fa6961234',
        'p2-report' => '290aa7e152e273a924f74b602bc8f7abfc2c046e8cd1195338c503840055b876',
        'p2-text' => '3c87a80e8be49ff9b91239b4008697f5c66947622e52303c0e60648af67f2a37',
        'std14' => 'ae67d42175c70a2b94a74171e19a13d31db1c01824e96115d64e3ad312055a45',
    ];

    /** Rectangle coordinates and decimal components, shared with the direct reference. */
    public static function rectangles(): array
    {
        $out = [[0, 0, 612, 792, 1.0, 1.0, 1.0]];
        for ($i = 0; $i < 90; $i++) {
            $out[] = [10 + ($i % 6) * 98, 12 + intdiv($i, 6) * 50, 88, 40,
                round(($i % 7) / 7, 3), round(($i % 5) / 5, 3), round(($i % 3) / 3, 3)];
        }

        return $out;
    }

    public static function pdf(string $name): string
    {
        if ($name === 'opaque-image') {
            $b = PdfBuilder::textPdf(['240 0 0 180 30 40 cm /Im Do'], ['mediaBox' => '[0 0 612 792]', 'resources' => '/XObject << /Im 6 0 R >>']);
            $data = '';
            for ($y = 0; $y < 16; $y++) {
                for ($x = 0; $x < 16; $x++) {
                    $data .= chr($x * 17).chr($y * 17).chr(($x ^ $y) * 17);
                }
            }
            $b->stream($data, '/Subtype /Image /Width 16 /Height 16 /ColorSpace /DeviceRGB /BitsPerComponent 8');

            return $b->build();
        }
        if (in_array($name, ['p2-text', 'std14'], true)) {
            return RenderFixtures::pdf($name);
        }
        if ($name === 'p2-report') {
            return Agent::toBytes(json_decode((string) file_get_contents(__DIR__.'/../fixtures/canonical.json'), true));
        }
        if ($name === 'opaque-blocks') {
            $ops = '';
            foreach (self::rectangles() as [$x, $y, $w, $h, $r, $g, $b]) {
                $ops .= sprintf('%.3F %.3F %.3F rg %d %d %d %d re f ', $r, $g, $b, $x, $y, $w, $h);
            }
        } else {
            $ops = '0.1 0.2 0.4 rg 0 0 612 792 re f 1 1 1 rg 20 20 572 752 re f ';
            for ($i = 0; $i < 60; $i++) {
                $ops .= '0.1 0.2 0.4 rg BT /F1 10 Tf 30 '.(760 - $i * 12).' Td '
                    .PdfBuilder::lit('Owned benchmark: opaque text and shapes 0123456789.').' Tj ET ';
            }
        }

        return PdfBuilder::textPdf([$ops], ['mediaBox' => '[0 0 612 792]'])->build();
    }
}
