<?php

declare(strict_types=1);

namespace FinePrint\Laravel;

use InvalidArgumentException;
use FinePrint\Budget;
use FinePrint\Limits;
use FinePrint\Exceptions\RefusedException;

/** The app's authorised bytes and trusted identity/version, never client query data. */
final class PageSource
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $key,
        public readonly string $version,
        public readonly ?array $watermark = null,
    ) {
        if ($key === '' || $version === '' || strlen($key) > 1024 || strlen($version) > 1024) {
            throw new InvalidArgumentException('A page source needs a bounded server file key and version.');
        }
    }

    /** Read a host-authorised stream from its current position; the caller owns it. */
    public static function fromStream(mixed $stream, string $key, string $version, ?array $watermark = null, int $maxBytes = 32 * 1024 * 1024): self
    {
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream' || $maxBytes < 1 || $maxBytes >= PHP_INT_MAX) {
            throw new InvalidArgumentException('A bounded page source requires an open stream and a positive byte limit.');
        }
        $budget = new Budget(new Limits(maxFileBytes: $maxBytes, timeSeconds: 2.0), 'Reading an authorised PDF source');
        $bytes = '';
        while (! feof($stream)) {
            $length = min(4096, $maxBytes + 1 - strlen($bytes));
            if ($length <= 0) {
                throw new RefusedException('too_large', 'The authorised PDF stream exceeds its byte limit.');
            }
            $budget->allocation(strlen($bytes) + $length * 2 + 4096);
            $piece = @fread($stream, $length);
            if ($piece === false || ($piece === '' && ! feof($stream))) {
                throw new RefusedException('corrupt', 'The authorised PDF stream could not be read.');
            }
            $bytes .= $piece;
            if (strlen($bytes) > $maxBytes) {
                throw new RefusedException('too_large', 'The authorised PDF stream exceeds its byte limit.');
            }
            $budget->tick(strlen($piece));
        }
        $budget->check();

        return new self($bytes, $key, $version, $watermark);
    }
}
