<?php

declare(strict_types=1);

namespace FinePrint\Objects;

/**
 * A PDF dictionary. Keys are decoded names without the slash; values are
 * null|bool|int|float|PdfName|PdfString|PdfRef|PdfDict|PdfStream|list<mixed>.
 *
 * Values are NOT resolved here — a PdfRef stays a PdfRef until a caller asks
 * the document to resolve it, which is where the depth and cycle limits live.
 */
final class PdfDict
{
    /** @param array<string, mixed> $entries */
    public function __construct(public array $entries = []) {}

    public function get(string $key): mixed
    {
        return $this->entries[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->entries);
    }

    /** The value as a name string, or null when it is not a name. */
    public function name(string $key): ?string
    {
        $v = $this->entries[$key] ?? null;

        return $v instanceof PdfName ? $v->value : null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map('strval', array_keys($this->entries));
    }
}
