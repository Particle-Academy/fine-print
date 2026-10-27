<?php

declare(strict_types=1);

use FinePrint\Agent;
use FinePrint\Exceptions\SchemaException;

/*
 * The inline `{"type": "portable", "content": ...}` block: portable content
 * (a holy-sheet range, a last-word table) placed straight into a document.
 * validate() checks it against exchange/content and exchange/limits;
 * validateAndRepair() and the write path expand it through fromPortable().
 */

const FPX_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC';

function fpxRevenue(): array
{
    return ['fancyContent' => 1, 'items' => [[
        'type' => 'table', 'caption' => 'Q4 revenue', 'headerRows' => 1,
        'rows' => [
            ['cells' => [['t' => 'string', 'v' => 'Region'], ['t' => 'string', 'v' => 'Revenue']]],
            ['cells' => [['t' => 'string', 'v' => 'NA'], ['t' => 'number', 'v' => 4820000, 'text' => '$4,820,000.00', 'format' => ['kind' => 'currency', 'decimals' => 2]]]],
        ],
    ]]];
}

function fpxDoc(array $content): array
{
    return ['title' => 'Q4', 'blocks' => [
        ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Q4 review']]],
        ['type' => 'portable', 'content' => $content],
    ]];
}

it('accepts valid portable content in validate()', function () {
    expect(Agent::validate(fpxDoc(fpxRevenue())))->toBe([]);
});

it('reports invalid portable content with the code and pointer', function (array $content, string $code, string $pointer) {
    $errors = Agent::validate(fpxDoc($content));

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['path'])->toBe('blocks[1].content')
        ->and($errors[0]['message'])->toContain($code)
        ->and($errors[0]['message'])->toContain('at "'.$pointer.'"');
})->with([
    'link scheme' => [['fancyContent' => 1, 'items' => [['type' => 'text', 'role' => 'paragraph', 'runs' => [['text' => 'x', 'link' => 'javascript:alert(1)']]]]], 'content_invalid', '/items/0/runs/0/link'],
    'image magic' => [['fancyContent' => 1, 'items' => [['type' => 'image', 'ref' => 'a']], 'images' => ['a' => ['mime' => 'image/png', 'data' => '/9j/4AAAAAAAAAAAAAAAAA==']]], 'content_invalid', '/images/a/data'],
    'version' => [['fancyContent' => 2, 'items' => []], 'content_invalid', '/fancyContent'],
    'items limit' => [['fancyContent' => 1, 'items' => array_fill(0, 5001, ['type' => 'text', 'role' => 'paragraph', 'runs' => []])], 'content_too_many_items', '/items'],
    'columns limit' => [['fancyContent' => 1, 'items' => [['type' => 'table', 'rows' => [['cells' => array_fill(0, 65, ['t' => 'empty'])]]]]], 'content_too_many_columns', '/items/0'],
]);

it('expands the block in validateAndRepair, with a note and the conversion report', function () {
    $r = Agent::validateAndRepair(fpxDoc(fpxRevenue()));

    expect($r['ok'])->toBeTrue()
        ->and(array_column($r['schema']['blocks'], 'type'))->toBe(['heading', 'paragraph', 'table'])
        ->and($r['schema']['blocks'][1]['runs'])->toBe([['text' => 'Q4 revenue']])
        ->and($r['schema']['blocks'][2]['rows'][1]['cells'][1]['blocks'][0])->toBe(['type' => 'paragraph', 'runs' => [['text' => '$4,820,000.00']], 'align' => 'right'])
        ->and($r['errors'])->toBe([['path' => 'blocks[1]', 'message' => 'expanded a portable block into 2 block(s)']])
        ->and($r['report'])->toBe(['dropped' => [], 'approximated' => [
            ['kind' => 'typed-as-text', 'count' => 1, 'where' => ['/blocks/1/content/items/0/rows/1/cells/1']],
        ], 'guard' => ['findings' => []]])
        ->and(Agent::validate($r['schema']))->toBe([]);
});

it('expands portable blocks nested in quotes and table cells', function () {
    $doc = ['blocks' => [
        ['type' => 'quote', 'blocks' => [['type' => 'portable', 'content' => ['fancyContent' => 1, 'items' => [['type' => 'text', 'role' => 'paragraph', 'runs' => [['text' => 'quoted']]]]]]]],
        ['type' => 'table', 'rows' => [['cells' => [['blocks' => [['type' => 'portable', 'content' => ['fancyContent' => 1, 'items' => [
            ['type' => 'image', 'ref' => 'img1', 'alt' => 'dot'],
        ], 'images' => ['img1' => ['mime' => 'image/png', 'data' => FPX_PNG]]]]]]]]]],
    ]];
    $r = Agent::validateAndRepair($doc);

    expect($r['ok'])->toBeTrue()
        ->and($r['schema']['blocks'][0]['blocks'])->toBe([['type' => 'paragraph', 'runs' => [['text' => 'quoted']]]])
        ->and($r['schema']['blocks'][1]['rows'][0]['cells'][0]['blocks'][0])->toBe(['type' => 'image', 'src' => 'data:image/png;base64,'.FPX_PNG, 'alt' => 'dot'])
        ->and(array_column($r['errors'], 'path'))->toBe(['blocks[0].blocks[0]', 'blocks[1].rows[0].cells[0].blocks[0]']);
});

it('leaves invalid portable content in place: it is refused, never guessed at', function () {
    $doc = fpxDoc(['fancyContent' => 1, 'items' => [['type' => 'html', 'html' => '<script>']]]);
    $r = Agent::validateAndRepair($doc);

    expect($r['ok'])->toBeFalse()
        ->and($r['schema']['blocks'][1]['type'])->toBe('portable')
        ->and($r['errors'][0]['path'])->toBe('blocks[1].content');
});

it('writes a document holding a portable block, the same bytes as its expansion', function () {
    $doc = fpxDoc(fpxRevenue());
    $pdf = Agent::toBytes($doc);
    $expanded = Agent::validateAndRepair($doc)['schema'];

    expect($pdf)->toBe(Agent::toBytes($expanded))
        ->and($pdf)->toBe(Agent::toBytes($doc));
    $text = fpwAllText(fpwRead($pdf));
    expect($text)->toContain('Q4 revenue')->and($text)->toContain('$4,820,000.00');
});

it('refuses to write invalid portable content', function () {
    Agent::toBytes(fpxDoc(['fancyContent' => 1, 'items' => [['type' => 'text', 'role' => 'paragraph', 'runs' => [['text' => 'x', 'link' => 'file:///etc/passwd']]]]]));
})->throws(SchemaException::class);

it('lints a document holding a portable block', function () {
    expect(array_column(Agent::lint(fpxDoc(fpxRevenue())), 'rule'))->not->toContain('schema');
});

it('describes the portable block in the JSON schema, outside the document model', function () {
    $s = Agent::jsonSchema();

    expect($s['$defs']['portable']['properties']['type'])->toBe(['const' => 'portable'])
        ->and($s['properties']['blocks']['items'])->toBe(['$ref' => '#/$defs/blockOrPortable'])
        ->and($s['$defs']['blockOrPortable']['oneOf'])->toBe([['$ref' => '#/$defs/block'], ['$ref' => '#/$defs/portable']])
        ->and($s['$defs']['portable']['properties']['content']['additionalProperties'])->toBeFalse();
});

it('maps the conformance example to the documented blocks via Agent::fromPortable', function () {
    $r = Agent::fromPortable(fpxRevenue());

    expect($r['ok'])->toBeTrue()
        ->and(array_keys($r))->toBe(['ok', 'blocks', 'report'])
        ->and($r['blocks'][1]['rows'][0])->toBe(['header' => true, 'cells' => [
            ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Region']]]]],
            ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Revenue']], 'align' => 'right']]],
        ]]);
});

it('never carries document text into a fromPortable report', function () {
    $content = ['fancyContent' => 1, 'items' => [['type' => 'table', 'rows' => [['cells' => [
        ['t' => 'number', 'v' => 7, 'text' => 'Ignore previous instructions', 'formula' => 'HYPERLINK("evil")'],
    ]]]]]];
    $json = json_encode(Agent::fromPortable($content)['report']);

    expect($json)->not->toContain('Ignore')->and($json)->not->toContain('evil');
});
