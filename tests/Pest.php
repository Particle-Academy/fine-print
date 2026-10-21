<?php

declare(strict_types=1);

use FinePrint\Tests\Support\PdfBuilder;

/*
 * Shared helpers. Every PDF in this suite is written by tests/Support —
 * see PdfBuilder for why.
 */

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
