<?php

declare(strict_types=1);

it('refuses raster allocations before exhausting a configured or PHP memory limit', function (string $mode, string $memory) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit='.$memory, dirname(__DIR__, 2).'/Support/render-memory-child.php', $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($process))->toBeTrue();
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    expect($exit)->toBe(0)->and($out)->toBe('memory_budget')->and($err)->toBe('');
})->with(['tall row array' => ['thin', '64M'], 'PHP hard limit' => ['hard', '16M']]);

it('refuses offscreen and soft-mask allocations before a child hits its PHP hard limit', function (string $mode, string $memory) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit='.$memory, dirname(__DIR__, 2).'/Support/transparency-memory-child.php', $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($process))->toBeTrue();
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($out)->toBe('memory_budget')->and($err)->toBe('');
})->with(['configured group budget' => ['configured', '64M'], 'group PHP headroom' => ['hard', '32M'], 'mask PHP headroom' => ['mask', '32M'],
    'late group row' => ['late-group-row', '32M'], 'late page row' => ['late-page-row', '32M']]);
