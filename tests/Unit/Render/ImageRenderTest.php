<?php

declare(strict_types=1);

use FinePrint\Exceptions\RefusedException;
use FinePrint\Render\Renderer;
use FinePrint\Tests\Support\ImageBuilder;
use FinePrint\Tests\Support\PdfBuilder;

/** A 20-point image on a 40-point page; source rows run from top to bottom. */
function fpiPage(string $bytes, string $dict, string $paint = '20 0 0 20 10 10 cm /Im Do', ?callable $extra = null): string
{
    return fprPage($paint, '[0 0 40 40]', '', '/XObject << /Im 6 0 R >>', function (PdfBuilder $b) use ($bytes, $dict, $extra) {
        $b->rawStream($bytes, '/Type /XObject /Subtype /Image '.$dict);
        $extra?->__invoke($b);
    });
}

it('renders RGB image rows in PDF image order and preserves the surrounding page', function () {
    $r = Renderer::render(fpiPage(pack('C*', 255, 0, 0, 0, 255, 0, 0, 0, 255, 255, 255, 0), '/Width 2 /Height 2 /BitsPerComponent 8 /ColorSpace /DeviceRGB'));

    expect($r->pixel(12, 12))->toBe([255, 0, 0])
        ->and($r->pixel(27, 12))->toBe([0, 255, 0])
        ->and($r->pixel(12, 27))->toBe([0, 0, 255])
        ->and($r->pixel(27, 27))->toBe([255, 255, 0])
        ->and($r->pixel(5, 5))->toBe([255, 255, 255])
        ->and($r->complete())->toBeTrue();
});

it('decodes packed samples with row padding at every supported bit depth', function (int $bits, string $bytes, array $expected) {
    $r = Renderer::render(fpiPage($bytes, '/Width 3 /Height 2 /BitsPerComponent '.$bits.' /ColorSpace /DeviceGray'));
    foreach ([12, 20, 27] as $i => $x) {
        expect($r->pixel($x, 12))->toBe(array_fill(0, 3, $expected[$i]))
            ->and($r->pixel($x, 27))->toBe(array_fill(0, 3, $expected[2 - $i]));
    }
    expect($r->complete())->toBeTrue();
})->with([
    '1 bit' => [1, "\x40\x40", [0, 255, 0]],
    '2 bits' => [2, "\x1C\xD0", [0, 85, 255]],
    '4 bits' => [4, "\x08\xF0\xF8\x00", [0, 136, 255]],
    '8 bits' => [8, "\x00\x80\xFF\xFF\x80\x00", [0, 128, 255]],
    '16 bits' => [16, pack('n*', 0, 32768, 65535, 65535, 32768, 0), [0, 128, 255]],
]);

it('applies Decode arrays before colour conversion', function () {
    $r = Renderer::render(fpiPage("\x00\xFF", '/Width 2 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceGray /Decode [1 0]'));
    expect($r->pixel(12, 20))->toBe([255, 255, 255])
        ->and($r->pixel(27, 20))->toBe([0, 0, 0]);
});

it('renders CMYK samples', function () {
    $r = Renderer::render(fpiPage("\x00\xFF\xFF\x00", '/Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceCMYK'));
    expect($r->pixel(20, 20))->toBe([255, 0, 0]);
});

it('resolves Indexed palettes from a string or an indirect lookup stream', function (string $space, ?Closure $extra) {
    $r = Renderer::render(fpiPage("\x10", '/Width 2 /Height 1 /BitsPerComponent 4 /ColorSpace '.$space, extra: $extra));
    expect($r->pixel(12, 20))->toBe([0, 0, 255])
        ->and($r->pixel(27, 20))->toBe([255, 0, 0])
        ->and($r->complete())->toBeTrue();
})->with([
    'hex string' => ['[/Indexed /DeviceRGB 1 <FF00000000FF>]', null],
    'stream' => ['[/Indexed /DeviceRGB 1 7 0 R]', function (PdfBuilder $b) { $b->stream("\xFF\x00\x00\x00\x00\xFF", flate: true); }],
]);

it('paints stencil zero bits in the current fill colour and honours an inverted Decode', function (string $decode, array $left, array $right) {
    $r = Renderer::render(fpiPage("\x40", '/Width 2 /Height 1 /ImageMask true '.$decode, '0 0 1 rg 0 0 40 40 re f 1 0 0 rg 20 0 0 20 10 10 cm /Im Do'));
    expect($r->pixel(12, 20))->toBe($left)->and($r->pixel(27, 20))->toBe($right)
        ->and($r->complete())->toBeTrue();
})->with([
    'default' => ['', [255, 0, 0], [0, 0, 255]],
    'inverted' => ['/Decode [1 0]', [0, 0, 255], [255, 0, 0]],
]);

it('renders an image through reflected and rotated CTMs', function (string $matrix, array $left, array $right) {
    $r = Renderer::render(fpiPage("\x00\xFF", '/Width 2 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceGray', $matrix.' cm /Im Do'));
    expect($r->pixel(...$left))->toBe([0, 0, 0])->and($r->pixel(...$right))->toBe([255, 255, 255]);
})->with([
    'reflect' => ['-20 0 0 20 30 10', [27, 20], [12, 20]],
    'rotate' => ['0 20 -20 0 30 10', [20, 27], [20, 12]],
]);

it('refuses a source image above its own pixel limit before decoding or allocating it', function () {
    $pdf = fpiPage('', '/Width 1000000000 /Height 1000000000 /BitsPerComponent 8 /ColorSpace /DeviceRGB');
    try {
        Renderer::render($pdf, limits: ['maxImagePixels' => 100]);
        test()->fail('The image should be refused.');
    } catch (RefusedException $e) {
        expect($e->reason)->toBe('too_many_pixels');
    }
});

it('reports truncated images and unsupported codecs without presenting the page as complete', function (string $bytes, string $dict) {
    $r = Renderer::render(fpiPage($bytes, '/Width 2 /Height 2 /BitsPerComponent 8 /ColorSpace /DeviceRGB '.$dict));
    expect($r->complete())->toBeFalse()->and(array_column($r->skipped, 'kind'))->toContain('image');
})->with([
    'truncated' => ['x', ''],
    'JPX' => ['x', '/Filter /JPXDecode'],
    'CCITT' => ['x', '/Filter /CCITTFaxDecode'],
    'JBIG2' => ['x', '/Filter /JBIG2Decode'],
    'chained opaque' => ['x', '/Filter [/JPXDecode /DCTDecode]'],
]);

it('applies a grayscale image soft mask', function () {
    $r = Renderer::render(fpiPage("\xFF\x00\x00", '/Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceRGB /SMask 7 0 R', extra: function (PdfBuilder $b) {
        $b->stream("\x80", '/Subtype /Image /Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceGray');
    }));
    expect($r->complete())->toBeTrue()->and($r->pixel(20, 20))->toBe([255, 127, 127]);
});

it('paints a visible placeholder for an unsupported codec and reports the omission', function () {
    $r = Renderer::render(fpiPage('unsupported', '/Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceRGB /Filter /JPXDecode'));
    expect($r->pixel(12, 12))->toBe([224, 224, 224])->and($r->pixel(27, 12))->toBe([192, 192, 192])
        ->and($r->complete())->toBeFalse()->and(array_column($r->skipped, 'kind'))->toContain('image');
});

it('renders the writer own PNG image output', function () {
    $png = ImageBuilder::png(2, 2, 2, 8, fn (int $x, int $y) => $x === 0 ? [255, 0, 0] : [0, 0, 255]);
    $pdf = fpwWrite(['blocks' => [['type' => 'image', 'src' => ImageBuilder::dataUrl($png, 'image/png'), 'width' => 20, 'height' => 20]]]);
    $r = Renderer::render($pdf);
    expect($r->complete())->toBeTrue();
});

it('applies graphics-state alpha to an ordinary image', function () {
    $pdf = fprPage('/Half gs 20 0 0 20 10 10 cm /Im Do', '[0 0 40 40]', '', '/XObject << /Im 6 0 R >> /ExtGState << /Half << /ca 0.5 >> >>', function (PdfBuilder $b) {
        $b->stream("\xFF\x00\x00", '/Subtype /Image /Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceRGB');
    });
    $r = Renderer::render($pdf);
    expect($r->complete())->toBeTrue()->and($r->pixel(20, 20))->toBe([255, 128, 128]);
});

it('reports Decode endpoints whose difference overflows despite each endpoint being finite', function () {
    $large = '1'.str_repeat('0', 308);
    $r = Renderer::render(fpiPage("\0", '/Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceGray /Decode ['.$large.' -'.$large.']'));
    expect($r->complete())->toBeFalse()->and(array_column($r->skipped, 'kind'))->toContain('image');
});
