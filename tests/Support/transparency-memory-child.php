<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use FinePrint\Budget;
use FinePrint\Exceptions\RefusedException;
use FinePrint\Limits;
use FinePrint\Render\ColourSpace;
use FinePrint\Render\Raster;
use FinePrint\Render\Renderer;
use FinePrint\Tests\Support\PdfBuilder;

try {
    if (in_array($argv[1] ?? '', ['late-group-row', 'late-page-row'], true)) {
        // Other live planes can consume headroom after shared blank rows were
        // created. First writes must preflight their copy-on-write row too.
        $budget = new Budget(new Limits);
        $raster = new Raster(200000, 2, $budget, transparentPage: true);
        if ($argv[1] === 'late-group-row') {
            $raster = Raster::group($raster, ColourSpace::rgb(), true, false, $budget);
        }
        $pressure = str_repeat("\0", max(0, 30 * 1024 * 1024 - memory_get_usage(true)));
        $raster->paint(0, 0, 1, [1.0, 0.0, 0.0], ColourSpace::rgb(), 1.0, 1.0, $budget);
        echo 'unexpected success';
        exit(2);
    }
    $mask = ($argv[1] ?? '') === 'mask';
    $b = PdfBuilder::textPdf([$mask ? '/M gs 0 0 1024 1024 re f' : '/G Do'], [
        'mediaBox' => '[0 0 1024 1024]',
        'resources' => $mask ? '/ExtGState << /M << /SMask << /S /Alpha /G 6 0 R >> >> >>' : '/XObject << /G 6 0 R >>',
    ]);
    $b->stream('0 0 1024 1024 re f', '/Subtype /Form /BBox [0 0 1024 1024] /Group << /S /Transparency /I true >>');
    Renderer::render($b->build(), limits: ['memoryBytes' => ($argv[1] ?? '') === 'configured' ? 12 * 1024 * 1024 : 256 * 1024 * 1024]);
    echo 'unexpected success';
    exit(2);
} catch (RefusedException $e) {
    echo $e->reason;
}
