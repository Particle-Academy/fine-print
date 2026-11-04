<?php

declare(strict_types=1);

use FinePrint\Agent;
use FinePrint\Budget;
use FinePrint\Document;
use FinePrint\Exceptions\RefusedException;
use FinePrint\Filters\Filters;
use FinePrint\Limits;
use FinePrint\Objects\PdfStream;
use FinePrint\Render\Renderer;
use FinePrint\Tests\Support\Encoders;
use FinePrint\Tests\Support\ImageBuilder;
use FinePrint\Tests\Support\PdfBuilder;

/*
 * maxStreamBytes bounds memory; maxRatio only catches what no conforming filter
 * can produce. Flat content legitimately reaches deflate's ceiling (about
 * 1032:1) and a conforming LZW run reaches about 1363:1, so a ratio below those
 * refuses real documents, including ones this package wrote itself.
 *
 * A bomb now gets as far as the byte cap, tens of megabytes rather than a few,
 * so the hostile half here is about two things: the cap holds through every
 * filter chain, and reaching it never becomes a fatal error at a PHP
 * memory_limit a host really runs with. A fatal cannot be caught in-process, so
 * those run in a child.
 */

/** A flat white PNG, built incrementally; $hole makes one pixel transparent. */
function fpdFlatPng(int $w, int $h, bool $alpha, bool $hole = false): string
{
    $pixel = $alpha ? "\xFF\xFF\xFF\xFF" : "\xFF\xFF\xFF";
    $row = "\0".str_repeat($pixel, $w);
    $ctx = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
    $idat = '';
    for ($y = 0; $y < $h; $y++) {
        $line = $row;
        if ($hole && $y === 0) {
            $line[4] = "\0";
        }
        $idat .= deflate_add($ctx, $line, ZLIB_NO_FLUSH);
    }
    $idat .= deflate_add($ctx, '', ZLIB_FINISH);
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', $w, $h, 8, $alpha ? 6 : 2, 0, 0, 0))
        .$chunk('IDAT', $idat)
        .$chunk('IEND', '');
}

/**
 * Agent::read of one PDF in a child PHP process at $memory.
 *
 * @return array{code: int, out: string, result: mixed}
 */
function fpdReadInChild(string $pdf, string $memory): array
{
    $path = (string) tempnam(sys_get_temp_dir(), 'fp-ratio-');
    file_put_contents($path, $pdf);
    try {
        $lines = [];
        exec(escapeshellarg(PHP_BINARY).' -d memory_limit='.$memory.' '
            .escapeshellarg(dirname(__DIR__).'/Support/agent-read-child.php').' '.escapeshellarg($path).' 2>&1', $lines, $code);
    } finally {
        @unlink($path);
    }

    return ['code' => $code, 'out' => implode("\n", $lines), 'result' => json_decode((string) end($lines), true)];
}

/** Page 1's content replaced by one stream of $bytes behind $filter. */
function fpdPdf(string $bytes, string $filter): string
{
    $b = PdfBuilder::textPdf(['placeholder']);
    $b->rawStream($bytes, '/Filter '.$filter, 4);

    return $b->build();
}

/** A flate stream of $mb megabytes of a two-byte pattern, without holding it. */
function fpdFlatePattern(string $pattern, int $mb): string
{
    $ctx = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
    $chunk = str_repeat($pattern, intdiv(1 << 20, strlen($pattern)));
    $out = '';
    for ($i = 0; $i < $mb; $i++) {
        $out .= deflate_add($ctx, $chunk, ZLIB_NO_FLUSH);
    }

    return $out.deflate_add($ctx, '', ZLIB_FINISH);
}

/** The hostile chains, by name; built once, only when a test asks for one. */
const FPD_CHAINS = [
    'flate alone',
    'flate over flate',
    'ascii85 z runs behind flate',
    'run length behind flate',
    'lzw behind flate',
];

/** @return array{0: string, 1: string} encoded bytes, /Filter value */
function fpdChain(string $name): array
{
    static $built = [];

    return $built[$name] ??= match ($name) {
        'flate alone' => [Encoders::bomb(200 << 20), '/FlateDecode'],
        'flate over flate' => [(string) gzcompress(Encoders::bomb(200 << 20), 9), '[/FlateDecode /FlateDecode]'],
        // Four times their size, with no check of their own inside the stage.
        'ascii85 z runs behind flate' => [Encoders::bomb(60 << 20, 'z'), '[/FlateDecode /ASCII85Decode]'],
        // Sixty times its size.
        'run length behind flate' => [fpdFlatePattern("\x81 ", 60), '[/FlateDecode /RunLengthDecode]'],
        // Conforming LZW, so the ratio stays out of it and only the cap can stop it.
        'lzw behind flate' => [(string) gzcompress(Encoders::lzwFlat(tables: 10), 9), '[/FlateDecode /LZWDecode]'],
    };
}

// --- what the raise is for ---------------------------------------------------

it('renders flat white images this package wrote', function (int $w, int $h, bool $alpha, bool $hole) {
    $png = fpdFlatPng($w, $h, $alpha, $hole);
    $pdf = Agent::toBytes(['blocks' => [[
        'type' => 'image',
        'src' => ImageBuilder::dataUrl($png, 'image/png'),
        'widthPx' => 200,
        'heightPx' => 200,
    ]]]);

    // The fixture must really be in the class that used to be refused.
    $doc = Document::open($pdf);
    $ratios = [];
    foreach ($doc->objectNumbers() as $n) {
        $o = $doc->object($n);
        if ($o instanceof PdfStream && $o->dict->name('Subtype') === 'Image') {
            $data = $doc->streamData($o);
            if ($o->dict->name('ColorSpace') !== 'DeviceGray') {
                expect(strlen($data))->toBeGreaterThanOrEqual($w * $h * 3);
            }
            $ratios[] = strlen($data) / strlen($o->raw);
        }
    }
    expect(max($ratios))->toBeGreaterThan(500.0);

    $r = Renderer::render($pdf);

    expect($r->skipped)->toBe([])
        ->and($r->complete())->toBeTrue()
        ->and($r->pixel(100, 100))->toBe([255, 255, 255]);
})->with([
    '2880x2884 RGB' => [2880, 2884, false, false],
    '2880x2884 RGBA, opaque' => [2880, 2884, true, false],
    '2880x2884 RGBA, one transparent pixel' => [2880, 2884, true, true],
    '1000x1000 RGBA, one transparent pixel' => [1000, 1000, true, true],
]);

it('reads flat content at deflate\'s own ceiling', function () {
    $bomb = Encoders::bomb(32 << 20);
    expect((32 << 20) / strlen($bomb))->toBeGreaterThan(1000.0);

    $decoded = Filters::decodeChain(['FlateDecode'], $bomb);

    expect(strlen($decoded))->toBeGreaterThanOrEqual(32 << 20);
});

it('reads the flattest conforming LZW stream, which a table clear holds under the ratio', function () {
    $lzw = Encoders::lzwFlat(tables: 2);

    $decoded = Filters::decodeChain(['LZWDecode'], $lzw);

    // Each table is 1 + 2 + ... + 3839 bytes of the run.
    expect(strlen($decoded))->toBe(2 * intdiv(3839 * 3840, 2))
        ->and($decoded === str_repeat(' ', strlen($decoded)))->toBeTrue()
        ->and(strlen($decoded) / strlen($lzw))->toBeGreaterThan(1300.0);
});

// --- the byte cap ------------------------------------------------------------

it('refuses a stream over maxStreamBytes however flat it is', function () {
    $pdf = fpdPdf(Encoders::bomb(200 << 20), '/FlateDecode');

    $read = Agent::read($pdf);

    expect($read['ok'])->toBeFalse()
        ->and($read['refused']['reason'])->toBe('too_large')
        ->and($read['refused']['message'])->toContain((string) (new Limits())->maxStreamBytes);
});

it('holds the byte cap through every filter chain', function (string $name) {
    [$bytes, $filter] = fpdChain($name);
    $read = Agent::read(fpdPdf($bytes, $filter));

    expect($read['ok'])->toBeFalse()
        ->and($read['refused']['reason'])->toBe('too_large');
})->with(FPD_CHAINS);

it('holds the byte cap inside an ascii85 stage, not only after it', function () {
    $budget = new Budget(new Limits(maxStreamBytes: 1 << 20));
    memory_reset_peak_usage();
    $base = memory_get_usage();

    expect(fn () => Filters::decodeChain(['ASCII85Decode'], str_repeat('z', 8 << 20), [], $budget))
        ->toThrow(RefusedException::class);
    // The 8 MB input and its one working copy, not the 32 MB it would decode to.
    expect(memory_get_peak_usage() - $base)->toBeLessThan(24 << 20);
});

it('ends a chain over the byte cap in a refusal, never a fatal error, at a low memory_limit', function (string $name, string $memory) {
    [$bytes, $filter] = fpdChain($name);
    $child = fpdReadInChild(fpdPdf($bytes, $filter), $memory);

    expect($child['code'])->toBe(0, $child['out'])
        ->and($child['result'])->toBeArray()
        ->and($child['result']['ok'])->toBeFalse()
        ->and($child['result']['reason'])->toBeIn(['too_large', 'memory_budget']);
})->with(FPD_CHAINS)->with(['128M', '256M']);

it('ends a large flat file of ascii85 z in a refusal, never a fatal error', function (string $memory) {
    // No flate in front: the file itself holds the 70 MB, four times that decoded.
    $child = fpdReadInChild(fpdPdf(str_repeat('z', 70 << 20), '/ASCII85Decode'), $memory);

    expect($child['code'])->toBe(0, $child['out'])
        ->and($child['result']['ok'])->toBeFalse()
        ->and($child['result']['reason'])->toBeIn(['too_large', 'memory_budget']);
})->with(['256M', '512M']);

it('ends several flat streams on one page in a refusal or a read, never a fatal error', function (string $memory) {
    // Each stream is within every per-stream limit; together they lean on memory.
    $b = PdfBuilder::textPdf(['placeholder']);
    $bomb = Encoders::bomb(60 << 20);
    $tree = 2;
    $refs = [];
    for ($i = 0; $i < 8; $i++) {
        $refs[] = $b->rawStream($bomb, '/Filter /FlateDecode').' 0 R';
    }
    $b->object('<< /Type /Page /Parent '.$tree.' 0 R /Contents ['.implode(' ', $refs).'] >>', 5);
    $child = fpdReadInChild($b->build(), $memory);

    expect($child['code'])->toBe(0, $child['out'])
        ->and($child['result'])->toBeArray();
})->with(['128M', '512M']);
