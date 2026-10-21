<?php

declare(strict_types=1);

namespace FinePrint\Objects;

/**
 * A PDF string: raw BYTES, escapes already decoded. Whether those bytes are
 * text, and in which encoding, is decided by whoever reads them — see
 * {@see \FinePrint\Text\TextString} for document text strings.
 */
final class PdfString
{
    public function __construct(
        public readonly string $bytes,
        public readonly bool $hex = false,
    ) {}
}
