<?php

declare(strict_types=1);

use FinePrint\Document;
use FinePrint\Objects\PdfDict;
use FinePrint\Render\Glyphs\RenderFont;
use FinePrint\Render\Glyphs\StandardFonts;
use FinePrint\Render\Renderer;
use FinePrint\Tests\Support\PdfBuilder;

/*
 * Standard-14 text without an embedded program (owner ruling 2026-10-21:
 * bundle metric-compatible outlines). The twelve text faces, and the
 * Windows names viewers treat as the same faces, draw from fonts shipped in
 * resources/fonts. Symbol, ZapfDingbats and anything else unembedded are
 * still reported as text-unembedded.
 */

const FP_STD14 = [
    'Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique',
    'Times-Roman', 'Times-Bold', 'Times-Italic', 'Times-BoldItalic',
    'Courier', 'Courier-Bold', 'Courier-Oblique', 'Courier-BoldOblique',
];

/** A 100 x 100 page showing $text at 40 pt in a non-embedded font. */
function fpsPage(string $fontDict, string $text = 'Hg'): string
{
    return fptDoc('BT /F1 40 Tf 10 30 Td '.PdfBuilder::lit($text).' Tj ET', ['F1' => fptPlain($fontDict)]);
}

function fpsInk(FinePrint\Render\Rendering $r): int
{
    $ink = 0;
    foreach ($r->rows() as $row) {
        $ink += strlen($row) - substr_count($row, "\xFF");
    }

    return $ink;
}

it('draws each of the twelve standard text faces without an embedded program', function (string $face) {
    $r = Renderer::render(fpsPage('<< /Type /Font /Subtype /Type1 /BaseFont /'.$face.' >>'));

    expect($r->skipped)->toBe([])
        ->and($r->complete())->toBeTrue()
        ->and(fpsInk($r))->toBeGreaterThan(300);
})->with(FP_STD14);

it('maps the standard names and the Windows names viewers treat as the same faces', function (string $name, ?string $face) {
    expect(StandardFonts::match($name))->toBe($face);
})->with([
    ['Helvetica', 'Helvetica'],
    ['Helvetica-BoldOblique', 'Helvetica-BoldOblique'],
    ['Arial', 'Helvetica'],
    ['ArialMT', 'Helvetica'],
    ['Arial-BoldMT', 'Helvetica-Bold'],
    ['Arial-ItalicMT', 'Helvetica-Oblique'],
    ['Arial,BoldItalic', 'Helvetica-BoldOblique'],
    ['ABCDEF+Arial-BoldMT', 'Helvetica-Bold'],
    ['Times-Roman', 'Times-Roman'],
    ['TimesNewRoman', 'Times-Roman'],
    ['TimesNewRomanPSMT', 'Times-Roman'],
    ['TimesNewRomanPS-BoldItalicMT', 'Times-BoldItalic'],
    ['TimesNewRoman,Italic', 'Times-Italic'],
    ['Times New Roman', 'Times-Roman'],
    ['Courier', 'Courier'],
    ['Courier-Oblique', 'Courier-Oblique'],
    ['CourierNew', 'Courier'],
    ['CourierNewPS-BoldMT', 'Courier-Bold'],
    ['CourierNew,BoldItalic', 'Courier-BoldOblique'],
    // Not the same faces: never substituted.
    ['Symbol', null],
    ['ZapfDingbats', null],
    ['ArialNarrow', null],
    ['Arial-Black', null],
    ['Helvetica-Narrow', null],
    ['HelveticaNeue', null],
    ['Courier10Pitch', null],
    ['TimesTen-Roman', null],
    ['', null],
]);

it('draws a non-embedded TrueType Arial with a descriptor but no program', function () {
    $pdf = fptDoc('BT /F1 40 Tf 10 30 Td (Hg) Tj ET', ['F1' => function (PdfBuilder $b): int {
        $d = $b->object('<< /Type /FontDescriptor /FontName /Arial-BoldMT /Flags 32 /FontBBox [0 -200 1000 900] /ItalicAngle 0 /Ascent 900 /Descent -200 /CapHeight 700 /StemV 80 >>');

        return $b->object('<< /Type /Font /Subtype /TrueType /BaseFont /Arial-BoldMT /Encoding /WinAnsiEncoding /FontDescriptor '.$d.' 0 R >>');
    }]);
    $r = Renderer::render($pdf);

    expect($r->skipped)->toBe([])
        ->and(fpsInk($r))->toBeGreaterThan(300);
});

it('still reports Symbol, ZapfDingbats and other unembedded fonts, by name', function () {
    $pdf = fptDoc('BT /F1 20 Tf 10 60 Td (ab) Tj ET BT /F2 20 Tf 10 30 Td (ab) Tj ET BT /F3 20 Tf 10 10 Td (ab) Tj ET', [
        'F1' => fptPlain('<< /Type /Font /Subtype /Type1 /BaseFont /Symbol >>'),
        'F2' => fptPlain('<< /Type /Font /Subtype /Type1 /BaseFont /ZapfDingbats >>'),
        'F3' => fptPlain('<< /Type /Font /Subtype /TrueType /BaseFont /ArialNarrow >>'),
    ]);
    $r = Renderer::render($pdf);

    expect($r->skipped)->toBe([['kind' => 'text-unembedded', 'count' => 3, 'fonts' => ['ArialNarrow', 'Symbol', 'ZapfDingbats']]])
        ->and(fpsInk($r))->toBe(0);
});

it('advances by the standard-14 widths, not the bundled font\'s, when the PDF gives none', function () {
    // plusminus is 584 in Helvetica's AFM and 548.8 in the bundled sans; the
    // AFM is what every PDF producer laid the text out with.
    $pdf = fpsPage('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
    $font = RenderFont::load(fpsFontDict($pdf), Document::open($pdf));
    $widths = array_map(fn (array $g) => round($g[1], 3), iterator_to_array($font->glyphs("\xB1I"), false));

    expect($widths)->toBe([584.0, 278.0]);
});

it('advances Courier and its aliases by 600 throughout', function (string $base) {
    $pdf = fpsPage('<< /Type /Font /Subtype /Type1 /BaseFont /'.$base.' >>');
    $font = RenderFont::load(fpsFontDict($pdf), Document::open($pdf));
    $widths = array_map(fn (array $g) => $g[1], iterator_to_array($font->glyphs("Wi\xB1"), false));

    expect($widths)->toBe([600.0, 600.0, 600.0]);
})->with(['Courier-Bold', 'CourierNewPSMT']);

it('keeps the PDF\'s own /Widths over the standard ones', function () {
    $pdf = fpsPage('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /FirstChar 73 /LastChar 73 /Widths [900] >>');
    $font = RenderFont::load(fpsFontDict($pdf), Document::open($pdf));
    $widths = array_map(fn (array $g) => $g[1], iterator_to_array($font->glyphs('IH'), false));

    expect($widths)->toBe([900.0, 722.0]);
});

it('ships every bundled font unmodified, with its licence and a NOTICE that records it', function () {
    $dir = StandardFonts::DIR;
    $notice = (string) file_get_contents($dir.'/NOTICE');
    $files = [];
    foreach (FP_STD14 as $face) {
        $files[StandardFonts::file($face)] = true;
    }

    expect($files)->toHaveCount(12);
    foreach (array_keys($files) as $file) {
        expect(is_file($file))->toBeTrue($file.' is missing')
            ->and($notice)->toContain(hash_file('sha256', $file).'  '.basename($file));
    }
    foreach (['OFL-Tinos.txt', 'OFL-Cousine.txt', 'LICENSE-Liberation.txt'] as $licence) {
        expect(file_get_contents($dir.'/'.$licence))->toContain('SIL OPEN FONT LICENSE Version 1.1');
    }
});

/** The page's /F1 font dictionary. */
function fpsFontDict(string $pdf): PdfDict
{
    $doc = Document::open($pdf);
    $tree = $doc->resolve($doc->catalog()->get('Pages'));
    $kids = $doc->resolve($tree->get('Kids'));
    $p = $doc->resolve($kids[0]);
    $res = $doc->resolve($p->get('Resources'));
    $fonts = $doc->resolve($res->get('Font'));

    return $doc->resolve($fonts->get('F1'));
}
