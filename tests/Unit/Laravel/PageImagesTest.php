<?php

declare(strict_types=1);

use FinePrint\Budget;
use FinePrint\Laravel\PageImages;
use FinePrint\Laravel\PageSource;
use FinePrint\Limits;
use FinePrint\Render\Raster;
use FinePrint\Render\RasterCache;
use FinePrint\Render\Rendering;
use FinePrint\Tests\Support\PdfBuilder;
use FinePrint\Tests\Support\PngReader;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class FpImageAuthMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $request->user() === null ? new Response('', 401) : $next($request);
    }
}

class FpCountingImageStore extends ArrayStore
{
    public int $writes = 0;

    public function put($key, $value, $seconds)
    {
        $this->writes++;

        return parent::put($key, $value, $seconds);
    }
}

function fplApp(Closure $resolver, array $options = []): array
{
    $container = new Container;
    Container::setInstance($container);
    $container->instance(Container::class, $container);
    $router = new Router(new Dispatcher($container), $container);
    $router->aliasMiddleware('auth', FpImageAuthMiddleware::class);
    $store = new FpCountingImageStore;
    $cache = new Repository($store);
    PageImages::register($router, '/docs/{file}/{page}.png', $resolver, $cache, $options);

    return [$router, $container, $store, $cache];
}

function fplGet(array $app, string $url = '/docs/one/1.png', ?string $viewer = 'Alex'): Response
{
    [$router, $container] = $app;
    $request = Request::create($url);
    $request->setUserResolver(fn () => $viewer === null ? null : (object) ['id' => $viewer, 'name' => $viewer, 'email' => strtolower($viewer).'@example.test']);
    // These are the same request/container bindings Laravel's HTTP kernel supplies.
    $container->instance('request', $request);
    $container->instance(Request::class, $request);
    try {
        return $router->dispatch($request);
    } catch (HttpExceptionInterface $e) {
        return new Response('', $e->getStatusCode());
    }
}

function fplSource(string $pdf, string $version = '1', ?array $lines = null, string $key = 'tenant:one'): PageSource
{
    return new PageSource($pdf, $key, $version, $lines);
}

it('runs app authentication before resolving or looking up cached pages', function () {
    $calls = 0;
    $app = fplApp(function () use (&$calls) {
        $calls++;

        return fplSource(fprPage(''));
    });
    expect(fplGet($app, viewer: null)->getStatusCode())->toBe(401)
        ->and($calls)->toBe(0)->and($app[2]->writes)->toBe(0);
});

it('does not reuse the old renderer omission packet even in a custom cache namespace', function () {
    $pdf = fprPage('/G Do', resources: '/XObject << /G 6 0 R >>', extra: fn (PdfBuilder $b) => $b->stream('1 0 0 rg 0 0 100 100 re f', '/Subtype /Form /BBox [0 0 100 100] /Group << /S /Transparency >>'));
    $source = fplSource($pdf);
    $app = fplApp(fn () => $source, ['namespace' => 'host-pages']);
    // Published v0.1.0 cache identity and its stale transparency omission.
    $limits = new Limits(maxFileBytes: 32 * 1024 * 1024, maxRenderPixels: 8_000_000, timeSeconds: 3.0, renderTimeSeconds: 3.0);
    $oldKey = 'host-pages:'.hash('sha256', json_encode([hash('sha256', $source->key), hash('sha256', $source->version),
        hash('sha256', $pdf), 1, 1.0, get_object_vars($limits)], JSON_THROW_ON_ERROR));
    $stale = new Rendering(new Raster(100, 100), 1, 1.0, 0, [['kind' => 'transparency', 'count' => 1]], [], 1, 100, 100);
    $app[3]->put($oldKey, RasterCache::encode($stale, new Budget($limits)), 300);
    $response = fplGet($app);
    expect($response->getStatusCode())->toBe(200)->and($response->headers->get('X-FinePrint-Complete'))->toBe('1')
        ->and($app[2]->writes)->toBe(2);
});

it('returns only a private non-cacheable PNG with a server watermark', function () {
    $app = fplApp(fn () => fplSource(fprPage('', '[0 0 400 300]'), lines: ['Alex', 'alex@example.test', '2026-10-22 UTC']));
    $r = fplGet($app);
    expect($r->getStatusCode())->toBe(200)->and($r->headers->get('Content-Type'))->toBe('image/png')
        ->and($r->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and($r->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(str_starts_with($r->getContent(), "\x89PNG"))->toBeTrue()
        ->and($r->getContent())->not->toContain('%PDF-');
});

it('shares a clean raster cache while rendering each viewer identity separately', function () {
    $calls = 0;
    $app = fplApp(function (Request $request) use (&$calls) {
        $calls++;

        return fplSource(fprPage('', '[0 0 400 300]'), lines: [$request->user()->email]);
    });
    $a = fplGet($app, viewer: 'Alex');
    $b = fplGet($app, viewer: 'Riley');
    expect([$a->getStatusCode(), $b->getStatusCode()])->toBe([200, 200])
        ->and($app[2]->writes)->toBe(1)->and($calls)->toBe(2)
        ->and($a->getContent())->not->toBe($b->getContent());
});

it('reauthorizes a file even on a warm cache', function () {
    $allowed = true;
    $app = fplApp(function () use (&$allowed) {
        return $allowed ? fplSource(fprPage('')) : null;
    }, ['watermark' => false]);
    expect(fplGet($app)->getStatusCode())->toBe(200);
    $allowed = false;
    expect(fplGet($app)->getStatusCode())->toBe(404)->and($app[2]->writes)->toBe(1);
});

it('keys the cache by file identity, server version and content', function () {
    $version = '1';
    $pdf = fprPage('');
    $key = 'tenant:one';
    $app = fplApp(function () use (&$version, &$pdf, &$key) {
        return fplSource($pdf, $version, key: $key);
    }, ['watermark' => false]);
    expect(fplGet($app)->getStatusCode())->toBe(200);
    expect(fplGet($app, '/docs/one/1.png?version=client-override')->getStatusCode())->toBe(200);
    expect($app[2]->writes)->toBe(1);
    $version = '2';
    expect(fplGet($app)->getStatusCode())->toBe(200);
    $key = 'tenant:two';
    expect(fplGet($app)->getStatusCode())->toBe(200);
    $pdf = fprPage('0 0 100 100 re f');
    expect(fplGet($app)->getStatusCode())->toBe(200)->and($app[2]->writes)->toBe(4);
});

it('canonicalizes nearby scales to a bounded quarter-step cache key', function () {
    $app = fplApp(fn () => fplSource(fprPage('')), ['watermark' => false]);
    $a = fplGet($app, '/docs/one/1.png?scale=1.01');
    $b = fplGet($app, '/docs/one/1.png?scale=1.02');
    expect($a->getStatusCode())->toBe(200)->and($b->getContent())->toBe($a->getContent())
        ->and($app[2]->writes)->toBe(1)->and(PngReader::read($a->getContent())['width'])->toBe(100);
});

it('refuses invalid client scale parameters cleanly', function (string $query) {
    $app = fplApp(fn () => fplSource(fprPage('')), ['watermark' => false]);
    expect(fplGet($app, '/docs/one/1.png?'.$query)->getStatusCode())->toBe(400)->and($app[2]->writes)->toBe(0);
})->with(['scale=nan', 'scale=1e300', 'scale=0', 'scale=-1', 'scale=5', 'scale[]=1']);

it('refuses invalid or out-of-range page numbers', function (string $page) {
    $app = fplApp(fn () => fplSource(fprPage('')), ['watermark' => false]);
    expect(fplGet($app, '/docs/one/'.$page.'.png')->getStatusCode())->toBe(404);
})->with(['0', '-1', '2', '999999999999999999999999']);

it('refuses oversized or corrupt documents without exposing their content', function () {
    $app = fplApp(fn () => fplSource(str_repeat('private-secret', 1000)), ['limits' => ['maxFileBytes' => 5000]]);
    $large = fplGet($app);
    expect($large->getStatusCode())->toBe(413)->and($large->getContent())->not->toContain('private-secret');
    $app = fplApp(fn () => fplSource('private-secret-not-a-pdf'));
    $bad = fplGet($app);
    expect($bad->getStatusCode())->toBe(422)->and($bad->getContent())->not->toContain('private-secret');
});

it('uses a short render budget and does not emit a partially timed-out page', function () {
    $app = fplApp(fn () => fplSource(fprPage('')), ['limits' => ['renderTimeSeconds' => 0.000000001]]);
    $r = fplGet($app);
    expect($r->getStatusCode())->toBe(422)->and($r->getContent())->toContain('time_budget');
});

it('refuses incomplete pages by default and allows an explicit host opt-in', function () {
    $source = fn () => fplSource(fprPage('/UnknownShading sh'));
    expect(fplGet(fplApp($source, ['watermark' => false]))->getStatusCode())->toBe(422);
    $r = fplGet(fplApp($source, ['watermark' => false, 'requireComplete' => false]));
    expect($r->getStatusCode())->toBe(200)->and($r->headers->get('X-FinePrint-Complete'))->toBe('0');
});

it('keeps route descriptors free of captured resolvers or cache objects for route caching', function () {
    $app = fplApp(fn () => fplSource(fprPage('')), ['watermark' => false]);
    foreach ($app[0]->getRoutes() as $route) {
        $route->prepareForSerialization();
    }
    $compiled = $app[0]->getRoutes()->compile();
    expect(json_encode($compiled, JSON_THROW_ON_ERROR))->not->toContain('SerializableClosure', 'FpCountingImageStore');
    $new = fplApp(fn () => fplSource(fprPage('')), ['watermark' => false]);
    $new[0]->setCompiledRoutes($compiled);
    expect(fplGet($new)->getStatusCode())->toBe(200);
});

it('never rounds a scale above the configured maximum', function () {
    $app = fplApp(fn () => fplSource(fprPage('')), ['watermark' => false, 'maxScale' => 1.4]);
    $r = fplGet($app, '/docs/one/1.png?scale=1.4');
    expect($r->getStatusCode())->toBe(200)->and(PngReader::read($r->getContent())['width'])->toBe(125);
});

it('judges serving dimensions from the actual crop intersection', function () {
    $app = fplApp(fn () => fplSource(fprPage('', page: '/CropBox [-1000000 -1000000 1000000 1000000]')), ['watermark' => false, 'maxDimension' => 200]);
    $r = fplGet($app);
    expect($r->getStatusCode())->toBe(200)->and(PngReader::read($r->getContent())['width'])->toBe(100);
});

it('accepts binary server version hashes without JSON or cache-key errors', function () {
    $app = fplApp(fn () => fplSource(fprPage(''), hash('sha256', 'revision', true)), ['watermark' => false]);
    expect(fplGet($app)->getStatusCode())->toBe(200)->and(fplGet($app)->getStatusCode())->toBe(200)
        ->and($app[2]->writes)->toBe(1);
});

it('turns a bounded source-read refusal into a clean HTTP error', function () {
    $app = fplApp(function () {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, str_repeat('secret', 100));
        rewind($stream);
        try {
            return PageSource::fromStream($stream, 'key', 'version', maxBytes: 100);
        } finally {
            fclose($stream);
        }
    });
    $r = fplGet($app);
    expect($r->getStatusCode())->toBe(413)->and($r->getContent())->not->toContain('secret');
});

it('uses the server request timestamp for the default watermark', function () {
    $time = 1700000000;
    $pdf = fprPage('', '[0 0 400 300]');
    $reference = fplApp(fn () => fplSource($pdf, lines: ['Alex', 'alex@example.test', gmdate('Y-m-d H:i:s', $time).' UTC']));
    $expected = fplGet($reference)->getContent();
    $app = fplApp(fn () => fplSource($pdf));
    $request = Request::create('/docs/one/1.png', server: ['REQUEST_TIME' => $time]);
    $request->setUserResolver(fn () => (object) ['id' => 42, 'name' => 'Alex', 'email' => 'alex@example.test']);
    $app[1]->instance('request', $request);
    $app[1]->instance(Request::class, $request);
    expect($app[0]->dispatch($request)->getContent())->toBe($expected);
});

it('keeps identities distinct when authenticated users have names but no email', function () {
    $app = fplApp(fn () => fplSource(fprPage('', '[0 0 400 300]')));
    $bodies = [];
    foreach ([42, 43] as $id) {
        $request = Request::create('/docs/one/1.png', server: ['REQUEST_TIME' => 1700000000]);
        $request->setUserResolver(fn () => (object) ['id' => $id, 'name' => 'Alex']);
        $app[1]->instance('request', $request);
        $app[1]->instance(Request::class, $request);
        $bodies[] = $app[0]->dispatch($request)->getContent();
    }
    expect($bodies[0])->not->toBe($bodies[1])->and($app[2]->writes)->toBe(1);
});
