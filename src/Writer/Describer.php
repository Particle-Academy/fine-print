<?php

declare(strict_types=1);

namespace FinePrint\Writer;

use FinePrint\Exchange\Portable;

/**
 * A compact plain-text summary of a document for the model: title, page,
 * top-level blocks by type, table sizes, images, portable blocks still to
 * expand, and a word count. last-word's `describe(doc)` idea, so an agent can
 * check what it built without the whole JSON going back into its context.
 *
 * Never fails on a malformed document: it describes what is there. Nesting is
 * walked to the validator's depth and no further.
 */
final class Describer
{
    private int $words = 0;

    private int $images = 0;

    private int $portable = 0;

    /** @var list<string> */
    private array $tables = [];

    /** @param array<string, mixed> $doc */
    public static function describe(array $doc): string
    {
        $d = new self;
        $blocks = is_array($doc['blocks'] ?? null) && array_is_list($doc['blocks']) ? $doc['blocks'] : [];

        $counts = [];
        foreach ($blocks as $block) {
            $type = is_array($block) && is_string($block['type'] ?? null) ? $block['type'] : 'unknown';
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        $d->walk($blocks, 0);

        $title = is_string($doc['title'] ?? null) && $doc['title'] !== '' ? $doc['title'] : 'Untitled';
        $page = is_array($doc['page'] ?? null) ? $doc['page'] : [];
        $size = is_string($page['size'] ?? null) && isset(Schema::PAGE_SIZES[$page['size']]) ? $page['size'] : 'letter';
        $orientation = ($page['orientation'] ?? null) === 'landscape' ? 'landscape' : 'portrait';
        $pageLine = "Page: {$size} {$orientation}";
        foreach (['header', 'footer'] as $band) {
            if (is_array($page[$band] ?? null)) {
                $pageLine .= ', '.$band;
            }
        }

        $parts = [];
        foreach ($counts as $type => $n) {
            $parts[] = "{$n} {$type}";
        }
        $lines = ["Document: {$title}", $pageLine, 'Blocks: '.count($blocks).($parts === [] ? '' : ' ('.implode(', ', $parts).')')];
        if ($d->tables !== []) {
            $lines[] = 'Tables: '.implode(', ', $d->tables);
        }
        if ($d->images > 0) {
            $lines[] = "Images: {$d->images}";
        }
        if ($d->portable > 0) {
            $lines[] = "Portable: {$d->portable} block(s), expanded by validateAndRepair";
        }
        $lines[] = "Words: {$d->words}";

        return implode("\n", $lines);
    }

    /** @param list<mixed> $blocks */
    private function walk(array $blocks, int $depth): void
    {
        if ($depth > Validator::MAX_DEPTH) {
            return;
        }
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            match ($block['type'] ?? null) {
                'heading', 'paragraph' => $this->runs($block['runs'] ?? null),
                'list' => $this->items($block['items'] ?? null, $depth),
                'code' => $this->count($block['text'] ?? null),
                'quote' => $this->walk(self::list($block['blocks'] ?? null), $depth + 1),
                'table' => $this->table($block, $depth),
                'image' => $this->images++,
                Portable::TYPE => $this->portable++,
                default => null,
            };
        }
    }

    /** @param array<string, mixed> $table */
    private function table(array $table, int $depth): void
    {
        $rows = self::list($table['rows'] ?? null);
        $width = 0;
        foreach ($rows as $row) {
            $cells = is_array($row) ? self::list($row['cells'] ?? null) : [];
            $w = 0;
            foreach ($cells as $cell) {
                $span = is_array($cell) ? ($cell['colSpan'] ?? 1) : 1;
                $w += is_int($span) && $span > 0 ? $span : 1;
                if (is_array($cell)) {
                    $this->walk(self::list($cell['blocks'] ?? null), $depth + 1);
                }
            }
            $width = max($width, $w);
        }
        $this->tables[] = count($rows).'x'.$width;
    }

    private function items(mixed $items, int $depth): void
    {
        if ($depth > Validator::MAX_DEPTH) {
            return;
        }
        foreach (self::list($items) as $item) {
            if (is_array($item)) {
                $this->runs($item['runs'] ?? null);
                $this->items($item['children'] ?? null, $depth + 1);
            }
        }
    }

    private function runs(mixed $runs): void
    {
        foreach (self::list($runs) as $run) {
            $this->count(is_array($run) ? ($run['text'] ?? null) : $run);
        }
    }

    private function count(mixed $text): void
    {
        if (is_string($text)) {
            $this->words += count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }
    }

    /** @return list<mixed> */
    private static function list(mixed $x): array
    {
        return is_array($x) && array_is_list($x) ? $x : [];
    }
}
