<?php

declare(strict_types=1);

use FinePrint\Budget;
use FinePrint\Exceptions\RefusedException;
use FinePrint\Filters\Filters;
use FinePrint\Limits;
use FinePrint\Objects\PdfDict;
use FinePrint\Render\Renderer;
use FinePrint\Tests\Support\Encoders;
use FinePrint\Tests\Support\PdfBuilder;

it('undoes TIFF horizontal differencing at every PDF sample depth', function (int $bits, string $encoded, string $plain) {
    $params = new PdfDict(['Predictor' => 2, 'Colors' => 1, 'BitsPerComponent' => $bits, 'Columns' => 3]);
    expect(Filters::decodeChain(['FlateDecode'], (string) gzcompress($encoded), [$params]))->toBe($plain);
})->with([
    '1 bit' => [1, "\x60", "\x40"],
    '2 bits' => [2, "\x24", "\x2C"],
    '4 bits' => [4, "\x23\x90", "\x25\xE0"],
    '8 bits' => [8, "\x0A\x14\xFA", "\x0A\x1E\x18"],
    '16 bits' => [16, pack('n*', 1000, 2000, 65000), pack('n*', 1000, 3000, 2464)],
]);

it('keeps TIFF colour channels separate and resets each row', function () {
    $params = new PdfDict(['Predictor' => 2, 'Colors' => 3, 'BitsPerComponent' => 8, 'Columns' => 2]);
    $encoded = pack('C*', 10, 20, 30, 1, 2, 3, 5, 6, 7, 255, 254, 253);
    $plain = pack('C*', 10, 20, 30, 11, 22, 33, 5, 6, 7, 4, 4, 4);
    expect(Filters::decodeChain(['FlateDecode'], (string) gzcompress($encoded), [$params]))->toBe($plain);
});

it('bounds hostile predictor row declarations before allocating them', function (array $changes) {
    $params = new PdfDict($changes + ['Predictor' => 15, 'Colors' => 3, 'BitsPerComponent' => 8, 'Columns' => 2]);
    $budget = new Budget(new Limits(maxStreamBytes: 256));
    expect(fn () => Filters::decodeChain(['FlateDecode'], (string) gzcompress("\0x"), [$params], $budget))->toThrow(RefusedException::class);
})->with([
    'large row' => [['Columns' => 1000]],
    'overflow' => [['Columns' => PHP_INT_MAX]],
    'invalid type' => [['Columns' => new PdfDict()]],
    'invalid sample depth' => [['BitsPerComponent' => 3]],
]);

it('charges predictor work to its supplied time budget', function () {
    $params = new PdfDict(['Predictor' => 15, 'Columns' => 20000]);
    $budget = new Budget(new Limits(timeSeconds: 0.001));
    // A short budget must propagate through the whole filter/predictor chain.
    $data = str_repeat("\x01".str_repeat('x', 20000), 50);
    expect(fn () => Filters::decodeChain(['FlateDecode'], (string) gzcompress($data), [$params], $budget))->toThrow(RefusedException::class);
});

it('renders a PNG predictor after ASCII85 and Flate filters', function () {
    $raw = "\x01\xFF\x00\x00\x01\x00\xFF\x02\x01\x00\x00\xFF\x00\x00";
    $pdf = fprPage('20 0 0 20 10 10 cm /Im Do', '[0 0 40 40]', '', '/XObject << /Im 6 0 R >>', function (PdfBuilder $b) use ($raw) {
        $b->rawStream(Encoders::ascii85((string) gzcompress($raw)), '/Subtype /Image /Width 2 /Height 2 /BitsPerComponent 8 /ColorSpace /DeviceRGB /Filter [/ASCII85Decode /FlateDecode] /DecodeParms [null << /Predictor 15 /Colors 3 /Columns 2 >>]');
    });
    $r = Renderer::render($pdf);
    expect($r->pixel(12, 12))->toBe([255, 0, 0])->and($r->pixel(27, 12))->toBe([0, 0, 255])
        ->and($r->pixel(12, 27))->toBe([0, 0, 0])->and($r->pixel(27, 27))->toBe([255, 0, 255])
        ->and($r->complete())->toBeTrue();
});
