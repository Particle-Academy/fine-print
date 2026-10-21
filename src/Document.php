<?php

declare(strict_types=1);

namespace FinePrint;

use FinePrint\Exceptions\RefusedException;
use FinePrint\Filters\Filters;
use FinePrint\Filters\OpaqueStreamException;
use FinePrint\Objects\PdfDict;
use FinePrint\Objects\PdfName;
use FinePrint\Objects\PdfRef;
use FinePrint\Objects\PdfStream;
use FinePrint\Objects\PdfString;
use FinePrint\Parser\Parser;
use FinePrint\Parser\PdfSyntaxException;
use FinePrint\Security\ActiveContentScanner;
use FinePrint\Security\StandardSecurity;
use FinePrint\Text\Layout;
use FinePrint\Text\TextExtractor;
use FinePrint\Text\TextString;

/**
 * A parsed PDF — the HOST's raw view of a file.
 *
 * Nothing here is filtered for an agent. Text, metadata and annotation
 * contents come back exactly as the file wrote them, invisible characters and
 * hidden text included. Hand an agent {@see Agent::read()} instead, which runs
 * every string through the agent-read guard.
 *
 * Objects are loaded lazily: opening a file reads its cross-reference data and
 * trailer and nothing else, and a stream is only decoded when something asks
 * for it.
 */
final class Document
{
    private string $version = '1.4';

    private int $base = 0;

    /** @var array<int, array{type: int, offset?: int, stm?: int, idx?: int}> */
    private array $xref = [];

    private PdfDict $trailer;

    /** @var array<int, mixed> */
    private array $cache = [];

    /** @var array<int, array{data: string, first: int, offsets: array<int, int>}> */
    private array $objStreams = [];

    private bool $repaired = false;

    /** @var ?list<array{number: int, dict: PdfDict, ref: ?PdfRef, mediaBox: list<float>, cropBox: list<float>, rotate: int, resources: ?PdfDict}> */
    private ?array $pages = null;

    private Parser $parser;

    private readonly Budget $budget;

    /** @var array<int, true> objects being loaded right now, for cycle detection */
    private array $loading = [];

    /** @var ?array<int, int> object number => offset, from scanning the file */
    private ?array $scanned = null;

    /** @var list<string> */
    private array $warnings = [];

    /** The empty-password key, when the file is encrypted. */
    private ?StandardSecurity $security = null;

    /** The /Encrypt dictionary's own object, which is never encrypted. */
    private ?int $encryptNum = null;

    private function __construct(private readonly string $data, Limits $limits)
    {
        $this->trailer = new PdfDict();
        $this->budget = new Budget($limits);
        $this->parser = new Parser($data, true, fn (PdfRef $r) => $this->resolve($r), $this->budget);
    }

    /**
     * Open a PDF from its bytes.
     *
     * @throws RefusedException when the file is not a PDF, is encrypted, is
     *                          damaged beyond repair, or breaches a limit
     */
    public static function open(string $bytes, ?Limits $limits = null): self
    {
        $limits ??= new Limits();
        if (strlen($bytes) > $limits->maxFileBytes) {
            throw new RefusedException('too_large', 'The file is larger than the '.$limits->maxFileBytes.'-byte limit.');
        }
        $doc = new self($bytes, $limits);
        $doc->load();

        return $doc;
    }

    private function load(): void
    {
        $head = substr($this->data, 0, 1024);
        $at = strpos($head, '%PDF-');
        if ($at === false) {
            throw new RefusedException('not_pdf', 'There is no PDF header in the first 1024 bytes.');
        }
        $this->base = $at;
        if (preg_match('/%PDF-(\d\.\d)/', $head, $m)) {
            $this->version = $m[1];
        }

        try {
            $this->readXref();
            // Encryption is set up BEFORE any object is resolved, so nothing
            // is ever cached in its encrypted form.
            $this->secure();
            $ok = $this->resolve($this->trailer->get('Root')) instanceof PdfDict;
        } catch (PdfSyntaxException) {
            $ok = false;
        }
        if (! $ok) {
            $this->repair();
            $this->secure();
        }
    }

    /**
     * Open an encrypted file with the empty user password, or refuse it.
     * See {@see StandardSecurity}.
     *
     * @throws RefusedException
     */
    private function secure(): void
    {
        $ref = $this->trailer->get('Encrypt');
        if ($ref === null || $this->security !== null) {
            return;
        }
        $enc = $this->resolve($ref);
        if (! $enc instanceof PdfDict) {
            throw new RefusedException('corrupt', 'The file\'s encryption dictionary is missing or is not a dictionary.');
        }
        $id = $this->resolve($this->trailer->get('ID'));
        $id0 = is_array($id) && ($id[0] ?? null) instanceof PdfString ? $id[0]->bytes : '';
        $this->security = StandardSecurity::open($enc, $id0, fn (mixed $v) => $this->resolve($v), $this->budget);
        $this->encryptNum = $ref instanceof PdfRef ? $ref->num : null;

        // Anything read while finding the key was read raw: forget it.
        $this->cache = $this->encryptNum !== null ? [$this->encryptNum => $enc] : [];
        $this->objStreams = [];
    }

    /**
     * How the file was encrypted, and its permission flags, or null for a
     * plain file. Never the key or a password.
     *
     * Permissions are REPORTED, not enforced: they are the author's request
     * to viewers, and what a host does with them is the host's decision.
     *
     * @return ?array<string, mixed>
     */
    public function encryption(): ?array
    {
        return $this->security?->report();
    }

    /** Decrypt every string and stream in an object read from the file. */
    private function decryptValue(mixed $v, int $num, int $gen): mixed
    {
        if ($v instanceof PdfString) {
            $plain = $this->security?->string($v->bytes, $num, $gen);
            if ($plain === null) {
                $this->warn('a string could not be decrypted; read as empty');
            }

            return new PdfString($plain ?? '', $v->hex);
        }
        if ($v instanceof PdfStream) {
            $type = $v->dict->name('Type');
            if ($type === 'XRef') {
                return $v;   // cross-reference streams are never encrypted
            }
            $dict = $this->decryptValue($v->dict, $num, $gen);
            $raw = $v->raw;
            $plainMetadata = $type === 'Metadata' && ! $this->security?->encryptMetadata;
            if (! $plainMetadata && ! $this->identityCrypt($v->dict)) {
                $raw = $this->security?->stream($raw, $num, $gen);
                if ($raw === null) {
                    $this->warn('a stream could not be decrypted; read as empty');
                    $raw = '';
                }
            }

            return new PdfStream($dict, $raw, $v->objectNumber);
        }
        if ($v instanceof PdfDict) {
            $out = [];
            $signature = $v->name('Type') === 'Sig' || $v->has('ByteRange');
            foreach ($v->entries as $k => $child) {
                // A signature's /Contents is never encrypted.
                $out[$k] = $signature && $k === 'Contents' ? $child : $this->decryptValue($child, $num, $gen);
            }

            return new PdfDict($out);
        }
        if (is_array($v)) {
            return array_map(fn (mixed $c) => $this->decryptValue($c, $num, $gen), $v);
        }

        return $v;
    }

    /** A stream with its own `/Crypt` filter naming `/Identity` is stored in the clear. */
    private function identityCrypt(PdfDict $dict): bool
    {
        $f = $dict->get('Filter');
        $names = $f instanceof PdfName ? [$f] : (is_array($f) ? $f : []);
        foreach ($names as $n) {
            if ($n instanceof PdfName && $n->value === 'Crypt') {
                $parms = $dict->get('DecodeParms');
                $parms = is_array($parms) ? ($parms[array_search($n, $names, true)] ?? null) : $parms;

                return ! ($parms instanceof PdfDict) || $parms->name('Name') === null || $parms->name('Name') === 'Identity';
            }
        }

        return false;
    }

    /** The running budget; for the readers inside this package. */
    public function budget(): Budget
    {
        return $this->budget;
    }

    /** Record something odd that was survived rather than refused. */
    public function warn(string $message): void
    {
        if (count($this->warnings) < 100 && ! in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }

    /**
     * Damage the reader worked around: cycles, skipped self-references, a
     * rebuilt cross-reference table. Never contains file content.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /** The header version, e.g. "1.7". */
    public function version(): string
    {
        return $this->version;
    }

    /** Whether the cross-reference data had to be rebuilt by scanning. */
    public function repaired(): bool
    {
        return $this->repaired;
    }

    public function pageCount(): int
    {
        return count($this->pageList());
    }

    /**
     * Page geometry. `width`/`height` are the visible (CropBox) size AS
     * DISPLAYED, so a page rotated 90 degrees reports them swapped.
     *
     * @return list<array{number: int, width: float, height: float, rotate: int, mediaBox: list<float>, cropBox: list<float>}>
     */
    public function pages(): array
    {
        $out = [];
        foreach ($this->pageList() as $p) {
            $w = $p['cropBox'][2] - $p['cropBox'][0];
            $h = $p['cropBox'][3] - $p['cropBox'][1];
            if ($p['rotate'] % 180 !== 0) {
                [$w, $h] = [$h, $w];
            }
            $out[] = [
                'number' => $p['number'],
                'width' => $w,
                'height' => $h,
                'rotate' => $p['rotate'],
                'mediaBox' => $p['mediaBox'],
                'cropBox' => $p['cropBox'],
            ];
        }

        return $out;
    }

    /** A page's text in reading order. Pages are numbered from 1. */
    public function text(int $page): string
    {
        return $this->pageText($page)['text'];
    }

    /**
     * A page's text with the runs it was composed from, and the spans of any
     * runs carrying flags (hidden, off-page).
     *
     * @return array{text: string, spans: list<array{start: int, length: int, flags: list<string>}>, runs: list<array<string, mixed>>}
     */
    public function pageText(int $page): array
    {
        $p = $this->pageList()[$page - 1] ?? null;
        if ($p === null) {
            throw new \OutOfRangeException('No page '.$page);
        }

        $content = $this->pageContent($p['dict']);
        $runs = (new TextExtractor($this, $p['cropBox']))->extract($content, $p['resources']);
        $composed = Layout::compose($runs);

        return $composed + ['runs' => $runs];
    }

    /**
     * The Info dictionary as strings.
     *
     * @return array<string, string>
     */
    public function metadata(): array
    {
        $info = $this->resolve($this->trailer->get('Info'));
        if (! $info instanceof PdfDict) {
            return [];
        }
        $out = [];
        foreach ($info->entries as $key => $v) {
            $v = $this->resolve($v);
            if ($v instanceof PdfString) {
                $out[(string) $key] = TextString::decode($v->bytes);
            } elseif ($v instanceof PdfName) {
                $out[(string) $key] = $v->value;
            } elseif (is_int($v) || is_float($v) || is_bool($v)) {
                $out[(string) $key] = var_export($v, true);
            }
        }

        return $out;
    }

    /** The catalog's XMP metadata packet, or null. */
    public function xmp(): ?string
    {
        $m = $this->resolve($this->catalog()->get('Metadata'));
        if (! $m instanceof PdfStream) {
            return null;
        }
        $data = $this->streamData($m);

        return $data === null ? null : TextString::scrub($data);
    }

    /**
     * The document outline (bookmarks).
     *
     * @return list<array{title: string, page: ?int, children: list<mixed>}>
     */
    public function outline(): array
    {
        $root = $this->resolve($this->catalog()->get('Outlines'));
        if (! $root instanceof PdfDict) {
            return [];
        }
        $seen = [];

        return $this->outlineLevel($root->get('First'), $seen);
    }

    /**
     * A page's annotations that carry text.
     *
     * @return list<array{subtype: ?string, object: ?string, contents: ?string, author: ?string}>
     */
    public function annotations(int $page): array
    {
        $p = $this->pageList()[$page - 1] ?? null;
        if ($p === null) {
            throw new \OutOfRangeException('No page '.$page);
        }
        $annots = $this->resolve($p['dict']->get('Annots'));
        if (! is_array($annots)) {
            return [];
        }
        $out = [];
        foreach ($annots as $ref) {
            $a = $this->resolve($ref);
            if (! $a instanceof PdfDict) {
                continue;
            }
            $contents = $this->resolve($a->get('Contents'));
            $author = $this->resolve($a->get('T'));
            $out[] = [
                'subtype' => $a->name('Subtype'),
                'object' => $ref instanceof PdfRef ? $ref->key() : null,
                'contents' => $contents instanceof PdfString ? TextString::decode($contents->bytes) : null,
                'author' => $author instanceof PdfString ? TextString::decode($author->bytes) : null,
            ];
        }

        return $out;
    }

    /**
     * Active content: scripts, launch actions, forms, embedded files and the
     * like. Detected and reported only — see {@see ActiveContentScanner}.
     *
     * @return list<array{kind: string, severity: string, object: string, path: string, detail: array<string, mixed>}>
     */
    public function activeContent(): array
    {
        return (new ActiveContentScanner($this))->scan();
    }

    public function trailer(): PdfDict
    {
        return $this->trailer;
    }

    public function catalog(): PdfDict
    {
        $c = $this->resolve($this->trailer->get('Root'));

        return $c instanceof PdfDict ? $c : new PdfDict();
    }

    /**
     * Every object number the cross-reference data says is in use.
     *
     * @return list<int>
     */
    public function objectNumbers(): array
    {
        $out = [];
        foreach ($this->xref as $num => $e) {
            if ($e['type'] !== 0) {
                $out[] = $num;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Objects
    // ------------------------------------------------------------------

    /** Follow references until a direct value. A missing object is null. */
    public function resolve(mixed $v): mixed
    {
        $hops = 0;
        $seen = [];
        while ($v instanceof PdfRef) {
            if (isset($seen[$v->num]) || ++$hops > $this->budget->limits->maxDepth) {
                $this->warn('reference cycle through object '.$v->num.'; read as null');

                return null;
            }
            $seen[$v->num] = true;
            $v = $this->object($v->num);
        }

        return $v;
    }

    /** An indirect object by number (direct value, refs inside untouched). */
    public function object(int $num): mixed
    {
        if (array_key_exists($num, $this->cache)) {
            return $this->cache[$num];
        }
        if (isset($this->loading[$num])) {
            // Loading this object needs this object (a stream whose /Length is
            // itself, an object stream inside itself).
            $this->warn('reference cycle while loading object '.$num.'; read as null');

            return null;
        }

        $this->loading[$num] = true;
        try {
            $e = $this->xref[$num] ?? null;
            $value = null;
            if ($e !== null && $e['type'] === 1) {
                $value = $this->loadAt($e['offset'], $num);
            } elseif ($e !== null && $e['type'] === 2) {
                $value = $this->loadCompressed($e['stm'], $num);
            }
        } finally {
            unset($this->loading[$num]);
        }

        return $this->cache[$num] = $value;
    }

    /**
     * A stream's decoded bytes, or null when it is image-encoded (opaque).
     */
    public function streamData(PdfStream $s): ?string
    {
        $filter = $this->resolve($s->dict->get('Filter'));
        $parms = $this->resolve($s->dict->get('DecodeParms'));
        $names = [];
        foreach ($filter instanceof PdfName ? [$filter] : (is_array($filter) ? $filter : []) as $f) {
            $f = $this->resolve($f);
            if ($f instanceof PdfName) {
                $names[] = $f->value;
            }
        }
        $plist = [];
        foreach ($parms instanceof PdfDict ? [$parms] : (is_array($parms) ? $parms : []) as $pp) {
            $pp = $this->resolve($pp);
            $plist[] = $pp instanceof PdfDict ? $pp : null;
        }

        try {
            return Filters::decodeChain($names, $s->raw, $plist, $this->budget);
        } catch (OpaqueStreamException) {
            return null;
        }
    }

    private function loadAt(int $offset, int $expect): mixed
    {
        foreach (array_unique([$offset, $offset + $this->base]) as $at) {
            $found = $this->parseAt($at, $expect);
            if ($found[0]) {
                return $found[1];
            }
        }

        // The table lied about this object. Find it by scanning instead.
        $scanned = $this->scanIndex()[$expect] ?? null;
        if ($scanned !== null && $scanned !== $offset) {
            $found = $this->parseAt($scanned, $expect);
            if ($found[0]) {
                $this->repaired = true;
                $this->warn('cross-reference offsets did not match their objects; located by scanning');

                return $found[1];
            }
        }

        return null;
    }

    /** @return array{0: bool, 1: mixed} [found, value] */
    private function parseAt(int $at, int $expect): array
    {
        try {
            $this->parser->seek($at);
            $o = $this->parser->parseIndirect();
            if ($o['num'] === $expect) {
                $value = $this->security !== null && $o['num'] !== $this->encryptNum
                    ? $this->decryptValue($o['value'], $o['num'], $o['gen'])
                    : $o['value'];

                return [true, $value];
            }
        } catch (PdfSyntaxException) {
            // not here
        }

        return [false, null];
    }

    /**
     * Every `N G obj` in the file, newest (last) occurrence winning.
     *
     * @return array<int, int>
     */
    private function scanIndex(): array
    {
        if ($this->scanned !== null) {
            return $this->scanned;
        }
        $this->scanned = [];
        preg_match_all('/(?<![0-9])(\d{1,10})[\x00\t\n\f\r ]+(\d{1,5})[\x00\t\n\f\r ]+obj\b/', $this->data, $m, PREG_OFFSET_CAPTURE);
        if (count($m[0]) > $this->budget->limits->maxObjects) {
            throw new RefusedException('too_many_objects', 'The file holds more than '.$this->budget->limits->maxObjects.' objects.');
        }
        foreach ($m[1] as $k => [$num]) {
            $this->scanned[(int) $num] = $m[0][$k][1];
        }
        $this->budget->check();

        return $this->scanned;
    }

    /**
     * Rebuild the cross-reference data by scanning, when it is missing,
     * truncated or points at nothing.
     */
    private function repair(): void
    {
        $this->xref = [];
        $this->cache = [];
        $this->objStreams = [];
        $this->trailer = new PdfDict();

        $index = $this->scanIndex();
        if ($index === []) {
            throw new RefusedException('corrupt', 'The file has a PDF header but no readable objects.');
        }
        foreach ($index as $num => $offset) {
            $this->xref[$num] = ['type' => 1, 'offset' => $offset];
        }

        // Every trailer dictionary, newest first.
        preg_match_all('/trailer[\x00\t\n\f\r ]*<</', $this->data, $tm, PREG_OFFSET_CAPTURE);
        foreach (array_reverse($tm[0]) as [, $at]) {
            try {
                $this->parser->seek($at + 7);
                $t = $this->parser->parseValue();
            } catch (PdfSyntaxException) {
                continue;
            }
            if ($t instanceof PdfDict) {
                $this->mergeTrailer($t);
            }
        }

        // Cross-reference streams carry trailer keys; object streams hold
        // objects the scan cannot see directly.
        $catalog = null;
        foreach (array_keys($index) as $num) {
            $v = $this->object($num);
            if ($v instanceof PdfStream && $v->dict->name('Type') === 'XRef') {
                $this->mergeTrailer($v->dict);
            } elseif ($v instanceof PdfStream && $v->dict->name('Type') === 'ObjStm') {
                foreach ($this->objectStreamOffsets($num) as $inner => $_) {
                    if (! isset($this->xref[$inner])) {
                        $this->xref[$inner] = ['type' => 2, 'stm' => $num, 'idx' => 0];
                    }
                }
            }
        }
        if (count($this->xref) > $this->budget->limits->maxObjects) {
            throw new RefusedException('too_many_objects', 'The file holds more than '.$this->budget->limits->maxObjects.' objects.');
        }

        if (! $this->resolve($this->trailer->get('Root')) instanceof PdfDict) {
            foreach (array_keys($this->xref) as $num) {
                $v = $this->object($num);
                if ($v instanceof PdfDict && $v->name('Type') === 'Catalog') {
                    $catalog = $num;
                }
            }
            if ($catalog === null) {
                throw new RefusedException('corrupt', 'The cross-reference data is unusable and no document catalog could be found.');
            }
            $this->trailer->entries['Root'] = new PdfRef($catalog, 0);
        }

        $this->repaired = true;
        $this->warn('cross-reference data was rebuilt by scanning the file');
    }

    private function mergeTrailer(PdfDict $t): void
    {
        foreach (['Root', 'Info', 'Encrypt', 'ID'] as $k) {
            if ($t->has($k) && ! $this->trailer->has($k)) {
                $this->trailer->entries[$k] = $t->get($k);
            }
        }
    }

    /** @return array<int, int> object number => offset within the decoded stream */
    private function objectStreamOffsets(int $stm): array
    {
        $this->loadObjectStream($stm);

        return $this->objStreams[$stm]['offsets'] ?? [];
    }

    private function loadObjectStream(int $stm): void
    {
        if (isset($this->objStreams[$stm])) {
            return;
        }
        $this->objStreams[$stm] = ['data' => '', 'first' => 0, 'offsets' => []];
        $s = $this->object($stm);
        if (! $s instanceof PdfStream) {
            return;
        }
        $data = $this->streamData($s) ?? '';
        $n = $this->resolve($s->dict->get('N'));
        $first = $this->resolve($s->dict->get('First'));
        $offsets = [];
        if (is_int($n) && is_int($first)) {
            $hp = new Parser($data, refs: false, budget: $this->budget);
            for ($i = 0; $i < min($n, $this->budget->limits->maxObjects); $i++) {
                try {
                    $on = $hp->parseValue();
                    $oo = $hp->parseValue();
                } catch (PdfSyntaxException) {
                    break;
                }
                if (is_int($on) && is_int($oo)) {
                    $offsets[$on] = $first + $oo;
                }
            }
        }
        $this->objStreams[$stm] = ['data' => $data, 'first' => is_int($first) ? $first : 0, 'offsets' => $offsets];
    }

    private function loadCompressed(int $stm, int $num): mixed
    {
        if (($this->xref[$stm]['type'] ?? 1) === 2) {
            // An object stream inside an object stream is not allowed.
            return null;
        }
        $this->loadObjectStream($stm);

        $os = $this->objStreams[$stm];
        if (! isset($os['offsets'][$num])) {
            return null;
        }
        try {
            $p = new Parser($os['data'], budget: $this->budget);
            $p->seek($os['offsets'][$num]);

            return $p->parseValue();
        } catch (PdfSyntaxException) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Cross-reference data
    // ------------------------------------------------------------------

    private function readXref(): void
    {
        $at = strrpos($this->data, 'startxref');
        if ($at === false || ! preg_match('/startxref\s+(\d+)/', $this->data, $m, 0, $at)) {
            throw new PdfSyntaxException('No startxref');
        }
        $offset = (int) $m[1];
        $visited = [];

        while ($offset !== null) {
            if (isset($visited[$offset]) || count($visited) >= 1000) {
                $this->warn('the cross-reference /Prev chain loops; stopped following it');

                break;
            }
            $visited[$offset] = true;
            [$entries, $trailer] = $this->readSection($offset);

            $stm = $trailer->get('XRefStm');
            if (is_int($stm)) {
                try {
                    [$extra] = $this->readSection($stm);
                    foreach ($extra as $num => $e) {
                        if (! isset($entries[$num]) || $entries[$num]['type'] === 0) {
                            $entries[$num] = $e;
                        }
                    }
                } catch (PdfSyntaxException) {
                    // A broken hybrid stream leaves the table's view.
                }
            }

            foreach ($entries as $num => $e) {
                if (! isset($this->xref[$num])) {
                    $this->xref[$num] = $e;
                }
            }
            if (count($this->xref) > $this->budget->limits->maxObjects) {
                throw new RefusedException('too_many_objects', 'The file lists more than '.$this->budget->limits->maxObjects.' objects.');
            }
            foreach ($trailer->entries as $k => $v) {
                if (! in_array($k, ['Prev', 'XRefStm', 'Type', 'W', 'Index', 'Filter', 'DecodeParms', 'Length'], true)
                    && ! $this->trailer->has((string) $k)) {
                    $this->trailer->entries[$k] = $v;
                }
            }

            $prev = $trailer->get('Prev');
            $offset = is_int($prev) ? $prev : null;
        }
    }

    /**
     * One cross-reference section: a table + trailer, or a stream.
     *
     * @return array{0: array<int, array<string, int>>, 1: PdfDict}
     */
    private function readSection(int $offset): array
    {
        foreach (array_unique([$offset, $offset + $this->base]) as $at) {
            $this->parser->seek($at);
            $this->parser->skipWhitespace();
            $pos = $this->parser->position();
            if (substr_compare($this->data, 'xref', $pos, 4) === 0) {
                return $this->readTable($pos + 4);
            }
            try {
                $o = $this->parser->parseIndirect();
                if ($o['value'] instanceof PdfStream && $o['value']->dict->name('Type') === 'XRef') {
                    return $this->readStream($o['value']);
                }
            } catch (PdfSyntaxException) {
                // try the next candidate
            }
        }

        throw new PdfSyntaxException('No cross-reference section at '.$offset);
    }

    /** @return array{0: array<int, array<string, int>>, 1: PdfDict} */
    private function readTable(int $pos): array
    {
        $entries = [];
        while (true) {
            if (! preg_match('/\G\s*(\d+)\s+(\d+)[ \t]*[\r\n]+/', $this->data, $m, 0, $pos)) {
                break;
            }
            $pos += strlen($m[0]);
            $start = (int) $m[1];
            $count = (int) $m[2];
            if ($count + count($entries) > $this->budget->limits->maxObjects) {
                throw new RefusedException('too_many_objects', 'The cross-reference table declares more than '.$this->budget->limits->maxObjects.' objects.');
            }
            for ($i = 0; $i < $count; $i++) {
                $this->budget->tick();
                if (! preg_match('/\G\s*(\d{1,10})\s+(\d{1,5})\s+([nf])/', $this->data, $e, 0, $pos)) {
                    break 2;
                }
                $pos += strlen($e[0]);
                $num = $start + $i;
                if (! isset($entries[$num])) {
                    $entries[$num] = $e[3] === 'n'
                        ? ['type' => 1, 'offset' => (int) $e[1]]
                        : ['type' => 0];
                }
            }
        }

        $t = strpos($this->data, 'trailer', $pos);
        if ($t === false) {
            throw new PdfSyntaxException('No trailer');
        }
        $this->parser->seek($t + 7);
        $trailer = $this->parser->parseValue();
        if (! $trailer instanceof PdfDict) {
            throw new PdfSyntaxException('Trailer is not a dictionary');
        }
        unset($entries[0]);

        return [$entries, $trailer];
    }

    /** @return array{0: array<int, array<string, int>>, 1: PdfDict} */
    private function readStream(PdfStream $s): array
    {
        $data = $this->streamData($s) ?? '';
        $w = $this->resolve($s->dict->get('W'));
        if (! is_array($w) || count($w) < 3) {
            throw new PdfSyntaxException('Bad /W');
        }
        $w = array_map(fn ($v) => is_int($v) ? max(0, $v) : 0, array_slice($w, 0, 3));
        $row = $w[0] + $w[1] + $w[2];
        $size = $this->resolve($s->dict->get('Size'));
        $index = $this->resolve($s->dict->get('Index'));
        if (! is_array($index)) {
            $index = [0, is_int($size) ? $size : 0];
        }
        $declared = 0;
        for ($k = 1; $k < count($index); $k += 2) {
            $declared += is_int($index[$k]) ? max(0, $index[$k]) : 0;
        }
        // Judged on the DECLARATION, before a single row is read.
        if ($declared > $this->budget->limits->maxObjects) {
            throw new RefusedException('too_many_objects', 'A cross-reference stream declares more than '.$this->budget->limits->maxObjects.' objects.');
        }

        $entries = [];
        $pos = 0;
        $len = strlen($data);
        for ($k = 0; $k + 1 < count($index); $k += 2) {
            $start = (int) $index[$k];
            $count = (int) $index[$k + 1];
            for ($i = 0; $i < $count && $pos + $row <= $len; $i++) {
                $f1 = $w[0] === 0 ? 1 : self::be($data, $pos, $w[0]);
                $f2 = self::be($data, $pos + $w[0], $w[1]);
                $f3 = self::be($data, $pos + $w[0] + $w[1], $w[2]);
                $pos += $row;
                $num = $start + $i;
                $entries[$num] = match ($f1) {
                    1 => ['type' => 1, 'offset' => $f2],
                    2 => ['type' => 2, 'stm' => $f2, 'idx' => $f3],
                    default => ['type' => 0],
                };
            }
        }
        unset($entries[0]);

        return [$entries, $s->dict];
    }

    private static function be(string $data, int $at, int $width): int
    {
        $v = 0;
        for ($i = 0; $i < $width; $i++) {
            $v = ($v << 8) | ord($data[$at + $i] ?? "\0");
        }

        return $v;
    }

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    /** @return list<array{number: int, dict: PdfDict, ref: ?PdfRef, mediaBox: list<float>, cropBox: list<float>, rotate: int, resources: ?PdfDict}> */
    private function pageList(): array
    {
        if ($this->pages !== null) {
            return $this->pages;
        }
        $this->pages = [];
        $visited = [];
        $this->walkPages($this->catalog()->get('Pages'), [
            'MediaBox' => null, 'CropBox' => null, 'Rotate' => null, 'Resources' => null,
        ], $visited, 0);

        return $this->pages;
    }

    /**
     * @param  array<string, mixed>  $inherited
     * @param  array<string, true>  $visited
     */
    private function walkPages(mixed $node, array $inherited, array &$visited, int $depth): void
    {
        $ref = $node instanceof PdfRef ? $node : null;
        $dict = $this->resolve($node);
        if (! $dict instanceof PdfDict) {
            return;
        }
        $key = $ref !== null ? $ref->key() : 'd'.spl_object_id($dict);
        if (isset($visited[$key]) || $depth > $this->budget->limits->maxDepth) {
            $this->warn('the page tree loops back on itself at '.$key.'; the repeat was skipped');

            return;
        }
        $visited[$key] = true;
        $this->budget->tick();
        foreach (array_keys($inherited) as $k) {
            if ($dict->has($k)) {
                $inherited[$k] = $dict->get($k);
            }
        }

        $kids = $this->resolve($dict->get('Kids'));
        $type = $dict->name('Type');
        if ($type === 'Pages' || ($type !== 'Page' && is_array($kids))) {
            foreach (is_array($kids) ? $kids : [] as $kid) {
                $this->walkPages($kid, $inherited, $visited, $depth + 1);
            }

            return;
        }
        if (count($this->pages) >= $this->budget->limits->maxPages) {
            throw new RefusedException('too_many_pages', 'The document has more than '.$this->budget->limits->maxPages.' pages.');
        }

        $media = $this->box($inherited['MediaBox']) ?? [0.0, 0.0, 612.0, 792.0];
        $crop = $this->box($inherited['CropBox']) ?? $media;
        $rotate = $this->resolve($inherited['Rotate']);
        $rotate = is_int($rotate) ? (($rotate % 360) + 360) % 360 : 0;
        $resources = $this->resolve($inherited['Resources']);

        $this->pages[] = [
            'number' => count($this->pages) + 1,
            'dict' => $dict,
            'ref' => $ref,
            'mediaBox' => $media,
            'cropBox' => $crop,
            'rotate' => $rotate - $rotate % 90,
            'resources' => $resources instanceof PdfDict ? $resources : null,
        ];
    }

    /** @return ?list<float> */
    private function box(mixed $v): ?array
    {
        $v = $this->resolve($v);
        if (! is_array($v) || count($v) < 4) {
            return null;
        }
        $n = array_map(fn ($x) => (float) (is_int($x) || is_float($x) ? $x : 0), array_map(fn ($x) => $this->resolve($x), array_slice($v, 0, 4)));

        return [min($n[0], $n[2]), min($n[1], $n[3]), max($n[0], $n[2]), max($n[1], $n[3])];
    }

    private function pageContent(PdfDict $page): string
    {
        $c = $this->resolve($page->get('Contents'));
        $streams = $c instanceof PdfStream ? [$c] : (is_array($c) ? $c : []);
        $out = '';
        foreach ($streams as $s) {
            $s = $this->resolve($s);
            if ($s instanceof PdfStream) {
                $out .= ($this->streamData($s) ?? '')."\n";
            }
        }

        return $out;
    }

    /** Page number (1-based) for a page object number, or null. */
    public function pageNumberOf(mixed $ref): ?int
    {
        if (is_int($ref)) {
            // Some producers write a 0-based page index instead of a reference.
            return $ref >= 0 && $ref < $this->pageCount() ? $ref + 1 : null;
        }
        if (! $ref instanceof PdfRef) {
            return null;
        }
        foreach ($this->pageList() as $p) {
            if ($p['ref'] !== null && $p['ref']->num === $ref->num) {
                return $p['number'];
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Outline
    // ------------------------------------------------------------------

    /**
     * @param  array<int, true>  $seen
     * @return list<array{title: string, page: ?int, children: list<mixed>}>
     */
    private function outlineLevel(mixed $first, array &$seen): array
    {
        $out = [];
        $node = $first;
        while ($node !== null) {
            $key = $node instanceof PdfRef ? $node->num : null;
            if (($key !== null && isset($seen[$key])) || count($seen) >= 10_000) {
                $this->warn('the outline loops back on itself; the repeat was skipped');

                break;
            }
            if ($key !== null) {
                $seen[$key] = true;
            }
            $item = $this->resolve($node);
            if (! $item instanceof PdfDict) {
                break;
            }
            $title = $this->resolve($item->get('Title'));
            $out[] = [
                'title' => $title instanceof PdfString ? TextString::decode($title->bytes) : '',
                'page' => $this->destinationPage($item),
                'children' => $this->outlineLevel($item->get('First'), $seen),
            ];
            $node = $item->get('Next');
        }

        return $out;
    }

    private function destinationPage(PdfDict $item): ?int
    {
        $dest = $this->resolve($item->get('Dest'));
        if ($dest === null) {
            $action = $this->resolve($item->get('A'));
            if ($action instanceof PdfDict && $action->name('S') === 'GoTo') {
                $dest = $this->resolve($action->get('D'));
            }
        }
        if ($dest instanceof PdfName || $dest instanceof PdfString) {
            $dest = $this->namedDestination($dest);
        }
        if ($dest instanceof PdfDict) {
            $dest = $this->resolve($dest->get('D'));
        }

        return is_array($dest) ? $this->pageNumberOf($dest[0] ?? null) : null;
    }

    private function namedDestination(PdfName|PdfString $name): mixed
    {
        if ($name instanceof PdfName) {
            $dests = $this->resolve($this->catalog()->get('Dests'));

            return $dests instanceof PdfDict ? $this->resolve($dests->get($name->value)) : null;
        }
        $names = $this->resolve($this->catalog()->get('Names'));
        $tree = $names instanceof PdfDict ? $this->resolve($names->get('Dests')) : null;

        return $tree instanceof PdfDict ? $this->nameTreeGet($tree, $name->bytes) : null;
    }

    private function nameTreeGet(PdfDict $root, string $key): mixed
    {
        $queue = [$root];
        $visited = 0;
        while ($queue !== [] && $visited++ < 10_000) {
            $node = array_shift($queue);
            $names = $this->resolve($node->get('Names'));
            if (is_array($names)) {
                for ($i = 0; $i + 1 < count($names); $i += 2) {
                    $k = $this->resolve($names[$i]);
                    if ($k instanceof PdfString && $k->bytes === $key) {
                        return $this->resolve($names[$i + 1]);
                    }
                }
            }
            $kids = $this->resolve($node->get('Kids'));
            foreach (is_array($kids) ? $kids : [] as $kid) {
                $kid = $this->resolve($kid);
                if ($kid instanceof PdfDict) {
                    $queue[] = $kid;
                }
            }
        }

        return null;
    }
}
