<?php

declare(strict_types=1);

namespace FinePrint\Laravel;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Stateless controller: route descriptors carry only an opaque binding key. */
final class PageImageController
{
    public function show(Request $request): Response
    {
        $binding = $request->route()?->defaults['_finePrintPages'] ?? null;
        $container = Container::getInstance();
        if (! is_string($binding) || ! str_starts_with($binding, 'fine-print.pages.') || ! $container->bound($binding)) {
            return new Response('', 404);
        }
        $handler = $container->make($binding);

        return $handler instanceof PageImages ? $handler->response($request) : new Response('', 404);
    }
}
