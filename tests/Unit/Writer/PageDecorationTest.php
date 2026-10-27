<?php

declare(strict_types=1);

use FinePrint\Agent;
use FinePrint\Render\Renderer;

/*
 * Page decoration: a full-bleed background colour and a frame inset from the
 * page edge, both painted on every page before any content, and both sized by
 * the page rather than by how much content the page happens to carry.
 *
 * Pixels are read at scale 1 (one point per pixel) on a letter page, and only
 * where a whole pixel lies inside one paint, so the expected colours are exact.
 */

const FPW_CREAM = [253, 250, 243];   // #FDFAF3
const FPW_GOLD = [176, 141, 87];     // #B08D57
const FPW_WHITE = [255, 255, 255];

function fpwDecorated(array $page, array $blocks = []): string
{
    return fpwWrite(['page' => $page, 'blocks' => $blocks ?: [fpwPara('Short.')]]);
}

it('paints a background colour full-bleed on every page', function () {
    $pdf = fpwDecorated(['background' => '#FDFAF3'], [fpwPara('One.'), ['type' => 'pageBreak'], fpwPara('Two.')]);

    foreach ([1, 2] as $n) {
        $r = Renderer::render($pdf, $n);
        expect($r->pixel(0, 0))->toBe(FPW_CREAM)
            ->and($r->pixel(611, 791))->toBe(FPW_CREAM)
            ->and($r->pixel(306, 700))->toBe(FPW_CREAM);
    }
    expect(Agent::pageCount($pdf))->toBe(2);
});

it('paints the background before the content, so text stays on top', function () {
    $pdf = fpwWrite(['page' => ['background' => '#FDFAF3'], 'blocks' => [fpwPara('Hello')]], ['compress' => false]);

    $fill = strpos($pdf, '0 0 612 792 re f');
    expect($fill)->not->toBeFalse()
        ->and($fill)->toBeLessThan(strpos($pdf, "BT\n"));
    expect(fpwRead($pdf)['pages'][0]['text'])->toBe('Hello');
});

it('leaves a page without a background white, and its bytes unchanged', function () {
    $doc = ['blocks' => [fpwPara('Plain')]];
    $r = Renderer::render(fpwWrite($doc), 1);

    expect($r->pixel(0, 0))->toBe(FPW_WHITE)
        ->and(fpwWrite($doc + ['page' => []]))->toBe(fpwWrite($doc));
});

it('draws a single frame inset from the page edge, independent of content height', function () {
    $pdf = fpwDecorated(['background' => '#FDFAF3', 'border' => ['width' => 4, 'color' => '#B08D57', 'inset' => 20]]);
    $r = Renderer::render($pdf, 1);

    // The frame covers 20..24pt from every edge; the content is one short line.
    expect($r->pixel(19, 396))->toBe(FPW_CREAM)
        ->and($r->pixel(22, 396))->toBe(FPW_GOLD)               // left
        ->and($r->pixel(612 - 23, 396))->toBe(FPW_GOLD)         // right
        ->and($r->pixel(306, 22))->toBe(FPW_GOLD)               // top
        ->and($r->pixel(306, 792 - 23))->toBe(FPW_GOLD)         // bottom, far below the text
        ->and($r->pixel(21, 21))->toBe(FPW_GOLD)                // a closed corner
        ->and($r->pixel(25, 396))->toBe(FPW_CREAM);
});

it('draws a double frame as two closed rules with a gap between', function () {
    $pdf = fpwDecorated(['border' => ['style' => 'double', 'width' => 6, 'color' => '#B08D57', 'inset' => 20]]);
    $r = Renderer::render($pdf, 1);

    // Outer rule 20..22, gap 22..24, inner rule 24..26.
    expect($r->pixel(20, 396))->toBe(FPW_GOLD)
        ->and($r->pixel(22, 396))->toBe(FPW_WHITE)
        ->and($r->pixel(24, 396))->toBe(FPW_GOLD)
        ->and($r->pixel(26, 396))->toBe(FPW_WHITE)
        ->and($r->pixel(306, 792 - 21))->toBe(FPW_GOLD)
        ->and($r->pixel(306, 792 - 25))->toBe(FPW_GOLD)
        ->and($r->pixel(20, 20))->toBe(FPW_GOLD)               // corners meet, no gaps
        ->and($r->pixel(24, 24))->toBe(FPW_GOLD)
        ->and($r->pixel(22, 22))->toBe(FPW_WHITE);
});

it('draws the frame on every page', function () {
    $pdf = fpwDecorated(['border' => ['width' => 2, 'color' => '#B08D57', 'inset' => 10]], [fpwPara('One.'), ['type' => 'pageBreak'], fpwPara('Two.')]);

    expect(Renderer::render($pdf, 2)->pixel(11, 396))->toBe(FPW_GOLD);
});

it('defaults the frame to a half-point black hairline at 24pt', function () {
    $pdf = fpwWrite(['page' => ['border' => []], 'blocks' => [fpwPara('x')]], ['compress' => false]);

    expect($pdf)->toContain('0.5 w')
        ->and($pdf)->toContain('24.25 24.25 563.5 743.5 re S');
});

it('draws no frame for style none', function () {
    $doc = ['blocks' => [fpwPara('x')]];

    expect(fpwWrite($doc + ['page' => ['border' => ['style' => 'none', 'width' => 3]]]))->toBe(fpwWrite($doc));
});

it('keeps white text on a dark page background visible to the guard', function () {
    $read = fpwRead(fpwWrite(['page' => ['background' => '#1F3A5F'], 'blocks' => [
        ['type' => 'paragraph', 'runs' => [['text' => 'White on navy', 'color' => '#FFFFFF']]],
    ]]));

    expect($read['pages'][0]['text'])->toBe('White on navy')
        ->and($read['guard']['findings'])->toBe([]);
});

it('validates the background and the frame', function (array $page, string $path) {
    $paths = array_column(Agent::validate(['page' => $page, 'blocks' => []]), 'path');

    expect($paths)->toContain($path);
})->with([
    'colour name' => [['background' => 'cream'], 'page.background'],
    'short hex' => [['background' => '#FFF'], 'page.background'],
    'border not an object' => [['border' => 'gold'], 'page.border'],
    'unknown style' => [['border' => ['style' => 'wavy']], 'page.border.style'],
    'zero width' => [['border' => ['width' => 0]], 'page.border.width'],
    'bad colour' => [['border' => ['color' => 'gold']], 'page.border.color'],
    'negative inset' => [['border' => ['inset' => -1]], 'page.border.inset'],
    'off the page' => [['border' => ['inset' => 300, 'width' => 10]], 'page.border'],
]);

it('accepts a valid background and frame', function () {
    expect(Agent::validate(['page' => [
        'size' => 'letter', 'orientation' => 'landscape', 'background' => '#FDFAF3',
        'border' => ['style' => 'double', 'width' => 6, 'color' => '#B08D57', 'inset' => 18],
    ], 'blocks' => []]))->toBe([]);
});

it('repairs an invalid background or frame by removing it, and says so', function () {
    $out = Agent::validateAndRepair(['page' => ['background' => 'cream', 'border' => ['style' => 'wavy', 'width' => 2]], 'blocks' => [fpwPara('x')]]);

    expect($out['ok'])->toBeTrue()
        ->and($out['schema']['page'])->toBe(['border' => ['width' => 2]])
        ->and(implode(' ', array_column($out['errors'], 'path')))->toContain('page.background')->toContain('page.border.style');
});

it('lints a frame that the text area runs into', function () {
    $rules = fn (array $page) => array_column(Agent::lint(['page' => $page, 'blocks' => [fpwPara('x')]]), 'rule');

    // 20 + 6 = 26pt of frame, inside a 24pt left margin.
    expect($rules(['margins' => ['top' => 72, 'right' => 72, 'bottom' => 72, 'left' => 24], 'border' => ['width' => 6, 'inset' => 20]]))
        ->toContain('frame-overlap')
        ->and($rules(['border' => ['width' => 6, 'inset' => 20]]))->not->toContain('frame-overlap')
        ->and($rules(['margins' => ['top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10], 'border' => ['style' => 'none']]))->not->toContain('frame-overlap');
});

it('describes the background and the frame in the JSON schema', function () {
    $page = Agent::jsonSchema()['properties']['page']['properties'];

    expect($page)->toHaveKeys(['background', 'border'])
        ->and($page['border']['properties'])->toHaveKeys(['style', 'width', 'color', 'inset']);
});
