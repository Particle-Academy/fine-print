<?php

declare(strict_types=1);

it('reads writes renders caches and watermarks without any vendor or framework autoloader', function () {
    $pipes = [];
    $child = proc_open([PHP_BINARY, dirname(__DIR__, 2).'/Support/core-no-framework-child.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($child))->toBeTrue();
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($child))->toBe(0)->and($out)->toBe('core-without-framework-ok')->and($err)->toBe('');
});
