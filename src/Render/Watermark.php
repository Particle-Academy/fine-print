<?php

declare(strict_types=1);

namespace FinePrint\Render;

use FinePrint\Agent;
use FinePrint\Budget;
use FinePrint\Document;
use FinePrint\Exceptions\RefusedException;
use FinePrint\Objects\PdfDict;
use FinePrint\Objects\PdfName;
use InvalidArgumentException;

/** Burned-in tiled text on a COPY; the clean page is safe to share in a cache. */
final class Watermark
{
    public static function apply(Rendering $clean, array $lines, Budget $budget): Rendering
    {
        $budget->check();
        if ($lines === [] || count($lines) > 3) {
            throw new InvalidArgumentException('A watermark needs one to three text lines.');
        }
        $encoded = [];
        foreach ($lines as $line) {
            if (! is_string($line)) {
                throw new InvalidArgumentException('Watermark lines must be non-empty strings.');
            }
            $line = mb_strcut($line, 0, 512, 'UTF-8');
            $line = trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', $line));
            if ($line === '') {
                throw new InvalidArgumentException('A bounded watermark line must contain visible text.');
            }
            $encoded[] = mb_convert_encoding(mb_substr($line, 0, 96, 'UTF-8'), 'Windows-1252', 'UTF-8');
        }
        $budget->allocation((float) $clean->width * $clean->height * 3 + (float) $clean->height * 96);
        $raster = new Raster($clean->width, $clean->height, $budget, $clean->rows());
        $size = 12.0 * $clean->scale;
        $gap = 80.0 * $clean->scale;
        $stepX = max(120.0 * $clean->scale, max(array_map('strlen', $encoded)) * $size + $gap);
        $stepY = count($encoded) * $size * 1.3 + $gap;
        $diagonal = hypot($clean->width, $clean->height);
        $nx = (int) ceil($diagonal / $stepX);
        $ny = (int) ceil($diagonal / $stepY);
        if ((2 * $nx + 1) * (2 * $ny + 1) > 4096) {
            throw new RefusedException('too_many_operators', 'Watermark tiling exceeds its operation limit.');
        }
        // A fresh tiny first-party document gives the interpreter a raw host
        // context. It contains no uploaded PDF resources or scripts.
        $doc = Document::open(Agent::toBytes(['blocks' => []]), $budget->limits->with(['timeSeconds' => $budget->remainingSeconds()]));
        $resources = new PdfDict([
            'Font' => new PdfDict(['Watermark' => new PdfDict(['Subtype' => new PdfName('Type1'),
                'BaseFont' => new PdfName('Helvetica'), 'Encoding' => new PdfName('WinAnsiEncoding')])]),
            'ExtGState' => new PdfDict(['Fade' => new PdfDict(['ca' => 0.16])]),
        ]);
        $interpreter = new Interpreter($doc, $raster, $budget, [1.0, 0.0, 0.0, 1.0, 0.0, 0.0]);
        $c = cos(-M_PI / 6);
        $s = sin(-M_PI / 6);
        $content = 'q /Fade gs 0.4 g BT /Watermark '.sprintf('%.5F', $size).' Tf ';
        for ($iy = -$ny; $iy <= $ny; $iy++) {
            for ($ix = -$nx; $ix <= $nx; $ix++) {
                $budget->tick();
                foreach ($encoded as $line => $bytes) {
                    $x = $ix * $stepX;
                    $y = $iy * $stepY + $line * $size * 1.3;
                    $dx = $c * $x - $s * $y + $clean->width / 2;
                    $dy = $s * $x + $c * $y + $clean->height / 2;
                    $content .= sprintf('%.8F %.8F %.8F %.8F %.5F %.5F Tm <%s> Tj ', $c, $s, $s, -$c, $dx, $dy, bin2hex($bytes));
                }
            }
        }
        $interpreter->run($content.' ET Q', $resources);
        $budget->check();
        if ($interpreter->skipped() !== []) {
            throw new RefusedException('corrupt', 'A required watermark could not be drawn.');
        }

        return new Rendering($raster, $clean->page, $clean->scale, $clean->rotate, $clean->skipped,
            $clean->warnings, $clean->operators + $interpreter->operators(), $clean->width, $clean->height);
    }
}
