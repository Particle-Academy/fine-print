<?php

declare(strict_types=1);

use FinePrint\Agent;
use FinePrint\Document;
use FinePrint\Exceptions\FontException;
use FinePrint\Objects\PdfStream;
use FinePrint\Tests\Support\FontBuilder;
use FinePrint\Writer\Fonts\TrueType;

/*
 * Host TrueType fonts, embedded as subsets with a ToUnicode CMap, so every
 * character the font has is drawn AND extracts back as the same text.
 */

function fpwFontDoc(string $text, array $run = []): array
{
    return ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => $text, 'font' => 'Test Sans'] + $run]]]];
}

/** The embedded font program(s), decoded. */
function fpwFontFiles(string $pdf): array
{
    $doc = Document::open($pdf);
    $out = [];
    foreach ($doc->objectNumbers() as $n) {
        $o = $doc->object($n);
        if ($o instanceof PdfStream && $o->dict->has('Length1')) {
            $out[] = $doc->streamData($o);
        }
    }

    return $out;
}

it('embeds a host font and reads back every character it has, astral ones included', function () {
    $pdf = fpwWrite(fpwFontDoc('Hello world Ä Ж 😀'), ['fonts' => ['Test Sans' => FontBuilder::make()], 'compress' => false]);

    expect($pdf)->toContain('/Subtype /Type0')
        ->and($pdf)->toContain('/Subtype /CIDFontType2')
        ->and($pdf)->toContain('/Encoding /Identity-H')
        ->and($pdf)->toContain('/CIDToGIDMap /Identity')
        ->and($pdf)->toContain('/ToUnicode')
        ->and($pdf)->toMatch('#/BaseFont /[A-Z]{6}\+FpTest-Regular #');

    $read = fpwRead($pdf);
    expect($read['pages'][0]['text'])->toBe('Hello world Ä Ж 😀')
        ->and($read['guard']['findings'])->toBe([]);
});

it('subsets the font to the glyphs used', function () {
    $full = FontBuilder::make();
    $files = fpwFontFiles(fpwWrite(fpwFontDoc('Hi'), ['fonts' => ['Test Sans' => $full]]));

    expect($files)->toHaveCount(1);
    $sub = TrueType::parse($files[0]);
    $tt = TrueType::parse($full);
    expect($sub->glyphLength($tt->glyphFor(0x48)))->toBeGreaterThan(0)     // H
        ->and($sub->glyphLength($tt->glyphFor(0x69)))->toBeGreaterThan(0)  // i
        ->and($sub->glyphLength($tt->glyphFor(0x57)))->toBe(0)             // W unused
        ->and(strlen($files[0]))->toBeLessThan(strlen($full));
});

it('positions glyphs with the font\'s own advance widths', function () {
    // H 720 + i 240 = 960 units at 1000/em, 20pt.
    $runs = fpwRuns(fpwWrite(fpwFontDoc('Hi', ['size' => 20]), ['fonts' => ['Test Sans' => FontBuilder::make()]]));

    expect(round($runs[0]['x1'] - $runs[0]['x0'], 3))->toBe(19.2);
});

it('wraps and justifies with the embedded metrics', function () {
    $text = implode(' ', array_fill(0, 40, 'hello world'));
    $pdf = fpwWrite(['blocks' => [['type' => 'paragraph', 'align' => 'justify', 'runs' => [['text' => $text, 'font' => 'Test Sans']]]]],
        ['fonts' => ['Test Sans' => FontBuilder::make()]]);
    $read = fpwRead($pdf);

    expect(fpwWords($read['pages'][0]['text']))->toBe(fpwWords($text));
    foreach (fpwRuns($pdf) as $r) {
        expect($r['x1'])->toBeLessThanOrEqual(540.01);
    }
});

it('takes faces per weight and slant, and family names case-insensitively', function () {
    $pdf = fpwWrite(['blocks' => [['type' => 'paragraph', 'runs' => [
        ['text' => 'Hello ', 'font' => 'test sans'],
        ['text' => 'world', 'font' => 'TEST SANS', 'bold' => true],
    ]]]], ['fonts' => ['Test Sans' => [
        'regular' => FontBuilder::make(postScriptName: 'FpTest-Regular'),
        'bold' => FontBuilder::make(postScriptName: 'FpTest-Bold'),
    ]], 'compress' => false]);

    expect($pdf)->toMatch('#\+FpTest-Regular #')
        ->and($pdf)->toMatch('#\+FpTest-Bold #')
        ->and(fpwRead($pdf)['pages'][0]['text'])->toBe('Hello world');
});

it('accepts a font file path from the host', function () {
    $path = sys_get_temp_dir().'/fine-print-test-font.ttf';
    file_put_contents($path, FontBuilder::make());
    try {
        $read = fpwRead(fpwWrite(fpwFontDoc('Hello'), ['fonts' => ['Test Sans' => $path]]));
    } finally {
        @unlink($path);
    }

    expect($read['pages'][0]['text'])->toBe('Hello');
});

it('refuses a font whose licence forbids embedding, and a font that is not one', function () {
    expect(fn () => fpwWrite(fpwFontDoc('Hi'), ['fonts' => ['Test Sans' => FontBuilder::make(fsType: 0x0002)]]))
        ->toThrow(FontException::class, 'does not allow embedding');
    expect(fn () => fpwWrite(fpwFontDoc('Hi'), ['fonts' => ['Test Sans' => 'not a font']]))
        ->toThrow(FontException::class, 'Test Sans');
});

it('writes the same bytes for the same document and font', function () {
    $opts = ['fonts' => ['Test Sans' => FontBuilder::make()]];

    expect(Agent::toBytes(fpwFontDoc('Hello world Ж'), $opts))->toBe(Agent::toBytes(fpwFontDoc('Hello world Ж'), $opts));
});

it('round-trips a real system font when one is installed (local only)', function () {
    $path = 'C:/Windows/Fonts/DejaVuSans.ttf';
    if (! is_file($path)) {
        $this->markTestSkipped('DejaVuSans is not installed here');
    }
    $text = 'Grüße, Ελληνικά, Кириллица, ½ → ∞, “quotes” — done.';
    $pdf = fpwWrite(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => $text, 'font' => 'DejaVu Sans']]]]], ['fonts' => ['DejaVu Sans' => $path]]);
    $read = fpwRead($pdf);

    expect($read['pages'][0]['text'])->toBe($text)
        ->and($read['guard']['findings'])->toBe([])
        ->and(strlen($pdf))->toBeLessThan((int) filesize($path) / 4);
})->group('local');
