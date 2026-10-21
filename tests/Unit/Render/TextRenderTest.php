<?php

declare(strict_types=1);

use FinePrint\Render\Renderer;
use FinePrint\Render\Rendering;
use FinePrint\Tests\Support\FontBuilder;
use FinePrint\Tests\Support\PdfBuilder;

/*
 * Text drawn from embedded TrueType fonts (P3b), end to end: text operators,
 * the text matrix through the CTM, glyph selection (simple fonts through
 * their encoding and cmap; Type0 through the CMap and CIDToGIDMap), render
 * modes, and the honest `skipped` report for what is not drawn.
 *
 * The test font's glyphs are squares and an L so pixels can be predicted
 * exactly: 'I' fills its whole em (1000 x 1000 units), so at 10 pt it is a
 * 10 x 10 point square sitting on the origin. Pages are 100 x 100 at scale 1.
 */


// --- drawing and positioning ------------------------------------------------------

it('draws a glyph from an embedded TrueType font where the text matrix puts it', function () {
    $r = fptRender('BT /T1 10 Tf 20 30 Td (I) Tj ET');

    expect(fptAt($r, 25, 35))->toBe(FPT_K)
        ->and(fptAt($r, 20.5, 30.5))->toBe(FPT_K)
        ->and(fptAt($r, 29.5, 39.5))->toBe(FPT_K)
        ->and(fptAt($r, 19.5, 35))->toBe(FPT_W)
        ->and(fptAt($r, 30.5, 35))->toBe(FPT_W)
        ->and(fptAt($r, 25, 40.5))->toBe(FPT_W)
        ->and($r->skipped)->toBe([])
        ->and($r->complete())->toBeTrue();
});

it('paints text in the fill colour', function () {
    $r = fptRender('1 0 0 rg BT /T1 10 Tf 20 30 Td (I) Tj ET');

    expect(fptAt($r, 25, 35))->toBe([255, 0, 0]);
});

it('advances by the glyph width after each glyph', function () {
    $r = fptRender('BT /T1 10 Tf 10 50 Td (I.I) Tj ET');

    // I at 10..20, the dot at 20..22 (advance 5), the next I at 25..35.
    expect(fptAt($r, 21, 51))->toBe(FPT_K)
        ->and(fptAt($r, 23.5, 55))->toBe(FPT_W)
        ->and(fptAt($r, 26, 55))->toBe(FPT_K)
        ->and(fptAt($r, 35.5, 55))->toBe(FPT_W);
});

it('uses /Widths over the font program\'s own advances, and the program where /Widths is silent', function () {
    $r = fptRender('BT /T1 10 Tf 10 50 Td (IIL) Tj ET', fptSimple(extra: '/Encoding /WinAnsiEncoding /FirstChar 73 /LastChar 73 /Widths [2000]'));

    // I advances 20 (Widths), so the second I is at 30..40; L (no entry) follows at 50.
    expect(fptAt($r, 25, 55))->toBe(FPT_W)
        ->and(fptAt($r, 31, 55))->toBe(FPT_K)
        ->and(fptAt($r, 51, 58))->toBe(FPT_K)
        ->and(fptAt($r, 45, 55))->toBe(FPT_W);
});

it('moves to the next line with Td from the line start, not the current point', function () {
    $r = fptRender('BT /T1 10 Tf 10 80 Td (II) Tj 0 -20 Td (I) Tj ET');

    expect(fptAt($r, 15, 65))->toBe(FPT_K)
        ->and(fptAt($r, 25, 65))->toBe(FPT_W);
});

it('sets the leading with TD and moves by it with T*', function () {
    $r = fptRender('BT /T1 10 Tf 10 80 Td 0 -20 TD (I) Tj T* (I) Tj ET');

    expect(fptAt($r, 15, 65))->toBe(FPT_K)
        ->and(fptAt($r, 15, 45))->toBe(FPT_K)
        ->and(fptAt($r, 15, 85))->toBe(FPT_W);
});

it('moves to the next line and shows with \' and with ", which also sets word and character spacing', function () {
    $q = fptRender("BT /T1 10 Tf 15 TL 10 80 Td (I) Tj (I) ' ET");
    // aw = 5, ac = 2: I at 10, space at 22 (10 + 2), next I at 22 + 5 + 2 + 5 = 34.
    $dq = fptRender('BT /T1 10 Tf 15 TL 10 80 Td 5 2 (I I) " ET');

    expect(fptAt($q, 15, 70))->toBe(FPT_K)
        ->and(fptAt($dq, 15, 70))->toBe(FPT_K)
        ->and(fptAt($dq, 33.5, 70))->toBe(FPT_W)
        ->and(fptAt($dq, 34.5, 70))->toBe(FPT_K)
        ->and(fptAt($dq, 15, 85))->toBe(FPT_W);
});

it('adds character spacing after every glyph', function () {
    $r = fptRender('BT /T1 10 Tf 3 Tc 10 50 Td (II) Tj ET');

    expect(fptAt($r, 21.5, 55))->toBe(FPT_W)
        ->and(fptAt($r, 23.5, 55))->toBe(FPT_K);
});

it('adds word spacing only after a single-byte code 32', function () {
    $simple = fptRender('BT /T1 10 Tf 10 Tw 10 50 Td (I I) Tj ET');
    // Two-byte codes never get word spacing, even <0020>... here CID 3 is the space glyph.
    $cid = fptRender('BT /T1 10 Tf 10 Tw 10 50 Td <000100030001> Tj ET', fptType0());

    // simple: I 10..20, space 5 + 10, I at 35. cid: I 10..20, space 10 (DW 1000), I at 30.
    expect(fptAt($simple, 33.5, 55))->toBe(FPT_W)
        ->and(fptAt($simple, 35.5, 55))->toBe(FPT_K)
        ->and(fptAt($cid, 30.5, 55))->toBe(FPT_K)
        ->and(fptAt($cid, 29.5, 55))->toBe(FPT_W);
});

it('scales horizontally with Tz', function () {
    $r = fptRender('BT /T1 10 Tf 50 Tz 10 50 Td (II) Tj ET');

    expect(fptAt($r, 17, 55))->toBe(FPT_K)
        ->and(fptAt($r, 19.5, 55))->toBe(FPT_K)
        ->and(fptAt($r, 20.5, 55))->toBe(FPT_W);
});

it('raises text with Ts', function () {
    $r = fptRender('BT /T1 10 Tf 5 Ts 10 50 Td (I) Tj ET');

    expect(fptAt($r, 15, 54.5))->toBe(FPT_W)
        ->and(fptAt($r, 15, 55.5))->toBe(FPT_K)
        ->and(fptAt($r, 15, 64.5))->toBe(FPT_K)
        ->and(fptAt($r, 15, 65.5))->toBe(FPT_W);
});

it('moves by TJ adjustments, in thousandths of the font size, negative to the right', function () {
    $r = fptRender('BT /T1 10 Tf 10 50 Td [(I) -500 (I) 1000 (I)] TJ ET');

    // I 10..20, then +5: I 25..35, then -10: I 25..35 again (drawn on top).
    expect(fptAt($r, 22.5, 55))->toBe(FPT_W)
        ->and(fptAt($r, 25.5, 55))->toBe(FPT_K)
        ->and(fptAt($r, 35.5, 55))->toBe(FPT_W);
});

it('sets the text matrix with Tm, and the text matrix is reset by BT', function () {
    $r = fptRender('BT /T1 10 Tf 2 0 0 2 10 10 Tm (I) Tj ET BT 50 50 Td ET BT /T1 10 Tf (I) Tj ET');

    expect(fptAt($r, 29.5, 29.5))->toBe(FPT_K)
        ->and(fptAt($r, 30.5, 20))->toBe(FPT_W)
        ->and(fptAt($r, 5, 5))->toBe(FPT_K)
        ->and(fptAt($r, 55, 55))->toBe(FPT_W);
});

it('turns glyphs with the text matrix, keeping their handedness', function () {
    // L turned 90 degrees anticlockwise about (50, 50): its foot (along x)
    // becomes a bar going up at x 48..50; its stem a bar going left at y 50..52.
    $r = fptRender('BT /T1 10 Tf 0 1 -1 0 50 50 Tm (L) Tj ET');

    expect(fptAt($r, 49, 58))->toBe(FPT_K)
        ->and(fptAt($r, 42, 51))->toBe(FPT_K)
        ->and(fptAt($r, 42, 58))->toBe(FPT_W)
        ->and(fptAt($r, 51, 58))->toBe(FPT_W);
});

it('mirrors a glyph under a mirrored matrix, and fills it either way round', function () {
    $r = fptRender('BT /T1 10 Tf -1 0 0 1 60 50 Tm (L) Tj ET');

    // Stem now at x 58..60, foot spans 50..60.
    expect(fptAt($r, 59, 58))->toBe(FPT_K)
        ->and(fptAt($r, 51, 51))->toBe(FPT_K)
        ->and(fptAt($r, 51, 58))->toBe(FPT_W);
});

it('maps text space through the CTM', function () {
    $r = fptRender('2 0 0 2 0 0 cm BT /T1 10 Tf 5 5 Td (I) Tj ET');

    expect(fptAt($r, 10.5, 10.5))->toBe(FPT_K)
        ->and(fptAt($r, 29.5, 29.5))->toBe(FPT_K)
        ->and(fptAt($r, 30.5, 20))->toBe(FPT_W);
});

it('saves and restores the text state with q and Q', function () {
    $r = fptRender('BT /T1 10 Tf 0 Tc q /T1 20 Tf 9 Tc Q 10 50 Td (II) Tj ET');

    expect(fptAt($r, 15, 55))->toBe(FPT_K)
        ->and(fptAt($r, 15, 65))->toBe(FPT_W)
        ->and(fptAt($r, 20.5, 55))->toBe(FPT_K);
});

it('anti-aliases a glyph edge that falls inside a pixel', function () {
    $r = fptRender('BT /T1 10 Tf 20.5 30 Td (I) Tj ET');
    $p = fptAt($r, 20.5, 35);

    expect($p[0])->toBeGreaterThan(110)->toBeLessThan(145);
});

it('draws the same text at scale 2, twice the size', function () {
    $r = fptRender('BT /T1 10 Tf 20 30 Td (I) Tj ET', scale: 2.0);

    expect($r->pixel(41, 200 - 61))->toBe(FPT_K)
        ->and($r->pixel(59, 200 - 79))->toBe(FPT_K)
        ->and($r->pixel(61, 200 - 70))->toBe(FPT_W);
});

// --- render modes ---------------------------------------------------------------

it('strokes the outline in mode 1, leaving the inside unpainted', function () {
    $r = fptRender('0 0 1 RG 2 w BT /T1 40 Tf 1 Tr 10 10 Td (I) Tj ET');

    expect(fptAt($r, 10, 30))->toBe([0, 0, 255])
        ->and(fptAt($r, 30, 30))->toBe(FPT_W)
        ->and($r->skipped)->toBe([]);
});

it('fills then strokes in mode 2', function () {
    $r = fptRender('1 0 0 rg 0 0 1 RG 4 w BT /T1 40 Tf 2 Tr 10 10 Td (I) Tj ET');

    expect(fptAt($r, 30, 30))->toBe([255, 0, 0])
        ->and(fptAt($r, 10.5, 30))->toBe([0, 0, 255])
        ->and(fptAt($r, 8.5, 30))->toBe([0, 0, 255]);
});

it('draws nothing in mode 3 (invisible), and that is not a skip', function () {
    $r = fptRender('BT /T1 40 Tf 3 Tr 10 10 Td (I) Tj ET');

    expect(fptAt($r, 30, 30))->toBe(FPT_W)
        ->and($r->skipped)->toBe([]);
});

it('paints in the clipping modes 4-6 and reports the clip as skipped; draws nothing in mode 7', function () {
    $four = fptRender('BT /T1 40 Tf 4 Tr 10 10 Td (I) Tj (I) Tj ET');
    $five = fptRender('2 w BT /T1 40 Tf 5 Tr 10 10 Td (I) Tj ET');
    $six = fptRender('2 w BT /T1 40 Tf 6 Tr 10 10 Td (I) Tj ET');
    $seven = fptRender('BT /T1 40 Tf 7 Tr 10 10 Td (I) Tj ET');

    expect(fptAt($four, 30, 30))->toBe(FPT_K)
        ->and(fptSkipped($four))->toBe(['clip' => 2])
        ->and(fptAt($five, 30, 30))->toBe(FPT_W)
        ->and(fptAt($five, 10, 30))->toBe(FPT_K)
        ->and(fptSkipped($five))->toBe(['clip' => 1])
        ->and(fptAt($six, 30, 30))->toBe(FPT_K)
        ->and(fptSkipped($six))->toBe(['clip' => 1])
        ->and(fptAt($seven, 30, 30))->toBe(FPT_W)
        ->and(fptSkipped($seven))->toBe(['clip' => 1]);
});

// --- glyph selection: simple fonts --------------------------------------------------

it('selects glyphs in a non-symbolic font through its encoding and the (3,1) cmap', function () {
    // WinAnsi 0xC4 is Adieresis, U+00C4, which the cmap maps to the square.
    $r = fptRender("BT /T1 10 Tf 10 50 Td <C4> Tj ET");

    expect(fptAt($r, 19, 59))->toBe(FPT_K);
});

it('applies /Differences before the cmap', function () {
    $r = fptRender('BT /T1 10 Tf 10 50 Td (A) Tj ET', fptSimple(extra: '/Encoding << /BaseEncoding /WinAnsiEncoding /Differences [65 /L] >>'));

    // An L: stem filled, the space above the foot empty.
    expect(fptAt($r, 11, 58))->toBe(FPT_K)
        ->and(fptAt($r, 18, 58))->toBe(FPT_W);
});

it('selects glyphs in a symbolic font through the (3,0) cmap, by code and in the F0xx range', function () {
    $font = fptFont([[3, 0, 4, [0xF049 => FPT_L, 0x4C => FPT_SQUARE]]]);
    $r = fptRender('BT /T1 10 Tf 10 50 Td (IL) Tj ET', fptSimple($font, '', 4));

    // I (0x49) finds F049, the L; L (0x4C) finds 4C directly, the square.
    expect(fptAt($r, 18, 58))->toBe(FPT_W)
        ->and(fptAt($r, 11, 58))->toBe(FPT_K)
        ->and(fptAt($r, 28, 58))->toBe(FPT_K);
});

it('selects glyphs through a Macintosh (1,0) cmap when that is all the font has', function () {
    $font = fptFont([[1, 0, 0, [0x49 => FPT_L, 0x80 => FPT_SQUARE]]]);
    $plain = fptRender('BT /T1 10 Tf 10 50 Td (I) Tj ET', fptSimple($font, '', 4));
    // Non-symbolic with WinAnsi: 0xC4 is Adieresis, which is 0x80 in Mac OS Roman.
    $named = fptRender('BT /T1 10 Tf 10 50 Td <C4> Tj ET', fptSimple($font));

    expect(fptAt($plain, 18, 58))->toBe(FPT_W)
        ->and(fptAt($plain, 11, 58))->toBe(FPT_K)
        ->and(fptAt($named, 18, 58))->toBe(FPT_K);
});

it('draws .notdef (glyph 0) for a code the font cannot map', function () {
    $r = fptRender('BT /T1 10 Tf 10 50 Td (Z) Tj ET');

    // .notdef is the half-em square.
    expect(fptAt($r, 14, 54))->toBe(FPT_K)
        ->and(fptAt($r, 16, 56))->toBe(FPT_W);
});

// --- glyph selection: Type0 -------------------------------------------------------

it('draws a Type0 font with Identity-H, two bytes a glyph', function () {
    $r = fptRender('BT /T1 10 Tf 10 50 Td <00010004> Tj ET', fptType0());

    expect(fptAt($r, 15, 55))->toBe(FPT_K)
        ->and(fptAt($r, 21, 58))->toBe(FPT_K)
        ->and(fptAt($r, 28, 58))->toBe(FPT_W)
        ->and($r->skipped)->toBe([]);
});

it('maps CIDs to glyphs through a CIDToGIDMap stream', function () {
    $font = fptType0(cidExtra: '', extraObjects: function (PdfBuilder $b, string $enc, string $cid) {
        // CID 0..5: gid 0, 0, 0, 0, 0, then CID 5 -> gid 4 (the L).
        $map = $b->stream(pack('n*', 0, 0, 0, 0, 0, FPT_L));

        return [$enc, '/CIDToGIDMap '.$map.' 0 R'];
    });
    $r = fptRender('BT /T1 10 Tf 10 50 Td <0005> Tj ET', $font);

    expect(fptAt($r, 11, 58))->toBe(FPT_K)
        ->and(fptAt($r, 18, 58))->toBe(FPT_W);
});

it('reads widths for CIDs from /W', function () {
    $r = fptRender('BT /T1 10 Tf 10 50 Td <00010001> Tj ET', fptType0(cidExtra: '/CIDToGIDMap /Identity /W [1 [2000]]'));

    expect(fptAt($r, 25, 55))->toBe(FPT_W)
        ->and(fptAt($r, 31, 55))->toBe(FPT_K);
});

it('maps codes to CIDs through an embedded CMap with cidrange and cidchar', function () {
    $font = fptType0(extraObjects: function (PdfBuilder $b, string $enc, string $cid) {
        $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            ."/CMapName /FpTest def\n1 begincodespacerange\n<00> <FF>\nendcodespacerange\n"
            ."1 begincidrange\n<41> <42> 1\nendcidrange\n"
            ."1 begincidchar\n<4C> 4\nendcidchar\n"
            ."endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";

        return [$b->stream($cmap, '/Type /CMap /CMapName /FpTest').' 0 R', $cid];
    });
    // A -> CID 1 (square), B -> CID 2 (dot), L -> CID 4.
    $r = fptRender('BT /T1 10 Tf 10 50 Td (ABL) Tj ET', $font);

    expect(fptAt($r, 15, 55))->toBe(FPT_K)
        ->and(fptAt($r, 21, 51))->toBe(FPT_K)
        ->and(fptAt($r, 23, 55))->toBe(FPT_W)
        ->and(fptAt($r, 31, 58))->toBe(FPT_K)
        ->and(fptAt($r, 38, 58))->toBe(FPT_W);
});

// --- what is not drawn, and is said -------------------------------------------------

it('does not draw text in a font with no embedded program, and names the font', function () {
    $pdf = PdfBuilder::textPdf([PdfBuilder::line('Hello', 10, 50).PdfBuilder::line('again', 10, 30).'BT /F1 9 Tf [(a) 5 (b)] TJ (c) \' ET'], ['mediaBox' => '[0 0 100 100]'])->build();
    $r = Renderer::render($pdf);

    expect($r->skipped)->toBe([['kind' => 'text-unembedded', 'count' => 4, 'fonts' => ['Helvetica']]])
        ->and($r->complete())->toBeFalse()
        ->and(fptAt($r, 12, 52))->toBe(FPT_W);
});

it('reports embedded CFF, Type 1 and Type 3 fonts as not drawn yet', function () {
    $cff = fptRender('BT /T1 10 Tf 10 50 Td (I) Tj ET', fptSimple('not really CFF', '', 32, 'FontFile3', '/Subtype /Type1C'));
    $t1 = fptRender('BT /T1 10 Tf 10 50 Td (I) Tj ET', fptSimple('%!PS-AdobeFont-1.0', '', 32, 'FontFile'));
    $t3 = fptRender('BT /T1 10 Tf 10 50 Td (I) Tj ET', fptPlain('<< /Type /Font /Subtype /Type3 /FontMatrix [0.001 0 0 0.001 0 0] /FontBBox [0 0 1000 1000] /CharProcs << >> /Encoding << /Differences [73 /I] >> /FirstChar 73 /LastChar 73 /Widths [1000] >>'));
    $otf = fptRender('BT /T1 10 Tf 10 50 Td (I) Tj ET', fptSimple('OTTO'.str_repeat("\0", 60), '', 32, 'FontFile3', '/Subtype /OpenType'));

    expect(fptSkipped($cff))->toBe(['text-cff' => 1])
        ->and(fptSkipped($t1))->toBe(['text-type1' => 1])
        ->and(fptSkipped($t3))->toBe(['text-type3' => 1])
        ->and(fptSkipped($otf))->toBe(['text-cff' => 1]);
});

it('draws an embedded TrueType program whatever the font dictionary calls itself', function () {
    // Some producers write /Subtype /Type1 over a FontFile2: the program decides.
    $r = fptRender('BT /T1 10 Tf 10 50 Td (I) Tj ET', function (PdfBuilder $b): int {
        $p = fptFont();
        $ff = $b->stream($p, '/Length1 '.strlen($p));
        $d = $b->object('<< /Type /FontDescriptor /FontName /FpText /Flags 32 /FontBBox [0 0 1000 1000] /ItalicAngle 0 /Ascent 1000 /Descent 0 /StemV 80 /FontFile2 '.$ff.' 0 R >>');

        return $b->object('<< /Type /Font /Subtype /Type1 /BaseFont /FpText /Encoding /WinAnsiEncoding /FontDescriptor '.$d.' 0 R >>');
    });

    expect(fptAt($r, 15, 55))->toBe(FPT_K)
        ->and($r->skipped)->toBe([]);
});

it('reports vertical writing and predefined CMaps it cannot map', function () {
    $v = fptRender('BT /T1 10 Tf 10 50 Td <0001> Tj ET', fptType0('/Identity-V'));
    $cmap = fptRender('BT /T1 10 Tf 10 50 Td <0001> Tj ET', fptType0('/UniJIS-UCS2-H'));

    expect(fptSkipped($v))->toBe(['text-vertical' => 1])
        ->and(fptSkipped($cmap))->toBe(['text-cmap' => 1])
        ->and(fptAt($v, 15, 55))->toBe(FPT_W);
});

it('reports a font it cannot read, and a font resource that is missing', function () {
    $bad = fptRender('BT /T1 10 Tf 10 50 Td (I) Tj ET', fptSimple('this is not a font at all'));
    $missing = fptRender('BT /T9 10 Tf 10 50 Td (I) Tj ET (I) Tj ET');
    $none = fptRender('BT 10 50 Td (I) Tj ET');

    expect(fptSkipped($bad))->toBe(['text-font-invalid' => 1])
        ->and(fptSkipped($missing))->toBe(['text-font-invalid' => 2])
        ->and(fptSkipped($none))->toBe(['text-font-invalid' => 1]);
});

it('skips a broken glyph, says so, and draws the rest of the line', function () {
    $sq = FontBuilder::simpleGlyph([[[0, 0], [0, 1000], [1000, 1000], [1000, 0]]]);
    $font = FontBuilder::build([
        ['data' => '', 'advance' => 1000, 'lsb' => 0],
        ['data' => $sq, 'advance' => 1000],
        ['data' => pack('n', 1).str_repeat("\0", 8).pack('n', 2).pack('n', 0xFFFF), 'advance' => 1000],
    ], [[3, 1, 4, [0x49 => 1, 0x58 => 2]]]);
    $r = fptRender('BT /T1 10 Tf 10 50 Td (XIX) Tj ET', fptSimple($font));

    expect(fptSkipped($r))->toBe(['text-glyph' => 2])
        ->and(fptAt($r, 25, 55))->toBe(FPT_K)
        ->and(fptAt($r, 15, 55))->toBe(FPT_W);
});

// --- the P2 writer's own output -------------------------------------------------------

it('draws what the P2 writer makes with an embedded font, completely', function () {
    $pdf = fpwWrite(['blocks' => [
        ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'DOS', 'font' => 'Fp']]],
        ['type' => 'paragraph', 'runs' => [['text' => 'OD AD xo', 'font' => 'Fp']]],
    ]], ['fonts' => ['Fp' => FontBuilder::outlined()]]);
    $r = Renderer::render($pdf, 1, 1.0);

    $ink = 0;
    foreach ($r->rows() as $row) {
        $ink += strlen($row) - substr_count($row, "\xFF") > 0 ? 1 : 0;
    }
    expect($r->skipped)->toBe([])
        ->and($r->complete())->toBeTrue()
        ->and($ink)->toBeGreaterThan(20);
});

it('reports the P2 writer\'s standard fonts as unembedded, by name', function () {
    $pdf = fpwWrite(['blocks' => [
        ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Title']]],
        fpwPara('Body text.'),
    ]]);
    $r = Renderer::render($pdf, 1, 1.0);

    expect($r->skipped)->toHaveCount(1)
        ->and($r->skipped[0]['kind'])->toBe('text-unembedded')
        ->and($r->skipped[0]['fonts'])->toBe(['Helvetica', 'Helvetica-Bold']);
});
