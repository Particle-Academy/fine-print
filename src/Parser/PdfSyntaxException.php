<?php

declare(strict_types=1);

namespace FinePrint\Parser;

use RuntimeException;

/**
 * Internal: the bytes are not valid PDF syntax at this point. The document
 * layer decides whether that is repairable or a refusal; callers outside the
 * package see {@see \FinePrint\Exceptions\RefusedException} instead.
 */
final class PdfSyntaxException extends RuntimeException {}
