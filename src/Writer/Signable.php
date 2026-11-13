<?php

declare(strict_types=1);

namespace FinePrint\Writer;

use FinePrint\Exceptions\SchemaException;
use FinePrint\Exchange\Portable;

/**
 * The signing extension, for HOSTS (an e-signature package, a forms tool).
 * Not on {@see \FinePrint\Agent}: an agent places field nodes in a document;
 * deciding who signs and generating the signature page is the host's job.
 *
 *   Signable::write($doc, [
 *       'signaturePage' => ['title' => 'Signatures', 'reference' => 'ENV-1', 'contentHash' => $hex,
 *                           'signers' => [['id' => 'alice', 'name' => 'Alice', 'email' => 'a@example.com']]],
 *       'pageMarks' => ['kind' => 'initials', 'signers' => [['id' => 'alice', 'label' => 'AE']]],
 *   ])
 *   => [bytes, pages, authoredPages, fields: [{id, kind, signer, required, origin, page, rect}], diagnostics]
 *
 * - `fields` is the placement map: where each field landed, computed by the
 *   layout. `rect` is [x0, y0, x1, y1] in PDF points, bottom-up. `origin` is
 *   `node` (a field node in the document), `final_page` (the generated page)
 *   or `page_mark` (a per-page mark).
 * - The signature page is generated, appended as its own stretch after the
 *   authored pages, and lists each signer with a signature and a date field.
 *   It cannot print the hash of the PDF it is part of; `contentHash` is
 *   whatever the host passes, typically a hash of the document model.
 * - Per-page marks go on pages 1 to `authoredPages` only, so the generated
 *   page never carries one.
 *
 * Everything is validated first: the document with field nodes allowed, and
 * every option, so the writer never sees an unchecked value.
 */
final class Signable
{
    /** At most this many signers on a signature page or in per-page marks. */
    public const MAX_SIGNERS = 50;

    /** The longest signer name, email, title or reference. */
    public const MAX_TEXT = 200;

    /** A per-page mark label: a few characters, such as initials. */
    public const MARK_LABEL = '/^[A-Za-z0-9 .\-]{0,8}$/';

    /**
     * @param  array<string, mixed>  $doc
     * @param  array{signaturePage?: array<string, mixed>, pageMarks?: array<string, mixed>, fonts?: array<string, mixed>, compress?: bool}  $options
     * @return array{bytes: string, pages: int, authoredPages: int, fields: list<array<string, mixed>>, diagnostics: list<array<string, mixed>>}
     *
     * @throws SchemaException when the document or an option is invalid
     */
    public static function write(array $doc, array $options = []): array
    {
        $doc = Portable::expand($doc)['doc'];
        $errors = (new Validator(fields: true))->validate($doc);
        $writer = ['fields' => true];
        foreach (['fonts', 'compress'] as $key) {
            if (array_key_exists($key, $options)) {
                $writer[$key] = $options[$key];
            }
        }
        if (isset($options['signaturePage'])) {
            [$blocks, $more] = self::signaturePage($options['signaturePage']);
            $errors = array_merge($errors, $more);
            $writer['signaturePage'] = $blocks;
        }
        if (isset($options['pageMarks'])) {
            [$marks, $more] = self::pageMarks($options['pageMarks']);
            $errors = array_merge($errors, $more);
            $writer['pageMarks'] = $marks;
        }
        if ($errors !== []) {
            throw new SchemaException('Document or signing options failed validation.', $errors);
        }
        $out = (new PdfWriter($writer))->write($doc);

        return [
            'bytes' => $out['bytes'],
            'pages' => $out['pages'],
            'authoredPages' => $out['authoredPages'],
            'fields' => $out['fields'],
            'diagnostics' => $out['diagnostics'],
        ];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<array{path: string, message: string}>}
     */
    private static function signaturePage(mixed $spec): array
    {
        $errors = [];
        if (! is_array($spec)) {
            return [[], [['path' => 'signaturePage', 'message' => 'signaturePage must be an object']]];
        }
        $signers = self::signers($spec['signers'] ?? null, 'signaturePage.signers', $errors);
        foreach (['title', 'reference', 'contentHash'] as $key) {
            if (isset($spec[$key]) && (! is_string($spec[$key]) || mb_strlen($spec[$key], 'UTF-8') > self::MAX_TEXT)) {
                $errors[] = ['path' => "signaturePage.{$key}", 'message' => "{$key} must be a string of at most ".self::MAX_TEXT.' characters'];
            }
        }
        if ($errors !== []) {
            return [[], $errors];
        }

        $small = fn (string $text): array => ['type' => 'paragraph', 'runs' => [['text' => $text, 'size' => 8, 'color' => '#6B7280']]];
        $blocks = [['type' => 'heading', 'level' => 2, 'runs' => [['text' => is_string($spec['title'] ?? null) && $spec['title'] !== '' ? $spec['title'] : 'Signatures']]]];
        if (is_string($spec['reference'] ?? null) && $spec['reference'] !== '') {
            $blocks[] = $small('Reference: '.$spec['reference']);
        }
        if (is_string($spec['contentHash'] ?? null) && $spec['contentHash'] !== '') {
            $blocks[] = $small('Document content SHA-256: '.$spec['contentHash']);
        }
        foreach ($signers as $s) {
            $runs = [['text' => $s['name'], 'bold' => true]];
            if (($s['email'] ?? '') !== '') {
                $runs[] = ['text' => '  '.$s['email'], 'color' => '#4B5563'];
            }
            $blocks[] = ['type' => 'paragraph', 'runs' => $runs, 'keepNext' => true, 'spaceBefore' => 14];
            $blocks[] = ['type' => 'signatureField', 'id' => 'final-signature-'.$s['id'], 'signer' => $s['id'], 'label' => 'Signature', 'keepNext' => true];
            $blocks[] = ['type' => 'dateSignedField', 'id' => 'final-date-'.$s['id'], 'signer' => $s['id'], 'label' => 'Date signed'];
        }

        return [$blocks, []];
    }

    /**
     * @return array{0: array{kind: string, signers: list<array{id: string, label: string}>}, 1: list<array{path: string, message: string}>}
     */
    private static function pageMarks(mixed $spec): array
    {
        $errors = [];
        if (! is_array($spec)) {
            return [['kind' => 'initials', 'signers' => []], [['path' => 'pageMarks', 'message' => 'pageMarks must be an object']]];
        }
        $kind = $spec['kind'] ?? null;
        if (! in_array($kind, ['initials', 'signature'], true)) {
            $errors[] = ['path' => 'pageMarks.kind', 'message' => 'kind is "initials" or "signature"'];
            $kind = 'initials';
        }
        $signers = [];
        foreach (self::signers($spec['signers'] ?? null, 'pageMarks.signers', $errors, nameRequired: false) as $i => $s) {
            $label = $s['label'] ?? '';
            if (! is_string($label) || preg_match(self::MARK_LABEL, $label) !== 1) {
                $errors[] = ['path' => "pageMarks.signers[{$i}].label", 'message' => 'a mark label is at most 8 of A-Z, a-z, 0-9, space, dot and hyphen'];
                $label = '';
            }
            $signers[] = ['id' => $s['id'], 'label' => $label];
        }

        return [['kind' => $kind, 'signers' => $signers], $errors];
    }

    /**
     * @param  list<array{path: string, message: string}>  $errors
     * @return list<array<string, mixed>>
     */
    private static function signers(mixed $list, string $path, array &$errors, bool $nameRequired = true): array
    {
        if (! is_array($list) || ! array_is_list($list) || $list === []) {
            $errors[] = ['path' => $path, 'message' => 'signers must be a non-empty list'];

            return [];
        }
        if (count($list) > self::MAX_SIGNERS) {
            $errors[] = ['path' => $path, 'message' => 'at most '.self::MAX_SIGNERS.' signers'];

            return [];
        }
        $out = [];
        $seen = [];
        foreach ($list as $i => $s) {
            if (! is_array($s)) {
                $errors[] = ['path' => "{$path}[{$i}]", 'message' => 'a signer must be an object'];

                continue;
            }
            $id = $s['id'] ?? null;
            if (! is_string($id) || preg_match(Schema::FIELD_ID, $id) !== 1 || isset($seen[$id])) {
                $errors[] = ['path' => "{$path}[{$i}].id", 'message' => 'a signer id is 1 to 64 of A-Z, a-z, 0-9, _ and -, unique in the list'];

                continue;
            }
            $seen[$id] = true;
            foreach (['name', 'email'] as $key) {
                $v = $s[$key] ?? null;
                $missing = $v === null || $v === '';
                if (($missing && $key === 'name' && $nameRequired) || (! $missing && (! is_string($v) || mb_strlen($v, 'UTF-8') > self::MAX_TEXT))) {
                    $errors[] = ['path' => "{$path}[{$i}].{$key}", 'message' => "{$key} must be a string of at most ".self::MAX_TEXT.' characters'];

                    continue 2;
                }
            }
            $out[] = $s;
        }

        return $out;
    }
}
