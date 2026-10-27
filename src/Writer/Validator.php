<?php

declare(strict_types=1);

namespace FinePrint\Writer;

use FinePrint\Exceptions\ImageException;
use FinePrint\Writer\Images\Image;

/**
 * Structural validation of the document model. Returns a flat `{path,
 * message}` list, empty when the document is valid, written to be handed
 * straight back to the agent that produced it.
 *
 * Mirrors last-word's validator: lenient about unknown extra keys (agents
 * decorate, and the writer never reads a key it does not know), strict about
 * the keys the writer consumes. A valid document always writes: every image
 * is opened here, so a bad one is an error with a path rather than a failure
 * halfway through a write.
 */
final class Validator
{
    private const BOX_EDGES = ['top', 'right', 'bottom', 'left'];

    private const TABLE_EDGES = ['top', 'right', 'bottom', 'left', 'insideH', 'insideV'];

    /** Nesting deeper than this (quotes in cells in quotes...) is refused. */
    public const MAX_DEPTH = 8;

    /** @var list<array{path: string, message: string}> */
    private array $errors = [];

    public function __construct(private readonly int $maxImagePixels = Image::MAX_PIXELS) {}

    /**
     * @param  array<string, mixed>  $doc
     * @return list<array{path: string, message: string}>
     */
    public function validate(array $doc): array
    {
        $this->errors = [];

        if (array_key_exists('title', $doc) && ! is_string($doc['title'])) {
            $this->error('title', 'title must be a string when present');
        }
        if (isset($doc['defaultFont']) && (! is_string($doc['defaultFont']) || $doc['defaultFont'] === '')) {
            $this->error('defaultFont', 'defaultFont must be a font family name');
        }
        $this->size($doc['defaultSize'] ?? null, 'defaultSize');
        $this->page($doc);

        if (! array_key_exists('blocks', $doc)) {
            $this->error('blocks', 'missing required key "blocks" (array of block objects)');

            return $this->errors;
        }
        if (! is_array($doc['blocks']) || ! array_is_list($doc['blocks'])) {
            $this->error('blocks', 'blocks must be a list of block objects');

            return $this->errors;
        }
        $this->blocks($doc['blocks'], 'blocks', 0);

        return $this->errors;
    }

    /** @param array<string, mixed> $doc */
    private function page(array $doc): void
    {
        if (! isset($doc['page'])) {
            return;
        }
        $page = $doc['page'];
        if (! is_array($page)) {
            $this->error('page', 'page must be an object ({size?, orientation?, margins?, header?, footer?, background?, border?})');

            return;
        }
        $this->enum($page['size'] ?? null, 'page.size', 'page size', array_keys(Schema::PAGE_SIZES));
        $this->enum($page['orientation'] ?? null, 'page.orientation', 'page orientation', ['portrait', 'landscape']);
        $before = count($this->errors);
        $this->sides($page['margins'] ?? null, 'page.margins', 'page margins', true);
        if (count($this->errors) === $before && isset($page['margins'])) {
            [$w, $h] = Schema::pageSize($doc);
            $m = Schema::margins($doc);
            if ($w - $m['left'] - $m['right'] < 72 || $h - $m['top'] - $m['bottom'] < 72) {
                $this->error('page.margins', 'margins must leave at least one inch (72pt) of text area across and down the page');
            }
        }
        foreach (['header', 'footer'] as $kind) {
            if (! isset($page[$kind])) {
                continue;
            }
            $band = $page[$kind];
            if (! is_array($band)) {
                $this->error("page.{$kind}", "page {$kind} must be an object ({left?, center?, right?, size?, color?})");

                continue;
            }
            foreach (['left', 'center', 'right'] as $slot) {
                if (isset($band[$slot]) && ! is_string($band[$slot])) {
                    $this->error("page.{$kind}.{$slot}", "page {$kind} {$slot} must be text");
                }
            }
            $this->size($band['size'] ?? null, "page.{$kind}.size");
            $this->hex($band['color'] ?? null, "page.{$kind}.color", "page {$kind} color");
        }
        $this->hex($page['background'] ?? null, 'page.background', 'page background');
        if (isset($page['border'])) {
            $border = $page['border'];
            if (! is_array($border)) {
                $this->error('page.border', 'page border must be an object ({style?, width?, color?, inset?})');

                return;
            }
            $before = count($this->errors);
            $this->enum($border['style'] ?? null, 'page.border.style', 'page border style', Schema::BORDER_STYLES);
            $this->number($border['width'] ?? null, 'page.border.width', 'page border width', positive: true);
            $this->hex($border['color'] ?? null, 'page.border.color', 'page border color');
            $this->number($border['inset'] ?? null, 'page.border.inset', 'page border inset', nonNegative: true);
            $frame = Schema::pageFrame($doc);
            if (count($this->errors) === $before && $frame !== null) {
                [$w, $h] = Schema::pageSize($doc);
                if (2 * ($frame['inset'] + $frame['width']) >= min($w, $h)) {
                    $this->error('page.border', 'page border inset + width must leave room inside the frame on both axes');
                }
            }
        }
    }

    /** @param list<mixed> $blocks */
    private function blocks(array $blocks, string $path, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            $this->error($path, 'blocks are nested more than '.self::MAX_DEPTH.' deep');

            return;
        }
        foreach ($blocks as $i => $block) {
            $this->block($block, "{$path}[{$i}]", $depth);
        }
    }

    private function block(mixed $block, string $path, int $depth): void
    {
        if (! is_array($block)) {
            $this->error($path, 'block must be an object, got '.get_debug_type($block));

            return;
        }
        $type = $block['type'] ?? null;
        if (! is_string($type)) {
            $this->error("{$path}.type", 'block is missing its "type" discriminator');

            return;
        }
        if ($type === 'chart') {
            $this->error("{$path}.type", 'chart blocks are not drawn in a PDF yet; put the figures in a table (validateAndRepair drops the chart)');

            return;
        }
        if (! in_array($type, Schema::BLOCK_TYPES, true)) {
            $this->error("{$path}.type", "unknown block type \"{$type}\" (expected one of: ".implode(', ', Schema::BLOCK_TYPES).')');

            return;
        }

        match ($type) {
            'heading' => $this->heading($block, $path),
            'paragraph' => $this->paragraph($block, $path),
            'list' => $this->listBlock($block, $path),
            'table' => $this->table($block, $path, $depth),
            'code' => $this->code($block, $path),
            'quote' => $this->quote($block, $path, $depth),
            'image' => $this->image($block, $path),
            'pageBreak', 'hr' => null,
        };
    }

    /** @param array<string, mixed> $block */
    private function heading(array $block, string $path): void
    {
        $level = $block['level'] ?? null;
        if (! is_int($level) && ! (is_float($level) && floor($level) === $level)) {
            $this->error("{$path}.level", 'heading requires an integer "level" (1-'.Schema::MAX_HEADING_LEVEL.')');
        } elseif ($level < 1 || $level > Schema::MAX_HEADING_LEVEL) {
            $this->error("{$path}.level", "heading level {$level} is out of range 1-".Schema::MAX_HEADING_LEVEL);
        }
        $this->runs($block['runs'] ?? null, "{$path}.runs");
        $this->paragraphProps($block, $path);
    }

    /** @param array<string, mixed> $block */
    private function paragraph(array $block, string $path): void
    {
        $this->runs($block['runs'] ?? null, "{$path}.runs");
        $this->paragraphProps($block, $path);
    }

    /** @param array<string, mixed> $block */
    private function paragraphProps(array $block, string $path): void
    {
        $this->enum($block['align'] ?? null, "{$path}.align", 'align', Schema::ALIGNMENTS);
        foreach (['spaceBefore', 'spaceAfter', 'indentLeft', 'indentRight'] as $key) {
            $this->number($block[$key] ?? null, "{$path}.{$key}", $key, nonNegative: true);
        }
        $this->number($block['lineHeight'] ?? null, "{$path}.lineHeight", 'lineHeight (a multiple of single spacing)', positive: true);
        if (isset($block['keepNext']) && ! is_bool($block['keepNext'])) {
            $this->error("{$path}.keepNext", 'keepNext must be a boolean');
        }
        $this->hex($block['shading'] ?? null, "{$path}.shading", 'shading');
        $this->borders($block['borders'] ?? null, "{$path}.borders", 'borders', self::BOX_EDGES);
    }

    /** @param array<string, mixed> $block */
    private function listBlock(array $block, string $path): void
    {
        if (isset($block['ordered']) && ! is_bool($block['ordered'])) {
            $this->error("{$path}.ordered", 'ordered must be a boolean');
        }
        $items = $block['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            $this->error("{$path}.items", 'list requires an "items" array');

            return;
        }
        $this->items($items, "{$path}.items", 0);
    }

    /** @param list<mixed> $items */
    private function items(array $items, string $path, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            $this->error($path, 'list items are nested more than '.self::MAX_DEPTH.' deep');

            return;
        }
        foreach ($items as $i => $item) {
            $itemPath = "{$path}[{$i}]";
            if (! is_array($item)) {
                $this->error($itemPath, 'list item must be an object with "runs"');

                continue;
            }
            $this->runs($item['runs'] ?? null, "{$itemPath}.runs");
            $this->paragraphProps($item, $itemPath);
            if (isset($item['children'])) {
                if (! is_array($item['children']) || ! array_is_list($item['children'])) {
                    $this->error("{$itemPath}.children", 'children must be a list of list items');
                } else {
                    $this->items($item['children'], "{$itemPath}.children", $depth + 1);
                }
            }
        }
    }

    /** @param array<string, mixed> $block */
    private function table(array $block, string $path, int $depth): void
    {
        $this->enum($block['align'] ?? null, "{$path}.align", 'table align', ['left', 'center', 'right']);
        $this->borders($block['borders'] ?? null, "{$path}.borders", 'table borders', self::TABLE_EDGES);
        $this->sides($block['cellPadding'] ?? null, "{$path}.cellPadding", 'table cellPadding');
        $width = $block['width'] ?? null;
        if ($width !== null && (! (is_int($width) || is_float($width)) || $width <= 0 || $width > 100)) {
            $this->error("{$path}.width", 'table width must be a percentage of the text column, above 0 and at most 100');
        }
        $widths = $block['widths'] ?? null;
        if ($widths !== null) {
            if (! is_array($widths) || ! array_is_list($widths) || $widths === []) {
                $this->error("{$path}.widths", 'table widths must be a non-empty array of relative column weights');
            } else {
                foreach ($widths as $i => $w) {
                    if (! (is_int($w) || is_float($w)) || $w < 0) {
                        $this->error("{$path}.widths[{$i}]", 'a column weight must be a non-negative number');
                    }
                }
                if (array_sum(array_filter($widths, fn ($w) => is_int($w) || is_float($w))) <= 0) {
                    $this->error("{$path}.widths", 'table widths must not all be zero');
                }
            }
        }

        $rows = $block['rows'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->error("{$path}.rows", 'table requires a "rows" array');

            return;
        }
        foreach ($rows as $r => $row) {
            $rowPath = "{$path}.rows[{$r}]";
            if (! is_array($row) || ! is_array($row['cells'] ?? null) || ! array_is_list($row['cells'])) {
                $this->error("{$rowPath}.cells", 'table row requires a "cells" array');

                continue;
            }
            if (isset($row['header']) && ! is_bool($row['header'])) {
                $this->error("{$rowPath}.header", 'header must be a boolean');
            }
            foreach ($row['cells'] as $c => $cell) {
                $cellPath = "{$rowPath}.cells[{$c}]";
                if (! is_array($cell) || ! is_array($cell['blocks'] ?? null) || ! array_is_list($cell['blocks'])) {
                    $this->error("{$cellPath}.blocks", 'table cell requires a "blocks" array');

                    continue;
                }
                $this->hex($cell['shading'] ?? null, "{$cellPath}.shading", 'cell shading');
                $this->borders($cell['borders'] ?? null, "{$cellPath}.borders", 'cell borders', self::BOX_EDGES);
                $this->sides($cell['padding'] ?? null, "{$cellPath}.padding", 'cell padding');
                $this->enum($cell['valign'] ?? null, "{$cellPath}.valign", 'cell valign', ['top', 'center', 'bottom']);
                foreach (['colSpan', 'rowSpan'] as $span) {
                    $value = $cell[$span] ?? null;
                    if ($value !== null && (! is_int($value) || $value < 1 || $value > 1000)) {
                        $this->error("{$cellPath}.{$span}", "{$span} must be an integer of 1 or more");
                    }
                }
                $this->blocks($cell['blocks'], "{$cellPath}.blocks", $depth + 1);
            }
        }
    }

    /** @param array<string, mixed> $block */
    private function code(array $block, string $path): void
    {
        if (! is_string($block['text'] ?? null)) {
            $this->error("{$path}.text", 'code block requires a string "text"');
        }
        if (isset($block['language']) && ! is_string($block['language'])) {
            $this->error("{$path}.language", 'code language must be a string when present');
        }
    }

    /** @param array<string, mixed> $block */
    private function quote(array $block, string $path, int $depth): void
    {
        $blocks = $block['blocks'] ?? null;
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            $this->error("{$path}.blocks", 'quote requires a "blocks" array');

            return;
        }
        $this->blocks($blocks, "{$path}.blocks", $depth + 1);
    }

    /** @param array<string, mixed> $block */
    private function image(array $block, string $path): void
    {
        $src = $block['src'] ?? null;
        if (! is_string($src)) {
            $this->error("{$path}.src", 'image requires a string "src" data URL');
        } else {
            try {
                Image::fromDataUrl($src, $this->maxImagePixels);
            } catch (ImageException $e) {
                $this->error("{$path}.src", $e->getMessage());
            }
        }
        foreach (['widthPx', 'heightPx'] as $dim) {
            $v = $block[$dim] ?? null;
            if ($v !== null && (! (is_int($v) || is_float($v)) || $v <= 0)) {
                $this->error("{$path}.{$dim}", "{$dim} must be a positive number when present");
            }
        }
        if (isset($block['alt']) && ! is_string($block['alt'])) {
            $this->error("{$path}.alt", 'alt must be a string when present');
        }
    }

    private function runs(mixed $runs, string $path): void
    {
        if (! is_array($runs) || ! array_is_list($runs)) {
            $this->error($path, 'missing "runs" (array of {text, ...} run objects)');

            return;
        }
        foreach ($runs as $i => $run) {
            $runPath = "{$path}[{$i}]";
            if (! is_array($run)) {
                $this->error($runPath, 'run must be an object with "text", got '.get_debug_type($run));

                continue;
            }
            if (! is_string($run['text'] ?? null)) {
                $this->error("{$runPath}.text", 'run requires a string "text"');
            }
            foreach (Schema::RUN_FLAGS as $flag) {
                if (isset($run[$flag]) && ! is_bool($run[$flag])) {
                    $this->error("{$runPath}.{$flag}", "run flag \"{$flag}\" must be a boolean");
                }
            }
            if (isset($run['link']) && ! Schema::linkAllowed($run['link'])) {
                $this->error("{$runPath}.link", 'run link must be an http://, https:// or mailto: URL of printable ASCII; no other scheme can be written into a PDF');
            }
            $this->hex($run['color'] ?? null, "{$runPath}.color", 'run color');
            $this->hex($run['highlight'] ?? null, "{$runPath}.highlight", 'run highlight');
            if (isset($run['font']) && (! is_string($run['font']) || $run['font'] === '')) {
                $this->error("{$runPath}.font", 'run font must be a font family name');
            }
            $this->size($run['size'] ?? null, "{$runPath}.size");
            $this->number($run['letterSpacing'] ?? null, "{$runPath}.letterSpacing", 'run letterSpacing');
        }
    }

    // ------------------------------------------------------------------

    private function size(mixed $value, string $path): void
    {
        if ($value === null) {
            return;
        }
        if (! is_int($value) && ! is_float($value)) {
            $this->error($path, 'size must be a number of points');

            return;
        }
        if ($value < Schema::MIN_TEXT_SIZE || $value > Schema::MAX_TEXT_SIZE) {
            $this->error($path, 'size must be between '.Schema::MIN_TEXT_SIZE.' and '.Schema::MAX_TEXT_SIZE.' points; smaller text is invisible, and a PDF reader (this package\'s included) flags it as hidden');
        }
    }

    private function number(mixed $value, string $path, string $label, bool $positive = false, bool $nonNegative = false): void
    {
        if ($value === null) {
            return;
        }
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
            $this->error($path, "{$label} must be a number of points");

            return;
        }
        if ($positive && $value <= 0) {
            $this->error($path, "{$label} must be greater than zero");
        } elseif ($nonNegative && $value < 0) {
            $this->error($path, "{$label} must not be negative");
        } elseif (abs((float) $value) > 10000) {
            $this->error($path, "{$label} is out of range");
        }
    }

    private function hex(mixed $value, string $path, string $label): void
    {
        if ($value !== null && (! is_string($value) || preg_match('/^#[0-9A-Fa-f]{6}$/', $value) !== 1)) {
            $this->error($path, "{$label} must be a #RRGGBB hex string");
        }
    }

    /** @param list<string> $allowed */
    private function enum(mixed $value, string $path, string $label, array $allowed): void
    {
        if ($value !== null && ! in_array($value, $allowed, true)) {
            $this->error($path, "{$label} must be one of: ".implode(', ', $allowed));
        }
    }

    private function sides(mixed $value, string $path, string $label, bool $nonNegative = true): void
    {
        if ($value === null) {
            return;
        }
        if (! is_array($value)) {
            $this->error($path, "{$label} must be an object of sides in points");

            return;
        }
        foreach (self::BOX_EDGES as $side) {
            $this->number($value[$side] ?? null, "{$path}.{$side}", $side, nonNegative: $nonNegative);
        }
    }

    /** @param list<string> $edges */
    private function borders(mixed $value, string $path, string $label, array $edges): void
    {
        if ($value === null) {
            return;
        }
        if (! is_array($value)) {
            $this->error($path, "{$label} must be an object keyed by edge");

            return;
        }
        foreach ($edges as $edge) {
            $border = $value[$edge] ?? null;
            if ($border === null) {
                continue;
            }
            $edgePath = "{$path}.{$edge}";
            if (! is_array($border)) {
                $this->error($edgePath, 'a border must be an object ({style?, width?, color?})');

                continue;
            }
            $this->enum($border['style'] ?? null, "{$edgePath}.style", 'border style', Schema::BORDER_STYLES);
            $this->number($border['width'] ?? null, "{$edgePath}.width", 'border width', positive: true);
            $this->hex($border['color'] ?? null, "{$edgePath}.color", 'border color');
        }
    }

    private function error(string $path, string $message): void
    {
        $this->errors[] = ['path' => $path, 'message' => $message];
    }
}
