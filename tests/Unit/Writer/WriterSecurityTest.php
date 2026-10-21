<?php

declare(strict_types=1);

use FinePrint\Agent;
use FinePrint\Document;
use FinePrint\Exceptions\SchemaException;
use FinePrint\Tests\Support\FontBuilder;
use FinePrint\Writer\PdfWriter;

/*
 * The writer NEVER emits active content: no JavaScript, OpenAction,
 * additional actions, Launch, forms, embedded files, RichMedia, XFA or remote
 * go-tos, whatever the model asks for and however it asks. Each output is
 * checked twice: by P1's ActiveContentScanner, and by searching the
 * uncompressed bytes for the names themselves.
 */

const FPW_FORBIDDEN = ['/JavaScript', '/JS', '/OpenAction', '/AA', '/Launch', '/AcroForm', '/SubmitForm', '/ImportData',
    '/EmbeddedFile', '/RichMedia', '/XFA', '/GoToR', '/GoToE', '/Names', '/Widget', '/Sound', '/Movie', '/Rendition'];

function fpwAssertInert(string $pdf, array $allowedKinds = []): void
{
    $kinds = array_column(Document::open($pdf)->activeContent(), 'kind');
    expect(array_values(array_diff($kinds, $allowedKinds)))->toBe([]);
    foreach (FPW_FORBIDDEN as $name) {
        // A name ends at a delimiter or whitespace; /JS must not match /JSON-ish text.
        expect(preg_match('#'.preg_quote($name, '#').'(?=[\s/<>\[\]()]|$)#', $pdf))->toBe(0, "found {$name}");
    }
}

$hostileTitle = ') >> /OpenAction << /S /JavaScript /JS (app.alert(1)) >> /AA << >> (';

it('ignores every active-content key a model can invent', function () use ($hostileTitle) {
    $doc = [
        'title' => $hostileTitle,
        'openAction' => ['S' => 'JavaScript', 'JS' => 'app.alert(1)'],
        'OpenAction' => 'app.alert(1)',
        'javascript' => 'app.alert(1)',
        'actions' => [['type' => 'launch', 'file' => 'calc.exe']],
        'acroForm' => ['fields' => [['name' => 'x', 'submit' => 'https://example.com']]],
        'embeddedFiles' => [['name' => 'payload.exe', 'data' => 'TVqQ']],
        'names' => ['JavaScript' => ['app.alert(1)']],
        'page' => ['size' => 'letter'],
        'blocks' => [
            ['type' => 'paragraph', 'onClick' => 'app.alert(1)', 'action' => ['S' => 'Launch'], 'runs' => [
                ['text' => '/JavaScript (app.alert(1)) /OpenAction', 'onOpen' => 'x', 'js' => 'app.alert(1)'],
            ]],
            ['type' => 'heading', 'level' => 1, 'aa' => ['O' => 'x'], 'runs' => [['text' => 'endobj trailer << /Root 1 0 R >>']]],
        ],
    ];

    expect(Agent::validate($doc))->toBe([]);   // unknown keys are tolerated...
    $pdf = Agent::toBytes($doc, ['compress' => false]);
    fpwAssertInert($pdf);                       // ...and never written

    $read = fpwRead($pdf);
    expect($read['metadata']['Title'])->toBe($hostileTitle)   // the title is DATA, intact
        ->and($read['pages'][0]['text'])->toContain('/JavaScript (app.alert(1)) /OpenAction');
});

it('refuses script and file links, and repair removes them rather than rewriting them', function (string $link) {
    $doc = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'click', 'link' => $link]]]]];

    expect(fn () => Agent::toBytes($doc))->toThrow(SchemaException::class);

    $fixed = Agent::validateAndRepair($doc);
    expect($fixed['ok'])->toBeTrue()
        ->and($fixed['schema']['blocks'][0]['runs'][0])->toBe(['text' => 'click']);
    fpwAssertInert(Agent::toBytes($fixed['schema'], ['compress' => false]));
})->with([
    'javascript:alert(1)', 'JAVASCRIPT:alert(1)', "java\tscript:alert(1)", 'vbscript:msgbox(1)',
    'file:///C:/Windows/System32/calc.exe', 'data:text/html,<script>alert(1)</script>',
    '\\\\server\\share\\payload.exe', 'calc.exe', 'launch:calc', '#page=1',
]);

it('writes the links it does allow as URI actions and nothing else', function () {
    $pdf = Agent::toBytes(['blocks' => [['type' => 'paragraph', 'runs' => [
        ['text' => 'site', 'link' => 'https://example.com/a'], ['text' => ' and '], ['text' => 'mail', 'link' => 'mailto:a@example.com'],
    ]]]], ['compress' => false]);

    fpwAssertInert($pdf, ['uri']);
    $found = Document::open($pdf)->activeContent();
    expect(array_column($found, 'kind'))->toBe(['uri', 'uri'])
        ->and(fpwRead($pdf)['guard']['findings'])->toBe([]);
});

it('keeps a link out even when the writer is handed an unvalidated document', function () {
    // Defence in depth: the writer itself re-checks every link.
    $out = (new PdfWriter(['compress' => false]))->write(['blocks' => [['type' => 'paragraph', 'runs' => [
        ['text' => 'x', 'link' => 'javascript:app.alert(1)'],
    ]]]]);

    fpwAssertInert($out['bytes']);
    expect(Document::open($out['bytes'])->activeContent())->toBe([]);
});

it('cannot be steered by font names, in the model or in a host font file', function () {
    $evil = FontBuilder::make(postScriptName: 'Evil/JavaScript(app.alert(1))>>/OpenAction');
    $pdf = Agent::toBytes([
        'defaultFont' => 'Helvetica /JavaScript',
        'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Hello', 'font' => 'Evil'], ['text' => ' there', 'font' => '/OpenAction<<>>']]]],
    ], ['fonts' => ['Evil' => $evil], 'compress' => false]);

    fpwAssertInert($pdf);
    expect($pdf)->toContain('+EvilJavaScriptapp.alert1OpenAction');
});

it('writes no form fields, widgets or embedded files under any block type', function () {
    $blocks = [];
    foreach (['heading', 'paragraph', 'list', 'table', 'code', 'quote', 'image', 'pageBreak', 'hr'] as $type) {
        $blocks[] = match ($type) {
            'heading' => ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'H']]],
            'paragraph' => fpwPara('P'),
            'list' => ['type' => 'list', 'items' => [['runs' => [['text' => 'L']]]]],
            'table' => ['type' => 'table', 'rows' => [['cells' => [['blocks' => [fpwPara('T')]]]]]],
            'code' => ['type' => 'code', 'text' => 'C'],
            'quote' => ['type' => 'quote', 'blocks' => [fpwPara('Q')]],
            'image' => ['type' => 'image', 'src' => FinePrint\Tests\Support\ImageBuilder::dataUrl(FinePrint\Tests\Support\ImageBuilder::jpeg(8, 8), 'image/jpeg')],
            'pageBreak' => ['type' => 'pageBreak'],
            'hr' => ['type' => 'hr'],
        };
    }
    $pdf = Agent::toBytes(['blocks' => $blocks], ['compress' => false]);

    fpwAssertInert($pdf);
    $read = fpwRead($pdf);
    expect($read['annotations'])->toBe([])
        ->and($read['guard']['findings'])->toBe([]);
});
