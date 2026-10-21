<?php

declare(strict_types=1);

namespace FinePrint\Writer\Layout;

/**
 * A table: a grid of columns, rows of cells (each a {@see ContainerFlow}),
 * column and row spans, fills, padding and borders.
 *
 * Rows tied together by a row span move as one group. A table splits between
 * groups, and its leading header rows are repeated at the top of every page
 * it continues onto. A single row taller than a page is the one thing split
 * inside, cell by cell.
 *
 * Borders: a cell edge on the outside of this piece of the table takes the
 * table's top/right/bottom/left border, an inside edge takes insideH/insideV,
 * and a cell's own border overrides either.
 */
final class TableFlow extends Flowable
{
    /** @var list<float> */
    private array $heights = [];

    /** @var list<array{0: int, 1: int}> first and last row of each group */
    private array $groups = [];

    /**
     * @param  list<float>  $cols  column widths
     * @param  list<array{header: bool, cells: list<array<string, mixed>>}>  $rows
     * @param  array<string, ?array{style: string, width: float, color: array{0: float, 1: float, 2: float}}>  $borders
     * @param  int  $headerRows  leading header rows, repeated on continuation pages
     * @param  list<float>|null  $heights  precomputed row heights (pieces of a split table)
     */
    public function __construct(
        private readonly array $cols,
        private readonly array $rows,
        private readonly array $borders,
        private readonly float $offset,
        private readonly int $headerRows,
        ?array $heights = null,
    ) {
        $this->heights = $heights ?? $this->measure();
        $this->groups = $this->group();
    }

    public function height(): float
    {
        return array_sum($this->heights);
    }

    public function minHeight(): float
    {
        $body = $this->bodyGroups();
        if ($body === []) {
            return $this->height();
        }
        [$a, $b] = $body[0];

        return $this->headerHeight() + array_sum(array_slice($this->heights, $a, $b - $a + 1));
    }

    public function split(float $avail): ?array
    {
        $h = $this->headerHeight();
        $take = [];
        foreach ($this->bodyGroups() as [$a, $b]) {
            $gh = array_sum(array_slice($this->heights, $a, $b - $a + 1));
            if ($h + $gh > $avail + 0.001) {
                break;
            }
            $h += $gh;
            $take[] = [$a, $b];
        }
        $body = $this->bodyGroups();
        if ($take === [] || count($take) === count($body)) {
            return null;
        }
        $cut = end($take)[1] + 1;
        // range(0, -1) is [0, -1] in PHP, not []: build the header list explicitly.
        $header = $this->headerRows > 0 ? range(0, $this->headerRows - 1) : [];

        return $this->pieces(range(0, $cut - 1), [...$header, ...range($cut, count($this->rows) - 1)]);
    }

    /**
     * The first body row does not fit on an empty page: split it cell by
     * cell. Only a group of one row can be split this way.
     */
    public function forceSplit(float $avail): ?array
    {
        $plain = $this->split($avail);
        if ($plain !== null) {
            return $plain;
        }
        $body = $this->bodyGroups();
        if ($body === [] || $body[0][0] !== $body[0][1]) {
            return null;
        }
        $r = $body[0][0];
        $room = $avail - $this->headerHeight();
        $row = $this->rows[$r];
        $headCells = [];
        $tailCells = [];
        foreach ($row['cells'] as $cell) {
            $inner = $room - $cell['pad']['top'] - $cell['pad']['bottom'];
            $content = $cell['content'];
            if ($content->height() <= $inner + 0.001) {
                $headCells[] = $cell;
                $tailCells[] = ['content' => new ContainerFlow([])] + $cell;

                continue;
            }
            $parts = $content->forceSplit($inner);
            if ($parts === null) {
                return null;
            }
            $headCells[] = ['content' => $parts[0]] + $cell;
            $tailCells[] = ['content' => $parts[1]] + $cell;
        }
        $rows = $this->rows;
        $headRows = [...array_slice($rows, 0, $r), ['header' => $row['header'], 'cells' => $headCells]];
        $tailRows = [...array_slice($rows, 0, $this->headerRows), ['header' => $row['header'], 'cells' => $tailCells], ...array_slice($rows, $r + 1)];

        $head = new self($this->cols, $headRows, $this->borders, $this->offset, $this->headerRows);
        $tail = new self($this->cols, $tailRows, $this->borders, $this->offset, $this->headerRows);

        return [$this->inherit($head, true, false), $this->inherit($tail, false, true)];
    }

    public function draw(Canvas $c, float $x, float $top): void
    {
        $x += $this->offset;
        $colX = [0.0];
        foreach ($this->cols as $i => $w) {
            $colX[$i + 1] = $colX[$i] + $w;
        }
        $rowTop = [];
        $y = $top;
        foreach ($this->heights as $r => $h) {
            $rowTop[$r] = $y;
            $y += $h;
        }
        $last = count($this->rows) - 1;
        $boxes = [];
        foreach ($this->rows as $r => $row) {
            foreach ($row['cells'] as $cell) {
                $endRow = min($last, $r + $cell['rowSpan'] - 1);
                $endCol = min(count($this->cols), $cell['col'] + $cell['span']);
                $boxes[] = [
                    'cell' => $cell,
                    'x0' => $x + $colX[$cell['col']], 'x1' => $x + $colX[$endCol],
                    'y0' => $rowTop[$r], 'y1' => $rowTop[$endRow] + $this->heights[$endRow],
                    'top' => $r === 0, 'bottom' => $endRow === $last,
                    'left' => $cell['col'] === 0, 'right' => $endCol === count($this->cols),
                ];
            }
        }
        foreach ($boxes as $b) {
            if ($b['cell']['shading'] !== null) {
                $c->rect($b['x0'], $b['y0'], $b['x1'] - $b['x0'], $b['y1'] - $b['y0'], $b['cell']['shading']);
            }
        }
        foreach ($boxes as $b) {
            $cell = $b['cell'];
            $pad = $cell['pad'];
            $content = $cell['content'];
            $space = $b['y1'] - $b['y0'] - $pad['top'] - $pad['bottom'] - $content->height();
            $dy = match ($cell['valign']) {
                'center' => max(0.0, $space / 2),
                'bottom' => max(0.0, $space),
                default => 0.0,
            };
            $content->draw($c, $b['x0'] + $pad['left'], $b['y0'] + $pad['top'] + $dy);
        }
        foreach ($boxes as $b) {
            $edges = [
                'top' => [$b['x0'], $b['y0'], $b['x1'], $b['y0'], $b['top'] ? 'top' : 'insideH'],
                'bottom' => [$b['x0'], $b['y1'], $b['x1'], $b['y1'], $b['bottom'] ? 'bottom' : 'insideH'],
                'left' => [$b['x0'], $b['y0'], $b['x0'], $b['y1'], $b['left'] ? 'left' : 'insideV'],
                'right' => [$b['x1'], $b['y0'], $b['x1'], $b['y1'], $b['right'] ? 'right' : 'insideV'],
            ];
            foreach ($edges as $edge => [$x1, $y1, $x2, $y2, $tableEdge]) {
                $spec = array_key_exists($edge, $b['cell']['borders']) ? $b['cell']['borders'][$edge] : ($this->borders[$tableEdge] ?? null);
                if ($spec !== null) {
                    $c->line($x1, $y1, $x2, $y2, $spec['width'], $spec['color'], $spec['style']);
                }
            }
        }
    }

    /** @return list<float> */
    private function measure(): array
    {
        $n = count($this->rows);
        $heights = array_fill(0, $n, 0.0);
        foreach ($this->rows as $r => $row) {
            foreach ($row['cells'] as $cell) {
                if ($cell['rowSpan'] === 1) {
                    $heights[$r] = max($heights[$r], $this->need($cell));
                }
            }
        }
        foreach ($this->rows as $r => $row) {
            foreach ($row['cells'] as $cell) {
                if ($cell['rowSpan'] > 1) {
                    $end = min($n - 1, $r + $cell['rowSpan'] - 1);
                    $have = array_sum(array_slice($heights, $r, $end - $r + 1));
                    $want = $this->need($cell);
                    if ($want > $have) {
                        $heights[$end] += $want - $have;
                    }
                }
            }
        }

        return $heights;
    }

    /** @param array<string, mixed> $cell */
    private function need(array $cell): float
    {
        return $cell['pad']['top'] + $cell['content']->height() + $cell['pad']['bottom'];
    }

    /** @return list<array{0: int, 1: int}> */
    private function group(): array
    {
        $groups = [];
        $n = count($this->rows);
        $start = 0;
        $reach = 0;
        foreach ($this->rows as $r => $row) {
            foreach ($row['cells'] as $cell) {
                $reach = max($reach, min($n - 1, $r + $cell['rowSpan'] - 1));
            }
            if ($r >= $reach) {
                $groups[] = [$start, $r];
                $start = $r + 1;
                $reach = $r + 1;
            }
        }

        return $groups;
    }

    /** @return list<array{0: int, 1: int}> */
    private function bodyGroups(): array
    {
        return array_values(array_filter($this->groups, fn (array $g) => $g[0] >= $this->headerRows));
    }

    private function headerHeight(): float
    {
        return array_sum(array_slice($this->heights, 0, $this->headerRows));
    }

    /**
     * @param  list<int>  $headIdx
     * @param  list<int>  $tailIdx
     * @return array{0: Flowable, 1: Flowable}
     */
    private function pieces(array $headIdx, array $tailIdx): array
    {
        $pick = fn (array $idx) => array_map(fn (int $i) => $this->rows[$i], $idx);
        $hs = fn (array $idx) => array_map(fn (int $i) => $this->heights[$i], $idx);
        $head = new self($this->cols, $pick($headIdx), $this->borders, $this->offset, $this->headerRows, $hs($headIdx));
        $tail = new self($this->cols, $pick($tailIdx), $this->borders, $this->offset, $this->headerRows, $hs($tailIdx));

        return [$this->inherit($head, true, false), $this->inherit($tail, false, true)];
    }
}
