<?php

declare(strict_types=1);

use FinePrint\Document;
use FinePrint\Tests\Support\PdfBuilder;

/*
 * Everything that is text but not page text: the Info dictionary, XMP, the
 * outline and annotation contents. These are where a human never looks, so
 * the guard reads them too — which means the reader must surface them.
 */

it('reads the Info dictionary in PDFDocEncoding and UTF-16', function () {
    $b = PdfBuilder::textPdf([PdfBuilder::line('x')]);
    $info = $b->object('<< /Title '.PdfBuilder::utf16('Quarterly r'."\u{E9}".'sum'."\u{E9}").' /Author (Ana \\251 Co) /Producer (fixture) /CreationDate (D:20261021120000Z) >>');
    $meta = Document::open($b->build(['info' => $info]))->metadata();

    expect($meta['Title'])->toBe("Quarterly r\u{E9}sum\u{E9}")
        ->and($meta['Author'])->toBe("Ana \u{A9} Co")
        ->and($meta['Producer'])->toBe('fixture')
        ->and($meta['CreationDate'])->toBe('D:20261021120000Z');
});

it('returns the XMP packet as text', function () {
    $xmp = '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta xmlns:x="adobe:ns:meta/"><dc:title>From XMP</dc:title></x:xmpmeta><?xpacket end="w"?>';
    // A one-page textPdf uses objects 1-5, so the next object is 6.
    $b = PdfBuilder::textPdf([PdfBuilder::line('x')], ['catalog' => '/Metadata 6 0 R']);
    expect($b->stream($xmp, '/Type /Metadata /Subtype /XML'))->toBe(6);

    expect(Document::open($b->build())->xmp())->toBe($xmp);
});

it('returns no XMP when there is none', function () {
    expect(Document::open(fpText('x'))->xmp())->toBeNull();
});

it('reads the outline with nesting and destination pages', function () {
    // A two-page textPdf uses objects 1-7, so the outline root is 8.
    $b = PdfBuilder::textPdf([PdfBuilder::line('p1'), PdfBuilder::line('p2')], ['catalog' => '/Outlines 8 0 R'], $pages);
    $outlines = $b->reserve();
    expect($outlines)->toBe(8);
    $first = $b->reserve();
    $second = $b->reserve();
    $child = $b->reserve();
    $b->object('<< /Type /Outlines /First '.$first.' 0 R /Last '.$second.' 0 R /Count 3 >>', $outlines);
    $b->object('<< /Title (Introduction) /Parent '.$outlines.' 0 R /Next '.$second.' 0 R /Dest ['.$pages[0].' 0 R /Fit] >>', $first);
    $b->object('<< /Title '.PdfBuilder::utf16('Chapter '."\u{2116}".'2').' /Parent '.$outlines.' 0 R /Prev '.$first.' 0 R /First '.$child.' 0 R /Last '.$child.' 0 R /A << /S /GoTo /D ['.$pages[1].' 0 R /XYZ 0 792 0] >> >>', $second);
    $b->object('<< /Title (Detail) /Parent '.$second.' 0 R >>', $child);
    expect(Document::open($b->build())->outline())->toBe([
        ['title' => 'Introduction', 'page' => 1, 'children' => []],
        ['title' => "Chapter \u{2116}2", 'page' => 2, 'children' => [
            ['title' => 'Detail', 'page' => null, 'children' => []],
        ]],
    ]);
});

it('reads annotation text per page', function () {
    $b = PdfBuilder::textPdf([PdfBuilder::line('x')], ['page' => '/Annots [6 0 R]']);
    $annot = $b->object('<< /Type /Annot /Subtype /Text /Rect [0 0 10 10] /Contents (Reviewer note) /T (Ana) >>');
    expect($annot)->toBe(6);

    expect(Document::open($b->build())->annotations(1))->toBe([
        ['subtype' => 'Text', 'object' => $annot.' 0', 'contents' => 'Reviewer note', 'author' => 'Ana'],
    ]);
});
