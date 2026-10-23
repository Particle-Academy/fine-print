<?php

declare(strict_types=1);

namespace FinePrint\Render;

use FinePrint\Budget;
use FinePrint\Exceptions\RefusedException;
use JsonException;

/** Versioned, checksummed, bounded compressed RGB. Never serialises PHP objects. */
final class RasterCache
{
    private const MAGIC = 'FPR1';
    private const MAX_META = 16384;

    public static function encode(Rendering $r, Budget $budget): string
    {
        $bytes = (float) $r->width * $r->height * 3;
        $budget->allocation($bytes * 3 + self::MAX_META);
        $meta = json_encode(['page' => $r->page, 'scale' => $r->scale, 'rotate' => $r->rotate,
            'operators' => $r->operators, 'skipped' => $r->skipped], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($meta) > self::MAX_META) {
            throw new RefusedException('too_large', 'Raster cache metadata exceeds its limit.');
        }
        $raw = implode('', $r->rows());
        $compressed = (string) gzcompress($raw, 3);
        if (strlen($compressed) > $budget->limits->maxStreamBytes) {
            throw new RefusedException('too_large', 'A raster cache packet exceeds the encoded stream limit.');
        }
        $packet = self::MAGIC.pack('NNN', $r->width, $r->height, strlen($meta)).$meta.$compressed;
        $budget->check();

        return $packet.hash('sha256', $packet, true);
    }

    /** Corruption is a miss; a request-budget breach always propagates. */
    public static function decode(string $packet, Budget $budget): ?Rendering
    {
        $budget->check();
        $length = strlen($packet);
        if ($length < 50 || $length > $budget->limits->maxStreamBytes + self::MAX_META + 48 || substr($packet, 0, 4) !== self::MAGIC) {
            return null;
        }
        $budget->allocation($length);
        $body = substr($packet, 0, -32);
        if (! hash_equals(substr($packet, -32), hash('sha256', $body, true))) {
            return null;
        }
        [$w, $h, $metaLength] = array_values(unpack('N3', substr($packet, 4, 12)));
        if ($w < 1 || $h < 1 || (float) $w * $h > $budget->limits->maxRenderPixels
            || $metaLength < 2 || $metaLength > self::MAX_META || 16 + $metaLength >= strlen($body)) {
            return null;
        }
        try {
            $meta = json_decode(substr($packet, 16, $metaLength), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($meta) || ! is_int($meta['page'] ?? null) || $meta['page'] < 1 || $meta['page'] > $budget->limits->maxPages
            || (! is_int($meta['scale'] ?? null) && ! is_float($meta['scale'] ?? null)) || ! is_finite((float) $meta['scale']) || $meta['scale'] <= 0
            || ! in_array($meta['rotate'] ?? null, [0, 90, 180, 270], true)
            || ! is_int($meta['operators'] ?? null) || $meta['operators'] < 0 || $meta['operators'] > $budget->limits->maxOperators
            || ! self::skips($meta['skipped'] ?? null)) {
            return null;
        }
        $bytes = (float) $w * $h * 3;
        $budget->allocation($bytes * 2 + (float) $h * 96 + 4096);
        $raw = self::inflate(substr($body, 16 + $metaLength), (int) $bytes, $budget);
        if ($raw === null) {
            return null;
        }
        $rows = str_split($raw, $w * 3);
        $budget->check();
        $raster = new Raster($w, $h, $budget, $rows);

        // Parser warnings belong to the active Document; cache only stable render metadata.
        return new Rendering($raster, $meta['page'], (float) $meta['scale'], $meta['rotate'], $meta['skipped'], [], $meta['operators'], $w, $h);
    }

    private static function skips(mixed $skips): bool
    {
        if (! is_array($skips) || ! array_is_list($skips) || count($skips) > count(Rendering::KINDS)) {
            return false;
        }
        foreach ($skips as $skip) {
            if (! is_array($skip) || ! in_array($skip['kind'] ?? null, Rendering::KINDS, true)
                || ! is_int($skip['count'] ?? null) || $skip['count'] < 1) {
                return false;
            }
            if (isset($skip['fonts'])) {
                if (! is_array($skip['fonts']) || count($skip['fonts']) > 16) {
                    return false;
                }
                foreach ($skip['fonts'] as $font) {
                    if (! is_string($font) || ! preg_match('/^[A-Za-z0-9+_.-]{1,63}$/D', $font)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /** Stop before oversized output becomes a zlib warning or a large buffer. */
    private static function inflate(string $encoded, int $expected, Budget $budget): ?string
    {
        $ctx = @inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($ctx === false) {
            return null;
        }
        $out = '';
        for ($pos = 0, $len = strlen($encoded); $pos < $len;) {
            $remaining = $expected - strlen($out);
            // Deflate expands at most ~1032:1. At the very end a one-byte
            // chunk can overshoot by at most ~1 KB, already reserved above.
            $step = max(1, min(1024, intdiv($remaining, 1032)));
            $piece = @inflate_add($ctx, substr($encoded, $pos, $step), ZLIB_SYNC_FLUSH);
            if ($piece === false || strlen($piece) > $remaining) {
                return null;
            }
            $out .= $piece;
            $pos += $step;
            $budget->tick(strlen($piece) + $step);
            if (inflate_get_status($ctx) === ZLIB_STREAM_END) {
                return strlen($out) === $expected && inflate_get_read_len($ctx) === $len ? $out : null;
            }
        }

        return null;
    }
}
