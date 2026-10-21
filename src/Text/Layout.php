<?php

declare(strict_types=1);

namespace FinePrint\Text;

/**
 * Puts text runs into READING order: lines top to bottom by baseline, pieces
 * of a line left to right, a space where the horizontal gap says one was
 * meant, a blank line where the vertical gap says a paragraph ended.
 *
 * Deliberately simple. Multi-column layouts read row by row across columns;
 * that is a known limit, stated in the README, not a bug to discover.
 *
 * Every flagged run (hidden, off-page) is reported as a span over the
 * composed text, so the agent-read guard can say exactly WHERE it is.
 */
final class Layout
{
    /** A word gap, as a fraction of the font size. */
    private const WORD_GAP = 0.15;

    /** A paragraph gap, as a multiple of the font size. */
    private const PARAGRAPH_GAP = 1.8;

    /**
     * @param  list<array{text: string, x0: float, y: float, x1: float, size: float, flags: list<string>}>  $runs
     * @return array{text: string, spans: list<array{start: int, length: int, flags: list<string>}>}
     */
    public static function compose(array $runs): array
    {
        $runs = array_values(array_filter($runs, fn (array $r) => $r['text'] !== ''));
        if ($runs === []) {
            return ['text' => '', 'spans' => []];
        }

        // Stable sort: baseline descending (PDF y grows upward), then x.
        $keyed = [];
        foreach ($runs as $i => $r) {
            $keyed[] = [$r, $i];
        }
        usort($keyed, function (array $a, array $b): int {
            [$ra, $ia] = $a;
            [$rb, $ib] = $b;

            return [-$ra['y'], $ra['x0'], $ia] <=> [-$rb['y'], $rb['x0'], $ib];
        });

        /** @var list<array{y: float, size: float, runs: list<array<string, mixed>>}> $lines */
        $lines = [];
        foreach ($keyed as [$r]) {
            $last = count($lines) - 1;
            if ($last >= 0) {
                $tol = max(1.0, 0.5 * min($r['size'], $lines[$last]['size']));
                if (abs($lines[$last]['y'] - $r['y']) <= $tol) {
                    $lines[$last]['runs'][] = $r;
                    $lines[$last]['size'] = max($lines[$last]['size'], $r['size']);

                    continue;
                }
            }
            $lines[] = ['y' => $r['y'], 'size' => $r['size'], 'runs' => [$r]];
        }

        $text = '';
        $spans = [];
        $prevLine = null;
        foreach ($lines as $line) {
            if ($prevLine !== null) {
                $gap = $prevLine['y'] - $line['y'];
                $text .= $gap > self::PARAGRAPH_GAP * max($prevLine['size'], $line['size'], 1.0) ? "\n\n" : "\n";
            }
            usort($line['runs'], fn (array $a, array $b) => $a['x0'] <=> $b['x0']);
            $prev = null;
            foreach ($line['runs'] as $r) {
                if ($prev !== null) {
                    // Fake bold: the same text drawn twice, a hair apart.
                    if ($r['text'] === $prev['text'] && abs($r['x0'] - $prev['x0']) < 1.0) {
                        continue;
                    }
                    $gap = $r['x0'] - $prev['x1'];
                    if ($gap > self::WORD_GAP * max($r['size'], $prev['size'])
                        && ! str_ends_with($prev['text'], ' ')
                        && ! str_starts_with($r['text'], ' ')) {
                        $text .= ' ';
                    }
                }
                if ($r['flags'] !== []) {
                    $spans[] = ['start' => strlen($text), 'length' => strlen($r['text']), 'flags' => $r['flags']];
                }
                $text .= $r['text'];
                $prev = $r;
            }
            $prevLine = $line;
        }

        return ['text' => $text, 'spans' => $spans];
    }
}
