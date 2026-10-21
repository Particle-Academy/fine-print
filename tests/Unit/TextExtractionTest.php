<?php

declare(strict_types=1);

use FinePrint\Document;
use FinePrint\Tests\Support\PdfBuilder;

/*
 * Text in READING order, not content-stream order, decoded through the
 * font's ToUnicode or its encoding.
 */

it('orders lines top to bottom regardless of drawing order', function () {
    $content = PdfBuilder::line('third line', 72, 680)
        .PdfBuilder::line('first line', 72, 720)
        .PdfBuilder::line('second line', 72, 700);
    $doc = Document::open(PdfBuilder::textPdf([$content])->build());

    expect($doc->text(1))->toBe("first line\nsecond line\nthird line");
});

it('orders pieces of one line left to right and spaces them by their gap', function () {
    $content = PdfBuilder::line('world', 120, 700).PdfBuilder::line('Hello', 72, 700);

    expect(Document::open(PdfBuilder::textPdf([$content])->build())->text(1))->toBe('Hello world');
});

it('separates paragraphs by a blank line when the vertical gap is large', function () {
    $content = PdfBuilder::line('para one', 72, 720).PdfBuilder::line('para two', 72, 660);

    expect(Document::open(PdfBuilder::textPdf([$content])->build())->text(1))->toBe("para one\n\npara two");
});

it('reads TJ arrays, turning a wide kerning gap into a space', function () {
    $content = "BT /F1 12 Tf 72 700 Td [(Kern) -40 (ing) -900 (works)] TJ ET\n";

    expect(Document::open(PdfBuilder::textPdf([$content])->build())->text(1))->toBe('Kerning works');
});

it('follows the text-positioning operators', function () {
    $content = "BT /F1 12 Tf 14 TL 72 720 Td (line one) Tj T* (line two) Tj (again) ' 0 -14 Td (line four) Tj ET\n";
    $doc = Document::open(PdfBuilder::textPdf([$content])->build());

    expect($doc->text(1))->toBe("line one\nline two\nagain\nline four");
});

it('honours the text matrix and the CTM', function () {
    $content = "q 1 0 0 1 0 -100 cm BT /F1 12 Tf 1 0 0 1 72 700 Tm (lower) Tj ET Q\n"
        ."BT /F1 12 Tf 1 0 0 1 72 700 Tm (upper) Tj ET\n";

    expect(Document::open(PdfBuilder::textPdf([$content])->build())->text(1))->toBe("upper\n\nlower");
});

it('decodes WinAnsi bytes beyond ASCII', function () {
    // 0xE9 is e-acute, 0x93/0x94 are curly double quotes, 0x80 is the euro sign.
    $content = "BT /F1 12 Tf 72 700 Td (caf\\351 \\223q\\224 \\200) Tj ET\n";

    expect(Document::open(PdfBuilder::textPdf([$content])->build())->text(1))->toBe("caf\u{E9} \u{201C}q\u{201D} \u{20AC}");
});

it('applies an encoding Differences array through glyph names', function () {
    $b = new PdfBuilder();
    $b->object('<< /Type /Catalog /Pages 2 0 R >>');
    $b->object('<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 612 792] >>');
    $b->object('<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>');
    $b->stream("BT /F1 12 Tf 72 700 Td (\\001\\002\\003AB) Tj ET\n");
    $b->object('<< /Type /Font /Subtype /Type1 /BaseFont /Custom /Encoding << /Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences [1 /fi /Eacute /uni2014 66 /bullet] >> >>');

    expect(Document::open($b->build())->text(1))->toBe("\u{FB01}\u{C9}\u{2014}A\u{2022}");
});

it('decodes a two-byte Type0 font through its ToUnicode CMap', function () {
    $cmap = "begincmap\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
        ."2 beginbfchar\n<0003> <0048>\n<0004> <0069>\nendbfchar\n"
        ."1 beginbfrange\n<0010> <0012> [<00E9> <4E2D> <D83DDE00>]\nendbfrange\nendcmap\n";
    $b = new PdfBuilder();
    $b->object('<< /Type /Catalog /Pages 2 0 R >>');
    $b->object('<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 612 792] >>');
    $b->object('<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>');
    $b->stream("BT /F1 12 Tf 72 700 Td <000300040010001100120003> Tj ET\n");
    $b->object('<< /Type /Font /Subtype /Type0 /BaseFont /Embedded /Encoding /Identity-H /DescendantFonts [6 0 R] /ToUnicode 7 0 R >>');
    $b->object('<< /Type /Font /Subtype /CIDFontType2 /BaseFont /Embedded /DW 600 /W [3 [700 300]] >>');
    $b->stream($cmap);

    expect(Document::open($b->build())->text(1))->toBe("Hi\u{E9}\u{4E2D}\u{1F600}H");
});

it('reads text inside a form XObject', function () {
    $b = PdfBuilder::textPdf(
        ["BT /F1 12 Tf 72 720 Td (outside) Tj ET\nq 1 0 0 1 0 -40 cm /Fm1 Do Q\n"],
        ['resources' => '/XObject << /Fm1 6 0 R >>'],
    );
    $form = $b->stream(PdfBuilder::line('inside the form', 72, 720), '/Type /XObject /Subtype /Form /BBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >>');
    expect($form)->toBe(6);

    expect(Document::open($b->build())->text(1))->toBe("outside\n\ninside the form");
});

it('skips an inline image without losing the text after it', function () {
    $content = "q 10 0 0 10 72 600 cm BI /W 2 /H 2 /CS /G /BPC 8 ID \x00\xFF EI\xFF\x00 EI Q\n".PdfBuilder::line('after the image');

    expect(Document::open(PdfBuilder::textPdf([$content])->build())->text(1))->toBe('after the image');
});

it('returns an empty string for a page with no content', function () {
    $b = new PdfBuilder();
    $b->object('<< /Type /Catalog /Pages 2 0 R >>');
    $b->object('<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 612 792] >>');
    $b->object('<< /Type /Page /Parent 2 0 R >>');

    expect(Document::open($b->build())->text(1))->toBe('');
});

it('reads Flate-compressed content streams', function () {
    $pdf = PdfBuilder::textPdf([PdfBuilder::line('deflated words')], ['flate' => true])->build();

    expect(Document::open($pdf)->text(1))->toBe('deflated words');
});
