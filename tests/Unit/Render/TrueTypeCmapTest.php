<?php

declare(strict_types=1);

use FinePrint\Exceptions\FontException;
use FinePrint\Tests\Support\FontBuilder;
use FinePrint\Writer\Fonts\TrueType;

/*
 * The P2 TrueType reader, extended for the renderer: fonts embedded in a PDF
 * often carry only a symbol (3,0) or Macintosh (1,0) cmap, or none at all,
 * and each subtable is looked up on its own terms (ISO 32000-1 9.6.6.4).
 * The writer still refuses a host font with no Unicode cmap.
 */

function fpcFont(array $cmaps): string
{
    $sq = FontBuilder::simpleGlyph([[[0, 0], [0, 100], [100, 100], [100, 0]]]);
    $glyphs = [['data' => '', 'advance' => 500, 'lsb' => 0]];
    for ($i = 1; $i <= 6; $i++) {
        $glyphs[] = ['data' => $sq, 'advance' => 100 * $i, 'lsb' => 10 * $i];
    }

    return FontBuilder::build($glyphs, $cmaps);
}

it('still refuses a host font with no Unicode cmap by default', function () {
    expect(fn () => TrueType::parse(fpcFont([[3, 0, 4, [0xF041 => 1]]])))->toThrow(FontException::class, 'Unicode');
});

it('opens an embedded font with only a symbol cmap, or none, when asked', function () {
    expect(TrueType::parse(fpcFont([[3, 0, 4, [0xF041 => 1]]]), requireUnicodeCmap: false)->numGlyphs)->toBe(7)
        ->and(TrueType::parse(fpcFont([]), requireUnicodeCmap: false)->numGlyphs)->toBe(7);
});

it('looks a code up in one named subtable, and says when the subtable is absent', function () {
    $tt = TrueType::parse(fpcFont([[3, 0, 4, [0xF041 => 1, 0x42 => 2]], [3, 1, 4, [0x41 => 3]]]), requireUnicodeCmap: false);

    expect($tt->cmapGlyph(3, 0, 0xF041))->toBe(1)
        ->and($tt->cmapGlyph(3, 0, 0x42))->toBe(2)
        ->and($tt->cmapGlyph(3, 0, 0x43))->toBe(0)
        ->and($tt->cmapGlyph(3, 1, 0x41))->toBe(3)
        ->and($tt->cmapGlyph(1, 0, 0x41))->toBeNull()
        ->and($tt->hasCmap(3, 0))->toBeTrue()
        ->and($tt->hasCmap(1, 0))->toBeFalse();
});

it('reads byte (format 0), trimmed (format 6) and segmented (format 12) subtables', function () {
    $f0 = TrueType::parse(fpcFont([[1, 0, 0, [0x41 => 4, 0xFF => 5]]]), requireUnicodeCmap: false);
    $f6 = TrueType::parse(fpcFont([[1, 0, 6, [0x41 => 6, 0x43 => 2]]]), requireUnicodeCmap: false);
    $f12 = TrueType::parse(fpcFont([[3, 10, 12, [0x1F600 => 3, 0x41 => 1]]]));

    expect($f0->cmapGlyph(1, 0, 0x41))->toBe(4)
        ->and($f0->cmapGlyph(1, 0, 0xFF))->toBe(5)
        ->and($f0->cmapGlyph(1, 0, 0x100))->toBe(0)
        ->and($f6->cmapGlyph(1, 0, 0x41))->toBe(6)
        ->and($f6->cmapGlyph(1, 0, 0x42))->toBe(0)
        ->and($f6->cmapGlyph(1, 0, 0x43))->toBe(2)
        ->and($f6->cmapGlyph(1, 0, 0x44))->toBe(0)
        ->and($f12->cmapGlyph(3, 10, 0x1F600))->toBe(3)
        ->and($f12->glyphFor(0x1F600))->toBe(3);
});

it('never maps to a glyph id the font does not have', function () {
    $tt = TrueType::parse(fpcFont([[3, 0, 4, [0x41 => 500]], [1, 0, 0, [0x41 => 200]]]), requireUnicodeCmap: false);

    expect($tt->cmapGlyph(3, 0, 0x41))->toBe(0)
        ->and($tt->cmapGlyph(1, 0, 0x41))->toBe(0);
});

it('survives a subtable that points outside the table', function () {
    $font = fpcFont([[3, 0, 4, [0x41 => 1]]]);
    $tt = TrueType::parse($font, requireUnicodeCmap: false);
    $at = $tt->tableOffset('cmap');
    // Point the one record's offset far past the end (same length, so nothing else moves).
    $bad = substr_replace($font, pack('N', 0x00FFFFF0), $at + 8, 4);
    $tt2 = TrueType::parse($bad, requireUnicodeCmap: false);

    // Unusable is absent, so glyph selection falls through to the font's other cmaps.
    expect($tt2->cmapGlyph(3, 0, 0x41))->toBeNull()
        ->and($tt2->hasCmap(3, 0))->toBeFalse();
});

it('gives each glyph its left side bearing and its raw outline bytes', function () {
    $tt = TrueType::parse(fpcFont([]), requireUnicodeCmap: false);

    expect($tt->leftSideBearing(3))->toBe(30)
        ->and($tt->leftSideBearing(999))->toBe(0)
        ->and(strlen($tt->glyphData(1)))->toBe($tt->glyphLength(1))
        ->and($tt->glyphData(0))->toBe('')
        ->and($tt->glyphData(999))->toBe('');
});
