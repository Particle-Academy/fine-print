<?php

declare(strict_types=1);

use FinePrint\Exceptions\RefusedException;
use FinePrint\Render\Renderer;
use FinePrint\Tests\Support\ImageBuilder;
use FinePrint\Tests\Support\JpegBuilder;
use FinePrint\Tests\Support\PdfBuilder;

function fpjPage(string $jpeg, int $w, int $h, int $components = 1, string $extra = ''): string
{
    $space = [1 => 'DeviceGray', 3 => 'DeviceRGB', 4 => 'DeviceCMYK'][$components];

    return fprPage("$w 0 0 $h 0 0 cm /Photo Do", "[0 0 $w $h]", '', '/XObject << /Photo 6 0 R >>', function (PdfBuilder $b) use ($jpeg, $w, $h, $space, $extra) {
        $b->rawStream($jpeg, "/Subtype /Image /Width $w /Height $h /BitsPerComponent 8 /ColorSpace /$space /Filter /DCTDecode $extra");
    });
}

it('renders a known baseline JPEG from the original writer fixture builder', function (int $components) {
    $r = Renderer::render(fpjPage(ImageBuilder::jpeg(9, 7, $components), 9, 7, $components));
    expect($r->pixel(0, 0))->toBe([128, 128, 128])->and($r->pixel(8, 6))->toBe([128, 128, 128])
        ->and($r->complete())->toBeTrue();
})->with([1, 3]);

it('decodes signed DC differences and discards MCU padding', function () {
    $jpeg = JpegBuilder::make(19, 9, [fn (int $x, int $y) => [20, 230, 80][$x] + $y]);
    $r = Renderer::render(fpjPage($jpeg, 19, 9));
    expect($r->pixel(0, 0))->toBe([20, 20, 20])->and($r->pixel(8, 0))->toBe([230, 230, 230])
        ->and($r->pixel(18, 0))->toBe([80, 80, 80])->and($r->pixel(18, 8))->toBe([81, 81, 81])
        ->and($r->complete())->toBeTrue();
});

it('decodes AC run lengths and IDCT in the correct zigzag positions', function (int $coefficient) {
    $r = Renderer::render(fpjPage(JpegBuilder::make(8, 8, [128], ['ac' => [$coefficient => 80]]), 8, 8));
    $u = $coefficient % 8;
    $v = intdiv($coefficient, 8);
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $expected = (int) round(128 + 20 * ($u === 0 ? 1 / sqrt(2) : 1) * ($v === 0 ? 1 / sqrt(2) : 1)
                * cos((2 * $x + 1) * $u * M_PI / 16) * cos((2 * $y + 1) * $v * M_PI / 16));
            expect(abs($r->pixel($x, $y)[0] - $expected))->toBeLessThanOrEqual(1);
        }
    }
    expect($r->complete())->toBeTrue();
})->with([1, 8, 16, 63]);

it('decodes subsampled YCbCr with odd dimensions', function () {
    $jpeg = JpegBuilder::make(19, 17, [76, 85, 255], ['sampling' => [[2, 2], [1, 1], [1, 1]]]);
    $r = Renderer::render(fpjPage($jpeg, 19, 17, 3));
    foreach ([[0, 0], [18, 0], [0, 16], [18, 16]] as [$x, $y]) {
        [$red, $green, $blue] = $r->pixel($x, $y);
        expect($red)->toBeGreaterThanOrEqual(253)->and($green)->toBeLessThanOrEqual(1)->and($blue)->toBeLessThanOrEqual(1);
    }
    expect($r->complete())->toBeTrue();
});

it('honours the PDF DCT ColorTransform override', function () {
    $jpeg = JpegBuilder::make(8, 8, [30, 100, 200]);
    $r = Renderer::render(fpjPage($jpeg, 8, 8, 3, '/DecodeParms << /ColorTransform 0 >>'));
    expect($r->pixel(0, 0))->toBe([30, 100, 200])->and($r->complete())->toBeTrue();
});

it('preserves Adobe CMYK inversion for the PDF Decode array', function () {
    $jpeg = JpegBuilder::make(8, 8, [255, 0, 0, 255], ['adobe' => 0]);
    $r = Renderer::render(fpjPage($jpeg, 8, 8, 4, '/Decode [1 0 1 0 1 0 1 0]'));
    expect($r->pixel(0, 0))->toBe([255, 0, 0])->and($r->complete())->toBeTrue();
});

it('resets DC predictions at restart intervals and handles stuffed entropy bytes', function () {
    $jpeg = JpegBuilder::make(48, 8, [fn (int $x) => [0, 255, 128, 230, 20, 170][$x]], ['restart' => 1]);
    $r = Renderer::render(fpjPage($jpeg, 48, 8));
    foreach ([0, 255, 128, 230, 20, 170] as $i => $gray) {
        expect($r->pixel($i * 8, 0))->toBe([$gray, $gray, $gray]);
    }
    expect($r->complete())->toBeTrue();
});

it('checks the JPEG own dimensions before allocating component planes', function () {
    $jpeg = JpegBuilder::make(8, 8);
    $at = strpos($jpeg, "\xFF\xC0");
    $jpeg = substr_replace($jpeg, pack('nn', 65535, 65535), $at + 5, 4);
    try {
        Renderer::render(fpjPage($jpeg, 1, 1));
        test()->fail('Oversized JPEG should be refused.');
    } catch (RefusedException $e) {
        expect($e->reason)->toBe('too_many_pixels');
    }
});

it('supports separate sequential component scans', function () {
    $r = Renderer::render(fpjPage(JpegBuilder::separate(9, 7, [30, 100, 200]), 9, 7, 3, '/DecodeParms << /ColorTransform 0 >>'));
    expect($r->pixel(8, 6))->toBe([30, 100, 200])->and($r->complete())->toBeTrue();
});

it('dequantises both DC and AC coefficients', function () {
    $r = Renderer::render(fpjPage(JpegBuilder::make(8, 8, [80], ['quant' => 4, 'ac' => [1 => 20]]), 8, 8));
    expect($r->pixel(0, 0)[0])->toBeGreaterThanOrEqual(93)->toBeLessThanOrEqual(95)
        ->and($r->pixel(7, 0)[0])->toBeGreaterThanOrEqual(65)->toBeLessThanOrEqual(67)
        ->and($r->complete())->toBeTrue();
});

it('lets Adobe APP14 override the dictionary transform', function () {
    $jpeg = JpegBuilder::make(8, 8, [30, 100, 200], ['adobe' => 0]);
    $r = Renderer::render(fpjPage($jpeg, 8, 8, 3, '/DecodeParms << /ColorTransform 1 >>'));
    expect($r->pixel(0, 0))->toBe([30, 100, 200])->and($r->complete())->toBeTrue();
});

it('converts Adobe YCCK before the PDF Decode inversion', function () {
    $jpeg = JpegBuilder::make(8, 8, [179, 171, 0, 255], ['adobe' => 2]);
    $r = Renderer::render(fpjPage($jpeg, 8, 8, 4, '/Decode [1 0 1 0 1 0 1 0]'));
    [$red, $green, $blue] = $r->pixel(0, 0);
    expect($red)->toBeGreaterThanOrEqual(253)->and($green)->toBeLessThanOrEqual(1)->and($blue)->toBeLessThanOrEqual(1)
        ->and($r->complete())->toBeTrue();
});

it('handles non-dictionary DecodeParms without a PHP fatal error', function (string $params) {
    $r = Renderer::render(fpjPage(JpegBuilder::make(8, 8), 8, 8, extra: '/DecodeParms '.$params));
    expect($r->complete())->toBeFalse();
})->with(['/Invalid', '[/Invalid]']);

it('refuses JPEG decoder work when the render deadline is exhausted', function () {
    $jpeg = ImageBuilder::jpeg(1024, 1024);
    expect(fn () => Renderer::render(fpjPage($jpeg, 1, 1, 3), limits: ['renderTimeSeconds' => 0.001]))->toThrow(RefusedException::class);
});

it('survives mutated JPEG headers and entropy without any PHP error escaping', function () {
    $original = JpegBuilder::make(8, 8, [40], ['ac' => [1 => 20]]);
    $outcomes = 0;
    for ($i = 2; $i < strlen($original); $i += 7) {
        $mutant = $original;
        $mutant[$i] = chr(ord($mutant[$i]) ^ 255);
        try {
            Renderer::render(fpjPage($mutant, 8, 8), limits: ['maxImagePixels' => 4096]);
        } catch (RefusedException $e) {
            expect($e->reason)->toBeIn(RefusedException::REASONS);
        }
        $outcomes++;
    }
    expect($outcomes)->toBeGreaterThan(30);
});

it('reports progressive and truncated JPEGs rather than claiming complete rendering', function (Closure $change) {
    $jpeg = $change(JpegBuilder::make(8, 8));
    $r = Renderer::render(fpjPage($jpeg, 8, 8));
    expect($r->complete())->toBeFalse()->and(array_column($r->skipped, 'kind'))->toContain('image');
})->with([
    'progressive' => [fn (string $jpeg) => str_replace("\xFF\xC0", "\xFF\xC2", $jpeg)],
    'truncated' => [fn (string $jpeg) => substr($jpeg, 0, -4)],
    'missing EOI' => [fn (string $jpeg) => substr($jpeg, 0, -2)],
]);
