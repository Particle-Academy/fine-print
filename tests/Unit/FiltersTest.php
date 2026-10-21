<?php

declare(strict_types=1);

use FinePrint\Filters\Filters;
use FinePrint\Filters\OpaqueStreamException;
use FinePrint\Objects\PdfDict;
use FinePrint\Tests\Support\Encoders;

/*
 * The five decoding filters, each against an encoder in tests/Support written
 * from the specification rather than from src/.
 */

$sample = "BT /F1 12 Tf 72 720 Td (Hello, filters) Tj ET\n".str_repeat('abcabcabc 0123 ', 40)."\x00\x01\xFE\xFF";

it('decodes FlateDecode', function () use ($sample) {
    expect(Filters::decodeChain(['FlateDecode'], (string) gzcompress($sample)))->toBe($sample);
});

it('decodes an EMPTY Flate stream to nothing, without retrying it as raw deflate', function () {
    // A blank page's content stream is gzcompress(''). It ends cleanly with
    // no output, which is not the same as failing; retrying it as raw deflate
    // raised a data error (suppressed, but still raised). Found by the P2
    // writer, whose empty pages are exactly this.
    $errors = [];
    set_error_handler(function (int $no, string $msg) use (&$errors): bool {
        $errors[] = $msg;

        return true;
    });
    try {
        $out = Filters::decodeChain(['FlateDecode'], (string) gzcompress(''));
    } finally {
        restore_error_handler();
    }

    expect($out)->toBe('')
        ->and($errors)->toBe([]);
});

it('decodes the abbreviated filter names used in inline images', function () use ($sample) {
    expect(Filters::decodeChain(['Fl'], (string) gzcompress($sample)))->toBe($sample)
        ->and(Filters::decodeChain(['AHx'], Encoders::asciiHex($sample)))->toBe($sample);
});

it('decodes ASCIIHexDecode, ignoring whitespace', function () use ($sample) {
    expect(Filters::decodeChain(['ASCIIHexDecode'], Encoders::asciiHex($sample)))->toBe($sample);
});

it('decodes ASCII85Decode, including the z shorthand and a short final group', function () use ($sample) {
    $data = $sample."\x00\x00\x00\x00xyz";

    expect(Filters::decodeChain(['ASCII85Decode'], Encoders::ascii85($data)))->toBe($data)
        ->and(Encoders::ascii85("\x00\x00\x00\x00"))->toBe('z~>');
});

it('decodes RunLengthDecode', function () use ($sample) {
    $data = $sample.str_repeat('Z', 300).'tail';

    expect(Filters::decodeChain(['RunLengthDecode'], Encoders::runLength($data)))->toBe($data);
});

it('decodes LZWDecode with early change', function () use ($sample) {
    expect(Filters::decodeChain(['LZWDecode'], Encoders::lzw($sample)))->toBe($sample);
});

it('applies a filter chain in order', function () use ($sample) {
    $encoded = Encoders::ascii85((string) gzcompress($sample));

    expect(Filters::decodeChain(['ASCII85Decode', 'FlateDecode'], $encoded))->toBe($sample);
});

it('reverses the PNG Up predictor', function () {
    $rows = ["\x01\x02\x03", "\x01\x02\x05", "\x10\x00\x05"];
    $prev = "\x00\x00\x00";
    $raw = '';
    foreach ($rows as $row) {
        $enc = '';
        for ($i = 0; $i < 3; $i++) {
            $enc .= chr((ord($row[$i]) - ord($prev[$i])) & 0xFF);
        }
        $raw .= "\x02".$enc;
        $prev = $row;
    }
    $parms = new PdfDict(['Predictor' => 12, 'Columns' => 3]);

    expect(Filters::decodeChain(['FlateDecode'], (string) gzcompress($raw), [$parms]))->toBe(implode('', $rows));
});

it('keeps image codecs opaque rather than decoding them', function (string $filter) {
    Filters::decodeChain([$filter], "\xFF\xD8\xFF\xE0 jpeg-ish bytes");
})->with(['DCTDecode', 'JPXDecode', 'CCITTFaxDecode', 'JBIG2Decode'])
    ->throws(OpaqueStreamException::class);

it('returns what it could decode from a truncated Flate stream', function () use ($sample) {
    $full = (string) gzcompress(str_repeat($sample, 20));
    $out = Filters::decodeChain(['FlateDecode'], substr($full, 0, (int) (strlen($full) * 0.6)));

    expect(strlen($out))->toBeGreaterThan(0)
        ->and(str_starts_with(str_repeat($sample, 20), $out))->toBeTrue();
});
