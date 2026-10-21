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

/**
 * One page holding ONE show operator of $n character codes, Flate-compressed.
 *
 * The show-string bytes are a run of 'a' broken every 100 bytes by a
 * pseudo-random letter: that deflates to about 1/55 of its size, so 3 million
 * codes are a ~55 KB file, yet stays well under the 500x ratio cap. (A plain
 * str_repeat is refused as a decompression bomb before the text is ever read,
 * which tests nothing about the text path.)
 *
 * Kinds: `tj` a simple font and Tj; `TJ` the same string inside a TJ array;
 * `type0` a Type0 font with Identity-H (fixed two-byte codes); `type0-cmap` a
 * Type0 font whose ToUnicode CMap declares the codespace, so codes are cut by
 * the CMap.
 */
function fpHugeShow(string $kind, int $n): string
{
    mt_srand(7);
    $bytes = $kind === 'tj' || $kind === 'TJ' ? $n : 2 * $n;
    $s = '';
    while (strlen($s) < $bytes) {
        $s .= str_repeat('a', 99).chr(mt_rand(98, 122));
    }
    $s = substr($s, 0, $bytes);

    $show = match ($kind) {
        'tj' => '('.$s.') Tj',
        'TJ' => '[('.$s.')] TJ',
        default => '<'.bin2hex($s).'> Tj',
    };
    $b = new PdfBuilder();
    $catalog = $b->reserve();
    $tree = $b->reserve();
    if ($kind === 'tj' || $kind === 'TJ') {
        $font = $b->object('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
    } else {
        $tu = '';
        if ($kind === 'type0-cmap') {
            $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
                ."1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
                ."1 beginbfrange\n<6161> <6161> <0061>\nendbfrange\n"
                ."endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
            $tu = ' /ToUnicode '.$b->stream($cmap).' 0 R';
        }
        $font = $b->object('<< /Type /Font /Subtype /Type0 /BaseFont /X /Encoding /Identity-H'.$tu
            .' /DescendantFonts [<< /Type /Font /Subtype /CIDFontType2 /BaseFont /X'
            .' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> >>] >>');
    }
    $c = $b->stream('BT /F1 12 Tf 72 720 Td '.$show.' ET', '', true);
    $page = $b->object('<< /Type /Page /Parent '.$tree.' 0 R /Contents '.$c.' 0 R /Resources << /Font << /F1 '.$font.' 0 R >> >> >>');
    $b->object('<< /Type /Pages /Kids ['.$page.' 0 R] /Count 1 /MediaBox [0 0 612 792] >>', $tree);
    $b->object('<< /Type /Catalog /Pages '.$tree.' 0 R >>', $catalog);

    return $b->build();
}

it('reads one huge show string without building a list of its codes', function (string $kind) {
    // Found by measurement (2026-10-21): the extractor decoded a show string
    // into a list of [text, width, space] triples, about 160 bytes a code, so
    // a ~55 KB file with one 3-million-code Tj took Agent::read() past PHP's
    // 512 MB memory limit: a FATAL error, not a refusal. 400 thousand codes
    // cost ~64 MB that way; streamed, they cost the text itself.
    $pdf = fpHugeShow($kind, 400_000);
    gc_collect_cycles();
    memory_reset_peak_usage();
    $base = memory_get_usage();
    $read = Agent::read($pdf);

    expect($read['ok'])->toBeTrue()
        ->and(mb_strlen($read['pages'][0]['text']))->toBeGreaterThan(390_000)
        ->and(memory_get_peak_usage() - $base)->toBeLessThan(24 * 1024 * 1024);
})->with(['tj', 'TJ', 'type0', 'type0-cmap']);

it('ends a 3-million-code show string cleanly under the default limits, never in a fatal error', function (string $kind) {
    // In its own process: the failure being guarded against is PHP's memory
    // limit, which in-process would kill the suite rather than fail a test.
    $pdf = fpHugeShow($kind, 3_000_000);
    expect(strlen($pdf))->toBeLessThan(200 * 1024);
    $path = tempnam(sys_get_temp_dir(), 'fp-huge-');
    file_put_contents($path, $pdf);
    try {
        $out = [];
        exec(escapeshellarg(PHP_BINARY).' -d memory_limit=512M '
            .escapeshellarg(dirname(__DIR__).'/Support/agent-read-child.php').' '.escapeshellarg($path).' 2>&1', $out, $code);
    } finally {
        @unlink($path);
    }
    $last = json_decode((string) end($out), true);

    expect($code)->toBe(0, implode("\n", $out))
        ->and($last)->toBeArray()
        ->and($last['ok'] || in_array($last['reason'], ['memory_budget', 'time_budget'], true))->toBeTrue()
        ->and($last['peak'])->toBeLessThan((new Limits())->memoryBytes);
})->with(['tj', 'type0', 'type0-cmap']);

it('stops at the time budget inside one show string, not after it', function () {
    // A ToUnicode CMap of 100 thousand one-byte ranges that never match makes
    // every code a 100-thousand-step lookup. 40 thousand codes in ONE Tj are
    // billions of steps; the budget used to be checked only between
    // operators, so the read ran for minutes past a half-second budget.
    $b = new PdfBuilder();
    $catalog = $b->reserve();
    $tree = $b->reserve();
    $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
        ."1 begincodespacerange\n<00> <FF>\nendcodespacerange\n"
        ."100000 beginbfrange\n".str_repeat("<01> <01> <0041>\n", 100_000)."endbfrange\n"
        ."endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    $tu = $b->stream($cmap, '', true);
    $font = $b->object('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /ToUnicode '.$tu.' 0 R >>');
    $c = $b->stream('BT /F1 12 Tf 72 720 Td ('.str_repeat('a', 40_000).') Tj ET', '', true);
    $page = $b->object('<< /Type /Page /Parent '.$tree.' 0 R /Contents '.$c.' 0 R /Resources << /Font << /F1 '.$font.' 0 R >> >> >>');
    $b->object('<< /Type /Pages /Kids ['.$page.' 0 R] /Count 1 /MediaBox [0 0 612 792] >>', $tree);
    $b->object('<< /Type /Catalog /Pages '.$tree.' 0 R >>', $catalog);

    $t = hrtime(true);
    $read = Agent::read($b->build(), ['limits' => ['timeSeconds' => 0.5, 'ratioFloorBytes' => 64 * 1024 * 1024]]);

    expect($read['ok'])->toBeFalse()
        ->and($read['refused']['reason'])->toBe('time_budget')
        ->and((hrtime(true) - $t) / 1e9)->toBeLessThan(3.0);
});

it('refuses an encrypted file that needs a password, rather than guessing at it', function () {
    // Since 2026-10-21 (owner ruling) files that open with an EMPTY password
    // are read; see EncryptionTest. One that needs a real password is not.
    $pdf = FinePrint\Tests\Support\Encryptor::pdf(['secret'], ['revision' => 3, 'userPassword' => 'hunter2']);

    expect(fpRefusal(fn () => Document::open($pdf)))->toBe('encrypted');
});

it('refuses a stub encryption dictionary as corrupt', function () {
    $b = PdfBuilder::textPdf([PdfBuilder::line('secret')]);
    $enc = $b->object('<< /Filter /Standard /V 2 /R 3 /Length 128 /O <00> /U <00> /P -4 >>');
    $pdf = $b->build(['trailer' => ' /Encrypt '.$enc.' 0 R /ID [<01> <01>]']);

    expect(fpRefusal(fn () => Document::open($pdf)))->toBe('corrupt');
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
