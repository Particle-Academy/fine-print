<?php

declare(strict_types=1);

use FinePrint\Agent;
use FinePrint\Document;
use FinePrint\Objects\PdfStream;
use FinePrint\Tests\Support\ImageBuilder;
use LastWord\Agent as LastWord;

/*
 * The supported route for a .docx letterhead on a fine-print PDF, as AGENTS.md
 * documents it ("Letterhead on a PDF"):
 *
 *   1. LastWord\Agent::read($docx)
 *   2. merge read.page into the document, the document's own values winning
 *      (last-word's templateParts rule)
 *   3. FinePrint\Agent::validateAndRepair, then toBytes
 *
 * The letterhead is written by last-word itself (a first-party dev dependency),
 * so this pins the two released packages agreeing, not a hand-written fixture.
 * The band images must reach the PDF byte for byte: a JPEG as the image stream
 * itself, a PNG as its IDAT data under the PNG predictor.
 */

/** The merge AGENTS.md documents, verbatim. */
function fplhMerge(array $doc, array $letterhead): array
{
    $page = $letterhead['page'] ?? [];    $own = $doc['page'] ?? [];
    // A document band replaces the letterhead's of that kind, first-page variant included.
    foreach (['header', 'footer'] as $kind) {
        if (isset($own[$kind])) {
            unset($page[$kind], $page['first'.ucfirst($kind)]);
        }
    }
    $margins = array_replace($page['margins'] ?? [], $own['margins'] ?? []);
    $doc['page'] = array_replace($page, $own);
    if ($margins !== []) {
        $doc['page']['margins'] = $margins;
    }

    return $doc;
}

/** The concatenated IDAT payload of a PNG: what a pass-through PDF image stream holds. */
function fplhIdat(string $png): string
{
    $o = 8;
    $data = '';
    while ($o + 8 <= strlen($png)) {
        $len = unpack('N', substr($png, $o, 4))[1];
        if (substr($png, $o + 4, 4) === 'IDAT') {
            $data .= substr($png, $o + 8, $len);
        }
        $o += 12 + $len;
    }

    return $data;
}

/** sha256 of every image XObject's raw (still encoded) stream in a PDF. */
function fplhImageHashes(string $pdf): array
{
    $doc = Document::open($pdf);
    $out = [];
    foreach ($doc->objectNumbers() as $n) {
        $o = $doc->object($n);
        if ($o instanceof PdfStream && $o->dict->name('Subtype') === 'Image') {
            $out[] = hash('sha256', $o->raw);
        }
    }

    return $out;
}

/** @return array{docx: string, png: string, jpeg: string} */
function fplhLetterhead(): array
{
    $png = ImageBuilder::png(24, 6, 2, 8, fn ($x, $y) => [200, ($x * 9) % 256, ($y * 31) % 256]);
    $jpeg = ImageBuilder::jpeg(32, 8);

    $docx = LastWord::toBytes(LastWord::validateAndRepair([
        'title' => 'Acme letterhead',
        'page' => [
            'size' => 'a4',
            'margins' => ['top' => 130, 'bottom' => 90, 'left' => 60, 'right' => 60, 'header' => 24, 'footer' => 20],
            'header' => ['blocks' => [
                ['type' => 'image', 'src' => ImageBuilder::dataUrl($png, 'image/png'), 'widthPx' => 120],
                ['type' => 'paragraph', 'runs' => [['text' => 'Acme Ltd (continuation)']]],
            ]],
            'firstHeader' => ['blocks' => [
                ['type' => 'image', 'src' => ImageBuilder::dataUrl($jpeg, 'image/jpeg'), 'widthPx' => 200],
                ['type' => 'paragraph', 'runs' => [['text' => 'Acme Ltd, 1 Example Road']]],
            ]],
            'footer' => ['center' => 'Page {page} of {pages}'],
            'firstFooter' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Registered office: Example Road']]]]],
        ],
        'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Letterhead body, never used.']]]],
    ])['schema']);

    return ['docx' => $docx, 'png' => $png, 'jpeg' => $jpeg];
}

function fplhLetter(): array
{
    return [
        'title' => 'Quote Q-0001',
        'blocks' => [
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Quote Q-0001']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'Body text on page one.']]],
            ['type' => 'pageBreak'],
            ['type' => 'paragraph', 'runs' => [['text' => 'Body text on page two.']]],
        ],
    ];
}

it('reads the page setup and all four bands from a last-word letterhead', function () {
    $read = LastWord::read(fplhLetterhead()['docx']);

    expect($read['page']['size'])->toBe('a4')
        ->and($read['page']['margins'])->toMatchArray(['top' => 130, 'bottom' => 90, 'left' => 60, 'right' => 60, 'header' => 24, 'footer' => 20])
        ->and(array_keys($read['page']))->toContain('header', 'footer', 'firstHeader', 'firstFooter')
        ->and($read['unrepresented'] ?? [])->toBe([]);
});

it('writes a clean PDF with the band images passed through byte for byte', function () {
    $lh = fplhLetterhead();
    $doc = fplhMerge(fplhLetter(), LastWord::read($lh['docx']));

    expect(Agent::validate($doc))->toBe([]);
    $repaired = Agent::validateAndRepair($doc);
    expect($repaired['ok'])->toBeTrue()
        ->and($repaired['errors'])->toBe([]);

    $pdf = Agent::toBytes($repaired['schema']);
    expect(Agent::pageCount($pdf))->toBe(2);

    $hashes = fplhImageHashes($pdf);
    expect($hashes)->toContain(hash('sha256', $lh['jpeg']))          // first-page header logo, JPEG as-is
        ->and($hashes)->toContain(hash('sha256', fplhIdat($lh['png']))); // continuation logo, PNG IDAT pass-through

    // The bands' text is on the pages it belongs to.
    $text = Agent::read($pdf, ['policy' => 'flag'])['pages'];
    expect($text[0]['text'])->toContain('Acme Ltd, 1 Example Road')
        ->and($text[0]['text'])->toContain('Registered office')
        ->and($text[1]['text'])->toContain('Acme Ltd (continuation)')
        ->and($text[1]['text'])->toContain('Page 2 of 2');
});

it('lets the document\'s own page values win over the letterhead', function () {
    $letter = fplhLetter();
    $letter['page'] = ['margins' => ['left' => 90], 'footer' => ['center' => 'Our own footer']];
    $doc = fplhMerge($letter, LastWord::read(fplhLetterhead()['docx']));

    expect($doc['page']['size'])->toBe('a4')
        ->and($doc['page']['margins'])->toMatchArray(['top' => 130, 'left' => 90, 'right' => 60])
        ->and($doc['page']['footer'])->toBe(['center' => 'Our own footer'])
        ->and($doc['page'])->not->toHaveKey('firstFooter')
        ->and($doc['page'])->toHaveKey('firstHeader');

    $pdf = Agent::toBytes(Agent::validateAndRepair($doc)['schema']);
    $pages = Agent::read($pdf, ['policy' => 'flag'])['pages'];
    expect($pages[0]['text'])->toContain('Our own footer')
        ->and($pages[0]['text'])->not->toContain('Registered office');
});

it('does NOT carry page setup or bands through the portable exchange', function () {
    $read = LastWord::read(fplhLetterhead()['docx']);
    $portable = LastWord::toPortable($read);
    expect($portable['ok'])->toBeTrue();

    $back = Agent::fromPortable($portable['content']);
    expect($back['ok'])->toBeTrue()
        ->and($back)->not->toHaveKey('page')
        ->and(json_encode($portable['content']))->not->toContain('Acme Ltd, 1 Example Road');
});
