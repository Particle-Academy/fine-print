<?php

declare(strict_types=1);

namespace FinePrint\Objects;

/**
 * A stream: its dictionary and its still-ENCODED bytes. Decoding happens on
 * demand through {@see \FinePrint\Filters\Filters}, under the document's
 * budget, so a stream nobody reads is never expanded.
 */
final class PdfStream
{
    public function __construct(
        public readonly PdfDict $dict,
        public readonly string $raw,
        public readonly ?int $objectNumber = null,
    ) {}
}
