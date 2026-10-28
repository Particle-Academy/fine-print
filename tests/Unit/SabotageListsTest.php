<?php

declare(strict_types=1);

/*
 * The sabotage lists in tests/Sabotage/lists stay APPLICABLE.
 *
 * A sabotage case whose edit no longer matches the source is worse than none:
 * the runner refuses it, but only when someone runs the pass, and until then
 * the list reads as coverage. They lived in the envelope's gitignored sandbox
 * until 2026-10-27, where nothing could notice them going stale. This test
 * runs in the ordinary suite, so a refactor that moves a guarded line fails
 * here, and the list is updated in the same change.
 *
 * It checks applicability only. Whether each case goes RED is the runner's
 * job (`php tests/Sabotage/run.php`): running Pest inside Pest seventy times
 * is a release-time pass, not a per-commit one.
 */

/** @return array<string, list<array<string, mixed>>> */
function fpsLists(): array
{
    $out = [];
    foreach (glob(dirname(__DIR__).'/Sabotage/lists/*.php') ?: [] as $file) {
        $out[basename($file, '.php')] = require $file;
    }
    ksort($out);

    return $out;
}

it('has the five lists the plan names', function (): void {
    expect(array_keys(fpsLists()))->toBe(['exchange', 'p1-reader-security', 'p2-writer', 'p3a-renderer', 'p3b-text']);
});

it('keeps every case applicable to the current source', function (string $list): void {
    $root = dirname(__DIR__, 2);
    $names = [];
    foreach (fpsLists()[$list] as $case) {
        expect($case['what'])->toBeString()->not->toBe('')
            ->and(isset($names[$case['what']]))->toBeFalse("{$list}: '{$case['what']}' is listed twice");
        $names[$case['what']] = true;

        foreach ($case['tests'] as $test) {
            expect(file_exists($root.'/'.$test))->toBeTrue("{$list}: '{$case['what']}' runs {$test}, which does not exist");
        }

        $patched = [];
        foreach ($case['edits'] as [$file, $from, $to]) {
            $current = $patched[$file] ?? (string) file_get_contents($root.'/'.$file);
            expect(substr_count($current, $from))->toBe(1, "{$list}: '{$case['what']}' no longer applies to {$file}");
            expect($from !== $to)->toBeTrue("{$list}: '{$case['what']}' changes nothing");
            $patched[$file] = str_replace($from, $to, $current);
        }
    }
    expect($names)->not->toBe([]);
})->with(['exchange', 'p1-reader-security', 'p2-writer', 'p3a-renderer', 'p3b-text']);
