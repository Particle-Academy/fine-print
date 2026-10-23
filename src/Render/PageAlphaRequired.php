<?php

declare(strict_types=1);

namespace FinePrint\Render;

/** @internal Retry marker; Renderer replays with page alpha under the SAME budget. */
final class PageAlphaRequired extends \RuntimeException {}
