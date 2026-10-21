<?php

declare(strict_types=1);

namespace FinePrint\Writer\Layout;

/**
 * Breaks styled text into lines: first fit, at spaces, with forced breaks at
 * `\n`. A word wider than the line is broken between characters (and
 * reported). Spaces at a line's ends are dropped; the spaces between words
 * are glue, which justification may widen.
 *
 * Leading is `size * 1.2 * lineHeight`, from the largest text on the line, so
 * 11pt text at the default 1.08 sits 14.256pt apart, about 45 lines on a
 * letter page inside one-inch margins (what the agent guide estimates with).
 */
final class LineBreaker
{
    public const LEADING = 1.2;

    public function __construct(private readonly Diagnostics $diagnostics) {}

    /**
     * @param  list<array{text: string, style: TextStyle}>  $pieces
     * @return list<Line>
     */
    public function lines(array $pieces, float $width, float $lineHeight, TextStyle $base, string $path): array
    {
        $tokens = $this->tokens($pieces);
        $lines = [];
        $cur = [];
        $curW = 0.0;
        $glue = [];
        $width = max(1.0, $width);

        foreach ($tokens as $t) {
            if ($t['kind'] === 'break') {
                $lines[] = $this->line($cur, $curW, $lineHeight, $base, true);
                $cur = [];
                $curW = 0.0;
                $glue = [];

                continue;
            }
            if ($t['kind'] === 'space') {
                if ($cur !== []) {
                    $glue[] = $t['item'];
                }

                continue;
            }
            $w = $t['width'];
            $gw = array_sum(array_column($glue, 'width'));
            if ($cur !== [] && $curW + $gw + $w <= $width + 0.001) {
                array_push($cur, ...$glue, ...$t['items']);
                $curW += $gw + $w;
                $glue = [];

                continue;
            }
            if ($cur !== []) {
                $lines[] = $this->line($cur, $curW, $lineHeight, $base, false);
            }
            $glue = [];
            if ($w <= $width + 0.001) {
                $cur = $t['items'];
                $curW = $w;

                continue;
            }
            // A word wider than the line: break it between characters.
            $this->diagnostics->add('word-broken', 'warning', $path, 'a word is wider than its column and was broken between characters; widen the column or shorten the word');
            $chunks = $this->chop($t['items'], $width);
            $last = array_pop($chunks);
            foreach ($chunks as $chunk) {
                $lines[] = $this->line($chunk['items'], $chunk['width'], $lineHeight, $base, false);
            }
            $cur = $last['items'];
            $curW = $last['width'];
        }
        $lines[] = $this->line($cur, $curW, $lineHeight, $base, true);

        return $lines;
    }

    /**
     * Code: every source line kept as it is, spaces and all, wrapped between
     * characters only when it is wider than the column.
     *
     * @return list<Line>
     */
    public function hardLines(string $text, TextStyle $style, float $width, float $lineHeight, string $path): array
    {
        $lines = [];
        foreach (explode("\n", $text) as $source) {
            $w = $style->measure($source);
            if ($w <= $width + 0.001 || $source === '') {
                $items = $source === '' ? [] : [['text' => $source, 'style' => $style, 'width' => $w, 'glue' => false]];
                $lines[] = $this->line($items, $w, $lineHeight, $style, true);

                continue;
            }
            $this->diagnostics->add('code-wrapped', 'info', $path, 'a code line is wider than the column and was wrapped');
            foreach ($this->chop([['text' => $source, 'style' => $style, 'width' => $w, 'glue' => false]], $width) as $chunk) {
                $lines[] = $this->line($chunk['items'], $chunk['width'], $lineHeight, $style, true);
            }
        }

        return $lines;
    }

    /**
     * @param  list<array{text: string, style: TextStyle}>  $pieces
     * @return list<array{kind: string, item?: array<string, mixed>, items?: list<array<string, mixed>>, width?: float}>
     */
    private function tokens(array $pieces): array
    {
        $tokens = [];
        $word = [];
        $wordW = 0.0;
        $flush = function () use (&$tokens, &$word, &$wordW): void {
            if ($word !== []) {
                $tokens[] = ['kind' => 'word', 'items' => $word, 'width' => $wordW];
                $word = [];
                $wordW = 0.0;
            }
        };
        foreach ($pieces as $piece) {
            $parts = preg_split('/( |\n)/u', $piece['text'], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($parts as $part) {
                if ($part === "\n") {
                    $flush();
                    $tokens[] = ['kind' => 'break'];
                } elseif ($part === ' ') {
                    $flush();
                    $tokens[] = ['kind' => 'space', 'item' => ['text' => ' ', 'style' => $piece['style'], 'width' => $piece['style']->measure(' '), 'glue' => true]];
                } else {
                    $w = $piece['style']->measure($part);
                    $word[] = ['text' => $part, 'style' => $piece['style'], 'width' => $w, 'glue' => false];
                    $wordW += $w;
                }
            }
        }
        $flush();

        return $tokens;
    }

    /**
     * Split items into chunks no wider than `$width`, between characters.
     *
     * @param  list<array{text: string, style: TextStyle, width: float, glue: bool}>  $items
     * @return list<array{items: list<array<string, mixed>>, width: float}>
     */
    private function chop(array $items, float $width): array
    {
        $chunks = [];
        $cur = [];
        $curW = 0.0;
        foreach ($items as $item) {
            $buf = '';
            $bufW = 0.0;
            foreach (mb_str_split($item['text'], 1, 'UTF-8') as $ch) {
                $cw = $item['style']->measure($ch);
                if ($curW + $bufW + $cw > $width + 0.001 && ($cur !== [] || $buf !== '')) {
                    if ($buf !== '') {
                        $cur[] = ['text' => $buf, 'style' => $item['style'], 'width' => $bufW, 'glue' => false];
                    }
                    $chunks[] = ['items' => $cur, 'width' => $curW + $bufW];
                    $cur = [];
                    $curW = 0.0;
                    $buf = '';
                    $bufW = 0.0;
                }
                $buf .= $ch;
                $bufW += $cw;
            }
            if ($buf !== '') {
                $cur[] = ['text' => $buf, 'style' => $item['style'], 'width' => $bufW, 'glue' => false];
                $curW += $bufW;
            }
        }
        $chunks[] = ['items' => $cur, 'width' => $curW];

        return $chunks;
    }

    /** @param list<array<string, mixed>> $items */
    private function line(array $items, float $width, float $lineHeight, TextStyle $base, bool $last): Line
    {
        $size = $base->size;
        $asc = $base->ascent();
        $desc = $base->descent();
        if ($items !== []) {
            $size = 0.0;
            $asc = 0.0;
            $desc = 0.0;
            foreach ($items as $i) {
                $size = max($size, $i['style']->size);
                $asc = max($asc, $i['style']->ascent());
                $desc = max($desc, $i['style']->descent());
            }
        }
        $height = $size * self::LEADING * $lineHeight;
        $baseline = ($height - ($asc + $desc)) / 2 + $asc;

        return new Line($items, $width, $height, $baseline, $last);
    }
}
