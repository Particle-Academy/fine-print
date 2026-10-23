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
        private readonly ?self $alpha = null,
        private readonly ?array $colourKey = null,
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

    public static function load(PdfStream $stream, Document $doc, Budget $budget, ?PdfDict $resources, bool $allowMasks = true): self
    {
        $d = $stream->dict;
        if (! $allowMasks && ($d->has('SMask') || $d->has('Mask'))) {
            throw new ImageException('Nested or recursive image masks are unsupported.');
        }
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
            $space = ColourSpace::fromPdf($value[1] ?? null, $resources, $doc, $budget);
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
            $space = $mask ? ColourSpace::gray() : ColourSpace::fromPdf($value, $resources, $doc, $budget);
        }
        if (! in_array($space->kind, ['gray', 'rgb', 'cmyk', 'alternate'], true)) {
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
        for ($i = 0; $i < count($decode); $i += 2) {
            if (! is_finite($decode[$i + 1] - $decode[$i])) {
                throw new ImageException('Image Decode endpoints overflow.');
            }
        }
        $jpeg = null;
        $data = self::stream($stream, $doc, $budget, $jpeg);
        if ($jpeg !== null && ($bits !== 8 || $jpeg !== [$w, $h, $components] || $mask || $palette !== null)) {
            throw new ImageException('JPEG frame does not match the PDF image dictionary.');
        }
        if (strlen($data) !== $row * $h) {
            throw new ImageException('Image sample length does not match its dimensions.');
        }

        $alpha = null;
        $key = null;
        $soft = $get('SMask');
        if ($d->has('SMask') && ! ($soft instanceof PdfName && $soft->value === 'None')) {
            if (! $soft instanceof PdfStream || $mask || $soft->dict->has('Matte')) {
                throw new ImageException('Unsupported or invalid image soft mask.');
            }
            $alpha = self::load($soft, $doc, $budget, $resources, false);
            if ($alpha->stencil || $alpha->space->kind !== 'gray' || $alpha->components !== 1) {
                throw new ImageException('An image soft mask must be grayscale.');
            }
        } elseif ($d->has('Mask')) {
            $value = $get('Mask');
            if ($mask) {
                throw new ImageException('A stencil cannot carry another image mask.');
            }
            if ($value instanceof PdfStream) {
                $alpha = self::load($value, $doc, $budget, $resources, false);
                if (! $alpha->stencil) {
                    throw new ImageException('An explicit image mask must be a stencil.');
                }
            } elseif (is_array($value) && count($value) === 2 * $components) {
                $key = array_map(fn ($v) => $doc->resolve($v), $value);
                foreach ($key as $i => $v) {
                    if (! is_int($v) || $v < 0 || $v > (1 << $bits) - 1 || ($i % 2 === 1 && $v < $key[$i - 1])) {
                        throw new ImageException('Invalid image colour-key mask.');
                    }
                }
            } else {
                throw new ImageException('Invalid image mask.');
            }
        }

        return new self($w, $h, $bits, $components, $data, $space, $decode, $mask, $palette, $hival, $alpha, $key);
    }

    /** Decode with the RENDER budget, including indirect filter parameters. */
    public static function stream(PdfStream $s, Document $doc, Budget $budget, ?array &$jpeg = null): string
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
            if ($p !== null && ! $p instanceof PdfDict) {
                throw new ImageException('Invalid image filter parameters.');
            }
            if ($p instanceof PdfDict) {
                $p = new PdfDict(array_map(fn ($v) => $doc->resolve($v), $p->entries));
            }
            $plist[] = $p;
        }
        try {
            $last = count($names) - 1;
            if ($last >= 0 && in_array($names[$last], ['DCT', 'DCTDecode'], true)) {
                $transform = ($plist[$last] ?? null)?->get('ColorTransform');
                if ($transform !== null && ! in_array($transform, [0, 1], true)) {
                    throw new ImageException('Invalid DCT ColorTransform.');
                }
                array_pop($names);
                $encoded = Filters::decodeChain($names, $s->raw, $plist, $budget);
                $decoded = JpegDecoder::decode($encoded, $budget, $transform);
                $jpeg = [$decoded['width'], $decoded['height'], $decoded['components']];

                return $decoded['data'];
            }

            return Filters::decodeChain($names, $s->raw, $plist, $budget);
        } catch (OpaqueStreamException) {
            throw new ImageException('Unsupported image codec.');
        }
    }

    /** RGB bytes, or native normalised components when a target is supplied. */
    public function pixel(int $x, int $y, array $fill, ?ColourSpace $target = null): ?array
    {
        $rowBits = intdiv($this->width * $this->components * $this->bits + 7, 8) * 8;
        $offset = $y * $rowBits + $x * $this->components * $this->bits;
        $max = (1 << $this->bits) - 1;
        $values = [];
        $masked = $this->colourKey !== null;
        for ($c = 0; $c < $this->components; $c++) {
            $byte = intdiv($offset, 8);
            $sample = match ($this->bits) {
                16 => (ord($this->data[$byte]) << 8) | ord($this->data[$byte + 1]),
                8 => ord($this->data[$byte]),
                default => (ord($this->data[$byte]) >> (8 - ($offset & 7) - $this->bits)) & $max,
            };
            if ($this->colourKey !== null && ($sample < $this->colourKey[2 * $c] || $sample > $this->colourKey[2 * $c + 1])) {
                $masked = false;
            }
            $values[] = $this->decode[2 * $c] + $sample / $max * ($this->decode[2 * $c + 1] - $this->decode[2 * $c]);
            $offset += $this->bits;
        }
        if ($masked) {
            return null;
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

        return $target !== null ? $this->space->convertTo($values, $target) : $this->space->toRgb($values);
    }

    /** Visible checkerboard for content we deliberately could not decode. */
    public static function placeholder(): self
    {
        return new self(2, 2, 8, 1, "\xE0\xC0\xC0\xE0", ColourSpace::gray(), [0.0, 1.0], false, null, 0);
    }

    /** Normalised image coordinates, independent of the base image resolution. */
    public function opacity(float $u, float $v): float
    {
        if ($this->alpha === null) {
            return 1.0;
        }
        $x = max(0, min($this->alpha->width - 1, (int) floor($u * $this->alpha->width)));
        $y = max(0, min($this->alpha->height - 1, (int) floor($v * $this->alpha->height)));
        $pixel = $this->alpha->pixel($x, $y, [0, 0, 0]);

        return $this->alpha->stencil ? ($pixel !== null ? 1.0 : 0.0) : ($pixel[0] ?? 0) / 255;
    }

    public function hasMask(): bool
    {
        return $this->alpha !== null || $this->colourKey !== null;
    }

    public function maskIsShape(): bool
    {
        return $this->alpha?->stencil ?? false;
    }
}
