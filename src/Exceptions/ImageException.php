<?php

declare(strict_types=1);

namespace FinePrint\Exceptions;

use RuntimeException;

/**
 * An image the writer was given cannot be embedded: not PNG or JPEG, damaged,
 * a JPEG flavour PDF cannot carry, or larger than the pixel limit.
 */
final class ImageException extends RuntimeException {}
