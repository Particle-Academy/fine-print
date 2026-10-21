<?php

declare(strict_types=1);

namespace FinePrint\Writer\Images;

use FinePrint\Exceptions\ImageException;

/**
 * A JPEG, embedded as-is with `/DCTDecode`. Only the headers are read: the
 * frame (size, components, precision) and Adobe's APP14 marker, which says
 * whether CMYK samples are stored inverted. No pixel is decoded.
 *
 * Refused, because PDF's DCTDecode cannot carry them: lossless, hierarchical
 * and arithmetic-coded JPEGs, and precisions other than 8 bits.
 */
final class Jpeg
{
    private const MAX_MARKERS = 1000;

    private function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $components,
        private readonly bool $adobe,
        private readonly string $bytes,
    ) {}

    public static function parse(string $bytes, int $maxPixels): self
    {
        $len = strlen($bytes);
        $p = 2;
        $adobe = false;
        for ($n = 0; $n < self::MAX_MARKERS; $n++) {
            while ($p < $len && $bytes[$p] === "\xFF" && ($bytes[$p + 1] ?? '') === "\xFF") {
                $p++;   // fill bytes
            }
            if ($p + 4 > $len || $bytes[$p] !== "\xFF") {
                break;
            }
            $marker = ord($bytes[$p + 1]);
            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $p += 2;

                continue;
            }
            $segment = unpack('n', substr($bytes, $p + 2, 2))[1];
            if ($segment < 2 || $p + 2 + $segment > $len) {
                break;
            }
            $body = substr($bytes, $p + 4, $segment - 2);
            if ($marker === 0xEE && str_starts_with($body, 'Adobe')) {
                $adobe = true;
            }
            if (in_array($marker, [0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF], true)) {
                throw new ImageException('this JPEG is lossless, hierarchical or arithmetic-coded, which a PDF cannot embed; save it as a baseline or progressive JPEG');
            }
            if (in_array($marker, [0xC0, 0xC1, 0xC2], true)) {
                if (strlen($body) < 6) {
                    break;
                }
                $f = unpack('Cprecision/nheight/nwidth/Ccomponents', $body);
                if ($f['precision'] !== 8) {
                    throw new ImageException('JPEG precision must be 8 bits');
                }
                if (! in_array($f['components'], [1, 3, 4], true)) {
                    throw new ImageException('JPEG must have 1, 3 or 4 colour components');
                }
                if ($f['width'] < 1 || $f['height'] < 1) {
                    throw new ImageException('JPEG has no size');
                }
                if ($f['width'] * $f['height'] > $maxPixels) {
                    throw new ImageException('image has more than '.$maxPixels.' pixels');
                }

                return new self($f['width'], $f['height'], $f['components'], $adobe || self::laterAdobe($bytes, $p), $bytes);
            }
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }
            $p += 2 + $segment;
        }

        throw new ImageException('JPEG is damaged: no frame header was found');
    }

    /** An APP14 marker may follow the frame header; look a short way ahead. */
    private static function laterAdobe(string $bytes, int $from): bool
    {
        $at = strpos($bytes, "\xFF\xEE", $from);

        return $at !== false && $at < $from + 4096 && substr($bytes, $at + 4, 5) === 'Adobe';
    }

    /** @return array{dict: string, data: string, smask: null} */
    public function xobject(): array
    {
        $space = [1 => '/DeviceGray', 3 => '/DeviceRGB', 4 => '/DeviceCMYK'][$this->components];
        $dict = '/Type /XObject /Subtype /Image /Width '.$this->width.' /Height '.$this->height
            .' /ColorSpace '.$space.' /BitsPerComponent 8 /Filter /DCTDecode';
        if ($this->components === 4 && $this->adobe) {
            $dict .= ' /Decode [1 0 1 0 1 0 1 0]';
        }

        return ['dict' => $dict, 'data' => $this->bytes, 'smask' => null];
    }
}
