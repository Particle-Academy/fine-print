<?php

declare(strict_types=1);

use FinePrint\Agent;
use FinePrint\Render\Renderer;
use FinePrint\Tests\Support\ImageBuilder;

/*
 * Running bands: blocks bands, the first and even variants, the distances, and
 * the body pushed per page by a tall one. The rule is last-word-ui's `bands.ts`
 * and `geometry.ts` `bodyBox`, so PDF and viewer agree, with one deliberate
 * exception: a one-line band never pushes the body, which is what keeps existing
 * documents byte-identical. `band-overlap` still reports that case.
 */

function fpoLogo(int $w = 96, int $h = 32): string
{
    return ImageBuilder::dataUrl(ImageBuilder::png($w, $h, 2, 8, fn (int $x, int $y) => [20, 90, 160]), 'image/png');
}

function fpoNoBorders(): array
{
    return array_fill_keys(['top', 'right', 'bottom', 'left', 'insideH', 'insideV'], ['style' => 'none']);
}

/** A borderless one-row footer table: logo, centred text, and "Page N" at the right. */
function fpoFooterRow(?string $centre = 'Report Line', string $right = 'Page {page}'): array
{
    return ['type' => 'table', 'borders' => fpoNoBorders(), 'widths' => [1, 2, 1], 'rows' => [['cells' => [
        ['valign' => 'center', 'blocks' => [['type' => 'image', 'src' => fpoLogo(), 'widthPx' => 96, 'alt' => 'Logo']]],
        ['valign' => 'center', 'blocks' => $centre === null ? [] : [fpwPara($centre, ['align' => 'center', 'spaceAfter' => 0])]],
        ['valign' => 'center', 'blocks' => [fpwPara($right, ['align' => 'right', 'spaceAfter' => 0])]],
    ]]]];
}

/** @return list<string> the text of each page */
function fpoTexts(string $pdf): array
{
    return array_column(fpwRead($pdf)['pages'], 'text');
}

/** @return array{text: string, x0: float, x1: float, y: float} the first run on a page holding $word */
function fpoRun(string $pdf, int $page, string $word): array
{
    foreach (fpwRuns($pdf, $page) as $r) {
        if (str_contains($r['text'], $word)) {
            return $r;
        }
    }
    throw new RuntimeException("no run with {$word} on page {$page}");
}

it('draws a blocks band, a logo image included', function () {
    $pdf = fpwWrite(['page' => ['header' => ['blocks' => [['type' => 'image', 'src' => fpoLogo(), 'widthPx' => 96], fpwPara('Acme Ltd', ['spaceAfter' => 0])]]], 'blocks' => [fpwPara('Body')]]);

    $r = Renderer::render($pdf, 1);
    // A 96px image is 72pt wide, drawn at the left margin, 36pt from the top.
    expect($r->pixel(100, 40))->toBe([20, 90, 160])
        ->and($r->pixel(150, 40))->toBe([255, 255, 255])
        ->and(fpoTexts($pdf)[0])->toContain('Acme Ltd')->toContain('Body');
});

it('resolves {page} and {pages} in a blocks band, in a paragraph and inside a table cell', function () {
    $pdf = fpwWrite(['page' => ['footer' => ['blocks' => [
        fpwPara('para {page}/{pages}', ['spaceAfter' => 0]),
        ['type' => 'table', 'rows' => [['cells' => [['blocks' => [fpwPara('cell {page}/{pages}')]]]]]],
    ]]], 'blocks' => [fpwPara('A'), ['type' => 'pageBreak'], fpwPara('B'), ['type' => 'pageBreak'], fpwPara('C')]]);

    foreach (fpoTexts($pdf) as $i => $text) {
        $n = $i + 1;
        expect($text)->toContain("para {$n}/3")->toContain("cell {$n}/3")->not->toContain('{page');
    }
});

it('resolves the token in a three-item footer row', function () {
    $pdf = fpwWrite(['page' => ['footer' => ['blocks' => [fpoFooterRow()]]], 'blocks' => [fpwPara('A'), ['type' => 'pageBreak'], fpwPara('B')]]);
    $texts = fpoTexts($pdf);

    expect($texts[0])->toContain('Report Line')->toContain('Page 1')
        ->and($texts[1])->toContain('Page 2');

    $first = fpoRun($pdf, 1, 'Report');
    $last = fpoRun($pdf, 1, 'Line');
    $page = fpoRun($pdf, 1, '1');
    expect(($first['x0'] + $last['x1']) / 2)->toEqualWithDelta(306, 1)
        ->and($page['x1'])->toEqualWithDelta(540 - 5.4, 0.5);
    // The logo paints in the left cell, in the footer.
    expect(Renderer::render($pdf, 1)->pixel(100, 792 - 36 - 20))->toBe([20, 90, 160]);
});

it('the cover uses the same row without the centre text, through firstFooter', function () {
    $pdf = fpwWrite(['page' => ['footer' => ['blocks' => [fpoFooterRow()]], 'firstFooter' => ['blocks' => [fpoFooterRow(null)]]], 'blocks' => [fpwPara('Cover'), ['type' => 'pageBreak'], fpwPara('B')]]);
    $texts = fpoTexts($pdf);

    expect($texts[0])->not->toContain('Report Line')->toContain('Page 1')
        ->and($texts[1])->toContain('Report Line')->toContain('Page 2');
    expect(Renderer::render($pdf, 1)->pixel(100, 792 - 36 - 20))->toBe([20, 90, 160]);
});

it('draws a header of one italic 8pt grey line and a rule under it, with hr', function () {
    $pdf = fpwWrite(['page' => ['header' => ['blocks' => [
        ['type' => 'paragraph', 'spaceAfter' => 0, 'runs' => [['text' => 'Well 7 · 2026-11-03', 'italic' => true, 'size' => 8, 'color' => '#6B7280']]],
        ['type' => 'hr'],
    ]]], 'blocks' => [fpwPara('Body')]]);
    $run = fpoRun($pdf, 1, 'Well');

    expect($run['size'])->toEqualWithDelta(8, 0.01);
    $font = fpwRead($pdf)['pages'][0]['text'];
    expect($font)->toContain('Well 7 · 2026-11-03');
    // hr: 6pt before, a 0.75pt line, at the top of the band's rule box.
    $raster = Renderer::render($pdf, 1, 4.0);
    $found = false;
    for ($y = 36 * 4; $y < 72 * 4; $y++) {
        if ($raster->pixel(300 * 4, $y)[0] < 128) {
            $found = true;
        }
    }
    expect($found)->toBeTrue();
});

it('draws the same header with a bottom border on the paragraph, in a chosen colour', function () {
    $pdf = fpwWrite(['page' => ['header' => ['blocks' => [
        ['type' => 'paragraph', 'spaceAfter' => 0, 'borders' => ['bottom' => ['width' => 1, 'color' => '#FF0000']], 'runs' => [['text' => 'Well 7', 'italic' => true, 'size' => 8, 'color' => '#6B7280']]],
    ]]], 'blocks' => [fpwPara('Body')]]);
    $raster = Renderer::render($pdf, 1, 4.0);
    $red = false;
    for ($y = 36 * 4; $y < 72 * 4; $y++) {
        $p = $raster->pixel(300 * 4, $y);
        if ($p[0] > 200 && $p[1] < 80) {
            $red = true;
        }
    }
    expect($red)->toBeTrue();
});

it('draws the same header with a bottom border on a table cell', function () {
    $pdf = fpwWrite(['page' => ['header' => ['blocks' => [
        ['type' => 'table', 'borders' => fpoNoBorders(), 'rows' => [['cells' => [[
            'borders' => ['bottom' => ['width' => 1, 'color' => '#FF0000']],
            'blocks' => [['type' => 'paragraph', 'spaceAfter' => 0, 'runs' => [['text' => 'Well 7', 'italic' => true, 'size' => 8, 'color' => '#6B7280']]]],
        ]]]]],
    ]]], 'blocks' => [fpwPara('Body')]]);
    $raster = Renderer::render($pdf, 1, 4.0);
    $red = false;
    for ($y = 36 * 4; $y < 72 * 4; $y++) {
        $p = $raster->pixel(300 * 4, $y);
        if ($p[0] > 200 && $p[1] < 80) {
            $red = true;
        }
    }
    expect($red)->toBeTrue();
});

it('honours colour, italic and size on a band run', function () {
    $pdf = fpwWrite(['page' => ['footer' => ['blocks' => [
        ['type' => 'paragraph', 'runs' => [['text' => 'Grey italic', 'italic' => true, 'size' => 8, 'color' => '#808080'], ['text' => ' bold', 'bold' => true]]],
    ]]], 'blocks' => [fpwPara('B')]], ['compress' => false]);

    expect($pdf)->toContain('/Helvetica-Oblique')
        ->toContain('/Helvetica-Bold')
        ->toContain('0.502 0.502 0.502 rg');
    expect(fpoRun($pdf, 1, 'Grey')['size'])->toEqualWithDelta(8, 0.01);
});

it('shows firstHeader and firstFooter on page 1 only', function () {
    $pdf = fpwWrite(['page' => [
        'header' => ['left' => 'running-head'], 'footer' => ['left' => 'running-foot'],
        'firstHeader' => ['left' => 'cover-head'], 'firstFooter' => ['blocks' => [fpwPara('cover-foot')]],
    ], 'blocks' => [fpwPara('A'), ['type' => 'pageBreak'], fpwPara('B')]]);
    $t = fpoTexts($pdf);

    expect($t[0])->toContain('cover-head')->toContain('cover-foot')->not->toContain('running-')
        ->and($t[1])->toContain('running-head')->toContain('running-foot')->not->toContain('cover-');
});

it('leaves the cover with no running header via an empty firstHeader', function (array $empty) {
    $pdf = fpwWrite(['page' => ['header' => ['left' => 'running-head'], 'firstHeader' => $empty], 'blocks' => [fpwPara('A'), ['type' => 'pageBreak'], fpwPara('B')]]);
    $t = fpoTexts($pdf);

    expect($t[0])->not->toContain('running-head')
        ->and($t[1])->toContain('running-head');
})->with([
    'blocks' => [['blocks' => []]],
    'one line with no text' => [['left' => '']],
]);

it('shows evenHeader and evenFooter on even pages, by the running page number', function () {
    $pdf = fpwWrite(['page' => ['header' => ['left' => 'odd-head'], 'evenHeader' => ['left' => 'even-head'], 'evenFooter' => ['left' => 'even-foot']], 'blocks' => [
        fpwPara('1'), ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']], fpwPara('2'), ['type' => 'pageBreak'], fpwPara('3'),
        ['type' => 'pageBreak', 'page' => ['orientation' => 'portrait']], fpwPara('4'),
    ]]);
    $t = fpoTexts($pdf);

    expect($t[0])->toContain('odd-head')->not->toContain('even-')
        ->and($t[1])->toContain('even-head')->toContain('even-foot')->not->toContain('odd-head')
        ->and($t[2])->toContain('odd-head')
        ->and($t[3])->toContain('even-head');
});

it('never inherits firstHeader or firstFooter across a break', function () {
    $pdf = fpwWrite(['page' => ['header' => ['left' => 'running'], 'firstHeader' => ['left' => 'cover']], 'blocks' => [
        fpwPara('A'), ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']], fpwPara('B'),
    ]]);

    expect(fpoTexts($pdf)[1])->toContain('running')->not->toContain('cover');
});

it('on a break, firstHeader means the first page after that break', function () {
    $pdf = fpwWrite(['page' => ['header' => ['left' => 'running']], 'blocks' => [
        fpwPara('A'),
        ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape', 'firstHeader' => ['left' => 'chapter'], 'firstFooter' => ['left' => 'chapter-foot']]],
        fpwPara('B'), ['type' => 'pageBreak'], fpwPara('C'),
        ['type' => 'pageBreak', 'page' => []], fpwPara('D'),
    ]]);
    $t = fpoTexts($pdf);

    expect($t[0])->toContain('running')->not->toContain('chapter')
        ->and($t[1])->toContain('chapter')->toContain('chapter-foot')->not->toContain('running')
        ->and($t[2])->toContain('running')->not->toContain('chapter')
        ->and($t[3])->toContain('running')->not->toContain('chapter');
});

it('a first-page band beats an even one on the first page of a stretch', function () {
    $pdf = fpwWrite(['page' => ['evenHeader' => ['left' => 'even']], 'blocks' => [
        fpwPara('A'), ['type' => 'pageBreak', 'page' => ['firstHeader' => ['left' => 'first']]], fpwPara('B'),
    ]]);

    expect(fpoTexts($pdf)[1])->toContain('first')->not->toContain('even');
});

it('honours margins.header and margins.footer, one-line and blocks alike', function () {
    $doc = fn (array $h, array $f) => ['page' => ['margins' => ['header' => 20, 'footer' => 15], 'header' => $h, 'footer' => $f], 'blocks' => [fpwPara('Body')]];
    $line = fpwWrite($doc(['left' => 'HEADLINE'], ['left' => 'FOOTLINE']));
    $blocks = fpwWrite($doc(['blocks' => [fpwPara('HEADLINE', ['spaceAfter' => 0])]], ['blocks' => [fpwPara('FOOTLINE', ['spaceAfter' => 0])]]));

    foreach ([$line, $blocks] as $pdf) {
        expect(fpoRun($pdf, 1, 'HEADLINE')['y'])->toBeGreaterThan(792 - 20 - 14)->toBeLessThan(792 - 20)
            ->and(fpoRun($pdf, 1, 'FOOTLINE')['y'])->toBeGreaterThan(15)->toBeLessThan(15 + 12);
    }
});

it('keeps the one-line band at its distance from the edge by default', function () {
    $pdf = fpwWrite(['page' => ['header' => ['left' => 'HEADLINE']], 'blocks' => [fpwPara('Body')]]);

    expect(fpoRun($pdf, 1, 'HEADLINE')['y'])->toBeGreaterThan(792 - 36 - 12)->toBeLessThan(792 - 36);
});

it('pushes the body down for a tall blocks header, on the pages that draw it', function () {
    $tall = ['blocks' => [['type' => 'image', 'src' => fpoLogo(96, 200), 'heightPx' => 200]]];   // 150pt
    $pdf = fpwWrite(['page' => ['header' => ['left' => 'plain'], 'firstHeader' => $tall], 'blocks' => [fpwPara('BodyOne'), ['type' => 'pageBreak'], fpwPara('BodyTwo')]]);

    // bodyBox: top = max(margin 72, distance 36 + band 150 + image's 8 after) = 194.
    $top = fn (string $word, int $p) => 792 - fpoRun($pdf, $p, $word)['y'];
    expect($top('BodyOne', 1))->toBeGreaterThan(194)->toBeLessThan(194 + 14)
        ->and($top('BodyTwo', 2))->toBeGreaterThan(72)->toBeLessThan(72 + 14);
});

it('pushes the body up for a tall blocks footer', function () {
    $tall = ['blocks' => [['type' => 'image', 'src' => fpoLogo(96, 400), 'heightPx' => 400]]];   // 300pt
    $lines = array_map(fn ($i) => fpwPara("Line {$i}", ['spaceAfter' => 0]), range(1, 40));
    $pdf = fpwWrite(['page' => ['footer' => $tall], 'blocks' => $lines]);

    // bottom = max(72, 36 + 300 + 8) = 344, so nothing in the body falls below it.
    foreach (fpwRuns($pdf, 1) as $r) {
        expect($r['y'])->toBeGreaterThan(344);
    }
    expect(Agent::pageCount($pdf))->toBe(2);
});

it('does not push the body for a one-line band, and band-overlap still reports it', function () {
    $doc = ['page' => ['margins' => ['top' => 36], 'header' => ['left' => 'HEADLINE']], 'blocks' => [fpwPara('Body')]];
    $pdf = fpwWrite($doc);

    expect(792 - fpoRun($pdf, 1, 'Body')['y'])->toBeLessThan(36 + 14);
    expect(array_column(Agent::lint($doc), 'rule'))->toContain('band-overlap');
});

it('reports a band too tall to leave a body, rather than losing the body', function () {
    $huge = ['blocks' => array_fill(0, 20, ['type' => 'image', 'src' => fpoLogo(96, 200), 'heightPx' => 200])];
    $doc = ['page' => ['header' => $huge], 'blocks' => [fpwPara('StillHere')]];

    expect(fpoTexts(fpwWrite($doc))[0])->toContain('StillHere');
    expect(array_column(Agent::lint($doc), 'rule'))->toContain('band-too-tall');
});

it('a landscape stretch\'s bands are laid out at its own width', function () {
    $doc = ['page' => ['margins' => ['left' => 43.2, 'right' => 43.2], 'footer' => ['left' => 'FL', 'center' => 'FC', 'right' => 'FR'], 'header' => ['blocks' => [fpwPara('HB', ['align' => 'right', 'spaceAfter' => 0])]]], 'blocks' => [
        fpwPara('P'), ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape', 'margins' => ['left' => 28.8, 'right' => 28.8]]], fpwPara('L'),
    ]];
    $pdf = fpwWrite($doc);

    expect(fpoRun($pdf, 1, 'FL')['x0'])->toEqualWithDelta(43.2, 0.01)
        ->and(fpoRun($pdf, 1, 'FR')['x1'])->toEqualWithDelta(612 - 43.2, 0.01)
        ->and((fpoRun($pdf, 1, 'FC')['x0'] + fpoRun($pdf, 1, 'FC')['x1']) / 2)->toEqualWithDelta(306, 0.01)
        ->and(fpoRun($pdf, 1, 'HB')['x1'])->toEqualWithDelta(612 - 43.2, 0.01)
        ->and(fpoRun($pdf, 2, 'FL')['x0'])->toEqualWithDelta(28.8, 0.01)
        ->and(fpoRun($pdf, 2, 'FR')['x1'])->toEqualWithDelta(792 - 28.8, 0.01)
        ->and((fpoRun($pdf, 2, 'FC')['x0'] + fpoRun($pdf, 2, 'FC')['x1']) / 2)->toEqualWithDelta(396, 0.01)
        ->and(fpoRun($pdf, 2, 'HB')['x1'])->toEqualWithDelta(792 - 28.8, 0.01);
});

it('sets the footer row at the landscape width too', function () {
    $pdf = fpwWrite(['page' => ['footer' => ['blocks' => [fpoFooterRow()]]], 'blocks' => [fpwPara('P'), ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']], fpwPara('L')]]);

    expect(fpoRun($pdf, 2, '2')['x1'])->toEqualWithDelta(792 - 72 - 5.4, 0.5);
});

it('draws every line of a one-line band that holds a line break', function () {
    $pdf = fpwWrite(['page' => ['footer' => ['left' => "LineA\nLineB", 'right' => 'Right']], 'blocks' => [fpwPara('Body')]]);
    $text = fpoTexts($pdf)[0];

    expect($text)->toContain('LineA')->toContain('LineB');
    // Stacked upward from the footer distance, both clear of the page edge.
    expect(fpoRun($pdf, 1, 'LineA')['y'])->toBeGreaterThan(fpoRun($pdf, 1, 'LineB')['y'])
        ->and(fpoRun($pdf, 1, 'LineB')['y'])->toBeGreaterThan(36);
});

it('keeps text cleaning in a blocks band', function () {
    $out = Agent::lint(['page' => ['footer' => ['blocks' => [fpwPara("zero\u{200B}width")]]], 'blocks' => [fpwPara('x')]]);

    expect(array_column($out, 'rule'))->toContain('invisible-chars');
});

it('names the blocks-band path in a finding', function () {
    $out = Agent::lint(['page' => ['footer' => ['blocks' => [fpwPara('Привет')]]], 'blocks' => [fpwPara('x')]]);
    $found = array_values(array_filter($out, fn ($f) => $f['rule'] === 'unencodable'));

    expect($found[0]['path'])->toBe('page.footer.blocks[0].runs[0]');
});

it('names a band defined on a break by the break', function () {
    $out = Agent::lint(['blocks' => [fpwPara('x'), ['type' => 'pageBreak', 'page' => ['footer' => ['left' => '{date}']]], fpwPara('y')]]);
    $found = array_values(array_filter($out, fn ($f) => $f['rule'] === 'band-field-literal'));

    expect($found[0]['path'])->toBe('blocks[1].page.footer.left');
});

it('a page number in a blocks band counts as page numbers for lint', function () {
    $doc = ['page' => ['footer' => ['blocks' => [fpwPara('Page {page}')]]], 'blocks' => [fpwPara('A'), ['type' => 'pageBreak'], fpwPara('B')]];

    expect(array_column(Agent::lint($doc), 'rule'))->not->toContain('no-page-numbers');
});

it('finds a placeholder in a band', function () {
    $out = Agent::lint(['page' => ['header' => ['blocks' => [fpwPara('Date: [Insert date]')]]], 'blocks' => [fpwPara('x')]]);

    expect(array_column($out, 'rule'))->toContain('placeholder');
});

it('refuses what a band cannot hold, at any depth', function (string $type) {
    $block = ['type' => $type] + match ($type) {
        'portable' => ['content' => ['fancyContent' => 1, 'items' => []]],
        'chart' => ['option' => []],
        default => [],
    };
    $errors = Agent::validate(['page' => ['header' => ['blocks' => [['type' => 'quote', 'blocks' => [$block]]]]], 'blocks' => []]);

    expect(array_column($errors, 'path'))->toBe(['page.header.blocks[0].blocks[0].type']);
})->with(['pageBreak', 'chart', 'portable']);

it('refuses a band that mixes blocks with one-line keys, and repair keeps the blocks', function () {
    $doc = ['page' => ['footer' => ['left' => 'gone', 'blocks' => [fpwPara('kept')]]], 'blocks' => []];

    expect(array_column(Agent::validate($doc), 'path'))->toBe(['page.footer']);
    $r = Agent::validateAndRepair($doc);
    expect($r['ok'])->toBeTrue()
        ->and($r['schema']['page']['footer'])->toBe(['blocks' => [fpwPara('kept')]]);
});

it('emits only images a page actually draws', function () {
    // The even header exists, but a one-page document never draws it.
    $pdf = fpwWrite(['page' => ['evenHeader' => ['blocks' => [['type' => 'image', 'src' => fpoLogo()]]]], 'blocks' => [fpwPara('x')]], ['compress' => false]);

    expect($pdf)->not->toContain('/Subtype /Image');
});

it('embeds a logo once however many pages draw it', function () {
    $pdf = fpwWrite(['page' => ['footer' => ['blocks' => [fpoFooterRow()]]], 'blocks' => array_merge(...array_map(fn ($i) => [fpwPara("P{$i}"), ['type' => 'pageBreak']], range(1, 5)))], ['compress' => false]);

    expect(substr_count($pdf, '/Subtype /Image'))->toBe(1);
});
