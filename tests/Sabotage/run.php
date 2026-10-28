<?php

declare(strict_types=1);

/*
 * The sabotage pass: break one behaviour at a time, run the tests that guard
 * it, and require RED. A guard whose test stays green when the guard is gone is
 * not guarded.
 *
 *     php tests/Sabotage/run.php                 # every list
 *     php tests/Sabotage/run.php p2-writer       # one list
 *     ONLY_CASE=bomb php tests/Sabotage/run.php  # cases whose name contains "bomb"
 *
 * Rules the runner enforces, because each was a way a past run lied:
 *
 * - Every edit must apply EXACTLY once. An edit that does not apply would run
 *   the unbroken code and read as "green", proving nothing; it is a setup
 *   failure here, and SabotageListsTest fails it in the ordinary suite first.
 * - A run with no Pest `Tests:` summary is not red, it is a run that did not
 *   happen (a fatal before the suite, a missing file).
 * - Output goes to a FILE and the exit code is exec()'s own, never read
 *   through a pipe.
 * - Every edited file is restored in `finally`, and the restore is verified.
 */

$root = dirname(__DIR__, 2);
chdir($root);
$out = $root.'/tests/.out';
if (! is_dir($out)) {
    mkdir($out, 0o777, true);
}

$lists = array_slice($argv, 1);
if ($lists === []) {
    $lists = array_map(fn (string $f) => basename($f, '.php'), glob(__DIR__.'/lists/*.php') ?: []);
}
$only = getenv('ONLY_CASE');

$failures = 0;
$total = 0;
foreach ($lists as $list) {
    $path = __DIR__.'/lists/'.$list.'.php';
    if (! is_file($path)) {
        fwrite(STDERR, "no sabotage list {$list}\n");
        exit(2);
    }
    echo "== {$list}\n";
    foreach (require $path as $case) {
        if ($only !== false && $only !== '' && ! str_contains($case['what'], $only)) {
            continue;
        }
        $total++;
        $originals = [];
        $patched = [];
        $setup = null;
        foreach ($case['edits'] as [$file, $from, $to]) {
            $originals[$file] ??= (string) file_get_contents($file);
            $current = $patched[$file] ?? $originals[$file];
            $n = substr_count($current, $from);
            if ($n !== 1) {
                $setup = "edit applies {$n} times in {$file}";
                break;
            }
            $patched[$file] = str_replace($from, $to, $current);
        }
        if ($setup !== null) {
            printf("  SETUP ERROR  %-48s %s\n", $case['what'], $setup);
            $failures++;

            continue;
        }

        $log = $out.'/sabotage.log';
        $cmd = escapeshellarg(PHP_BINARY).' vendor/pestphp/pest/bin/pest '
            .implode(' ', array_map('escapeshellarg', $case['tests']))
            .(isset($case['filter']) ? ' --filter='.escapeshellarg($case['filter']) : '')
            .' > '.escapeshellarg($log).' 2>&1';
        try {
            foreach ($patched as $file => $content) {
                file_put_contents($file, $content);
            }
            exec($cmd, $_, $code);
        } finally {
            foreach ($originals as $file => $orig) {
                file_put_contents($file, $orig);
            }
        }
        foreach ($originals as $file => $orig) {
            if (file_get_contents($file) !== $orig) {
                fwrite(STDERR, "ABORT: restoring {$file} failed\n");
                exit(3);
            }
        }

        $text = (string) preg_replace('/\e\[[0-9;]*m/', '', (string) file_get_contents($log));
        $ran = preg_match('/Tests:\s+([^\n]*)/', $text, $m) === 1;
        $red = $code !== 0 && $ran;
        if (! $red) {
            $failures++;
        }
        printf("  %-11s  %-48s exit=%d  %s\n", $red ? 'RED (good)' : ($ran ? 'STILL GREEN' : 'DID NOT RUN'), $case['what'], $code, trim($m[1] ?? ''));
    }
}

printf("%d case(s), %d not red\n", $total, $failures);
exit($failures === 0 && $total > 0 ? 0 : 1);
