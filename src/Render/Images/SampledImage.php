<?php

declare(strict_types=1);

namespace FinePrint\Render\Images;

use FinePrint\Budget;
use FinePrint\Document;
use FinePrint\Exceptions\ImageException;
use FinePrint\Exceptions\RefusedException;
use FinePrint\Filters\Filters;
use FinePrint\Filters\OpaqueStreamException;
use FinePrint\Objects\PdfDict;
use FinePrint\Objects\PdfName;
use FinePrint\Objects\PdfStream;
use FinePrint\Objects\PdfString;
use FinePrint\Render\ColourSpace;

/** Packed source samples. No PHP array is allocated per source pixel. */
final class SampledImage
{
    private function __construct(
        public readonly int $width,
        public readonly int $height,
        private readonly int $bits,
        private readonly int $components,
        private readonly string $data,
        private readonly ColourSpace $space,
        private readonly array $decode,
        public readonly bool $stencil,
        private readonly ?string $palette,
        private readonly int $hival,
    ) {}

    public static function dimensions(mixed $width, mixed $height, Budget $budget): void
    {
        if (! is_int($width) || ! is_int($height) || $width < 1 || $height < 1) {
            throw new ImageException('Invalid image dimensions.');
        }
        if ((float) $width * $height > min($budget->limits->maxImagePixels, PHP_INT_MAX / 64)) {
            throw new RefusedException('too_many_pixels', 'An image exceeds the source pixel limit.');
        }
        $budget->check();
    }

    public static function load(PdfStream $stream, Document $doc, Budget $budget, ?PdfDict $resources): self
    {
        $d = $stream->dict;
        $get = fn (string $key) => $doc->resolve($d->get($key));
        $w = $get('Width');
        $h = $get('Height');
        self::dimensions($w, $h, $budget);
        $mask = $get('ImageMask') === true;
        $bits = $get('BitsPerComponent') ?? ($mask ? 1 : null);
        if (! in_array($bits, [1, 2, 4, 8, 16], true) || ($mask && $bits !== 1)) {
            throw new ImageException('Unsupported image sample depth.');
        }
        $value = $get('ColorSpace');
        // Follow a named colour-space resource once, including Indexed spaces.
        if ($value instanceof PdfName && $resources !== null) {
            $named = $doc->resolve($resources->get('ColorSpace'));
            if ($named instanceof PdfDict && $named->has($value->value)) {
                $value = $doc->resolve($named->get($value->value));
            }
        }
        $palette = null;
        $hival = 0;
        if (! $mask && is_array($value) && ($doc->resolve($value[0] ?? null) instanceof PdfName)
            && in_array($doc->resolve($value[0])->value, ['Indexed', 'I'], true)) {
            $space = ColourSpace::fromPdf($value[1] ?? null, $resources, $doc);
            $hival = $doc->resolve($value[2] ?? null);
            $lookup = $doc->resolve($value[3] ?? null);
            if (! is_int($hival) || $hival < 0 || $hival > 255) {
                throw new ImageException('Invalid image palette.');
            }
            // A palette is at most 1024 bytes; judge its stream with the render budget.
            $palette = $lookup instanceof PdfString ? $lookup->bytes
                : ($lookup instanceof PdfStream ? self::stream($lookup, $doc, $budget) : null);
            if ($palette === null || strlen($palette) < ($hival + 1) * $space->components) {
                throw new ImageException('Truncated image palette.');
            }
        } else {
            $space = $mask ? ColourSpace::gray() : ColourSpace::fromPdf($value, $resources, $doc);
        }
        if (! in_array($space->kind, ['gray', 'rgb', 'cmyk'], true)) {
            throw new ImageException('Unsupported image colour space.');
        }
        $components = $palette !== null ? 1 : $space->components;
        $rowBytes = ceil((float) $w * $components * $bits / 8);
        if ($rowBytes * $h > min($budget->limits->maxStreamBytes, PHP_INT_MAX / 16)) {
            throw new RefusedException('too_large', 'Image samples exceed the decoded stream limit.');
        }
        $row = (int) $rowBytes;
        $decode = $get('Decode');
        if ($decode === null) {
            $decode = [];
            for ($i = 0; $i < $components; $i++) {
                array_push($decode, 0.0, $palette !== null ? (float) ((1 << $bits) - 1) : 1.0);
            }
        } elseif (! is_array($decode) || count($decode) !== $components * 2) {
            throw new ImageException('Invalid image Decode array.');
        }
        foreach ($decode as &$number) {
            $number = $doc->resolve($number);
            if ((! is_int($number) && ! is_float($number)) || ! is_finite((float) $number)) {
                throw new ImageException('Invalid image Decode number.');
            }
        }
        unset($number);
        $data = self::stream($stream, $doc, $budget);
        if (strlen($data) !== $row * $h) {
            throw new ImageException('Image sample length does not match its dimensions.');
        }

        return new self($w, $h, $bits, $components, $data, $space, $decode, $mask, $palette, $hival);
    }

    /** Decode with the RENDER budget, including indirect filter parameters. */
    public static function stream(PdfStream $s, Document $doc, Budget $budget): string
    {
        $filters = $doc->resolve($s->dict->get('Filter'));
        $params = $doc->resolve($s->dict->get('DecodeParms'));
        $filters = $filters instanceof PdfName ? [$filters] : ($filters ?? []);
        $params = $params instanceof PdfDict ? [$params] : ($params ?? []);
        if (! is_array($filters) || ! is_array($params) || count($filters) > 32) {
            throw new ImageException('Invalid image filters.');
        }
        $names = [];
        $plist = [];
        foreach ($filters as $i => $filter) {
            $filter = $doc->resolve($filter);
            if (! $filter instanceof PdfName) {
                throw new ImageException('Invalid image filter.');
            }
            $names[] = $filter->value;
            $p = $doc->resolve($params[$i] ?? null);
            if ($p instanceof PdfDict) {
                $p = new PdfDict(array_map(fn ($v) => $doc->resolve($v), $p->entries));
            }
            $plist[] = $p;
        }
        try {
            return Filters::decodeChain($names, $s->raw, $plist, $budget);
        } catch (OpaqueStreamException) {
            throw new ImageException('Unsupported image codec.');
        }
    }

    /** @param array{int, int, int} $fill @return ?array{int, int, int} */
    public function pixel(int $x, int $y, array $fill): ?array
    {
        $rowBits = intdiv($this->width * $this->components * $this->bits + 7, 8) * 8;
        $offset = $y * $rowBits + $x * $this->components * $this->bits;
        $max = (1 << $this->bits) - 1;
        $values = [];
        for ($c = 0; $c < $this->components; $c++) {
            $byte = intdiv($offset, 8);
            $sample = match ($this->bits) {
                16 => (ord($this->data[$byte]) << 8) | ord($this->data[$byte + 1]),
                8 => ord($this->data[$byte]),
                default => (ord($this->data[$byte]) >> (8 - ($offset & 7) - $this->bits)) & $max,
            };
            $values[] = $this->decode[2 * $c] + $sample / $max * ($this->decode[2 * $c + 1] - $this->decode[2 * $c]);
            $offset += $this->bits;
        }
        if ($this->stencil) {
            return $values[0] < 0.5 ? $fill : null;
        }
        if ($this->palette !== null) {
            $index = max(0, min($this->hival, (int) round($values[0])));
            $values = [];
            for ($c = 0; $c < $this->space->components; $c++) {
                $values[] = ord($this->palette[$index * $this->space->components + $c]) / 255;
            }
        }

        return $this->space->toRgb($values);
    }
}
