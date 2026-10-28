<?php

declare(strict_types=1);

// Sabotage list: P2: the writer (links, active content, layout rules, images, fonts) plus encryption cases added alongside.
//
// Each case breaks one behaviour (every edit must apply EXACTLY once), runs
// the tests that guard it, and must go RED. Run with:
//   php tests/Sabotage/run.php p2-writer
// Moved in-repo on 2026-10-27 from the envelope's gitignored sandbox/fine-print/p2/sabotage.php;
// SabotageListsTest keeps every edit applicable to the current source.

return [
    [
        'what' => 'links unchecked in BOTH writer layers',
        'edits' => [
            [
                'src/Writer/Layout/Composer.php',
                '$link = Schema::linkAllowed($run[\'link\'] ?? null) ? $run[\'link\'] : null;',
                '$link = $run[\'link\'] ?? null;',
            ],
            [
                'src/Writer/PdfWriter.php',
                'if (! Schema::linkAllowed($link[\'uri\'])) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Writer/WriterSecurityTest.php',
        ],
        'filter' => 'unvalidated document',
    ],
    [
        'what' => 'catalog gains an OpenAction',
        'edits' => [
            [
                'src/Writer/PdfWriter.php',
                '\'<< /Type /Catalog /Pages \'.$tree.\' 0 R >>\'',
                '\'<< /Type /Catalog /Pages \'.$tree.\' 0 R /OpenAction << /S /JavaScript /JS (x) >> >>\'',
            ],
        ],
        'tests' => [
            'tests/Unit/Writer/WriterSecurityTest.php',
        ],
        'filter' => 'invent',
    ],
    [
        'what' => 'metadata written as a literal string',
        'edits' => [
            [
                'src/Writer/Pdf/Syntax.php',
                'return self::hex("\\xFE\\xFF".mb_convert_encoding($clean, \'UTF-16BE\', \'UTF-8\'));',
                'return \'(\'.$clean.\')\';',
            ],
        ],
        'tests' => [
            'tests/Unit/Writer/WriterSecurityTest.php',
        ],
        'filter' => 'invent',
    ],
    [
        'what' => 'orphan/widow control off',
        'edits' => [
            [
                'src/Writer/Layout/ParagraphFlow.php',
                'private const ORPHANS = 2;',
                'private const ORPHANS = 1;',
            ],
            [
                'src/Writer/Layout/ParagraphFlow.php',
                'if (count($this->lines) <= 3) {',
                'if (count($this->lines) <= 1) {',
            ],
            [
                'src/Writer/Layout/ParagraphFlow.php',
                '        if ($total <= 3) {
            return null;',
                '        if ($total <= 1) {
            return null;',
            ],
        ],
        'tests' => [
            'tests/Unit/Writer/LayoutTest.php',
        ],
        'filter' => 'never leaves one line',
    ],
    [
        'what' => 'keep-with-next ignored',
        'edits' => [
            [
                'src/Writer/Layout/Paginator.php',
                'if ($f->keepNext && ! $this->atTop) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Writer/LayoutTest.php',
        ],
        'filter' => 'keeps a heading',
    ],
    [
        'what' => 'header rows not repeated',
        'edits' => [
            [
                'src/Writer/Layout/TableFlow.php',
                '$header = $this->headerRows > 0 ? range(0, $this->headerRows - 1) : [];',
                '$header = [];',
            ],
        ],
        'tests' => [
            'tests/Unit/Writer/LayoutTest.php',
        ],
        'filter' => 'repeats header rows',
    ],
    [
        'what' => 'invisible characters kept',
        'edits' => [
            [
                'src/Writer/Layout/Composer.php',
                'preg_replace([self::INVISIBLE, self::CONTROLS], \'\', $text)',
                'preg_replace([self::CONTROLS], \'\', $text)',
            ],
        ],
        'tests' => [
            'tests/Unit/Writer/AgentWriterTest.php',
        ],
        'filter' => 'lints what the PDF',
    ],
    [
        'what' => 'PNG bomb copied through',
        'edits' => [
            [
                'src/Writer/Images/Png.php',
                '        self::inflate($idat, $png->expectedBytes());',
                '',
            ],
        ],
        'tests' => [
            'tests/Unit/Writer/ImageEmbedTest.php',
        ],
        'filter' => 'inflates past',
    ],
    [
        'what' => 'PNG predictor not declared',
        'edits' => [
            [
                'src/Writer/Images/Png.php',
                '\' /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors \'',
                '\' /Filter /FlateDecode /DecodeParms << /Predictor 1 /Colors \'',
            ],
        ],
        'tests' => [
            'tests/Unit/Writer/ImageEmbedTest.php',
        ],
        'filter' => 'RGB PNG through',
    ],
    [
        'what' => 'embedded font without ToUnicode',
        'edits' => [
            [
                'src/Writer/Fonts/EmbeddedFont.php',
                '\' /DescendantFonts [\'.$cid.\' 0 R] /ToUnicode \'.$toUnicode.\' 0 R >>\'',
                '\' /DescendantFonts [\'.$cid.\' 0 R] >>\'',
            ],
        ],
        'tests' => [
            'tests/Unit/Writer/FontEmbedTest.php',
        ],
        'filter' => 'astral',
    ],
    [
        'what' => 'subset keeps every glyph',
        'edits' => [
            [
                'src/Writer/Fonts/TrueType.php',
                'if (isset($keep[$g])) {',
                'if (true) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Writer/FontEmbedTest.php',
        ],
        'filter' => 'subsets the font',
    ],
    [
        'what' => 'standard fonts without /Widths',
        'edits' => [
            [
                'src/Writer/Fonts/StandardFont.php',
                '\' /Encoding /WinAnsiEncoding /FirstChar 32 /LastChar 255 /Widths [\'.implode(\' \', $this->widths).\'] >>\'',
                '\' /Encoding /WinAnsiEncoding >>\'',
            ],
        ],
        'tests' => [
            'tests/Unit/Writer/WriterTest.php',
        ],
        'filter' => 'real Helvetica widths',
    ],
    [
        'what' => 'justified spaces not widened',
        'edits' => [
            [
                'src/Writer/Layout/Canvas.php',
                'if ($p[\'glue\'] && $spread > 0) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Writer/WriterTest.php',
        ],
        'filter' => 'aligns left',
    ],
    [
        'what' => 'decryption skipped',
        'edits' => [
            [
                'src/Document.php',
                '$value = $this->security !== null && $o[\'num\'] !== $this->encryptNum',
                '$value = false && $o[\'num\'] !== $this->encryptNum',
            ],
        ],
        'tests' => [
            'tests/Unit/EncryptionTest.php',
        ],
        'filter' => 'reads it like any other',
    ],
    [
        'what' => 'any password accepted (R2-R4)',
        'edits' => [
            [
                'src/Security/StandardSecurity.php',
                'if (! self::userMatches($key, $u, $id0, $r)) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/EncryptionTest.php',
        ],
        'filter' => 'real password',
    ],
    [
        'what' => 'any password accepted (R5/R6)',
        'edits' => [
            [
                'src/Security/StandardSecurity.php',
                'if (! hash_equals(substr($u, 0, 32), self::hashR6(\'\', $vs, \'\', $r, $budget))) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/EncryptionTest.php',
        ],
        'filter' => 'real password',
    ],
    [
        'what' => 'key length unchecked',
        'edits' => [
            [
                'src/Security/StandardSecurity.php',
                'if ($bits !== null && (! is_int($bits) || $bits % 8 !== 0',
                'if (false && (! is_int($bits) || $bits % 8 !== 0',
            ],
        ],
        'tests' => [
            'tests/Unit/EncryptionTest.php',
        ],
        'filter' => 'malformed or unsupported',
    ],
    [
        'what' => 'metadata always decrypted',
        'edits' => [
            [
                'src/Document.php',
                '$plainMetadata = $type === \'Metadata\' && ! $this->security?->encryptMetadata;',
                '$plainMetadata = false;',
            ],
        ],
        'tests' => [
            'tests/Unit/EncryptionTest.php',
        ],
        'filter' => 'EncryptMetadata is false',
    ],
    [
        'what' => 'redaction notice dropped',
        'edits' => [
            [
                'src/Agent.php',
                'if ($policy !== \'redact\' || $result[\'acted\'] === []) {',
                'if (true) {',
            ],
        ],
        'tests' => [
            'tests/Unit/RedactionNoticeTest.php',
        ],
        'filter' => 'tells the agent',
    ],
    [
        'what' => 'sub-1pt text allowed',
        'edits' => [
            [
                'src/Writer/Validator.php',
                'if ($value < Schema::MIN_TEXT_SIZE || $value > Schema::MAX_TEXT_SIZE) {',
                'if ($value > Schema::MAX_TEXT_SIZE) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Writer/ModelTest.php',
        ],
        'filter' => 'too small to see',
    ],
];
