<?php

declare(strict_types=1);

namespace FinePrint\Writer;

/**
 * Rows of data as a finished document: an optional caption above (kept with
 * the table), a header row (bold, filled), numbers right-aligned and
 * thousands-separated, a bottom rule per row and no vertical lines, an
 * optional source line below. The look the agent guide asks for.
 */
final class Tabulator
{
    /**
     * @param  list<list<mixed>>  $rows
     * @param  list<string>|null  $headers
     * @param  array{caption?: string, source?: string}  $options
     * @return array<string, mixed>
     */
    public static function document(array $rows, ?array $headers = null, ?string $title = null, array $options = []): array
    {
        $rows = array_values(array_map(fn ($r) => is_array($r) ? array_values($r) : [$r], $rows));
        if ($headers === null && $rows !== []) {
            $headers = array_map(fn ($h) => self::text($h), array_shift($rows));
        }
        $headers = array_values($headers ?? []);
        $columns = max(count($headers), ...array_map('count', $rows ?: [[]]));

        $numeric = [];
        $decimals = [];
        for ($c = 0; $c < $columns; $c++) {
            $values = array_filter(array_column($rows, $c), fn ($v) => $v !== null && $v !== '');
            $numeric[$c] = $values !== [] && array_filter($values, fn ($v) => ! self::isNumeric($v)) === [];
            // One precision per column: if any number has a fraction, all get two decimals.
            $decimals[$c] = array_filter($values, fn ($v) => is_float($v) && floor($v) !== $v) !== [] ? 2 : 0;
        }

        $cell = function (mixed $v, int $c, bool $header) use ($numeric, $decimals): array {
            $p = ['type' => 'paragraph', 'runs' => [['text' => $header ? self::text($v) : self::format($v, $decimals[$c] ?? 0)]]];
            if ($numeric[$c] ?? false) {
                $p['align'] = 'right';
            }

            return ['blocks' => [$p]];
        };

        $tableRows = [];
        if ($headers !== []) {
            $tableRows[] = ['header' => true, 'cells' => array_map(fn ($c) => $cell($headers[$c] ?? '', $c, true), range(0, $columns - 1))];
        }
        foreach ($rows as $row) {
            $tableRows[] = ['cells' => array_map(fn ($c) => $cell($row[$c] ?? '', $c, false), range(0, max(0, $columns - 1)))];
        }

        $blocks = [];
        if (is_string($options['caption'] ?? null) && $options['caption'] !== '') {
            $blocks[] = ['type' => 'paragraph', 'keepNext' => true, 'spaceAfter' => 4, 'runs' => [['text' => $options['caption'], 'bold' => true]]];
        }
        if ($columns > 0) {
            $blocks[] = [
                'type' => 'table',
                'cellPadding' => ['top' => 4, 'bottom' => 4, 'left' => 6, 'right' => 6],
                'borders' => ['left' => ['style' => 'none'], 'right' => ['style' => 'none'], 'insideV' => ['style' => 'none']],
                'rows' => $tableRows,
            ];
        }
        if (is_string($options['source'] ?? null) && $options['source'] !== '') {
            $blocks[] = ['type' => 'paragraph', 'runs' => [['text' => $options['source'], 'size' => 9, 'color' => '#6B7280']]];
        }

        $doc = ['blocks' => $blocks];
        if ($title !== null && $title !== '') {
            $doc = ['title' => $title] + $doc;
        }

        return $doc;
    }

    private static function isNumeric(mixed $v): bool
    {
        if (is_int($v) || is_float($v)) {
            return true;
        }

        return is_string($v) && preg_match('/^\s*[-+]?[$€£¥]?\(?\d[\d,]*(\.\d+)?\)?\s*%?\s*$/u', $v) === 1;
    }

    private static function format(mixed $v, int $decimals): string
    {
        if (is_int($v) || is_float($v)) {
            return number_format((float) $v, $decimals);
        }

        return self::text($v);
    }

    private static function text(mixed $v): string
    {
        return match (true) {
            is_string($v) => $v,
            is_bool($v) => $v ? 'Yes' : 'No',
            is_scalar($v) => (string) $v,
            default => '',
        };
    }
}
