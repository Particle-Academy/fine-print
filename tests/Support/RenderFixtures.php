<?php

declare(strict_types=1);

namespace FinePrint\Tests\Support;

use FinePrint\Agent;

/**
 * The vector pages the renderer is compared against Chromium on.
 *
 * Each is written by {@see PdfBuilder}, so the bytes are ours and fixed. The
 * same bytes are committed as `tests/golden/<name>.pdf` next to Chromium's
 * rendering of them (`<name>@<scale>x.png`); the golden test rebuilds each PDF
 * and checks it still matches the committed one, because a golden image of a
 * PDF that has since changed is a comparison against nothing.
 *
 * Regenerate the goldens with tests/oracle (see tests/oracle/build.php).
 *
 * PAGE SIZES ARE CHOSEN, NOT ARBITRARY. Chromium's viewer draws some page
 * sizes stretched: at zoom 150, 200x150 pt comes out 0.34% taller and
 * 0.06 px off the pixel grid, 200x120 0.42%. 200x200, 200x160, 200x128 and
 * 128x200 are exact (measured 2026-10-21). A fixture on a stretched size
 * would charge Chromium's stretch to the renderer, so keep to those, and the
 * oracle refuses an off-grid page if one slips in.
 */
final class RenderFixtures
{
    /** Scales each fixture is compared at. */
    public const SCALES = [
        'rects' => [1, 2],
        'curves' => [1, 2],
        'fill-rules' => [1],
        'strokes' => [1, 2],
        'dashes' => [1, 2],
        'transforms' => [1, 2],
        'rotate-90' => [1],
        'rotate-180' => [1],
        'rotate-270' => [1],
        'cropbox' => [1],
        'forms' => [1],
        'cmyk' => [1],
    ];

    /**
     * @return array<string, array{content: string, mediaBox: string, page?: string, resources?: string, forms?: list<array{content: string, dict: string}>}>
     */
    public static function all(): array
    {
        $k = 0.5522847498;

        return [
            'rects' => [
                'mediaBox' => '[0 0 200 160]',
                'content' => "1 0 0 rg 10 10 60 40 re f\n"
                    ."0 0.6 0 rg 40 30 60 40 re f\n"
                    ."0.2 0.3 0.8 rg 120.5 20.25 50.5 50.5 re f\n"
                    ."0.5 g 10 90 180 10 re f\n"
                    ."0 g 10 110 30.3 30.7 re f\n"
                    ."1 0.5 0 rg 60 105 m 100 140 l 140 105 l h f\n"
                    ."0.3 0.7 0.9 rg 150 110 m 190 112 l 185 140 l 155 135 l h f\n",
            ],
            'curves' => [
                'mediaBox' => '[0 0 200 200]',
                'content' => '0.8 0.1 0.1 rg '.self::circle(55, 145, 40, $k)." f\n"
                    .'0 0 0.7 RG 3 w '.self::ellipse(145, 145, 40, 25, $k)." S\n"
                    ."0.1 0.6 0.2 rg 15 20 m 15 90 95 90 v h f\n"
                    ."0.6 0.2 0.7 rg 110 20 m 110 90 190 20 y h f\n"
                    ."0 0 0 RG 2 w 10 100 m 40 130 70 70 100 100 c 130 130 160 70 190 100 c S\n",
            ],
            'fill-rules' => [
                'mediaBox' => '[0 0 200 100]',
                'content' => '0 0 0 rg '.self::star(50, 50, 42).' f '.self::star(150, 50, 42)." f*\n",
            ],
            'strokes' => [
                'mediaBox' => '[0 0 200 200]',
                'content' => "0 G 1 w 10 190 m 190 190 l S\n"
                    ."2.5 w 10 180 m 190 180 l S\n"
                    ."6 w 10 167 m 190 167 l S\n"
                    ."0.7 0 0 RG 12 w 0 J 20 145 m 60 145 l S\n"
                    ."1 J 85 145 m 125 145 l S\n"
                    ."2 J 150 145 m 180 145 l S\n"
                    ."0 0 0.7 RG 10 w 0 J 0 j 15 70 m 40 120 l 65 70 l S\n"
                    ."1 j 80 70 m 105 120 l 130 70 l S\n"
                    ."2 j 145 70 m 170 120 l 195 70 l S\n"
                    ."0 0.5 0 RG 6 w 0 j 10 M 15 15 m 60 45 l 105 15 l S\n"
                    ."1.5 M 120 15 m 150 50 l 180 15 l S\n",
            ],
            'dashes' => [
                'mediaBox' => '[0 0 200 200]',
                'content' => "0 G 3 w [10 5] 0 d 10 185 m 190 185 l S\n"
                    ."[10 5] 7 d 10 172 m 190 172 l S\n"
                    ."[2 4 12 4] 0 d 10 159 m 190 159 l S\n"
                    ."6 w 1 J [0 12] 0 d 10 142 m 190 142 l S\n"
                    .'0 J 2 w [8 6] 0 d 0 0 0.8 RG '.self::circle(55, 75, 40, $k)." S\n"
                    ."0.8 0 0 RG 4 w 0 j [15 10] 3 d 110 40 70 70 re S\n",
            ],
            'transforms' => [
                'mediaBox' => '[0 0 200 200]',
                'content' => "q 0.866 0.5 -0.5 0.866 60 110 cm 0.9 0.4 0 rg -30 -15 60 30 re f Q\n"
                    .'q 1 0 0 0.4 140 150 cm 0 0 0.6 RG 6 w '.self::circle(0, 0, 40, $k)." S Q\n"
                    ."q 1 0 0.5 1 20 20 cm 0.2 0.6 0.2 rg 0 0 60 50 re f Q\n"
                    ."q 2 0 0 2 120 20 cm q 0.5 g 0 0 20 20 re f Q 1 0 0 rg 10 10 20 20 re f Q\n",
            ],
            'rotate-90' => self::rotated(90),
            'rotate-180' => self::rotated(180),
            'rotate-270' => self::rotated(270),
            'cropbox' => [
                'mediaBox' => '[0 0 300 300]',
                'page' => '/CropBox [50 60 250 220]',
                'content' => "0 0 1 rg 0 0 300 300 re f\n"
                    ."1 1 1 rg 55 65 190 140 re f\n"
                    ."1 0 0 rg 60 70 40 40 re f\n"
                    ."0 0.6 0 rg 200 160 40 40 re f\n"
                    ."0 G 2 w 50 60 m 250 210 l S\n",
            ],
            'forms' => [
                'mediaBox' => '[0 0 200 200]',
                'resources' => '/XObject << /Fm 6 0 R >>',
                'content' => "0.8 0.2 0.2 rg /Fm Do\n"
                    ."q 1 0 0 1 100 0 cm 0.2 0.2 0.8 rg /Fm Do Q\n"
                    ."q 0.5 0 0 0.5 50 110 cm 0.2 0.6 0.2 rg /Fm Do Q\n",
                'forms' => [
                    ['content' => "10 10 60 60 re f\n0 G 2 w 10 10 m 70 70 l S\n/In Do\n", 'dict' => '/BBox [0 0 100 100] /Matrix [1 0 0 1 10 10] /Resources << /XObject << /In 7 0 R >> >>'],
                    ['content' => "0 g 75 75 15 15 re f\n", 'dict' => '/BBox [0 0 100 100]'],
                ],
            ],
            'cmyk' => [
                'mediaBox' => '[0 0 200 100]',
                'content' => "1 0 0 0 k 10 50 40 40 re f\n"
                    ."0 1 0 0 k 55 50 40 40 re f\n"
                    ."0 0 1 0 k 100 50 40 40 re f\n"
                    ."0 0 0 1 k 145 50 40 40 re f\n"
                    ."0.2 0.4 0 0.1 k 10 5 85 40 re f\n"
                    ."0 0 0 0.5 k 105 5 80 40 re f\n",
            ],
        ] + TransparencyFixtures::all();
    }

    /**
     * Text goldens (P3b). `glyphs` is written here with glyph origins on
     * half points (whole pixels at the oracle's 2x), because PDFium snaps
     * every glyph origin to a whole device pixel (measured 2026-10-21): with
     * origins already on the grid the comparison is of outlines. `p2-text`
     * is written by the P2 writer, so its origins fall where layout puts
     * them and the snapping shows; it is held to the looser TEXT tolerance.
     */
    public const TEXT_SCALES = [
        'glyphs' => [1, 2],
        'p2-text' => [1, 2],
        'std14' => [1, 2],
    ];

    /** Snap-immune fixtures, held to the strict tolerance. */
    public const SNAP_IMMUNE = ['glyphs'];

    /**
     * Text goldens built from a font installed on THIS machine (the `local`
     * group). The PDF is built at test time and never committed, so no font
     * byte ships; only Chromium's PNG of it and the PDF's SHA-256 are kept.
     */
    public const LOCAL_SCALES = [
        'p2-dejavu' => [1, 2],
    ];

    public const DEJAVU = 'C:/Windows/Fonts/DejaVuSans.ttf';

    /** A local fixture's PDF, or null when its font is not installed. */
    public static function localPdf(string $name): ?string
    {
        if ($name !== 'p2-dejavu' || ! is_file(self::DEJAVU)) {
            return null;
        }
        $body = 'The renderer draws this page from the outlines of an embedded TrueType font, glyph by glyph, '
            .'with nothing but PHP. Each word is placed by the writer and each glyph is filled by exact coverage. '
            .'Quick brown foxes jump over lazy dogs; sphinx of black quartz, judge my vow. 0123456789 (and) [some] {marks}: '
            .'"quotes", apostrophes\' and dashes - all of them; Greek αβγδ and Cyrillic жизнь. ';
        $blocks = [
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Rendering embedded text', 'font' => 'DejaVu Sans']]],
        ];
        for ($i = 0; $i < 7; $i++) {
            $blocks[] = ['type' => 'paragraph', 'runs' => [['text' => $body, 'font' => 'DejaVu Sans', 'size' => $i % 2 === 0 ? 10 : 9]]];
        }
        $blocks[] = ['type' => 'paragraph', 'runs' => [['text' => 'Large: Rag 36', 'font' => 'DejaVu Sans', 'size' => 36, 'bold' => false]]];

        return Agent::toBytes(['blocks' => $blocks], ['fonts' => ['DejaVu Sans' => self::DEJAVU]]);
    }

    /** The text fixtures' PDF bytes. */
    public static function textPdf(string $name): string
    {
        if ($name === 'p2-text') {
            $line = 'DOS AD xo ÄÉ ZQ WIW OD SAD ox Ä ZQ DOS ';
            $blocks = [
                ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'DOS ÄÉ ZQ', 'font' => 'Fp', 'size' => 30]]],
                ['type' => 'paragraph', 'runs' => [['text' => str_repeat($line, 3), 'font' => 'Fp', 'size' => 10]]],
                ['type' => 'paragraph', 'align' => 'justify', 'runs' => [['text' => str_repeat($line, 4), 'font' => 'Fp', 'size' => 12]]],
                ['type' => 'paragraph', 'runs' => [
                    ['text' => 'OD AD ', 'font' => 'Fp', 'size' => 20, 'color' => '#B00020'],
                    ['text' => 'ZQ xo', 'font' => 'Fp', 'size' => 20, 'color' => '#1A4FA0'],
                ]],
            ];

            return Agent::toBytes(['blocks' => $blocks], ['fonts' => ['Fp' => FontBuilder::outlined()]]);
        }

        if ($name === 'std14') {
            // The twelve standard text faces, none embedded: Chromium draws
            // them from its own substitutes (on Windows, Arial / Times New
            // Roman / Courier New); we draw them from resources/fonts.
            // WinAnsi text with accents, figures and punctuation; origins
            // on half points.
            $faces = ['Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique',
                'Times-Roman', 'Times-Bold', 'Times-Italic', 'Times-BoldItalic',
                'Courier', 'Courier-Bold', 'Courier-Oblique', 'Courier-BoldOblique'];
            $b = new PdfBuilder;
            $cat = $b->reserve();
            $tree = $b->reserve();
            $res = '';
            $content = '';
            foreach ($faces as $i => $face) {
                $f = $b->object('<< /Type /Font /Subtype /Type1 /BaseFont /'.$face.' /Encoding /WinAnsiEncoding >>');
                $res .= '/F'.$i.' '.$f.' 0 R ';
                $y = 750 - 60 * $i;
                $content .= 'BT /F'.$i.' 22 Tf 30 '.$y.' Td '.PdfBuilder::lit("Quick brown fox \xC4\xC9 0123 & (W?)").' Tj ET'."\n";
                $content .= 'BT /F'.$i.' 11 Tf 30.5 '.($y - 22).' Td '.PdfBuilder::lit('Sphinx of black quartz, judge my vow. $9.99 - 50%').' Tj ET'."\n";
            }
            $c = $b->stream($content);
            $page = $b->object('<< /Type /Page /Parent '.$tree.' 0 R /Contents '.$c.' 0 R /Resources << /Font << '.$res.'>> >> >>');
            $b->object('<< /Type /Pages /Kids ['.$page.' 0 R] /Count 1 /MediaBox [0 0 612 792] >>', $tree);
            $b->object('<< /Type /Catalog /Pages '.$tree.' 0 R >>', $cat);

            return $b->build();
        }

        // 'glyphs': written here, origins on half points.
        $outlined = FontBuilder::outlined();
        $square = FontBuilder::make();
        $b = new PdfBuilder;
        $cat = $b->reserve();
        $tree = $b->reserve();
        $simple = function (string $program, string $name, string $extra) use ($b): int {
            $ff = $b->stream($program, '/Length1 '.strlen($program));
            $d = $b->object('<< /Type /FontDescriptor /FontName /'.$name.' /Flags 32 /FontBBox [-200 -300 1200 1000] /ItalicAngle 0 /Ascent 800 /Descent -200 /CapHeight 700 /StemV 80 /FontFile2 '.$ff.' 0 R >>');

            return $b->object('<< /Type /Font /Subtype /TrueType /BaseFont /'.$name.' /Encoding /WinAnsiEncoding /FontDescriptor '.$d.' 0 R '.$extra.' >>');
        };
        $s = $simple($outlined, 'FpOutline-Regular', '/FirstChar 32 /LastChar 201 /Widths ['.self::widths().']');
        $q = $simple($square, 'FpTest-Regular', '');
        $ff = $b->stream($outlined, '/Length1 '.strlen($outlined));
        $d = $b->object('<< /Type /FontDescriptor /FontName /FpOutline-Regular /Flags 4 /FontBBox [-200 -300 1200 1000] /ItalicAngle 0 /Ascent 800 /Descent -200 /CapHeight 700 /StemV 80 /FontFile2 '.$ff.' 0 R >>');
        $cid = $b->object('<< /Type /Font /Subtype /CIDFontType2 /BaseFont /FpOutline-Regular /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor '.$d.' 0 R /DW 1000 /W [9 [700 700 750 700]] /CIDToGIDMap /Identity >>');
        $c = $b->object('<< /Type /Font /Subtype /Type0 /BaseFont /FpOutline-Regular /Encoding /Identity-H /DescendantFonts ['.$cid.' 0 R] >>');
        $content = "BT /S 20 Tf 10 170 Td (DOSAo) Tj ET\n"
            ."BT /C 20 Tf 10 140 Td <0009000A000B000C> Tj ET\n"
            ."BT /S 20 Tf 1 0 0.3 1 120 140 Tm (A) Tj ET\n"
            ."0.8 0 0 rg 0 0 0.7 RG 1 w BT /S 30 Tf 10 105 Td 2 Tr (OD) Tj 1 Tr (A) Tj 0 Tr ET\n"
            ."0 g BT /S 20 Tf 50 Tz 2 Tc 110 105 Td (xWx) Tj ET\n"
            ."0 0.5 0 rg BT /S 20 Tf 0 1 -1 0 190 20 Tm (DOS) Tj ET\n"
            ."0 g BT /S 20 Tf 10 70 Td [(I) -500 (W) 250 (I)] TJ 5 Ts (o) Tj ET\n"
            ."BT /Q 50 Tf 10 8 Td (HIW) Tj ET\n";
        $cs = $b->stream($content);
        $page = $b->object('<< /Type /Page /Parent '.$tree.' 0 R /Contents '.$cs.' 0 R /Resources << /Font << /S '.$s.' 0 R /C '.$c.' 0 R /Q '.$q.' 0 R >> >> >>');
        $b->object('<< /Type /Pages /Kids ['.$page.' 0 R] /Count 1 /MediaBox [0 0 200 200] >>', $tree);
        $b->object('<< /Type /Catalog /Pages '.$tree.' 0 R >>', $cat);

        return $b->build();
    }

    /** /Widths for FontBuilder::outlined() from code 32 to 201 (WinAnsi), 0 where unmapped. */
    private static function widths(): string
    {
        $adv = [0x20 => 250, 0x4F => 700, 0x44 => 700, 0x41 => 700, 0x53 => 600, 0x6F => 500, 0x78 => 600,
            0xC4 => 700, 0xC9 => 700, 0x5A => 750, 0x51 => 700, 0x49 => 500, 0x57 => 900];
        $out = [];
        for ($c = 32; $c <= 201; $c++) {
            $out[] = $adv[$c] ?? 0;
        }

        return implode(' ', $out);
    }

    /** The fixture's PDF bytes. */
    public static function pdf(string $name): string
    {
        if (isset(self::TEXT_SCALES[$name])) {
            return self::textPdf($name);
        }
        $f = self::all()[$name];
        $b = PdfBuilder::textPdf([$f['content']], [
            'mediaBox' => $f['mediaBox'],
            'page' => $f['page'] ?? '',
            'resources' => $f['resources'] ?? '',
        ]);
        foreach ($f['forms'] ?? [] as $form) {
            $b->stream($form['content'], '/Type /XObject /Subtype /Form '.$form['dict']);
        }

        return $b->build();
    }

    /** Displayed size in points (after /Rotate and the CropBox). @return array{0: float, 1: float} */
    public static function displaySize(string $name): array
    {
        if ($name === 'glyphs') {
            return [200.0, 200.0];
        }
        if (isset(self::TEXT_SCALES[$name]) || isset(self::LOCAL_SCALES[$name])) {
            return [612.0, 792.0];
        }
        $f = self::all()[$name];
        $box = $f['mediaBox'];
        if (preg_match('/CropBox \[([^\]]+)\]/', $f['page'] ?? '', $m)) {
            $box = '['.$m[1].']';
        }
        $n = array_map('floatval', preg_split('/\s+/', trim($box, '[] ')));
        $w = $n[2] - $n[0];
        $h = $n[3] - $n[1];
        if (preg_match('/Rotate (\d+)/', $f['page'] ?? '', $m) && ((int) $m[1]) % 180 === 90) {
            [$w, $h] = [$h, $w];
        }

        return [$w, $h];
    }

    /** An asymmetric page (arrow + corner marks) with /Rotate. */
    private static function rotated(int $deg): array
    {
        return [
            'mediaBox' => '[0 0 200 128]',
            'page' => '/Rotate '.$deg,
            'content' => "1 0 0 rg 5 95 20 20 re f\n"
                ."0 0 1 rg 175 5 20 20 re f\n"
                ."0 0.5 0 rg 40 50 m 120 50 l 120 35 l 160 60 l 120 85 l 120 70 l 40 70 l h f\n"
                ."0 G 3 w 30 20 m 30 100 l S\n",
        ];
    }

    private static function circle(float $cx, float $cy, float $r, float $k): string
    {
        return self::ellipse($cx, $cy, $r, $r, $k);
    }

    private static function ellipse(float $cx, float $cy, float $rx, float $ry, float $k): string
    {
        $kx = $k * $rx;
        $ky = $k * $ry;
        $f = fn (float ...$v) => implode(' ', array_map(fn ($x) => rtrim(rtrim(sprintf('%.4F', $x), '0'), '.'), $v));

        return $f($cx + $rx, $cy).' m '
            .$f($cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry).' c '
            .$f($cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy).' c '
            .$f($cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry).' c '
            .$f($cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy).' c h';
    }

    private static function star(float $cx, float $cy, float $r): string
    {
        $out = '';
        for ($i = 0; $i < 5; $i++) {
            $a = M_PI / 2 + $i * 4 * M_PI / 5;
            $out .= sprintf('%.3F %.3F %s ', $cx + $r * cos($a), $cy + $r * sin($a), $i === 0 ? 'm' : 'l');
        }

        return $out.'h';
    }
}
