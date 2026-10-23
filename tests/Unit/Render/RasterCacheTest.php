<?php

declare(strict_types=1);

use FinePrint\Budget;
use FinePrint\Limits;
use FinePrint\Exceptions\RefusedException;
use FinePrint\Render\RasterCache;
use FinePrint\Render\Renderer;

it('restores cached clean pixels and rendering metadata exactly', function () {
    $r = Renderer::render(fprPage('1 0 0 rg 10 10 30 30 re f /Shade sh'), scale: 2);
    $budget = new Budget(new Limits());
    $packet = RasterCache::encode($r, $budget);
    $restored = RasterCache::decode($packet, $budget);
    expect($restored)->not->toBeNull()->and($restored->rows())->toBe($r->rows())
        ->and([$restored->width, $restored->height, $restored->page, $restored->scale, $restored->rotate])->toBe([$r->width, $r->height, 1, 2.0, 0])
        ->and($restored->skipped)->toBe($r->skipped);
});

it('rejects damaged or unknown cache packets', function (Closure $change) {
    $budget = new Budget(new Limits());
    $packet = RasterCache::encode(Renderer::render(fprPage('')), $budget);
    expect(RasterCache::decode($change($packet), $budget))->toBeNull();
})->with([
    'empty' => [fn (string $p) => ''],
    'truncated' => [fn (string $p) => substr($p, 0, -5)],
    'unknown version' => [fn (string $p) => 'BAD!'.substr($p, 4)],
    'changed byte' => [function (string $p) { $p[20] = chr(ord($p[20]) ^ 255); return $p; }],
]);

it('checks forged cache dimensions before inflating or splitting rows', function () {
    $budget = new Budget(new Limits(maxRenderPixels: 10000));
    $packet = RasterCache::encode(Renderer::render(fprPage('')), $budget);
    $body = substr($packet, 0, -32);
    $body = substr_replace($body, pack('NN', 0x7FFFFFFF, 0x7FFFFFFF), 4, 8);
    $forged = $body.hash('sha256', $body, true);
    expect(RasterCache::decode($forged, $budget))->toBeNull();
});

it('bounds expansion of a checksum-valid cache bomb by the declared raster size', function () {
    $budget = new Budget(new Limits());
    $packet = RasterCache::encode(Renderer::render(fprPage('', '[0 0 1 1]')), $budget);
    $metaLength = unpack('N', substr($packet, 12, 4))[1];
    $body = substr($packet, 0, 16 + $metaLength).gzcompress(str_repeat('x', 4 * 1024 * 1024));
    $forged = $body.hash('sha256', $body, true);
    expect(RasterCache::decode($forged, $budget))->toBeNull();
});

it('propagates the request budget instead of treating exhaustion as a cache miss', function () {
    $packet = RasterCache::encode(Renderer::render(fprPage('')), new Budget(new Limits()));
    expect(fn () => RasterCache::decode($packet, new Budget(new Limits(timeSeconds: 0))))->toThrow(RefusedException::class);
});
