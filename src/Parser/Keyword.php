<?php

declare(strict_types=1);

namespace FinePrint\Parser;

/**
 * A bare word that is not a value: `obj`, `endobj`, `stream`, or a
 * content-stream operator (`Tj`, `cm`, `BT`, ...).
 */
final class Keyword
{
    public function __construct(public readonly string $word) {}
}
