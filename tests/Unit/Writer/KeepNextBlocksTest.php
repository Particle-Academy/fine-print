<?php

declare(strict_types=1);

use FinePrint\Tests\Support\ImageBuilder;

/*
 * `keepNext` on the blocks that used to ignore it: table, image, code and rule.
 * Only that key is read, not the spacing keys (the plan's ruling), and there is
 * no new keep word.
 */

function fpoImage(int $hPx, array $more = []): array
{
    return ['type' => 'image', 'src' => ImageBuilder::dataUrl(ImageBuilder::png(10, 10, 2, 8, fn () => [10, 120, 60]), 'image/png'), 'widthPx' => 100, 'heightPx' => $hPx] + $more;
}

/** Filler paragraphs that stop `$room` points from the foot of a default letter page. */
function fpoFill(float $room): array
{
    // One paragraph per 14.256pt line, no space after; the body is 648pt.
    $n = (int) floor((648 - $room) / 14.256);

    return array_map(fn ($i) => fpwPara("Filler {$i}", ['spaceAfter' => 0]), range(1, $n));
}

function fpoPageOf(string $pdf, string $word): int
{
    foreach (fpwRead($pdf)['pages'] as $i => $p) {
        if (str_contains($p['text'], $word)) {
            return $i + 1;
        }
    }
    throw new RuntimeException("{$word} not found");
}

it('an image with keepNext keeps its caption with it', function () {
    // The 60pt image fits in the 80pt left; the 3-line caption does not fit under it.
    $caption = fpwPara('CAPTION '.str_repeat('caption words run on across the column ', 8));
    $doc = ['blocks' => [...fpoFill(80), fpoImage(80, ['keepNext' => true]), $caption]];
    $pdf = fpwWrite($doc);

    expect(fpoPageOf($pdf, 'CAPTION'))->toBe(2);
    // The image moved with it: nothing but filler is drawn on page 1.
    $p1 = FinePrint\Render\Renderer::render($pdf, 1);
    $seen = false;
    for ($y = 792 - 72 - 80; $y < 792 - 72; $y += 2) {
        if ($p1->pixel(100, $y) === [10, 120, 60]) {
            $seen = true;
        }
    }
    expect($seen)->toBeFalse()
        ->and(FinePrint\Render\Renderer::render($pdf, 2)->pixel(100, 100))->toBe([10, 120, 60]);
});

it('an image without keepNext leaves the caption behind, as ever', function () {
    $caption = fpwPara('CAPTION '.str_repeat('caption words run on across the column ', 8));
    $pdf = fpwWrite(['blocks' => [...fpoFill(80), fpoImage(80), $caption]]);

    expect(FinePrint\Render\Renderer::render($pdf, 1)->pixel(100, 650))->toBe([10, 120, 60])
        ->and(fpoPageOf($pdf, 'CAPTION'))->toBe(2);
});

it('a heading keeps with a chart, and the chart with its caption', function () {
    $doc = ['blocks' => [
        ...fpoFill(120),
        ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'ChartHeading']]],
        fpoImage(120, ['keepNext' => true]),
        fpwPara('ChartCaption'),
    ]];
    $pdf = fpwWrite($doc);

    expect(fpoPageOf($pdf, 'ChartHeading'))->toBe(2)
        ->and(fpoPageOf($pdf, 'ChartCaption'))->toBe(2);
    expect(array_column(FinePrint\Agent::lint($doc), 'rule'))->not->toContain('stranded-heading');
});

it('a one-row table with keepNext keeps with the next block', function () {
    $card = ['type' => 'table', 'keepNext' => true, 'rows' => [['cells' => [['blocks' => [fpwPara('CARD')]]]]]];
    $next = fpwPara('NEXTBLOCK '.str_repeat('words run across the line and on ', 12));
    $pdf = fpwWrite(['blocks' => [...fpoFill(45), $card, $next]]);

    expect(fpoPageOf($pdf, 'CARD'))->toBe(2);
});

it('a one-row table moves whole with no keep word at all', function () {
    $lines = str_repeat('Result line of the card, wrapping through the whole cell. ', 30);
    $card = ['type' => 'table', 'rows' => [['cells' => [['blocks' => [fpwPara('CARDTOP '.$lines.' CARDEND')]]]]]];
    $pdf = fpwWrite(['blocks' => [...fpoFill(120), $card]]);

    expect(fpoPageOf($pdf, 'CARDTOP'))->toBe(2)
        ->and(fpoPageOf($pdf, 'CARDEND'))->toBe(2);
});

it('code with keepNext keeps with the next block', function () {
    $code = ['type' => 'code', 'keepNext' => true, 'text' => "CODE\nline two"];
    $next = fpwPara('AFTERCODE '.str_repeat('words run across the line and on ', 12));
    $pdf = fpwWrite(['blocks' => [...fpoFill(45), $code, $next]]);

    expect(fpoPageOf($pdf, 'CODE'))->toBe(2);
});

it('a rule with keepNext keeps with the next block', function () {
    $next = fpwPara('AFTERRULE '.str_repeat('words run across the line and on ', 12));
    $with = fpwWrite(['blocks' => [...fpoFill(30), ['type' => 'hr', 'keepNext' => true], $next]]);
    $without = fpwWrite(['blocks' => [...fpoFill(30), ['type' => 'hr'], $next]]);

    $inkAtFoot = function (string $pdf): bool {
        $r = FinePrint\Render\Renderer::render($pdf, 1, 4.0);
        for ($y = (792 - 72 - 30) * 4; $y < (792 - 72) * 4; $y++) {
            if ($r->pixel(400 * 4, (int) $y)[0] < 128) {
                return true;
            }
        }

        return false;
    };
    expect($inkAtFoot($with))->toBeFalse()
        ->and($inkAtFoot($without))->toBeTrue();
});

it('respects the keep-chain limit: a chain of more than six does not move as a block', function () {
    $chain = array_map(fn ($i) => fpoImage(20, ['keepNext' => true]), range(1, 10));
    $last = fpwPara('ENDCHAIN');
    $pdf = fpwWrite(['blocks' => [...fpoFill(160), ...$chain, $last]]);

    // The chain is measured seven images deep and no further: 7 x 15 + 6 x 8 = 153pt
    // of the 163 left, so it starts on page 1. All ten plus the paragraph would not
    // fit, and without the limit the whole chain would have moved to page 2.
    expect(FinePrint\Render\Renderer::render($pdf, 1)->pixel(112, 560))->toBe([10, 120, 60]);
});

it('does not move a chain that could not fit on any page', function () {
    $tall = fpoImage(800, ['keepNext' => true]);   // 600pt
    $after = fpoImage(200);                       // 150pt
    $pdf = fpwWrite(['blocks' => [fpwPara('First'), $tall, $after]]);

    expect(FinePrint\Agent::pageCount($pdf))->toBeGreaterThanOrEqual(2);
});

it('reads keepNext and not the spacing keys on those blocks', function () {
    $plain = fpwWrite(['blocks' => [fpoImage(20), fpwPara('x')]], ['compress' => false]);
    $spaced = fpwWrite(['blocks' => [fpoImage(20, ['spaceAfter' => 99, 'spaceBefore' => 99]), fpwPara('x')]], ['compress' => false]);

    expect($spaced)->toBe($plain);
});

it('refuses a non-boolean keepNext on those blocks, and repair removes it', function (array $block) {
    $doc = ['blocks' => [$block + ['keepNext' => 'yes']]];
    expect(array_column(FinePrint\Agent::validate($doc), 'path'))->toBe(['blocks[0].keepNext']);
    $r = FinePrint\Agent::validateAndRepair($doc);
    expect($r['ok'])->toBeTrue()
        ->and($r['schema']['blocks'][0])->not->toHaveKey('keepNext');
})->with([
    'table' => [['type' => 'table', 'rows' => []]],
    'image' => [fn () => fpoImage(10)],
    'code' => [['type' => 'code', 'text' => 'x']],
    'hr' => [['type' => 'hr']],
]);
