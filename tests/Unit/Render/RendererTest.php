<?php

declare(strict_types=1);

use FinePrint\Document;
use FinePrint\Render\Renderer;
use FinePrint\Render\Rendering;
use FinePrint\Tests\Support\PdfBuilder;
use FinePrint\Tests\Support\PngReader;

/*
 * The content-stream interpreter, end to end: a PDF written by tests/Support
 * goes in, pixels come out. Shapes sit on whole points at scale 1 so the
 * expected pixels are exact.
 */

function fpRender(string $pdf, float $scale = 1.0, int $page = 1): Rendering
{
    return Renderer::render($pdf, $page, $scale);
}

/** The pixel at (x, y), top-left origin, as [r, g, b]. */
function fpPx(Rendering $r, int $x, int $y): array
{
    return $r->pixel($x, $y);
}

function fpSkipped(Rendering $r): array
{
    $out = [];
    foreach ($r->skipped as $s) {
        $out[$s['kind']] = $s['count'];
    }

    return $out;
}

const FP_WHITE = [255, 255, 255];
const FP_BLACK = [0, 0, 0];

// --- page geometry ------------------------------------------------------------

it('sizes the raster from the page box and the scale', function () {
    $pdf = fprPage('', '[0 0 200 100]');

    expect([fpRender($pdf)->width, fpRender($pdf)->height])->toBe([200, 100])
        ->and([fpRender($pdf, 2)->width, fpRender($pdf, 2)->height])->toBe([400, 200])
        ->and([fpRender($pdf, 0.5)->width, fpRender($pdf, 0.5)->height])->toBe([100, 50]);
});

it('renders a blank page white', function () {
    $r = fpRender(fprPage(''));

    expect(fpPx($r, 0, 0))->toBe(FP_WHITE)
        ->and(fpPx($r, 99, 99))->toBe(FP_WHITE)
        ->and($r->skipped)->toBe([])
        ->and($r->complete())->toBeTrue();
});

it('puts PDF y-up content at the right place in a y-down raster', function () {
    // A black square at the BOTTOM-left of the page in PDF space.
    $r = fpRender(fprPage('0 0 10 10 re f'));

    expect(fpPx($r, 5, 95))->toBe(FP_BLACK)
        ->and(fpPx($r, 5, 5))->toBe(FP_WHITE)
        ->and(fpPx($r, 15, 95))->toBe(FP_WHITE);
});

it('renders the CropBox, offset to its origin, and clips it to the MediaBox', function () {
    $content = '1 0 0 rg 50 50 10 10 re f';
    $r = fpRender(fprPage($content, '[0 0 100 100]', '/CropBox [40 40 80 90]'));

    expect([$r->width, $r->height])->toBe([40, 50])
        // The red square spans x 50..60, y 50..60 => raster x 10..20, y (90-60)..(90-50) = 30..40.
        ->and(fpPx($r, 15, 35))->toBe([255, 0, 0])
        ->and(fpPx($r, 5, 35))->toBe(FP_WHITE);

    // A CropBox larger than the MediaBox is cut down to it.
    $big = fpRender(fprPage('', '[0 0 100 100]', '/CropBox [-50 -50 300 300]'));
    expect([$big->width, $big->height])->toBe([100, 100]);
});

it('honours /Rotate in all four directions', function (int $rotate, array $size, array $at) {
    // Page 100 wide, 50 tall; a black square at its top-left corner in PDF space (x 0..10, y 40..50).
    $r = fpRender(fprPage('0 40 10 10 re f', '[0 0 100 50]', '/Rotate '.$rotate));

    expect([$r->width, $r->height])->toBe($size)
        ->and($r->rotate)->toBe($rotate)
        ->and(fpPx($r, $at[0], $at[1]))->toBe(FP_BLACK);
})->with([
    'none' => [0, [100, 50], [5, 5]],
    // Rotated clockwise: the page's top-left corner goes to the top-right.
    '90' => [90, [50, 100], [45, 5]],
    '180' => [180, [100, 50], [95, 45]],
    '270' => [270, [50, 100], [5, 95]],
]);

it('normalises odd /Rotate values', function () {
    expect(fpRender(fprPage('', '[0 0 100 50]', '/Rotate -90'))->rotate)->toBe(270)
        ->and(fpRender(fprPage('', '[0 0 100 50]', '/Rotate 450'))->rotate)->toBe(90);
});

it('scales content with the page', function () {
    $r = fpRender(fprPage('0 0 10 10 re f'), 3);

    expect(fpPx($r, 29, 299))->toBe(FP_BLACK)
        ->and(fpPx($r, 30, 299))->toBe(FP_WHITE);
});

it('renders a chosen page of several', function () {
    $pdf = PdfBuilder::textPdf(['1 0 0 rg 0 0 100 100 re f', '0 0 1 rg 0 0 100 100 re f'], ['mediaBox' => '[0 0 100 100]'])->build();

    expect(fpPx(Renderer::render($pdf, 2), 50, 50))->toBe([0, 0, 255])
        ->and(fn () => Renderer::render($pdf, 3))->toThrow(OutOfRangeException::class)
        ->and(fn () => Renderer::render($pdf, 0))->toThrow(OutOfRangeException::class);
});

it('accepts an open Document', function () {
    $doc = Document::open(fprPage('0 1 0 rg 0 0 100 100 re f'));

    expect(fpPx(Renderer::render($doc, 1), 1, 1))->toBe([0, 255, 0]);
});

it('rejects a scale that is not a positive finite number', function (float $scale) {
    expect(fn () => Renderer::render(fprPage(''), 1, $scale))->toThrow(InvalidArgumentException::class);
})->with([0.0, -1.0, NAN, INF]);

// --- colour -------------------------------------------------------------------

it('paints in DeviceGray, DeviceRGB and DeviceCMYK', function () {
    $r = fpRender(fprPage(
        "0.5 g 0 0 20 20 re f\n"
        ."0.2 0.4 0.6 rg 20 0 20 20 re f\n"
        ."1 0 0 0 k 40 0 20 20 re f\n"
        ."0 0 0 1 k 60 0 20 20 re f\n"
        ."0 1 1 0 k 80 0 20 20 re f\n"
    ));

    expect(fpPx($r, 10, 90))->toBe([128, 128, 128])
        ->and(fpPx($r, 30, 90))->toBe([51, 102, 153])
        // CMYK is converted naively: (1-c)(1-k), ...
        ->and(fpPx($r, 50, 90))->toBe([0, 255, 255])
        ->and(fpPx($r, 70, 90))->toBe(FP_BLACK)
        ->and(fpPx($r, 90, 90))->toBe([255, 0, 0]);
});

it('sets stroke and fill colours independently', function () {
    $r = fpRender(fprPage('1 0 0 rg 0 0 1 RG 10 w 20 20 60 60 re B'));

    // Interior red, the stroke band blue (centred on the edge: x 15..25).
    expect(fpPx($r, 50, 50))->toBe([255, 0, 0])
        ->and(fpPx($r, 20, 50))->toBe([0, 0, 255])
        ->and(fpPx($r, 10, 50))->toBe(FP_WHITE);
});

it('resolves named colour spaces: ICCBased by N, CalRGB, CalGray', function () {
    $pdf = fprPage(
        "/CS0 cs 0 0 1 sc 0 0 30 100 re f\n"
        ."/CS1 cs 1 1 0 scn 30 0 30 100 re f\n"
        ."/CS2 cs 0.25 sc 60 0 40 100 re f\n",
        resources: '/ColorSpace << /CS0 [/ICCBased 6 0 R] /CS1 [/CalRGB << /WhitePoint [0.95 1 1.09] >>] /CS2 [/CalGray << /WhitePoint [0.95 1 1.09] >>] >>',
        extra: fn (PdfBuilder $b) => $b->stream('not a real profile', '/N 3'),
    );
    $r = fpRender($pdf);

    expect(fpPx($r, 15, 50))->toBe([0, 0, 255])
        ->and(fpPx($r, 45, 50))->toBe([255, 255, 0])
        ->and(fpPx($r, 80, 50))->toBe([64, 64, 64]);
});

it('resets the colour to the space\'s initial value when the space is set', function () {
    // cs to DeviceRGB resets to black; the red set before it is gone.
    $r = fpRender(fprPage('1 0 0 rg /DeviceRGB cs 0 0 100 100 re f'));

    expect(fpPx($r, 50, 50))->toBe(FP_BLACK);
});

it('skips painting with a pattern and reports it', function () {
    $r = fpRender(fprPage('/Pattern cs /P0 scn 0 0 100 100 re f'));

    expect(fpPx($r, 50, 50))->toBe(FP_WHITE)
        ->and(fpSkipped($r))->toBe(['pattern' => 1]);
});

it('approximates a Separation colour as grey and reports it', function () {
    $r = fpRender(fprPage(
        '/Sp cs 1 sc 0 0 100 100 re f',
        resources: '/ColorSpace << /Sp [/Separation /Spot /DeviceCMYK << /FunctionType 2 /Domain [0 1] /C0 [0 0 0 0] /C1 [0 0 0 1] /N 1 >>] >>',
    ));

    expect(fpPx($r, 50, 50))->toBe(FP_BLACK)
        ->and(fpSkipped($r))->toBe(['colorspace' => 1]);
});

// --- graphics state -----------------------------------------------------------

it('restores colour and transform on Q', function () {
    $r = fpRender(fprPage("q 1 0 0 rg 1 0 0 1 50 0 cm 0 0 10 10 re f Q\n0 0 10 10 re f"));

    expect(fpPx($r, 55, 95))->toBe([255, 0, 0])
        ->and(fpPx($r, 5, 95))->toBe(FP_BLACK);
});

it('survives unbalanced Q', function () {
    $r = fpRender(fprPage("Q Q 1 0 0 rg 0 0 10 10 re f Q"));

    expect(fpPx($r, 5, 95))->toBe([255, 0, 0]);
});

it('concatenates cm: scale, translate and a 90 degree rotation', function () {
    // Rotate 90 (x' = -y, y' = x), then move by (60, 20): the 20 x 10
    // rectangle lands on PDF x 50..60, y 20..40, which is raster y 60..80.
    $r = fpRender(fprPage('1 0 0 1 60 20 cm 0 1 -1 0 0 0 cm 0 0 20 10 re f'));

    expect(fpPx($r, 55, 75))->toBe(FP_BLACK)
        ->and(fpPx($r, 55, 62))->toBe(FP_BLACK)
        ->and(fpPx($r, 45, 75))->toBe(FP_WHITE)
        ->and(fpPx($r, 65, 75))->toBe(FP_WHITE)
        ->and(fpPx($r, 55, 55))->toBe(FP_WHITE)
        ->and(fpPx($r, 55, 85))->toBe(FP_WHITE);
});

it('applies line width, cap and dash from an ExtGState', function () {
    $r = fpRender(fprPage(
        '/GS0 gs 10 50 m 90 50 l S',
        resources: '/ExtGState << /GS0 << /Type /ExtGState /LW 10 /LC 2 /D [[20 20] 0] >> >>',
    ));

    // Square caps: the first dash runs x 5..35 (0..20 plus 5 each side), the second 45..75.
    expect(fpPx($r, 7, 50))->toBe(FP_BLACK)
        ->and(fpPx($r, 40, 50))->toBe(FP_WHITE)
        ->and(fpPx($r, 50, 50))->toBe(FP_BLACK)
        // Width 10 about raster y 50: rows 45..54.
        ->and(fpPx($r, 50, 46))->toBe(FP_BLACK)
        ->and(fpPx($r, 50, 44))->toBe(FP_WHITE)
        ->and(fpPx($r, 50, 56))->toBe(FP_WHITE);
});

it('reports transparency it does not apply', function () {
    $r = fpRender(fprPage(
        '/GS0 gs 0 0 100 100 re f',
        resources: '/ExtGState << /GS0 << /ca 0.5 >> >>',
    ));

    expect(fpSkipped($r))->toBe(['transparency' => 1]);
});

// --- paths and painting ---------------------------------------------------------

it('fills even-odd with f* and nonzero with f', function () {
    $outer = '10 10 m 90 10 l 90 90 l 10 90 l h ';
    $inner = '30 30 m 70 30 l 70 70 l 30 70 l h ';

    expect(fpPx(fpRender(fprPage($outer.$inner.'f')), 50, 50))->toBe(FP_BLACK)
        ->and(fpPx(fpRender(fprPage($outer.$inner.'f*')), 50, 50))->toBe(FP_WHITE)
        ->and(fpPx(fpRender(fprPage($outer.$inner.'f*')), 20, 50))->toBe(FP_BLACK);
});

it('treats F as f', function () {
    expect(fpPx(fpRender(fprPage('0 0 50 50 re F')), 25, 75))->toBe(FP_BLACK);
});

it('fills with v and y curves', function () {
    // v: first control point is the current point; y: second is the end point.
    $r = fpRender(fprPage('10 10 m 90 90 90 10 v h f 10 90 m 10 50 50 90 y h f'));

    expect(fpPx($r, 80, 70))->toBe(FP_BLACK)
        ->and(fpPx($r, 12, 15))->toBe(FP_BLACK);
});

it('closes a path with s and b, and paints nothing with n', function () {
    $r = fpRender(fprPage('4 w 20 20 m 80 20 l 80 80 l s'));
    // The closing edge runs from (80,80) back to (20,20): its midpoint (50,50) is inked.
    expect(fpPx($r, 50, 50))->toBe(FP_BLACK);

    $n = fpRender(fprPage('0 0 100 100 re n'));
    expect(fpPx($n, 50, 50))->toBe(FP_WHITE);
});

it('draws a zero-width line as a one-pixel hairline', function () {
    // y 50.375 puts the line on raster row 198's centre at scale 4.
    $r = fpRender(fprPage('0 w 0 50.375 m 100 50.375 l S'), 4);

    $dark = 0;
    for ($y = 190; $y < 216; $y++) {
        if (fpPx($r, 200, $y)[0] < 128) {
            $dark++;
        }
    }
    expect($dark)->toBe(1);
});

it('ignores path operators with too few operands instead of failing', function () {
    $r = fpRender(fprPage("10 m 1 2 3 c 5 re 1 0 0 rg 0 0 10 10 re f"));

    expect(fpPx($r, 5, 95))->toBe([255, 0, 0]);
});

// --- form XObjects --------------------------------------------------------------

it('draws a form XObject through its Matrix, twice', function () {
    $pdf = fprPage(
        '1 0 0 rg /Fm0 Do q 1 0 0 1 50 0 cm /Fm0 Do Q',
        resources: '/XObject << /Fm0 6 0 R >>',
        extra: fn (PdfBuilder $b) => $b->stream('0 0 10 10 re f', '/Type /XObject /Subtype /Form /BBox [0 0 20 20] /Matrix [2 0 0 2 0 0]'),
    );
    $r = fpRender($pdf);

    // The 10x10 square is doubled to 20x20, at x 0 and x 50; it inherits the red fill.
    expect(fpPx($r, 15, 85))->toBe([255, 0, 0])
        ->and(fpPx($r, 65, 85))->toBe([255, 0, 0])
        ->and(fpPx($r, 35, 85))->toBe(FP_WHITE)
        ->and($r->complete())->toBeTrue();
});

it('gives a form its own resources and restores state after it', function () {
    $pdf = fprPage(
        '/Fm0 Do 0 0 10 10 re f',
        resources: '/XObject << /Fm0 6 0 R >>',
        extra: fn (PdfBuilder $b) => $b->stream('/G cs 0 1 0 sc 50 50 10 10 re f', '/Type /XObject /Subtype /Form /BBox [0 0 100 100] /Resources << /ColorSpace << /G /DeviceRGB >> >>'),
    );
    $r = fpRender($pdf);

    expect(fpPx($r, 55, 45))->toBe([0, 255, 0])
        // The form's green did not leak out: the page's square is black.
        ->and(fpPx($r, 5, 95))->toBe(FP_BLACK);
});

it('draws nested forms', function () {
    $pdf = fprPage(
        '/A Do',
        resources: '/XObject << /A 6 0 R >>',
        extra: function (PdfBuilder $b) {
            $b->stream('1 0 0 1 10 0 cm /B Do', '/Type /XObject /Subtype /Form /BBox [0 0 100 100] /Resources << /XObject << /B 7 0 R >> >>');
            $b->stream('0 0 1 rg 0 0 10 10 re f', '/Type /XObject /Subtype /Form /BBox [0 0 100 100]');
        },
    );

    expect(fpPx(fpRender($pdf), 15, 95))->toBe([0, 0, 255]);
});

// --- the skipped report ---------------------------------------------------------

it('skips text in a font with no embedded program and says so', function () {
    // Optima is not a standard-14 face, so nothing bundled stands in for it.
    $content = 'BT /F1 12 Tf 10 50 Td (Hello) Tj ET BT /F1 12 Tf 10 30 Td (again) Tj ET BT /F1 9 Tf [(a) 5 (b)] TJ (c) \' ET';
    $r = fpRender(fptDoc($content, ['F1' => fptPlain('<< /Type /Font /Subtype /Type1 /BaseFont /Optima >>')]));

    expect(fpSkipped($r))->toBe(['text-unembedded' => 4])
        ->and($r->complete())->toBeFalse();
});

it('reports unsupported inline images, clipping, shading and optional content', function () {
    $pdf = fprPage(
        "q 10 0 0 10 0 0 cm /Im0 Do Q\n"
        ."BI /W 2 /H 1 /BPC 8 /CS /G ID \x00EI\xFF EI\n"
        ."0 0 50 50 re W n\n"
        ."/Sh0 sh\n"
        ."/OC /L1 BDC 0 0 10 10 re f EMC\n"
        ."1 0 0 rg 60 60 10 10 re f\n",
        resources: '/XObject << /Im0 6 0 R >>',
        extra: fn (PdfBuilder $b) => $b->stream("\x00\x00\x00", '/Type /XObject /Subtype /Image /Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceRGB'),
    );
    $r = fpRender($pdf);

    expect(fpSkipped($r))->toBe([
        'clip' => 1,
        'inline-image' => 1,
        'optional-content' => 1,
        'shading' => 1,
    ])
        // The inline image's data (which holds "EI") did not derail the operators after it.
        ->and(fpPx($r, 65, 35))->toBe([255, 0, 0]);
});

it('reports annotations whose appearance it does not draw', function () {
    $pdf = fprPage('', page: '/Annots [6 0 R 7 0 R]', extra: function (PdfBuilder $b) {
        $b->object('<< /Type /Annot /Subtype /Square /Rect [0 0 10 10] /AP << /N 8 0 R >> >>');
        $b->object('<< /Type /Annot /Subtype /Link /Rect [0 0 10 10] >>');
        $b->stream('0 0 10 10 re f', '/Type /XObject /Subtype /Form /BBox [0 0 10 10]');
    });

    expect(fpSkipped(fpRender($pdf)))->toBe(['annotation' => 1]);
});

it('lists skipped kinds in a stable order with counts', function () {
    $r = fpRender(fprPage("/Sh0 sh /Sh0 sh 0 0 1 1 re W n"));

    expect($r->skipped)->toBe([
        ['kind' => 'clip', 'count' => 1],
        ['kind' => 'shading', 'count' => 2],
    ]);
});

// --- output -----------------------------------------------------------------------

it('encodes the raster as a PNG that reads back pixel for pixel', function () {
    $r = fpRender(fprPage('1 0 0 rg 10 10 30 30 re f 0 0 1 RG 3 w 50 50 m 90 80 l S'));
    $img = PngReader::read($r->png());

    expect($img['width'])->toBe(100)
        ->and($img['height'])->toBe(100)
        ->and($img['rgb'])->toBe($r->rows());
});

it('offers a one-call PNG', function () {
    $png = Renderer::png(fprPage('0 0 10 10 re f'), 1, 2.0);

    expect(PngReader::read($png)['width'])->toBe(200);
});

it('anti-aliases a diagonal edge', function () {
    $r = fpRender(fprPage('0 0 m 100 100 l 100 0 l h f'));

    // Raster pixel (50, 49) is PDF x 50..51, y 50..51: the diagonal halves it.
    $p = fpPx($r, 50, 49);
    expect($p[0])->toBeGreaterThan(100)->toBeLessThan(156);
});

it('renders what the P2 writer makes completely, standard-font text included', function () {
    $pdf = fpwWrite(['blocks' => [
        ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Title']]],
        fpwPara('Body text.'),
        ['type' => 'table', 'rows' => [['cells' => [['blocks' => [fpwPara('a')]], ['blocks' => [fpwPara('b')]]]]]],
    ]]);
    $r = Renderer::render($pdf, 1, 1.0);

    expect($r->width)->toBe(612)
        ->and($r->skipped)->toBe([]);
});
