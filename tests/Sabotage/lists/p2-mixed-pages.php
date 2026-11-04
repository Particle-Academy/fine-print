<?php

declare(strict_types=1);

// Sabotage list: mixed page orientation, bands, keepNext on the other blocks, footnotes.
//
// Each case breaks one behaviour (every edit must apply EXACTLY once), runs
// the tests that guard it, and must go RED. Run with:
//   php tests/Sabotage/run.php p2-mixed-pages
// SabotageListsTest keeps every edit applicable to the current source.

return [
    [
        'what' => 'one MediaBox for all pages',
        'edits' => [
            ['src/Writer/PdfWriter.php', "/MediaBox [0 0 '.Syntax::num(\$page->width).' '.Syntax::num(\$page->height).']'", "/MediaBox [0 0 '.Syntax::num(\$pages[0]->width).' '.Syntax::num(\$pages[0]->height).']'"],
        ],
        'tests' => ['tests/Unit/Writer/MixedPagesTest.php'],
        'filter' => 'one MediaBox per page',
    ],
    [
        'what' => 'bands at portrait width on a landscape page: one-line slots',
        'edits' => [
            ['src/Writer/Layout/PageBands.php', "'right' => \$canvas->width - \$m['right'] - \$line->width,", "'right' => 612.0 - \$m['right'] - \$line->width,"],
        ],
        'tests' => ['tests/Unit/Writer/BlockBandsTest.php'],
        'filter' => 'laid out at its own width',
    ],
    [
        'what' => 'bands at portrait width on a landscape page: blocks',
        'edits' => [
            ['src/Writer/Layout/PageBands.php', "\$width = \$canvas->width - \$m['left'] - \$m['right'];", "\$width = 612.0 - \$m['left'] - \$m['right'];"],
        ],
        'tests' => ['tests/Unit/Writer/BlockBandsTest.php'],
        'filter' => 'footer row at the landscape width',
    ],
    [
        'what' => 'repair strips page from a break',
        'edits' => [
            ['src/Writer/Repairer.php', "'pageBreak' => \$this->pageBreak(\$block, \$path),", "'pageBreak' => ['type' => 'pageBreak'],"],
        ],
        'tests' => ['tests/Unit/Writer/PageBreakVocabularyTest.php'],
        'filter' => 'repair keeps page',
    ],
    [
        'what' => 'keepNext ignored on tables, images, code and rules',
        'edits' => [
            ['src/Writer/Layout/Composer.php', "return array_intersect_key(\$block, ['keepNext' => true]);", 'return [];'],
        ],
        'tests' => ['tests/Unit/Writer/KeepNextBlocksTest.php'],
        'filter' => 'keeps its caption',
    ],
    [
        'what' => 'the spacing keys read on those blocks too',
        'edits' => [
            ['src/Writer/Layout/Composer.php', "return array_intersect_key(\$block, ['keepNext' => true]);", 'return $block;'],
        ],
        'tests' => ['tests/Unit/Writer/KeepNextBlocksTest.php'],
        'filter' => 'not the spacing keys',
    ],
    [
        'what' => 'footnote dropped: the note never drawn',
        'edits' => [
            ['src/Writer/Layout/Paginator.php', '$note->draw($page, $this->x, $y);', ''],
        ],
        'tests' => ['tests/Unit/Writer/FootnoteTest.php'],
        'filter' => 'foot of the page',
    ],
    [
        'what' => 'footnote dropped: the mark never made',
        'edits' => [
            ['src/Writer/Layout/Composer.php', "\$pieces[] = \$this->footnoteMark(\$run['footnote'], \$ctx, \"{\$path}[{\$i}]\");", ''],
        ],
        'tests' => ['tests/Unit/Writer/FootnoteTest.php'],
        'filter' => 'foot of the page',
    ],
    [
        'what' => 'no room reserved for a note',
        'edits' => [
            ['src/Writer/Layout/Paginator.php', 'return $h > 0.0 && $this->notesHere === [] ? $h + self::NOTE_SEPARATOR : $h;', 'return 0.0;'],
        ],
        'tests' => ['tests/Unit/Writer/FootnoteTest.php'],
        'filter' => 'reserves its room',
    ],
    [
        'what' => 'a note that runs off the page is lost',
        'edits' => [
            ['src/Writer/Layout/Paginator.php', '$this->carry = $carry;', '$this->carry = [];'],
        ],
        'tests' => ['tests/Unit/Writer/FootnoteTest.php'],
        'filter' => 'losing nothing',
    ],
    [
        'what' => 'a note set at its cell\'s width',
        'edits' => [
            ['src/Writer/Layout/Composer.php', '$width = max(1.0, $this->noteWidth - $number->width - 3.0);', '$width = 40.0;'],
        ],
        'tests' => ['tests/Unit/Writer/FootnoteTest.php'],
        'filter' => 'as wide as the page',
    ],
    [
        'what' => 'the mark raised too far, so it reads back before its sentence',
        'edits' => [
            ['src/Writer/Layout/Composer.php', 'private const NOTE_MARK_RISE = 0.3;', 'private const NOTE_MARK_RISE = 0.4;'],
        ],
        'tests' => ['tests/Unit/Writer/FootnoteTest.php'],
        'filter' => 'in its sentence',
    ],
    [
        'what' => 'text rise never reset after a mark',
        'edits' => [
            ['src/Writer/Layout/Canvas.php', 'if ($st->rise !== $this->ts) {', 'if ($st->rise !== $this->ts && $st->rise !== 0.0) {'],
        ],
        'tests' => ['tests/Unit/Writer/FootnoteTest.php'],
        'filter' => 'resets the text rise',
    ],
    [
        'what' => 'firstHeader inherited across a break',
        'edits' => [
            ['src/Writer/Schema.php', "unset(\$page['firstHeader'], \$page['firstFooter']);", ''],
        ],
        'tests' => ['tests/Unit/Writer/BlockBandsTest.php'],
        'filter' => 'never inherits',
    ],
    [
        'what' => 'margins replaced whole instead of merged',
        'edits' => [
            ['src/Writer/Schema.php', "if (\$key === 'margins' && is_array(\$value) && is_array(\$page['margins'] ?? null)) {", 'if (false) {'],
        ],
        'tests' => ['tests/Unit/Writer/MixedPagesTest.php'],
        'filter' => 'merges margins',
    ],
    [
        'what' => 'a token in a blocks band left literal',
        'edits' => [
            ['src/Writer/Layout/Composer.php', "\$source = \$this->mode === 'band' && \$this->fields !== null ? \$this->expandFields(\$run['text'], \$this->fields, \"{\$path}[{\$i}]\") : \$run['text'];", "\$source = \$run['text'];"],
        ],
        'tests' => ['tests/Unit/Writer/BlockBandsTest.php'],
        'filter' => 'inside a table cell',
    ],
    [
        'what' => 'a tall band does not push the body',
        'edits' => [
            ['src/Writer/Layout/PageBands.php', "\$top = max(\$top, \$m['header'] + \$h);", ''],
        ],
        'tests' => ['tests/Unit/Writer/BlockBandsTest.php'],
        'filter' => 'pushes the body down',
    ],
    [
        'what' => 'a one-line band pushes the body',
        'edits' => [
            ['src/Writer/Layout/PageBands.php', "if (\$picked === null || ! is_array(\$picked['band']['blocks'] ?? null)) {", 'if ($picked === null) {'],
            ['src/Writer/Layout/PageBands.php', "\$h = \$this->measure(\$picked['band'], \$width, \$pageHeight, \$stretch['doc'], \$picked['path']);", "\$h = is_array(\$picked['band']['blocks'] ?? null) ? \$this->measure(\$picked['band'], \$width, \$pageHeight, \$stretch['doc'], \$picked['path']) : 12.0;"],
        ],
        'tests' => ['tests/Unit/Writer/BlockBandsTest.php'],
        'filter' => 'does not push the body',
    ],
    [
        'what' => 'an even band keyed to the stretch rather than the running page number',
        'edits' => [
            ['src/Writer/PdfWriter.php', "\$bands->draw(\$canvas, \$p['stretch'], \$p['m'], \$fields, \$i + 1, \$p['first']);", "\$bands->draw(\$canvas, \$p['stretch'], \$p['m'], \$fields, 1, \$p['first']);"],
        ],
        'tests' => ['tests/Unit/Writer/BlockBandsTest.php'],
        'filter' => 'running page number',
    ],
    [
        'what' => 'near-miss keys tolerated on a break',
        'edits' => [
            ['src/Writer/Validator.php', "\$this->error(\"{\$path}.{\$key}\", \"a pageBreak has no {\$key} key; use pageBreak.page\");", ''],
        ],
        'tests' => ['tests/Unit/Writer/PageBreakVocabularyTest.php'],
        'filter' => 'near-miss, naming page',
    ],
    [
        'what' => 'an image no page drew still written',
        'edits' => [
            ['src/Writer/Images/ImageStore.php', "if (! isset(\$drawn[\$entry['name']])) {", 'if (false) {'],
        ],
        'tests' => ['tests/Unit/Writer/BlockBandsTest.php'],
        'filter' => 'only images a page actually draws',
    ],
    [
        'what' => 'write stops returning warnings',
        'edits' => [
            ['src/Agent.php', "'warnings' => \$out['diagnostics']];", "'warnings' => []];"],
        ],
        'tests' => ['tests/Unit/Writer/WriterWarningsTest.php'],
        'filter' => 'warns from write',
    ],
    [
        'what' => 'a literal date goes quiet',
        'edits' => [
            ['src/Writer/Layout/Composer.php', "\$this->diagnostics->add('band-field-literal', 'warning', \$path, 'a PDF has no live date or time and this writer does not know the file name: {date}, {time} and {filename} are written as literal text, so put the value in the text itself');", ''],
        ],
        'tests' => ['tests/Unit/Writer/WriterWarningsTest.php'],
        'filter' => 'literal {date}',
    ],
];
