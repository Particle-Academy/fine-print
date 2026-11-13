<?php

declare(strict_types=1);

namespace FinePrint\Writer;

use FinePrint\Agent;
use FinePrint\Writer\Fonts\FontBook;
use FinePrint\Writer\Images\ImageStore;
use FinePrint\Writer\Layout\Canvas;
use FinePrint\Writer\Layout\Composer;
use FinePrint\Writer\Layout\Diagnostics;
use FinePrint\Writer\Layout\PageBands;
use FinePrint\Writer\Layout\Paginator;
use FinePrint\Writer\Pdf\PdfFile;
use FinePrint\Writer\Pdf\Syntax;

/**
 * Writes a (validated) document as PDF 1.7.
 *
 * WHAT IT NEVER WRITES. The file has a catalog, a page tree, pages with
 * content streams, fonts, images, link annotations, and an Info dictionary.
 * Nothing else exists in this writer to be asked for: no `/OpenAction`, no
 * `/AA`, no `/JavaScript` or `/JS`, no `/Launch`, no `/AcroForm` or widget
 * annotations, no `/EmbeddedFiles`, no `/Names`, no `/RichMedia`, no XFA. The
 * only action is `/URI` on a link, and only with a target
 * {@see Schema::linkAllowed()} accepted. Model text reaches the file only as
 * hex strings, which nothing can break out of.
 *
 * Deterministic: no timestamps, a file ID hashed from the body, resources and
 * subset tags named in first-use order.
 */
final class PdfWriter
{
    /** The Producer written into every file. Deliberately unversioned, so golden files survive a release. */
    public const PRODUCER = 'fine-print (particle-academy/fine-print)';

    /**
     * @param  array{fonts?: array<string, string|array<string, string>>, compress?: bool}  $options
     */
    public function __construct(private readonly array $options = []) {}

    /**
     * @param  array<string, mixed>  $doc
     * @return array{bytes: string, pages: int, diagnostics: list<array<string, mixed>>, fill: list<float>, stranded: list<int>}
     */
    public function write(array $doc): array
    {
        $fonts = new FontBook($this->options['fonts'] ?? []);
        $images = new ImageStore();
        $diagnostics = new Diagnostics();

        $composer = new Composer($fonts, $images, $diagnostics, $doc);
        $bands = new PageBands($composer, $diagnostics);

        // The signing extension (see Signable, which validates these options).
        // A generated signature page is appended as a stretch of its own, so the
        // pages before it are exactly the authored ones.
        $signing = ($this->options['fields'] ?? false) === true;
        if ($signing) {
            $composer->enableFields();
        }
        $appendedAt = null;
        if ($signing && is_array($this->options['signaturePage'] ?? null) && $this->options['signaturePage'] !== []) {
            $appendedAt = count(Schema::stretches($doc));
            $doc['blocks'] = array_merge(is_array($doc['blocks'] ?? null) ? array_values($doc['blocks']) : [], [['type' => 'pageBreak', 'page' => []]], $this->options['signaturePage']);
        }
        $authored = null;

        // A stretch at a time: a run of pages of one shape, composed and
        // paginated at its own size. The first page of a stretch is numbered by
        // the pages before it, so even bands follow the document's own numbering.
        $pages = [];
        $fill = [];
        $stranded = [];
        /** @var list<array{stretch: array<string, mixed>, m: array<string, float>, section: int, first: bool, count: int}> $placed */
        $placed = [];
        foreach (Schema::stretches($doc) as $s => $stretch) {
            [$pw, $ph] = Schema::pageSize($stretch['doc']);
            $m = Schema::margins($stretch['doc']);
            $frameWidth = $pw - $m['left'] - $m['right'];
            $done = count($pages);
            if ($s === $appendedAt) {
                $authored = $done;
            }

            $boxAt = fn (int $index): array => $bands->body($stretch, $m, $frameWidth, $ph, $done + $index + 1, $index === 0);
            // An image is capped to the shortest body it could land on: the
            // first page's, or either number's. Without a tall band they are one
            // and the same, the page less its margins.
            $bodyHeight = $ph - $m['top'] - $m['bottom'];
            foreach ([[$done + 1, true], [$done + 2, false], [$done + 3, false]] as [$number, $first]) {
                $box = $bands->body($stretch, $m, $frameWidth, $ph, $number, $first);
                $bodyHeight = min($bodyHeight, $box['bottom'] - $box['top']);
            }
            $flow = $composer->compose($stretch['blocks'], $frameWidth, $bodyHeight);

            // Page decoration goes first on every page, so all content paints over
            // it, and it is sized by the page, never by what the page holds.
            $background = is_array($stretch['doc']['page'] ?? null) && is_string($stretch['doc']['page']['background'] ?? null)
                && preg_match('/^#[0-9A-Fa-f]{6}$/', $stretch['doc']['page']['background']) === 1 ? Syntax::rgb($stretch['doc']['page']['background']) : null;
            $frame = Schema::pageFrame($stretch['doc']);
            $newCanvas = function () use ($pw, $ph, $fonts, $background, $frame): Canvas {
                $canvas = new Canvas($pw, $ph, $fonts);
                if ($background !== null) {
                    $canvas->rect(0, 0, $pw, $ph, $background);
                }
                if ($frame !== null) {
                    $canvas->frame($frame['inset'], $frame['width'], Syntax::rgb($frame['color']), $frame['style']);
                }

                return $canvas;
            };
            $paginator = new Paginator($m['left'], $boxAt, $newCanvas, $diagnostics, $composer->footnotes());
            $canvases = $paginator->paginate($flow);

            foreach ($canvases as $i => $canvas) {
                $box = $paginator->boxes()[$i];
                $height = $box['bottom'] - $box['top'];
                $fill[] = $height > 0 ? max(0.0, min(1.0, ($canvas->bodyBottom - $box['top']) / $height)) : 0.0;
                $pages[] = $canvas;
                $placed[] = ['stretch' => $stretch, 'm' => $m, 'section' => $s + 1, 'first' => $i === 0, 'count' => count($canvases)];
            }
            foreach ($paginator->strandedKeeps() as $page) {
                $stranded[] = $done + $page;
            }
        }

        // Running headers and footers, drawn once the page count is known.
        $total = count($pages);
        foreach ($pages as $i => $canvas) {
            $p = $placed[$i];
            $fields = [
                'page' => (string) ($i + 1), 'pages' => (string) $total,
                'section' => (string) $p['section'], 'sectionpages' => (string) $p['count'],
            ] + PageBands::docFields($doc);
            $bands->draw($canvas, $p['stretch'], $p['m'], $fields, $i + 1, $p['first']);
        }

        // Per-page marks, on the authored pages only: the generated page comes
        // after them, so it can never carry one, by construction.
        $authored ??= $total;
        if ($signing && is_array($this->options['pageMarks'] ?? null)) {
            for ($i = 0; $i < $authored; $i++) {
                $this->pageMarks($pages[$i], $placed[$i]['m'], $i + 1, $this->options['pageMarks'], $composer, $diagnostics);
            }
        }
        $placement = [];
        foreach ($pages as $i => $canvas) {
            foreach ($canvas->fields() as $f) {
                if ($i + 1 > $authored && $f['origin'] === 'node') {
                    $f['origin'] = 'final_page';
                }
                $placement[] = ['id' => $f['id'], 'kind' => $f['kind'], 'signer' => $f['signer'], 'required' => $f['required'], 'origin' => $f['origin'], 'page' => $i + 1, 'rect' => $f['rect']];
            }
        }

        $file = new PdfFile(($this->options['compress'] ?? true) !== false);
        $catalog = $file->reserve();
        $tree = $file->reserve();

        $fontRefs = [];
        foreach ($fonts->used() as $name => $font) {
            $fontRefs[$name] = $font->emit($file);
        }
        // Only what a page drew: a band that no page ended up drawing (an even
        // header in a one-page document) was measured, and is not in the file.
        $drawn = [];
        foreach ($pages as $page) {
            foreach ($page->images() as $name) {
                $drawn[$name] = true;
            }
        }
        $imageRefs = $images->emit($file, $drawn);
        $resources = '<<';
        if ($fontRefs !== []) {
            $resources .= ' /Font <<';
            foreach ($fontRefs as $name => $num) {
                $resources .= ' '.Syntax::name($name).' '.$num.' 0 R';
            }
            $resources .= ' >>';
        }
        if ($imageRefs !== []) {
            $resources .= ' /XObject <<';
            foreach ($imageRefs as $name => $num) {
                $resources .= ' '.Syntax::name($name).' '.$num.' 0 R';
            }
            $resources .= ' >>';
        }
        $resources = $file->add($resources.' >>');

        $kids = [];
        foreach ($pages as $page) {
            $content = $file->stream('', $page->content());
            $annots = [];
            foreach ($page->links() as $link) {
                if (! Schema::linkAllowed($link['uri'])) {
                    continue;   // unreachable: the composer never makes one
                }
                $rect = implode(' ', array_map(fn (float $v) => Syntax::num($v), $link['rect']));
                $annots[] = $file->add('<< /Type /Annot /Subtype /Link /Rect ['.$rect.'] /Border [0 0 0] /F 4'
                    .' /A << /Type /Action /S /URI /URI '.Syntax::ascii($link['uri']).' >> >>');
            }
            $kids[] = $file->add('<< /Type /Page /Parent '.$tree.' 0 R /MediaBox [0 0 '.Syntax::num($page->width).' '.Syntax::num($page->height).']'
                .' /Resources '.$resources.' 0 R /Contents '.$content.' 0 R'
                .($annots !== [] ? ' /Annots ['.implode(' ', array_map(fn (int $a) => $a.' 0 R', $annots)).']' : '')
                .' >>');
        }
        $file->add('<< /Type /Pages /Kids ['.implode(' ', array_map(fn (int $k) => $k.' 0 R', $kids)).'] /Count '.count($kids).' >>', $tree);
        $file->add('<< /Type /Catalog /Pages '.$tree.' 0 R >>', $catalog);

        $info = '<< /Producer '.Syntax::text(self::PRODUCER);
        if (is_string($doc['title'] ?? null) && $doc['title'] !== '') {
            $info .= ' /Title '.Syntax::text($doc['title']);
        }
        if (is_string($doc['meta']['author'] ?? null) && $doc['meta']['author'] !== '') {
            $info .= ' /Author '.Syntax::text($doc['meta']['author']);
        }
        $info = $file->add($info.' >>');

        return [
            'bytes' => $file->output($catalog, $info),
            'pages' => count($pages),
            'diagnostics' => $diagnostics->all(),
            'fill' => $fill,
            'stranded' => $stranded,
            'fields' => $placement,
            'authoredPages' => $authored,
        ];
    }

    /** Mark sizes in points, by kind: [width, height]. */
    private const MARK_SIZES = ['initials' => [54.0, 22.0], 'signature' => [120.0, 26.0]];

    /**
     * One page's per-page marks: a box per signer in the bottom margin, right
     * aligned, under the body.
     *
     * @param  array<string, float>  $m
     * @param  array{kind: string, signers: list<array{id: string, label?: string}>}  $spec
     */
    private function pageMarks(Canvas $canvas, array $m, int $page, array $spec, Composer $composer, Diagnostics $diagnostics): void
    {
        $kind = $spec['kind'];
        [$w, $h] = self::MARK_SIZES[$kind];
        $room = $m['bottom'] - 8.0;
        if ($room < 12.0) {
            $diagnostics->add('page-mark-no-room', 'warning', 'page.margins.bottom', 'the bottom margin is too small for a per-page mark: it is drawn over the page edge, so make the margin at least 40 points', $page);
        }
        $h = max(12.0, min($h, $room));
        $top = $canvas->height - $m['bottom'] + 4.0;
        $right = $canvas->width - $m['right'];
        foreach ($spec['signers'] as $j => $signer) {
            $x = $right - ($j + 1) * $w - $j * 8.0;
            $canvas->field("page-{$page}-{$kind}-{$signer['id']}", $kind, $signer['id'], true, 'page_mark', $x, $top, $w, $h);
            $canvas->line($x, $top + $h, $x + $w, $top + $h, 0.5, [0.2, 0.2, 0.2]);
            $label = $signer['label'] ?? '';
            if ($label !== '') {
                foreach ($composer->bandLines($label, ['size' => 6], [], 'pageMarks') as $line) {
                    $canvas->text($line, $x, $top + $h + 1.0, $w, 'left');
                }
            }
        }
    }

    /** The package version, for anyone who wants it next to the Producer. */
    public static function version(): string
    {
        return Agent::VERSION;
    }
}
