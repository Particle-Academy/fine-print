<?php

declare(strict_types=1);

use FinePrint\Agent;

/*
 * Agent::describe(): a compact plain-text summary of a document for the
 * model, the same idea as last-word's, without dumping the JSON back.
 */

it('summarises title, page, blocks, tables, images and words', function () {
    $doc = Agent::fromArray([['Region', 'Revenue'], ['NA', 1], ['EU', 2]], title: 'Q4 review');
    $doc['page'] = ['size' => 'a4', 'orientation' => 'landscape', 'footer' => ['right' => 'Page {page} of {pages}']];
    $doc['blocks'][] = ['type' => 'paragraph', 'runs' => [['text' => 'Three more words']]];

    expect(Agent::describe($doc))->toBe(implode("\n", [
        'Document: Q4 review',
        'Page: a4 landscape, footer',
        'Blocks: 2 (1 table, 1 paragraph)',
        'Tables: 3x2',
        'Words: 9',
    ]));
});

it('counts nested blocks, flags portable blocks to expand, and never fails on junk', function () {
    $doc = ['blocks' => [
        ['type' => 'quote', 'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'a b']]]]],
        ['type' => 'portable', 'content' => ['fancyContent' => 1, 'items' => []]],
        'junk',
    ]];

    expect(Agent::describe($doc))->toBe(implode("\n", [
        'Document: Untitled',
        'Page: letter portrait',
        'Blocks: 3 (1 quote, 1 portable, 1 unknown)',
        'Portable: 1 block(s), expanded by validateAndRepair',
        'Words: 2',
    ]));
});
