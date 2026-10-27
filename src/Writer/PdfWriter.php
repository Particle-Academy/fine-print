<?php

declare(strict_types=1);

namespace FinePrint\Writer;

use FinePrint\Agent;
use FinePrint\Writer\Fonts\FontBook;
use FinePrint\Writer\Images\ImageStore;
use FinePrint\Writer\Layout\Canvas;
use FinePrint\Writer\Layout\Composer;
use FinePrint\Writer\Layout\Diagnostics;
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

        [$pw, $ph] = Schema::pageSize($doc);
        $m = Schema::margins($doc);
        $frameWidth = $pw - $m['left'] - $m['right'];
        $bodyHeight = $ph - $m['top'] - $m['bottom'];

        $composer = new Composer($fonts, $images, $diagnostics, $doc, $bodyHeight);
        $flow = $composer->compose(is_array($doc['blocks'] ?? null) ? $doc['blocks'] : [], $frameWidth);

        // Page decoration goes first on every page, so all content paints over
        // it, and it is sized by the page, never by what the page holds.
        $background = is_array($doc['page'] ?? null) && is_string($doc['page']['background'] ?? null)
            && preg_match('/^#[0-9A-Fa-f]{6}$/', $doc['page']['background']) === 1 ? Syntax::rgb($doc['page']['background']) : null;
        $frame = Schema::pageFrame($doc);
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
        $paginator = new Paginator($m['left'], $m['top'], $ph - $m['bottom'], $newCanvas, $diagnostics);
        $pages = $paginator->paginate($flow);

        $fill = [];
        foreach ($pages as $page) {
            $fill[] = $bodyHeight > 0 ? max(0.0, min(1.0, ($page->bodyBottom - $m['top']) / $bodyHeight)) : 0.0;
        }
        $this->bands($doc, $pages, $composer, $m, $pw, $ph);

        $file = new PdfFile(($this->options['compress'] ?? true) !== false);
        $catalog = $file->reserve();
        $tree = $file->reserve();

        $fontRefs = [];
        foreach ($fonts->used() as $name => $font) {
            $fontRefs[$name] = $font->emit($file);
        }
        $imageRefs = $images->emit($file);
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
            $kids[] = $file->add('<< /Type /Page /Parent '.$tree.' 0 R /MediaBox [0 0 '.Syntax::num($pw).' '.Syntax::num($ph).']'
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
        $info = $file->add($info.' >>');

        return [
            'bytes' => $file->output($catalog, $info),
            'pages' => count($pages),
            'diagnostics' => $diagnostics->all(),
            'fill' => $fill,
            'stranded' => $paginator->strandedKeeps(),
        ];
    }

    /**
     * Running headers and footers, drawn once the page count is known.
     *
     * @param  array<string, mixed>  $doc
     * @param  list<Canvas>  $pages
     * @param  array{top: float, right: float, bottom: float, left: float}  $m
     */
    private function bands(array $doc, array $pages, Composer $composer, array $m, float $pw, float $ph): void
    {
        $total = count($pages);
        foreach (['header', 'footer'] as $kind) {
            $band = $doc['page'][$kind] ?? null;
            if (! is_array($band)) {
                continue;
            }
            foreach ($pages as $i => $canvas) {
                foreach (['left', 'center', 'right'] as $slot) {
                    if (! is_string($band[$slot] ?? null) || $band[$slot] === '') {
                        continue;
                    }
                    $text = str_replace(['{page}', '{pages}'], [(string) ($i + 1), (string) $total], $band[$slot]);
                    $line = $composer->bandLine($text, $band, "page.{$kind}.{$slot}");
                    $top = $kind === 'header' ? 36.0 : $ph - 36.0 - $line->height;
                    $x = match ($slot) {
                        'left' => $m['left'],
                        'center' => ($pw - $line->width) / 2,
                        'right' => $pw - $m['right'] - $line->width,
                    };
                    $canvas->text($line, $x, $top, $line->width + 1, 'left');
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
