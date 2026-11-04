<?php

declare(strict_types=1);

namespace FinePrint\Writer\Layout;

use FinePrint\Writer\Schema;

/**
 * Which header and footer each page draws, how tall they are, and drawing them.
 * The rules are last-word-ui's `bands.ts` and `geometry.ts`, so a PDF and the
 * viewer agree:
 *
 *   - a variant that is set wins on its pages, even when it is empty: an empty
 *     one blanks that page's band rather than falling back to the default
 *   - `first*` wins on the first page, then `even*` on an even page number
 *   - a band is placed at its distance from the page edge, and spans the text
 *     column: its own stretch's width, between that stretch's margins
 *   - the body clears a blocks band: top = max(margin, distance + height), and
 *     likewise at the foot
 *
 * One deliberate difference from the viewer: a ONE-LINE band never moves the
 * body. Moving it would change the bytes of every existing document whose margin
 * is tight, and `band-overlap` already says so in lint.
 */
final class PageBands
{
    /** A blocks band that would leave less body than this is not given the room. */
    private const MIN_BODY = 72.0;

    /** @var array<string, BandBox> static blocks bands, by band and width */
    private array $boxes = [];

    /** @var array<string, float> measured heights, by band and width */
    private array $heights = [];

    public function __construct(
        private readonly Composer $composer,
        private readonly Diagnostics $diagnostics,
    ) {}

    /**
     * The band a page draws for a kind, and where the document set it: the page
     * object's key and the path to report it under. Null when it draws none.
     *
     * @param  array<string, mixed>  $stretch
     * @return array{key: string, band: array<string, mixed>, path: string}|null
     */
    public function band(array $stretch, string $kind, int $number, bool $first): ?array
    {
        $page = is_array($stretch['doc']['page'] ?? null) ? $stretch['doc']['page'] : [];
        $variants = $first ? ['first'.ucfirst($kind)] : [];
        if ($number % 2 === 0) {
            $variants[] = 'even'.ucfirst($kind);
        }
        $key = $kind;
        foreach ($variants as $variant) {
            if (is_array($page[$variant] ?? null)) {
                $key = $variant;

                break;
            }
        }
        $band = $page[$key] ?? null;
        if (! Schema::bandHasContent($band)) {
            return null;
        }

        return ['key' => $key, 'band' => $band, 'path' => $stretch['origins'][$key] ?? "page.{$key}"];
    }

    /**
     * The body of a page, from the bands it draws.
     *
     * @param  array<string, mixed>  $stretch
     * @param  array{top: float, right: float, bottom: float, left: float, header: float, footer: float}  $m
     * @return array{top: float, bottom: float}
     */
    public function body(array $stretch, array $m, float $width, float $pageHeight, int $number, bool $first): array
    {
        $top = $m['top'];
        $foot = $m['bottom'];
        foreach (['header', 'footer'] as $kind) {
            $picked = $this->band($stretch, $kind, $number, $first);
            if ($picked === null || ! is_array($picked['band']['blocks'] ?? null)) {
                continue;
            }
            $h = $this->measure($picked['band'], $width, $pageHeight, $stretch['doc'], $picked['path']);
            if ($kind === 'header') {
                $top = max($top, $m['header'] + $h);
            } else {
                $foot = max($foot, $m['footer'] + $h);
            }
        }
        if ($pageHeight - $top - $foot < self::MIN_BODY) {
            $this->diagnostics->add('band-too-tall', 'error', 'page', 'a header or footer leaves less than an inch of body on a page: it is drawn over the body, so make it smaller', $number);
            [$top, $foot] = [$m['top'], $m['bottom']];
        }

        return ['top' => $top, 'bottom' => $pageHeight - $foot];
    }

    /**
     * Draw a page's header and footer. The page count is known by now.
     *
     * @param  array<string, mixed>  $stretch
     * @param  array{top: float, right: float, bottom: float, left: float, header: float, footer: float}  $m
     * @param  array<string, string>  $fields
     */
    public function draw(Canvas $canvas, array $stretch, array $m, array $fields, int $number, bool $first): void
    {
        $width = $canvas->width - $m['left'] - $m['right'];
        foreach (['header', 'footer'] as $kind) {
            $picked = $this->band($stretch, $kind, $number, $first);
            if ($picked === null) {
                continue;
            }
            $band = $picked['band'];
            if (is_array($band['blocks'] ?? null)) {
                $box = $this->box($band, $width, $canvas->height, $fields, $picked['path']);
                $top = $kind === 'header' ? $m['header'] : $canvas->height - $m['footer'] - $box->height();
                $box->draw($canvas, $m['left'], $top);

                continue;
            }
            $this->oneLine($canvas, $band, $kind, $m, $fields, $picked['path']);
        }
    }

    /**
     * @param  array<string, mixed>  $band
     * @param  array{top: float, right: float, bottom: float, left: float, header: float, footer: float}  $m
     * @param  array<string, string>  $fields
     */
    private function oneLine(Canvas $canvas, array $band, string $kind, array $m, array $fields, string $path): void
    {
        $slots = [];
        $height = 0.0;
        foreach (['left', 'center', 'right'] as $slot) {
            if (! is_string($band[$slot] ?? null) || $band[$slot] === '') {
                continue;
            }
            $lines = $this->composer->bandLines($band[$slot], $band, $fields, "{$path}.{$slot}");
            $slots[$slot] = $lines;
            $height = max($height, array_sum(array_map(fn (Line $l) => $l->height, $lines)));
        }
        $start = $kind === 'header' ? $m['header'] : $canvas->height - $m['footer'] - $height;
        foreach ($slots as $slot => $lines) {
            $top = $start;
            foreach ($lines as $line) {
                $x = match ($slot) {
                    'left' => $m['left'],
                    'center' => ($canvas->width - $line->width) / 2,
                    'right' => $canvas->width - $m['right'] - $line->width,
                };
                $canvas->text($line, $x, $top, $line->width + 1, 'left');
                $top += $line->height;
            }
        }
    }

    /**
     * A blocks band's height, before pages exist. Its page tokens are measured
     * with the widest they could reasonably be, so a number that widens later
     * cannot wrap a line the room was not made for.
     *
     * @param  array<string, mixed>  $band
     * @param  array<string, mixed>  $doc
     */
    private function measure(array $band, float $width, float $pageHeight, array $doc, string $path): float
    {
        $key = $this->key($band, $width);
        if (! isset($this->heights[$key])) {
            $wide = '9999';
            $fields = ['page' => $wide, 'pages' => $wide, 'section' => $wide, 'sectionpages' => $wide] + self::docFields($doc);
            $this->heights[$key] = $this->box($band, $width, $pageHeight, $fields, $path)->height();
        }

        return $this->heights[$key];
    }

    /**
     * @param  array<string, mixed>  $band
     * @param  array<string, string>  $fields
     */
    private function box(array $band, float $width, float $pageHeight, array $fields, string $path): BandBox
    {
        $live = false;
        foreach (Schema::bandTexts($band) as $text) {
            if (preg_match(Schema::FIELD_TOKEN, $text) === 1) {
                $live = true;

                break;
            }
        }
        if ($live) {
            return $this->composer->bandBox($band['blocks'], $width, $pageHeight, $fields, "{$path}.blocks");
        }

        return $this->boxes[$this->key($band, $width)] ??= $this->composer->bandBox($band['blocks'], $width, $pageHeight, $fields, "{$path}.blocks");
    }

    /** @param array<string, mixed> $band */
    private function key(array $band, float $width): string
    {
        return md5(serialize($band)).'|'.$width;
    }

    /**
     * The tokens that are the same on every page.
     *
     * @param  array<string, mixed>  $doc
     * @return array{title: string, author: string}
     */
    public static function docFields(array $doc): array
    {
        return [
            'title' => is_string($doc['title'] ?? null) ? $doc['title'] : '',
            'author' => is_string($doc['meta']['author'] ?? null) ? $doc['meta']['author'] : '',
        ];
    }
}
