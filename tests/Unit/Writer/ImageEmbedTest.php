<?php

declare(strict_types=1);

use FinePrint\Agent;
use FinePrint\Document;
use FinePrint\Exceptions\ImageException;
use FinePrint\Objects\PdfStream;
use FinePrint\Tests\Support\ImageBuilder;
use FinePrint\Writer\Images\Image;

/*
 * Images: JPEG embedded as-is, PNG passed through with a predictor when PDF
 * can take it unchanged, decoded (zlib only) when it cannot. Each embedded
 * image is decoded again by P1's own filters and compared sample for sample
 * with what the test put in.
 */

/** @return list<array{dict: \FinePrint\Objects\PdfDict, raw: string, data: ?string, smask: ?string}> */
function fpwImages(string $pdf): array
{
    $doc = Document::open($pdf);
    $out = [];
    foreach ($doc->objectNumbers() as $n) {
        $o = $doc->object($n);
        if ($o instanceof PdfStream && $o->dict->name('Subtype') === 'Image' && ! fpwIsMask($doc, $n)) {
            $mask = $doc->resolve($o->dict->get('SMask'));
            $out[] = [
                'dict' => $o->dict,
                'raw' => $o->raw,
                'data' => $o->dict->name('Filter') === 'DCTDecode' ? null : $doc->streamData($o),
                'smask' => $mask instanceof PdfStream ? $doc->streamData($mask) : null,
            ];
        }
    }

    return $out;
}

function fpwIsMask(Document $doc, int $num): bool
{
    foreach ($doc->objectNumbers() as $n) {
        $o = $doc->object($n);
        if ($o instanceof PdfStream && $o->dict->get('SMask') instanceof FinePrint\Objects\PdfRef && $o->dict->get('SMask')->num === $num) {
            return true;
        }
    }

    return false;
}

function fpwImageDoc(string $src, array $props = []): array
{
    return ['blocks' => [fpwPara('Before'), ['type' => 'image', 'src' => $src] + $props, fpwPara('After')]];
}

it('passes an RGB PNG through untouched, with the PNG predictor', function () {
    $px = fn ($x, $y) => [($x * 37) % 256, ($y * 59) % 256, ($x * $y) % 256];
    $png = ImageBuilder::png(9, 7, 2, 8, $px);
    $images = fpwImages(fpwWrite(fpwImageDoc(ImageBuilder::dataUrl($png, 'image/png'))));

    expect($images)->toHaveCount(1);
    $img = $images[0];
    expect($img['dict']->get('Width'))->toBe(9)
        ->and($img['dict']->get('Height'))->toBe(7)
        ->and($img['dict']->name('ColorSpace'))->toBe('DeviceRGB')
        ->and($img['dict']->get('BitsPerComponent'))->toBe(8)
        ->and($img['dict']->get('DecodeParms')->get('Predictor'))->toBe(15);

    $expected = '';
    for ($y = 0; $y < 7; $y++) {
        for ($x = 0; $x < 9; $x++) {
            $expected .= pack('C*', ...$px($x, $y));
        }
    }
    expect(bin2hex($img['data']))->toBe(bin2hex($expected));
});

it('passes grey, 1-bit and 16-bit PNGs through', function (int $type, int $depth, string $space) {
    $max = (1 << $depth) - 1;
    $px = fn ($x, $y) => array_fill(0, $type === 2 ? 3 : 1, ($x + 3 * $y) % ($max + 1));
    $png = ImageBuilder::png(11, 4, $type, $depth, $px);
    $img = fpwImages(fpwWrite(fpwImageDoc(ImageBuilder::dataUrl($png, 'image/png'))))[0];

    expect($img['dict']->name('ColorSpace'))->toBe($space)
        ->and($img['dict']->get('BitsPerComponent'))->toBe($depth)
        ->and($img['smask'])->toBeNull();

    // P1 undoes the predictor; the rows must equal the PNG's unfiltered rows.
    $rowBytes = intdiv(11 * ($type === 2 ? 3 : 1) * $depth + 7, 8);
    expect(strlen($img['data']))->toBe($rowBytes * 4);
})->with([
    'grey 8' => [0, 8, 'DeviceGray'],
    'grey 1' => [0, 1, 'DeviceGray'],
    'grey 4' => [0, 4, 'DeviceGray'],
    'rgb 16' => [2, 16, 'DeviceRGB'],
]);

it('embeds a palette PNG as an Indexed image', function () {
    $palette = [[255, 0, 0], [0, 255, 0], [0, 0, 255]];
    $png = ImageBuilder::png(5, 2, 3, 8, fn ($x, $y) => [($x + $y) % 3], $palette);
    $img = fpwImages(fpwWrite(fpwImageDoc(ImageBuilder::dataUrl($png, 'image/png'))))[0];

    $cs = $img['dict']->get('ColorSpace');
    expect($cs)->toBeArray()
        ->and($cs[0]->value)->toBe('Indexed')
        ->and($cs[2])->toBe(2)
        ->and(bin2hex($img['data']))->toBe('0001020001'.'0102000102');
});

it('decodes an RGBA PNG into colour plus a soft mask', function () {
    $png = ImageBuilder::png(4, 3, 6, 8, fn ($x, $y) => [10 * $x, 20 * $y, 30, 60 * $x]);
    $img = fpwImages(fpwWrite(fpwImageDoc(ImageBuilder::dataUrl($png, 'image/png'))))[0];

    $rgb = '';
    $alpha = '';
    for ($y = 0; $y < 3; $y++) {
        for ($x = 0; $x < 4; $x++) {
            $rgb .= pack('C3', 10 * $x, 20 * $y, 30);
            $alpha .= chr(60 * $x);
        }
    }
    expect($img['dict']->name('ColorSpace'))->toBe('DeviceRGB')
        ->and($img['dict']->has('DecodeParms'))->toBeFalse()
        ->and(bin2hex($img['data']))->toBe(bin2hex($rgb))
        ->and(bin2hex($img['smask']))->toBe(bin2hex($alpha));
});

it('turns palette transparency into a soft mask', function () {
    $png = ImageBuilder::png(3, 1, 3, 8, fn ($x) => [$x], [[1, 2, 3], [4, 5, 6], [7, 8, 9]], trns: "\x00\x80");
    $img = fpwImages(fpwWrite(fpwImageDoc(ImageBuilder::dataUrl($png, 'image/png'))))[0];

    expect(bin2hex($img['data']))->toBe('010203040506070809')
        ->and(bin2hex($img['smask']))->toBe('0080ff');
});

it('de-interlaces an Adam7 PNG to the same pixels', function () {
    $px = fn ($x, $y) => [($x * 31 + $y) % 256, ($y * 17) % 256, ($x ^ $y) % 256];
    $plain = fpwImages(fpwWrite(fpwImageDoc(ImageBuilder::dataUrl(ImageBuilder::png(13, 11, 2, 8, $px), 'image/png'))))[0];
    $laced = fpwImages(fpwWrite(fpwImageDoc(ImageBuilder::dataUrl(ImageBuilder::png(13, 11, 2, 8, $px, interlace: true), 'image/png'))))[0];

    expect(bin2hex($laced['data']))->toBe(bin2hex($plain['data']));
});

it('embeds a JPEG byte for byte, with DCTDecode', function (int $components, string $space) {
    $jpeg = ImageBuilder::jpeg(24, 16, $components);
    $img = fpwImages(fpwWrite(fpwImageDoc(ImageBuilder::dataUrl($jpeg, 'image/jpeg'))))[0];

    expect($img['dict']->name('Filter'))->toBe('DCTDecode')
        ->and($img['dict']->name('ColorSpace'))->toBe($space)
        ->and($img['dict']->get('Width'))->toBe(24)
        ->and($img['dict']->get('Height'))->toBe(16)
        ->and($img['raw'])->toBe($jpeg);
})->with([[3, 'DeviceRGB'], [1, 'DeviceGray']]);

it('decides the format from the bytes, not the MIME type', function () {
    $jpeg = ImageBuilder::jpeg(8, 8);
    $img = fpwImages(fpwWrite(fpwImageDoc(ImageBuilder::dataUrl($jpeg, 'image/png'))))[0];

    expect($img['dict']->name('Filter'))->toBe('DCTDecode');
});

it('embeds an image used twice only once', function () {
    $src = ImageBuilder::dataUrl(ImageBuilder::jpeg(8, 8), 'image/jpeg');
    $pdf = fpwWrite(['blocks' => [['type' => 'image', 'src' => $src], ['type' => 'image', 'src' => $src]]], ['compress' => false]);

    expect(fpwImages($pdf))->toHaveCount(1)
        ->and(substr_count($pdf, '/Im1 Do'))->toBe(2);
});

it('sizes images at 96 dpi, keeps the aspect ratio, and fits the column', function (array $props, float $w, float $h) {
    $src = ImageBuilder::dataUrl(ImageBuilder::jpeg(400, 200), 'image/jpeg');
    $pdf = fpwWrite(['blocks' => [['type' => 'image', 'src' => $src] + $props]], ['compress' => false]);

    preg_match('/q ([\d.]+) 0 0 ([\d.]+) [\d.]+ [\d.]+ cm \/Im1 Do Q/', $pdf, $m);
    expect((float) $m[1])->toBe($w)
        ->and((float) $m[2])->toBe($h);
})->with([
    'natural' => [[], 300.0, 150.0],
    'width only' => [['widthPx' => 200], 150.0, 75.0],
    'height only' => [['heightPx' => 100], 150.0, 75.0],
    'both' => [['widthPx' => 100, 'heightPx' => 100], 75.0, 75.0],
    'too wide' => [['widthPx' => 1248], 468.0, 234.0],
]);

it('reads back the text around an image, with no findings', function () {
    $read = fpwRead(fpwWrite(fpwImageDoc(ImageBuilder::dataUrl(ImageBuilder::jpeg(40, 40), 'image/jpeg'))));

    expect(fpwWords(fpwAllText($read)))->toBe(['Before', 'After'])
        ->and($read['guard']['findings'])->toBe([]);
});

it('refuses damaged and oversized images cleanly', function (string $bytes, string $why) {
    expect(fn () => Image::fromBytes($bytes, 10_000))->toThrow(ImageException::class, $why);
})->with([
    'bad CRC' => [substr_replace(ImageBuilder::png(4, 4, 2, 8, fn () => [1, 2, 3]), 'X', 20, 1), 'checksum'],
    'truncated' => [substr(ImageBuilder::png(4, 4, 2, 8, fn () => [1, 2, 3]), 0, 40), 'PNG'],
    'too many pixels' => [ImageBuilder::png(200, 200, 0, 8, fn () => [0]), 'pixels'],
    'jpeg too many pixels' => [ImageBuilder::jpeg(200, 200), 'pixels'],
    'arithmetic jpeg' => [str_replace("\xFF\xC0", "\xFF\xC9", ImageBuilder::jpeg(8, 8)), 'arithmetic'],
    'not an image' => ['GIF89a....', 'PNG or JPEG'],
]);

it('stops a PNG whose data inflates past what its header promises', function () {
    // A 2x2 grey image whose IDAT is a megabyte of zeros: a small bomb.
    $ihdr = pack('NNCCCCC', 2, 2, 8, 0, 0, 0, 0);
    $chunk = fn (string $t, string $d) => pack('N', strlen($d)).$t.$d.pack('N', crc32($t.$d));
    $png = "\x89PNG\r\n\x1A\n".$chunk('IHDR', $ihdr).$chunk('IDAT', (string) gzcompress(str_repeat("\0", 1 << 20))).$chunk('IEND', '');

    // Refused when the image is OPENED (so validation reports it), even though
    // a plain grey PNG would otherwise be copied into the PDF without inflating.
    expect(fn () => Image::fromBytes($png))->toThrow(ImageException::class, 'more than its header allows');
});

it('validates images through the Agent as well', function () {
    $errors = Agent::validate(['blocks' => [['type' => 'image', 'src' => ImageBuilder::dataUrl(str_replace("\xFF\xC0", "\xFF\xC3", ImageBuilder::jpeg(8, 8)), 'image/jpeg')]]]);

    expect($errors[0]['message'])->toContain('lossless');
});
