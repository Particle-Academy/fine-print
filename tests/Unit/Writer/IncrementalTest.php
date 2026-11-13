<?php

declare(strict_types=1);

use FinePrint\Document;
use FinePrint\Exceptions\SchemaException;
use FinePrint\Tests\Support\PdfBuilder;
use FinePrint\Writer\Incremental;

/*
 * Append-only incremental updates. The property everything rests on: the
 * original file is a BYTE-FOR-BYTE PREFIX of the updated one, so anyone can
 * prove the signed content did not change by truncating at the original
 * length and hashing, with no tool of ours involved.
 */

/** WriterSecurityTest's list, repeated so this file runs on its own. */
const FPI_FORBIDDEN = ['/JavaScript', '/JS', '/OpenAction', '/AA', '/Launch', '/AcroForm', '/SubmitForm', '/ImportData',
    '/EmbeddedFile', '/RichMedia', '/XFA', '/GoToR', '/GoToE', '/Names', '/Widget', '/Sound', '/Movie', '/Rendition'];

function fpiOriginal(int $pages = 1): string
{
    $blocks = [];
    for ($i = 1; $i <= $pages; $i++) {
        $blocks[] = fpwPara("Page {$i} body text");
        if ($i < $pages) {
            $blocks[] = ['type' => 'pageBreak'];
        }
    }

    return fpwWrite(['blocks' => $blocks]);
}

function fpiSignature(int $page = 1): array
{
    return ['page' => $page, 'items' => [
        ['type' => 'path', 'strokes' => [[[100.0, 100.0], [140.0, 120.0], [180.0, 100.0]], [[110.0, 90.0], [170.0, 90.0]]], 'width' => 1.2, 'color' => '#1F2937'],
        ['type' => 'text', 'x' => 100.0, 'y' => 70.0, 'size' => 9.0, 'text' => 'Signed by Zoë Example'],
    ]];
}

// ---------------------------------------------------------------- refusals first

it('refuses what it cannot append to safely', function (string $pdf, string $reason) {
    try {
        Incremental::overlay($pdf, [fpiSignature()]);
        $this->fail('expected a refusal');
    } catch (SchemaException $e) {
        expect($e->getMessage())->toContain($reason);
    }
})->with([
    'a cross-reference stream' => [PdfBuilder::textPdf([PdfBuilder::line('x')])->build(['xref' => 'stream']), 'xref_stream'],
    'a file that needed repair' => [PdfBuilder::textPdf([PdfBuilder::line('x')])->build(['xref' => 'none']), 'repaired'],
    'not a PDF' => ['hello', 'unreadable'],
]);

it('refuses marks that are out of range or malformed', function (array $mark) {
    expect(fn () => Incremental::overlay(fpiOriginal(), [$mark]))->toThrow(SchemaException::class);
})->with([
    'page 0' => [['page' => 0, 'items' => []]],
    'page past the end' => [['page' => 2, 'items' => []]],
    'unknown item' => [['page' => 1, 'items' => [['type' => 'image']]]],
    'a non-finite point' => [['page' => 1, 'items' => [['type' => 'path', 'strokes' => [[[INF, 1.0], [2.0, 2.0]]]]]]],
    'a point off any page' => [['page' => 1, 'items' => [['type' => 'path', 'strokes' => [[[1.0e9, 1.0], [2.0, 2.0]]]]]]],
    'too many points' => [['page' => 1, 'items' => [['type' => 'path', 'strokes' => [array_fill(0, 20001, [1.0, 1.0])]]]]],
    'text too long' => [['page' => 1, 'items' => [['type' => 'text', 'x' => 1.0, 'y' => 1.0, 'size' => 9.0, 'text' => str_repeat('x', 1001)]]]],
    'a bad colour' => [['page' => 1, 'items' => [['type' => 'path', 'strokes' => [[[1.0, 1.0], [2.0, 2.0]]], 'color' => 'red']]]],
]);

// ---------------------------------------------------------------- the prefix property

it('leaves the original file as a byte-for-byte prefix', function () {
    $original = fpiOriginal(2);
    $updated = Incremental::overlay($original, [fpiSignature(2)]);

    expect(strlen($updated))->toBeGreaterThan(strlen($original))
        ->and(substr($updated, 0, strlen($original)))->toBe($original)
        ->and(hash('sha256', substr($updated, 0, strlen($original))))->toBe(hash('sha256', $original))
        ->and($updated)->toEndWith("%%EOF\n");
});

it('reads back with the overlay drawn over the untouched original page', function () {
    $updated = Incremental::overlay(fpiOriginal(2), [fpiSignature(2)]);
    $doc = Document::open($updated);

    expect($doc->repaired())->toBeFalse()
        ->and($doc->warnings())->toBe([])
        ->and($doc->pageCount())->toBe(2)
        ->and($doc->text(1))->toBe('Page 1 body text')
        ->and($doc->text(2))->toContain('Page 2 body text')
        ->and($doc->text(2))->toContain('Signed by Zoë Example');

    $content = $doc->pageContents(2);
    expect($content)->toContain('100 100 m')->toContain('140 120 l')->toContain("\nS Q");
});

it('chains: a second update keeps the first as its prefix and both overlays survive', function () {
    $first = Incremental::overlay(fpiOriginal(), [fpiSignature()]);
    $second = Incremental::overlay($first, [['page' => 1, 'items' => [
        ['type' => 'text', 'x' => 300.0, 'y' => 70.0, 'size' => 9.0, 'text' => 'Second signer'],
    ]]]);

    expect(substr($second, 0, strlen($first)))->toBe($first);
    $doc = Document::open($second);
    expect($doc->repaired())->toBeFalse()
        ->and($doc->text(1))->toContain('Signed by Zoë Example')
        ->and($doc->text(1))->toContain('Second signer');
});

it('appends nothing active', function () {
    $original = fpiOriginal();
    $tail = substr(Incremental::overlay($original, [fpiSignature()]), strlen($original));

    foreach (FPI_FORBIDDEN as $name) {
        expect(preg_match('#'.preg_quote($name, '#').'(?![A-Za-z])#', $tail))->toBe(0);
    }
    expect(Document::open($original.$tail)->activeContent())->toBe([]);
});

it('writes text as hex only, so overlay text cannot break out of its string', function () {
    $original = fpiOriginal();
    $tail = substr(Incremental::overlay($original, [['page' => 1, 'items' => [
        ['type' => 'text', 'x' => 10.0, 'y' => 10.0, 'size' => 9.0, 'text' => 'a) Tj /JavaScript (x'],
    ]]]), strlen($original));

    expect($tail)->not->toContain('a) Tj')
        ->and($tail)->not->toContain('/JavaScript');
});

it('is deterministic', function () {
    $original = fpiOriginal();

    expect(Incremental::overlay($original, [fpiSignature()]))->toBe(Incremental::overlay($original, [fpiSignature()]));
});

it('accepts an update with no marks as a no-op that still appends nothing harmful', function () {
    $original = fpiOriginal();

    expect(Incremental::overlay($original, []))->toBe($original);
});
