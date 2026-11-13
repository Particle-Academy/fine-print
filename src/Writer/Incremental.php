<?php

declare(strict_types=1);

namespace FinePrint\Writer;

use FinePrint\Document;
use FinePrint\Exceptions\SchemaException;
use FinePrint\Limits;
use FinePrint\Objects\PdfDict;
use FinePrint\Objects\PdfName;
use FinePrint\Objects\PdfRef;
use FinePrint\Objects\PdfStream;
use FinePrint\Objects\PdfString;
use FinePrint\Writer\Pdf\Syntax;

/**
 * Append-only incremental updates (ISO 32000-1 section 7.5.6): new and
 * replaced objects, a cross-reference section for them alone, and a trailer
 * whose `/Prev` points at the original one.
 *
 * THE PROPERTY: the original file is a byte-for-byte PREFIX of the result.
 * Truncating the result at the original length and hashing proves the earlier
 * content did not change, with no tool of ours involved. This is how a signing
 * package fills in marks after a document was sent, and (later) how a PAdES
 * signature is carried.
 *
 * What it appends, and nothing else: content streams that draw paths and
 * WinAnsi Helvetica text over existing pages (wrapped so the original content
 * runs in its own saved graphics state), one standard-14 font object, and the
 * replaced page dictionaries that reference them. No annotation, no form, no
 * action. Text reaches the file only as a hex string.
 *
 * Refused: a file that needed repair to read (its cross-reference data cannot
 * be trusted as a `/Prev`), an encrypted file, a file whose last
 * cross-reference section is a stream, and any mark outside its limits.
 */
final class Incremental
{
    /** Every limit below is a constant a host can see; each is checked before anything is written. */
    public const MAX_ITEMS = 1000;

    public const MAX_POINTS = 20000;

    public const MAX_TEXT = 1000;

    /** 200 inches: the largest page PDF 1.7 allows. */
    public const MAX_COORD = 14400.0;

    /** The overlay font's resource name prefix. */
    private const FONT = 'FSo';

    /**
     * Draw over existing pages, as an incremental update.
     *
     * `$marks` is a list of `['page' => int (from 1), 'items' => list<item>]`:
     *   - `['type' => 'path', 'strokes' => list<list<[x, y]>>, 'width' => 1.0, 'color' => '#RRGGBB']`
     *   - `['type' => 'text', 'x' => float, 'y' => float, 'size' => float, 'text' => string, 'color' => '#RRGGBB']`
     * Coordinates are PDF user space (points, origin bottom-left).
     *
     * @param  list<array{page: int, items: list<array<string, mixed>>}>  $marks
     *
     * @throws SchemaException when the file cannot be appended to safely or a mark is invalid
     */
    public static function overlay(string $pdf, array $marks, ?Limits $limits = null): string
    {
        if ($marks === []) {
            return $pdf;
        }
        try {
            $doc = Document::open($pdf, $limits);
        } catch (\Throwable) {
            self::refuse('unreadable', 'the file could not be read as a PDF');
        }
        if ($doc->repaired()) {
            self::refuse('repaired', 'the file needed repair to read, so its cross-reference data cannot be appended to');
        }
        if ($doc->encryption() !== null) {
            self::refuse('encrypted', 'the file is encrypted');
        }
        $prev = self::lastXref($pdf);

        $pages = $doc->pageCount();
        $byPage = [];
        $points = 0;
        $items = 0;
        foreach ($marks as $i => $mark) {
            $page = is_array($mark) ? ($mark['page'] ?? null) : null;
            if (! is_int($page) || $page < 1 || $page > $pages) {
                self::refuse('page', "marks[{$i}].page must be a page of the document (1 to {$pages})");
            }
            if (! is_array($mark['items'] ?? null) || ! array_is_list($mark['items'])) {
                self::refuse('items', "marks[{$i}].items must be a list");
            }
            foreach ($mark['items'] as $j => $item) {
                if (++$items > self::MAX_ITEMS) {
                    self::refuse('limit', 'more than '.self::MAX_ITEMS.' overlay items');
                }
                $byPage[$page][] = self::item($item, "marks[{$i}].items[{$j}]", $points);
            }
        }
        ksort($byPage);

        $trailer = $doc->trailer();
        $size = $trailer->get('Size');
        $root = $trailer->get('Root');
        if (! is_int($size) || $size < 1 || ! $root instanceof PdfRef) {
            self::refuse('trailer', 'the trailer has no usable /Size or /Root');
        }
        $info = $trailer->get('Info');

        /** @var array<int, array{gen: int, body: string}> $objects */
        $objects = [];
        $next = $size;
        $add = function (string $body) use (&$objects, &$next): int {
            $objects[$next] = ['gen' => 0, 'body' => $body];

            return $next++;
        };
        $stream = fn (string $data): string => '<< /Length '.strlen($data)." >>\nstream\n".$data."\nendstream";

        $save = $add($stream("q\n"));
        $restore = $add($stream("Q\n"));
        $font = null;

        foreach ($byPage as $number => $drawn) {
            $p = $doc->page($number);
            if (! $p['ref'] instanceof PdfRef) {
                self::refuse('page', "page {$number} is not an indirect object");
            }
            $entries = $p['dict']->entries;

            $resources = $p['resources'] instanceof PdfDict ? $p['resources']->entries : [];
            $fontName = null;
            $hasText = array_filter($drawn, fn (array $d) => $d['type'] === 'text') !== [];
            if ($hasText) {
                $font ??= $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
                $fonts = $doc->resolve($resources['Font'] ?? null);
                $fontEntries = $fonts instanceof PdfDict ? $fonts->entries : [];
                $n = 1;
                while (array_key_exists(self::FONT.$n, $fontEntries)) {
                    $n++;
                }
                $fontName = self::FONT.$n;
                $fontEntries[$fontName] = new PdfRef($font, 0);
                $resources['Font'] = new PdfDict($fontEntries);
            }
            $overlay = $add($stream(self::ops($drawn, $fontName)));

            $old = $entries['Contents'] ?? null;
            $old = is_array($old) ? $old : ($old === null ? [] : [$old]);
            $entries['Contents'] = array_merge([new PdfRef($save, 0)], $old, [new PdfRef($restore, 0), new PdfRef($overlay, 0)]);
            $entries['Resources'] = new PdfDict($resources);
            $objects[$p['ref']->num] = ['gen' => $p['ref']->gen, 'body' => self::value(new PdfDict($entries), $doc)];
        }

        $out = str_ends_with($pdf, "\n") ? $pdf : $pdf."\n";
        $base = strlen($out);
        $body = '';
        $offsets = [];
        ksort($objects);
        foreach ($objects as $num => $o) {
            $offsets[$num] = $base + strlen($body);
            $body .= $num.' '.$o['gen']." obj\n".$o['body']."\nendobj\n";
        }
        $xrefAt = $base + strlen($body);
        $xref = "xref\n";
        foreach (self::runs(array_keys($offsets)) as [$first, $count]) {
            $xref .= $first.' '.$count."\n";
            for ($k = $first; $k < $first + $count; $k++) {
                $xref .= sprintf('%010d %05d n', $offsets[$k], $objects[$k]['gen'])."\r\n";
            }
        }
        $id = $trailer->get('ID');
        $firstId = is_array($id) && ($id[0] ?? null) instanceof PdfString ? $id[0]->bytes : null;
        $newId = md5($body.$xref);
        $trailerDict = '<< /Size '.$next.' /Root '.$root->num.' '.$root->gen.' R'
            .($info instanceof PdfRef ? ' /Info '.$info->num.' '.$info->gen.' R' : '')
            .' /Prev '.$prev
            .' /ID ['.Syntax::hex($firstId ?? hex2bin($newId)).' '.Syntax::hex((string) hex2bin($newId)).'] >>';

        return $out.$body.$xref."trailer\n".$trailerDict."\nstartxref\n".$xrefAt."\n%%EOF\n";
    }

    /** The original's last cross-reference offset, which must be a classic table. */
    private static function lastXref(string $pdf): int
    {
        $at = strrpos($pdf, 'startxref');
        if ($at === false || preg_match('/startxref\s+(\d+)/', $pdf, $m, 0, $at) !== 1) {
            self::refuse('repaired', 'the file has no startxref');
        }
        $offset = (int) $m[1];
        if (substr($pdf, $offset, 4) !== 'xref') {
            self::refuse('xref_stream', 'the last cross-reference section is a stream; only a classic table can be appended to');
        }

        return $offset;
    }

    /**
     * @return array<string, mixed> the item, checked and normalised
     */
    private static function item(mixed $item, string $path, int &$points): array
    {
        if (! is_array($item)) {
            self::refuse('item', "{$path} must be an object");
        }
        $color = $item['color'] ?? '#000000';
        if (! is_string($color) || preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1) {
            self::refuse('item', "{$path}.color must be #RRGGBB");
        }
        $type = $item['type'] ?? null;
        if ($type === 'path') {
            $width = $item['width'] ?? 1.0;
            if (! is_int($width) && ! is_float($width) || ! is_finite((float) $width) || $width <= 0 || $width > 20) {
                self::refuse('item', "{$path}.width must be a number from 0 to 20");
            }
            $strokes = $item['strokes'] ?? null;
            if (! is_array($strokes) || ! array_is_list($strokes)) {
                self::refuse('item', "{$path}.strokes must be a list");
            }
            $out = [];
            foreach ($strokes as $s => $stroke) {
                if (! is_array($stroke) || ! array_is_list($stroke) || $stroke === []) {
                    self::refuse('item', "{$path}.strokes[{$s}] must be a non-empty list of points");
                }
                $line = [];
                foreach ($stroke as $point) {
                    if (++$points > self::MAX_POINTS) {
                        self::refuse('limit', 'more than '.self::MAX_POINTS.' points');
                    }
                    $line[] = [self::coord($point[0] ?? null, $path), self::coord($point[1] ?? null, $path)];
                }
                $out[] = $line;
            }

            return ['type' => 'path', 'strokes' => $out, 'width' => (float) $width, 'color' => $color];
        }
        if ($type === 'text') {
            $text = $item['text'] ?? null;
            $size = $item['size'] ?? 9.0;
            if (! is_string($text) || mb_strlen($text, 'UTF-8') > self::MAX_TEXT) {
                self::refuse('item', "{$path}.text must be a string of at most ".self::MAX_TEXT.' characters');
            }
            if ((! is_int($size) && ! is_float($size)) || $size < 1 || $size > 200) {
                self::refuse('item', "{$path}.size must be from 1 to 200");
            }

            return ['type' => 'text', 'x' => self::coord($item['x'] ?? null, $path), 'y' => self::coord($item['y'] ?? null, $path),
                'size' => (float) $size, 'text' => $text, 'color' => $color];
        }
        self::refuse('item', "{$path}.type must be path or text");
    }

    private static function coord(mixed $v, string $path): float
    {
        if ((! is_int($v) && ! is_float($v)) || ! is_finite((float) $v) || abs((float) $v) > self::MAX_COORD) {
            self::refuse('item', "{$path}: a coordinate must be a finite number within ".self::MAX_COORD.' points');
        }

        return (float) $v;
    }

    /** @param list<array<string, mixed>> $items */
    private static function ops(array $items, ?string $font): string
    {
        $ops = '';
        foreach ($items as $item) {
            $rgb = Syntax::rgb($item['color']);
            if ($item['type'] === 'path') {
                $ops .= 'q '.Syntax::num($item['width']).' w '.Syntax::stroke($rgb)." 1 J 1 j\n";
                foreach ($item['strokes'] as $stroke) {
                    $first = $stroke[0];
                    $ops .= Syntax::num($first[0]).' '.Syntax::num($first[1])." m\n";
                    foreach (count($stroke) === 1 ? [$first] : array_slice($stroke, 1) as $pt) {
                        $ops .= Syntax::num($pt[0]).' '.Syntax::num($pt[1])." l\n";
                    }
                }
                $ops .= "S Q\n";

                continue;
            }
            $ops .= 'BT '.Syntax::name((string) $font).' '.Syntax::num($item['size']).' Tf '.Syntax::fill($rgb)
                .' 1 0 0 1 '.Syntax::num($item['x']).' '.Syntax::num($item['y']).' Tm '.Syntax::hex(self::winAnsi($item['text']))." Tj ET\n";
        }

        return $ops;
    }

    /** UTF-8 to WinAnsi (Windows-1252): controls dropped, anything unmappable a question mark. */
    private static function winAnsi(string $utf8): string
    {
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]/', '', Syntax::validUtf8($utf8));
        $held = mb_substitute_character();
        mb_substitute_character(0x3F);
        try {
            return (string) mb_convert_encoding($clean, 'Windows-1252', 'UTF-8');
        } finally {
            mb_substitute_character($held);
        }
    }

    /** A value as PDF syntax. Streams cannot appear inside a page dictionary and are refused. */
    private static function value(mixed $v, Document $doc, int $depth = 0): string
    {
        if ($depth > 32) {
            self::refuse('page', 'the page dictionary is nested too deeply to copy');
        }

        return match (true) {
            $v === null => 'null',
            is_bool($v) => $v ? 'true' : 'false',
            is_int($v) => (string) $v,
            is_float($v) => Syntax::num($v),
            $v instanceof PdfName => Syntax::name($v->value),
            $v instanceof PdfString => Syntax::hex($v->bytes),
            $v instanceof PdfRef => $v->num.' '.$v->gen.' R',
            $v instanceof PdfDict => '<<'.implode('', array_map(
                fn (string $k) => ' '.Syntax::name($k).' '.self::value($v->entries[$k], $doc, $depth + 1),
                array_map('strval', array_keys($v->entries)),
            )).' >>',
            is_array($v) => '['.implode(' ', array_map(fn ($x) => self::value($x, $doc, $depth + 1), $v)).']',
            $v instanceof PdfStream => self::refuse('page', 'a stream inside a page dictionary cannot be copied'),
            default => self::refuse('page', 'the page dictionary holds a value that cannot be copied'),
        };
    }

    /**
     * Contiguous runs of object numbers, for the cross-reference subsections.
     *
     * @param  list<int>  $nums  sorted
     * @return list<array{0: int, 1: int}>
     */
    private static function runs(array $nums): array
    {
        $runs = [];
        foreach ($nums as $n) {
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last][0] + $runs[$last][1] === $n) {
                $runs[$last][1]++;
            } else {
                $runs[] = [$n, 1];
            }
        }

        return $runs;
    }

    private static function refuse(string $reason, string $message): never
    {
        throw new SchemaException("Incremental update refused ({$reason}): {$message}.", [['path' => $reason, 'message' => $message]]);
    }
}
