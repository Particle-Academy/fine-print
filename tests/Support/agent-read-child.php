<?php

/*
 * Runs Agent::read() on one PDF file in its own PHP process and prints the
 * outcome as one line of JSON.
 *
 * Exists for hostile-input tests whose failure mode is a FATAL error (PHP's
 * memory limit): in-process, that would kill the whole suite instead of
 * failing one test. A fatal here leaves no JSON line and a non-zero exit,
 * which the parent test reads as red.
 *
 * Usage: php agent-read-child.php <pdf path>
 */

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

$pdf = (string) file_get_contents($argv[1]);
memory_reset_peak_usage();
$base = memory_get_usage();
$t = hrtime(true);
$r = FinePrint\Agent::read($pdf);

echo json_encode([
    'ok' => $r['ok'] ?? false,
    'reason' => $r['refused']['reason'] ?? null,
    'chars' => isset($r['pages']) ? array_sum(array_map(fn (array $p) => mb_strlen((string) ($p['text'] ?? '')), $r['pages'])) : 0,
    'peak' => memory_get_peak_usage() - $base,
    'seconds' => (hrtime(true) - $t) / 1e9,
]), "\n";
