<?php

declare(strict_types=1);

namespace FinePrint\Render;

use FinePrint\Budget;
use FinePrint\Document;
use FinePrint\Exceptions\RefusedException;
use FinePrint\Limits;
use FinePrint\Objects\PdfDict;
use FinePrint\Parser\PdfSyntaxException;
use InvalidArgumentException;

/**
 * Renders one PDF page to pixels, in pure PHP. For HOSTS: the output is an
 * image of attacker-authored content and is never handed to an agent as text.
 *
 *     $png = Renderer::png($pdfBytes, page: 1, scale: 2.0);
 *
 * `scale` is device pixels per PDF point: 1.0 is 72 DPI, 2.0 is 144 DPI. The
 * page shown is the CropBox (cut to the MediaBox), turned by /Rotate, on a
 * white background, as viewers show it.
 *
 * Drawn: vector paths (fills, strokes, dashes, colour, transforms, form
 * XObjects), TrueType text, sampled/baseline JPEG images, clipping, constant
 * alpha, image masks and sampled/exponential alternate-colour tint functions.
 * NOT drawn yet, and
 * listed in {@see Rendering::$skipped}: text in fonts that are not embedded
 * or are CFF / Type 1 / Type 3, unsupported image codecs, shading, patterns,
 * transparency groups, blend modes and ExtGState soft masks.
 *
 * Bounded by {@see Limits}: the raster's pixel count is judged before it is
 * allocated (`maxRenderPixels`), every operator counts (`maxOperators`), forms
 * nest at most `maxFormDepth`, and the render has its own wall-clock budget
 * (`renderTimeSeconds`) and memory guard (`memoryBytes`). A breach is a
 * {@see RefusedException}, never a partial image presented as a whole one.
 */
final class Renderer
{
    /**
     * @param  string|Document  $pdf  PDF bytes, or a document already open
     * @param  Limits|array<string, int|float>|null  $limits  for bytes: every limit; for a Document: the render limits
     *                                                       (null keeps the document's own)
     *
     * @throws RefusedException
     * @throws InvalidArgumentException for a scale that is not a positive finite number
     * @throws \OutOfRangeException for a page that does not exist
     */
    public static function render(string|Document $pdf, int $page = 1, float $scale = 1.0, Limits|array|null $limits = null): Rendering
    {
        if (! is_finite($scale) || $scale <= 0.0) {
            throw new InvalidArgumentException('The scale must be a positive, finite number.');
        }
        if ($pdf instanceof Document) {
            $doc = $pdf;
            $limits = $limits === null ? $doc->budget()->limits : Limits::from($limits);
        } else {
            $limits = Limits::from($limits);
            try {
                $doc = Document::open($pdf, $limits);
            } catch (PdfSyntaxException) {
                throw new RefusedException('corrupt', 'The file could not be parsed.');
            }
        }

        try {
            return self::draw($doc, $page, $scale, $limits);
        } catch (PdfSyntaxException) {
            throw new RefusedException('corrupt', 'The file could not be parsed.');
        }
    }

    /**
     * The page as PNG bytes.
     *
     * @param  Limits|array<string, int|float>|null  $limits
     *
     * @throws RefusedException
     */
    public static function png(string|Document $pdf, int $page = 1, float $scale = 1.0, Limits|array|null $limits = null): string
    {
        return self::render($pdf, $page, $scale, $limits)->png();
    }

    private static function draw(Document $doc, int $number, float $scale, Limits $limits): Rendering
    {
        $p = $doc->page($number);

        // The visible box: CropBox cut to the MediaBox (ISO 32000-1 14.11.2).
        [$mx0, $my0, $mx1, $my1] = $p['mediaBox'];
        [$cx0, $cy0, $cx1, $cy1] = $p['cropBox'];
        $x0 = max($mx0, $cx0);
        $y0 = max($my0, $cy0);
        $x1 = min($mx1, $cx1);
        $y1 = min($my1, $cy1);
        if ($x1 <= $x0 || $y1 <= $y0) {
            [$x0, $y0, $x1, $y1] = [$mx0, $my0, $mx1, $my1];
        }
        $rotate = $p['rotate'];
        $wPt = $x1 - $x0;
        $hPt = $y1 - $y0;
        if ($rotate % 180 === 90) {
            [$wPt, $hPt] = [$hPt, $wPt];
        }

        // Judge the pixel count as floats, BEFORE any int conversion or allocation.
        $wf = max(1.0, round($wPt * $scale));
        $hf = max(1.0, round($hPt * $scale));
        if (! is_finite($wf * $hf) || $wf * $hf > $limits->maxRenderPixels) {
            throw new RefusedException('too_many_pixels', sprintf(
                'The page would render at %.0f x %.0f pixels, over the %d-pixel limit.',
                $wf, $hf, $limits->maxRenderPixels,
            ));
        }
        $w = (int) $wf;
        $h = (int) $hf;

        $s = $scale;
        $base = match ($rotate) {
            90 => [0.0, $s, $s, 0.0, -$s * $y0, -$s * $x0],
            180 => [-$s, 0.0, 0.0, $s, $s * $x1, -$s * $y0],
            270 => [0.0, -$s, -$s, 0.0, $s * $y1, $s * $x1],
            default => [$s, 0.0, 0.0, -$s, -$s * $x0, $s * $y1],
        };

        $budget = new Budget($limits->with(['timeSeconds' => $limits->renderTimeSeconds]), 'Rendering');
        $content = $doc->pageContents($number);
        $raster = new Raster($w, $h, $budget);
        $budget->check();

        $interp = new Interpreter($doc, $raster, $budget, $base);
        $interp->run($content, $p['resources']);
        self::annotations($doc, $p['dict'], $interp);

        $skipped = $interp->skipped();
        ksort($skipped);
        $fonts = $interp->skippedFonts();
        $list = [];
        foreach ($skipped as $kind => $count) {
            $entry = ['kind' => $kind, 'count' => $count];
            if (isset($fonts[$kind])) {
                $entry['fonts'] = $fonts[$kind];
            }
            $list[] = $entry;
        }

        return new Rendering($raster, $number, $scale, $rotate, $list, $doc->warnings(), $interp->operators(), $w, $h);
    }

    /** Annotations carrying an appearance a viewer would draw: counted, not drawn (yet). */
    private static function annotations(Document $doc, PdfDict $page, Interpreter $interp): void
    {
        $annots = $doc->resolve($page->get('Annots'));
        if (! is_array($annots)) {
            return;
        }
        foreach (array_slice($annots, 0, 10_000) as $a) {
            $a = $doc->resolve($a);
            if ($a instanceof PdfDict && $a->has('AP')) {
                $interp->skip('annotation');
            }
        }
    }
}
