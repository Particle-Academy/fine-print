<?php

declare(strict_types=1);

// Sabotage list: P1: the reader and its security layer (parser, filters, guard, active content, encryption).
//
// Each case breaks one behaviour (every edit must apply EXACTLY once), runs
// the tests that guard it, and must go RED. Run with:
//   php tests/Sabotage/run.php p1-reader-security
// Moved in-repo on 2026-10-27 from the envelope's gitignored sandbox/fine-print/sabotage.php;
// SabotageListsTest keeps every edit applicable to the current source.

return [
    [
        'what' => 'name #xx escapes not decoded',
        'edits' => [
            [
                'src/Parser/Parser.php',
                'if (str_contains($raw, \'#\')) {',
                'if (false && str_contains($raw, \'#\')) {',
            ],
        ],
        'tests' => [
            'tests/Unit/ActiveContentTest.php',
        ],
        'filter' => 'sees through name escapes',
    ],
    [
        'what' => 'script body leaks into the report',
        'edits' => [
            [
                'src/Security/ActiveContentScanner.php',
                'return [\'length\' => strlen($source), \'sha256\' => hash(\'sha256\', $source)];',
                'return [\'length\' => strlen($source), \'sha256\' => hash(\'sha256\', $source), \'source\' => $source];',
            ],
        ],
        'tests' => [
            'tests/Unit/ActiveContentTest.php',
        ],
        'filter' => 'not its source',
    ],
    [
        'what' => 'redact policy passes text through',
        'edits' => [
            [
                'src/Security/AgentReadGuard.php',
                '$this->policy === \'redact\' => self::redact($seg[\'text\'], $merged),',
                '$this->policy === \'redact\' => $seg[\'text\'],',
            ],
        ],
        'tests' => [
            'tests/Unit/AgentReadGuardTest.php',
        ],
        'filter' => 'redacts the instruction under the redact policy',
    ],
    [
        'what' => 'white text never detected',
        'edits' => [
            [
                'src/Text/TextExtractor.php',
                'if ($v < 0.95) {',
                'if ($v < 9.95) {',
            ],
        ],
        'tests' => [
            'tests/Unit/AgentReadGuardTest.php',
        ],
        'filter' => 'flags white-on-white text',
    ],
    [
        'what' => 'painted background ignored',
        'edits' => [
            [
                'src/Text/TextExtractor.php',
                'if (! $r[\'white\'] && $x >= $r[\'x0\']',
                'if (false && $x >= $r[\'x0\']',
            ],
        ],
        'tests' => [
            'tests/Unit/AgentReadGuardTest.php',
        ],
        'filter' => 'does not flag white text drawn on a dark box',
    ],
    [
        'what' => 'excerpts always included',
        'edits' => [
            [
                'src/Security/AgentReadGuard.php',
                'if ($this->excerpts) {',
                'if (true) {',
            ],
        ],
        'tests' => [
            'tests/Unit/AgentReadGuardTest.php',
        ],
        'filter' => 'omits excerpts by default',
    ],
    [
        'what' => 'tag characters not decoded',
        'edits' => [
            [
                'src/Security/AgentReadGuard.php',
                '$smuggled .= chr($cp - 0xE0000);',
                '$smuggled .= "";',
            ],
        ],
        'tests' => [
            'tests/Unit/AgentReadGuardTest.php',
        ],
        'filter' => 'ASCII smuggling',
    ],
    [
        'what' => 'decompression ratio unchecked',
        'edits' => [
            [
                'src/Budget.php',
                'if ($outBytes > $this->limits->ratioFloorBytes && $outBytes > $this->limits->maxRatio * max(1, $inBytes)) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/HostileInputTest.php',
        ],
        'filter' => 'decompression bomb',
    ],
    [
        'what' => 'decoded stream cap unchecked',
        'edits' => [
            [
                'src/Budget.php',
                'if ($outBytes > $this->limits->maxStreamBytes) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/HostileInputTest.php',
            'tests/Unit/DecompressionRatioTest.php',
        ],
        'filter' => 'decompression bomb|byte cap',
    ],
    [
        'what' => 'ascii85 stage not judged while it runs',
        'edits' => [
            [
                'src/Filters/Filters.php',
                'if (($i & 0x3FF) === 0) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/DecompressionRatioTest.php',
        ],
        'filter' => 'inside an ascii85 stage',
    ],
    [
        'what' => 'inflate grows with no memory preflight',
        'edits' => [
            [
                'src/Filters/Filters.php',
                '$budget->decoding(strlen($out), max(1, $i), strlen($out) + 2 * self::MAX_INFLATE_PIECE);',
                '',
            ],
        ],
        'tests' => [
            'tests/Unit/DecompressionRatioTest.php',
        ],
        'filter' => 'memory_limit',
    ],
    [
        'what' => 'nesting depth unchecked',
        'edits' => [
            [
                'src/Parser/Parser.php',
                'if ($depth > $this->maxDepth) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/HostileInputTest.php',
        ],
        'filter' => 'refuses nesting deeper than the limit',
    ],
    [
        'what' => 'password-protected file opened',
        'edits' => [
            [
                'src/Security/StandardSecurity.php',
                'if (! self::userMatches($key, $u, $id0, $r)) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/HostileInputTest.php',
        ],
        'filter' => 'needs a password',
    ],
    [
        'what' => 'redacted words kept in the agent read',
        'edits' => [
            [
                'src/Agent.php',
                'if ($drop) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/WordsTest.php',
        ],
        'filter' => 'redacts words exactly as it redacts the page text',
    ],
    [
        'what' => 'word pieces joined across a gap in the guarded text',
        'edits' => [
            [
                'src/Text/Words.php',
                'if ($cur !== null && $cur[\'end\'] === $start) {',
                'if ($cur !== null) {',
            ],
        ],
        'tests' => [
            'tests/Unit/WordsTest.php',
        ],
        'filter' => 'redacts words exactly as it redacts the page text',
    ],
];
