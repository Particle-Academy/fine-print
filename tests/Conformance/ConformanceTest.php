<?php

declare(strict_types=1);

use FinePrint\Tests\Conformance\Export;
use ParticleAcademy\Conformance\Conformance;

/*
 * PHP passes its OWN exported rows: every `fine-print/*` suite, read back from
 * the installed fixture set through fancy-conformance's loader (JSON decoded,
 * file references resolved by fixtureBytes(), digests checked), replayed
 * through PHP, compared by the loader's own equals().
 *
 * This is the reference half of the ports' contract. The rows are only worth
 * something to fine-print-js and fine-print-py if the implementation they were
 * exported from still produces them after the trip through JSON and disk, on
 * every platform CI runs.
 *
 * The fixture set is pinned EXACTLY in require-dev (no composer.lock is
 * committed), and the first two tests make the pin a checked claim.
 */
const FPC_PINNED_SUITE_VERSION = '0.38.0';

/** Exact row counts. A row lost between the exporter and the loader fails here. */
const FPC_ROWS = [
    'fine-print/php-semantics' => 90,
    'fine-print/writer-model' => 57,
    'fine-print/writer-bytes' => 51,
];

it('runs against the pinned fixture set, and says which', function (): void {
    echo "\nfancy-conformance installed: ".Conformance::version().', pinned: '.FPC_PINNED_SUITE_VERSION."\n";

    expect(Conformance::version())->toBe(FPC_PINNED_SUITE_VERSION);
});

it('pins the fixture set exactly in composer.json', function (): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require-dev']['particle-academy/fancy-conformance'] ?? null)->toBe(FPC_PINNED_SUITE_VERSION);
});

it('runs every fine-print suite in the fixture set, and no other', function (): void {
    $found = array_values(array_filter(Conformance::listSuites(), fn (string $s) => str_starts_with($s, 'fine-print/')));
    $ours = Export::SUITES;
    sort($found);
    sort($ours);

    // A fine-print suite added in a later fixture release fails here until it
    // is wired into Export::call(), instead of being silently never run.
    expect($found)->toBe($ours)
        ->and(array_keys(FPC_ROWS))->toBe(Export::SUITES);
});

it('passes every row of its own exported suite', function (string $suite): void {
    $summary = Conformance::runTable($suite, fn (array $case) => Export::call(
        $suite,
        (string) $case['fn'],
        Export::resolve($case['input'], fn (array $ref) => Conformance::fixtureBytes($suite, $ref)),
    ));
    echo "\n".Conformance::formatSummary($summary)."\n";

    expect($summary['ok'])->toBeTrue(Conformance::formatSummary($summary))
        ->and($summary['skipped'])->toBe(0)            // the reference never skips its own rows
        ->and($summary['passed'])->toBe(FPC_ROWS[$suite]);
})->with(Export::SUITES);

it('writes the raw-tier PDFs byte for byte, the artifact included', function (): void {
    // The fingerprint compares digests; this compares the BYTES, so a raw
    // artifact that drifted from the golden it sits beside is caught too.
    $raw = 0;
    foreach (Conformance::cases('fine-print/writer-bytes') as $case) {
        if ($case['input']['tier'] !== 'raw') {
            continue;
        }
        $input = Export::resolve($case['input'], fn (array $ref) => Conformance::fixtureBytes('fine-print/writer-bytes', $ref));
        $pdf = FinePrint\Agent::toBytes($input['doc'], $input['options']);
        expect($pdf === Conformance::fixtureBytes('fine-print/writer-bytes', $case['artifacts']['pdf']))->toBeTrue($case['id']);
        $raw++;
    }
    expect($raw)->toBeGreaterThanOrEqual(30);
});

it('finds no deflate in a raw-tier row and some in every canon-tier row', function (): void {
    foreach (Conformance::cases('fine-print/writer-bytes') as $case) {
        $input = Export::resolve($case['input'], fn (array $ref) => Conformance::fixtureBytes('fine-print/writer-bytes', $ref));
        $pdf = FinePrint\Agent::toBytes($input['doc'], $input['options']);

        expect(str_contains($pdf, '/FlateDecode'))->toBe($case['input']['tier'] === 'canon', $case['id']);
    }
});
