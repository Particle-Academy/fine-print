<?php

declare(strict_types=1);

// Sabotage list: P3a: the renderer core (pixel cap, operator count, strokes, goldens).
//
// Each case breaks one behaviour (every edit must apply EXACTLY once), runs
// the tests that guard it, and must go RED. Run with:
//   php tests/Sabotage/run.php p3a-renderer
// Moved in-repo on 2026-10-27 from the envelope's gitignored sandbox/fine-print/p3/sabotage.php;
// SabotageListsTest keeps every edit applicable to the current source.
//
// Updated on the move, because the new test found them stale: the operator
// cap now reads `$this->operators + $this->pastOperators` (P3d's replay pass
// shares one count), so both operator cases target that line; and "text not
// reported" is gone, because P3b draws text and `skip('text')` no longer
// exists anywhere.

return [
    [
        'what' => 'pixel cap removed',
        'edits' => [
            [
                'src/Render/Renderer.php',
                'if (! is_finite($wf * $hf) || $wf * $hf > $limits->maxRenderPixels) {',
                'if (! is_finite($wf * $hf)) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/RenderHostileTest.php',
        ],
        'filter' => 'blow the pixel cap',
    ],
    [
        'what' => 'operators not counted',
        'edits' => [
            [
                'src/Render/Interpreter.php',
                'if (++$this->operators + $this->pastOperators > $max) {',
                'if (++$this->operators + $this->pastOperators > PHP_INT_MAX) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/RenderHostileTest.php',
        ],
        'filter' => 'more operators than the cap',
    ],
    [
        'what' => 'operators not counted (fan-out)',
        'edits' => [
            [
                'src/Render/Interpreter.php',
                'if (++$this->operators + $this->pastOperators > $max) {',
                'if (++$this->operators + $this->pastOperators > PHP_INT_MAX) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/RenderHostileTest.php',
        ],
        'filter' => 'billion-operator',
    ],
    [
        'what' => 'form depth cap removed',
        'edits' => [
            [
                'src/Render/Interpreter.php',
                'if (count($this->forms) >= $this->budget->limits->maxFormDepth) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/RenderHostileTest.php',
        ],
        'filter' => 'deep chain',
    ],
    [
        'what' => 'form cycle check removed',
        'edits' => [
            [
                'src/Render/Interpreter.php',
                'if (in_array($key, $this->forms, true)) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/RenderHostileTest.php',
        ],
        'filter' => 'draws itself',
    ],
    [
        'what' => 'render time budget not applied',
        'edits' => [
            [
                'src/Render/Renderer.php',
                'new Budget($limits->with([\'timeSeconds\' => $limits->renderTimeSeconds]), \'Rendering\')',
                'new Budget($limits, \'Rendering\')',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/RenderHostileTest.php',
        ],
        'filter' => 'render time budget',
    ],
    [
        'what' => 'even-odd treated as nonzero',
        'edits' => [
            [
                'src/Render/Rasterizer.php',
                'if (! $evenOdd) {',
                'if (true) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/RasterizerTest.php',
        ],
        'filter' => 'nested same-direction',
    ],
    [
        'what' => 'Rotate 90 drawn as 270',
        'edits' => [
            [
                'src/Render/Renderer.php',
                '90 => [0.0, $s, $s, 0.0, -$s * $y0, -$s * $x0],',
                '90 => [0.0, -$s, -$s, 0.0, $s * $y1, $s * $x1],',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/RendererTest.php',
        ],
        'filter' => 'Rotate in all four',
    ],
    [
        'what' => 'Rotate 90 drawn as 270 (golden)',
        'edits' => [
            [
                'src/Render/Renderer.php',
                '90 => [0.0, $s, $s, 0.0, -$s * $y0, -$s * $x0],',
                '90 => [0.0, -$s, -$s, 0.0, $s * $y1, $s * $x1],',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/GoldenTest.php',
        ],
        'filter' => 'rotate-90',
    ],
    [
        'what' => 'stroke polygons not oriented',
        'edits' => [
            [
                'src/Render/Stroker.php',
                'if (self::signedArea($poly) < 0) {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/StrokerTest.php',
        ],
        'filter' => 'right angle',
    ],
    [
        'what' => 'miter limit ignored',
        'edits' => [
            [
                'src/Render/Stroker.php',
                'if ($ratio <= $miterLimit) {',
                'if (true) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/StrokerTest.php',
        ],
        'filter' => 'past the miter limit',
    ],
    [
        'what' => 'miter limit ignored (golden)',
        'edits' => [
            [
                'src/Render/Stroker.php',
                'if ($ratio <= $miterLimit) {',
                'if (true) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/GoldenTest.php',
        ],
        'filter' => 'strokes',
    ],
    [
        'what' => 'CropBox not cut to MediaBox',
        'edits' => [
            [
                'src/Render/Renderer.php',
                '$x1 = min($mx1, $cx1);',
                '$x1 = $cx1;',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/RendererTest.php',
        ],
        'filter' => 'renders the CropBox',
    ],
    [
        'what' => 'pattern painted instead of skipped',
        'edits' => [
            [
                'src/Render/Interpreter.php',
                'if ($space->kind === \'pattern\') {',
                'if (false) {',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/RendererTest.php',
        ],
        'filter' => 'pattern and reports',
    ],
    [
        'what' => 'PNG CRC over data only',
        'edits' => [
            [
                'src/Render/PngEncoder.php',
                'pack(\'N\', crc32($type.$data))',
                'pack(\'N\', crc32($data))',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/PngEncoderTest.php',
        ],
        'filter' => 'round-trips RGB',
    ],
    [
        'what' => 'Paeth predictor wrong tie-break',
        'edits' => [
            [
                'src/Render/PngEncoder.php',
                '$v = $x - ($pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c));',
                '$v = $x - ($pa < $pb && $pa < $pc ? $a : ($pb <= $pc ? $b : $c));',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/PngEncoderTest.php',
        ],
        'filter' => 'every filter',
    ],
    [
        'what' => 'dash phase ignored',
        'edits' => [
            [
                'src/Render/Stroker.php',
                '$phase = fmod($phase, $period);',
                '$phase = 0.0;',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/StrokerTest.php',
        ],
        'filter' => 'shifts the dashes',
    ],
    [
        'what' => 'strokes 15% too wide (golden)',
        'edits' => [
            [
                'src/Render/Interpreter.php',
                '$width = $gs->lineWidth > 0 ? $gs->lineWidth : 1.0 / $stretch;',
                '$width = $gs->lineWidth > 0 ? $gs->lineWidth * 1.15 : 1.0 / $stretch;',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/GoldenTest.php',
        ],
        'filter' => 'strokes',
    ],
    [
        'what' => 'half-pixel x offset (golden)',
        'edits' => [
            [
                'src/Render/Renderer.php',
                'default => [$s, 0.0, 0.0, -$s, -$s * $x0, $s * $y1],',
                'default => [$s, 0.0, 0.0, -$s, -$s * $x0 + 0.5, $s * $y1],',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/GoldenTest.php',
        ],
        'filter' => 'curves',
    ],
    [
        'what' => 'one-pixel x offset (golden, rects)',
        'edits' => [
            [
                'src/Render/Renderer.php',
                'default => [$s, 0.0, 0.0, -$s, -$s * $x0, $s * $y1],',
                'default => [$s, 0.0, 0.0, -$s, -$s * $x0 + 1.0, $s * $y1],',
            ],
        ],
        'tests' => [
            'tests/Unit/Render/GoldenTest.php',
        ],
        'filter' => 'rects',
    ],
];
