<?php

declare(strict_types=1);

/*
 * Block layout: lists, tables, code, quotes, rules, and the pagination rules
 * (keep-with-next, no orphaned or widowed lines, repeated header rows).
 */

function fpwCell(string $text, array $props = []): array
{
    return ['blocks' => [fpwPara($text)]] + $props;
}

/** Page text with blank lines collapsed: a 20pt table row reads back as a paragraph gap. */
function fpwLines(string $text): array
{
    return explode("\n", (string) preg_replace('/\n{2,}/', "\n", trim($text)));
}

it('draws bulleted and numbered lists, nested', function () {
    $read = fpwRead(fpwWrite(['blocks' => [
        ['type' => 'list', 'items' => [['runs' => [['text' => 'Apples']]], ['runs' => [['text' => 'Pears']]]]],
        ['type' => 'list', 'ordered' => true, 'items' => [
            ['runs' => [['text' => 'First']], 'children' => [['runs' => [['text' => 'Inner one']]], ['runs' => [['text' => 'Inner two']]]]],
            ['runs' => [['text' => 'Second']]],
        ]],
    ]]));

    expect(explode("\n", str_replace("\n\n", "\n", $read['pages'][0]['text'])))->toBe([
        '• Apples', '• Pears', '1. First', 'a. Inner one', 'b. Inner two', '2. Second',
    ]);
});

it('indents list items with a hanging marker', function () {
    $pdf = fpwWrite(['blocks' => [['type' => 'list', 'items' => [
        ['runs' => [['text' => 'Outer']], 'children' => [['runs' => [['text' => 'Inner']]]]],
    ]]]]);
    $x = [];
    foreach (fpwRuns($pdf) as $r) {
        $x[trim($r['text'])] = $r['x0'];
    }

    expect($x['Outer'])->toBe(72.0 + 36)
        ->and($x['Inner'])->toBe(72.0 + 72)
        ->and($x['•'])->toBeLessThan($x['Outer'])
        ->and($x['◦'] ?? $x['•'])->toBeLessThan($x['Inner']);
});

it('lays a table out in its columns, with widths as weights', function () {
    $pdf = fpwWrite(['blocks' => [['type' => 'table', 'widths' => [1, 3], 'rows' => [
        ['header' => true, 'cells' => [fpwCell('Region'), fpwCell('Revenue')]],
        ['cells' => [fpwCell('EMEA'), ['blocks' => [fpwPara('1,250', ['align' => 'right'])]]]],
    ]]]]);

    $x = [];
    foreach (fpwRuns($pdf) as $r) {
        $x[trim($r['text'])] = [$r['x0'], $r['x1']];
    }
    // Column 1 is 117pt wide (468 * 1/4), column 2 starts at 189.
    expect($x['Region'][0])->toBeGreaterThan(72)->toBeLessThan(72 + 117)
        ->and($x['EMEA'][1])->toBeLessThan(72 + 117)
        ->and($x['Revenue'][0])->toBeGreaterThan(189)
        ->and(abs($x['1,250'][1] - (540 - 5.4)))->toBeLessThan(0.05);

    $read = fpwRead($pdf);
    expect(fpwLines($read['pages'][0]['text']))->toBe(['Region Revenue', 'EMEA 1,250'])
        ->and($read['guard']['findings'])->toBe([]);
});

it('fills and bolds a header row, and keeps white header text visible to the guard', function () {
    $pdf = fpwWrite(['blocks' => [['type' => 'table', 'rows' => [
        ['header' => true, 'cells' => [fpwCell('Plain header'), ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'White on navy', 'color' => '#FFFFFF']]]], 'shading' => '#1F3A5F']]],
        ['cells' => [fpwCell('a'), fpwCell('b')]],
    ]]]], ['compress' => false]);

    expect($pdf)->toContain('/BaseFont /Helvetica-Bold ')
        ->and($pdf)->toContain('0.9059 0.9059 0.9059 rg');   // #E7E7E7

    $read = fpwRead($pdf);
    expect($read['guard']['findings'])->toBe([])
        ->and($read['pages'][0]['text'])->toContain('White on navy');
});

it('spans columns and rows', function () {
    $read = fpwRead(fpwWrite(['blocks' => [['type' => 'table', 'rows' => [
        ['cells' => [fpwCell('Wide', ['colSpan' => 2]), fpwCell('Tall', ['rowSpan' => 2])]],
        ['cells' => [fpwCell('a'), fpwCell('b')]],
    ]]]]));

    expect(fpwLines($read['pages'][0]['text']))->toBe(['Wide Tall', 'a b']);
});

it('repeats header rows on every page a table continues onto', function () {
    $rows = [['header' => true, 'cells' => [fpwCell('Item'), fpwCell('Value')]]];
    for ($i = 1; $i <= 90; $i++) {
        $rows[] = ['cells' => [fpwCell("row{$i}"), fpwCell((string) ($i * 10))]];
    }
    $read = fpwRead(fpwWrite(['blocks' => [['type' => 'table', 'rows' => $rows]]]));

    expect($read['pageCount'])->toBeGreaterThan(1);
    $seen = [];
    foreach ($read['pages'] as $page) {
        expect($page['text'])->toStartWith('Item Value');
        preg_match_all('/row(\d+)/', $page['text'], $m);
        array_push($seen, ...array_map('intval', $m[1]));
    }
    expect($seen)->toBe(range(1, 90));
});

it('keeps a heading with the paragraph after it', function () {
    // Each one-line paragraph takes 14.256pt + 8pt after; 27 leave 47pt, room
    // for the heading (32.7pt with its space before) but not heading + a line.
    $blocks = array_map(fn ($i) => fpwPara(fpwSentence($i)), range(1, 27));
    $blocks[] = ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Stranded?']]];
    $blocks[] = fpwPara(implode(' ', array_map('fpwSentence', range(100, 104))));
    $read = fpwRead(fpwWrite(['blocks' => $blocks]));

    expect($read['pageCount'])->toBe(2)
        ->and($read['pages'][0]['text'])->not->toContain('Stranded?')
        ->and($read['pages'][1]['text'])->toStartWith('Stranded?');
});

it('never leaves one line of a paragraph alone at the top or bottom of a page', function () {
    $crossings = 0;
    foreach (range(18, 29) as $fill) {
        $blocks = array_map(fn ($i) => fpwPara(fpwSentence($i)), range(1, $fill));
        $blocks[] = fpwPara(implode(' ', array_map('fpwSentence', range(200, 212))));
        $pdf = fpwWrite(['blocks' => $blocks]);
        $read = fpwRead($pdf);
        if ($read['pageCount'] < 2) {
            continue;
        }
        $mine = fn (string $l) => preg_match('/(Sentence |alpha|beta|gamma|delta)2\d\d\b/', $l) === 1;
        $tailOnPage1 = count(array_filter(explode("\n", trim($read['pages'][0]['text'])), $mine));
        $headOnPage2 = count(array_filter(explode("\n", trim($read['pages'][1]['text'])), $mine));
        if ($tailOnPage1 > 0 && $headOnPage2 > 0) {
            $crossings++;
        }
        if ($tailOnPage1 > 0) {
            expect($tailOnPage1)->toBeGreaterThanOrEqual(2, "fill {$fill}: an orphan line at the foot of page 1");
        }
        if ($headOnPage2 > 0 && $tailOnPage1 > 0) {
            expect($headOnPage2)->toBeGreaterThanOrEqual(2, "fill {$fill}: a widow line at the top of page 2");
        }
    }
    // The sweep must actually have split the paragraph somewhere, or it tested nothing.
    expect($crossings)->toBeGreaterThan(3);
});

it('sets code in Courier, keeping its spacing, on a grey ground', function () {
    $pdf = fpwWrite(['blocks' => [['type' => 'code', 'language' => 'php', 'text' => "if (\$a) {\n    return 1;\n}"]]], ['compress' => false]);

    expect($pdf)->toContain('/BaseFont /Courier ')
        ->and($pdf)->toContain('0.949 0.949 0.949 rg');   // #F2F2F2
    expect(fpwRead($pdf)['pages'][0]['text'])->toBe("if (\$a) {\n    return 1;\n}");
});

it('indents quotes and sets them in grey italics', function () {
    $pdf = fpwWrite(['blocks' => [['type' => 'quote', 'blocks' => [fpwPara('Quoted words')]]]], ['compress' => false]);

    expect($pdf)->toContain('/BaseFont /Helvetica-Oblique ')
        ->and(fpwRuns($pdf)[0]['x0'])->toBe(72.0 + 36);
});

it('draws a horizontal rule and paragraph shading behind the text', function () {
    $pdf = fpwWrite(['blocks' => [
        fpwPara('Callout', ['shading' => '#EEF2F7', 'borders' => ['left' => ['style' => 'single', 'width' => 4, 'color' => '#1F3A5F']], 'indentLeft' => 8]),
        ['type' => 'hr'],
        fpwPara('After'),
    ]], ['compress' => false]);

    expect($pdf)->toContain('0.9333 0.949 0.9686 rg')   // #EEF2F7
        ->and($pdf)->toContain('4 w');
    $read = fpwRead($pdf);
    expect($read['pages'][0]['text'])->toContain('Callout')
        ->and($read['guard']['findings'])->toBe([]);
});

it('underlines and strikes through with lines, highlights behind', function () {
    $pdf = fpwWrite(['blocks' => [['type' => 'paragraph', 'runs' => [
        ['text' => 'under', 'underline' => true], ['text' => ' struck', 'strike' => true], ['text' => ' lit', 'highlight' => '#FFFF00'],
    ]]]], ['compress' => false]);

    expect(substr_count($pdf, ' re f'))->toBeGreaterThanOrEqual(3)
        ->and(fpwRead($pdf)['pages'][0]['text'])->toBe('under struck lit');
});
