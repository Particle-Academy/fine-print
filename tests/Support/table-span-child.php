<?php

declare(strict_types=1);

/*
 * Writes a small table whose spans cover many slots, in a child with its own
 * memory_limit: a regression is one failing test, not a dead suite. A fatal
 * error is the red.
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

use FinePrint\Agent;

$cell = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'x']]]], 'colSpan' => 1000, 'rowSpan' => 300];
$rows = [['cells' => array_fill(0, 30, $cell)], ...array_fill(0, 299, ['cells' => []])];

echo strlen(Agent::toBytes(['blocks' => [['type' => 'table', 'rows' => $rows]]])) > 0 ? 'written' : 'empty';
