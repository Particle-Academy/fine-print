<?php

declare(strict_types=1);

// Sabotage list: P3b: TrueType text rendering (budget, outlines, culling, glyph limits, font names).
//
// Each case breaks one behaviour (every edit must apply EXACTLY once), runs
// the tests that guard it, and must go RED. Run with:
//   php tests/Sabotage/run.php p3b-text
// Moved in-repo on 2026-10-27 from the envelope's gitignored sandbox/fine-print/p3b/sabotage.php;
// SabotageListsTest keeps every edit applicable to the current source.
//
// Updated on the move: the per-glyph tick's next line became
// `if ($draw || $clipOn) {` when P3d added clipping, so the case targets that.

return [
    [
        'what' => 'no budget tick per glyph',
        'edits' => [
            [
                'src/Render/Interpreter.php',
                '                $this->budget->tick();
                if ($draw || $clipOn) {',
                '                if ($draw || $clipOn) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/TextHostileTest.php',
        ],
    ],
    [
        'what' => 'contour start ignores an on-curve last point',
        'edits' => [
            [
                'src/Render/Glyphs/TrueTypeGlyphs.php',
                '} elseif ($last[2]) {',
                '} elseif (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/GlyphOutlineTest.php',
        ],
    ],
    [
        'what' => 'word spacing on every code',
        'edits' => [
            [
                'src/Render/Glyphs/RenderFont.php',
                '$tt->unitsPerEm, $c === 32];',
                '$tt->unitsPerEm, true];',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/TextRenderTest.php',
        ],
    ],
    [
        'what' => 'no lsb (pp1) shift',
        'edits' => [
            [
                'src/Render/Glyphs/TrueTypeGlyphs.php',
                '$shift = (float) ($this->font->leftSideBearing($gid) - self::s16($data, 2));',
                '$shift = 0.0;',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/GlyphOutlineTest.php',
        ],
    ],
    [
        'what' => 'no cull',
        'edits' => [
            [
                'src/Render/Interpreter.php',
                '        if (max($xs) < -$margin',
                '        if (false && max($xs) < -$margin',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/TextHostileTest.php',
        ],
    ],
    [
        'what' => 'no point limit',
        'edits' => [
            [
                'src/Render/Glyphs/TrueTypeGlyphs.php',
                'if ($total + $n > self::MAX_POINTS) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/GlyphOutlineTest.php',
        ],
    ],
    [
        'what' => 'no depth limit',
        'edits' => [
            [
                'src/Render/Glyphs/TrueTypeGlyphs.php',
                'if ($depth + 1 > self::MAX_DEPTH) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/GlyphOutlineTest.php',
        ],
    ],
    [
        'what' => 'no self-containment check',
        'edits' => [
            [
                'src/Render/Glyphs/TrueTypeGlyphs.php',
                'if ($child === $gid || in_array($child, $stack, true)) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/GlyphOutlineTest.php',
        ],
    ],
    [
        'what' => 'symbolic (3,0) without F0xx',
        'edits' => [
            [
                'src/Render/Glyphs/RenderFont.php',
                'foreach ([$code, 0xF000 + $code, 0xF100 + $code, 0xF200 + $code] as $c) {',
                'foreach ([$code] as $c) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/TextRenderTest.php',
        ],
    ],
    [
        'what' => 'TJ adjustment sign flipped',
        'edits' => [
            [
                'src/Render/Interpreter.php',
                '$this->advance(-self::finite((float) $it) / 1000 * $fs * $th);',
                '$this->advance(self::finite((float) $it) / 1000 * $fs * $th);',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/TextRenderTest.php',
        ],
    ],
    [
        'what' => 'glyph outlines y-flipped',
        'edits' => [
            [
                'src/Render/Glyphs/TrueTypeGlyphs.php',
                '$y = (float) $ys[$i];',
                '$y = -(float) $ys[$i];',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/GoldenTest.php',
        ],
    ],
    [
        'what' => 'font name not cleaned',
        'edits' => [
            [
                'src/Render/Glyphs/RenderFont.php',
                'return substr((string) preg_replace(\'/[^A-Za-z0-9+_.\\-]/\', \'\', $name), 0, 63);',
                'return $name;',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/TextHostileTest.php',
        ],
    ],
];
