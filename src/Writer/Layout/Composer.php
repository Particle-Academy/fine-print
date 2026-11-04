<?php

declare(strict_types=1);

namespace FinePrint\Writer\Layout;

use FinePrint\Writer\Fonts\FontBook;
use FinePrint\Writer\Images\ImageStore;
use FinePrint\Writer\Pdf\Syntax;
use FinePrint\Writer\Schema;

/**
 * Turns the document model into flowables, with last-word's defaults so the
 * same JSON looks the same in both: 11pt body with 8pt after each paragraph,
 * headings 18/16/14/13/12/11pt bold with 12pt before and 6pt after, lists
 * indented 36pt a level with an 18pt hanging marker, header rows filled
 * #E7E7E7 and bold, cell padding 3/5.4pt, code in a grey box, quotes indented
 * 36pt in grey italics, links #0563C1 and underlined.
 *
 * It also cleans text: zero-width, bidi-control, Unicode tag and control
 * characters are removed (and reported), because a reader's guard, this
 * package's included, rightly flags them as smuggling. The writer will not be
 * the way hidden text gets into a PDF.
 */
final class Composer
{
    private const HEADING_SIZES = [1 => 18, 2 => 16, 3 => 14, 4 => 13, 5 => 12, 6 => 11];

    public const LINE_HEIGHT = 1.08;

    private const LIST_INDENT = 36.0;

    private const HANGING = 18.0;

    private const QUOTE_INDENT = 36.0;

    private const PADDING = ['top' => 3.0, 'right' => 5.4, 'bottom' => 3.0, 'left' => 5.4];

    private const HEADER_FILL = '#E7E7E7';

    private const CODE_FILL = '#F2F2F2';

    private const LINK_COLOR = '#0563C1';

    private const QUOTE_COLOR = '#595959';

    private const INVISIBLE = '/[\x{00AD}\x{061C}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}\x{E0000}-\x{E007F}]/u';

    private const CONTROLS = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{80}-\x{9F}]/u';

    /** Size of a footnote's text and of the number beside it, as last-word-ui has them. */
    private const NOTE_SIZE = 10.0;

    private const NOTE_NUMBER_SCALE = 0.65;

    /**
     * How far a mark rises above its line, as a share of the text it follows.
     * Held at 0.3 on purpose: a reader groups text into a line within half the
     * smaller size (`Text\Layout`, 0.325 of the text here). Any higher and the mark
     * reads back as a line of its own, before the sentence it belongs to.
     */
    private const NOTE_MARK_RISE = 0.3;

    private LineBreaker $breaker;

    private float $bodyHeight = 0.0;

    /** 'body', 'band' or 'note': only a run in the body makes a footnote. */
    private string $mode = 'body';

    /** The width a footnote sets at: the page's column, wherever its mark is. */
    private float $noteWidth = 0.0;

    /** @var array<int, FootnoteFlow> every footnote so far, numbered in reading order */
    private array $notes = [];

    /** @var array<string, string>|null a band's token values while it composes */
    private ?array $fields = null;

    /** @param array<string, mixed> $doc */
    public function __construct(
        private readonly FontBook $fonts,
        private readonly ImageStore $images,
        private readonly Diagnostics $diagnostics,
        private readonly array $doc,
    ) {
        $this->breaker = new LineBreaker($diagnostics);
    }

    /**
     * @param  array<int, mixed>  $blocks  keys are the blocks' indexes in the document, which a finding's path names
     * @return list<Flowable>
     */
    public function compose(array $blocks, float $width, float $bodyHeight): array
    {
        $this->bodyHeight = $bodyHeight;
        $this->noteWidth = $width;

        return $this->flow($blocks, $width, $this->baseContext(), 'blocks');
    }

    /** @return array<int, FootnoteFlow> every footnote composed so far, by number */
    public function footnotes(): array
    {
        return $this->notes;
    }

    /**
     * The lines of one slot of a running header or footer: more than one only
     * when the text itself holds a line break.
     *
     * @param  array<string, mixed>  $band
     * @param  array<string, string>  $fields
     * @return list<Line>
     */
    public function bandLines(string $text, array $band, array $fields, string $path): array
    {
        $ctx = $this->baseContext();
        $ctx['size'] = (float) ($band['size'] ?? 9);
        $ctx['color'] = is_string($band['color'] ?? null) ? $band['color'] : '#6B7280';
        $style = $this->style([], $ctx, $path);
        $text = $this->clean($this->expandFields($text, $fields, $path), $path);

        return $this->breaker->lines([['text' => $text, 'style' => $style]], 1.0e6, 1.0, $style, $path);
    }

    /**
     * A blocks band, composed at the page's column with its tokens resolved.
     *
     * @param  list<mixed>  $blocks
     * @param  array<string, string>  $fields
     */
    public function bandBox(array $blocks, float $width, float $pageHeight, array $fields, string $path): BandBox
    {
        [$mode, $held, $this->mode, $this->fields] = [$this->mode, $this->fields, 'band', $fields];
        $body = $this->bodyHeight;
        $this->bodyHeight = $pageHeight;
        try {
            return new BandBox($this->flow($blocks, $width, $this->baseContext(), $path));
        } finally {
            [$this->mode, $this->fields, $this->bodyHeight] = [$mode, $held, $body];
        }
    }

    /**
     * Text with its tokens replaced, in one pass, so a value is never read for
     * tokens of its own. A token a PDF cannot honour stays as written, and is
     * reported: the writer stamps no time and does not know the file's name.
     *
     * @param  array<string, string>  $fields
     */
    private function expandFields(string $text, array $fields, string $path): string
    {
        return (string) preg_replace_callback(Schema::FIELD_TOKEN, function (array $m) use ($fields, $path): string {
            $name = ($m[1] ?? '') !== '' ? $m[1] : '';
            if ($name !== '' && array_key_exists($name, $fields)) {
                return $fields[$name];
            }
            $this->diagnostics->add('band-field-literal', 'warning', $path, 'a PDF has no live date or time and this writer does not know the file name: {date}, {time} and {filename} are written as literal text, so put the value in the text itself');

            return $m[0];
        }, $text);
    }

    /** @return array{family: ?string, size: float, bold: bool, italic: bool, color: ?string} */
    private function baseContext(): array
    {
        return [
            'family' => is_string($this->doc['defaultFont'] ?? null) ? $this->doc['defaultFont'] : null,
            'size' => (float) ($this->doc['defaultSize'] ?? 11),
            'bold' => false,
            'italic' => false,
            'color' => null,
        ];
    }

    /**
     * @param  list<mixed>  $blocks
     * @param  array<string, mixed>  $ctx
     * @return list<Flowable>
     */
    private function flow(array $blocks, float $width, array $ctx, string $path): array
    {
        $out = [];
        foreach ($blocks as $i => $block) {
            if (! is_array($block)) {
                continue;
            }
            array_push($out, ...$this->block($block, $width, $ctx, "{$path}[{$i}]"));
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $ctx
     * @return list<Flowable>
     */
    private function block(array $block, float $width, array $ctx, string $path): array
    {
        return match ($block['type'] ?? null) {
            'heading' => [$this->heading($block, $width, $ctx, $path)],
            'paragraph' => [$this->paragraph($block, $width, $ctx, $path)],
            'list' => $this->listBlock($block, $width, $ctx, $path),
            'table' => $this->table($block, $width, $ctx, $path),
            'code' => [$this->code($block, $width, $path)],
            'quote' => $this->quote($block, $width, $ctx, $path),
            'image' => [$this->image($block, $width, $ctx, $path)],
            'hr' => [$this->rule($block, $width, $path)],
            'pageBreak' => [new PageBreakFlow()],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $ctx
     */
    private function heading(array $block, float $width, array $ctx, string $path): Flowable
    {
        $level = max(1, min(6, (int) ($block['level'] ?? 1)));
        $ctx['size'] = (float) self::HEADING_SIZES[$level];
        $ctx['bold'] = true;

        return $this->paragraph($block, $width, $ctx, $path, ['spaceBefore' => 12.0, 'spaceAfter' => 6.0, 'keepNext' => true]);
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $ctx
     * @param  array{spaceBefore?: float, spaceAfter?: float, keepNext?: bool}  $defaults
     */
    private function paragraph(array $block, float $width, array $ctx, string $path, array $defaults = []): Flowable
    {
        $left = self::points($block['indentLeft'] ?? null, 0.0);
        $right = self::points($block['indentRight'] ?? null, 0.0);
        $lineHeight = self::points($block['lineHeight'] ?? null, self::LINE_HEIGHT);
        $base = $this->style([], $ctx, $path);
        $lines = $this->breaker->lines($this->pieces($block['runs'] ?? [], $ctx, "{$path}.runs"), $width - $left - $right, $lineHeight, $base, $path);

        $flow = new ParagraphFlow(
            $lines, $width, self::align($block['align'] ?? null), $left, $right,
            self::color($block['shading'] ?? null), self::boxBorders($block['borders'] ?? null),
        );

        return $this->place($flow, $block, $path, $defaults + ['spaceBefore' => 0.0, 'spaceAfter' => $ctx['after'] ?? 8.0, 'keepNext' => false]);
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $ctx
     * @return list<Flowable>
     */
    private function listBlock(array $block, float $width, array $ctx, string $path): array
    {
        $out = [];
        $explicitAfter = [];
        $ordered = ($block['ordered'] ?? false) === true;
        $walk = function (array $items, int $level, string $itemsPath) use (&$walk, &$out, &$explicitAfter, $ordered, $width, $ctx): void {
            foreach ($items as $i => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $itemPath = "{$itemsPath}[{$i}]";
                $indent = self::LIST_INDENT * ($level + 1) + self::points($item['indentLeft'] ?? null, 0.0);
                $right = self::points($item['indentRight'] ?? null, 0.0);
                $lineHeight = self::points($item['lineHeight'] ?? null, self::LINE_HEIGHT);
                $base = $this->style([], $ctx, $itemPath);
                $lines = $this->breaker->lines($this->pieces($item['runs'] ?? [], $ctx, "{$itemPath}.runs"), $width - $indent - $right, $lineHeight, $base, $itemPath);
                $markerText = $ordered ? self::number($i + 1, $level).'.' : ['•', '–', '•'][$level % 3];
                $marker = $this->breaker->lines([['text' => $markerText, 'style' => $base]], 1.0e6, $lineHeight, $base, $itemPath)[0];

                $flow = new ParagraphFlow(
                    $lines, $width, self::align($item['align'] ?? null), $indent, $right,
                    self::color($item['shading'] ?? null), self::boxBorders($item['borders'] ?? null),
                    ['line' => $marker, 'x' => $indent - self::HANGING],
                );
                $out[] = $this->place($flow, $item, $itemPath, ['spaceBefore' => 0.0, 'spaceAfter' => 0.0, 'keepNext' => false]);
                $explicitAfter[] = isset($item['spaceAfter']);
                if (is_array($item['children'] ?? null)) {
                    $walk($item['children'], $level + 1, "{$itemPath}.children");
                }
            }
        };
        $walk(is_array($block['items'] ?? null) ? $block['items'] : [], 0, "{$path}.items");

        // The list as a whole has paragraph spacing after it.
        $last = count($out) - 1;
        if ($last >= 0 && ! $explicitAfter[$last]) {
            $out[$last]->spaceAfter = $ctx['after'] ?? 8.0;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $ctx
     * @return list<Flowable>
     */
    private function table(array $block, float $width, array $ctx, string $path): array
    {
        $rows = is_array($block['rows'] ?? null) ? array_values(array_filter($block['rows'], 'is_array')) : [];

        // Place cells on the grid, HTML-style: a row span occupies the slots
        // below it, and later rows' cells flow around them.
        $occupied = [];
        $placed = [];
        $columns = 0;
        $n = count($rows);
        foreach ($rows as $r => $row) {
            $col = 0;
            $placed[$r] = [];
            foreach (is_array($row['cells'] ?? null) ? $row['cells'] : [] as $c => $cell) {
                if (! is_array($cell)) {
                    continue;
                }
                while (isset($occupied[$r][$col])) {
                    $col++;
                }
                $span = max(1, min(1000, (int) ($cell['colSpan'] ?? 1)));
                $rowSpan = max(1, min($n - $r, (int) ($cell['rowSpan'] ?? 1)));
                for ($rr = $r; $rr < $r + $rowSpan; $rr++) {
                    for ($cc = $col; $cc < $col + $span; $cc++) {
                        $occupied[$rr][$cc] = true;
                    }
                }
                $placed[$r][] = ['cell' => $cell, 'col' => $col, 'span' => $span, 'rowSpan' => $rowSpan, 'path' => "{$path}.rows[{$r}].cells[{$c}]"];
                $col += $span;
                $columns = max($columns, $col);
            }
        }
        $weights = array_values(array_filter(is_array($block['widths'] ?? null) ? $block['widths'] : [], fn ($w) => is_int($w) || is_float($w)));
        $columns = max($columns, count($weights));
        if ($columns === 0) {
            return [];
        }
        $avg = $weights !== [] && array_sum($weights) > 0 ? array_sum($weights) / count($weights) : 1.0;
        for ($i = count($weights); $i < $columns; $i++) {
            $weights[] = $avg;
        }
        if (array_sum($weights) <= 0) {
            $weights = array_fill(0, $columns, 1.0);
        }
        $tableWidth = $width * min(100.0, max(1.0, self::points($block['width'] ?? null, 100.0))) / 100;
        $total = array_sum($weights);
        $cols = array_map(fn ($w) => $tableWidth * $w / $total, $weights);
        $offset = match ($block['align'] ?? null) {
            'center' => ($width - $tableWidth) / 2,
            'right' => $width - $tableWidth,
            default => 0.0,
        };

        $defaultBorder = ['style' => 'single', 'width' => 0.5, 'color' => [0.0, 0.0, 0.0]];
        $given = is_array($block['borders'] ?? null) ? $block['borders'] : [];
        $borders = [];
        foreach (['top', 'right', 'bottom', 'left', 'insideH', 'insideV'] as $edge) {
            $borders[$edge] = self::border($given[$edge] ?? null, $defaultBorder);
        }
        $tablePad = self::sides($block['cellPadding'] ?? null, self::PADDING);

        $headerRows = 0;
        foreach ($rows as $row) {
            if (($row['header'] ?? false) !== true) {
                break;
            }
            $headerRows++;
        }

        $outRows = [];
        $ragged = false;
        foreach ($rows as $r => $row) {
            $header = ($row['header'] ?? false) === true;
            $cells = [];
            $covered = count($occupied[$r] ?? []);
            if ($covered < $columns) {
                $ragged = true;
            }
            foreach ($placed[$r] as $p) {
                $cell = $p['cell'];
                $pad = self::sides($cell['padding'] ?? null, $tablePad);
                $cellWidth = array_sum(array_slice($cols, $p['col'], $p['span']));
                $inner = max(1.0, $cellWidth - $pad['left'] - $pad['right']);
                $cellCtx = $ctx;
                if ($header) {
                    $cellCtx['bold'] = true;
                }
                $content = new ContainerFlow($this->flow(is_array($cell['blocks'] ?? null) ? $cell['blocks'] : [], $inner, $cellCtx, "{$p['path']}.blocks"));
                $cellBorders = [];
                foreach (['top', 'right', 'bottom', 'left'] as $edge) {
                    if (is_array($cell['borders'] ?? null) && is_array($cell['borders'][$edge] ?? null)) {
                        $cellBorders[$edge] = self::border($cell['borders'][$edge], $borders[$edge === 'top' || $edge === 'bottom' ? 'insideH' : 'insideV'] ?? $defaultBorder);
                    }
                }
                $cells[] = [
                    'col' => $p['col'],
                    'span' => $p['span'],
                    'rowSpan' => $p['rowSpan'],
                    'content' => $content,
                    'pad' => $pad,
                    'shading' => self::color($cell['shading'] ?? ($header ? self::HEADER_FILL : null)),
                    'borders' => $cellBorders,
                    'valign' => in_array($cell['valign'] ?? null, ['center', 'bottom'], true) ? $cell['valign'] : 'top',
                ];
            }
            $outRows[] = ['header' => $header, 'cells' => $cells];
        }
        if ($ragged) {
            $this->diagnostics->add('ragged-table', 'info', $path, 'some rows have fewer cells than the table has columns; the missing cells are left blank');
        }

        $flow = new TableFlow($cols, $outRows, $borders, $offset, $headerRows);

        return [$this->place($flow, self::keepOnly($block), $path, ['spaceBefore' => 0.0, 'spaceAfter' => $ctx['after'] ?? 8.0, 'keepNext' => false])];
    }

    /**
     * Of a table, image, code block or rule, only `keepNext` is read: the
     * spacing keys are not part of those blocks' vocabulary.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function keepOnly(array $block): array
    {
        return array_intersect_key($block, ['keepNext' => true]);
    }

    /** @param array<string, mixed> $block */
    private function code(array $block, float $width, string $path): Flowable
    {
        $font = $this->fonts->resolve('Courier', false, false)['font'];
        $style = new TextStyle($font, 10.0);
        $text = $this->clean(is_string($block['text'] ?? null) ? $block['text'] : '', "{$path}.text");
        $this->checkEncodable($text, $style, "{$path}.text");
        $lines = $this->breaker->hardLines($text, $style, $width - 8, 1.0, $path);
        $flow = new ParagraphFlow($lines, $width, 'left', 4.0, 4.0, Syntax::rgb(self::CODE_FILL), [], null, 4.0);
        if (is_string($block['language'] ?? null) && $block['language'] !== '') {
            $this->diagnostics->add('code-language', 'info', $path, 'a code language draws nothing in a PDF yet: the code is set plain, in Courier');
        }

        return $this->place($flow, self::keepOnly($block), $path, ['spaceBefore' => 0.0, 'spaceAfter' => 8.0, 'keepNext' => false]);
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $ctx
     * @return list<Flowable>
     */
    private function quote(array $block, float $width, array $ctx, string $path): array
    {
        $ctx['italic'] = true;
        $ctx['color'] = self::QUOTE_COLOR;
        $children = $this->flow(is_array($block['blocks'] ?? null) ? $block['blocks'] : [], $width - self::QUOTE_INDENT, $ctx, "{$path}.blocks");
        foreach ($children as $child) {
            $child->indent += self::QUOTE_INDENT;
        }

        return $children;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $ctx
     */
    private function image(array $block, float $width, array $ctx, string $path): Flowable
    {
        if (is_string($block['alt'] ?? null) && $block['alt'] !== '') {
            $this->diagnostics->add('image-alt', 'info', $path, 'a PDF from this writer has no tagged structure to carry alt text yet: the image is drawn and its alt text is not stored');
        }
        $entry = $this->images->add((string) $block['src']);
        $img = $entry['image'];
        $wpx = $block['widthPx'] ?? null;
        $hpx = $block['heightPx'] ?? null;
        $ratio = $img->height / $img->width;
        if (is_numeric($wpx) && is_numeric($hpx)) {
            [$w, $h] = [(float) $wpx, (float) $hpx];
        } elseif (is_numeric($wpx)) {
            [$w, $h] = [(float) $wpx, (float) $wpx * $ratio];
        } elseif (is_numeric($hpx)) {
            [$w, $h] = [(float) $hpx / $ratio, (float) $hpx];
        } else {
            [$w, $h] = [(float) $img->width, (float) $img->height];
        }
        // px at 96 dpi to points.
        $w *= 0.75;
        $h *= 0.75;
        if ($w > $width) {
            $h *= $width / $w;
            $w = $width;
            $this->diagnostics->add('image-scaled', 'info', $path, 'an image wider than the text column was scaled down to fit it');
        }
        if ($h > $this->bodyHeight) {
            $w *= $this->bodyHeight / $h;
            $h = $this->bodyHeight;
            $this->diagnostics->add('image-scaled', 'info', $path, 'an image taller than the page was scaled down to fit it');
        }

        return $this->place(new ImageFlow($entry['name'], $w, $h), self::keepOnly($block), $path, ['spaceBefore' => 0.0, 'spaceAfter' => $ctx['after'] ?? 8.0, 'keepNext' => false]);
    }

    /** @param array<string, mixed> $block */
    private function rule(array $block, float $width, string $path): Flowable
    {
        return $this->place(new RuleFlow($width), self::keepOnly($block), $path, ['spaceBefore' => 6.0, 'spaceAfter' => 10.0, 'keepNext' => false]);
    }

    /**
     * A footnote mark: its number, at 0.65 of the text around it and raised off
     * the line. It belongs to the word before it (no space is added) and being
     * smaller it never grows its line, so a marked paragraph has the height of an
     * unmarked one. Numbered here, in reading order, which is the order this
     * class meets runs: paragraphs, headings, list items and quotes in turn, and
     * a table's rows then cells, the same walk as last-word-ui's `footnotes.ts`.
     *
     * @param  array<string, mixed>  $note
     * @param  array<string, mixed>  $ctx
     * @return array{text: string, style: TextStyle}
     */
    private function footnoteMark(array $note, array $ctx, string $path): array
    {
        $id = count($this->notes) + 1;
        $held = $this->mode;
        $this->mode = 'note';
        // Reserve the number first: a note's own runs cannot hold another note.
        try {
            $noteCtx = ['size' => self::NOTE_SIZE, 'after' => 0.0] + $this->baseContext();
            $base = $this->style([], $noteCtx, $path);
            $numberStyle = new TextStyle($base->font, self::NOTE_SIZE * self::NOTE_NUMBER_SCALE, $base->color);
            $number = $this->breaker->lines([['text' => (string) $id, 'style' => $numberStyle]], 1.0e6, 1.0 / LineBreaker::LEADING, $numberStyle, $path)[0];
            $width = max(1.0, $this->noteWidth - $number->width - 3.0);
            $flow = new FootnoteFlow($id, $number, new ContainerFlow($this->flow($note['blocks'] ?? [], $width, $noteCtx, "{$path}.footnote.blocks")));
            $flow->path = "{$path}.footnote";
            $this->notes[$id] = $flow;
        } finally {
            $this->mode = $held;
        }
        $resolved = $this->fonts->resolve($ctx['family'], $ctx['bold'], $ctx['italic']);
        $size = (float) $ctx['size'];

        return ['text' => (string) $id, 'style' => new TextStyle(
            $resolved['font'],
            max(Schema::MIN_TEXT_SIZE, $size * self::NOTE_NUMBER_SCALE),
            Syntax::rgb(is_string($ctx['color'] ?? null) ? $ctx['color'] : '#000000'),
            rise: $size * self::NOTE_MARK_RISE,
            note: $id,
        )];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array{spaceBefore: float, spaceAfter: float, keepNext: bool}  $defaults
     */
    private function place(Flowable $flow, array $block, string $path, array $defaults): Flowable
    {
        $flow->spaceBefore = self::points($block['spaceBefore'] ?? null, $defaults['spaceBefore']);
        $flow->spaceAfter = self::points($block['spaceAfter'] ?? null, $defaults['spaceAfter']);
        $flow->keepNext = is_bool($block['keepNext'] ?? null) ? $block['keepNext'] : $defaults['keepNext'];
        $flow->path = $path;

        return $flow;
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return list<array{text: string, style: TextStyle}>
     */
    private function pieces(mixed $runs, array $ctx, string $path): array
    {
        $pieces = [];
        foreach (is_array($runs) ? $runs : [] as $i => $run) {
            if (! is_array($run)) {
                continue;
            }
            if (is_array($run['footnote'] ?? null)) {
                // Validation refused one anywhere but the body.
                if ($this->mode === 'body') {
                    $pieces[] = $this->footnoteMark($run['footnote'], $ctx, "{$path}[{$i}]");
                }

                continue;
            }
            if (! is_string($run['text'] ?? null)) {
                continue;
            }
            $source = $this->mode === 'band' && $this->fields !== null ? $this->expandFields($run['text'], $this->fields, "{$path}[{$i}]") : $run['text'];
            $text = $this->clean($source, "{$path}[{$i}]");
            if ($text === '') {
                continue;
            }
            $style = $this->style($run, $ctx, "{$path}[{$i}]");
            $this->checkEncodable($text, $style, "{$path}[{$i}]");
            $pieces[] = ['text' => $text, 'style' => $style];
        }

        return $pieces;
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>  $ctx
     */
    private function style(array $run, array $ctx, string $path): TextStyle
    {
        $code = ($run['code'] ?? false) === true;
        $family = $code ? 'Courier' : (is_string($run['font'] ?? null) ? $run['font'] : $ctx['family']);
        $bold = is_bool($run['bold'] ?? null) ? $run['bold'] : $ctx['bold'];
        $italic = is_bool($run['italic'] ?? null) ? $run['italic'] : $ctx['italic'];
        $size = is_int($run['size'] ?? null) || is_float($run['size'] ?? null)
            ? (float) $run['size']
            : ($code ? max(1.0, $ctx['size'] - 1) : $ctx['size']);
        $size = max(Schema::MIN_TEXT_SIZE, min(Schema::MAX_TEXT_SIZE, $size));
        // Defence in depth: validation already refused any other link.
        $link = Schema::linkAllowed($run['link'] ?? null) ? $run['link'] : null;
        $color = is_string($run['color'] ?? null) ? $run['color'] : ($link !== null ? self::LINK_COLOR : ($ctx['color'] ?? '#000000'));
        $underline = is_bool($run['underline'] ?? null) ? $run['underline'] : $link !== null;
        $highlight = is_string($run['highlight'] ?? null) ? $run['highlight'] : ($code ? self::CODE_FILL : null);

        $resolved = $this->fonts->resolve($family, $bold, $italic);
        if ($resolved['note'] !== null) {
            $this->diagnostics->add('font-substituted', 'info', $path, $resolved['note']);
        }
        if (($run['smallCaps'] ?? false) === true) {
            $this->diagnostics->add('small-caps', 'info', $path, 'smallCaps is not drawn in a PDF yet; the text is set as written');
        }

        return new TextStyle(
            $resolved['font'],
            $size,
            Syntax::rgb($color),
            $underline,
            ($run['strike'] ?? false) === true,
            $highlight !== null ? Syntax::rgb($highlight) : null,
            $link,
            is_int($run['letterSpacing'] ?? null) || is_float($run['letterSpacing'] ?? null) ? (float) $run['letterSpacing'] : 0.0,
        );
    }

    /** Remove what must never be drawn, and normalise breaks and tabs. */
    private function clean(string $text, string $path): string
    {
        $text = Syntax::validUtf8($text);
        $text = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $text);
        $text = str_replace("\t", '    ', $text);
        $stripped = (string) preg_replace([self::INVISIBLE, self::CONTROLS], '', $text);
        if ($stripped !== $text) {
            $this->diagnostics->add('invisible-chars', 'warning', $path, 'zero-width, bidi-control, Unicode tag or control characters were removed: a reader\'s guard flags them as hidden text');
        }

        return $stripped;
    }

    private function checkEncodable(string $text, TextStyle $style, string $path): void
    {
        foreach (mb_str_split($text, 1, 'UTF-8') as $ch) {
            if ($ch === "\n" || $ch === ' ' || $style->font->canEncode($ch)) {
                continue;
            }
            $this->diagnostics->add('unencodable', 'warning', $path, sprintf(
                'U+%04X (and possibly more) cannot be drawn in the standard fonts and is written as "?"; register a TrueType font that has it with the host\'s fonts option',
                mb_ord($ch, 'UTF-8'),
            ));

            return;
        }
    }

    private static function points(mixed $v, float $default): float
    {
        return is_int($v) || is_float($v) ? (float) $v : $default;
    }

    private static function align(mixed $v): string
    {
        return in_array($v, Schema::ALIGNMENTS, true) ? $v : 'left';
    }

    /** @return array{0: float, 1: float, 2: float}|null */
    private static function color(mixed $hex): ?array
    {
        return is_string($hex) && preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) === 1 ? Syntax::rgb($hex) : null;
    }

    /**
     * @param  array{style: string, width: float, color: array{0: float, 1: float, 2: float}}  $default
     * @return array{style: string, width: float, color: array{0: float, 1: float, 2: float}}|null
     */
    private static function border(mixed $spec, array $default): ?array
    {
        if (! is_array($spec)) {
            return $default;
        }
        $style = in_array($spec['style'] ?? null, Schema::BORDER_STYLES, true) ? $spec['style'] : 'single';
        if ($style === 'none') {
            return null;
        }

        return [
            'style' => $style,
            'width' => is_int($spec['width'] ?? null) || is_float($spec['width'] ?? null) ? max(0.1, (float) $spec['width']) : 0.5,
            'color' => self::color($spec['color'] ?? null) ?? [0.0, 0.0, 0.0],
        ];
    }

    /** @return array<string, array{style: string, width: float, color: array{0: float, 1: float, 2: float}}> */
    private static function boxBorders(mixed $spec): array
    {
        $out = [];
        if (! is_array($spec)) {
            return $out;
        }
        foreach (['top', 'right', 'bottom', 'left'] as $edge) {
            if (is_array($spec[$edge] ?? null)) {
                $b = self::border($spec[$edge], ['style' => 'single', 'width' => 0.5, 'color' => [0.0, 0.0, 0.0]]);
                if ($b !== null) {
                    $out[$edge] = $b;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, float>  $default
     * @return array{top: float, right: float, bottom: float, left: float}
     */
    private static function sides(mixed $spec, array $default): array
    {
        $out = $default;
        if (is_array($spec)) {
            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                if (is_int($spec[$side] ?? null) || is_float($spec[$side] ?? null)) {
                    $out[$side] = max(0.0, (float) $spec[$side]);
                }
            }
        }

        return $out;
    }

    /** List numbering: 1, a, i by level. */
    private static function number(int $n, int $level): string
    {
        return match ($level % 3) {
            0 => (string) $n,
            1 => self::alpha($n),
            default => self::roman($n),
        };
    }

    private static function alpha(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(ord('a') + $n % 26).$s;
            $n = intdiv($n, 26);
        }

        return $s;
    }

    private static function roman(int $n): string
    {
        $map = ['m' => 1000, 'cm' => 900, 'd' => 500, 'cd' => 400, 'c' => 100, 'xc' => 90, 'l' => 50, 'xl' => 40, 'x' => 10, 'ix' => 9, 'v' => 5, 'iv' => 4, 'i' => 1];
        $s = '';
        foreach ($map as $r => $v) {
            while ($n >= $v) {
                $s .= $r;
                $n -= $v;
            }
        }

        return $s;
    }
}
