<?php

declare(strict_types=1);

namespace FinePrint\Laravel;

use Closure;
use FinePrint\Budget;
use FinePrint\Document;
use FinePrint\Exceptions\RefusedException;
use FinePrint\Limits;
use FinePrint\Render\RasterCache;
use FinePrint\Render\Renderer;
use FinePrint\Render\Watermark;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use InvalidArgumentException;

/** Optional Laravel adapter. No framework type is loaded by the core package. */
final class PageImages
{
    private readonly Limits $limits;
    private readonly float $maxScale;
    private readonly int $maxDimension;
    private readonly int $ttl;
    private readonly string $namespace;
    private readonly bool $watermark;
    private readonly bool $requireComplete;

    private function __construct(private readonly Closure $resolve, private readonly Repository $cache, array $options)
    {
        $this->limits = (new Limits(maxFileBytes: 32 * 1024 * 1024, maxRenderPixels: 8_000_000,
            timeSeconds: 3.0, renderTimeSeconds: 3.0))->with($options['limits'] ?? []);
        $this->maxScale = (float) ($options['maxScale'] ?? 4.0);
        $this->maxDimension = $options['maxDimension'] ?? 8192;
        $this->ttl = $options['ttl'] ?? 300;
        $this->namespace = $options['namespace'] ?? 'fine-print.raster.v1';
        $this->watermark = $options['watermark'] ?? true;
        $this->requireComplete = $options['requireComplete'] ?? true;
        if (! is_finite($this->maxScale) || $this->maxScale < 0.25 || $this->maxDimension < 1
            || $this->ttl < 1 || $this->ttl > 86400 || $this->namespace === '' || strlen($this->namespace) > 128) {
            throw new InvalidArgumentException('Invalid page-image serving options.');
        }
    }

    /** Register in a service provider's boot(), so bindings also exist with cached routes. */
    public static function register(Router $router, string $path, Closure $resolve, Repository $cache, array $options = []): Route
    {
        if (! preg_match('/\{file(?::[^}?]+)?\}/', $path) || ! str_contains($path, '{page}') || str_contains($path, '_finePrintPages')) {
            throw new InvalidArgumentException('A page-image route needs {file} and {page} parameters.');
        }
        $handler = new self($resolve, $cache, $options);
        $route = $router->get($path, [PageImageController::class, 'show']);
        $binding = 'fine-print.pages.'.hash('sha256', ($route->getDomain() ?? '').'|'.$route->uri());
        Container::getInstance()->instance($binding, $handler);
        $route->defaults('_finePrintPages', $binding)->where('page', '[1-9][0-9]{0,5}');
        $middleware = $options['middleware'] ?? ['auth'];

        return $route->middleware($middleware);
    }

    public function response(Request $request): Response
    {
        // The app checks its policy EVERY time, including warm-cache requests.
        // The resolver may return null for an inaccessible/nonexistent file.
        try {
            $source = ($this->resolve)($request, $request->route('file'));
        } catch (RefusedException $e) {
            return self::refused($e);
        }
        if ($source === null) {
            return new Response('', 404);
        }
        if (! $source instanceof PageSource) {
            throw new InvalidArgumentException('The page resolver must return PageSource or null.');
        }
        $page = (int) $request->route('page');
        if ($page < 1 || $page > $this->limits->maxPages) {
            return new Response('', 404);
        }
        $rawScale = $request->query('scale', '1');
        if ((! is_string($rawScale) && ! is_int($rawScale) && ! is_float($rawScale))
            || strlen((string) $rawScale) > 24 || ! preg_match('/^(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D', (string) $rawScale)) {
            return self::error('invalid_scale', 400);
        }
        $number = (float) $rawScale;
        if (! is_finite($number) || $number < 0.25 || $number > $this->maxScale) {
            return self::error('invalid_scale', 400);
        }
        $scale = min(floor($this->maxScale * 4) / 4, round($number * 4) / 4);
        $budget = new Budget($this->limits->with(['timeSeconds' => $this->limits->renderTimeSeconds]), 'Serving a PDF page');
        try {
            $budget->check();
            if (strlen($source->bytes) > $this->limits->maxFileBytes) {
                throw new RefusedException('too_large', 'The PDF exceeds the serving file limit.');
            }
            $key = $this->namespace.':'.hash('sha256', json_encode([hash('sha256', $source->key), hash('sha256', $source->version),
                hash('sha256', $source->bytes), $page, $scale, get_object_vars($this->limits)], JSON_THROW_ON_ERROR));
            $budget->check();
            $packet = $this->cache->get($key);
            $rendering = is_string($packet) ? RasterCache::decode($packet, $budget) : null;
            if ($rendering !== null && ($rendering->page !== $page || $rendering->scale !== $scale
                || $rendering->width > $this->maxDimension || $rendering->height > $this->maxDimension)) {
                $rendering = null;
            }
            if ($rendering === null) {
                $doc = Document::open($source->bytes, $this->limits->with(['timeSeconds' => $budget->remainingSeconds(), 'memoryBytes' => $budget->remainingMemory()]));
                if ($page > $doc->pageCount()) {
                    return new Response('', 404);
                }
                // Renderer judges pixels before allocation; a dimension cap
                // additionally bounds per-row work at a serving endpoint.
                $info = $doc->pages()[$page - 1];
                [$mx0, $my0, $mx1, $my1] = $info['mediaBox'];
                [$cx0, $cy0, $cx1, $cy1] = $info['cropBox'];
                $x0 = max($mx0, $cx0);
                $y0 = max($my0, $cy0);
                $x1 = min($mx1, $cx1);
                $y1 = min($my1, $cy1);
                if ($x1 <= $x0 || $y1 <= $y0) {
                    [$x0, $y0, $x1, $y1] = [$mx0, $my0, $mx1, $my1];
                }
                if (max($x1 - $x0, $y1 - $y0) * $scale > $this->maxDimension) {
                    throw new RefusedException('too_many_pixels', 'A page exceeds the serving dimension limit.');
                }
                $rendering = Renderer::render($doc, $page, $scale, $this->limits->with([
                    'renderTimeSeconds' => $budget->remainingSeconds(), 'memoryBytes' => $budget->remainingMemory(),
                ]));
                $budget->check();
                $this->cache->put($key, RasterCache::encode($rendering, $budget), $this->ttl);
            }
            if ($this->requireComplete && ! $rendering->complete()) {
                return self::error('pdf_render_incomplete', 422);
            }
            if ($this->watermark) {
                $rendering = Watermark::apply($rendering, $source->watermark ?? self::viewerLines($request), $budget);
            }
            $png = $rendering->png(budget: $budget);
            $budget->check();

            return new Response($png, 200, [
                'Content-Type' => 'image/png', 'Content-Disposition' => 'inline; filename="page-'.$page.'.png"',
                'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff', 'Vary' => 'Cookie, Authorization',
                'X-FinePrint-Complete' => $rendering->complete() ? '1' : '0',
            ]);
        } catch (RefusedException $e) {
            return self::refused($e);
        } catch (InvalidArgumentException) {
            return self::error('invalid_page_image_options', 422);
        }
    }

    private static function viewerLines(Request $request): array
    {
        $user = $request->user();
        $lines = [];
        if (is_object($user)) {
            if (is_string($user->name ?? null) && trim($user->name) !== '') {
                $lines[] = $user->name;
            }
            if (is_string($user->email ?? null) && trim($user->email) !== '') {
                $lines[] = $user->email;
            } else {
                $id = method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : ($user->id ?? 'viewer');
                $lines[] = 'Viewer '.(is_string($id) || is_int($id) ? $id : 'authenticated');
            }
        } else {
            $lines[] = 'Viewer';
        }
        $requestTime = $request->server->get('REQUEST_TIME');
        $lines[] = gmdate('Y-m-d H:i:s', is_int($requestTime) ? $requestTime : time()).' UTC';

        return $lines;
    }

    private static function error(string $error, int $status, ?string $reason = null): Response
    {
        return new Response(json_encode(array_filter(['error' => $error, 'reason' => $reason]), JSON_THROW_ON_ERROR), $status,
            ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private static function refused(RefusedException $e): Response
    {
        return self::error('pdf_render_refused', $e->reason === 'too_large' ? 413 : 422, $e->reason);
    }
}
