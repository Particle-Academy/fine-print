<?php

declare(strict_types=1);

namespace FinePrint\Writer;

use FinePrint\Agent;
use FinePrint\Writer\Pdf\Syntax;

/**
 * What will be wrong with the written PDF. It writes the document, then
 * reads it back through the agent-read guard, so the guard findings are what
 * a reader of this PDF (with this package or with any careful agent stack)
 * would actually see, not a guess.
 *
 * Findings are `{rule, severity, path, message}`, plus `page` where there is
 * one. Severities: `error` (the PDF is wrong), `warning` (it will look or read
 * wrong), `info` (worth knowing). No finding quotes the document's text: a
 * finding about an injection must not carry the injection.
 */
final class Linter
{
    /** A page holding less than this share of its body is sparse. */
    private const SPARSE = 0.2;

    private const PLACEHOLDER = '/\[(?:insert|enter|add|your|name|date|tbd|todo|placeholder|xx+)[^\]]{0,40}\]|\bTBD\b|\bTODO\b|\blorem ipsum\b|\bXXX+\b/i';

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    public function lint(array $doc, array $options = []): array
    {
        $errors = Agent::validate($doc);
        if ($errors !== []) {
            return array_map(fn (array $e) => ['rule' => 'schema', 'severity' => 'error', 'path' => $e['path'], 'message' => $e['message']], $errors);
        }

        $out = (new PdfWriter(['fonts' => $options['fonts'] ?? []]))->write($doc);
        $findings = $out['diagnostics'];

        foreach ($out['stranded'] as $page) {
            $findings[] = ['rule' => 'stranded-heading', 'severity' => 'warning', 'path' => 'page '.$page, 'page' => $page,
                'message' => 'the last block on this page is a heading (or another keep-with-next block) with nothing of its own after it'];
        }
        if ($out['pages'] > 1) {
            foreach ($out['fill'] as $i => $fill) {
                if ($fill < self::SPARSE) {
                    $findings[] = ['rule' => 'sparse-page', 'severity' => 'warning', 'path' => 'page '.($i + 1), 'page' => $i + 1,
                        'message' => sprintf('page %d is %d%% full; move material so every page carries content, and never pad', $i + 1, (int) round($fill * 100))];
                }
            }
            if (! $this->hasPageNumbers($doc)) {
                $findings[] = ['rule' => 'no-page-numbers', 'severity' => 'info', 'path' => 'page',
                    'message' => 'a multi-page document should carry "Page {page} of {pages}" in page.footer'];
            }
        }
        $this->bands($doc, $findings);
        $this->placeholders($doc['blocks'] ?? [], 'blocks', $findings);

        $read = Agent::read($out['bytes'], ['policy' => 'flag', 'threshold' => 'low']);
        foreach ($read['guard']['findings'] ?? [] as $g) {
            $loc = $g['location'] ?? [];
            $page = isset($loc['page']) ? (int) $loc['page'] : null;
            $f = [
                'rule' => 'guard:'.$g['rule'],
                'severity' => ($g['severity'] ?? 'low') === 'low' ? 'info' : 'warning',
                'path' => $page !== null ? 'page '.$page : (string) ($loc['source'] ?? 'document'),
                'message' => 'a reader\'s agent-read guard flags this ('.$g['rule'].', '.($g['severity'] ?? 'low').'); a reader using redact or discard will cut it or refuse the file',
            ];
            if ($page !== null) {
                $f['page'] = $page;
            }
            $findings[] = $f;
        }

        // One per rule and path.
        $unique = [];
        foreach ($findings as $f) {
            $unique[$f['rule'].'|'.$f['path']] ??= $f;
        }

        return array_values($unique);
    }

    /** @param array<string, mixed> $doc */
    private function hasPageNumbers(array $doc): bool
    {
        foreach (['header', 'footer'] as $kind) {
            foreach (['left', 'center', 'right'] as $slot) {
                if (str_contains((string) ($doc['page'][$kind][$slot] ?? ''), '{page}')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<array<string, mixed>>  $findings
     */
    private function bands(array $doc, array &$findings): void
    {
        $m = Schema::margins($doc);
        foreach (['header' => 'top', 'footer' => 'bottom'] as $kind => $side) {
            $band = $doc['page'][$kind] ?? null;
            if (! is_array($band)) {
                continue;
            }
            $needs = 36 + (float) ($band['size'] ?? 9) * 1.2 + 4;
            if ($m[$side] < $needs) {
                $findings[] = ['rule' => 'band-overlap', 'severity' => 'warning', 'path' => "page.{$kind}",
                    'message' => sprintf('the %s sits 36pt from the page edge and needs a %s margin of at least %dpt, or it runs into the text', $kind, $side, (int) ceil($needs))];
            }
        }
        $frame = Schema::pageFrame($doc);
        if ($frame !== null) {
            $reach = $frame['inset'] + $frame['width'];
            $tight = array_keys(array_filter($m, fn (float $v) => $v < $reach));
            if ($tight !== []) {
                $findings[] = ['rule' => 'frame-overlap', 'severity' => 'warning', 'path' => 'page.border',
                    'message' => sprintf('the page border reaches %spt from the edge, inside the %s margin, so text can run over it; make every margin at least %spt', Syntax::num($reach), implode('/', $tight), Syntax::num($reach))];
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     */
    private function placeholders(mixed $blocks, string $path, array &$findings): void
    {
        if (! is_array($blocks)) {
            return;
        }
        foreach ($blocks as $i => $block) {
            if (! is_array($block)) {
                continue;
            }
            $p = "{$path}[{$i}]";
            $this->runs($block['runs'] ?? null, "{$p}.runs", $findings);
            if (is_string($block['text'] ?? null) && ($block['type'] ?? '') !== 'code') {
                $this->check($block['text'], "{$p}.text", $findings);
            }
            $this->placeholders($block['blocks'] ?? null, "{$p}.blocks", $findings);
            $this->items($block['items'] ?? null, "{$p}.items", $findings);
            foreach (is_array($block['rows'] ?? null) ? $block['rows'] : [] as $r => $row) {
                foreach (is_array($row['cells'] ?? null) ? $row['cells'] : [] as $c => $cell) {
                    $this->placeholders($cell['blocks'] ?? null, "{$p}.rows[{$r}].cells[{$c}].blocks", $findings);
                }
            }
        }
    }

    /** @param list<array<string, mixed>> $findings */
    private function items(mixed $items, string $path, array &$findings): void
    {
        foreach (is_array($items) ? $items : [] as $i => $item) {
            if (is_array($item)) {
                $this->runs($item['runs'] ?? null, "{$path}[{$i}].runs", $findings);
                $this->items($item['children'] ?? null, "{$path}[{$i}].children", $findings);
            }
        }
    }

    /** @param list<array<string, mixed>> $findings */
    private function runs(mixed $runs, string $path, array &$findings): void
    {
        foreach (is_array($runs) ? $runs : [] as $i => $run) {
            if (is_array($run) && is_string($run['text'] ?? null) && ($run['code'] ?? false) !== true) {
                $this->check($run['text'], "{$path}[{$i}]", $findings);
            }
        }
    }

    /** @param list<array<string, mixed>> $findings */
    private function check(string $text, string $path, array &$findings): void
    {
        if (preg_match(self::PLACEHOLDER, $text) === 1) {
            $findings[] = ['rule' => 'placeholder', 'severity' => 'warning', 'path' => $path,
                'message' => 'this text looks like a placeholder ("[Insert ...]", "TBD", "TODO", lorem ipsum); a finished document has none'];
        }
    }
}
