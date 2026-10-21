<?php

declare(strict_types=1);

use FinePrint\Document;
use FinePrint\Tests\Support\PdfBuilder;

/*
 * Structure: header, the three cross-reference shapes, incremental updates,
 * the page tree and what it inherits.
 */

it('reads a classic cross-reference table', function () {
    $doc = Document::open(fpText('one', 'two', 'three'));

    expect($doc->pageCount())->toBe(3)
        ->and($doc->version())->toBe('1.7')
        ->and($doc->repaired())->toBeFalse();
});

it('reads a cross-reference stream', function () {
    $pdf = PdfBuilder::textPdf([PdfBuilder::line('streamed')])->build(['xref' => 'stream']);
    $doc = Document::open($pdf);

    expect($doc->pageCount())->toBe(1)
        ->and($doc->text(1))->toBe('streamed')
        ->and($doc->repaired())->toBeFalse();
});

it('reads a cross-reference stream with a PNG predictor', function () {
    $pdf = PdfBuilder::textPdf([PdfBuilder::line('predicted')])->build(['xref' => 'stream', 'predictor' => true]);

    expect(Document::open($pdf)->text(1))->toBe('predicted');
});

it('reads objects out of an object stream', function () {
    $pdf = PdfBuilder::textPdf([PdfBuilder::line('compressed objects')])->build(['objstm' => true]);
    $doc = Document::open($pdf);

    expect($doc->pageCount())->toBe(1)
        ->and($doc->text(1))->toBe('compressed objects')
        ->and($doc->repaired())->toBeFalse();
});

it('follows an incremental update, newest object winning', function () {
    $b = PdfBuilder::textPdf([PdfBuilder::line('original text')], [], $pages);
    $pdf = $b->build();

    // Object 3 is the font; the first page's content stream is object 4.
    $content = PdfBuilder::line('revised text');
    $updated = PdfBuilder::appendUpdate($pdf, [
        4 => '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream",
    ], 6);
    $doc = Document::open($updated);

    expect($doc->text(1))->toBe('revised text')
        ->and($doc->repaired())->toBeFalse();
});

it('tolerates junk before the header, as viewers do', function () {
    $pdf = "garbage-from-a-mail-gateway\r\n".fpText('still readable');

    expect(Document::open($pdf)->text(1))->toBe('still readable');
});

it('inherits MediaBox and Rotate down the page tree, and honours CropBox', function () {
    $pdf = PdfBuilder::textPdf(
        [PdfBuilder::line('a'), PdfBuilder::line('b')],
        ['mediaBox' => '[0 0 595 842]'],
    );
    $b = new PdfBuilder();
    // Hand-built: page 2 overrides, page 1 inherits.
    $b->object('<< /Type /Catalog /Pages 2 0 R >>');
    $b->object('<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 /MediaBox [0 0 595 842] /Rotate 90 >>');
    $b->object('<< /Type /Page /Parent 2 0 R >>');
    $b->object('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /CropBox [10 10 310 410] /Rotate 0 >>');
    $pages = Document::open($b->build())->pages();

    expect($pages[0])->toMatchArray(['number' => 1, 'width' => 842.0, 'height' => 595.0, 'rotate' => 90])
        ->and($pages[1])->toMatchArray(['number' => 2, 'width' => 300.0, 'height' => 400.0, 'rotate' => 0])
        ->and($pages[1]['mediaBox'])->toBe([0.0, 0.0, 612.0, 792.0]);
});

it('walks a nested page tree in order', function () {
    $b = new PdfBuilder();
    $b->object('<< /Type /Catalog /Pages 2 0 R >>');
    $b->object('<< /Type /Pages /Kids [3 0 R 6 0 R] /Count 3 /MediaBox [0 0 100 100] >>');
    $b->object('<< /Type /Pages /Parent 2 0 R /Kids [4 0 R 5 0 R] /Count 2 >>');
    $b->object('<< /Type /Page /Parent 3 0 R /UserUnit 1 /Contents 7 0 R /Resources << /Font << /F1 8 0 R >> >> >>');
    $b->object('<< /Type /Page /Parent 3 0 R /Contents 9 0 R /Resources << /Font << /F1 8 0 R >> >> >>');
    $b->object('<< /Type /Page /Parent 2 0 R /Contents 10 0 R /Resources << /Font << /F1 8 0 R >> >> >>');
    $b->stream(PdfBuilder::line('first', 10, 50));
    $b->object('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
    $b->stream(PdfBuilder::line('second', 10, 50));
    $b->stream(PdfBuilder::line('third', 10, 50));
    $doc = Document::open($b->build());

    expect($doc->pageCount())->toBe(3)
        ->and([$doc->text(1), $doc->text(2), $doc->text(3)])->toBe(['first', 'second', 'third']);
});
