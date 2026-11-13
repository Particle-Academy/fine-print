<?php

declare(strict_types=1);

use FinePrint\Agent;
use FinePrint\Document;
use FinePrint\Exceptions\SchemaException;
use FinePrint\Writer\Schema;
use FinePrint\Writer\Signable;

/*
 * The signing extension: field nodes, the placement map, the generated final
 * signature page and per-page marks. Field nodes are OPT-IN (`fields: true`):
 * last-word's model does not have them, and the default schema is the shared
 * doc-model one, so a document that names a field without opting in is
 * refused exactly as any unknown block type is.
 */

/** WriterSecurityTest's list, repeated so this file runs on its own. */
const FPS_FORBIDDEN = ['/JavaScript', '/JS', '/OpenAction', '/AA', '/Launch', '/AcroForm', '/SubmitForm', '/ImportData',
    '/EmbeddedFile', '/RichMedia', '/XFA', '/GoToR', '/GoToE', '/Names', '/Widget', '/Sound', '/Movie', '/Rendition'];

function fpsField(string $kind, string $id, string $signer = 'alice', array $props = []): array
{
    return ['type' => $kind, 'id' => $id, 'signer' => $signer] + $props;
}

function fpsSigners(): array
{
    return [
        ['id' => 'alice', 'name' => 'Alice Example', 'email' => 'alice@example.com', 'initials' => 'AE'],
        ['id' => 'bob', 'name' => 'Bob Example', 'email' => 'bob@example.com', 'initials' => 'BE'],
    ];
}

/** A document of `$paragraphs` filler paragraphs: about 13 to a letter page. */
function fpsLongDoc(int $paragraphs, array $tail = []): array
{
    $blocks = [];
    for ($i = 1; $i <= $paragraphs; $i++) {
        $blocks[] = fpwPara(fpwSentence($i).' '.fpwSentence($i + 1000).' '.fpwSentence($i + 2000));
    }

    return ['blocks' => array_merge($blocks, $tail)];
}

// ---------------------------------------------------------------- refusals first

it('refuses a field node when the signing extension is not on', function () {
    $doc = ['blocks' => [fpsField('signatureField', 'sig-1')]];

    $errors = Agent::validate($doc);
    expect($errors)->not->toBe([])
        ->and($errors[0]['path'])->toBe('blocks[0].type')
        ->and($errors[0]['message'])->toContain('fields: true');

    expect(fn () => Agent::toBytes($doc))->toThrow(SchemaException::class);
});

it('refuses a field with a missing or malformed id or signer', function (array $field, string $path) {
    $errors = Agent::validate(['blocks' => [$field]], ['fields' => true]);

    expect(array_column($errors, 'path'))->toContain($path);
})->with([
    'no id' => [['type' => 'signatureField', 'signer' => 'alice'], 'blocks[0].id'],
    'id with a space' => [fpsField('signatureField', 'sig 1'), 'blocks[0].id'],
    'id too long' => [fpsField('signatureField', str_repeat('a', 65)), 'blocks[0].id'],
    'no signer' => [['type' => 'initialsField', 'id' => 'i1'], 'blocks[0].signer'],
    'signer with a slash' => [fpsField('dateSignedField', 'd1', 'a/b'), 'blocks[0].signer'],
    'required not a bool' => [fpsField('signatureField', 's1', 'alice', ['required' => 'yes']), 'blocks[0].required'],
    'label too long' => [fpsField('signatureField', 's1', 'alice', ['label' => str_repeat('x', 201)]), 'blocks[0].label'],
    'unknown key' => [fpsField('signatureField', 's1', 'alice', ['x' => 1]), 'blocks[0].x'],
]);

it('refuses two fields with the same id, wherever they sit', function () {
    $doc = ['blocks' => [
        fpsField('signatureField', 'dup'),
        ['type' => 'quote', 'blocks' => [fpsField('initialsField', 'dup')]],
    ]];

    $errors = Agent::validate($doc, ['fields' => true]);
    expect(array_column($errors, 'path'))->toContain('blocks[1].blocks[0].id');
});

it('refuses a field in a page band or a footnote', function () {
    $band = ['page' => ['footer' => ['blocks' => [fpsField('initialsField', 'i1')]]], 'blocks' => [fpwPara('x')]];
    expect(Agent::validate($band, ['fields' => true]))->not->toBe([]);

    $note = ['blocks' => [['type' => 'paragraph', 'runs' => [
        ['text' => 'x', 'footnote' => ['blocks' => [fpsField('signatureField', 's1')]]],
    ]]]];
    expect(Agent::validate($note, ['fields' => true]))->not->toBe([]);
});

it('keeps the default JSON schema the shared doc-model one, and adds fields only on request', function () {
    $types = fn (array $s) => array_map(fn (array $b) => $b['properties']['type']['const'], $s['$defs']['block']['oneOf']);

    expect($types(Schema::jsonSchema()))->not->toContain('signatureField')
        ->and($types(Agent::jsonSchema()))->not->toContain('signatureField')
        ->and($types(Schema::jsonSchema(fields: true)))->toContain('signatureField', 'initialsField', 'dateSignedField');
});

it('refuses a signature page or page marks that name a signer badly', function (array $options) {
    expect(fn () => Signable::write(['blocks' => [fpwPara('x')]], $options))->toThrow(SchemaException::class);
})->with([
    'signer id with a space' => [['signaturePage' => ['signers' => [['id' => 'a b', 'name' => 'A']]]]],
    'signer name too long' => [['signaturePage' => ['signers' => [['id' => 'a', 'name' => str_repeat('n', 201)]]]]],
    'too many signers' => [['signaturePage' => ['signers' => array_map(fn (int $i) => ['id' => "s{$i}", 'name' => "S{$i}"], range(1, 51))]]],
    'unknown mark kind' => [['pageMarks' => ['kind' => 'stamp', 'signers' => [['id' => 'a', 'label' => 'A']]]]],
    'mark label too long' => [['pageMarks' => ['kind' => 'initials', 'signers' => [['id' => 'a', 'label' => str_repeat('A', 9)]]]]],
]);

// ---------------------------------------------------------------- the placement map

it('lays out each field kind as a box and reports where it landed', function () {
    $out = Signable::write(['blocks' => [
        fpwPara('Accepted by:'),
        fpsField('signatureField', 'sig-alice', 'alice', ['label' => 'Signature']),
        fpsField('initialsField', 'ini-alice', 'alice'),
        fpsField('dateSignedField', 'date-alice', 'alice', ['required' => false]),
    ]]);

    expect($out['pages'])->toBe(1)
        ->and(array_column($out['fields'], 'id'))->toBe(['sig-alice', 'ini-alice', 'date-alice']);

    $byId = array_column($out['fields'], null, 'id');
    foreach ($byId as $f) {
        [$x0, $y0, $x1, $y1] = $f['rect'];
        expect($f['page'])->toBe(1)
            ->and($f['origin'])->toBe('node')
            ->and($f['signer'])->toBe('alice')
            ->and($x0)->toBeGreaterThanOrEqual(72.0)->and($x1)->toBeLessThanOrEqual(540.0)
            ->and($y0)->toBeGreaterThan(0.0)->and($y1)->toBeLessThanOrEqual(792.0)
            ->and($x1)->toBeGreaterThan($x0)->and($y1)->toBeGreaterThan($y0);
    }
    // A signature box is wider and taller than initials; fields stack down the page.
    $w = fn (array $f) => $f['rect'][2] - $f['rect'][0];
    expect($w($byId['sig-alice']))->toBeGreaterThan($w($byId['ini-alice']))
        ->and($byId['ini-alice']['rect'][1])->toBeLessThan($byId['sig-alice']['rect'][1])
        ->and($byId['sig-alice']['kind'])->toBe('signature')
        ->and($byId['ini-alice']['kind'])->toBe('initials')
        ->and($byId['date-alice']['kind'])->toBe('date_signed')
        ->and($byId['date-alice']['required'])->toBeFalse()
        ->and($byId['sig-alice']['required'])->toBeTrue();

    // The PDF is still a plain one: the label is text, the field is drawing, nothing is a widget.
    $read = fpwRead($out['bytes']);
    expect($read['pages'][0]['text'])->toContain('Signature')
        ->and($read['activeContent'])->toBe([]);
    foreach (FPS_FORBIDDEN as $name) {
        expect(preg_match('#'.preg_quote($name, '#').'(?![A-Za-z])#', $out['bytes']))->toBe(0);
    }
});

it('computes placement from the layout, so a re-flowed document moves the field', function () {
    $short = Signable::write(['blocks' => [fpwPara('Intro'), fpsField('signatureField', 's1')]]);
    $long = Signable::write(fpsLongDoc(20, [fpsField('signatureField', 's1')]));

    expect($short['fields'][0]['page'])->toBe(1)
        ->and($long['fields'][0]['page'])->toBeGreaterThan(1)
        ->and($long['fields'][0]['rect'])->not->toBe($short['fields'][0]['rect']);
});

it('places a field inside a table cell', function () {
    $out = Signable::write(['blocks' => [['type' => 'table', 'rows' => [
        ['cells' => [['blocks' => [fpwPara('Alice')]], ['blocks' => [fpsField('signatureField', 'cell-sig')]]]],
    ]]]]);

    expect($out['fields'][0]['id'])->toBe('cell-sig')
        ->and($out['fields'][0]['rect'][0])->toBeGreaterThan(200.0);
});

it('is deterministic', function () {
    $doc = ['blocks' => [fpwPara('Terms'), fpsField('signatureField', 's1')]];
    $options = ['signaturePage' => ['reference' => 'ENV-1', 'signers' => fpsSigners()], 'pageMarks' => ['kind' => 'initials', 'signers' => fpsSigners()]];

    expect(Signable::write($doc, $options)['bytes'])->toBe(Signable::write($doc, $options)['bytes']);
});

// ---------------------------------------------------------------- the final signature page

it('appends a generated signature page with a signature and a date field per signer', function () {
    $out = Signable::write(fpsLongDoc(3), ['signaturePage' => [
        'reference' => 'ENV-01JD3X', 'contentHash' => str_repeat('ab', 32), 'signers' => fpsSigners(),
    ]]);

    expect($out['pages'])->toBe($out['authoredPages'] + 1);
    $final = $out['pages'];
    $finals = array_values(array_filter($out['fields'], fn (array $f) => $f['origin'] === 'final_page'));
    expect(array_column($finals, 'id'))->toBe(['final-signature-alice', 'final-date-alice', 'final-signature-bob', 'final-date-bob'])
        ->and(array_unique(array_column($finals, 'page')))->toBe([$final]);

    $text = Document::open($out['bytes'])->text($final);
    expect($text)->toContain('Alice Example')->toContain('bob@example.com')
        ->toContain('ENV-01JD3X')->toContain(str_repeat('ab', 32));
});

it('fits many signers on more than one final page, all after the authored ones', function () {
    $signers = array_map(fn (int $i) => ['id' => "s{$i}", 'name' => "Signer {$i}", 'email' => "s{$i}@example.com"], range(1, 12));
    $out = Signable::write(['blocks' => [fpwPara('One page')]], ['signaturePage' => ['signers' => $signers]]);

    expect($out['authoredPages'])->toBe(1)
        ->and($out['pages'])->toBeGreaterThan(2);
    foreach ($out['fields'] as $f) {
        expect($f['page'])->toBeGreaterThan(1);
    }
});

it('reports authoredPages as the page count when there is no signature page', function () {
    $out = Signable::write(fpsLongDoc(20));
    expect($out['authoredPages'])->toBe($out['pages']);
});

// ---------------------------------------------------------------- per-page marks

it('puts one mark per signer on every authored page and NEVER on the final page', function (int $paragraphs, string $kind) {
    $out = Signable::write(fpsLongDoc($paragraphs), [
        'signaturePage' => ['signers' => fpsSigners()],
        'pageMarks' => ['kind' => $kind, 'signers' => fpsSigners()],
    ]);
    $n = $out['authoredPages'];
    $marks = array_values(array_filter($out['fields'], fn (array $f) => $f['origin'] === 'page_mark'));

    expect($n)->toBeGreaterThanOrEqual(1)
        ->and(count($marks))->toBe($n * 2);
    foreach ($marks as $m) {
        expect($m['page'])->toBeLessThanOrEqual($n)
            ->and($m['kind'])->toBe($kind)
            ->and($m['id'])->toBe("page-{$m['page']}-{$kind}-{$m['signer']}");
        // In the bottom margin, under the body.
        expect($m['rect'][3])->toBeLessThanOrEqual(72.0);
    }
    for ($p = 1; $p <= $n; $p++) {
        expect(count(array_filter($marks, fn (array $m) => $m['page'] === $p)))->toBe(2);
    }
    expect(array_filter($out['fields'], fn (array $f) => $f['page'] > $n && $f['origin'] === 'page_mark'))->toBe([]);
})->with([
    'one-page document, initials' => [1, 'initials'],
    'one-page document, signature' => [1, 'signature'],
    'multi-page document, initials' => [30, 'initials'],
    'multi-page document, signature' => [30, 'signature'],
]);

it('marks every page when there is no final page', function () {
    $out = Signable::write(fpsLongDoc(30), ['pageMarks' => ['kind' => 'initials', 'signers' => [fpsSigners()[0]]]]);
    expect(count($out['fields']))->toBe($out['pages']);
});

it('warns when the bottom margin leaves no room for a mark, and places it anyway', function () {
    $out = Signable::write(['page' => ['margins' => ['bottom' => 14.4]], 'blocks' => [fpwPara('x')]], [
        'pageMarks' => ['kind' => 'signature', 'signers' => [fpsSigners()[0]]],
    ]);

    expect(array_column($out['diagnostics'], 'rule'))->toContain('page-mark-no-room')
        ->and($out['fields'])->toHaveCount(1);
});
