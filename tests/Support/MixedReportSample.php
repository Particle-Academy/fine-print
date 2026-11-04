<?php

declare(strict_types=1);

namespace FinePrint\Tests\Support;

/**
 * The acceptance document for mixed page orientation: a dense engineering report,
 * written as the JSON an agent would compose. All of its data is invented.
 *
 *   - portrait 43.2pt left and right, landscape 28.8pt, top and bottom 36pt on both
 *   - the same header and footer on both, at each page's own width
 *   - a cover with no running header, and the footer row without its centre text
 *   - a footer row: a 1in logo, a centred line, "Page N"
 *   - a header: one italic 8pt grey line with a thin rule under it
 *   - a 17-column table: merged cells, tinted rows, repeating headers
 *   - portrait pages of PNG charts, each heading kept with its chart and caption
 *   - a result card as a one-row table, which moves whole with no keep word
 *   - text with delta, >= and an arrow, through a host font
 *   - footnotes, in a paragraph and in a table cell on a landscape page
 */
final class MixedReportSample
{
    public const FONT = 'DejaVu Sans';

    /** @return array{regular: string, bold: string, italic: string, boldItalic: string} */
    public static function fontFaces(): array
    {
        $dir = 'C:/Windows/Fonts/';

        return ['regular' => $dir.'DejaVuSans.ttf', 'bold' => $dir.'DejaVuSans-Bold.ttf', 'italic' => $dir.'DejaVuSans-Oblique.ttf', 'boldItalic' => $dir.'DejaVuSans-BoldOblique.ttf'];
    }

    /** A horizontal blue logo, 192 x 48 px. */
    public static function logo(): string
    {
        return ImageBuilder::dataUrl(ImageBuilder::png(192, 48, 2, 8, function (int $x, int $y): array {
            $bar = $x % 48 < 8;

            return $bar ? [255, 255, 255] : [24, 72, 140];
        }), 'image/png');
    }

    /** A bar chart drawn as pixels: real image data, not a flat fill. */
    public static function chart(int $seed, int $w = 720, int $h = 420): string
    {
        return ImageBuilder::dataUrl(ImageBuilder::png($w, $h, 2, 8, function (int $x, int $y) use ($seed, $w, $h): array {
            $bars = 12;
            $bar = (int) floor($x * $bars / $w);
            $value = 0.25 + 0.7 * abs(sin(($bar + 1) * ($seed + 1) * 0.73));
            $inBar = $x % (int) ceil($w / $bars) > 6 && $y > $h * (1 - $value);
            if ($inBar) {
                return $bar % 2 === 0 ? [31, 119, 180] : [255, 127, 14];
            }

            return $y % 60 === 0 ? [220, 220, 220] : [255, 255, 255];
        }), 'image/png');
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private static function para(string $text, array $props = []): array
    {
        return ['type' => 'paragraph', 'runs' => [['text' => $text]]] + $props;
    }

    /** @return array<string, array<string, string>> */
    private static function noBorders(): array
    {
        return array_fill_keys(['top', 'right', 'bottom', 'left', 'insideH', 'insideV'], ['style' => 'none']);
    }

    /**
     * The footer row, a borderless table: logo, centred line, "Page N".
     *
     * @return array<string, mixed>
     */
    public static function footerRow(bool $centreText): array
    {
        $small = ['size' => 8, 'color' => '#4B5563'];

        return ['type' => 'table', 'borders' => self::noBorders(), 'widths' => [1, 3, 1], 'cellPadding' => ['top' => 0, 'bottom' => 0, 'left' => 0, 'right' => 0], 'rows' => [['cells' => [
            ['valign' => 'center', 'blocks' => [['type' => 'image', 'src' => self::logo(), 'widthPx' => 96, 'alt' => 'Company logo']]],
            ['valign' => 'center', 'blocks' => $centreText ? [['type' => 'paragraph', 'align' => 'center', 'spaceAfter' => 0, 'runs' => [['text' => 'Field Performance Report', ...$small]]]] : []],
            ['valign' => 'center', 'blocks' => [['type' => 'paragraph', 'align' => 'right', 'spaceAfter' => 0, 'runs' => [['text' => 'Page {page}', ...$small]]]]],
        ]]]];
    }

    /**
     * The header: an italic 8pt grey line, with a thin rule under it. A table
     * cell's bottom border draws the rule, because it can be coloured and spans
     * the column exactly with nothing added above or below.
     *
     * @return array<string, mixed>
     */
    public static function header(): array
    {
        return ['blocks' => [[
            'type' => 'table', 'borders' => self::noBorders(), 'cellPadding' => ['top' => 0, 'left' => 0, 'right' => 0, 'bottom' => 2],
            'rows' => [['cells' => [[
                'borders' => ['bottom' => ['width' => 0.5, 'color' => '#9CA3AF']],
                'blocks' => [['type' => 'paragraph', 'spaceAfter' => 0, 'runs' => [['text' => 'Well 7-Alpha · 2026-10-31', 'italic' => true, 'size' => 8, 'color' => '#6B7280']]]],
            ]]]],
        ]]];
    }

    /** @return array<string, mixed> */
    public static function doc(): array
    {
        $margins = ['top' => 36, 'bottom' => 36, 'header' => 12, 'footer' => 10];
        $footnote = fn (string $text) => ['text' => '', 'footnote' => ['blocks' => [self::para($text)]]];
        $delta = fn (string $text) => ['text' => $text, 'font' => self::FONT];

        $blocks = [
            ...self::cover(),
            ['type' => 'pageBreak'],
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Summary']]],
            ['type' => 'paragraph', 'runs' => [
                ['text' => 'The change raised average output over the 90-day window'],
                $footnote('Rates are allocated from the test meter, corrected to standard conditions.'),
                ['text' => '. The operating envelope held throughout, with pressure inside its limit on every day but two.'],
            ]],
            ['type' => 'paragraph', 'runs' => [
                $delta('Δ output ≥ 12% → the target was met; Δ waste ≥ 2% → review the schedule.'),
            ]],
            self::card('Result: output uplift', '+148 units', 'Sustained for 90 days, against a 120 unit target.', '#E8F3EA'),
            self::card('Result: efficiency', '0.82 ratio', 'Up from 0.71 before the change.', '#EAF0FB'),
            self::para(str_repeat('The baseline was taken across fourteen days of stable running, and the system was allowed to settle after each change before a new reading was recorded. ', 6)),
            ...self::keptChartBlocks('Figure 1: Daily output', 1),
            self::card('Result: peak pressure', '1,040 psi', 'Peak in the window; limit 1,100 psi.', '#FBF3E4'),
            ...self::keptChartBlocks('Figure 2: Input against output', 2),
            ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape', 'margins' => ['left' => 28.8, 'right' => 28.8]]],
            ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Daily operating data']]],
            self::wideTable(70, $footnote),
            ['type' => 'pageBreak', 'page' => ['orientation' => 'portrait', 'margins' => ['left' => 43.2, 'right' => 43.2]]],
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Trend charts']]],
            ...self::keptChartBlocks('Figure 3: Line pressure', 3),
            ...self::keptChartBlocks('Figure 4: Return pressure', 4),
            ...self::keptChartBlocks('Figure 5: Input rate', 5),
            ...self::keptChartBlocks('Figure 6: Waste share', 6),
            ...self::keptChartBlocks('Figure 7: Total rate', 7),
            ['type' => 'paragraph', 'runs' => [['text' => 'End of the portrait section.'], $footnote('Charts are plotted at daily resolution.')]],
            ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape', 'margins' => ['left' => 28.8, 'right' => 28.8]]],
            ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Appendix: test records']]],
            self::wideTable(24, $footnote),
        ];

        return [
            'title' => 'Field Performance Report',
            'meta' => ['author' => 'Example Analytics'],
            'defaultFont' => 'Helvetica',
            'page' => [
                'size' => 'letter',
                'orientation' => 'portrait',
                'margins' => $margins + ['left' => 43.2, 'right' => 43.2],
                'header' => self::header(),
                'firstHeader' => ['blocks' => []],
                'footer' => ['blocks' => [self::footerRow(true)]],
                'firstFooter' => ['blocks' => [self::footerRow(false)]],
            ],
            'blocks' => $blocks,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function cover(): array
    {
        $row = fn (string $k, string $v) => ['cells' => [['shading' => '#F3F4F6', 'blocks' => [self::para($k, ['spaceAfter' => 0])]], ['blocks' => [self::para($v, ['spaceAfter' => 0])]]]];

        return [
            ['type' => 'paragraph', 'spaceBefore' => 120, 'spaceAfter' => 4, 'runs' => [['text' => 'Field Performance Report', 'size' => 26, 'bold' => true, 'color' => '#183F7A']]],
            self::para('Well 7-Alpha, Q3 2026', ['spaceAfter' => 24]),
            ['type' => 'table', 'widths' => [1, 2], 'rows' => [$row('Client', 'Example Partners'), $row('Well', 'Well 7-Alpha'), $row('Window', '2026-08-01 to 2026-10-31'), $row('Prepared by', 'Example Analytics')]],
        ];
    }

    /** @return array<string, mixed> */
    private static function card(string $label, string $value, string $note, string $tint): array
    {
        return ['type' => 'table', 'widths' => [2, 1], 'cellPadding' => ['top' => 6, 'bottom' => 6, 'left' => 8, 'right' => 8], 'rows' => [['cells' => [
            ['shading' => $tint, 'blocks' => [
                ['type' => 'paragraph', 'spaceAfter' => 2, 'runs' => [['text' => $label, 'bold' => true]]],
                ['type' => 'paragraph', 'spaceAfter' => 0, 'runs' => [['text' => $note, 'size' => 9]]],
            ]],
            ['shading' => $tint, 'valign' => 'center', 'blocks' => [['type' => 'paragraph', 'align' => 'right', 'spaceAfter' => 0, 'runs' => [['text' => $value, 'size' => 16, 'bold' => true]]]]],
        ]]]];
    }

    /** @return list<array<string, mixed>> a heading kept with its chart, and the chart with its caption */
    private static function keptChartBlocks(string $title, int $seed): array
    {
        return [
            ['type' => 'heading', 'level' => 3, 'runs' => [['text' => $title]]],
            ['type' => 'image', 'src' => self::chart($seed), 'widthPx' => 660, 'keepNext' => true, 'alt' => $title],
            ['type' => 'paragraph', 'align' => 'center', 'runs' => [['text' => "{$title}, daily values over the window.", 'italic' => true, 'size' => 9, 'color' => '#4B5563']]],
        ];
    }

    /**
     * Seventeen columns, two header rows with merged cells, tinted rows, and a
     * row-spanned stage label.
     *
     * @param  \Closure(string): array<string, mixed>  $footnote
     * @return array<string, mixed>
     */
    private static function wideTable(int $rows, \Closure $footnote): array
    {
        $small = fn (string $t, array $more = []) => ['type' => 'paragraph', 'align' => 'center', 'spaceAfter' => 0, 'runs' => [['text' => $t, 'size' => 7] + $more]];
        $head = fn (string $t, int $colSpan = 1, int $rowSpan = 1) => ['shading' => '#183F7A', 'colSpan' => $colSpan, 'rowSpan' => $rowSpan, 'valign' => 'center', 'blocks' => [$small($t, ['color' => '#FFFFFF', 'bold' => true])]];

        $out = [
            ['header' => true, 'cells' => [
                $head('Date', 1, 2), $head('Stage', 1, 2),
                $head('Production', 5), $head('Pressure (psi)', 4), $head('Input', 3), $head('Temp (F)', 3),
            ]],
            ['header' => true, 'cells' => array_map(fn (string $t) => $head($t), ['Out', 'Waste', 'Side', 'Total', 'W %', 'P1', 'P2', 'P3', 'P4', 'Rate', 'Ratio', 'Eff', 'Unit', 'Sep', 'Amb'])],
        ];
        for ($r = 1; $r <= $rows; $r++) {
            $tint = $r % 2 === 0 ? ['shading' => '#EEF2F7'] : [];
            $cells = [['blocks' => [$small(sprintf('2026-%02d-%02d', 8 + intdiv($r, 31), 1 + $r % 28))]] + $tint];
            if ($r % 6 === 1) {
                $cells[] = ['rowSpan' => 3, 'shading' => '#DDE6F2', 'valign' => 'center', 'blocks' => [$small('S'.(1 + intdiv($r, 6)), ['bold' => true])]];
            }
            for ($c = 0; $c < 15; $c++) {
                $cells[] = ['blocks' => [$small((string) round(100 + 7.31 * $r + 13.7 * $c, 1))]] + $tint;
            }
            if ($r === 3) {
                $cells[count($cells) - 1]['blocks'] = [['type' => 'paragraph', 'align' => 'center', 'spaceAfter' => 0, 'runs' => [['text' => '142.9', 'size' => 7], $footnote('Ambient reading taken from the nearest station, not the site.')]]];
            }
            if ($r % 6 !== 1 && $r % 6 !== 2 && $r % 6 !== 3) {
                array_splice($cells, 1, 0, [['blocks' => [$small('-')]] + $tint]);
            }
            $out[] = ['cells' => $cells];
        }
        $widths = array_fill(0, 17, 4.0);
        $widths[0] = 6.0;

        return ['type' => 'table', 'widths' => $widths, 'cellPadding' => ['top' => 2, 'bottom' => 2, 'left' => 1.5, 'right' => 1.5], 'borders' => [
            'top' => ['width' => 0.5, 'color' => '#9CA3AF'], 'bottom' => ['width' => 0.5, 'color' => '#9CA3AF'], 'left' => ['width' => 0.5, 'color' => '#9CA3AF'],
            'right' => ['width' => 0.5, 'color' => '#9CA3AF'], 'insideH' => ['width' => 0.25, 'color' => '#D1D5DB'], 'insideV' => ['width' => 0.25, 'color' => '#D1D5DB'],
        ], 'rows' => $out];
    }
}
