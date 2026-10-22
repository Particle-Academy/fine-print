<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use FinePrint\Exceptions\RefusedException;
use FinePrint\Render\Renderer;
use FinePrint\Tests\Support\PdfBuilder;

try {
    $hard = ($argv[1] ?? '') === 'hard';
    $pdf = PdfBuilder::textPdf([$hard ? '0 0 2048 2048 re f' : ''], ['mediaBox' => $hard ? '[0 0 2048 2048]' : '[0 0 1 4000000]'])->build();
    Renderer::render($pdf, limits: ['memoryBytes' => ($hard ? 256 : 24) * 1024 * 1024]);
    echo 'unexpected success';
    exit(2);
} catch (RefusedException $e) {
    echo $e->reason;
}
