<?php

declare(strict_types=1);

use FinePrint\Document;

/*
 * The writer, read back through P1. Structure first (a valid PDF 1.7 whose
 * cross-reference table tells the truth), then text, pages and sizes.
 */

it('writes PDF 1.7 with a cross-reference table that tells the truth', function () {
    $pdf = fpwWrite(['blocks' => [fpwPara('Hello world')]]);

    expect($pdf)->toStartWith('%PDF-1.7')
        ->and($pdf)->toEndWith("%%EOF\n");

    // Every in-use xref entry points at the start of its own object.
    $xref = strpos($pdf, "\nxref\n");
    expect($xref)->not->toBeFalse();
    preg_match('/xref\n0 (\d+)\n/', $pdf, $m, 0, $xref);
    $count = (int) $m[1];
    $entries = substr($pdf, $xref + strlen("\nxref\n0 {$count}\n"), 20 * $count);
    for ($i = 1; $i < $count; $i++) {
        $entry = substr($entries, 20 * $i, 20);
        expect($entry)->toMatch('/^\d{10} 00000 n\r?\n$/');
        expect(substr($pdf, (int) substr($entry, 0, 10), strlen("{$i} 0 obj")))->toBe("{$i} 0 obj");
    }
    // startxref points at the table.
    preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $s);
    expect((int) $s[1])->toBe($xref + 1);

    // P1 reads it without repairing anything.
    $doc = Document::open($pdf);
    expect($doc->repaired())->toBeFalse()
        ->and($doc->warnings())->toBe([])
        ->and($doc->version())->toBe('1.7');
});

it('round-trips a paragraph: text, one page, letter size, no findings', function () {
    $read = fpwRead(fpwWrite(['title' => 'Memo', 'blocks' => [fpwPara('The quick brown fox jumps over the lazy dog.')]]));

    expect($read['ok'])->toBeTrue()
        ->and($read['pageCount'])->toBe(1)
        ->and($read['pages'][0]['width'])->toBe(612.0)
        ->and($read['pages'][0]['height'])->toBe(792.0)
        ->and($read['pages'][0]['text'])->toBe('The quick brown fox jumps over the lazy dog.')
        ->and($read['metadata']['Title'])->toBe('Memo')
        ->and($read['activeContent'])->toBe([])
        ->and($read['guard']['findings'])->toBe([])
        ->and($read['warnings'])->toBe([]);
});

it('writes each page size and orientation', function (string $size, string $orientation, float $w, float $h) {
    $read = fpwRead(fpwWrite(['page' => ['size' => $size, 'orientation' => $orientation], 'blocks' => [fpwPara('x')]]));

    expect($read['pages'][0]['width'])->toBe($w)
        ->and($read['pages'][0]['height'])->toBe($h);
})->with([
    ['letter', 'portrait', 612.0, 792.0],
    ['letter', 'landscape', 792.0, 612.0],
    ['legal', 'portrait', 612.0, 1008.0],
    ['a4', 'portrait', 595.28, 841.89],
    ['a4', 'landscape', 841.89, 595.28],
]);

it('keeps text inside the margins and wraps long paragraphs', function () {
    $text = implode(' ', array_map('fpwSentence', range(1, 12)));
    $pdf = fpwWrite(['page' => ['margins' => ['top' => 50, 'bottom' => 60, 'left' => 90, 'right' => 110]], 'blocks' => [fpwPara($text)]]);

    $runs = fpwRuns($pdf);
    $ys = array_unique(array_map(fn ($r) => round($r['y'], 2), $runs));
    expect(count($ys))->toBeGreaterThan(3);
    foreach ($runs as $r) {
        expect($r['x0'])->toBeGreaterThanOrEqual(90 - 0.01)
            ->and($r['x1'])->toBeLessThanOrEqual(612 - 110 + 0.01)
            ->and($r['y'])->toBeLessThan(792 - 50);
    }
    expect(fpwWords(fpwRead($pdf)['pages'][0]['text']))->toBe(fpwWords($text));
});

it('measures text with the real Helvetica widths', function () {
    // H 722 + e 556 + l 222 + l 222 + o 556 = 2278 units, at 11pt.
    $runs = fpwRuns(fpwWrite(['blocks' => [fpwPara('Hello')]]));

    expect($runs[0]['x0'])->toBe(72.0)
        ->and(round($runs[0]['x1'] - $runs[0]['x0'], 3))->toBe(round(2278 * 11 / 1000, 3));
});

it('flows onto as many pages as it needs, keeping every word in order', function () {
    $blocks = array_map(fn ($i) => fpwPara(fpwSentence($i).' '.fpwSentence($i + 1000)), range(1, 80));
    $read = fpwRead(fpwWrite(['blocks' => $blocks]));

    $expected = [];
    foreach ($blocks as $b) {
        array_push($expected, ...fpwWords($b['runs'][0]['text']));
    }
    expect($read['pageCount'])->toBeGreaterThan(2)
        ->and(fpwWords(fpwAllText($read)))->toBe($expected)
        ->and($read['guard']['findings'])->toBe([]);
});

it('starts a new page at a page break', function () {
    $read = fpwRead(fpwWrite(['blocks' => [fpwPara('First page'), ['type' => 'pageBreak'], fpwPara('Second page')]]));

    expect($read['pageCount'])->toBe(2)
        ->and($read['pages'][0]['text'])->toBe('First page')
        ->and($read['pages'][1]['text'])->toBe('Second page');
});

it('draws running headers and footers with live page numbers', function () {
    $doc = [
        'page' => [
            'header' => ['left' => 'Board memo', 'right' => 'Confidential'],
            'footer' => ['center' => 'Page {page} of {pages}'],
        ],
        'blocks' => [fpwPara('One'), ['type' => 'pageBreak'], fpwPara('Two'), ['type' => 'pageBreak'], fpwPara('Three')],
    ];
    $read = fpwRead(fpwWrite($doc));

    expect($read['pageCount'])->toBe(3);
    foreach ([1, 2, 3] as $n) {
        $text = $read['pages'][$n - 1]['text'];
        expect($text)->toContain('Board memo')
            ->and($text)->toContain('Confidential')
            ->and($text)->toContain("Page {$n} of 3");
    }
    // The header reads before the body, the footer after it.
    expect(fpwWords($read['pages'][1]['text']))->toBe(['Board', 'memo', 'Confidential', 'Two', 'Page', '2', 'of', '3']);
});

it('names the standard faces it uses: bold, italic, bold italic, Times and Courier', function () {
    $pdf = fpwWrite(['blocks' => [
        ['type' => 'paragraph', 'runs' => [
            ['text' => 'plain '], ['text' => 'bold ', 'bold' => true], ['text' => 'italic ', 'italic' => true],
            ['text' => 'both ', 'bold' => true, 'italic' => true], ['text' => 'serif ', 'font' => 'Times New Roman'],
            ['text' => 'mono', 'font' => 'Courier New'],
        ]],
    ]], ['compress' => false]);

    foreach (['Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique', 'Times-Roman', 'Courier'] as $face) {
        expect($pdf)->toContain('/BaseFont /'.$face.' ');
    }
    expect(fpwRead($pdf)['pages'][0]['text'])->toBe('plain bold italic both serif mono');
});

it('sizes runs and headings', function () {
    $pdf = fpwWrite(['blocks' => [
        ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Title']]],
        ['type' => 'heading', 'level' => 3, 'runs' => [['text' => 'Sub']]],
        ['type' => 'paragraph', 'runs' => [['text' => 'big', 'size' => 20], ['text' => ' small', 'size' => 8]]],
    ]]);
    $sizes = [];
    foreach (fpwRuns($pdf) as $r) {
        $sizes[trim($r['text'])] = round($r['size'], 2);
    }

    expect($sizes)->toBe(['Title' => 18.0, 'Sub' => 14.0, 'big' => 20.0, 'small' => 8.0]);
});

it('colours text', function () {
    $pdf = fpwWrite(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'red', 'color' => '#FF0000']]]]], ['compress' => false]);

    expect($pdf)->toContain('1 0 0 rg');
});

it('aligns left, centre, right and justified', function () {
    $long = implode(' ', array_map('fpwSentence', range(1, 6)));
    $pdf = fpwWrite(['blocks' => [
        fpwPara('left', ['align' => 'left']),
        fpwPara('centre', ['align' => 'center']),
        fpwPara('right', ['align' => 'right']),
        fpwPara($long, ['align' => 'justify']),
    ]]);
    $runs = fpwRuns($pdf);
    $by = fn (string $t) => array_values(array_filter($runs, fn ($r) => trim($r['text']) === $t))[0];

    expect($by('left')['x0'])->toBe(72.0)
        ->and(round(($by('centre')['x0'] + $by('centre')['x1']) / 2, 2))->toBe(306.0)
        ->and(round($by('right')['x1'], 2))->toBe(540.0);

    // Justified: every line but the last ends at the right margin.
    $lines = [];
    foreach ($runs as $r) {
        if (str_contains($long, trim($r['text']))) {
            $y = (string) round($r['y'], 2);
            $lines[$y] = max($lines[$y] ?? 0, $r['x1']);
        }
    }
    $ends = array_values($lines);
    expect(count($ends))->toBeGreaterThan(2);
    foreach (array_slice($ends, 0, -1) as $end) {
        expect(abs($end - 540))->toBeLessThan(0.05);
    }
    expect(end($ends))->toBeLessThan(530);

    // And the words still read back once each, single-spaced: the widened
    // spaces are positioning, not extra space characters.
    $flat = (string) preg_replace('/\s+/', ' ', fpwRead($pdf)['pages'][0]['text']);
    expect($flat)->toContain(fpwSentence(1).' '.fpwSentence(2).' '.fpwSentence(3))
        ->and(fpwRead($pdf)['pages'][0]['text'])->not->toContain('  ');
});

it('breaks lines where the run says so', function () {
    $read = fpwRead(fpwWrite(['blocks' => [fpwPara("first line\nsecond line")]]));

    expect($read['pages'][0]['text'])->toBe("first line\nsecond line");
});

it('replaces characters the standard fonts cannot draw, and keeps the rest', function () {
    $read = fpwRead(fpwWrite(['blocks' => [fpwPara('Café – “quoted” € 5 • Ж')]]));

    expect($read['pages'][0]['text'])->toBe('Café – “quoted” € 5 • ?');
});

it('writes an empty document as one blank page', function () {
    $read = fpwRead(fpwWrite(['blocks' => []]));

    expect($read['ok'])->toBeTrue()
        ->and($read['pageCount'])->toBe(1)
        ->and($read['pages'][0]['text'])->toBe('');
});
