<?php

declare(strict_types=1);

// Sabotage list: 0.5.0 exchange: portable-content limits, link schemes, image magic, export policy, no-text reports.
//
// Each case breaks one behaviour (every edit must apply EXACTLY once), runs
// the tests that guard it, and must go RED. Run with:
//   php tests/Sabotage/run.php exchange
// Moved in-repo on 2026-10-27 from the envelope's gitignored sandbox/fp-sabotage.php;
// SabotageListsTest keeps every edit applicable to the current source.

return [
    [
        'what' => 'limits: items limit removed',
        'edits' => [
            [
                'src/Exchange/Content.php',
                'if (count($p[\'items\']) > $limits[\'items\']) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Exchange',
            'tests/Unit/Writer/ModelTest.php',
        ],
    ],
    [
        'what' => 'limits: image bytes limit removed',
        'edits' => [
            [
                'src/Exchange/Content.php',
                'if ($this->imageBytes[$key] > $limits[\'imageBytes\']) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Exchange',
            'tests/Unit/Writer/ModelTest.php',
        ],
    ],
    [
        'what' => 'link scheme: portable link check removed',
        'edits' => [
            [
                'src/Exchange/Content.php',
                'if (array_key_exists(\'link\', $p) && ! Schema::linkAllowed($p[\'link\'])) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Exchange',
            'tests/Unit/Writer/ModelTest.php',
        ],
    ],
    [
        'what' => 'link scheme: D anchor removed (trailing LF accepted)',
        'edits' => [
            [
                'src/Writer/Schema.php',
                '[\\x21-\\x7E]+$#D\';',
                '[\\x21-\\x7E]+$#\';',
            ],
        ],
        'tests' => [
            'tests/Unit/Exchange',
            'tests/Unit/Writer/ModelTest.php',
        ],
    ],
    [
        'what' => 'image magic: magic-byte check removed',
        'edits' => [
            [
                'src/Exchange/Content.php',
                'if (! str_starts_with($head, self::MAGIC[$mime])) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Exchange',
            'tests/Unit/Writer/ModelTest.php',
        ],
    ],
    [
        'what' => 'policy: null policy allows everything',
        'edits' => [
            [
                'src/Exchange/ExportPolicy.php',
                'if ($policy === null || ! in_array($target, $policy->allow, true)) {',
                'if ($policy !== null && ! in_array($target, $policy->allow, true)) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Exchange',
            'tests/Unit/Writer/ModelTest.php',
        ],
    ],
    [
        'what' => 'policy: agent options may carry allow',
        'edits' => [
            [
                'src/Exchange/ExportPolicy.php',
                'public const OPTION_KEYS = [\'selector\', \'headerRows\', \'display\'];',
                'public const OPTION_KEYS = [\'selector\', \'headerRows\', \'display\', \'allow\'];',
            ],
        ],
        'tests' => [
            'tests/Unit/Exchange',
            'tests/Unit/Writer/ModelTest.php',
        ],
    ],
    [
        'what' => 'no-text: extract report locates by page text',
        'edits' => [
            [
                'src/Exchange/ToPortable.php',
                '$report->add(\'structure-not-recovered\', \'/pages/\'.$index);',
                '$report->add(\'structure-not-recovered\', \'/pages/\'.$index.\'/\'.$page[\'text\']);',
            ],
        ],
        'tests' => [
            'tests/Unit/Exchange',
            'tests/Unit/Writer/ModelTest.php',
        ],
    ],
    [
        'what' => 'no-text: read finding excerpt passed into the rule',
        'edits' => [
            [
                'src/Exchange/ToPortable.php',
                '$report->finding($f[\'rule\']);',
                '$report->finding($f[\'rule\'].($f[\'excerpt\'] ?? \'\'));',
            ],
        ],
        'tests' => [
            'tests/Unit/Exchange',
            'tests/Unit/Writer/ModelTest.php',
        ],
    ],
    [
        'what' => 'no-text: fromPortable report locates by cell text',
        'edits' => [
            [
                'src/Exchange/FromPortable.php',
                '$report->add(\'typed-as-text\', $at);',
                '$report->add(\'typed-as-text\', $at.\'/\'.($cell[\'text\'] ?? \'\'));',
            ],
        ],
        'tests' => [
            'tests/Unit/Exchange',
            'tests/Unit/Writer/ModelTest.php',
        ],
    ],
];
