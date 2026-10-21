<?php

declare(strict_types=1);

namespace FinePrint\Exceptions;

use RuntimeException;

/**
 * The file was refused: not a PDF, damaged beyond repair, encrypted, or over
 * one of the {@see \FinePrint\Limits}.
 *
 * `reason` is a stable machine code; the message is for humans and never
 * quotes the file's own content.
 */
final class RefusedException extends RuntimeException
{
    public const REASONS = [
        'not_pdf',
        'corrupt',
        'encrypted',
        'too_large',
        'decompression_bomb',
        'too_deep',
        'too_many_objects',
        'too_many_pages',
        'too_many_operators',
        'time_budget',
        'memory_budget',
        'too_many_pixels',
    ];

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
