<?php

declare(strict_types=1);

use FinePrint\Budget;
use FinePrint\Limits;
use FinePrint\Exceptions\RefusedException;
use FinePrint\Render\Renderer;
use FinePrint\Render\Watermark;
use FinePrint\Tests\Support\PdfBuilder;

it('burns a tiled viewer identity into pixels without changing the clean raster', function () {
    $clean = Renderer::render(fprPage('', '[0 0 400 300]'));
    $before = $clean->rows();
    $marked = Watermark::apply($clean, ['Alex', 'alex@example.test', '2026-10-22 23:00 UTC'], new Budget(new Limits()));
    $changedRows = 0;
    foreach ($marked->rows() as $row) {
        $changedRows += strspn($row, "\xFF") < strlen($row) ? 1 : 0;
    }
    expect($changedRows)->toBeGreaterThan(50)->and($clean->rows())->toBe($before)
        ->and($marked->complete())->toBeTrue()->and($marked->png())->not->toBe($clean->png());
});

it('keeps different viewers out of each others watermarked images', function () {
    $clean = Renderer::render(fprPage('', '[0 0 400 300]'));
    $a = Watermark::apply($clean, ['alex@example.test'], new Budget(new Limits()));
    $b = Watermark::apply($clean, ['riley@example.test'], new Budget(new Limits()));
    $again = Watermark::apply($clean, ['alex@example.test'], new Budget(new Limits()));
    expect($a->rows())->not->toBe($b->rows())->and($a->rows())->toBe($again->rows());
});

it('writes hostile watermark text as glyph data without PDF operator injection', function () {
    $clean = Renderer::render(fprPage('', '[0 0 400 300]'));
    $r = Watermark::apply($clean, [') ET 1 0 0 rg 0 0 999 999 re f ('], new Budget(new Limits()));
    $coloured = false;
    foreach ($r->rows() as $row) {
        for ($i = 0; $i < strlen($row); $i += 3) {
            if ($row[$i] !== $row[$i + 1] || $row[$i] !== $row[$i + 2]) {
                $coloured = true;
                break 2;
            }
        }
    }
    expect($coloured)->toBeFalse()->and($r->complete())->toBeTrue();
});

it('preserves the source omission report through watermarking', function () {
    $clean = Renderer::render(fprPage('/Shade sh', '[0 0 400 300]'));
    $marked = Watermark::apply($clean, ['alex@example.test'], new Budget(new Limits()));
    expect($marked->skipped)->toBe($clean->skipped)->and($marked->complete())->toBeFalse();
});

it('bounds watermark labels and checks the budget before drawing', function () {
    $clean = Renderer::render(fprPage(''));
    expect(fn () => Watermark::apply($clean, ['one', 'two', 'three', 'four'], new Budget(new Limits())))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Watermark::apply($clean, ['viewer'], new Budget(new Limits(timeSeconds: 0))))->toThrow(RefusedException::class);
});

it('refuses a label whose bounded prefix is all whitespace instead of claiming a watermark was drawn', function () {
    $clean = Renderer::render(fprPage(''));
    expect(fn () => Watermark::apply($clean, [str_repeat(' ', 600).'viewer'], new Budget(new Limits())))->toThrow(InvalidArgumentException::class);
});

it('renders WinAnsi viewer characters with the correct font encoding', function () {
    $clean = Renderer::render(fprPage('', '[0 0 120 120]'));
    $marked = Watermark::apply($clean, ['€'], new Budget(new Limits()));
    // On this small page only the central tile intersects it. An independent
    // one-glyph PDF with explicit WinAnsiEncoding is the reference.
    $b = new PdfBuilder();
    $catalog = $b->reserve();
    $tree = $b->reserve();
    $font = $b->object('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
    $c = cos(-M_PI / 6);
    $s = sin(-M_PI / 6);
    $content = $b->stream(sprintf('/Fade gs 0.4 g BT /W 12 Tf %.8F %.8F %.8F %.8F 60 60 Tm <80> Tj ET', $c, -$s, $s, $c));
    $page = $b->object('<< /Type /Page /Parent '.$tree.' 0 R /Contents '.$content.' 0 R /Resources << /Font << /W '.$font.' 0 R >> /ExtGState << /Fade << /ca 0.16 >> >> >> >>');
    $b->object('<< /Type /Pages /Count 1 /Kids ['.$page.' 0 R] /MediaBox [0 0 120 120] >>', $tree);
    $b->object('<< /Type /Catalog /Pages '.$tree.' 0 R >>', $catalog);
    expect($marked->rows())->toBe(Renderer::render($b->build())->rows());
});
