<?php

declare(strict_types=1);

use FinePrint\Exceptions\RefusedException;
use FinePrint\Laravel\PageSource;

it('reads only a bounded authorised stream without opening a client path', function () {
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, 'pdf bytes');
    rewind($stream);
    try {
        $source = PageSource::fromStream($stream, 'tenant:file', 'version', maxBytes: 100);
        expect($source->bytes)->toBe('pdf bytes')->and($source->key)->toBe('tenant:file')
            ->and(is_resource($stream))->toBeTrue();
    } finally {
        fclose($stream);
    }
});

it('stops a stream read at the size limit plus one byte', function () {
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, str_repeat('secret', 1000));
    rewind($stream);
    try {
        try {
            PageSource::fromStream($stream, 'key', 'version', maxBytes: 100);
            test()->fail('Oversized stream should be refused.');
        } catch (RefusedException $e) {
            expect($e->reason)->toBe('too_large')->and(ftell($stream))->toBe(101);
        }
    } finally {
        fclose($stream);
    }
});

it('rejects paths and non-stream values instead of opening or fetching them', function () {
    expect(fn () => PageSource::fromStream('https://example.test/private.pdf', 'key', 'version'))->toThrow(InvalidArgumentException::class);
});
