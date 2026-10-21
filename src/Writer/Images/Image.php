<?php

declare(strict_types=1);

namespace FinePrint\Writer\Images;

use FinePrint\Exceptions\ImageException;

/**
 * A PNG or JPEG ready to become an image XObject.
 *
 * The format is decided by the BYTES, never by the data URL's MIME type: a
 * MIME type is a claim by whoever wrote the URL. JPEG is embedded as-is
 * (`/DCTDecode`, nothing decoded). PNG is passed through as Flate data with a
 * PNG predictor when PDF can take it unchanged, and decoded with zlib only when
 * it cannot (alpha, transparency keys, interlacing).
 */
final class Image
{
    /** Default ceiling on pixels per image, so one data URL cannot exhaust memory. */
    public const MAX_PIXELS = 16_000_000;

    /** Default ceiling on an image's encoded size, in bytes. */
    public const MAX_BYTES = 32 * 1024 * 1024;

    private function __construct(
        public readonly int $width,
        public readonly int $height,
        private readonly Png|Jpeg $source,
    ) {}

    /** Parse a `data:image/png;base64,...` or `data:image/jpeg;base64,...` URL. */
    public static function fromDataUrl(string $src, int $maxPixels = self::MAX_PIXELS): self
    {
        if (preg_match('#^data:image/(png|jpe?g);base64,#', $src, $m) !== 1) {
            throw new ImageException('image src must be a PNG or JPEG data URL (data:image/png;base64,... or data:image/jpeg;base64,...)');
        }
        $payload = substr($src, strlen($m[0]));
        if (strlen($payload) > intdiv(self::MAX_BYTES * 4, 3) + 4) {
            throw new ImageException('image is larger than the '.self::MAX_BYTES.'-byte limit');
        }
        $bytes = base64_decode(preg_replace('/\s+/', '', $payload) ?? '', true);
        if ($bytes === false || $bytes === '') {
            throw new ImageException('image src is not valid base64');
        }

        return self::fromBytes($bytes, $maxPixels);
    }

    public static function fromBytes(string $bytes, int $maxPixels = self::MAX_PIXELS): self
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            $png = Png::parse($bytes, $maxPixels);

            return new self($png->width, $png->height, $png);
        }
        if (str_starts_with($bytes, "\xFF\xD8")) {
            $jpeg = Jpeg::parse($bytes, $maxPixels);

            return new self($jpeg->width, $jpeg->height, $jpeg);
        }

        throw new ImageException('image data could not be read as PNG or JPEG');
    }

    /**
     * The XObject: dictionary entries (without `/Length`), the stream data, and
     * a soft mask of the same shape when the image has transparency.
     *
     * @return array{dict: string, data: string, smask: ?array{dict: string, data: string}}
     */
    public function xobject(): array
    {
        return $this->source->xobject();
    }
}
