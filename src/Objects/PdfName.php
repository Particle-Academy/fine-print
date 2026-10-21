<?php

declare(strict_types=1);

namespace FinePrint\Objects;

/**
 * A PDF name, WITHOUT the leading slash and with every #xx escape already
 * decoded. Comparing the decoded form is what makes /J#61vaScript and
 * /JavaScript the same name to every check in this package.
 */
final class PdfName
{
    public function __construct(public readonly string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }
}
