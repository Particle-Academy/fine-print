<?php

declare(strict_types=1);

namespace FinePrint;

use FinePrint\Exceptions\RefusedException;
use FinePrint\Parser\PdfSyntaxException;
use FinePrint\Security\AgentReadGuard;
use InvalidArgumentException;

/**
 * Agent — the structured-tool surface for FinePrint.
 *
 * Mirrors {@see \HolySheet\Agent}, {@see \DarkSlide\Agent} and
 * {@see \LastWord\Agent}: static methods, JSON-friendly arrays in and out.
 *
 * The difference from the siblings is the direction of trust. Those packages
 * mostly WRITE documents an agent asked for; this one mostly READS documents
 * someone else wrote. So {@see read()} never hands an agent raw file content:
 * every string passes the {@see AgentReadGuard} first, and the active-content
 * report rides alongside without ever carrying a script body.
 */
final class Agent
{
    /**
     * This package's own version, kept in ONE place and pinned to the changelog
     * by `VersionIsSingleSourcedTest`.
     */
    public const VERSION = '0.1.0';

    /**
     * Read a PDF for an agent.
     *
     * Options:
     *   - `policy`    `flag` | `redact` (default) | `discard` — see {@see AgentReadGuard}
     *   - `threshold` `low` | `medium` (default) | `high` — the severity a finding
     *                 needs before `redact` cuts it or `discard` fires
     *   - `excerpts`  bool, default false — include the offending text in each
     *                 finding. For a human audit log ONLY; never give that report
     *                 to an agent, since an excerpt of an injection is one.
     *   - `limits`    a {@see Limits} or an array of its named arguments
     *
     * A file that is refused — not a PDF, encrypted, damaged beyond repair,
     * or over a limit — returns `['ok' => false, 'refused' => ['reason' => ...,
     * 'message' => ...]]` rather than throwing, so an agent loop gets an answer
     * it can act on. Bad OPTIONS still throw: that is the caller's bug.
     *
     * @param  array{policy?: string, threshold?: string, excerpts?: bool, limits?: Limits|array<string, int|float>}  $options
     * @return array<string, mixed>
     */
    public static function read(string $bytesOrPath, array $options = []): array
    {
        $guard = new AgentReadGuard(
            (string) ($options['policy'] ?? 'redact'),
            (string) ($options['threshold'] ?? 'medium'),
            (bool) ($options['excerpts'] ?? false),
        );
        $limits = Limits::from($options['limits'] ?? null);

        try {
            return self::readGuarded(self::bytes($bytesOrPath, $limits), $limits, $guard, $options);
        } catch (RefusedException $e) {
            return ['ok' => false, 'refused' => ['reason' => $e->reason, 'message' => $e->getMessage()]];
        } catch (PdfSyntaxException) {
            return ['ok' => false, 'refused' => ['reason' => 'corrupt', 'message' => 'The file could not be parsed.']];
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function readGuarded(string $bytes, Limits $limits, AgentReadGuard $guard, array $options): array
    {
        $doc = Document::open($bytes, $limits);

        // Collect every string the agent could see, as guard segments.
        $segments = [];
        $slots = [];
        $pages = $doc->pages();
        foreach ($pages as $p) {
            $pt = $doc->pageText($p['number']);
            $slots[] = ['page', $p['number']];
            $segments[] = ['location' => ['source' => 'page', 'page' => $p['number']], 'text' => $pt['text'], 'spans' => $pt['spans']];
        }
        foreach ($doc->metadata() as $field => $value) {
            $slots[] = ['metadata', $field];
            $segments[] = ['location' => ['source' => 'metadata', 'field' => $field], 'text' => $value];
        }
        $xmp = $doc->xmp();
        if ($xmp !== null) {
            $slots[] = ['xmp'];
            $segments[] = ['location' => ['source' => 'xmp'], 'text' => $xmp];
        }
        $annotations = [];
        foreach ($pages as $p) {
            foreach ($doc->annotations($p['number']) as $a) {
                $k = count($annotations);
                $annotations[] = ['page' => $p['number']] + $a;
                foreach (['contents', 'author'] as $field) {
                    if ($a[$field] !== null) {
                        $slots[] = ['annotation', $k, $field];
                        $segments[] = [
                            'location' => ['source' => 'annotation', 'page' => $p['number'], 'object' => $a['object'], 'field' => $field],
                            'text' => $a[$field],
                        ];
                    }
                }
            }
        }
        $outline = $doc->outline();
        foreach (self::outlinePaths($outline) as $path) {
            $slots[] = ['outline', $path];
            $segments[] = ['location' => ['source' => 'outline', 'path' => $path], 'text' => self::outlineTitle($outline, $path)];
        }

        $result = $guard->apply($segments);

        $out = [
            'ok' => true,
            'version' => $doc->version(),
            'pageCount' => count($pages),
            'repaired' => $doc->repaired(),
            'pages' => [],
            'metadata' => [],
            'outline' => [],
            'annotations' => [],
            'activeContent' => $doc->activeContent(),
            'warnings' => $doc->warnings(),
            'guard' => [
                'policy' => (string) ($options['policy'] ?? 'redact'),
                'threshold' => (string) ($options['threshold'] ?? 'medium'),
                'discarded' => $result['discarded'],
                'findings' => $result['findings'],
            ],
        ];
        if ($result['discarded']) {
            return $out;
        }

        $byPage = [];
        foreach ($pages as $p) {
            $byPage[$p['number']] = ['page' => $p['number'], 'width' => $p['width'], 'height' => $p['height'], 'text' => ''];
        }
        foreach ($slots as $i => $slot) {
            $text = (string) $result['texts'][$i];
            switch ($slot[0]) {
                case 'page':
                    $byPage[$slot[1]]['text'] = $text;

                    break;
                case 'metadata':
                    $out['metadata'][$slot[1]] = $text;

                    break;
                case 'annotation':
                    $annotations[$slot[1]][$slot[2]] = $text;

                    break;
                case 'outline':
                    self::setOutlineTitle($outline, $slot[1], $text);

                    break;
            }
        }
        $out['pages'] = array_values($byPage);
        $out['annotations'] = $annotations;
        $out['outline'] = $outline;

        return $out;
    }

    /**
     * The number of pages. For hosts: it THROWS on a refused file, because a
     * host deciding whether to serve a file wants an exception, not a shape.
     *
     * @param  Limits|array<string, int|float>|null  $limits
     *
     * @throws RefusedException
     */
    public static function pageCount(string $bytesOrPath, Limits|array|null $limits = null): int
    {
        $limits = Limits::from($limits);
        try {
            return Document::open(self::bytes($bytesOrPath, $limits), $limits)->pageCount();
        } catch (PdfSyntaxException) {
            throw new RefusedException('corrupt', 'The file could not be parsed.');
        }
    }

    public static function version(): string
    {
        return self::VERSION;
    }

    /** Accept raw bytes or a filesystem path, as the sibling packages do. */
    private static function bytes(string $bytesOrPath, Limits $limits): string
    {
        if (str_contains(substr($bytesOrPath, 0, 1024), '%PDF-')) {
            return $bytesOrPath;
        }
        if (strlen($bytesOrPath) < 4096 && ! str_contains($bytesOrPath, "\0") && is_file($bytesOrPath)) {
            if ((int) filesize($bytesOrPath) > $limits->maxFileBytes) {
                throw new RefusedException('too_large', 'The file is larger than the '.$limits->maxFileBytes.'-byte limit.');
            }
            $bytes = file_get_contents($bytesOrPath);
            if ($bytes === false) {
                throw new InvalidArgumentException('Could not read '.$bytesOrPath);
            }

            return $bytes;
        }

        return $bytesOrPath;
    }

    /**
     * @param  list<array{title: string, page: ?int, children: list<mixed>}>  $items
     * @param  list<int>  $prefix
     * @return list<list<int>>
     */
    private static function outlinePaths(array $items, array $prefix = []): array
    {
        $out = [];
        foreach ($items as $i => $item) {
            $path = [...$prefix, $i];
            $out[] = $path;
            array_push($out, ...self::outlinePaths($item['children'], $path));
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<int>  $path
     */
    private static function outlineTitle(array $items, array $path): string
    {
        $node = ['children' => $items];
        foreach ($path as $i) {
            $node = $node['children'][$i];
        }

        return (string) $node['title'];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<int>  $path
     */
    private static function setOutlineTitle(array &$items, array $path, string $title): void
    {
        $i = array_shift($path);
        if ($path === []) {
            $items[$i]['title'] = $title;

            return;
        }
        self::setOutlineTitle($items[$i]['children'], $path, $title);
    }
}
