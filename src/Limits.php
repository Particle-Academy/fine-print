<?php

declare(strict_types=1);

namespace FinePrint;

use InvalidArgumentException;

/**
 * Hostile-input limits. Breaching any one refuses the file with a
 * {@see Exceptions\RefusedException} naming the limit; nothing degrades into a
 * fatal error or a hang.
 *
 * The defaults admit any ordinary document, including large reports, and
 * refuse the shapes that only exist to hurt a reader. Lower them for an upload
 * endpoint that should never see a 200-page file.
 */
final class Limits
{
    public function __construct(
        /** The whole file, before anything is parsed. */
        public readonly int $maxFileBytes = 256 * 1024 * 1024,
        /** Objects listed in the cross-reference data (or found by repair). */
        public readonly int $maxObjects = 1_000_000,
        /** Nesting of arrays and dictionaries in one value. */
        public readonly int $maxDepth = 64,
        /** Decoded size of any one stream. */
        public readonly int $maxStreamBytes = 64 * 1024 * 1024,
        /** Decoded size of every stream read, together. */
        public readonly int $maxTotalDecodedBytes = 512 * 1024 * 1024,
        /** Expansion ratio of one filter stage, once past $ratioFloorBytes. */
        public readonly float $maxRatio = 500.0,
        /** Output below this size is never judged on ratio alone. */
        public readonly int $ratioFloorBytes = 1024 * 1024,
        /** Content-stream operators per page, form XObjects included. */
        public readonly int $maxOperators = 2_000_000,
        /** Form XObjects drawn inside form XObjects. */
        public readonly int $maxFormDepth = 12,
        public readonly int $maxPages = 50_000,
        /** Wall-clock budget from open, in seconds. */
        public readonly float $timeSeconds = 30.0,
        /** Memory growth from open, in bytes. A guard rail; memory_limit stays the hard ceiling. */
        public readonly int $memoryBytes = 256 * 1024 * 1024,
        /**
         * Pixels in one rendered page (width x height), judged BEFORE the
         * raster is allocated. 25 million is an A4 page at about 430 DPI
         * (scale 6) and costs 75 MB of raster.
         */
        public readonly int $maxRenderPixels = 25_000_000,
        /** Wall-clock budget for rendering one page, from the call, in seconds. */
        public readonly float $renderTimeSeconds = 30.0,
    ) {}

    /**
     * A copy with some values replaced.
     *
     * @param  array<string, int|float>  $changes
     */
    public function with(array $changes): self
    {
        return self::from([...get_object_vars($this), ...$changes]);
    }

    /**
     * From a Limits, null (the defaults) or an array of the constructor's
     * named arguments — the form agent tool options arrive in.
     *
     * @param  self|array<string, int|float>|null  $v
     */
    public static function from(self|array|null $v): self
    {
        if ($v instanceof self) {
            return $v;
        }
        if ($v === null) {
            return new self();
        }
        $known = array_keys(get_class_vars(self::class));
        foreach (array_keys($v) as $k) {
            if (! in_array($k, $known, true)) {
                throw new InvalidArgumentException('Unknown limit "'.$k.'".');
            }
        }

        return new self(...$v);
    }
}
