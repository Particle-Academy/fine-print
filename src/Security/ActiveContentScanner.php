<?php

declare(strict_types=1);

namespace FinePrint\Security;

use FinePrint\Document;
use FinePrint\Objects\PdfDict;
use FinePrint\Objects\PdfName;
use FinePrint\Objects\PdfStream;
use FinePrint\Objects\PdfString;

/**
 * Finds active content: anything in a PDF that can run, launch, fetch, submit
 * or carry another file.
 *
 * It DETECTS and REPORTS. It has no code path that executes a script, opens a
 * file, resolves a URI or submits a form, and must never gain one: the owner's
 * ruling is that scripts in a PDF are hostile input and forms are unsupported.
 *
 * Every indirect object the cross-reference data lists is scanned — not only
 * what is reachable from the catalog — because a viewer may reach an object
 * by a path this reader does not model. Names are compared DECODED, so a
 * `/J#61vaScript` escape does not hide anything.
 *
 * A finding never carries the script body, the launch target or the URI.
 * Those are attacker-authored text; a report handed to an agent must not
 * become the injection channel it is warning about. Scripts are identified by
 * length and SHA-256 instead, which is enough for a human to match one.
 */
final class ActiveContentScanner
{
    public const SEVERITY = [
        'javascript' => 'high',
        'launch' => 'high',
        'submit-form' => 'high',
        'import-data' => 'high',
        'xfa' => 'high',
        'open-action' => 'medium',
        'additional-actions' => 'medium',
        'embedded-file' => 'medium',
        'rich-media' => 'medium',
        'remote-goto' => 'medium',
        'uri' => 'low',
        'acroform' => 'low',
    ];

    private const ACTIONS = [
        'Launch' => 'launch',
        'URI' => 'uri',
        'SubmitForm' => 'submit-form',
        'ImportData' => 'import-data',
        'GoToR' => 'remote-goto',
        'GoToE' => 'remote-goto',
    ];

    /** Direct-object nesting walked inside one indirect object. */
    private const MAX_DEPTH = 32;

    /** @var array<string, array{kind: string, severity: string, object: string, path: string, detail: array<string, mixed>}> */
    private array $findings = [];

    public function __construct(private readonly Document $doc) {}

    /**
     * @return list<array{kind: string, severity: string, object: string, path: string, detail: array<string, mixed>}>
     */
    public function scan(): array
    {
        $this->findings = [];
        $numbers = $this->doc->objectNumbers();
        sort($numbers);
        foreach ($numbers as $num) {
            $value = $this->doc->object($num);
            $this->walk($value, $num.' 0', '', 0);
        }

        return array_values($this->findings);
    }

    private function walk(mixed $v, string $object, string $path, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        if ($v instanceof PdfStream) {
            $this->inspect($v->dict, $object, $path, true);
            $v = $v->dict;
        } elseif ($v instanceof PdfDict) {
            $this->inspect($v, $object, $path, false);
        }

        if ($v instanceof PdfDict) {
            foreach ($v->entries as $k => $child) {
                if ($child instanceof PdfDict || is_array($child)) {
                    $this->walk($child, $object, $path.'/'.$k, $depth + 1);
                }
            }
        } elseif (is_array($v)) {
            foreach ($v as $i => $child) {
                if ($child instanceof PdfDict || is_array($child)) {
                    $this->walk($child, $object, $path.'['.$i.']', $depth + 1);
                }
            }
        }
    }

    private function inspect(PdfDict $d, string $object, string $path, bool $isStream): void
    {
        $s = $d->name('S');

        if ($s === 'JavaScript' || $d->has('JS')) {
            $this->add('javascript', $object, $path, $this->scriptDetail($d->get('JS')));
        }
        if ($s !== null && isset(self::ACTIONS[$s])) {
            $this->add(self::ACTIONS[$s], $object, $path);
        }
        if ($d->has('OpenAction')) {
            $oa = $this->doc->resolve($d->get('OpenAction'));
            if ($oa instanceof PdfDict) {
                $this->add('open-action', $object, $path.'/OpenAction', ['action' => $oa->name('S')]);
            }
        }
        if ($d->has('AA')) {
            $aa = $this->doc->resolve($d->get('AA'));
            $this->add('additional-actions', $object, $path.'/AA', [
                'triggers' => $aa instanceof PdfDict ? $aa->keys() : [],
            ]);
        }
        if ($d->has('JavaScript') && ! $d->has('S')) {
            // The document-level names tree: scripts that run on open.
            $this->add('javascript', $object, $path.'/JavaScript', ['namesTree' => true]);
        }
        if (($isStream && $d->name('Type') === 'EmbeddedFile') || $d->has('EF') || $d->has('EmbeddedFiles')) {
            $this->add('embedded-file', $object, $path);
        }
        if ($d->name('Subtype') === 'RichMedia' || $d->has('RichMediaContent')) {
            $this->add('rich-media', $object, $path);
        }
        if ($d->has('XFA')) {
            $this->add('xfa', $object, $path.'/XFA');
        }
        if ($d->has('AcroForm')) {
            $this->add('acroform', $object, $path.'/AcroForm');
        }
    }

    /** @return array<string, mixed> */
    private function scriptDetail(mixed $js): array
    {
        $js = $this->doc->resolve($js);
        $source = null;
        if ($js instanceof PdfString) {
            $source = $js->bytes;
        } elseif ($js instanceof PdfStream) {
            $source = $this->doc->streamData($js) ?? $js->raw;
        }
        if ($source === null) {
            return ['length' => 0];
        }

        return ['length' => strlen($source), 'sha256' => hash('sha256', $source)];
    }

    /** @param array<string, mixed> $detail */
    private function add(string $kind, string $object, string $path, array $detail = []): void
    {
        $key = $kind.'|'.$object.'|'.$path;
        $this->findings[$key] ??= [
            'kind' => $kind,
            'severity' => self::SEVERITY[$kind],
            'object' => $object,
            'path' => $path,
            'detail' => $detail,
        ];
    }
}
