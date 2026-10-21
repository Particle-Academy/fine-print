<?php

declare(strict_types=1);

use FinePrint\Render\Renderer;
use FinePrint\Render\Rendering;
use FinePrint\Tests\Support\FontBuilder;
use FinePrint\Tests\Support\PdfBuilder;

/*
 * Text-rendering helpers shared by TextRenderTest, TextHostileTest and the
 * goldens; loaded from tests/Pest.php so each test file runs on its own.
 *
 * The test font's glyphs are squares and an L so pixels can be predicted
 * exactly: 'I' fills its whole em (1000 x 1000 units), so at 10 pt it is a
 * 10 x 10 point square sitting on the origin. Pages are 100 x 100 at scale 1.
 */

const FPT_SQUARE = 1;

const FPT_DOT = 2;

const FPT_SPACE = 3;

const FPT_L = 4;

/**
 * The test font. gid 0 (.notdef) is a half-em square; 1 a full-em square; 2
 * a fifth-em dot; 3 empty; 4 an L (a full-height stem 200 wide plus a
 * full-width foot 200 tall).
 *
 * @param  list<array{0: int, 1: int, 2: int, 3: array<int, int>}>  $cmaps
 */
function fptFont(array $cmaps = [[3, 1, 4, [0x49 => 1, 0x2E => 2, 0x20 => 3, 0x4C => 4, 0xC4 => 1]]]): string
{
    $sq = fn (int $s) => FontBuilder::simpleGlyph([[[0, 0], [0, $s], [$s, $s], [$s, 0]]]);

    return FontBuilder::build([
        ['data' => $sq(500), 'advance' => 1000],
        ['data' => $sq(1000), 'advance' => 1000],
        ['data' => $sq(200), 'advance' => 500],
        ['data' => '', 'advance' => 500, 'lsb' => 0],
        ['data' => FontBuilder::simpleGlyph([[[0, 0], [0, 1000], [200, 1000], [200, 200], [1000, 200], [1000, 0]]]), 'advance' => 1000],
    ], $cmaps, postScriptName: 'FpText');
}

/**
 * A one-page PDF; each font is added by a callable that returns its object number.
 *
 * @param  array<string, callable(PdfBuilder): int>  $fonts
 */
function fptDoc(string $content, array $fonts, string $mediaBox = '[0 0 100 100]'): string
{
    $b = new PdfBuilder();
    $cat = $b->reserve();
    $tree = $b->reserve();
    $res = '';
    foreach ($fonts as $name => $make) {
        $res .= '/'.$name.' '.$make($b).' 0 R ';
    }
    $c = $b->stream($content);
    $page = $b->object('<< /Type /Page /Parent '.$tree.' 0 R /Contents '.$c.' 0 R /Resources << /Font << '.$res.'>> >> >>');
    $b->object('<< /Type /Pages /Kids ['.$page.' 0 R] /Count 1 /MediaBox '.$mediaBox.' >>', $tree);
    $b->object('<< /Type /Catalog /Pages '.$tree.' 0 R >>', $cat);

    return $b->build();
}

/** A simple /TrueType font with the program embedded as FontFile2. */
function fptSimple(?string $program = null, string $extra = '/Encoding /WinAnsiEncoding', int $flags = 32, string $fontFileKey = 'FontFile2', string $fontFileDict = ''): callable
{
    return function (PdfBuilder $b) use ($program, $extra, $flags, $fontFileKey, $fontFileDict): int {
        $program ??= fptFont();
        $ff = $b->stream($program, '/Length1 '.strlen($program).' '.$fontFileDict);
        $d = $b->object('<< /Type /FontDescriptor /FontName /FpText /Flags '.$flags.' /FontBBox [0 0 1000 1000] /ItalicAngle 0 /Ascent 1000 /Descent 0 /CapHeight 1000 /StemV 80 /'.$fontFileKey.' '.$ff.' 0 R >>');

        return $b->object('<< /Type /Font /Subtype /TrueType /BaseFont /FpText /FontDescriptor '.$d.' 0 R '.$extra.' >>');
    };
}

/** A Type0 font over a CIDFontType2 with the program embedded. */
function fptType0(string $encoding = '/Identity-H', string $cidExtra = '/CIDToGIDMap /Identity', ?callable $extraObjects = null): callable
{
    return function (PdfBuilder $b) use ($encoding, $cidExtra, $extraObjects): int {
        $program = fptFont([]);
        $ff = $b->stream($program, '/Length1 '.strlen($program));
        $d = $b->object('<< /Type /FontDescriptor /FontName /FpText /Flags 4 /FontBBox [0 0 1000 1000] /ItalicAngle 0 /Ascent 1000 /Descent 0 /CapHeight 1000 /StemV 80 /FontFile2 '.$ff.' 0 R >>');
        if ($extraObjects !== null) {
            [$encoding, $cidExtra] = $extraObjects($b, $encoding, $cidExtra);
        }
        $cid = $b->object('<< /Type /Font /Subtype /CIDFontType2 /BaseFont /FpText /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor '.$d.' 0 R /DW 1000 '.$cidExtra.' >>');

        return $b->object('<< /Type /Font /Subtype /Type0 /BaseFont /FpText /Encoding '.$encoding.' /DescendantFonts ['.$cid.' 0 R] >>');
    };
}

/** A font dictionary with no program at all. */
function fptPlain(string $dict): callable
{
    return fn (PdfBuilder $b): int => $b->object($dict);
}

function fptRender(string $content, ?callable $font = null, float $scale = 1.0): Rendering
{
    return Renderer::render(fptDoc($content, ['T1' => $font ?? fptSimple()]), 1, $scale);
}

/** The pixel covering user-space point (x, y) on a 100 pt page at scale 1. */
function fptAt(Rendering $r, float $x, float $y): array
{
    return $r->pixel((int) floor($x), (int) floor(100 - $y));
}

function fptSkipped(Rendering $r): array
{
    $out = [];
    foreach ($r->skipped as $s) {
        $out[$s['kind']] = $s['count'];
    }

    return $out;
}

const FPT_W = [255, 255, 255];

const FPT_K = [0, 0, 0];
