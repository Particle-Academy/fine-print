<?php

declare(strict_types=1);

use FinePrint\Agent;
use FinePrint\Document;
use FinePrint\Exceptions\RefusedException;
use FinePrint\Limits;
use FinePrint\Tests\Support\Encoders;
use FinePrint\Tests\Support\PdfBuilder;

/*
 * Hostile input: every one of these files is built to hurt a reader — to
 * exhaust memory, loop forever, recurse off the stack or confuse the
 * cross-reference data. Each must end in a CLEAN outcome: a refusal with a
 * stable reason, or a repaired read. Never a fatal error, never a hang.
 */

function fpRefusal(callable $fn): ?string
{
    try {
        $fn();
    } catch (RefusedException $e) {
        return $e->reason;
    }

    return null;
}

// --- refusals ----------------------------------------------------------------

it('refuses something that is not a PDF', function () {
    expect(fpRefusal(fn () => Document::open('PK\x03\x04 this is a zip')))->toBe('not_pdf');

    $read = Agent::read('just some text');
    expect($read)->toBe(['ok' => false, 'refused' => ['reason' => 'not_pdf', 'message' => $read['refused']['message']]])
        ->and($read['refused']['message'])->toBeString();
});

it('refuses a decompression bomb quickly and in bounded memory', function () {
    $bomb = Encoders::bomb(200 * 1024 * 1024);
    $b = PdfBuilder::textPdf(['placeholder']);
    // Replace page 1's content (object 4) with the bomb.
    $b->rawStream($bomb, '/Filter /FlateDecode', 4);
    $pdf = $b->build();

    $peak = memory_get_peak_usage();
    $t = hrtime(true);
    $read = Agent::read($pdf);

    expect($read['ok'])->toBeFalse()
        ->and($read['refused']['reason'])->toBe('decompression_bomb')
        ->and((hrtime(true) - $t) / 1e9)->toBeLessThan(5.0)
        ->and(memory_get_peak_usage() - $peak)->toBeLessThan(48 * 1024 * 1024);
});

it('caps a decoded stream at its size limit even when the ratio looks innocent', function () {
    $data = '';
    for ($i = 0; $i < 40000; $i++) {
        $data .= hash('sha256', (string) $i);
    }
    $pdf = PdfBuilder::textPdf([$data], ['flate' => true])->build();

    expect(fpRefusal(fn () => Document::open($pdf, new Limits(maxStreamBytes: 1024 * 1024))->text(1)))->toBe('too_large');
});

it('refuses a file over the size limit before parsing it', function () {
    expect(fpRefusal(fn () => Document::open(fpText('x'), new Limits(maxFileBytes: 100))))->toBe('too_large');
});

it('refuses nesting deeper than the limit', function () {
    $deep = str_repeat('[', 5000).str_repeat(']', 5000);
    $b = PdfBuilder::textPdf([PdfBuilder::line('x')], ['page' => '/Deep '.$deep]);

    $read = Agent::read($b->build());
    expect($read['ok'])->toBeFalse()
        ->and($read['refused']['reason'])->toBe('too_deep');
});

it('refuses deep nesting inside a content stream too', function () {
    $content = 'BT /F1 12 Tf 72 700 Td '.str_repeat('[', 5000).str_repeat(']', 5000)." TJ ET\n";

    expect(fpRefusal(fn () => Document::open(PdfBuilder::textPdf([$content])->build())->text(1)))->toBe('too_deep');
});

it('refuses more objects than the limit', function () {
    $pdf = fpText('a', 'b', 'c', 'd', 'e', 'f');

    expect(fpRefusal(fn () => Document::open($pdf, new Limits(maxObjects: 8))))->toBe('too_many_objects');
});

it('refuses an xref stream that declares an absurd object count, before allocating', function () {
    $b = new PdfBuilder();
    $b->object('<< /Type /Catalog /Pages 2 0 R >>');
    $b->object('<< /Type /Pages /Kids [] /Count 0 >>');
    $pdf = $b->build(['xref' => 'stream']);
    $pdf = str_replace('/Type /XRef /Size 4 ', '/Type /XRef /Size 4 /Index [0 999999999] ', $pdf);

    $t = hrtime(true);
    expect(fpRefusal(fn () => Document::open($pdf)))->toBe('too_many_objects')
        ->and((hrtime(true) - $t) / 1e9)->toBeLessThan(2.0);
});

it('refuses a page with more operators than the limit', function () {
    $content = str_repeat('q Q ', 50_000);

    expect(fpRefusal(fn () => Document::open(PdfBuilder::textPdf([$content])->build(), new Limits(maxOperators: 10_000))->text(1)))->toBe('too_many_operators');
});

it('stops at the time budget', function () {
    $content = str_repeat('1 0 0 1 0 0 cm ', 200_000);

    expect(fpRefusal(fn () => Document::open(PdfBuilder::textPdf([$content])->build(), new Limits(timeSeconds: 0.05))->text(1)))->toBe('time_budget');
});

it('stops at the memory budget', function () {
    // Deterministic on purpose. This used random_bytes(), and about one run in
    // fifteen the random stream happened to lex into too few tokens for the
    // budget to be sampled, so the test failed for reasons of luck. Hundreds
    // of thousands of shown strings grow memory steadily, every run.
    $data = 'BT /F1 12 Tf 72 700 Td '.str_repeat('(abcdefghijklmnop) Tj ', 280_000).'ET';
    $pdf = PdfBuilder::textPdf([$data])->build();

    expect(fpRefusal(fn () => Document::open($pdf, new Limits(memoryBytes: 2 * 1024 * 1024))->text(1)))->toBe('memory_budget');
});

it('refuses an encrypted file rather than guessing at it', function () {
    $b = PdfBuilder::textPdf([PdfBuilder::line('secret')]);
    $enc = $b->object('<< /Filter /Standard /V 2 /R 3 /Length 128 /O <00> /U <00> /P -4 >>');
    $pdf = $b->build(['trailer' => ' /Encrypt '.$enc.' 0 R /ID [<01> <01>]']);

    expect(fpRefusal(fn () => Document::open($pdf)))->toBe('encrypted');
});

it('refuses garbage after a header, which no scan can repair', function () {
    expect(fpRefusal(fn () => Document::open("%PDF-1.7\nnot an object in sight\n%%EOF\n")))->toBe('corrupt');
});

it('turns every refusal into a structured answer for an agent, and throws for a host', function () {
    expect(Agent::read("%PDF-1.7\nnope")['refused']['reason'])->toBe('corrupt');

    Agent::pageCount("%PDF-1.7\nnope");
})->throws(RefusedException::class);

// --- recursion and loops: terminate, carry on, say so --------------------------

it('survives a reference cycle', function () {
    $b = PdfBuilder::textPdf([PdfBuilder::line('x')]);
    // Point page 1's /Contents at a chain that loops: 6 -> 7 -> 6.
    $b->object('7 0 R');
    $b->object('6 0 R');
    // Same length as the original, so no offset moves.
    $pdf = str_replace('/Contents 4 0 R', '/Contents 6 0 R', $b->build());
    $doc = Document::open($pdf);

    expect($doc->text(1))->toBe('')
        ->and(implode(' ', $doc->warnings()))->toContain('cycle');
});

it('survives a page tree that contains itself', function () {
    $b = new PdfBuilder();
    $b->object('<< /Type /Catalog /Pages 2 0 R >>');
    $b->object('<< /Type /Pages /Kids [3 0 R 2 0 R] /Count 1 >>');
    $b->object('<< /Type /Page /Parent 2 0 R >>');
    $doc = Document::open($b->build());

    expect($doc->pageCount())->toBe(1)
        ->and(implode(' ', $doc->warnings()))->toContain('page tree');
});

it('survives a form XObject that draws itself', function () {
    $b = PdfBuilder::textPdf(['/Fm1 Do'], ['resources' => '/XObject << /Fm1 6 0 R >>']);
    $b->stream(PdfBuilder::line('drawn once').' /Fm1 Do', '/Type /XObject /Subtype /Form /BBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> /XObject << /Fm1 6 0 R >> >>');
    $doc = Document::open($b->build());

    expect($doc->text(1))->toBe('drawn once')
        ->and(implode(' ', $doc->warnings()))->toContain('form');
});

it('survives an outline whose siblings loop', function () {
    $b = PdfBuilder::textPdf([PdfBuilder::line('x')], ['catalog' => '/Outlines 6 0 R']);
    $b->object('<< /Type /Outlines /First 7 0 R >>');
    $b->object('<< /Title (A) /Next 8 0 R >>');
    $b->object('<< /Title (B) /Next 7 0 R /First 7 0 R >>');
    $doc = Document::open($b->build());

    expect(array_column($doc->outline(), 'title'))->toBe(['A', 'B'])
        ->and(implode(' ', $doc->warnings()))->toContain('outline');
});

it('survives an xref /Prev chain that loops', function () {
    $pdf = fpText('looped');
    $xrefAt = strpos($pdf, "\nxref\n") + 1;
    $pdf = str_replace('/Root 1 0 R >>', '/Root 1 0 R /Prev '.$xrefAt.' >>', $pdf);

    expect(Document::open($pdf)->text(1))->toBe('looped');
});

// --- repair --------------------------------------------------------------------

it('repairs a truncated cross-reference table by scanning', function () {
    $pdf = fpText('recovered text');
    $cut = substr($pdf, 0, strpos($pdf, "\nxref\n") + 20);
    $doc = Document::open($cut);

    expect($doc->repaired())->toBeTrue()
        ->and($doc->text(1))->toBe('recovered text');
});

it('repairs a startxref that points at garbage', function () {
    $pdf = (string) preg_replace('/startxref\s+\d+/', 'startxref 3', fpText('pointed wrong'));
    $doc = Document::open($pdf);

    expect($doc->repaired())->toBeTrue()
        ->and($doc->text(1))->toBe('pointed wrong');
});

it('repairs offsets that no longer match their objects', function () {
    $pdf = fpText('shifted');
    // Insert bytes after the header: every offset in the table is now wrong.
    $pdf = (string) preg_replace('/\n/', "\n% padding the file to move every object\n", $pdf, 2);
    $doc = Document::open($pdf);

    expect($doc->text(1))->toBe('shifted')
        ->and($doc->repaired())->toBeTrue();
});

it('repairs a file with no cross-reference data at all, including object streams', function () {
    $pdf = PdfBuilder::textPdf([PdfBuilder::line('no xref here')])->build(['objstm' => true]);
    $pdf = substr($pdf, 0, strrpos($pdf, 'startxref'));
    $doc = Document::open($pdf);

    expect($doc->repaired())->toBeTrue()
        ->and($doc->text(1))->toBe('no xref here');
});

it('reports repair to the agent', function () {
    $pdf = fpText('recovered text');
    $read = Agent::read(substr($pdf, 0, strpos($pdf, "\nxref\n") + 20));

    expect($read['ok'])->toBeTrue()
        ->and($read['repaired'])->toBeTrue()
        ->and($read['pages'][0]['text'])->toBe('recovered text');
});
