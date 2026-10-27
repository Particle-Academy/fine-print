<?php

declare(strict_types=1);

use FinePrint\Agent;
use FinePrint\Exceptions\SchemaException;
use FinePrint\Tests\Support\ImageBuilder;

/*
 * The JSON document model: last-word's, so an agent that can write a docx can
 * write a PDF with the same JSON. Validation names a path and says what to do;
 * repair fixes what it safely can and reports every change.
 */

function fpPng(): string
{
    return ImageBuilder::dataUrl(ImageBuilder::png(4, 3, 2, 8, fn ($x, $y) => [$x * 60, $y * 80, 200]), 'image/png');
}

function fpErrorPaths(array $doc): array
{
    return array_column(Agent::validate($doc), 'path');
}

it('accepts a last-word document as it is', function () {
    $doc = [
        'title' => 'Q3 memo',
        'page' => ['size' => 'letter', 'orientation' => 'portrait', 'margins' => ['top' => 72, 'bottom' => 72, 'left' => 72, 'right' => 72],
            'footer' => ['left' => 'Q3 | Confidential', 'right' => 'Page {page} of {pages}']],
        'defaultFont' => 'Helvetica',
        'defaultSize' => 11,
        'blocks' => [
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Summary', 'color' => '#1F3A5F']], 'keepNext' => true],
            ['type' => 'paragraph', 'runs' => [['text' => 'Revenue rose ', 'bold' => true], ['text' => 'see ', 'italic' => true], ['text' => 'site', 'link' => 'https://example.com/q3']],
                'align' => 'justify', 'shading' => '#EEF2F7', 'borders' => ['left' => ['style' => 'single', 'width' => 4, 'color' => '#1F3A5F']]],
            ['type' => 'list', 'ordered' => true, 'items' => [['runs' => [['text' => 'One']], 'children' => [['runs' => [['text' => 'One.a']]]]]]],
            ['type' => 'table', 'widths' => [30, 70], 'cellPadding' => ['top' => 4, 'bottom' => 4, 'left' => 6, 'right' => 6],
                'rows' => [
                    ['header' => true, 'cells' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Region']]]], 'shading' => '#1F3A5F']]],
                    ['cells' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'EMEA']]]], 'colSpan' => 2]]],
                ]],
            ['type' => 'code', 'language' => 'php', 'text' => "echo 1;\necho 2;"],
            ['type' => 'quote', 'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Quoted']]]]],
            ['type' => 'image', 'src' => fpPng(), 'widthPx' => 40, 'alt' => 'a chart'],
            ['type' => 'pageBreak'],
            ['type' => 'hr'],
        ],
    ];

    expect(Agent::validate($doc))->toBe([]);
});

it('names the path and the fix for each error', function () {
    $errors = Agent::validate(['blocks' => [['type' => 'heading', 'level' => 9, 'runs' => []]]]);

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['path'])->toBe('blocks[0].level')
        ->and($errors[0]['message'])->toContain('1-6');
});

it('requires blocks', function () {
    expect(fpErrorPaths([]))->toBe(['blocks']);
});

it('rejects unknown block types, and says a chart is not drawn in PDF yet', function () {
    $errors = Agent::validate(['blocks' => [['type' => 'video'], ['type' => 'chart', 'option' => ['series' => [['data' => [1]]]]]]]);

    expect($errors[0]['path'])->toBe('blocks[0].type')
        ->and($errors[1]['path'])->toBe('blocks[1].type')
        ->and($errors[1]['message'])->toContain('not drawn in a PDF yet');
});

it('only allows http, https and mailto links', function (string $link, bool $ok) {
    $errors = Agent::validate(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'x', 'link' => $link]]]]]);

    expect($errors === [])->toBe($ok);
    if (! $ok) {
        expect($errors[0]['path'])->toBe('blocks[0].runs[0].link');
    }
})->with([
    ['https://example.com/a?b=c', true],
    ['http://example.com', true],
    ['mailto:someone@example.com', true],
    ['javascript:alert(1)', false],
    ['JavaScript:alert(1)', false],
    [' javascript:alert(1)', false],
    ['file:///etc/passwd', false],
    ['data:text/html;base64,PHNjcmlwdD4=', false],
    ['launch:calc.exe', false],
    ['//example.com', false],
    ["https://example.com/\x01", false],
]);

it('refuses text too small to see, which a reader would flag as hidden', function () {
    expect(fpErrorPaths(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'x', 'size' => 0.5]]]]]))
        ->toBe(['blocks[0].runs[0].size']);
});

it('refuses margins that leave no room for text', function () {
    expect(fpErrorPaths(['page' => ['margins' => ['left' => 300, 'right' => 300]], 'blocks' => []]))
        ->toBe(['page.margins']);
});

it('checks that an image can actually be embedded', function () {
    $garbage = 'data:image/png;base64,'.base64_encode('not a png');
    $errors = Agent::validate(['blocks' => [
        ['type' => 'image', 'src' => 'https://example.com/a.png'],
        ['type' => 'image', 'src' => $garbage],
        ['type' => 'image', 'src' => ImageBuilder::dataUrl(ImageBuilder::jpeg(16, 8), 'image/jpeg')],
    ]]);

    expect(array_column($errors, 'path'))->toBe(['blocks[0].src', 'blocks[1].src']);
});

it('repairs near-miss agent output and reports every change', function () {
    $result = Agent::validateAndRepair(['blocks' => [
        'A bare string',
        ['type' => 'paragraph', 'text' => 'Shorthand'],
        ['type' => 'heading', 'level' => '2', 'text' => 'Heading'],
        ['type' => 'paragraph', 'runs' => [['text' => 'click me', 'link' => 'javascript:alert(1)']]],
        ['type' => 'chart', 'option' => ['series' => [['data' => [1, 2]]]]],
        ['type' => 'paragraph', 'runs' => [['text' => 'tiny', 'size' => 0.2]]],
    ]]);

    expect($result['ok'])->toBeTrue()
        ->and(Agent::validate($result['schema']))->toBe([])
        ->and($result['schema']['blocks'])->toHaveCount(5)
        ->and($result['schema']['blocks'][0])->toBe(['type' => 'paragraph', 'runs' => [['text' => 'A bare string']]])
        ->and($result['schema']['blocks'][2]['level'])->toBe(2)
        ->and($result['schema']['blocks'][3]['runs'][0])->toBe(['text' => 'click me'])
        ->and($result['schema']['blocks'][4]['runs'][0]['size'])->toBe(1);

    $messages = implode("\n", array_column($result['errors'], 'message'));
    expect($messages)->toContain('link')
        ->and($messages)->toContain('chart');
});

it('passes a valid document through validateAndRepair untouched', function () {
    $doc = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Fine']]]]];

    expect(Agent::validateAndRepair($doc))->toBe(['ok' => true, 'schema' => $doc, 'errors' => []]);
});

it('refuses to write an invalid document, pointing at validateAndRepair', function () {
    expect(fn () => Agent::toBytes(['blocks' => [['type' => 'nope']]]))
        ->toThrow(SchemaException::class, 'validateAndRepair');

    try {
        Agent::toBytes(['blocks' => [['type' => 'nope']]]);
    } catch (SchemaException $e) {
        expect($e->errors[0]['path'])->toBe('blocks[0].type');
    }
});

it('exports a JSON schema for tool registration, without a chart block', function () {
    $schema = Agent::jsonSchema();
    $types = array_map(fn (array $b) => $b['properties']['type']['const'], $schema['$defs']['block']['oneOf']);

    expect($schema['required'])->toBe(['blocks'])
        ->and($types)->toBe(['heading', 'paragraph', 'list', 'table', 'code', 'quote', 'image', 'pageBreak', 'hr'])
        ->and($schema['$defs']['run']['properties']['link']['description'])->toContain('mailto')
        ->and(json_encode($schema))->toBeString();
});

it('refuses a link with a trailing line feed: the pattern is anchored at the true end', function () {
    // PCRE's `$` also matches before a final "\n". Without the D modifier,
    // "https://example.com" plus a newline passed linkAllowed() and reached
    // the PDF's /URI. The spec's pattern is printable ASCII only, end to end.
    $doc = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'x', 'link' => "https://example.com\n"]]]]];

    expect(FinePrint\Writer\Schema::linkAllowed("https://example.com\n"))->toBeFalse()
        ->and(Agent::validate($doc)[0]['path'] ?? null)->toBe('blocks[0].runs[0].link');
});
