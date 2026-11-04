<?php

declare(strict_types=1);

use FinePrint\Agent;
use FinePrint\Exceptions\SchemaException;

/*
 * `pageBreak.page`: the vocabulary. What it may hold, what is refused and how
 * near-misses are repaired. Geometry itself is in MixedPagesTest.
 */

function fpoBreak(array $page): array
{
    return ['type' => 'pageBreak', 'page' => $page];
}

/** @return list<string> */
function fpoPaths(array $doc): array
{
    return array_column(Agent::validate($doc), 'path');
}

it('accepts a page break carrying page settings, including an empty set', function (array $page) {
    expect(Agent::validate(['blocks' => [fpwPara('A'), fpoBreak($page), fpwPara('B')]]))->toBe([]);
})->with([
    'landscape' => [['orientation' => 'landscape']],
    'margins' => [['margins' => ['left' => 28.8, 'right' => 28.8]]],
    'distances' => [['margins' => ['header' => 14, 'footer' => 14]]],
    'bands' => [['header' => ['left' => 'H'], 'firstFooter' => ['blocks' => []], 'evenHeader' => ['right' => 'E']]],
    'border' => [['border' => ['style' => 'single', 'width' => 1]]],
    'size' => [['size' => 'a4']],
    'empty' => [[]],
]);

it('refuses page.background on a break: Word has one page colour per document', function () {
    $errors = Agent::validate(['blocks' => [fpoBreak(['background' => '#FDFAF3'])]]);

    expect(array_column($errors, 'path'))->toBe(['blocks[0].page.background'])
        ->and($errors[0]['message'])->toContain('one page colour');
});

it('refuses page on a break nested in a quote or a table cell', function (array $doc, string $path) {
    expect(fpoPaths($doc))->toBe([$path]);
})->with([
    'quote' => [['blocks' => [['type' => 'quote', 'blocks' => [fpoBreak(['orientation' => 'landscape'])]]]], 'blocks[0].blocks[0].page'],
    'cell' => [['blocks' => [['type' => 'table', 'rows' => [['cells' => [['blocks' => [fpoBreak([])]]]]]]]], 'blocks[0].rows[0].cells[0].blocks[0].page'],
]);

it('checks a break\'s page with the page object\'s own rules, at its own path', function (array $page, string $path) {
    expect(fpoPaths(['blocks' => [fpoBreak($page)]]))->toContain($path);
})->with([
    'orientation' => [['orientation' => 'sideways'], 'blocks[0].page.orientation'],
    'size' => [['size' => 'a3'], 'blocks[0].page.size'],
    'margin' => [['margins' => ['left' => 'wide']], 'blocks[0].page.margins.left'],
    'distance' => [['margins' => ['header' => -1]], 'blocks[0].page.margins.header'],
    'band' => [['footer' => 'text'], 'blocks[0].page.footer'],
    'border' => [['border' => ['style' => 'wavy']], 'blocks[0].page.border.style'],
]);

it('refuses a page value that is not an object', function () {
    expect(fpoPaths(['blocks' => [['type' => 'pageBreak', 'page' => 'landscape']]]))->toBe(['blocks[0].page'])
        ->and(fpoPaths(['blocks' => [['type' => 'pageBreak', 'page' => [1, 2]]]]))->toBe(['blocks[0].page']);
});

it('judges margins against the size they inherit, not the portrait default', function () {
    // 300 + 300 fits a landscape letter page's 792 across and leaves 192. Going
    // back to portrait, inheriting them, leaves only 12.
    $doc = ['page' => ['orientation' => 'landscape', 'margins' => ['left' => 300, 'right' => 300]], 'blocks' => [fpoBreak(['orientation' => 'portrait'])]];

    expect(Agent::validate(['page' => $doc['page'], 'blocks' => []]))->toBe([])
        ->and(fpoPaths($doc))->toBe(['blocks[0].page.margins']);
});

it('errors on a near-miss, naming page', function (string $key, mixed $value) {
    $errors = Agent::validate(['blocks' => [['type' => 'pageBreak', $key => $value]]]);

    expect(array_column($errors, 'path'))->toBe(["blocks[0].{$key}"])
        ->and($errors[0]['message'])->toBe("a pageBreak has no {$key} key; use pageBreak.page");
})->with([
    ['orientation', 'landscape'],
    ['margins', ['left' => 10]],
    ['size', 'a4'],
    ['header', ['left' => 'x']],
    ['footer', ['left' => 'x']],
    ['firstFooter', ['left' => 'x']],
    ['evenHeader', ['left' => 'x']],
    ['border', ['width' => 1]],
    ['background', '#FFFFFF'],
]);

it('errors on a top-level sections key, and repair does not pretend otherwise', function () {
    $doc = ['sections' => [['blocks' => [fpwPara('Lost?')]]], 'blocks' => []];
    $errors = Agent::validate($doc);

    expect($errors)->toBe([['path' => 'sections', 'message' => 'there is no sections key; use pageBreak.page']]);

    $r = Agent::validateAndRepair($doc);
    expect($r['ok'])->toBeFalse()
        ->and(array_column($r['errors'], 'path'))->toContain('sections');
});

it('still tolerates an unknown key on a page break', function () {
    expect(Agent::validate(['blocks' => [['type' => 'pageBreak', 'note' => 'mine']]]))->toBe([]);
});

it('repair keeps page when an unrelated repair runs', function () {
    // A bare string block forces the repair path; the break must survive it.
    $r = Agent::validateAndRepair(['blocks' => ['coerced', fpoBreak(['orientation' => 'landscape']), fpwPara('B')]]);

    expect($r['ok'])->toBeTrue()
        ->and($r['schema']['blocks'][1])->toBe(['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']]);
});

it('repair keeps an empty page, so a new stretch survives', function () {
    $r = Agent::validateAndRepair(['blocks' => ['coerced', fpoBreak([])]]);

    expect($r['ok'])->toBeTrue()
        ->and($r['schema']['blocks'][1])->toHaveKey('page');
});

it('repair moves near-miss keys into page and says so', function () {
    $r = Agent::validateAndRepair(['blocks' => [['type' => 'pageBreak', 'orientation' => 'landscape', 'margins' => ['left' => 30]]]]);

    expect($r['ok'])->toBeTrue()
        ->and($r['schema']['blocks'][0])->toBe(['type' => 'pageBreak', 'page' => ['orientation' => 'landscape', 'margins' => ['left' => 30]]])
        ->and(array_column($r['errors'], 'path'))->toBe(['blocks[0].orientation', 'blocks[0].margins'])
        ->and($r['errors'][0]['message'])->toContain('moved');
});

it('repair lets page win over a near-miss of the same key', function () {
    $r = Agent::validateAndRepair(['blocks' => [['type' => 'pageBreak', 'orientation' => 'portrait', 'page' => ['orientation' => 'landscape']]]]);

    expect($r['ok'])->toBeTrue()
        ->and($r['schema']['blocks'][0]['page'])->toBe(['orientation' => 'landscape'])
        ->and($r['errors'][0]['message'])->toContain('page already says');
});

it('repair drops a background on a break, and a page on a nested break, with a note', function () {
    $bg = Agent::validateAndRepair(['blocks' => [fpoBreak(['background' => '#FDFAF3', 'orientation' => 'landscape'])]]);
    $nested = Agent::validateAndRepair(['blocks' => [['type' => 'quote', 'blocks' => [fpoBreak(['orientation' => 'landscape'])]]]]);

    expect($bg['ok'])->toBeTrue()
        ->and($bg['schema']['blocks'][0]['page'])->toBe(['orientation' => 'landscape'])
        ->and(array_column($bg['errors'], 'path'))->toBe(['blocks[0].page.background']);
    expect($nested['ok'])->toBeTrue()
        ->and($nested['schema']['blocks'][0]['blocks'][0])->toBe(['type' => 'pageBreak'])
        ->and(array_column($nested['errors'], 'path'))->toBe(['blocks[0].blocks[0].page']);
});

it('repairs a band on a break as it does a band on the page', function () {
    $r = Agent::validateAndRepair(['blocks' => [fpoBreak(['footer' => ['blocks' => ['logo line', ['type' => 'pageBreak']]]])]]);

    expect($r['ok'])->toBeTrue()
        ->and($r['schema']['blocks'][0]['page']['footer']['blocks'])->toBe([['type' => 'paragraph', 'runs' => [['text' => 'logo line']]]]);
});

it('describes the stretches and what a document carries beyond one page shape', function () {
    $text = Agent::describe(['page' => ['firstFooter' => ['blocks' => []]], 'blocks' => [
        fpwPara('A'),
        fpoBreak(['orientation' => 'landscape']),
        ['type' => 'paragraph', 'runs' => [['text' => 'B'], ['text' => '', 'footnote' => ['blocks' => [fpwPara('note words here')]]]]],
        fpoBreak(['orientation' => 'portrait']),
    ]]);

    expect($text)->toContain('Page: letter portrait, firstFooter')
        ->toContain('Stretches: 3 (portrait, landscape, portrait)')
        ->toContain('Footnotes: 1');
});

it('does not change a description of a document with one page shape', function () {
    $text = Agent::describe(['blocks' => [fpwPara('A'), ['type' => 'pageBreak'], fpwPara('B')]]);

    expect($text)->not->toContain('Stretches')->not->toContain('Footnotes');
});

it('throws through the write path on a refused break', function () {
    expect(fn () => Agent::toBytes(['blocks' => [fpoBreak(['background' => '#000000'])]]))->toThrow(SchemaException::class);
});

it('declares the vocabulary in the JSON Schema', function () {
    $s = Agent::jsonSchema();
    $blocks = [];
    foreach ($s['$defs']['block']['oneOf'] as $b) {
        $blocks[$b['properties']['type']['const']] = $b;
    }
    $page = $blocks['pageBreak']['properties']['page'];

    expect($page['additionalProperties'])->toBeFalse()
        ->and(array_keys($page['properties']))->toBe(['size', 'orientation', 'margins', 'header', 'footer', 'firstHeader', 'firstFooter', 'evenHeader', 'evenFooter', 'border'])
        ->and(array_keys($s['properties']['page']['properties']))->toBe(['size', 'orientation', 'margins', 'header', 'footer', 'firstHeader', 'firstFooter', 'evenHeader', 'evenFooter', 'background', 'border'])
        ->and(array_keys($s['$defs']['pageMargins']['properties']))->toBe(['top', 'right', 'bottom', 'left', 'header', 'footer']);
    foreach (['table', 'code', 'image', 'hr'] as $type) {
        expect($blocks[$type]['properties'])->toHaveKey('keepNext');
    }
    expect($s['$defs']['run']['properties'])->toHaveKey('footnote')
        ->and($s['$defs'])->toHaveKey('blocksBand')
        ->and($s['properties'])->toHaveKey('meta');
});
