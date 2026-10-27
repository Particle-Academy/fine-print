<?php

declare(strict_types=1);

use FinePrint\Agent;
use FinePrint\Exchange\Content;
use FinePrint\Exchange\ExportPolicy;
use FinePrint\Exchange\OptionsInvalid;
use FinePrint\Exchange\Report;
use FinePrint\Writer\Schema;
use ParticleAcademy\Conformance\Conformance;

/*
 * Every `exchange/*` suite whose manifest names `FinePrint\Agent` as an
 * implementation, run through fancy-conformance's own runner, plus the
 * fine-print half of `doc-model/shared`.
 *
 * composer.lock is NOT committed, so the require-dev constraint is the only
 * thing CI resolves from. It is EXACT, the way fancy-expr pins it, and the
 * first two tests make that a checked claim rather than a setting (rule 4 of
 * fancy-conformance's runners/README.md: print AND assert the pinned version).
 *
 * Rows are fed to the implementation decoded as JSON OBJECTS, not as PHP
 * associative arrays. `json_decode($x, true)` turns `{}` and `[]` into the same
 * value, and `exchange/content/0006` (an `items` that is an empty object) is
 * exactly that difference. The implementation accepts both decodings; the
 * object one is the one that can tell the two apart, so it is the one tested.
 */
const FPX_PINNED_SUITE_VERSION = '0.37.0';

/** The suites this package is named an implementation of, with a floor each. */
const FPX_SUITES = [
    'exchange/content' => 50,
    'exchange/limits' => 30,
    'exchange/policy' => 20,
    'exchange/report-kinds' => 35,
    'exchange/from-fine-print' => 9,
    'exchange/to-fine-print' => 11,
];

it('runs against the pinned fixture set, and says which', function (): void {
    echo "\nfancy-conformance installed: ".Conformance::version().', pinned: '.FPX_PINNED_SUITE_VERSION."\n";

    expect(Conformance::version())->toBe(FPX_PINNED_SUITE_VERSION);
});

it('pins the fixture set exactly in composer.json', function (): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require-dev']['particle-academy/fancy-conformance'] ?? null)->toBe(FPX_PINNED_SUITE_VERSION);
});

it('runs every exchange suite that names fine-print, and no other', function (): void {
    $named = [];
    foreach (Conformance::listSuites() as $suite) {
        if (! str_starts_with($suite, 'exchange/')) {
            continue;
        }
        foreach (Conformance::manifest($suite)['contract']['implementations'] ?? [] as $impl) {
            if (($impl['package'] ?? null) === 'particle-academy/fine-print') {
                $named[] = $suite;
            }
        }
    }
    sort($named);
    $ours = array_keys(FPX_SUITES);
    sort($ours);

    // A suite added for fine-print in a later fixture release fails here until
    // it is wired up, instead of being silently never run.
    expect($named)->toBe($ours);
});

/**
 * The row's input decoded as JSON objects (stdClass), found by id.
 *
 * @return array<string, mixed> input keys => object-decoded values
 */
function fpxObjectInput(string $suite, string $id): array
{
    static $cache = [];
    if (! isset($cache[$suite])) {
        $raw = (string) file_get_contents(Conformance::root().'/suites/'.$suite.'/cases.json');
        $cache[$suite] = [];
        foreach (json_decode($raw, false, 512, JSON_THROW_ON_ERROR)->cases as $case) {
            $cache[$suite][$case->id] = $case->input;
        }
    }

    return get_object_vars($cache[$suite][$id]);
}

/** Run one row; an `options_invalid` throw is the value `{throws: "options_invalid"}`. */
function fpxRun(string $suite, array $case): mixed
{
    $in = fpxObjectInput($suite, $case['id']);

    try {
        return match ($case['fn']) {
            'validateContent' => Content::validate($in['content'], $in['options'] ?? null),
            'truncateCell' => array_key_exists('maxBytes', $in)
                ? Content::truncateCell($in['text'], $in['maxBytes'])
                : Content::truncateCell($in['text']),
            'checkExport' => ExportPolicy::checkExport($in),
            'enabledTargets' => ExportPolicy::enabledTargetsFor($in),
            'classifyKind' => Report::classifyKind($in['kind']),
            'mergeReports' => Report::merge(Content::toArray($in['a']), Content::toArray($in['b'])),
            'toPortable' => Agent::toPortable(Content::toArray($in['model']), Content::toArray($in['selector']), Content::toArray($in['options'] ?? [])),
            'fromPortable' => Agent::fromPortable($in['content']),
        };
    } catch (OptionsInvalid) {
        return ['throws' => 'options_invalid'];
    }
}

it('passes every row of every applicable exchange suite', function (string $suite, int $floor): void {
    $summary = Conformance::runTable($suite, fn (array $case): mixed => fpxRun($suite, $case));

    echo "\n".Conformance::formatSummary($summary)."\n";

    $failures = array_filter($summary['results'], static fn (array $r): bool => $r['status'] === 'fail');
    expect(array_column($failures, 'id'))->toBe([])
        ->and($summary['failed'])->toBe(0)
        ->and($summary['skipped'])->toBe(0)
        // The vacuity floor: a suite that loaded nothing reports zero failures too.
        ->and($summary['passed'])->toBeGreaterThanOrEqual($floor);
})->with(array_map(null, array_keys(FPX_SUITES), array_values(FPX_SUITES)));

/*
 * doc-model/shared compares the last-word and fine-print schemas. It is run
 * here against fine-print's half only: fine-print takes no dependency on
 * last-word, not even a dev one, and the rows that are red against last-word
 * (0002, 0003: page.background / page.border, owner decision 11) are
 * last-word's to fix. What fine-print's schema must satisfy on every row:
 *   compare    -> its own property set is exactly both + finePrintOnly
 *   constraint -> its own keywords are exactly `finePrint`
 * `$defs.block` is the document model. The inline `portable` node is an INPUT
 * that validateAndRepair expands away; it is referenced beside the model
 * (`$defs.blockOrPortable`) and never joins it, so it is not a block type.
 */

/** @return array<string, mixed> the fine-print schema node for a doc-model definition */
function fpxSchemaDef(string $def): array
{
    $s = Schema::jsonSchema();
    $blocks = [];
    foreach ($s['$defs']['block']['oneOf'] as $b) {
        $blocks[$b['properties']['type']['const']] = $b;
    }
    $table = $blocks['table']['properties'];

    return match ($def) {
        'document' => $s,
        'page' => $s['properties']['page'],
        'pageBorder' => $s['properties']['page']['properties']['border'],
        'band' => $s['$defs']['band'],
        'run' => $s['$defs']['run'],
        'paragraph', 'heading', 'table', 'image' => $blocks[$def],
        'listItem' => $s['$defs']['listItem'],
        'tableRow' => $table['rows']['items'],
        'tableCell' => $table['rows']['items']['properties']['cells']['items'],
        'border' => $s['$defs']['border'],
    };
}

/** @return list<string> */
function fpxFinePrintFields(string $def): array
{
    if ($def === 'blockTypes') {
        $names = array_map(fn (array $b) => $b['properties']['type']['const'], Schema::jsonSchema()['$defs']['block']['oneOf']);
    } else {
        $names = array_keys(fpxSchemaDef($def)['properties']);
    }
    sort($names, SORT_STRING);

    return $names;
}

/** @return array<string, mixed> */
function fpxFinePrintConstraint(string $path): array
{
    $s = Schema::jsonSchema();
    $node = match ($path) {
        'run.link' => $s['$defs']['run']['properties']['link'],
        'run.size' => $s['$defs']['run']['properties']['size'],
        'defaultSize' => $s['properties']['defaultSize'],
        'band.size' => $s['$defs']['band']['properties']['size'],
        'image.src' => fpxSchemaDef('image')['properties']['src'],
        'page.size' => $s['properties']['page']['properties']['size'],
    };

    return array_intersect_key($node, array_flip(['type', 'pattern', 'enum', 'minimum', 'maximum', 'exclusiveMinimum']));
}

it('satisfies the fine-print half of every doc-model/shared row', function (): void {
    $cases = Conformance::cases('doc-model/shared');
    $passed = 0;
    $failed = [];
    foreach ($cases as $case) {
        if ($case['fn'] === 'compare') {
            $want = array_merge($case['expected']['both'], $case['expected']['finePrintOnly']);
            sort($want, SORT_STRING);
            $got = fpxFinePrintFields($case['input']['def']);
        } else {
            $want = $case['expected']['finePrint'];
            $got = fpxFinePrintConstraint($case['input']['def']);
        }
        if (Conformance::equals($got, $want)) {
            $passed++;
        } else {
            $failed[] = $case['id'].' expected '.json_encode($want).' got '.json_encode($got);
        }
    }

    echo "\ndoc-model/shared [php, fine-print half] - fancy-conformance ".Conformance::version()
        ."\n  {$passed} passed, ".count($failed).' failed, 0 skipped (of '.count($cases).")\n";

    expect($failed)->toBe([])
        ->and($passed)->toBe(count($cases))
        ->and($passed)->toBeGreaterThanOrEqual(20);
});
