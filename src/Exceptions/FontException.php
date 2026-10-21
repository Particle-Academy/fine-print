<?php

declare(strict_types=1);

namespace FinePrint\Exceptions;

use RuntimeException;

/**
 * A font file the writer was given cannot be used: not TrueType, damaged,
 * CFF-flavoured (not supported yet), or licensed against embedding.
 *
 * The message names the problem and never quotes the file's own bytes.
 */
final class FontException extends RuntimeException {}
