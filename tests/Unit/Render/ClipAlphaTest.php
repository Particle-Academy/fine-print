<?php

declare(strict_types=1);

use FinePrint\Render\Renderer;
use FinePrint\Exceptions\RefusedException;
use FinePrint\Tests\Support\PdfBuilder;

it('clips subsequent painting to the nonzero path', function () {
    $r = Renderer::render(fprPage('20 20 20 20 re W n 1 0 0 rg 0 0 100 100 re f'));
    expect($r->pixel(25, 75))->toBe([255, 0, 0])->and($r->pixel(10, 75))->toBe([255, 255, 255])
        ->and($r->complete())->toBeTrue();
});

it('applies a pending clip after painting its path', function () {
    $r = Renderer::render(fprPage('8 w 10 10 20 20 re W S 0 0 1 rg 0 0 100 100 re f'));
    expect($r->pixel(7, 80))->toBe([0, 0, 0])->and($r->pixel(12, 80))->toBe([0, 0, 255])
        ->and($r->pixel(35, 80))->toBe([255, 255, 255])->and($r->complete())->toBeTrue();
});

it('intersects clips and restores them through q and Q', function () {
    $r = Renderer::render(fprPage('0 0 40 100 re W n q 0 0 10 100 re W n 1 0 0 rg 0 0 100 100 re f Q 0 0 1 rg 20 0 20 100 re f'));
    expect($r->pixel(5, 50))->toBe([255, 0, 0])->and($r->pixel(15, 50))->toBe([255, 255, 255])
        ->and($r->pixel(25, 50))->toBe([0, 0, 255])->and($r->pixel(45, 50))->toBe([255, 255, 255])
        ->and($r->complete())->toBeTrue();
});

it('distinguishes even-odd and nonzero clipping', function (string $operator, array $centre) {
    $r = Renderer::render(fprPage('0 0 100 100 re 20 20 60 60 re '.$operator.' n 1 0 0 rg 0 0 100 100 re f'));
    expect($r->pixel(5, 5))->toBe([255, 0, 0])->and($r->pixel(50, 50))->toBe($centre)
        ->and($r->complete())->toBeTrue();
})->with(['nonzero' => ['W', [255, 0, 0]], 'even odd' => ['W*', [255, 255, 255]]]);

it('makes an empty clipping path an empty clip', function () {
    $r = Renderer::render(fprPage('W n 0 0 100 100 re f'));
    expect($r->pixel(50, 50))->toBe([255, 255, 255])->and($r->complete())->toBeTrue();
});

it('clips a form to its BBox and restores the parent clip afterwards', function () {
    $pdf = fprPage('/Form Do 0 0 1 rg 50 50 10 10 re f', resources: '/XObject << /Form 6 0 R >>', extra: function (PdfBuilder $b) {
        $b->stream('1 0 0 rg 0 0 100 100 re f', '/Subtype /Form /BBox [10 10 20 20]');
    });
    $r = Renderer::render($pdf);
    expect($r->pixel(15, 85))->toBe([255, 0, 0])->and($r->pixel(5, 85))->toBe([255, 255, 255])
        ->and($r->pixel(55, 45))->toBe([0, 0, 255])->and($r->complete())->toBeTrue();
});

it('blends constant fill alpha with the existing raster', function () {
    $pdf = fprPage('0 0 1 rg 0 0 100 100 re f /Half gs 1 0 0 rg 0 0 100 100 re f', resources: '/ExtGState << /Half << /ca 0.5 >> >>');
    $r = Renderer::render($pdf);
    expect($r->pixel(50, 50))->toBe([128, 0, 128])->and($r->complete())->toBeTrue();
});

it('keeps stroke and fill alpha separate and restores alpha with Q', function () {
    $pdf = fprPage('/Alpha gs 0 1 0 RG 10 w 20 10 m 20 90 l S q /Clear gs 1 0 0 rg 40 10 10 80 re f Q 1 0 0 rg 60 10 10 80 re f', resources: '/ExtGState << /Alpha << /CA 0.25 /ca 0.5 >> /Clear << /ca 0 >> >>');
    $r = Renderer::render($pdf);
    expect($r->pixel(20, 50))->toBe([191, 255, 191])->and($r->pixel(45, 50))->toBe([255, 255, 255])
        ->and($r->pixel(65, 50))->toBe([255, 128, 128])->and($r->complete())->toBeTrue();
});

it('unions every text clipping glyph until ET', function () {
    $r = fptRender('BT /T1 10 Tf 7 Tr 1 0 0 1 10 10 Tm (I) Tj 1 0 0 1 30 10 Tm (I) Tj ET 1 0 0 rg 0 0 100 100 re f');
    expect($r->pixel(15, 85))->toBe([255, 0, 0])->and($r->pixel(35, 85))->toBe([255, 0, 0])
        ->and($r->pixel(25, 85))->toBe([255, 255, 255])->and($r->complete())->toBeTrue();
});

it('paints all text before applying its accumulated text clip', function () {
    $r = fptRender('1 0 0 rg BT /T1 10 Tf 4 Tr 1 0 0 1 10 10 Tm (I) Tj 1 0 0 1 30 10 Tm (I) Tj ET');
    expect($r->pixel(15, 85))->toBe([255, 0, 0])->and($r->pixel(35, 85))->toBe([255, 0, 0])
        ->and($r->complete())->toBeTrue();
});

it('clips image painting as well as vector spans', function () {
    $pdf = fprPage('20 20 20 20 re W n 100 0 0 100 0 0 cm /Im Do', resources: '/XObject << /Im 6 0 R >>', extra: function (PdfBuilder $b) {
        $b->stream("\xFF\0\0", '/Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceRGB /BitsPerComponent 8');
    });
    $r = Renderer::render($pdf);
    expect($r->pixel(25, 75))->toBe([255, 0, 0])->and($r->pixel(10, 75))->toBe([255, 255, 255])
        ->and($r->complete())->toBeTrue();
});

it('applies a grayscale image soft mask at its own resolution', function () {
    $pdf = fprPage('100 0 0 100 0 0 cm /Im Do', resources: '/XObject << /Im 6 0 R >>', extra: function (PdfBuilder $b) {
        $b->stream("\xFF\0\0", '/Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceRGB /BitsPerComponent 8 /SMask 7 0 R');
        $b->stream("\0\x80", '/Subtype /Image /Width 2 /Height 1 /ColorSpace /DeviceGray /BitsPerComponent 8');
    });
    $r = Renderer::render($pdf);
    expect($r->pixel(20, 50))->toBe([255, 255, 255])->and($r->pixel(80, 50))->toBe([255, 127, 127])
        ->and($r->complete())->toBeTrue();
});

it('applies an explicit stencil image mask', function () {
    $pdf = fprPage('100 0 0 100 0 0 cm /Im Do', resources: '/XObject << /Im 6 0 R >>', extra: function (PdfBuilder $b) {
        $b->stream("\xFF\0\0", '/Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Mask 7 0 R');
        $b->stream("\x40", '/Subtype /Image /Width 2 /Height 1 /ImageMask true');
    });
    $r = Renderer::render($pdf);
    expect($r->pixel(20, 50))->toBe([255, 0, 0])->and($r->pixel(80, 50))->toBe([255, 255, 255])
        ->and($r->complete())->toBeTrue();
});

it('applies colour-key masks to samples before Decode', function () {
    $pdf = fprPage('100 0 0 100 0 0 cm /Im Do', resources: '/XObject << /Im 6 0 R >>', extra: function (PdfBuilder $b) {
        $b->stream("\xFF\0\0\0\0\xFF", '/Subtype /Image /Width 2 /Height 1 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Decode [1 0 1 0 1 0] /Mask [255 255 0 0 0 0]');
    });
    $r = Renderer::render($pdf);
    expect($r->pixel(20, 50))->toBe([255, 255, 255])->and($r->pixel(80, 50))->toBe([255, 255, 0])
        ->and($r->complete())->toBeTrue();
});

it('reports recursive image masks instead of following them', function () {
    $pdf = fprPage('100 0 0 100 0 0 cm /Im Do', resources: '/XObject << /Im 6 0 R >>', extra: fn (PdfBuilder $b) => $b->stream("\0", '/Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceGray /BitsPerComponent 8 /SMask 6 0 R'));
    expect(Renderer::render($pdf)->complete())->toBeFalse();
});

it('reports transparency groups that are outside the supported alpha subset', function () {
    $pdf = fprPage('0 0 1 rg 0 0 100 100 re f /Half gs /Form Do', resources: '/ExtGState << /Half << /ca 0.5 >> >> /XObject << /Form 6 0 R >>', extra: fn (PdfBuilder $b) => $b->stream('1 0 0 rg 0 0 50 50 re f 20 0 50 50 re f', '/Subtype /Form /BBox [0 0 100 100] /Group << /S /Transparency /I true >>'));
    $r = Renderer::render($pdf);
    expect($r->complete())->toBeFalse()->and(array_column($r->skipped, 'kind'))->toContain('transparency');
});

it('bounds saved clipping masks by the render memory budget', function () {
    $pdf = fprPage(str_repeat('q 0 0 300 300 re W n ', 300), '[0 0 300 300]');
    try {
        Renderer::render($pdf, limits: ['memoryBytes' => 2 * 1024 * 1024]);
        test()->fail('Saved masks should exceed the small working-memory budget.');
    } catch (RefusedException $e) {
        expect($e->reason)->toBe('memory_budget');
    }
});

it('tracks unsupported blend modes independently of soft-mask resets', function (string $ops, bool $complete) {
    $pdf = fprPage($ops.' 0 0 100 100 re f', resources: '/ExtGState << /Multiply << /BM /Multiply >> /NoMask << /SMask /None >> /Normal << /BM /Normal >> >>');
    $r = Renderer::render($pdf);
    expect($r->complete())->toBe($complete);
    if (! $complete) {
        expect(array_column($r->skipped, 'kind'))->toContain('transparency');
    }
})->with([
    'mask reset preserves blend' => ['/Multiply gs /NoMask gs', false],
    'normal clears blend' => ['/Multiply gs /Normal gs', true],
]);
