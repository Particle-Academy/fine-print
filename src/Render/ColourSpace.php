<?php

declare(strict_types=1);

namespace FinePrint\Render;

use FinePrint\Document;
use FinePrint\Objects\PdfDict;
use FinePrint\Objects\PdfName;
use FinePrint\Objects\PdfStream;

/**
 * A colour space as P3a paints it.
 *
 * - `gray`, `rgb`, `cmyk`: exact (CMYK by the naive formula, as the plan says).
 *   ICCBased maps to these by its component count, CalGray / CalRGB to theirs.
 * - `approx`: Separation / DeviceN, drawn as grey from the tint, REPORTED.
 *   Their tint transforms are functions, which wait for P3d.
 * - `unsupported`: Lab, Indexed, anything unknown; drawn in black, REPORTED.
 * - `pattern`: not drawn at all, REPORTED.
 */
final class ColourSpace
{
    private function __construct(
        public readonly string $kind,
        public readonly int $components,
    ) {}

    public static function gray(): self
    {
        return new self('gray', 1);
    }

    public static function rgb(): self
    {
        return new self('rgb', 3);
    }

    public static function cmyk(): self
    {
        return new self('cmyk', 4);
    }

    /** @return list<float> the initial colour for this space (ISO 32000-1 8.6.5.1, 8.6.6.2...) */
    public function initial(): array
    {
        return match ($this->kind) {
            'rgb' => [0.0, 0.0, 0.0],
            'cmyk' => [0.0, 0.0, 0.0, 1.0],
            'approx' => array_fill(0, $this->components, 1.0),
            default => array_fill(0, max(1, $this->components), 0.0),
        };
    }

    /**
     * Resolve a `cs` / `CS` operand.
     */
    public static function resolve(string $name, ?PdfDict $resources, Document $doc): self
    {
        return self::fromValue(new PdfName($name), $resources, $doc, 0);
    }

    /** Resolve an image's direct colour-space value or resource name. */
    public static function fromPdf(mixed $value, ?PdfDict $resources, Document $doc): self
    {
        return self::fromValue($value, $resources, $doc, 0);
    }

    private static function fromValue(mixed $v, ?PdfDict $resources, Document $doc, int $depth): self
    {
        $v = $doc->resolve($v);
        if ($depth > 4) {
            return new self('unsupported', 1);
        }
        if ($v instanceof PdfName) {
            // The abbreviations G / RGB / CMYK belong to inline images only;
            // to `cs` they are resource names like any other.
            switch ($v->value) {
                case 'DeviceGray':
                    return self::gray();
                case 'DeviceRGB':
                    return self::rgb();
                case 'DeviceCMYK':
                    return self::cmyk();
                case 'Pattern':
                    return new self('pattern', 0);
            }
            $named = $resources !== null ? $doc->resolve($resources->get('ColorSpace')) : null;
            if ($depth === 0 && $named instanceof PdfDict && $named->has($v->value)) {
                return self::fromValue($named->get($v->value), null, $doc, $depth + 1);
            }

            return new self('unsupported', 1);
        }
        if (! is_array($v) || $v === []) {
            return new self('unsupported', 1);
        }
        $family = $doc->resolve($v[0]);
        $family = $family instanceof PdfName ? $family->value : '';

        switch ($family) {
            case 'CalGray':
                return self::gray();
            case 'CalRGB':
                return self::rgb();
            case 'ICCBased':
                $stream = $doc->resolve($v[1] ?? null);
                $n = $stream instanceof PdfStream ? $doc->resolve($stream->dict->get('N')) : null;
                if ($n === 1) {
                    return self::gray();
                }
                if ($n === 3) {
                    return self::rgb();
                }
                if ($n === 4) {
                    return self::cmyk();
                }

                return $stream instanceof PdfStream && $stream->dict->has('Alternate')
                    ? self::fromValue($stream->dict->get('Alternate'), null, $doc, $depth + 1)
                    : new self('unsupported', 1);
            case 'Separation':
                return new self('approx', 1);
            case 'DeviceN':
                $names = $doc->resolve($v[1] ?? null);

                return new self('approx', is_array($names) ? max(1, min(32, count($names))) : 1);
            case 'Pattern':
                return new self('pattern', 0);
            case 'Indexed': case 'I':
                return new self('unsupported', 1);
            case 'Lab':
                return new self('unsupported', 3);
        }

        return self::fromValue($v[0], null, $doc, $depth + 1);
    }

    /**
     * The colour as 0..255 RGB.
     *
     * @param  list<float>  $c
     * @return array{0: int, 1: int, 2: int}
     */
    public function toRgb(array $c): array
    {
        $f = static fn (float $v): int => (int) round(max(0.0, min(1.0, $v)) * 255);
        switch ($this->kind) {
            case 'gray':
                $g = $f($c[0] ?? 0.0);

                return [$g, $g, $g];
            case 'rgb':
                return [$f($c[0] ?? 0.0), $f($c[1] ?? 0.0), $f($c[2] ?? 0.0)];
            case 'cmyk':
                $k = 1 - max(0.0, min(1.0, $c[3] ?? 1.0));

                return [
                    $f((1 - min(1.0, $c[0] ?? 0.0)) * $k),
                    $f((1 - min(1.0, $c[1] ?? 0.0)) * $k),
                    $f((1 - min(1.0, $c[2] ?? 0.0)) * $k),
                ];
            case 'approx':
                $g = $f(1 - ($c === [] ? 0.0 : max($c)));

                return [$g, $g, $g];
        }

        return [0, 0, 0];
    }
}
