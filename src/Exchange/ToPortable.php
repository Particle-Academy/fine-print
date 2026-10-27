<?php

declare(strict_types=1);

namespace FinePrint\Exchange;

use FinePrint\Security\AgentReadGuard;

/**
 * fine-print's guarded `read()` result to portable content (exchange SPEC
 * section 10.4, pinned by `exchange/from-fine-print`). Pages become
 * paragraphs; PDF structure is not recovered, and every page says so.
 *
 * Guard v1: every string is stripped of the characters AgentReadGuard calls
 * invisible (zero-width, bidi controls and isolates, Unicode tags), even when
 * the read was made under `flag`, and each maximal run removed is counted.
 * The read's own findings are counted by rule. No report carries text: the
 * excerpt and location of a finding never pass through.
 */
final class ToPortable
{
    private const OPTION_KEYS = ['includeHidden', 'headerRows', 'limits'];

    /**
     * @param  array<string, mixed>  $read  what Agent::read() returned
     * @param  array<string, mixed>  $options  `{includeHidden?, headerRows?, limits?}`; the first two have nothing to act on in a PDF
     * @return array<string, mixed>
     */
    public static function fromRead(array $read, mixed $selector, array $options = []): array
    {
        $limits = self::options($options);

        $selected = self::select($read, $selector);
        if (isset($selected['refused'])) {
            return ['ok' => false, 'refused' => $selected['refused']];
        }

        $report = new Report;
        $items = [];
        foreach ($selected['pages'] as $index => $page) {
            [$paragraphs, $stripped] = self::paragraphs($page['text']);
            if ($stripped > 0) {
                $report->finding('invisible-chars', $stripped);
            }
            foreach ($paragraphs as $text) {
                $items[] = ['type' => 'text', 'role' => 'paragraph', 'runs' => [['text' => $text]]];
            }
            $report->add('structure-not-recovered', '/pages/'.$index);
        }
        foreach (is_array($read['guard']['findings'] ?? null) ? $read['guard']['findings'] : [] as $f) {
            if (is_array($f) && is_string($f['rule'] ?? null) && $f['rule'] !== '') {
                $report->finding($f['rule']);
            }
        }

        $source = ['package' => 'fine-print'];
        if ($selector !== null) {
            $source['selector'] = $selector;
        }
        $content = ['fancyContent' => 1, 'source' => $source, 'items' => $items, 'report' => $report->toArray()];

        // A reader whose output is over a limit refuses with the code; it never cuts.
        $check = Content::validate($content, ['limits' => $limits]);
        if (! $check['ok']) {
            return ['ok' => false, 'refused' => ['reason' => $check['code']]];
        }

        return ['ok' => true, 'content' => $content];
    }

    /**
     * The selected pages, keyed by their index in `pages`, or a refusal.
     *
     * @param  array<string, mixed>  $read
     * @return array{pages?: array<int, array{page: int, text: string}>, refused?: array<string, string>}
     */
    public static function select(array $read, mixed $selector): array
    {
        if (($read['ok'] ?? null) !== true) {
            $cause = $read['refused']['reason'] ?? null;

            return ['refused' => ['reason' => 'source_refused', 'cause' => is_string($cause) ? $cause : 'unknown']];
        }
        if (($read['guard']['discarded'] ?? false) === true) {
            return ['refused' => ['reason' => 'source_discarded']];
        }
        $pages = [];
        foreach (is_array($read['pages'] ?? null) ? array_values($read['pages']) : [] as $i => $p) {
            if (is_array($p) && is_int($p['page'] ?? null)) {
                $pages[$i] = ['page' => $p['page'], 'text' => is_string($p['text'] ?? null) ? $p['text'] : ''];
            }
        }
        if ($selector === null) {
            return ['pages' => $pages];
        }

        $range = is_array($selector) && array_keys($selector) === ['pages'] ? $selector['pages'] : null;
        if (! Content::isList($range) || count($range) !== 2
            || ! Content::isInteger($range[0]) || ! Content::isInteger($range[1])
            || $range[0] < 1 || $range[0] > $range[1]) {
            return ['refused' => ['reason' => 'selector_invalid']];
        }
        $count = is_int($read['pageCount'] ?? null) ? $read['pageCount'] : count($pages);
        if ($range[1] > $count) {
            return ['refused' => ['reason' => 'selector_not_found']];
        }

        return ['pages' => array_filter($pages, fn (array $p) => $p['page'] >= $range[0] && $p['page'] <= $range[1])];
    }

    /**
     * Strip invisible characters, then split into paragraphs (SPEC section 10):
     * CRLF and CR become LF; paragraphs are separated by one or more blank
     * lines (only spaces and tabs); lines are joined with LF, otherwise
     * unchanged.
     *
     * @return array{0: list<string>, 1: int} the paragraphs, and the runs stripped
     */
    public static function paragraphs(string $text): array
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }
        $stripped = 0;
        $text = (string) preg_replace(AgentReadGuard::INVISIBLE, '', $text, -1, $stripped);
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $out = [];
        $current = [];
        foreach (explode("\n", $text) as $line) {
            if (trim($line, " \t") === '') {
                if ($current !== []) {
                    $out[] = implode("\n", $current);
                    $current = [];
                }

                continue;
            }
            $current[] = $line;
        }
        if ($current !== []) {
            $out[] = implode("\n", $current);
        }

        return [$out, $stripped];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, int>
     */
    private static function options(array $options): array
    {
        foreach ($options as $key => $value) {
            if (! in_array($key, self::OPTION_KEYS, true)) {
                throw new OptionsInvalid('unknown option "'.$key.'"');
            }
        }
        if (array_key_exists('includeHidden', $options) && ! is_bool($options['includeHidden'])) {
            throw new OptionsInvalid('includeHidden must be a boolean');
        }
        if (array_key_exists('headerRows', $options) && (! Content::isInteger($options['headerRows']) || $options['headerRows'] < 0)) {
            throw new OptionsInvalid('headerRows must be a non-negative integer');
        }

        return Content::limits(array_key_exists('limits', $options) ? ['limits' => $options['limits']] : null);
    }
}
