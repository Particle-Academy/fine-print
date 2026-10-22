<?php

declare(strict_types=1);

use FinePrint\Render\Renderer;
use FinePrint\Tests\Support\JpegBuilder;
use FinePrint\Tests\Support\Encoders;

it('renders abbreviated inline RGB images and resumes the following operators', function () {
    $raw = "\xFF\x00\x00\x00\xFF\x00";
    $pdf = fprPage('q 20 0 0 20 10 10 cm BI /W 2 /H 1 /BPC 8 /CS /RGB ID '.$raw." EI Q\n0 0 1 rg 0 0 5 5 re f", '[0 0 40 40]');
    $r = Renderer::render($pdf);
    expect($r->pixel(12, 20))->toBe([255, 0, 0])->and($r->pixel(27, 20))->toBe([0, 255, 0])
        ->and($r->pixel(2, 37))->toBe([0, 0, 255])->and($r->complete())->toBeTrue();
});

it('uses the exact raw sample length when image bytes contain a delimited EI', function () {
    $pdf = fprPage("20 0 0 20 10 10 cm BI /W 4 /H 1 /BPC 8 /CS /G ID  EI  EI", '[0 0 40 40]');
    $r = Renderer::render($pdf);
    expect($r->pixel(12, 20))->toBe([32, 32, 32])->and($r->pixel(17, 20))->toBe([69, 69, 69])
        ->and($r->pixel(22, 20))->toBe([73, 73, 73])->and($r->pixel(27, 20))->toBe([32, 32, 32])
        ->and($r->complete())->toBeTrue();
});

it('locates the end of an inline Flate stream instead of treating its EI bytes as operators', function () {
    $compressed = (string) gzcompress(' EI ', 0);
    $pdf = fprPage('20 0 0 20 10 10 cm BI /W 4 /H 1 /BPC 8 /CS /G /F /Fl ID '.$compressed.' EI', '[0 0 40 40]');
    $r = Renderer::render($pdf);
    expect($r->pixel(17, 20))->toBe([69, 69, 69])->and($r->pixel(22, 20))->toBe([73, 73, 73])
        ->and($r->complete())->toBeTrue();
});

it('consumes the ID line ending without stripping whitespace-valued samples', function (string $separator) {
    $pdf = fprPage('20 0 0 20 10 10 cm BI /W 1 /H 1 /BPC 8 /CS /G ID'.$separator."\x0A EI", '[0 0 40 40]');
    $r = Renderer::render($pdf);
    expect($r->pixel(20, 20))->toBe([10, 10, 10])->and($r->complete())->toBeTrue();
})->with(['space' => [' '], 'line feed' => ["\n"], 'CRLF' => ["\r\n"]]);

it('renders inline stencil masks in the current fill colour', function () {
    $pdf = fprPage('1 0 0 rg 20 0 0 20 10 10 cm BI /W 2 /H 1 /IM true ID '."\x40 EI", '[0 0 40 40]');
    $r = Renderer::render($pdf);
    expect($r->pixel(12, 20))->toBe([255, 0, 0])->and($r->pixel(27, 20))->toBe([255, 255, 255])
        ->and($r->complete())->toBeTrue();
});

it('reads inline JPEG boundaries even when metadata contains EI and an EOI byte pair', function () {
    $jpeg = JpegBuilder::make(8, 8, [70]);
    $comment = " EI \xFF\xD9 ";
    $jpeg = substr($jpeg, 0, 2)."\xFF\xFE".pack('n', strlen($comment) + 2).$comment.substr($jpeg, 2);
    $r = Renderer::render(fprPage('20 0 0 20 10 10 cm BI /W 8 /H 8 /BPC 8 /CS /G /F /DCT ID '.$jpeg.' EI', '[0 0 40 40]'));
    expect($r->pixel(20, 20))->toBe([70, 70, 70])->and($r->complete())->toBeTrue();
});

it('reads inline LZW through its EOD as code widths grow', function (int $early) {
    $raw = '';
    for ($i = 0; $i < 1024; $i++) {
        $raw .= chr(($i * 37) % 256);
    }
    $encoded = Encoders::lzw($raw, $early);
    $r = Renderer::render(fprPage('20 0 0 20 10 10 cm BI /W 32 /H 32 /BPC 8 /CS /G /F /LZW /DP << /EarlyChange '.$early.' >> ID '.$encoded.' EI', '[0 0 40 40]'));
    expect($r->pixel(20, 20))->toBe([80, 80, 80])->and($r->complete())->toBeTrue();
})->with([0, 1]);
