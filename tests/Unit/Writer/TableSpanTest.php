<?php

declare(strict_types=1);

use FinePrint\Agent;

function fpSpanDoc(array $cell): array
{
    return ['blocks' => [['type' => 'table', 'rows' => [['cells' => [
        ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'x']]]]] + $cell,
    ]]]]]];
}

it('names the limit when a span is past it', function () {
    foreach (['colSpan', 'rowSpan'] as $span) {
        expect(Agent::validate(fpSpanDoc([$span => 1000])))->toBe([]);
        expect(Agent::validate(fpSpanDoc([$span => 1001])))->toBe([[
            'path' => "blocks[0].rows[0].cells[0].{$span}",
            'message' => "{$span} must be an integer from 1 to 1000",
        ]]);
    }
});

it('places spans without a memory cost for the slots they cover', function () {
    $cmd = [PHP_BINARY, '-d', 'memory_limit=64M', '-d', 'max_execution_time=20', '-d', 'display_errors=1',
        dirname(__DIR__, 2).'/Support/table-span-child.php'];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($proc))->toBe(0, $out);
    expect($out)->toBe('written');
});

it('flows later rows around a span and reports a ragged table only when one is', function () {
    $c = fn (array $extra = []) => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'x']]]]] + $extra;
    $lint = fn (array $rows) => array_column(Agent::lint(['blocks' => [['type' => 'table', 'rows' => $rows]]]), 'rule');

    // Every slot of a 3x2 grid is covered, one of them by a row span from above.
    expect($lint([
        ['cells' => [$c(['rowSpan' => 2]), $c(), $c()]],
        ['cells' => [$c(), $c()]],
    ]))->not->toContain('ragged-table');

    // The second row stops a column short.
    expect($lint([
        ['cells' => [$c(), $c(), $c()]],
        ['cells' => [$c(), $c()]],
    ]))->toContain('ragged-table');

    // A slot two spans both cover counts once: this row reaches only 2 of 3 columns.
    expect($lint([
        ['cells' => [$c(), $c(['rowSpan' => 2]), $c()]],
        ['cells' => [$c(['colSpan' => 2])]],
    ]))->toContain('ragged-table');
});
