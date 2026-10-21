<?php

declare(strict_types=1);

use FinePrint\Render\PngEncoder;
use FinePrint\Tests\Support\PngReader;

/*
 * The PNG encoder, read back by tests/Support/PngReader — a decoder written
 * separately from the PNG specification, which checks every chunk CRC itself.
 */

/** A deterministic, noisy RGB image that exercises every filter's arithmetic. */
function fpNoise(int $w, int $h, int $channels): array
{
    $rows = [];
    for ($y = 0; $y < $h; $y++) {
        $row = '';
        for ($x = 0; $x < $w * $channels; $x++) {
            $row .= chr(($x * 37 + $y * 101 + ($x * $y) % 13 * 19) & 0xFF);
        }
        $rows[] = $row;
    }

    return $rows;
}

it('writes a valid signature, IHDR and IEND', function () {
    $png = PngEncoder::encode(3, 2, [str_repeat("\xFF\x00\x00", 3), str_repeat("\x00\x00\xFF", 3)]);

    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        // IHDR: length 13, then width 3, height 2, depth 8, colour type 2 (RGB), 0, 0, 0
        ->and(substr($png, 8, 4 + 4 + 13))->toBe("\x00\x00\x00\x0DIHDR\x00\x00\x00\x03\x00\x00\x00\x02\x08\x02\x00\x00\x00")
        // IEND is fixed bytes; its CRC is the well-known AE426082.
        ->and(substr($png, -12))->toBe("\x00\x00\x00\x00IEND\xAE\x42\x60\x82");
});

it('round-trips RGB through every filter', function (string $filter) {
    $rows = fpNoise(17, 9, 3);
    $img = PngReader::read(PngEncoder::encode(17, 9, $rows, 3, $filter));

    expect($img['width'])->toBe(17)
        ->and($img['height'])->toBe(9)
        ->and($img['channels'])->toBe(3)
        ->and($img['rgb'])->toBe($rows);
})->with(['none', 'sub', 'up', 'average', 'paeth', 'adaptive']);

it('uses the filter it was asked for on every row', function (string $filter, int $type) {
    $img = PngReader::read(PngEncoder::encode(5, 4, fpNoise(5, 4, 3), 3, $filter));

    expect(array_unique($img['filters']))->toBe([$type]);
})->with([['none', 0], ['sub', 1], ['up', 2], ['average', 3], ['paeth', 4]]);

it('round-trips RGBA', function (string $filter) {
    $rows = fpNoise(6, 5, 4);
    $png = PngEncoder::encode(6, 5, $rows, 4, $filter);

    // Colour type 6 in IHDR.
    expect(ord($png[8 + 8 + 9]))->toBe(6);

    $img = PngReader::read($png);
    expect($img['channels'])->toBe(4);
    // The reader composites onto white; recompute the expectation the same way.
    foreach ($rows as $y => $row) {
        $expect = '';
        for ($x = 0; $x < 6; $x++) {
            $a = ord($row[$x * 4 + 3]);
            for ($c = 0; $c < 3; $c++) {
                $expect .= chr((int) round((ord($row[$x * 4 + $c]) * $a + 255 * (255 - $a)) / 255));
            }
        }
        expect($img['rgb'][$y])->toBe($expect);
    }
})->with(['none', 'paeth', 'adaptive']);

it('is deterministic', function () {
    $rows = fpNoise(20, 20, 3);

    expect(PngEncoder::encode(20, 20, $rows))->toBe(PngEncoder::encode(20, 20, $rows));
});

it('rejects rows that do not match the declared size', function () {
    expect(fn () => PngEncoder::encode(4, 2, [str_repeat("\0", 12)]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PngEncoder::encode(4, 1, [str_repeat("\0", 11)]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PngEncoder::encode(0, 1, []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PngEncoder::encode(1, 1, ["\0\0"], 2))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PngEncoder::encode(1, 1, ["\0\0\0"], 3, 'bogus'))->toThrow(InvalidArgumentException::class);
});

it('compresses a blank page to almost nothing', function () {
    $rows = array_fill(0, 792, str_repeat("\xFF", 612 * 3));
    $png = PngEncoder::encode(612, 792, $rows);

    expect(strlen($png))->toBeLessThan(4096)
        ->and(PngReader::read($png)['rgb'][400])->toBe($rows[400]);
});
