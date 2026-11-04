<?php

declare(strict_types=1);

use FinePrint\Agent;
use FinePrint\Render\Renderer;

/*
 * Footnotes reach the PDF. Numbering and placement match last-word and
 * last-word-ui: marks 1, 2, 3 in reading order through paragraphs, headings,
 * list items, quotes and table cells; each note at the foot of the page holding
 * its mark, under a short rule, at 10pt, its number at 6.5pt beside it. Every
 * assertion reads the PDF back through this package's own reader.
 */

function fpoNote(string $text): array
{
    return ['text' => '', 'footnote' => ['blocks' => [fpwPara($text)]]];
}

function fpoMarked(string $text, string $note, array $props = []): array
{
    return ['type' => 'paragraph', 'runs' => [['text' => $text], fpoNote($note)]] + $props;
}

/** @return list<string> */
function fpoPagesText(string $pdf): array
{
    return array_column(fpwRead($pdf)['pages'], 'text');
}

function fpoNotePage(string $pdf, string $word): int
{
    foreach (fpoPagesText($pdf) as $i => $t) {
        if (str_contains($t, $word)) {
            return $i + 1;
        }
    }
    throw new RuntimeException("{$word} is on no page");
}

it('draws the note text at the foot of the page, and the mark in the line', function () {
    $pdf = fpwWrite(['blocks' => [fpoMarked('Revenue rose', 'Audited figures, FY2026.')]]);
    $read = fpwRead($pdf);

    expect($read['pages'][0]['text'])->toContain('Revenue rose')->toContain('Audited figures, FY2026.')
        ->and($read['guard']['findings'])->toBe([]);

    $runs = fpwRuns($pdf, 1);
    $note = array_values(array_filter($runs, fn ($r) => str_contains($r['text'], 'Audited')))[0];
    $body = array_values(array_filter($runs, fn ($r) => str_contains($r['text'], 'Revenue')))[0];
    // At the foot of the 72pt bottom margin, well below the body line, at 10pt.
    expect($note['y'])->toBeGreaterThan(72)->toBeLessThan(72 + 20)
        ->and($note['size'])->toEqualWithDelta(10, 0.01)
        ->and($body['y'])->toBeGreaterThan(600);
});

it('sets the mark at 0.65em, raised off the line, without growing the line', function () {
    $marked = fpwWrite(['blocks' => [fpoMarked('Word', 'Note.'), fpwPara('Next')]]);
    $plain = fpwWrite(['blocks' => [fpwPara('Word'), fpwPara('Next')]]);

    $mark = array_values(array_filter(fpwRuns($marked, 1), fn ($r) => trim($r['text']) === '1' && $r['y'] > 300));
    expect($mark)->toHaveCount(1)
        ->and($mark[0]['size'])->toEqualWithDelta(11 * 0.65, 0.01);

    $word = fn (string $pdf, string $w) => array_values(array_filter(fpwRuns($pdf, 1), fn ($r) => str_contains($r['text'], $w)))[0];
    expect($word($marked, 'Next')['y'])->toEqualWithDelta($word($plain, 'Next')['y'], 0.001)
        ->and($mark[0]['y'])->toBeGreaterThan($word($marked, 'Word')['y']);
});

it('reads the mark back in its sentence, not as a line of its own before it', function () {
    $pdf = fpwWrite(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Oil rate rose'], fpoNote('Allocated.'), ['text' => ' over the window.']]]]]);

    expect(fpwRead($pdf)['pages'][0]['text'])->toStartWith('Oil rate rose1 over the window.');
});

it('resets the text rise after the mark, so the next text is on the baseline', function () {
    $pdf = fpwWrite(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Before'], fpoNote('N'), ['text' => ' after-mark']]], fpwPara('Next')]]);
    $find = fn (string $w) => array_values(array_filter(fpwRuns($pdf, 1), fn ($r) => str_contains($r['text'], $w)))[0];

    expect($find('after-mark')['y'])->toEqualWithDelta($find('Before')['y'], 0.001);
});

it('numbers several notes on a page 1, 2, 3 in reading order', function () {
    $pdf = fpwWrite(['blocks' => [fpoMarked('First', 'NoteAlpha'), fpoMarked('Second', 'NoteBeta'), fpoMarked('Third', 'NoteGamma')]]);
    $notes = array_values(array_filter(fpwRuns($pdf, 1), fn ($r) => str_starts_with(trim($r['text']), 'Note')));

    expect(array_map(fn ($r) => trim($r['text']), $notes))->toBe(['NoteAlpha', 'NoteBeta', 'NoteGamma']);
    // Stacked downward in order.
    expect($notes[0]['y'])->toBeGreaterThan($notes[1]['y'])
        ->and($notes[1]['y'])->toBeGreaterThan($notes[2]['y']);
    $numbers = array_values(array_filter(fpwRuns($pdf, 1), fn ($r) => in_array(trim($r['text']), ['1', '2', '3'], true) && abs($r['size'] - 6.5) < 0.01));
    expect(array_map(fn ($r) => trim($r['text']), $numbers))->toBe(['1', '2', '3']);
});

it('numbers across headings, lists, quotes and table cells as last-word-ui does', function () {
    $pdf = fpwWrite(['blocks' => [
        ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Head'], fpoNote('N-heading')]],
        ['type' => 'list', 'items' => [['runs' => [['text' => 'Item'], fpoNote('N-item')], 'children' => [['runs' => [['text' => 'Child'], fpoNote('N-child')]]]]]],
        ['type' => 'quote', 'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Quoted'], fpoNote('N-quote')]]]],
        ['type' => 'table', 'rows' => [['cells' => [
            ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'CellA'], fpoNote('N-cell-a')]]]],
            ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'CellB'], fpoNote('N-cell-b')]]]],
        ]]]],
        fpoMarked('After', 'N-after'),
    ]]);

    $runs = fpwRuns($pdf, 1);
    $notes = array_values(array_filter($runs, fn ($r) => str_starts_with(trim($r['text']), 'N-')));
    expect(array_map(fn ($r) => trim($r['text']), $notes))->toBe(['N-heading', 'N-item', 'N-child', 'N-quote', 'N-cell-a', 'N-cell-b', 'N-after']);
    $numbers = array_values(array_filter($runs, fn ($r) => preg_match('/^\d$/', trim($r['text'])) === 1 && abs($r['size'] - 6.5) < 0.01));
    expect(array_map(fn ($r) => trim($r['text']), $numbers))->toBe(['1', '2', '3', '4', '5', '6', '7']);
});

it('a note in a table cell is as wide as the page, not the cell', function () {
    $long = str_repeat('words of a long note ', 12);
    $pdf = fpwWrite(['blocks' => [['type' => 'table', 'widths' => [1, 9], 'rows' => [['cells' => [
        ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'x'], fpoNote('NOTEWIDE '.$long)]]]],
        ['blocks' => [fpwPara('y')]],
    ]]]]]]);
    $notes = array_filter(fpwRuns($pdf, 1), fn ($r) => abs($r['size'] - 10) < 0.01);

    expect(max(array_column($notes, 'x1')))->toBeGreaterThan(400);
});

it('puts the note on the page where the mark lands, and reserves its room', function () {
    $fill = array_map(fn ($i) => fpwPara("Filler {$i}", ['spaceAfter' => 0]), range(1, 43));
    // 43 lines is 613pt of the 648pt body. The last two lines would fit, but not
    // with a note taller than what is left; the marked one must move to page 2.
    $note = str_repeat('Long footnote text that takes room at the foot of the page. ', 4);
    $pdf = fpwWrite(['blocks' => [...$fill, fpoMarked('MARKEDLINE', 'NOTETEXT '.$note, ['spaceAfter' => 0])]]);

    expect(fpoNotePage($pdf, 'MARKEDLINE'))->toBe(2)
        ->and(fpoNotePage($pdf, 'NOTETEXT'))->toBe(2);
});

it('keeps a body line and its note together when a paragraph splits around the mark', function () {
    $fill = array_map(fn ($i) => fpwPara("Filler {$i}", ['spaceAfter' => 0]), range(1, 30));
    $words = implode(' ', array_map(fn ($i) => "w{$i}", range(1, 800)));
    $note = str_repeat('Footnote words taking up room at the foot. ', 6);
    $pdf = fpwWrite(['blocks' => [...$fill, ['type' => 'paragraph', 'runs' => [['text' => $words], fpoNote('NOTETAIL '.$note), ['text' => ' TAILWORD']]]]]);

    expect(fpoNotePage($pdf, 'NOTETAIL'))->toBe(fpoNotePage($pdf, 'TAILWORD'));
    // Body text never collides with the note block on any page.
    foreach (fpwRead($pdf)['pages'] as $i => $p) {
        $runs = fpwRuns($pdf, $i + 1);
        $notes = array_values(array_filter($runs, fn ($r) => abs($r['size'] - 10) < 0.01));
        $bodies = array_values(array_filter($runs, fn ($r) => abs($r['size'] - 11) < 0.01));
        if ($notes !== [] && $bodies !== []) {
            expect(min(array_column($bodies, 'y')))->toBeGreaterThan(max(array_column($notes, 'y')));
        }
    }
});

it('reserves room for a note in a table row, moving that row with its note', function () {
    $rows = array_map(fn ($i) => ['cells' => [['blocks' => [fpwPara("Row {$i}", ['spaceAfter' => 0])]]]], range(1, 35));
    $note = str_repeat('Row note taking room at the foot of the page, more than is left. ', 8);
    $rows[] = ['cells' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'NOTEROW'], fpoNote('ROWNOTE '.$note)]]]]]];
    $pdf = fpwWrite(['blocks' => [['type' => 'table', 'rows' => $rows]]]);

    expect(fpoNotePage($pdf, 'NOTEROW'))->toBe(fpoNotePage($pdf, 'ROWNOTE'));
});

it('repeats a header-row note on each page its repeated mark appears', function () {
    $rows = [['header' => true, 'cells' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Head'], fpoNote('HEADNOTE')]]]]]]];
    foreach (range(1, 80) as $i) {
        $rows[] = ['cells' => [['blocks' => [fpwPara("Row {$i}")]]]];
    }
    $pdf = fpwWrite(['blocks' => [['type' => 'table', 'rows' => $rows]]]);
    $pages = fpoPagesText($pdf);

    expect(count($pages))->toBeGreaterThan(1);
    foreach ($pages as $t) {
        expect(substr_count($t, 'HEADNOTE'))->toBe(1);
    }
});

it('a note too long for the page continues on the next, losing nothing', function () {
    $words = array_map(fn ($i) => "fn{$i}", range(1, 1800));
    $pdf = fpwWrite(['blocks' => [fpoMarked('Marked', implode(' ', $words)), fpwPara('AfterIt')]]);
    $read = fpwRead($pdf);
    $found = array_values(array_filter(fpwWords(fpwAllText($read)), fn ($w) => preg_match('/^fn\d+$/', $w) === 1));

    expect($found)->toBe($words)
        ->and($read['pageCount'])->toBeGreaterThan(1)
        ->and($read['pages'][0]['text'])->toContain('Marked');
});

it('reports a note that had to continue on the next page', function () {
    $words = implode(' ', array_map(fn ($i) => "fn{$i}", range(1, 1800)));

    expect(array_column(Agent::lint(['blocks' => [fpoMarked('Marked', $words)]]), 'rule'))->toContain('footnote-continued');
});

it('flushes a continuing note before an orientation change, at the width it was set', function () {
    $words = array_map(fn ($i) => "fn{$i}", range(1, 1800));
    $pdf = fpwWrite(['blocks' => [
        fpoMarked('Marked', implode(' ', $words)),
        ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']],
        fpwPara('Landscape body'),
    ]]);
    $read = fpwRead($pdf);
    $found = array_values(array_filter(fpwWords(fpwAllText($read)), fn ($w) => preg_match('/^fn\d+$/', $w) === 1));

    expect($found)->toBe($words);
    $last = end($read['pages']);
    expect([(int) $last['width'], (int) $last['height']])->toBe([792, 612])
        ->and($last['text'])->toContain('Landscape body')->not->toContain('fn');
    foreach ($read['pages'] as $i => $p) {
        foreach (fpwRuns($pdf, $i + 1) as $r) {
            expect($r['x1'])->toBeLessThanOrEqual($p['width'] - 71.9);
        }
    }
});

it('an unsplittable note taller than the page is drawn and reported, not lost', function () {
    $img = FinePrint\Tests\Support\ImageBuilder::dataUrl(FinePrint\Tests\Support\ImageBuilder::png(4, 4, 2, 8, fn () => [1, 2, 3]), 'image/png');
    $doc = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Marked'], ['text' => '', 'footnote' => ['blocks' => [fpwPara('NOTEFIRST'), ['type' => 'image', 'src' => $img, 'widthPx' => 700, 'heightPx' => 2000]]]]]]]];
    $pdf = fpwWrite($doc);

    expect(implode(' ', fpoPagesText($pdf)))->toContain('NOTEFIRST')->toContain('Marked');
});

it('numbers across stretches, and fits a note at a landscape page\'s own foot and width', function () {
    $pdf = fpwWrite(['blocks' => [
        fpoMarked('Portrait', 'Portrait note'),
        ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']],
        fpoMarked('Landscape', 'LANDNOTE '.str_repeat('landscape note words ', 20)),
    ]]);
    $land = array_values(array_filter(fpwRuns($pdf, 2), fn ($r) => abs($r['size'] - 10) < 0.01));
    $num = array_values(array_filter(fpwRuns($pdf, 2), fn ($r) => abs($r['size'] - 6.5) < 0.01));

    expect(trim($num[0]['text']))->toBe('2')
        ->and(max(array_column($land, 'x1')))->toBeGreaterThan(600)
        ->and(min(array_column($land, 'y')))->toBeGreaterThan(72 - 0.01)->toBeLessThan(90);
});

it('sits above a footer that pushes the body', function () {
    $img = FinePrint\Tests\Support\ImageBuilder::dataUrl(FinePrint\Tests\Support\ImageBuilder::png(4, 4, 2, 8, fn () => [1, 2, 3]), 'image/png');
    $pdf = fpwWrite(['page' => ['footer' => ['blocks' => [['type' => 'image', 'src' => $img, 'widthPx' => 100, 'heightPx' => 200]]]], 'blocks' => [fpoMarked('Marked', 'NOTEABOVE')]]);
    $note = array_values(array_filter(fpwRuns($pdf, 1), fn ($r) => str_contains($r['text'], 'NOTEABOVE')))[0];

    // bottom = max(72, 36 + 150 + 8) = 194.
    expect($note['y'])->toBeGreaterThan(194)->toBeLessThan(194 + 20);
});

it('draws the short rule above the notes', function () {
    $pdf = fpwWrite(['blocks' => [fpoMarked('Marked', 'One line')]]);
    $r = Renderer::render($pdf, 1, 4.0);
    $note = array_values(array_filter(fpwRuns($pdf, 1), fn ($x) => str_contains($x['text'], 'One')))[0];

    $inRule = function (float $x) use ($r, $note): bool {
        for ($y = (int) ((792 - $note['y'] - 24) * 4); $y < (int) ((792 - $note['y']) * 4); $y++) {
            if ($r->pixel((int) ($x * 4), $y)[0] < 128) {
                return true;
            }
        }

        return false;
    };
    expect($inRule(72 + 100))->toBeTrue()       // inside the 144pt rule
        ->and($inRule(72 + 200))->toBeFalse();   // beyond it
});

it('takes a page with no footnote unchanged', function () {
    $doc = ['blocks' => [fpwPara('Plain')]];

    expect(fpwWrite($doc))->toBe(fpwWrite($doc))
        ->and(fpoPagesText(fpwWrite($doc))[0])->toBe('Plain');
});

it('validates a footnote as last-word does', function (array $run, string $path) {
    $errors = Agent::validate(['blocks' => [['type' => 'paragraph', 'runs' => [$run]]]]);

    expect(array_column($errors, 'path'))->toContain($path);
})->with([
    'text on the mark' => [['text' => 'x', 'footnote' => ['blocks' => [fpwPara('n')]]], 'blocks[0].runs[0].text'],
    'no text' => [['footnote' => ['blocks' => [fpwPara('n')]]], 'blocks[0].runs[0].text'],
    'not an object' => [['text' => '', 'footnote' => 'n'], 'blocks[0].runs[0].footnote'],
    'no blocks' => [['text' => '', 'footnote' => []], 'blocks[0].runs[0].footnote.blocks'],
    'empty blocks' => [['text' => '', 'footnote' => ['blocks' => []]], 'blocks[0].runs[0].footnote.blocks'],
    'a note in a note' => [['text' => '', 'footnote' => ['blocks' => [['type' => 'paragraph', 'runs' => [fpoNote('in')]]]]], 'blocks[0].runs[0].footnote.blocks[0].runs[0].footnote'],
    'a page break in a note' => [['text' => '', 'footnote' => ['blocks' => [['type' => 'pageBreak']]]], 'blocks[0].runs[0].footnote.blocks[0].type'],
    'a chart in a note' => [['text' => '', 'footnote' => ['blocks' => [['type' => 'chart', 'option' => []]]]], 'blocks[0].runs[0].footnote.blocks[0].type'],
]);

it('refuses a footnote in a band', function () {
    $errors = Agent::validate(['page' => ['footer' => ['blocks' => [['type' => 'paragraph', 'runs' => [fpoNote('n')]]]]], 'blocks' => []]);

    expect(array_column($errors, 'path'))->toBe(['page.footer.blocks[0].runs[0].footnote']);
});

it('repairs a footnote run as last-word does', function () {
    $r = Agent::validateAndRepair(['blocks' => [['type' => 'paragraph', 'runs' => [
        ['text' => 'typed', 'footnote' => 'A string note'],
        ['footnote' => [fpwPara('A bare list')]],
        ['text' => '', 'footnote' => ['blocks' => [fpwPara('kept'), ['type' => 'pageBreak']]]],
        ['text' => '', 'footnote' => ['blocks' => 7]],
    ]]]]);

    expect($r['ok'])->toBeTrue()
        ->and($r['schema']['blocks'][0]['runs'])->toBe([
            ['text' => 'typed'],
            ['text' => '', 'footnote' => ['blocks' => [fpwPara('A string note')]]],
            ['text' => '', 'footnote' => ['blocks' => [fpwPara('A bare list')]]],
            ['text' => '', 'footnote' => ['blocks' => [fpwPara('kept')]]],
        ]);
});

it('repair drops a footnote from a band, and says so', function () {
    $r = Agent::validateAndRepair(['page' => ['footer' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'kept'], fpoNote('n')]]]]], 'blocks' => []]);

    expect($r['ok'])->toBeTrue()
        ->and($r['schema']['page']['footer']['blocks'][0]['runs'])->toBe([['text' => 'kept']])
        ->and(implode(' ', array_column($r['errors'], 'message')))->toContain('cannot hold one');
});

it('cleans and checks a note\'s text like the body\'s', function () {
    $out = Agent::lint(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'x'], ['text' => '', 'footnote' => ['blocks' => [fpwPara("TODO zero\u{200B}width Привет")]]]]]]]);
    $rules = array_column($out, 'rule');

    expect($rules)->toContain('invisible-chars')->toContain('unencodable')->toContain('placeholder');
});
