<?php

declare(strict_types=1);

namespace FinePrint\Exceptions;

use InvalidArgumentException;

/**
 * A document failed validation. `errors` is the same `{path, message}` list
 * {@see \FinePrint\Agent::validate()} returns, so a caller can hand it straight
 * back to the agent that wrote the document.
 */
final class SchemaException extends InvalidArgumentException
{
    /**
     * @param  list<array{path: string, message: string}>  $errors
     */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
