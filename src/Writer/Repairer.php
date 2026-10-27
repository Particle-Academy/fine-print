<?php

declare(strict_types=1);

namespace FinePrint\Writer;

use FinePrint\Exceptions\ImageException;
use FinePrint\Writer\Images\Image;

/**
 * Heuristic repair of near-miss agent output, applied by
 * {@see \FinePrint\Agent::validateAndRepair()} when strict validation fails.
 *
 * last-word's repairs (bare strings wrapped, `"text"` shorthand turned into
 * runs, heading levels clamped, unknown blocks dropped), plus the PDF ones:
 *
 *   - a link with a scheme a PDF from this writer cannot carry is REMOVED and
 *     its text kept. It is never rewritten into something that looks safe.
 *   - text under 1pt is raised to 1pt (smaller reads as hidden text)
 *   - a chart block is dropped, with a note saying to tabulate it
 *   - an image that cannot be embedded is dropped
 *   - margins that leave no text area are reset to one inch
 *
 * Then any optional property still invalid is removed. Every change is
 * reported, so the agent learns what it got wrong.
 */
final class Repairer
{
    /** Optional keys that may be removed when invalid. */
    private const OPTIONAL = [
        'align', 'spaceBefore', 'spaceAfter', 'lineHeight', 'indentLeft', 'indentRight', 'keepNext', 'shading',
        'borders', 'ordered', 'width', 'widths', 'cellPadding', 'padding', 'valign', 'colSpan', 'rowSpan',
        'header', 'language', 'widthPx', 'heightPx', 'alt', 'bold', 'italic', 'underline', 'strike', 'code',
        'smallCaps', 'link', 'color', 'highlight', 'size', 'font', 'letterSpacing', 'title', 'defaultFont',
        'defaultSize', 'page', 'margins', 'orientation', 'footer', 'left', 'right', 'center', 'top', 'bottom',
        'insideH', 'insideV', 'style', 'background', 'border', 'inset',
    ];

    /** @var list<array{path: string, message: string}> */
    private array $notes = [];

    public function __construct(private readonly int $maxImagePixels = Image::MAX_PIXELS) {}

    /**
     * @param  array<string, mixed>  $doc
     * @return array{doc: array<string, mixed>, notes: list<array{path: string, message: string}>}
     */
    public function repair(array $doc): array
    {
        $this->notes = [];

        if (array_key_exists('title', $doc) && ! is_string($doc['title'])) {
            if (is_scalar($doc['title'])) {
                $doc['title'] = (string) $doc['title'];
                $this->note('title', 'coerced non-string title to string');
            } else {
                unset($doc['title']);
                $this->note('title', 'dropped non-string title');
            }
        }
        if (isset($doc['defaultSize']) && (is_int($doc['defaultSize']) || is_float($doc['defaultSize']))) {
            $doc['defaultSize'] = $this->clampSize($doc['defaultSize'], 'defaultSize');
        }
        if (! isset($doc['blocks']) || ! is_array($doc['blocks']) || ! array_is_list($doc['blocks'])) {
            $doc['blocks'] = [];
            $this->note('blocks', 'defaulted missing/invalid "blocks" to []');
        }
        $doc['blocks'] = $this->blocks($doc['blocks'], 'blocks');

        // Whatever is still wrong: remove optional properties, one pass.
        $validator = new Validator($this->maxImagePixels);
        foreach ($validator->validate($doc) as $error) {
            if ($this->dropPath($doc, $error['path'])) {
                $this->note($error['path'], 'removed: '.$error['message']);
            }
        }

        return ['doc' => $doc, 'notes' => $this->notes];
    }

    /**
     * @param  list<mixed>  $blocks
     * @return list<array<string, mixed>>
     */
    private function blocks(array $blocks, string $path): array
    {
        $out = [];
        foreach ($blocks as $i => $block) {
            $repaired = $this->block($block, "{$path}[{$i}]");
            if ($repaired !== null) {
                $out[] = $repaired;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null null drops the block */
    private function block(mixed $block, string $path): ?array
    {
        if (is_string($block)) {
            $this->note($path, 'coerced bare string block to a paragraph');

            return ['type' => 'paragraph', 'runs' => [['text' => $block]]];
        }
        if (! is_array($block)) {
            $this->note($path, 'dropped non-object block ('.get_debug_type($block).')');

            return null;
        }
        $type = $block['type'] ?? null;
        if (! is_string($type)) {
            if (isset($block['runs']) || isset($block['text'])) {
                $block['type'] = $type = 'paragraph';
                $this->note("{$path}.type", 'defaulted missing block type to "paragraph"');
            } else {
                $this->note($path, 'dropped block without a usable "type"');

                return null;
            }
        }
        if ($type === 'chart') {
            $this->note("{$path}.type", 'dropped a chart block: charts are not drawn in a PDF yet, so put its figures in a table');

            return null;
        }
        if (! in_array($type, Schema::BLOCK_TYPES, true)) {
            $this->note("{$path}.type", "dropped block with unknown type \"{$type}\"");

            return null;
        }

        return match ($type) {
            'heading' => $this->heading($block, $path),
            'paragraph' => $this->paragraphLike($block, $path),
            'list' => $this->listBlock($block, $path),
            'table' => $this->table($block, $path),
            'code' => $this->code($block, $path),
            'quote' => $this->quote($block, $path),
            'image' => $this->image($block, $path),
            'pageBreak' => ['type' => 'pageBreak'],
            'hr' => ['type' => 'hr'],
        };
    }

    /** @param array<string, mixed> $block */
    private function heading(array $block, string $path): array
    {
        $level = $block['level'] ?? 1;
        if (! is_int($level)) {
            $level = is_numeric($level) ? (int) $level : 1;
            $this->note("{$path}.level", 'coerced heading level to an integer');
        }
        $clamped = max(1, min(Schema::MAX_HEADING_LEVEL, $level));
        if ($clamped !== $level) {
            $this->note("{$path}.level", "clamped heading level {$level} to {$clamped}");
        }
        $block['level'] = $clamped;

        return $this->paragraphLike($block, $path);
    }

    /** @param array<string, mixed> $block */
    private function paragraphLike(array $block, string $path): array
    {
        $block['runs'] = $this->runs($block, $path);
        unset($block['text']);

        return $block;
    }

    /** @param array<string, mixed> $block */
    private function listBlock(array $block, string $path): array
    {
        $items = $block['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            $this->note("{$path}.items", 'defaulted missing list items to []');
            $items = [];
        }
        $block['items'] = $this->items($items, "{$path}.items");
        if (isset($block['ordered']) && ! is_bool($block['ordered'])) {
            $block['ordered'] = (bool) $block['ordered'];
            $this->note("{$path}.ordered", 'coerced ordered flag to boolean');
        }

        return $block;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function items(array $items, string $path): array
    {
        $out = [];
        foreach ($items as $i => $item) {
            $itemPath = "{$path}[{$i}]";
            if (is_string($item)) {
                $this->note($itemPath, 'coerced bare string list item to {runs}');
                $out[] = ['runs' => [['text' => $item]]];

                continue;
            }
            if (! is_array($item)) {
                $this->note($itemPath, 'dropped non-object list item');

                continue;
            }
            $item['runs'] = $this->runs($item, $itemPath);
            unset($item['text']);
            if (isset($item['children'])) {
                if (is_array($item['children']) && array_is_list($item['children'])) {
                    $item['children'] = $this->items($item['children'], "{$itemPath}.children");
                    if ($item['children'] === []) {
                        unset($item['children']);
                    }
                } else {
                    unset($item['children']);
                    $this->note("{$itemPath}.children", 'dropped invalid children');
                }
            }
            $out[] = $item;
        }

        return $out;
    }

    /** @param array<string, mixed> $block */
    private function table(array $block, string $path): array
    {
        $rows = $block['rows'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->note("{$path}.rows", 'defaulted missing table rows to []');
            $rows = [];
        }
        $outRows = [];
        foreach ($rows as $r => $row) {
            $rowPath = "{$path}.rows[{$r}]";
            if (is_array($row) && array_is_list($row) && $row !== []) {
                $this->note($rowPath, 'coerced a bare list of cells to {cells}');
                $row = ['cells' => $row];
            }
            if (! is_array($row)) {
                $this->note($rowPath, 'dropped non-object table row');

                continue;
            }
            $cells = $row['cells'] ?? null;
            if (! is_array($cells) || ! array_is_list($cells)) {
                $this->note("{$rowPath}.cells", 'defaulted missing cells to []');
                $cells = [];
            }
            $outCells = [];
            foreach ($cells as $c => $cell) {
                $cellPath = "{$rowPath}.cells[{$c}]";
                if (is_string($cell) || is_int($cell) || is_float($cell)) {
                    $this->note($cellPath, 'coerced bare value cell to {blocks: [paragraph]}');
                    $outCells[] = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => (string) $cell]]]]];

                    continue;
                }
                if (! is_array($cell)) {
                    $this->note($cellPath, 'dropped non-object table cell');

                    continue;
                }
                $cellBlocks = $cell['blocks'] ?? null;
                if (! is_array($cellBlocks) || ! array_is_list($cellBlocks)) {
                    if (isset($cell['text']) && is_string($cell['text'])) {
                        $this->note("{$cellPath}.blocks", 'coerced cell "text" shorthand to a paragraph block');
                        $cellBlocks = [['type' => 'paragraph', 'runs' => [['text' => $cell['text']]]]];
                        unset($cell['text']);
                    } else {
                        $this->note("{$cellPath}.blocks", 'defaulted missing cell blocks to []');
                        $cellBlocks = [];
                    }
                }
                $cell['blocks'] = $this->blocks($cellBlocks, "{$cellPath}.blocks");
                $outCells[] = $cell;
            }
            $row['cells'] = $outCells;
            if (isset($row['header']) && ! is_bool($row['header'])) {
                $row['header'] = (bool) $row['header'];
                $this->note("{$rowPath}.header", 'coerced header flag to boolean');
            }
            $outRows[] = $row;
        }
        $block['rows'] = $outRows;

        return $block;
    }

    /** @param array<string, mixed> $block */
    private function code(array $block, string $path): array
    {
        if (! is_string($block['text'] ?? null)) {
            if (is_scalar($block['text'] ?? null)) {
                $block['text'] = (string) $block['text'];
                $this->note("{$path}.text", 'coerced code text to string');
            } else {
                $block['text'] = '';
                $this->note("{$path}.text", 'defaulted missing code text to ""');
            }
        }

        return $block;
    }

    /** @param array<string, mixed> $block */
    private function quote(array $block, string $path): array
    {
        $blocks = $block['blocks'] ?? null;
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            if (isset($block['text']) && is_string($block['text'])) {
                $this->note("{$path}.blocks", 'coerced quote "text" shorthand to a paragraph block');
                $blocks = [['type' => 'paragraph', 'runs' => [['text' => $block['text']]]]];
                unset($block['text']);
            } else {
                $this->note("{$path}.blocks", 'defaulted missing quote blocks to []');
                $blocks = [];
            }
        }
        $block['blocks'] = $this->blocks($blocks, "{$path}.blocks");

        return $block;
    }

    /** @param array<string, mixed> $block */
    private function image(array $block, string $path): ?array
    {
        $src = $block['src'] ?? null;
        try {
            if (! is_string($src)) {
                throw new ImageException('image has no src');
            }
            Image::fromDataUrl($src, $this->maxImagePixels);
        } catch (ImageException $e) {
            $this->note("{$path}.src", 'dropped an image that cannot be embedded: '.$e->getMessage());

            return null;
        }

        return $block;
    }

    /**
     * @param  array<string, mixed>  $owner
     * @return list<array<string, mixed>>
     */
    private function runs(array $owner, string $path): array
    {
        $runs = $owner['runs'] ?? null;
        if (! is_array($runs) || ! array_is_list($runs)) {
            if (isset($owner['text']) && is_string($owner['text'])) {
                $this->note("{$path}.runs", 'coerced "text" string shorthand to runs');

                return [['text' => $owner['text']]];
            }
            if (is_string($runs)) {
                $this->note("{$path}.runs", 'coerced string runs to a single run');

                return [['text' => $runs]];
            }
            $this->note("{$path}.runs", 'defaulted missing runs to []');

            return [];
        }

        $out = [];
        foreach ($runs as $i => $run) {
            $runPath = "{$path}.runs[{$i}]";
            if (is_string($run)) {
                $this->note($runPath, 'coerced bare string run to {text}');
                $out[] = ['text' => $run];

                continue;
            }
            if (! is_array($run)) {
                $this->note($runPath, 'dropped non-object run');

                continue;
            }
            if (! is_string($run['text'] ?? null)) {
                if (is_scalar($run['text'] ?? null)) {
                    $run['text'] = (string) $run['text'];
                    $this->note("{$runPath}.text", 'coerced run text to string');
                } else {
                    $this->note($runPath, 'dropped run without text');

                    continue;
                }
            }
            foreach (Schema::RUN_FLAGS as $flag) {
                if (isset($run[$flag]) && ! is_bool($run[$flag])) {
                    $run[$flag] = (bool) $run[$flag];
                    $this->note("{$runPath}.{$flag}", "coerced {$flag} flag to boolean");
                }
            }
            if (isset($run['link']) && ! Schema::linkAllowed($run['link'])) {
                unset($run['link']);
                $this->note("{$runPath}.link", 'removed a link that is not an http://, https:// or mailto: URL; the text is kept');
            }
            if (isset($run['size']) && (is_int($run['size']) || is_float($run['size']))) {
                $run['size'] = $this->clampSize($run['size'], "{$runPath}.size");
            }
            $out[] = $run;
        }

        return $out;
    }

    private function clampSize(int|float $size, string $path): int|float
    {
        if ($size < Schema::MIN_TEXT_SIZE) {
            $this->note($path, 'raised a text size below '.Schema::MIN_TEXT_SIZE.'pt to '.Schema::MIN_TEXT_SIZE.'pt; smaller text reads as hidden');

            return Schema::MIN_TEXT_SIZE;
        }
        if ($size > Schema::MAX_TEXT_SIZE) {
            $this->note($path, 'lowered a text size above '.Schema::MAX_TEXT_SIZE.'pt to '.Schema::MAX_TEXT_SIZE.'pt');

            return Schema::MAX_TEXT_SIZE;
        }

        return $size;
    }

    /**
     * Remove the property an error path names, when it is optional.
     *
     * @param  array<string, mixed>  $doc
     */
    private function dropPath(array &$doc, string $path): bool
    {
        if (preg_match_all('/([A-Za-z]+)|\[(\d+)\]/', $path, $m, PREG_SET_ORDER) === 0) {
            return false;
        }
        $keys = [];
        foreach ($m as $part) {
            $keys[] = ($part[1] ?? '') !== '' ? $part[1] : (int) $part[2];
        }
        $last = array_pop($keys);
        if (! is_string($last) || ! in_array($last, self::OPTIONAL, true)) {
            return false;
        }
        $node = &$doc;
        foreach ($keys as $k) {
            if (! is_array($node) || ! array_key_exists($k, $node)) {
                return false;
            }
            $node = &$node[$k];
        }
        if (! is_array($node) || ! array_key_exists($last, $node)) {
            return false;
        }
        unset($node[$last]);

        return true;
    }

    private function note(string $path, string $message): void
    {
        $this->notes[] = ['path' => $path, 'message' => $message];
    }
}
