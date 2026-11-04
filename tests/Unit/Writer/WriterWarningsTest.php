<?php

declare(strict_types=1);

use FinePrint\Agent;
use FinePrint\Tests\Support\ImageBuilder;

/*
 * A valid setting never vanishes silently. Whatever the writer cannot honour is
 * a Diagnostics finding, and `Agent::write` returns it as `warnings`, so a host
 * that never calls lint still hears about it.
 */

function fpoTmpPdf(): string
{
    return tempnam(sys_get_temp_dir(), 'fpo');
}

/** @return list<array<string, mixed>> */
function fpoWarnings(array $doc, array $options = []): array
{
    $path = fpoTmpPdf();
    try {
        return Agent::write($doc, $path, $options)['warnings'];
    } finally {
        unlink($path);
    }
}

it('returns warnings from write, empty for a clean document', function () {
    $path = fpoTmpPdf();
    try {
        $out = Agent::write(['blocks' => [fpwPara('Clean')]], $path);
    } finally {
        unlink($path);
    }

    expect($out['warnings'])->toBe([])
        ->and($out)->toHaveKeys(['path', 'bytes', 'pages', 'warnings']);
});

it('warns from write on what lint already knew', function () {
    $rules = array_column(fpoWarnings(['blocks' => [fpwPara('Привет'), ['type' => 'paragraph', 'runs' => [['text' => 'x', 'smallCaps' => true]]]]]), 'rule');

    expect($rules)->toContain('unencodable')->toContain('small-caps');
});

it('warns about a literal {date}, {time} and {filename} in a band: this writer stamps no time', function (string $token, array $page, string $path) {
    $doc = ['page' => $page, 'blocks' => [fpwPara('x')]];
    $found = array_values(array_filter(fpoWarnings($doc), fn ($w) => $w['rule'] === 'band-field-literal'));

    expect($found)->toHaveCount(1)
        ->and($found[0]['path'])->toBe($path)
        ->and($found[0]['severity'])->toBe('warning')
        ->and(fpwRead(fpwWrite($doc))['pages'][0]['text'])->toContain($token);
    expect(array_column(Agent::lint($doc), 'rule'))->toContain('band-field-literal');
})->with([
    'date' => ['{date}', ['footer' => ['left' => '{date}']], 'page.footer.left'],
    'date with a picture' => ['{date:d MMMM yyyy}', ['header' => ['right' => 'As of {date:d MMMM yyyy}']], 'page.header.right'],
    'time' => ['{time}', ['footer' => ['center' => '{time}']], 'page.footer.center'],
    'filename' => ['{filename}', ['footer' => ['blocks' => [fpwPara('{filename}')]]], 'page.footer.blocks[0].runs[0]'],
]);

it('does not warn about braces that are not a field, nor about a field in the body', function () {
    $rules = array_column(fpoWarnings(['page' => ['footer' => ['left' => '{Date} {pagex}']], 'blocks' => [fpwPara('{date}')]]), 'rule');

    expect($rules)->toBe([]);
});

it('expands {title} and {author}, and writes the author into the metadata', function () {
    $doc = ['title' => 'The Report', 'meta' => ['author' => 'Ada'], 'page' => ['footer' => ['left' => '{title} by {author}']], 'blocks' => [fpwPara('x')]];
    $read = fpwRead(fpwWrite($doc));

    expect($read['pages'][0]['text'])->toContain('The Report by Ada')
        ->and($read['metadata']['Author'] ?? $read['metadata']['author'] ?? null)->toBe('Ada');
});

it('expands an absent {title} or {author} to nothing, as the viewer does', function () {
    expect(fpwRead(fpwWrite(['page' => ['footer' => ['left' => '[{title}|{author}]']], 'blocks' => [fpwPara('x')]]))['pages'][0]['text'])->toContain('[|]');
});

it('validates meta as last-word does', function () {
    expect(array_column(Agent::validate(['meta' => 'me', 'blocks' => []]), 'path'))->toBe(['meta'])
        ->and(array_column(Agent::validate(['meta' => ['author' => 7], 'blocks' => []]), 'path'))->toBe(['meta.author'])
        ->and(array_column(Agent::validate(['meta' => ['title' => 'T'], 'blocks' => []]), 'path'))->toBe(['meta.title']);
});

it('repair moves meta.title to the title, as last-word does', function () {
    $r = Agent::validateAndRepair(['meta' => ['title' => 'T', 'author' => 'A'], 'blocks' => []]);

    expect($r['ok'])->toBeTrue()
        ->and($r['schema']['title'])->toBe('T')
        ->and($r['schema']['meta'])->toBe(['author' => 'A']);
});

it('reports image alt text, which a PDF from this writer cannot carry', function () {
    $img = ImageBuilder::dataUrl(ImageBuilder::png(2, 2, 2, 8, fn () => [1, 2, 3]), 'image/png');
    $found = array_values(array_filter(fpoWarnings(['blocks' => [['type' => 'image', 'src' => $img, 'alt' => 'A chart']]]), fn ($w) => $w['rule'] === 'image-alt'));

    expect($found)->toHaveCount(1)
        ->and($found[0]['path'])->toBe('blocks[0]')
        ->and($found[0]['severity'])->toBe('info')
        ->and($found[0]['message'])->not->toContain('A chart');
});

it('reports a code language, which draws nothing here', function () {
    $found = array_values(array_filter(fpoWarnings(['blocks' => [['type' => 'code', 'language' => 'php', 'text' => 'x']]]), fn ($w) => $w['rule'] === 'code-language'));

    expect($found)->toHaveCount(1)
        ->and($found[0]['path'])->toBe('blocks[0]');
});

/*
 * The sweep, as a test. Every key last-word's schema declares is either honoured
 * or warned about; this document sets all of them, and each has a row saying
 * which. A key with no row fails, so a later addition has to say what it does.
 */
it('accounts for every model key: each is honoured or warned about', function () {
    $s = Agent::jsonSchema();
    $declared = [];
    $add = function (string $def, array $props) use (&$declared): void {
        foreach (array_keys($props) as $k) {
            $declared[] = "{$def}.{$k}";
        }
    };
    $add('document', $s['properties']);
    $add('page', $s['properties']['page']['properties']);
    $add('margins', $s['$defs']['pageMargins']['properties']);
    $add('band', $s['$defs']['band']['properties']);
    $add('run', $s['$defs']['run']['properties']);
    $add('listItem', $s['$defs']['listItem']['properties']);
    foreach ($s['$defs']['block']['oneOf'] as $b) {
        $type = $b['properties']['type']['const'];
        $add($type, array_diff_key($b['properties'], ['type' => 1]));
        if ($type === 'table') {
            $add('tableRow', $b['properties']['rows']['items']['properties']);
            $add('tableCell', $b['properties']['rows']['items']['properties']['cells']['items']['properties']);
        }
    }
    sort($declared);

    $warned = ['run.smallCaps' => 'small-caps', 'image.alt' => 'image-alt', 'code.language' => 'code-language'];
    $honoured = [
        'document.blocks', 'document.defaultFont', 'document.defaultSize', 'document.meta', 'document.page', 'document.title',
        'page.background', 'page.border', 'page.evenFooter', 'page.evenHeader', 'page.firstFooter', 'page.firstHeader', 'page.footer', 'page.header', 'page.margins', 'page.orientation', 'page.size',
        'margins.bottom', 'margins.footer', 'margins.header', 'margins.left', 'margins.right', 'margins.top',
        'band.center', 'band.color', 'band.left', 'band.right', 'band.size',
        'run.bold', 'run.code', 'run.color', 'run.font', 'run.footnote', 'run.highlight', 'run.italic', 'run.letterSpacing', 'run.link', 'run.size', 'run.strike', 'run.text', 'run.underline',
        'listItem.align', 'listItem.borders', 'listItem.children', 'listItem.indentLeft', 'listItem.indentRight', 'listItem.keepNext', 'listItem.lineHeight', 'listItem.runs', 'listItem.shading', 'listItem.spaceAfter', 'listItem.spaceBefore',
        'heading.align', 'heading.borders', 'heading.indentLeft', 'heading.indentRight', 'heading.keepNext', 'heading.level', 'heading.lineHeight', 'heading.runs', 'heading.shading', 'heading.spaceAfter', 'heading.spaceBefore',
        'paragraph.align', 'paragraph.borders', 'paragraph.indentLeft', 'paragraph.indentRight', 'paragraph.keepNext', 'paragraph.lineHeight', 'paragraph.runs', 'paragraph.shading', 'paragraph.spaceAfter', 'paragraph.spaceBefore',
        'list.items', 'list.ordered',
        'table.align', 'table.borders', 'table.cellPadding', 'table.keepNext', 'table.rows', 'table.width', 'table.widths',
        'tableRow.cells', 'tableRow.header',
        'tableCell.blocks', 'tableCell.borders', 'tableCell.colSpan', 'tableCell.padding', 'tableCell.rowSpan', 'tableCell.shading', 'tableCell.valign',
        'code.keepNext', 'code.text',
        'quote.blocks',
        'image.heightPx', 'image.keepNext', 'image.src', 'image.widthPx',
        'pageBreak.page',
        'hr.keepNext',
    ];
    $accounted = [...$honoured, ...array_keys($warned)];
    sort($accounted);

    expect($declared)->toBe($accounted);
});
