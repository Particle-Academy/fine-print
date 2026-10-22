<?php

declare(strict_types=1);

namespace FinePrint\Exceptions;

use RuntimeException;

/**
 * An image cannot be embedded or rendered: unsupported or damaged. Rendering
 * limit breaches are RefusedException instead, and must never be swallowed.
 */
final class ImageException extends RuntimeException {}
