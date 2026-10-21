<?php

declare(strict_types=1);

namespace FinePrint\Filters;

use RuntimeException;

/**
 * The stream is encoded with an image codec (DCT, JPX, CCITT, JBIG2) or a
 * crypt filter, which P1 deliberately does not decode. The bytes are still
 * there for whoever needs them; they are just not text.
 */
final class OpaqueStreamException extends RuntimeException
{
    public function __construct(public readonly string $filter)
    {
        parent::__construct('Stream is '.$filter.'-encoded and is kept as opaque bytes.');
    }
}
