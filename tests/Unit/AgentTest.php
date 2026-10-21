<?php

declare(strict_types=1);

use FinePrint\Agent;
use FinePrint\Tests\Support\PdfBuilder;

/*
 * The Agent surface's shape and its single-sourced version.
 */

it('counts pages from bytes or from a path', function () {
    $pdf = fpText('one', 'two');
    $path = tempnam(sys_get_temp_dir(), 'fp-').'.pdf';
    file_put_contents($path, $pdf);

    try {
        expect(Agent::pageCount($pdf))->toBe(2)
            ->and(Agent::pageCount($path))->toBe(2)
            ->and(Agent::read($path)['pageCount'])->toBe(2);
    } finally {
        @unlink($path);
    }
});

it('reads a document into the documented shape', function () {
    $read = Agent::read(PdfBuilder::textPdf([PdfBuilder::line('Hello')], ['mediaBox' => '[0 0 595 842]'])->build());

    expect(array_keys($read))->toBe(['ok', 'version', 'pageCount', 'repaired', 'pages', 'metadata', 'outline', 'annotations', 'activeContent', 'warnings', 'guard', 'redacted', 'unredacted', 'notice', 'encryption'])
        ->and($read['pages'])->toBe([['page' => 1, 'width' => 595.0, 'height' => 842.0, 'text' => 'Hello']])
        ->and($read['guard'])->toBe(['policy' => 'redact', 'threshold' => 'medium', 'discarded' => false, 'findings' => []]);
});

it('accepts limits as an array of named values', function () {
    $read = Agent::read(fpText('x'), ['limits' => ['maxFileBytes' => 10]]);

    expect($read['refused']['reason'])->toBe('too_large');
});

it('rejects an unknown limit name', function () {
    Agent::read(fpText('x'), ['limits' => ['maxBananas' => 1]]);
})->throws(InvalidArgumentException::class);

/**
 * `version()` must not be able to lie — the sibling packages each shipped a
 * stale VERSION constant before this test existed in all of them.
 *
 * Until the first release there is no version heading, only [Unreleased];
 * then the constant must be the first version this package will ship.
 */
it('reports the version it actually ships as', function () {
    $changelog = (string) file_get_contents(__DIR__.'/../../CHANGELOG.md');
    preg_match_all('/^## \[?v?(\d+\.\d+\.\d+)\]?/m', $changelog, $m);

    $expected = $m[1][0] ?? '0.1.0';
    expect(Agent::VERSION)->toBe(
        $expected,
        'the VERSION constant and the newest changelog entry disagree. Fix the constant.',
    )->and(Agent::version())->toBe(Agent::VERSION);
});

it('is a semver triple', function () {
    expect(Agent::VERSION)->toMatch('/^\d+\.\d+\.\d+$/');
});
