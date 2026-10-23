<?php

declare(strict_types=1);

// Deliberately NO vendor/autoload.php: framework test dependencies must not
// conceal a runtime dependency in the core library.
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'FinePrint\\')) {
        $path = dirname(__DIR__, 2).'/src/'.str_replace('\\', '/', substr($class, 10)).'.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

use FinePrint\Agent;
use FinePrint\Budget;
use FinePrint\Limits;
use FinePrint\Render\RasterCache;
use FinePrint\Render\Renderer;
use FinePrint\Render\Watermark;

$pdf = Agent::toBytes(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Core without a framework']]]]]);
$read = Agent::read($pdf);
$r = Renderer::render($pdf);
$budget = new Budget(new Limits());
$cached = RasterCache::decode(RasterCache::encode($r, $budget), $budget);
$marked = Watermark::apply($cached, ['viewer@example.test'], $budget);
if (class_exists('Illuminate\\Http\\Request', false) || ! $read['ok'] || ! str_starts_with($marked->png(budget: $budget), "\x89PNG")) {
    exit(2);
}
echo 'core-without-framework-ok';
