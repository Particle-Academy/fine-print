<?php

declare(strict_types=1);

use FinePrint\Exceptions\FontException;
use FinePrint\Tests\Support\FontBuilder;
use FinePrint\Writer\Fonts\TrueType;

/*
 * The TrueType reader and subsetter. A host hands the writer a font file it
 * may itself have been handed by a user, so the reader holds the same bar as
 * the PDF reader: a bad file is a FontException, never a warning or a crash.
 */

it('reads the metrics a PDF font descriptor needs', function () {
    $tt = TrueType::parse(FontBuilder::make());

    expect($tt->unitsPerEm)->toBe(1000)
        ->and($tt->ascent)->toBe(800)
        ->and($tt->descent)->toBe(-200)
        ->and($tt->capHeight)->toBe(700)
        ->and($tt->postScriptName)->toBe('FpTest-Regular')
        ->and($tt->bbox)->toBe([0, -200, 1000, 800])
        ->and($tt->isFixedPitch)->toBeFalse()
        ->and($tt->italicAngle)->toBe(0.0);
});

it('maps code points to glyphs through the cmap, astral ones through format 12', function () {
    $tt = TrueType::parse(FontBuilder::make());

    $a = $tt->glyphFor(0x41);
    expect($a)->toBeGreaterThan(0)
        ->and($tt->advance($a))->toBe(700)
        ->and($tt->advance($tt->glyphFor(0x69)))->toBe(240)
        ->and($tt->glyphFor(0x5A))->toBe(0)              // 'Z' is not in the font
        ->and($tt->glyphFor(0x1F600))->toBeGreaterThan(0)
        ->and($tt->advance($tt->glyphFor(0x1F600)))->toBe(1000);
});

it('reads italic angle and fixed pitch from post', function () {
    $tt = TrueType::parse(FontBuilder::make(fixedPitch: true, italicAngle: -12.0));

    expect($tt->isFixedPitch)->toBeTrue()
        ->and($tt->italicAngle)->toBe(-12.0);
});

it('reports whether the licence allows embedding', function () {
    expect(TrueType::parse(FontBuilder::make(fsType: 0))->embeddable())->toBeTrue()
        ->and(TrueType::parse(FontBuilder::make(fsType: 0x0008))->embeddable())->toBeTrue()
        ->and(TrueType::parse(FontBuilder::make(fsType: 0x0002))->embeddable())->toBeFalse()
        ->and(TrueType::parse(FontBuilder::make(fsType: 0x0200))->embeddable())->toBeFalse();
});

it('subsets to the glyphs used, keeping glyph ids and composite components', function () {
    $full = FontBuilder::make();
    $tt = TrueType::parse($full);
    $a = $tt->glyphFor(0x41);
    $auml = $tt->glyphFor(0xC4);
    $w = $tt->glyphFor(0x57);

    $subset = $tt->subset([$auml]);
    $sub = TrueType::parse($subset);

    expect(strlen($subset))->toBeLessThan(strlen($full))
        ->and($sub->numGlyphs)->toBe($tt->numGlyphs)          // ids are kept
        ->and($sub->glyphLength($auml))->toBeGreaterThan(0)
        ->and($sub->glyphLength($a))->toBeGreaterThan(0)      // a component of Ä
        ->and($sub->glyphLength($tt->numGlyphs - 1))->toBeGreaterThan(0) // the dieresis
        ->and($sub->glyphLength(0))->toBeGreaterThan(0)       // .notdef always
        ->and($sub->glyphLength($w))->toBe(0)                 // not used
        ->and($sub->advance($w))->toBe(900);                  // metrics are untouched
});

it('writes a subset whose checksums are valid', function () {
    $tt = TrueType::parse(FontBuilder::make());
    $subset = $tt->subset([$tt->glyphFor(0x41)]);

    // The whole-font checksum, with checkSumAdjustment applied, is the magic.
    $sum = 0;
    $padded = $subset.str_repeat("\0", (4 - strlen($subset) % 4) % 4);
    foreach (unpack('N*', $padded) as $word) {
        $sum = ($sum + $word) & 0xFFFFFFFF;
    }
    expect($sum)->toBe(0xB1B0AFBA);
});

it('subsets deterministically', function () {
    $tt = TrueType::parse(FontBuilder::make());

    expect($tt->subset([5, 3, 9]))->toBe($tt->subset([9, 5, 3, 3]));
});

it('refuses what is not a usable TrueType font', function (string $bytes, string $why) {
    expect(fn () => TrueType::parse($bytes))->toThrow(FontException::class, $why);
})->with([
    'garbage' => ['not a font at all, just text', 'not a TrueType'],
    'empty' => ['', 'not a TrueType'],
    'CFF outlines' => ['OTTO'.str_repeat("\0", 200), 'CFF'],
    'collection' => ['ttcf'.str_repeat("\0", 200), 'collection'],
]);

it('refuses a truncated font instead of reading past the end', function () {
    $font = FontBuilder::make();

    expect(fn () => TrueType::parse(substr($font, 0, (int) (strlen($font) / 2))))
        ->toThrow(FontException::class);
});

it('refuses a table directory that points outside the file', function () {
    $font = FontBuilder::make();
    // The first directory entry's offset field, rewritten to a huge value.
    $bad = substr_replace($font, pack('N', 0x7FFFFFF0), 12 + 8, 4);

    expect(fn () => TrueType::parse($bad))->toThrow(FontException::class);
});

it('refuses an absurd table count', function () {
    $font = FontBuilder::make();
    $bad = substr_replace($font, pack('n', 0xFFFF), 4, 2);

    expect(fn () => TrueType::parse($bad))->toThrow(FontException::class);
});

it('survives a composite glyph that refers to itself', function () {
    $tt = TrueType::parse(FontBuilder::make());
    $auml = $tt->glyphFor(0xC4);
    $font = FontBuilder::make();
    // Point Ä's first component at Ä itself: same length, so no offset moves.
    $glyfAt = $tt->tableOffset('glyf') + $tt->glyphOffset($auml);
    $bad = substr_replace($font, pack('n', $auml), $glyfAt + 10 + 2, 2);

    $sub = TrueType::parse($bad)->subset([$auml]);

    expect(TrueType::parse($sub)->glyphLength($auml))->toBeGreaterThan(0);
});
