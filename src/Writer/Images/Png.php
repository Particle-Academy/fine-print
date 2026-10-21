<?php

declare(strict_types=1);

namespace FinePrint\Writer\Images;

use FinePrint\Exceptions\ImageException;

/**
 * A PNG, embedded with as little decoding as PDF allows.
 *
 * PASSTHROUGH: a non-interlaced grey, RGB or palette image without
 * transparency is embedded byte-for-byte: the joined IDAT data is a zlib
 * stream, and PNG's per-row filters are exactly PDF's `/Predictor 15`. Nothing
 * is inflated.
 *
 * DECODE: alpha channels, `tRNS` transparency and Adam7 interlacing have no
 * PDF equivalent, so those images are inflated (zlib, bounded to the size the
 * header promises), unfiltered, and re-encoded as 8-bit samples plus an 8-bit
 * `/SMask`.
 *
 * Every chunk's CRC is checked, so a damaged file is refused rather than drawn
 * as garbage.
 */
final class Png
{
    private const MAX_CHUNKS = 100_000;

    /** Bit depths each colour type allows. */
    private const DEPTHS = [0 => [1, 2, 4, 8, 16], 2 => [8, 16], 3 => [1, 2, 4, 8], 4 => [8, 16], 6 => [8, 16]];

    private const CHANNELS = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4];

    private function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $bitDepth,
        public readonly int $colorType,
        public readonly bool $interlaced,
        private readonly string $palette,
        private readonly ?string $trns,
        private readonly string $idat,
    ) {}

    public static function parse(string $bytes, int $maxPixels): self
    {
        $len = strlen($bytes);
        $p = 8;
        $ihdr = null;
        $palette = '';
        $trns = null;
        $idat = '';
        $ended = false;
        for ($n = 0; $n < self::MAX_CHUNKS && $p + 12 <= $len; $n++) {
            $size = unpack('N', substr($bytes, $p, 4))[1];
            $type = substr($bytes, $p + 4, 4);
            if ($size > $len - $p - 12) {
                throw new ImageException('PNG is truncated');
            }
            $data = substr($bytes, $p + 8, $size);
            $crc = unpack('N', substr($bytes, $p + 8 + $size, 4))[1];
            if (crc32($type.$data) !== $crc) {
                throw new ImageException('PNG is damaged (a chunk checksum does not match)');
            }
            $p += 12 + $size;
            if ($ihdr === null && $type !== 'IHDR') {
                throw new ImageException('PNG is damaged (IHDR is not first)');
            }
            switch ($type) {
                case 'IHDR':
                    if ($size !== 13) {
                        throw new ImageException('PNG is damaged (bad IHDR)');
                    }
                    $ihdr = unpack('Nwidth/Nheight/Cdepth/Ctype/Ccompression/Cfilter/Cinterlace', $data);
                    break;
                case 'PLTE':
                    $palette = $data;
                    break;
                case 'tRNS':
                    $trns = $data;
                    break;
                case 'IDAT':
                    $idat .= $data;
                    break;
                case 'IEND':
                    $ended = true;
                    break 2;
            }
        }
        if ($ihdr === null || ! $ended || $idat === '') {
            throw new ImageException('PNG is truncated or has no image data');
        }

        $w = $ihdr['width'];
        $h = $ihdr['height'];
        $type = $ihdr['type'];
        $depth = $ihdr['depth'];
        if ($w < 1 || $h < 1 || $w > 0x7FFFFFFF || $h > 0x7FFFFFFF) {
            throw new ImageException('PNG has no size');
        }
        if ($w * $h > $maxPixels) {
            throw new ImageException('image has more than '.$maxPixels.' pixels');
        }
        if (! isset(self::DEPTHS[$type]) || ! in_array($depth, self::DEPTHS[$type], true)) {
            throw new ImageException('PNG colour type '.$type.' with bit depth '.$depth.' is not valid');
        }
        if ($ihdr['compression'] !== 0 || $ihdr['filter'] !== 0 || $ihdr['interlace'] > 1) {
            throw new ImageException('PNG uses an unknown compression, filter or interlace method');
        }
        if ($type === 3 && ($palette === '' || strlen($palette) % 3 !== 0 || strlen($palette) > 768)) {
            throw new ImageException('PNG palette image has no valid palette');
        }

        $png = new self($w, $h, $depth, $type, $ihdr['interlace'] === 1, $palette, $trns, $idat);
        // Even a passthrough image is inflated once (bounded, output thrown
        // away) to prove the data is what the header promises. Otherwise a
        // tiny PNG whose IDAT is a decompression bomb would be copied into the
        // PDF as-is, and every reader of it, ours included, would have to
        // defuse it.
        self::inflate($idat, $png->expectedBytes());

        return $png;
    }

    /** Bytes of filtered scanlines the header promises, interlacing included. */
    private function expectedBytes(): int
    {
        return array_sum(array_map(fn (array $s) => $s[0] > 0 && $s[1] > 0 ? $s[1] * (1 + $s[2]) : 0, $this->shapes()));
    }

    /** @return list<array{0: int, 1: int, 2: int}> width, height and row bytes of each pass */
    private function shapes(): array
    {
        $bitsPerPixel = self::CHANNELS[$this->colorType] * $this->bitDepth;
        $passes = $this->interlaced
            ? [[0, 0, 8, 8], [4, 0, 8, 8], [0, 4, 4, 8], [2, 0, 4, 4], [0, 2, 2, 4], [1, 0, 2, 2], [0, 1, 1, 2]]
            : [[0, 0, 1, 1]];
        $shapes = [];
        foreach ($passes as [$x0, $y0, $dx, $dy]) {
            $pw = $this->width > $x0 ? intdiv($this->width - $x0 + $dx - 1, $dx) : 0;
            $ph = $this->height > $y0 ? intdiv($this->height - $y0 + $dy - 1, $dy) : 0;
            $shapes[] = [$pw, $ph, intdiv($pw * $bitsPerPixel + 7, 8)];
        }

        return $shapes;
    }

    /** @return array{dict: string, data: string, smask: ?array{dict: string, data: string}} */
    public function xobject(): array
    {
        $alpha = $this->colorType === 4 || $this->colorType === 6 || $this->trns !== null;
        if (! $alpha && ! $this->interlaced) {
            return ['dict' => $this->passthroughDict(), 'data' => $this->idat, 'smask' => null];
        }

        return $this->decoded();
    }

    private function passthroughDict(): string
    {
        $colors = self::CHANNELS[$this->colorType];
        $space = match ($this->colorType) {
            0 => '/DeviceGray',
            2 => '/DeviceRGB',
            3 => '[/Indexed /DeviceRGB '.(intdiv(strlen($this->palette), 3) - 1).' <'.bin2hex($this->palette).'>]',
        };

        return '/Type /XObject /Subtype /Image /Width '.$this->width.' /Height '.$this->height
            .' /ColorSpace '.$space.' /BitsPerComponent '.$this->bitDepth
            .' /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors '.$colors
            .' /BitsPerComponent '.$this->bitDepth.' /Columns '.$this->width.' >>';
    }

    /** @return array{dict: string, data: string, smask: ?array{dict: string, data: string}} */
    private function decoded(): array
    {
        $channels = self::CHANNELS[$this->colorType];
        $bitsPerPixel = $channels * $this->bitDepth;
        $passes = $this->interlaced
            ? [[0, 0, 8, 8], [4, 0, 8, 8], [0, 4, 4, 8], [2, 0, 4, 4], [0, 2, 2, 4], [1, 0, 2, 2], [0, 1, 1, 2]]
            : [[0, 0, 1, 1]];

        $shapes = $this->shapes();
        $raw = self::inflate($this->idat, $this->expectedBytes());

        $isGray = $this->colorType === 0 || $this->colorType === 4;
        $outChannels = $isGray ? 1 : 3;
        $color = str_repeat("\0", $this->width * $this->height * $outChannels);
        $alpha = str_repeat("\xFF", $this->width * $this->height);
        $palette = $this->palette;
        $trns = $this->trns ?? '';
        $key = $this->transparencyKey();
        $max = (1 << $this->bitDepth) - 1;
        $bpp = max(1, intdiv($bitsPerPixel, 8));

        $at = 0;
        foreach ($passes as $i => [$x0, $y0, $dx, $dy]) {
            [$pw, $ph, $rowBytes] = $shapes[$i];
            if ($pw === 0 || $ph === 0) {
                continue;
            }
            $prev = str_repeat("\0", $rowBytes);
            for ($r = 0; $r < $ph; $r++) {
                $filter = ord($raw[$at]);
                $line = self::unfilter($filter, substr($raw, $at + 1, $rowBytes), $prev, $bpp);
                $at += 1 + $rowBytes;
                $prev = $line;
                $samples = self::samples($line, $pw * $channels, $this->bitDepth);
                $y = $y0 + $r * $dy;
                for ($c = 0; $c < $pw; $c++) {
                    $x = $x0 + $c * $dx;
                    $px = $y * $this->width + $x;
                    $s = array_slice($samples, $c * $channels, $channels);
                    if ($this->colorType === 3) {
                        $idx = $s[0];
                        $rgb = substr($palette, 3 * $idx, 3);
                        if (strlen($rgb) !== 3) {
                            $rgb = "\0\0\0";
                        }
                        $color[3 * $px] = $rgb[0];
                        $color[3 * $px + 1] = $rgb[1];
                        $color[3 * $px + 2] = $rgb[2];
                        if ($idx < strlen($trns)) {
                            $alpha[$px] = $trns[$idx];
                        }

                        continue;
                    }
                    $hasAlpha = $this->colorType === 4 || $this->colorType === 6;
                    $colorSamples = $hasAlpha ? array_slice($s, 0, $channels - 1) : $s;
                    foreach ($colorSamples as $k => $v) {
                        $color[$outChannels * $px + $k] = chr(self::to8($v, $this->bitDepth, $max));
                    }
                    if ($hasAlpha) {
                        $alpha[$px] = chr(self::to8($s[$channels - 1], $this->bitDepth, $max));
                    } elseif ($key !== null && $colorSamples === $key) {
                        $alpha[$px] = "\0";
                    }
                }
            }
        }

        $space = $isGray ? '/DeviceGray' : '/DeviceRGB';
        $base = '/Type /XObject /Subtype /Image /Width '.$this->width.' /Height '.$this->height.' /BitsPerComponent 8 /Filter /FlateDecode';
        $smask = trim($alpha, "\xFF") === ''
            ? null
            : ['dict' => $base.' /ColorSpace /DeviceGray', 'data' => (string) gzcompress($alpha, 6)];

        return ['dict' => $base.' /ColorSpace '.$space, 'data' => (string) gzcompress($color, 6), 'smask' => $smask];
    }

    /** @return ?list<int> the tRNS colour key of a grey or RGB image */
    private function transparencyKey(): ?array
    {
        if ($this->trns === null) {
            return null;
        }
        if ($this->colorType === 0 && strlen($this->trns) >= 2) {
            return [unpack('n', $this->trns)[1]];
        }
        if ($this->colorType === 2 && strlen($this->trns) >= 6) {
            return array_values(unpack('n3', $this->trns));
        }

        return null;
    }

    /** Inflate, refusing anything that is not exactly what the header promised. */
    private static function inflate(string $data, int $expected): string
    {
        $ctx = inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($ctx === false) {
            throw new ImageException('PNG data could not be decompressed');
        }
        $out = '';
        $limit = $expected + 1024;
        foreach (str_split($data, 8192) as $chunk) {
            $part = @inflate_add($ctx, $chunk, ZLIB_SYNC_FLUSH);
            if ($part === false) {
                throw new ImageException('PNG data is damaged and could not be decompressed');
            }
            $out .= $part;
            if (strlen($out) > $limit) {
                throw new ImageException('PNG data decompresses to more than its header allows');
            }
        }
        if (strlen($out) < $expected) {
            throw new ImageException('PNG data is shorter than its header says');
        }

        return substr($out, 0, $expected);
    }

    private static function unfilter(int $type, string $line, string $prev, int $bpp): string
    {
        $n = strlen($line);
        switch ($type) {
            case 0:
                return $line;
            case 1:
                for ($i = $bpp; $i < $n; $i++) {
                    $line[$i] = chr((ord($line[$i]) + ord($line[$i - $bpp])) & 0xFF);
                }

                return $line;
            case 2:
                for ($i = 0; $i < $n; $i++) {
                    $line[$i] = chr((ord($line[$i]) + ord($prev[$i])) & 0xFF);
                }

                return $line;
            case 3:
                for ($i = 0; $i < $n; $i++) {
                    $a = $i >= $bpp ? ord($line[$i - $bpp]) : 0;
                    $line[$i] = chr((ord($line[$i]) + intdiv($a + ord($prev[$i]), 2)) & 0xFF);
                }

                return $line;
            case 4:
                for ($i = 0; $i < $n; $i++) {
                    $a = $i >= $bpp ? ord($line[$i - $bpp]) : 0;
                    $b = ord($prev[$i]);
                    $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
                    $p = $a + $b - $c;
                    $pa = abs($p - $a);
                    $pb = abs($p - $b);
                    $pc = abs($p - $c);
                    $pred = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                    $line[$i] = chr((ord($line[$i]) + $pred) & 0xFF);
                }

                return $line;
        }

        throw new ImageException('PNG uses an unknown row filter');
    }

    /** @return list<int> */
    private static function samples(string $line, int $count, int $depth): array
    {
        if ($depth === 8) {
            return array_values(unpack('C*', $line) ?: []);
        }
        if ($depth === 16) {
            return array_values(unpack('n*', $line) ?: []);
        }
        $out = [];
        $mask = (1 << $depth) - 1;
        $perByte = intdiv(8, $depth);
        $n = strlen($line);
        for ($i = 0; $i < $n && count($out) < $count; $i++) {
            $byte = ord($line[$i]);
            for ($k = $perByte - 1; $k >= 0 && count($out) < $count; $k--) {
                $out[] = ($byte >> ($k * $depth)) & $mask;
            }
        }

        return $out;
    }

    private static function to8(int $v, int $depth, int $max): int
    {
        return match ($depth) {
            8 => $v,
            16 => $v >> 8,
            default => intdiv($v * 255, $max),
        };
    }
}
