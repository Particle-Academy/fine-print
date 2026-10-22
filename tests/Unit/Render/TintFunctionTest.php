<?php

declare(strict_types=1);

use FinePrint\Exceptions\RefusedException;
use FinePrint\Render\Renderer;
use FinePrint\Tests\Support\PdfBuilder;

it('renders Separation through its exponential tint transform', function () {
    $r = Renderer::render(fprPage('/Spot cs 0.25 sc 0 0 100 100 re f', resources: '/ColorSpace << /Spot [/Separation /Ink /DeviceRGB << /FunctionType 2 /Domain [0 1] /C0 [1 0 0] /C1 [0 0 1] /N 1 >>] >>'));
    expect($r->pixel(50, 50))->toBe([191, 0, 64])->and($r->complete())->toBeTrue();
});

it('evaluates the exponent and clamps inputs to the function domain', function (float $input, array $expected) {
    $r = Renderer::render(fprPage('/Spot cs '.$input.' sc 0 0 100 100 re f', resources: '/ColorSpace << /Spot [/Separation /Ink /DeviceRGB << /FunctionType 2 /Domain [0 1] /C0 [1 0 0] /C1 [0 0 1] /N 2 >>] >>'));
    expect($r->pixel(50, 50))->toBe($expected)->and($r->complete())->toBeTrue();
})->with(['squared' => [0.5, [191, 0, 64]], 'below' => [-1.0, [255, 0, 0]], 'above' => [2.0, [0, 0, 255]]]);

it('interpolates a sampled Separation tint transform', function () {
    $pdf = fprPage('/Spot cs 0.25 sc 0 0 100 100 re f', resources: '/ColorSpace << /Spot [/Separation /Ink /DeviceRGB 6 0 R] >>', extra: function (PdfBuilder $b) {
        $b->stream("\xFF\0\0\0\0\xFF", '/FunctionType 0 /Domain [0 1] /Range [0 1 0 1 0 1] /Size [2] /BitsPerSample 8', true);
    });
    $r = Renderer::render($pdf);
    expect($r->pixel(50, 50))->toBe([191, 0, 64])->and($r->complete())->toBeTrue();
});

it('multilinearly interpolates DeviceN with the first input varying fastest', function () {
    $pdf = fprPage('/Spot cs 0.25 0.5 scn 0 0 100 100 re f', resources: '/ColorSpace << /Spot [/DeviceN [/One /Two] /DeviceRGB 6 0 R] >>', extra: function (PdfBuilder $b) {
        $b->stream(pack('C*', 255, 0, 0, 0, 255, 0, 0, 0, 255, 255, 255, 255), '/FunctionType 0 /Domain [0 1 0 1] /Range [0 1 0 1 0 1] /Size [2 2] /BitsPerSample 8');
    });
    $r = Renderer::render($pdf);
    expect($r->pixel(50, 50))->toBe([128, 64, 128])->and($r->complete())->toBeTrue();
});

it('applies sampled Encode and Decode arrays', function () {
    $pdf = fprPage('/Spot cs 0.25 sc 0 0 100 100 re f', resources: '/ColorSpace << /Spot [/Separation /Ink /DeviceGray 6 0 R] >>', extra: function (PdfBuilder $b) {
        $b->stream("\0\xFF", '/FunctionType 0 /Domain [0 1] /Range [0 1] /Size [2] /BitsPerSample 8 /Encode [1 0] /Decode [0.2 0.6]');
    });
    $r = Renderer::render($pdf);
    expect($r->pixel(50, 50))->toBe([128, 128, 128])->and($r->complete())->toBeTrue();
});

it('reports calculator functions rather than executing their programs', function () {
    $pdf = fprPage('/Spot cs 0.25 sc 0 0 100 100 re f', resources: '/ColorSpace << /Spot [/Separation /Ink /DeviceRGB 6 0 R] >>', extra: fn (PdfBuilder $b) => $b->stream('{ arbitrary_program_should_never_run }', '/FunctionType 4 /Domain [0 1] /Range [0 1 0 1 0 1]'));
    $r = Renderer::render($pdf);
    expect(array_column($r->skipped, 'kind'))->toContain('colorspace');
});

it('reads packed sampled-function values at every PDF sample depth', function (int $bits, string $data) {
    $pdf = fprPage('/Spot cs 0.25 sc 0 0 100 100 re f', resources: '/ColorSpace << /Spot [/Separation /Ink /DeviceGray 6 0 R] >>', extra: fn (PdfBuilder $b) => $b->stream($data, '/FunctionType 0 /Domain [0 1] /Range [0 1] /Size [2] /BitsPerSample '.$bits));
    $r = Renderer::render($pdf);
    expect($r->pixel(50, 50))->toBe([64, 64, 64])->and($r->complete())->toBeTrue();
})->with([
    [1, "\x40"], [2, "\x30"], [4, "\x0F"], [8, "\0\xFF"],
    [12, "\0\x0F\xFF"], [16, pack('n*', 0, 65535)],
    [24, "\0\0\0\xFF\xFF\xFF"], [32, pack('N*', 0, 4294967295)],
]);

it('refuses an oversized sampled function before decoding its data', function () {
    $pdf = fprPage('/Spot cs 0.25 sc 0 0 100 100 re f', resources: '/ColorSpace << /Spot [/Separation /Ink /DeviceRGB 6 0 R] >>', extra: fn (PdfBuilder $b) => $b->stream('x', '/FunctionType 0 /Domain [0 1] /Range [0 1 0 1 0 1] /Size [2147483647] /BitsPerSample 32'));
    expect(fn () => Renderer::render($pdf))->toThrow(RefusedException::class);
});
