<?php

declare(strict_types=1);

use FinePrint\Tests\Support\PdfBuilder;

/*
 * Shared helpers. Every PDF in this suite is written by tests/Support —
 * see PdfBuilder for why.
 */

/**
 * Renderer helper: a one-page PDF with the given content stream, page
 * dictionary extras and resources. Extra objects added by `$extra` are
 * numbered from 6 (textPdf: catalog 1, tree 2, font 3, content 4, page 5).
 */
function fprPage(string $content, string $mediaBox = '[0 0 100 100]', string $page = '', string $resources = '', ?callable $extra = null): string
{
    $b = PdfBuilder::textPdf([$content], ['mediaBox' => $mediaBox, 'page' => $page, 'resources' => $resources]);
    if ($extra !== null) {
        $extra($b);
    }

    return $b->build();
}

/** A one-or-more page PDF of plain Helvetica text lines. */
function fpText(string ...$pages): string
{
    return PdfBuilder::textPdf(array_map(fn (string $t) => PdfBuilder::line($t), $pages))->build();
}

/**
 * A Helvetica font whose ToUnicode maps printable ASCII to itself plus three
 * extra codes: 0x01 -> U+200B (zero-width space), 0x02 -> U+202E (right-to-left
 * override) and 0x03 -> U+E0041 (TAG LATIN CAPITAL LETTER A, a surrogate pair).
 * Lets page text carry characters no simple encoding can.
 */
function fpUnicodeFont(PdfBuilder $b): int
{
    $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
        ."1 begincodespacerange\n<00> <FF>\nendcodespacerange\n"
        ."3 beginbfchar\n<01> <200B>\n<02> <202E>\n<03> <DB40DC41>\nendbfchar\n"
        ."1 beginbfrange\n<20> <7E> <0020>\nendbfrange\n"
        ."endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    $tu = $b->stream($cmap);

    return $b->object('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /ToUnicode '.$tu.' 0 R >>');
}

/*
 * Writer helpers. The writer's tests read its output back through the P1
 * reader: that round trip IS the core test loop. A PDF the writer makes must
 * read back with the right text, pages and sizes, and with no guard findings.
 */

/** A paragraph block of one plain run. */
function fpwPara(string $text, array $props = []): array
{
    return ['type' => 'paragraph', 'runs' => [['text' => $text]]] + $props;
}

/** Write a document (throws on an invalid one, which a test wants). */
function fpwWrite(array $doc, array $options = []): string
{
    return FinePrint\Agent::toBytes($doc, $options);
}

/** Read back for an agent, flag policy, so findings are visible and nothing is cut. */
function fpwRead(string $pdf): array
{
    return FinePrint\Agent::read($pdf, ['policy' => 'flag', 'threshold' => 'low']);
}

/** Whitespace-separated words, in order. */
function fpwWords(string $text): array
{
    return preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/** The positioned text runs P1 extracts from a page. */
function fpwRuns(string $pdf, int $page = 1): array
{
    return array_values(array_filter(
        FinePrint\Document::open($pdf)->pageText($page)['runs'],
        fn (array $r) => trim($r['text']) !== '',
    ));
}

/** All the page texts of a read-back, joined. */
function fpwAllText(array $read): string
{
    return implode("\n", array_column($read['pages'], 'text'));
}

/** A lorem-free filler sentence set, so word order can be checked exactly. */
function fpwSentence(int $i): string
{
    return "Sentence {$i} carries words alpha{$i} beta{$i} gamma{$i} delta{$i} and ends here.";
}

require_once __DIR__.'/Support/text-helpers.php';
