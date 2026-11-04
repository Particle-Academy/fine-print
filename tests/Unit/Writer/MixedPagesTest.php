<?php

declare(strict_types=1);

use FinePrint\Agent;
use FinePrint\Document;
use FinePrint\Render\Renderer;

/*
 * Mixed page shapes in one document. Every assertion reads the written PDF
 * back: the reader's own page sizes and text, the MediaBox in the bytes, never
 * what the writer meant to do.
 */

function fpoLandscape(array $more = []): array
{
    return ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape'] + $more];
}

function fpoPortrait(array $more = []): array
{
    return ['type' => 'pageBreak', 'page' => ['orientation' => 'portrait'] + $more];
}

/** @return list<array{0: float|int, 1: float|int}> [width, height] per page, as the reader reports them */
function fpoSizes(string $pdf): array
{
    return array_map(fn (array $p) => [(int) $p['width'], (int) $p['height']], fpwRead($pdf)['pages']);
}

function fpoGridTable(int $rows, int $cols = 3, bool $header = true): array
{
    $out = [];
    if ($header) {
        $out[] = ['header' => true, 'cells' => array_map(fn ($c) => ['blocks' => [fpwPara("Head{$c}")]], range(1, $cols))];
    }
    for ($r = 1; $r <= $rows; $r++) {
        $out[] = ['cells' => array_map(fn ($c) => ['blocks' => [fpwPara("R{$r}C{$c}")]], range(1, $cols))];
    }

    return ['type' => 'table', 'rows' => $out];
}

it('writes one MediaBox per page, and the reader reports them', function () {
    $pdf = fpwWrite(['blocks' => [fpwPara('One'), fpoLandscape(), fpwPara('Two'), fpoPortrait(), fpwPara('Three')]]);

    expect(fpoSizes($pdf))->toBe([[612, 792], [792, 612], [612, 792]]);
    expect(array_map(fn (array $p) => [(int) $p['width'], (int) $p['height']], Document::open($pdf)->pages()))->toBe([[612, 792], [792, 612], [612, 792]]);
});

it('keeps a shape until another break names a change: portrait does not come back by itself', function () {
    $pdf = fpwWrite(['blocks' => [fpoLandscape(), fpwPara('L1'), ['type' => 'pageBreak'], fpwPara('L2'), ['type' => 'pageBreak', 'page' => []], fpwPara('L3')]]);

    expect(fpoSizes($pdf))->toBe([[612, 792], [792, 612], [792, 612], [792, 612]]);
});

it('a plain page break stays inside its stretch, on the page shape in force', function () {
    $pdf = fpwWrite(['page' => ['footer' => ['left' => 'S{section} P{sectionpages}']], 'blocks' => [fpwPara('A'), ['type' => 'pageBreak'], fpwPara('B')]]);

    expect(fpoSizes($pdf))->toBe([[612, 792], [612, 792]])
        ->and(fpwRead($pdf)['pages'][0]['text'])->toContain('S1 P2');
});

it('merges margins side by side, and replaces every other key whole', function () {
    $doc = ['page' => [
        'margins' => ['left' => 100, 'top' => 50],
        'footer' => ['left' => 'first-left', 'right' => 'first-right'],
    ], 'blocks' => [
        fpwPara('Body one'),
        ['type' => 'pageBreak', 'page' => ['margins' => ['left' => 200], 'footer' => ['center' => 'only-centre']]],
        fpwPara('Body two'),
    ]];
    $pdf = fpwWrite($doc);

    $body = fn (int $p, string $word) => array_values(array_filter(fpwRuns($pdf, $p), fn ($r) => str_contains($r['text'], $word)))[0];
    expect($body(1, 'Body')['x0'])->toEqualWithDelta(100, 0.01)
        ->and($body(2, 'Body')['x0'])->toEqualWithDelta(200, 0.01)
        // top merged in from the first page: 50pt, not the one-inch default.
        ->and(792 - $body(2, 'Body')['y'])->toBeLessThan(72);

    $text = fpwRead($pdf)['pages'];
    expect($text[0]['text'])->toContain('first-left')->toContain('first-right')
        ->and($text[1]['text'])->toContain('only-centre')->not->toContain('first-left')->not->toContain('first-right');
});

it('composes each stretch at its own width', function () {
    $long = str_repeat('word ', 200);
    $pdf = fpwWrite(['blocks' => [fpwPara($long), fpoLandscape(), fpwPara($long)]]);

    $widest = fn (int $p) => max(array_map(fn ($r) => $r['x1'], fpwRuns($pdf, $p)));
    expect($widest(1))->toBeLessThanOrEqual(540.01)->toBeGreaterThan(500)
        ->and($widest(2))->toBeGreaterThan(600)->toBeLessThanOrEqual(720.01);
});

it('paginates each stretch at its own height', function () {
    // Enough lines to overflow a landscape body (468pt) and not a portrait one (648pt).
    $lines = array_map(fn ($i) => fpwPara("Line {$i}", ['spaceAfter' => 0]), range(1, 38));

    $portrait = fpwWrite(['blocks' => $lines]);
    $mixed = fpwWrite(['blocks' => [...$lines, fpoLandscape(), ...$lines]]);

    expect(Agent::pageCount($portrait))->toBe(1)
        ->and(fpoSizes($mixed))->toBe([[612, 792], [792, 612], [792, 612]]);
});

it('counts {pages} through the whole document and {section} and {sectionpages} by stretch', function () {
    $pdf = fpwWrite(['page' => ['footer' => ['left' => 'p{page}/{pages} s{section} of {sectionpages}']], 'blocks' => [
        fpwPara('A'), ['type' => 'pageBreak'], fpwPara('B'),
        fpoLandscape(), fpwPara('C'),
        fpoPortrait(), fpwPara('D'), ['type' => 'pageBreak'], fpwPara('E'), ['type' => 'pageBreak'], fpwPara('F'),
    ]]);

    $pages = fpwRead($pdf)['pages'];
    $want = ['p1/6 s1 of 2', 'p2/6 s1 of 2', 'p3/6 s2 of 1', 'p4/6 s3 of 3', 'p5/6 s3 of 3', 'p6/6 s3 of 3'];
    foreach ($want as $i => $line) {
        expect($pages[$i]['text'])->toContain($line);
    }
});

it('does not expand a token in a value it has just substituted', function () {
    $pdf = fpwWrite(['title' => '{pages}', 'page' => ['footer' => ['left' => 'T:{title} A:{author}']], 'meta' => ['author' => '{page}'], 'blocks' => [fpwPara('x')]]);

    expect(fpwRead($pdf)['pages'][0]['text'])->toContain('T:{pages} A:{page}');
});

it('leaves a token literal in the body', function () {
    expect(fpwRead(fpwWrite(['blocks' => [fpwPara('{page} of {pages} in {section}')]]))['pages'][0]['text'])->toBe('{page} of {pages} in {section}');
});

it('repeats a table\'s header rows in each stretch, at that stretch\'s width', function () {
    $pdf = fpwWrite(['blocks' => [fpwPara('Cover'), fpoLandscape(), fpoGridTable(120), fpoPortrait(), fpoGridTable(70)]]);
    $read = fpwRead($pdf);
    $sizes = fpoSizes($pdf);

    foreach ($read['pages'] as $i => $page) {
        if ($i > 0) {
            expect($page['text'])->toContain('Head1')->toContain('Head3');
        }
    }
    expect($sizes[1])->toBe([792, 612]);

    $landscapeEdge = max(array_map(fn ($r) => $r['x1'], fpwRuns($pdf, 2)));
    $col = fn (int $p) => array_values(array_filter(fpwRuns($pdf, $p), fn ($r) => str_contains($r['text'], 'Head3')))[0]['x0'];
    expect($col(2))->toBeGreaterThan(500)->toBeLessThan($landscapeEdge);

    $firstPortraitAfter = array_search([612, 792], array_slice($sizes, 1), true) + 2;
    expect($col($firstPortraitAfter))->toBeLessThan(500);
});

it('places a table straight after an orientation change at the top of the new page', function () {
    $pdf = fpwWrite(['blocks' => [fpwPara('Before'), fpoLandscape(), fpoGridTable(2)]]);
    $runs = fpwRuns($pdf, 2);

    expect(fpoSizes($pdf)[1])->toBe([792, 612])
        ->and(max(array_column($runs, 'y')))->toBeGreaterThan(612 - 72 - 20);
});

it('treats a break at the very start as a blank first page, like a plain break', function () {
    $mixed = fpwWrite(['page' => ['footer' => ['left' => 'p{page} s{section}']], 'blocks' => [fpoLandscape(), fpwPara('First content')]]);
    $plain = fpwWrite(['blocks' => [['type' => 'pageBreak'], fpwPara('First content')]]);

    expect(fpoSizes($mixed))->toBe([[612, 792], [792, 612]])
        ->and(Agent::pageCount($plain))->toBe(2)
        ->and(fpwRead($mixed)['pages'][0]['text'])->toBe('p1 s1')
        ->and(fpwRead($mixed)['pages'][1]['text'])->toContain('First content')->toContain('p2 s2');
    expect(array_column(Agent::lint(['blocks' => [fpoLandscape(), fpwPara('First content')]]), 'rule'))->toContain('sparse-page');
});

it('treats a break at the very end as a trailing blank page of the new shape', function () {
    expect(fpoSizes(fpwWrite(['blocks' => [fpwPara('Only'), fpoLandscape()]])))->toBe([[612, 792], [792, 612]]);
});

it('makes a blank page of the shape in between for two breaks in a row', function () {
    $pdf = fpwWrite(['page' => ['footer' => ['left' => 's{section} of {sectionpages}']], 'blocks' => [fpwPara('A'), fpoLandscape(), fpoPortrait(), fpwPara('B')]]);
    $pages = fpwRead($pdf)['pages'];

    expect(fpoSizes($pdf))->toBe([[612, 792], [792, 612], [612, 792]])
        ->and($pages[1]['text'])->toBe('s2 of 1')
        ->and($pages[2]['text'])->toContain('s3 of 1');
});

it('draws no blank page for a {} break mid-flow beyond the one it starts', function () {
    expect(fpoSizes(fpwWrite(['blocks' => [fpwPara('A'), ['type' => 'pageBreak', 'page' => []], fpwPara('B')]])))->toBe([[612, 792], [612, 792]]);
});

it('honours a new paper size on a break', function () {
    expect(fpoSizes(fpwWrite(['blocks' => [fpwPara('A'), ['type' => 'pageBreak', 'page' => ['size' => 'legal']], fpwPara('B')]])))->toBe([[612, 792], [612, 1008]]);
});

it('paints the frame and background by each page\'s own size', function () {
    $pdf = fpwWrite(['page' => ['background' => '#FDFAF3', 'border' => ['width' => 4, 'color' => '#B08D57', 'inset' => 20]], 'blocks' => [fpwPara('A'), fpoLandscape(), fpwPara('B')]]);
    $land = Renderer::render($pdf, 2);

    expect($land->pixel(791, 611))->toBe([253, 250, 243])
        ->and($land->pixel(21, 300))->toBe([176, 141, 87])
        ->and($land->pixel(770, 300))->toBe([176, 141, 87]);
});

it('replaces a frame on a break, inheriting the page colour', function () {
    $pdf = fpwWrite(['page' => ['background' => '#FDFAF3', 'border' => ['width' => 4, 'color' => '#B08D57', 'inset' => 20]], 'blocks' => [fpwPara('A'), ['type' => 'pageBreak', 'page' => ['border' => ['style' => 'none']]], fpwPara('B')]]);
    $second = Renderer::render($pdf, 2);

    expect($second->pixel(21, 300))->toBe([253, 250, 243]);
});

it('stays deterministic across stretches', function () {
    $doc = ['page' => ['footer' => ['center' => '{page}']], 'blocks' => [fpwPara('A'), fpoLandscape(), fpoGridTable(40), fpoPortrait(), fpwPara('B')]];

    expect(fpwWrite($doc))->toBe(fpwWrite($doc));
});

it('reads back mixed sizes and all the text after a long interleaved run', function () {
    $blocks = [];
    $want = [];
    foreach (range(1, 12) as $i) {
        $blocks[] = fpwPara(fpwSentence($i));
        $want[] = fpwSentence($i);
        $blocks[] = $i % 2 === 1 ? fpoLandscape() : fpoPortrait();
    }
    array_pop($blocks);
    $pdf = fpwWrite(['blocks' => $blocks]);
    $read = fpwRead($pdf);

    expect($read['pageCount'])->toBe(12)
        ->and(fpwWords(fpwAllText($read)))->toBe(fpwWords(implode(' ', $want)));
    foreach ($read['pages'] as $i => $p) {
        expect([(int) $p['width'], (int) $p['height']])->toBe($i % 2 === 0 ? [612, 792] : [792, 612]);
    }
});

it('renders pages of different shapes at their own size', function () {
    $pdf = fpwWrite(['blocks' => [fpwPara('A'), fpoLandscape(), fpwPara('B')]]);

    expect([Renderer::render($pdf, 1)->width, Renderer::render($pdf, 1)->height])->toBe([612, 792])
        ->and([Renderer::render($pdf, 2)->width, Renderer::render($pdf, 2)->height])->toBe([792, 612]);
});
