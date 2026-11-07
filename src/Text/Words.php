<?php

declare(strict_types=1);

namespace FinePrint\Text;

use FinePrint\Budget;

/**
 * Word boxes from the extractor's word pieces and the composed page text.
 *
 * A word is a maximal stretch of the composed text with no whitespace in it.
 * Pieces of one run are split on whitespace by the extractor; pieces of
 * separate runs join when the text layout put them side by side with nothing
 * between (it inserts a space or a line break wherever its gap rule says the
 * runs are apart). So the join rule IS the layout's gap rule, and every word
 * is a substring of exactly the text the agent-read guard scans: the
 * property that makes redaction impossible to route around through words.
 *
 * Output follows the composed text, which is reading order.
 */
final class Words
{
    /**
     * @param  array<int, string>  $pieces  run index => packed {@see TextExtractor::PIECE} records
     * @param  array<int, int>  $offsets  run index => byte offset in $text, from {@see Layout::compose()}
     * @return list<array{text: string, x0: float, x1: float, top: float, bottom: float, start: int, end: int}>
     */
    public static function assemble(array $pieces, array $offsets, string $text, Budget $budget): array
    {
        asort($offsets);
        $words = [];
        $cur = null;
        foreach ($offsets as $run => $base) {
            $packed = $pieces[$run] ?? '';
            $n = intdiv(strlen($packed), TextExtractor::PIECE_BYTES);
            for ($i = 0; $i < $n; $i++) {
                // Each word costs memory the extractor's text did not: tick, so
                // the memory and time budgets see it grow.
                $budget->tick();
                $p = unpack('Vs/Ve/dx0/dx1/dt/db', $packed, $i * TextExtractor::PIECE_BYTES);
                $start = $base + $p['s'];
                $end = $base + $p['e'];
                if ($end <= $start) {
                    // Glyphs that decode to nothing: no text, so no word.
                    continue;
                }
                if ($cur !== null && $cur['end'] === $start) {
                    $cur['x0'] = min($cur['x0'], $p['x0']);
                    $cur['x1'] = max($cur['x1'], $p['x1']);
                    $cur['top'] = min($cur['top'], $p['t']);
                    $cur['bottom'] = max($cur['bottom'], $p['b']);
                    $cur['end'] = $end;

                    continue;
                }
                if ($cur !== null) {
                    $words[] = self::finish($cur, $text);
                }
                $cur = ['x0' => $p['x0'], 'x1' => $p['x1'], 'top' => $p['t'], 'bottom' => $p['b'], 'start' => $start, 'end' => $end];
            }
        }
        if ($cur !== null) {
            $words[] = self::finish($cur, $text);
        }
        $budget->check();

        return $words;
    }

    /**
     * @param  array{x0: float, x1: float, top: float, bottom: float, start: int, end: int}  $w
     * @return array{text: string, x0: float, x1: float, top: float, bottom: float, start: int, end: int}
     */
    private static function finish(array $w, string $text): array
    {
        return ['text' => substr($text, $w['start'], $w['end'] - $w['start'])] + $w;
    }
}
