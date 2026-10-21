# FinePrint

[![Fancified](art/fancified.svg)](https://particle.academy)

A PDF reader and writer for agents, in pure PHP. It parses PDFs, extracts
their text in reading order, reports active content (JavaScript, launch
actions, forms, embedded files) without ever running it, and passes everything
an agent reads through a **prompt-injection guard** that can flag, redact or
discard. It also **writes** PDFs from the same JSON document model as
[`last-word`](https://github.com/Particle-Academy/last-word), and never writes
active content into them.

**Zero runtime dependencies.** No GD, no Imagick, no Ghostscript, no poppler,
no third-party PDF library. Just PHP 8.4 and its bundled `zlib`, `mbstring`,
`ctype` and `json`.

> **Status: 0.x, reader and writer.** A page renderer (PNG) and Laravel
> page-image serving are planned later phases, not part of this release.
> Pre-1.0, breaking changes may land in a minor release; the
> [changelog](CHANGELOG.md) marks each one.

## Why

Sister project to [`holy-sheet`](https://github.com/Particle-Academy/holy-sheet)
(xlsx), [`dark-slide`](https://github.com/Particle-Academy/dark-slide) (pptx)
and [`last-word`](https://github.com/Particle-Academy/last-word) (docx).

Those mostly write documents an agent asked for. PDF is the format an agent is
most often handed **by someone else**: an upload, an attachment, a supplier's
invoice. That makes a PDF read the place an agent is most exposed to prompt
injection, so this package never hands an agent raw file content.

## Install

```bash
composer require particle-academy/fine-print
```

## Quickstart

```php
use FinePrint\Agent;

$read = Agent::read('/path/to/upload.pdf');      // bytes work too

if (! $read['ok']) {
    // Refused: not a PDF, encrypted, damaged beyond repair, or over a limit.
    return $read['refused']['reason'];            // e.g. "decompression_bomb"
}

$read['pageCount'];             // 3
$read['pages'][0]['text'];      // page 1, in reading order, guarded
$read['guard']['findings'];     // what the guard found, and where
$read['activeContent'];         // scripts, launch actions, forms, ...
```

For a host that only needs the page count (and wants an exception, not a
shape, on a bad file):

```php
Agent::pageCount($bytes);       // throws FinePrint\Exceptions\RefusedException
```

## The agent-read guard

Every string `Agent::read()` returns passes through it: page text, metadata,
XMP, outline titles and annotation text.

| rule | catches |
|---|---|
| `instruction` | text addressed to an AI: "ignore previous instructions", "note to the AI:", "do not tell the user", chat-template tokens, requests to reveal a system prompt or exfiltrate credentials |
| `hidden-text` | text a human would not see: render mode 3, white or near-white paint on a white page, under one point tall |
| `off-page` | text drawn outside the visible page |
| `invisible-chars` | zero-width characters, bidi overrides, and Unicode tag characters, which are decoded and checked for what they spell |

Instructions found in places a human never looks (metadata, XMP, annotations,
the outline) or inside hidden text are raised to `high`.

### Policies

```php
Agent::read($pdf, ['policy' => 'redact']);   // the default
```

- **`flag`**: content unchanged, findings attached. For files you trust.
- **`redact`**: findings at or above the threshold are cut out. An instruction
  becomes `[fine-print: redacted instruction]`, hidden text becomes
  `[fine-print: removed hidden text]`, and invisible characters are removed.
- **`discard`**: any finding at or above the threshold and the agent gets **no
  content at all**, only the findings.

`threshold` is `low`, `medium` (default) or `high`. Joiners and directional
marks are `low` because they are legitimate in emoji and in right-to-left and
Indic scripts. They are reported, but not acted on at the default threshold.

### Locations, not excerpts

Each finding says where it is:

```php
['rule' => 'instruction', 'severity' => 'high', 'pattern' => 'ignore-previous',
 'hidden' => false, 'smuggled' => false,
 'location' => ['source' => 'page', 'page' => 1, 'offset' => 18, 'length' => 28]]
```

Findings do **not** quote the offending text by default, because an excerpt of
an injection is still an injection. A host that wants excerpts for a human
audit log can pass `['excerpts' => true]`. Never hand that report to an agent.

### What the guard is not

It is a **detector**, not a proof. It catches the phrasings and hiding tricks
that are actually used, and a determined author can phrase around any list.
Use `discard` for untrusted uploads. Known gaps:

- Hidden-text detection assumes a white page. Text painted over by a later
  shape, or hidden by a clipping path, is not detected yet.
- Patterns are English. Homoglyph and leetspeak spellings are not normalised.
- Many producers drop zero-width characters when they render, so those often
  never reach the PDF at all.

## Active content

```php
$read['activeContent'];
// [['kind' => 'javascript', 'severity' => 'high', 'object' => '1 0',
//   'path' => '/OpenAction', 'detail' => ['length' => 18, 'sha256' => '...']]]
```

It reports `javascript`, `launch`, `submit-form`, `import-data`, `xfa`,
`open-action`, `additional-actions`, `embedded-file`, `rich-media`,
`remote-goto`, `uri` and `acroform`. Every object in the file is scanned, not
just what the catalog reaches, and names are compared decoded, so a
`/J#61vaScript` escape hides nothing.

Active content is **detected and reported, never executed or followed.** There
is no code path that runs a script, opens a file, fetches a URI or submits a
form. A finding never carries the script body, the launch target or the URI,
because those are attacker-authored text. Scripts are identified by length and
SHA-256 instead.

**Forms are not supported.** An AcroForm or XFA form is reported. Field values
are not read, filled or submitted.

## Hostile input

Everything in a PDF is authored by someone else, so the whole reader is held to
the hostile-input bar. Limits are in `FinePrint\Limits` and every one can be
overridden:

```php
Agent::read($pdf, ['limits' => ['maxPages' => 200, 'timeSeconds' => 5]]);
```

| limit | default | refusal reason |
|---|---|---|
| `maxFileBytes` | 256 MB | `too_large` |
| `maxObjects` | 1,000,000 | `too_many_objects` (checked against what an xref *declares*, before allocating) |
| `maxDepth` | 64 | `too_deep` |
| `maxStreamBytes` | 64 MB per stream | `too_large` |
| `maxTotalDecodedBytes` | 512 MB | `too_large` |
| `maxRatio` | 500:1 (past 1 MB) | `decompression_bomb` |
| `maxOperators` | 2,000,000 per page | `too_many_operators` |
| `maxFormDepth` | 12 | skipped, with a warning |
| `maxPages` | 50,000 | `too_many_pages` |
| `timeSeconds` | 30 | `time_budget` |
| `memoryBytes` | 256 MB growth | `memory_budget` |

Other refusals are `not_pdf`, `corrupt` and `encrypted`. A refusal message
never quotes the file.

Some damage is survived rather than refused, and is listed in
`$read['warnings']`. That covers reference cycles, a page tree or outline that
loops, a form XObject that draws itself, and a `/Prev` chain that loops. A
missing, truncated or lying cross-reference table is **rebuilt by scanning**,
and `$read['repaired']` says so.

**Encrypted files are refused**, including those encrypted with an empty user
password, which many producers use only to set permissions.

## The raw view, for hosts

`FinePrint\Document` is the unguarded parser underneath. It is for the host,
never for an agent.

```php
use FinePrint\Document;

$doc = Document::open($bytes);          // optional second argument: Limits
$doc->pageCount();
$doc->pages();                          // number, width, height, rotate, mediaBox, cropBox
$doc->text(1);                          // raw text, hidden text and invisible characters included
$doc->metadata();  $doc->xmp();  $doc->outline();  $doc->annotations(1);
$doc->activeContent();
```

## What it reads

- Cross-reference tables and streams, hybrid files, incremental updates
  (newest object wins), object streams.
- FlateDecode, LZWDecode, ASCII85Decode, ASCIIHexDecode, RunLengthDecode, with
  PNG and TIFF predictors. Image codecs (DCT, JPX, CCITT, JBIG2) are kept as
  opaque bytes and are not decoded.
- Text through ToUnicode CMaps, the standard encodings and `/Differences`,
  including two-byte Type0 fonts and text inside form XObjects.

Reading order is line by line, top to bottom. **Multi-column layouts read across
the columns**, which is a known limit.

## Writing PDFs

The document model is last-word's: a `title`, `page` geometry with running
`header`/`footer` bands, and `blocks`.

```php
use FinePrint\Agent;

$doc = [
    'title' => 'Q3 board memo',
    'page' => ['size' => 'letter', 'footer' => ['right' => 'Page {page} of {pages}']],
    'blocks' => [
        ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Summary']]],
        ['type' => 'paragraph', 'align' => 'justify', 'runs' => [
            ['text' => 'Revenue rose '], ['text' => '12%', 'bold' => true],
            ['text' => '; details on the '], ['text' => 'finance site', 'link' => 'https://example.com/q3'], ['text' => '.'],
        ]],
    ],
];

Agent::validate($doc);            // [{path, message}], empty when valid
Agent::validateAndRepair($doc);   // {ok, schema, errors}: repairs listed
Agent::lint($doc);                // what will be wrong, before writing
Agent::write($doc, 'memo.pdf');   // or Agent::toBytes($doc)
Agent::fromArray($rows);          // rows of data -> a finished table document
Agent::jsonSchema();              // for LLM tool registration
Agent::guide();                   // the agent guide (agent-guide/building-pdfs.md)
```

- **Blocks:** heading, paragraph, list (nested, ordered or not), table (column
  weights, borders, cell padding, fills, column and row spans, header rows
  repeated on every page), code, quote, image (PNG and JPEG data URLs), page
  break and rule. No `chart` block yet.
- **Runs:** bold, italic, underline, strike, inline code, colour, highlight,
  size, letter spacing, font, and links.
- **Layout:** line wrapping and justification with real font metrics; headings
  kept with what follows; no single line left at the foot or head of a page.
- **Fonts:** the standard 14 text faces (Helvetica, Times, Courier) with their
  metrics, in Windows-1252. For anything else, the host registers TrueType
  fonts, embedded as subsets with a ToUnicode map so the text extracts exactly:

  ```php
  Agent::write($doc, 'out.pdf', ['fonts' => ['Inter' => ['regular' => '/fonts/Inter.ttf', 'bold' => '/fonts/Inter-Bold.ttf']]]);
  ```

  Fonts come from the host only. A document can name a family, never supply a
  file or a path. CFF-flavoured OpenType (`.otf`) and collections are not
  supported yet; a font whose licence forbids embedding is refused.
- **Output:** PDF 1.7 with a classic cross-reference table. **Deterministic**:
  no timestamps, so the same document gives the same bytes.

**What it never writes:** JavaScript, open actions, additional actions, launch
actions, forms or widgets, embedded files, rich media or XFA, whatever the
document contains. Links must be `http`, `https` or `mailto`. Text from the
model reaches the file only as hex strings, and zero-width, bidi-control and
tag characters are removed, so the writer cannot be used to smuggle hidden
text. Everything it writes reads back through `Agent::read()` with no guard
findings.

## License

MIT
