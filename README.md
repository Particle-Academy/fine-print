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

> **Status: 0.x, reader and writer, plus the first steps of a page renderer.**
> The renderer draws vector paths, TrueType text and sampled image XObjects
> (see [Rendering pages](#rendering-pages)), transparency groups, soft masks and
> blend modes; other font types and advanced image codecs are later steps. Laravel 13 page-image serving is
> available through the optional adapter below. Pre-1.0, breaking changes may
> land in a minor release; the [changelog](CHANGELOG.md) marks each one.

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
    // Refused: not a PDF, needs a password, damaged beyond repair, or over a limit.
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

**The agent is told when content was redacted.** A redacting read carries a
`notice` in plain words (how many items, what kind, where) and a `redacted`
summary (`count`, `byRule`, `where`), never the cut text itself. The original
is available only as a deliberate second call, which a host may or may not
expose as a tool:

```php
$read = Agent::read($pdf);              // redacted, with $read['notice'] saying so
$open = Agent::readUnredacted($pdf);    // original text, findings attached, unredacted: true
```

`readUnredacted()` always uses the `flag` policy and ignores a `policy`
option. Under `discard`, the notice says the content was withheld and does not
offer the second step.

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
| `maxRenderPixels` | 25,000,000 across the page and live group/mask buffers | `too_many_pixels` (judged before allocation) |
| `maxImagePixels` | 16,000,000 per source image | `too_many_pixels` (judged before decoding) |
| `renderTimeSeconds` | 30 per rendered page | `time_budget` |

Other refusals are `not_pdf`, `corrupt` and `encrypted`. A refusal message
never quotes the file.

Some damage is survived rather than refused, and is listed in
`$read['warnings']`. That covers reference cycles, a page tree or outline that
loops, a form XObject that draws itself, and a `/Prev` chain that loops. A
missing, truncated or lying cross-reference table is **rebuilt by scanning**,
and `$read['repaired']` says so.

**Encrypted files open when their user password is empty**, which is how
many producers set permission flags without asking anyone for a password.
The standard security handler is supported in full: RC4 40 to 128-bit and
AES-128 (R2-R4), and AES-256 (R5, R6), with this package's own RC4 and AES (no
openssl). The guard and the active-content scan run on the decrypted content
as usual, and the result says what it was:

```php
$read['encryption'];
// ['encrypted' => true, 'handler' => 'Standard', 'version' => 4, 'revision' => 4,
//  'algorithm' => 'AES-128', 'encryptMetadata' => true,
//  'permissions' => ['print' => false, 'modify' => false, 'copy' => true, ...]]
```

Permissions are **reported, not enforced**: they are the author's request to
viewers, and what to do with them is the host's decision. A file that needs a
real password is still refused (`encrypted`), as is one using another security
handler (certificates). A malformed encryption dictionary is `corrupt`.
Nothing tries a password other than the empty one, and no report carries a key.

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
- **Page decoration:** `page.background` paints a colour full-bleed, and
  `page.border` draws a frame inset from the page edge (single, double,
  dashed or dotted), on every page under all content and independent of how
  much the page holds: `'page' => ['background' => '#FDFAF3', 'border' =>
  ['style' => 'double', 'width' => 6, 'color' => '#B08D57', 'inset' => 18]]`.
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

## Exchange: portable content and exports

The four document packages (holy-sheet, last-word, dark-slide, fine-print)
move content between each other as portable content, `fancyContent: 1`, a
plain-JSON format specified in fancy-conformance (`suites/exchange/SPEC.md`)
and tested here against its suites. No package depends on another to do it.

```php
// A spreadsheet range, extracted by holy-sheet, placed in a PDF.
$doc = ['blocks' => [
    ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Q4 review']]],
    ['type' => 'portable', 'content' => $range],   // validated, then expanded
]];
$fixed = FinePrint\Agent::validateAndRepair($doc);   // schema, errors, report
$pdf = FinePrint\Agent::toBytes($fixed['schema']);

// A PDF's text, for a document or a deck to place.
$x = FinePrint\Agent::extract($pdf, ['pages' => [1, 3]]);   // ok, content, report

// Exports are OFF until the host turns one on.
$policy = new FinePrint\Exchange\ExportPolicy(['allow' => ['markdown']]);
FinePrint\Agent::export($pdf, 'markdown', [], $policy);      // ok, target, mime, text, report
FinePrint\Agent::exportToolDefinition($policy);              // target enum: ["markdown"]
```

- **`fromPortable` / the `portable` block:** content is checked closed (an
  unknown key is refused), links are http/https/mailto, image bytes must be
  the PNG or JPEG they claim, and the limits apply (10,000 cells, 2,000 rows,
  64 columns, 16 images of 5 MiB, 4 MiB of text, 5,000 items; a host may
  change them). Tables keep header rows, spans and widths; numeric columns
  are right-aligned. Formulas are never carried, only their values.
- **`extract`:** a read, so it is not gated. The guard runs (redact by
  default) and every invisible, bidi-control and tag character left is
  stripped and counted. Pages become paragraphs; PDF tables and headings are
  not recovered, and the report says so for each page.
- **`export`:** `text` and `markdown`. Default deny; only a host-built
  `ExportPolicy` enables a target, and nothing an agent passes can.
- **Reports** count and locate (JSON Pointers into the input); they never
  carry document text.
- **`describe($doc)`** summarises a document for the model.

## Rendering pages

For hosts: render a page to PNG, in pure PHP. Serving page *images* instead of
the PDF is what makes "no download, no print" meaningful in a viewer.

```php
use FinePrint\Render\Renderer;

$png = Renderer::png($pdfBytes, page: 1, scale: 2.0);   // 144 DPI

$r = Renderer::render($pdfBytes, 1, 2.0);
$r->width; $r->height;      // pixels
$r->png();                  // RGB PNG bytes
$r->complete();             // false when anything on the page was NOT drawn
$r->skipped;                // [['kind' => 'text-unembedded', 'count' => 12, 'fonts' => ['Symbol']], ...]
```

`scale` is pixels per point: 1.0 is 72 DPI. The page shown is the CropBox (cut
to the MediaBox), turned by `/Rotate`, on white. A `Document` already open can
be passed instead of bytes.

**What is drawn so far:** filled and stroked paths (even-odd and nonzero,
Bézier curves, line widths, caps, joins, miter limits, dashes), anti-aliased
by exact pixel coverage; DeviceGray, DeviceRGB and DeviceCMYK colour (CMYK by
the simple formula, so it differs from a press profile), ICC-based, CalGray
and CalRGB colour by their channel count; `cm` transforms, `q`/`Q`, line
settings from ExtGState, and form XObjects.

**Text** in fonts whose TrueType program is embedded in the file: simple
TrueType fonts (glyphs chosen through the encoding, `/Differences` and the
font's (3,1), (3,0) or (1,0) cmap) and Type0 fonts over CIDFontType2
(Identity-H or an embedded CMap, with a CIDToGIDMap). All text state: `Tc`,
`Tw`, `Tz`, `TL`, `Ts`, `Td`/`TD`/`Tm`/`T*`, `Tj`/`TJ`/`'`/`"`, through the
text matrix and the CTM. Render modes fill, stroke, fill and stroke, and
invisible; the clipping modes paint and report the clip as skipped. Glyphs
are drawn from their outlines, unhinted: hinting instructions are never run.

**Standard-14 text that is not embedded** (Helvetica, Times and Courier in all
four styles, and Arial, Times New Roman and Courier New, which viewers treat
as the same faces) is drawn from metric-compatible fonts bundled unmodified in
`resources/fonts`: Liberation Sans 2.1.5, Tinos 1.340 and Cousine 1.241, all
under the SIL Open Font License 1.1 (see `resources/fonts/NOTICE`). Where the
PDF gives no `/Widths`, text advances by the standard 14's own widths, so it
lands where the producer laid it out. The glyphs are these fonts' designs,
not Adobe's, so they look close to, not identical with, another viewer's.

**Image XObjects:** raw, Flate/LZW (including PNG/TIFF predictors), ASCII85,
ASCIIHex and RunLength samples; Gray/RGB/CMYK at 1/2/4/8/16 bits, Decode
arrays, Indexed palettes, and stencil masks in the current fill colour.
Source row padding and PDF's top-to-bottom sample order are preserved.
Images use nearest-neighbour sampling under any non-singular transform.
`maxImagePixels` bounds the source image before decoding (default 16 million).
Inline images use the same sampling and preserve binary `EI` bytes. Baseline
JPEG is decoded by our own Huffman/IDCT engine, including grayscale,
YCbCr/RGB, CMYK/YCCK, subsampling, restarts and separate sequential scans.
Adobe markers and PDF ColorTransform/Decode settings are respected.

**Clipping and transparency:** nonzero/even-odd path clips, Form BBox and
text clipping modes; constant fill/stroke alpha; image soft masks, explicit
stencil masks and colour-key masks. Text clips accumulate until `ET`; path
clips begin after painting the current path. Form transparency groups support
isolation, knockout, native Gray/RGB/CMYK blending and invocation alpha, blend
mode and soft mask applied once. ExtGState Alpha and device-space Luminosity
masks honour BC, AIS and Identity/type 0/2/3 transfer functions; their coordinates
are fixed when `gs` installs them. All sixteen PDF blend modes are supported,
including Hue, Saturation, Color and Luminosity. Combined fill/stroke portions
remain one transparency object. Separation/DeviceN colours use
their alternate space for exponential or multilinear sampled tint functions
(up to four inputs/outputs). Calculator functions remain unsupported.

**What is NOT drawn yet, and is listed in `skipped`:** text in any other font
with no embedded program (`text-unembedded`, which includes Symbol and
ZapfDingbats: nothing is bundled for them), in CFF
(`text-cff`), Type 1 (`text-type1`) or Type 3 (`text-type3`) fonts, vertical
text (`text-vertical`), predefined CJK CMaps (`text-cmap`), fonts that cannot
be read (`text-font-invalid`) and single damaged glyphs (`text-glyph`);
Progressive/arithmetic/lossless JPEG, JPX/CCITT/JBIG2 or damaged images (drawn
as checkerboard placeholders), shadings, patterns, unknown blend modes/group
spaces, unsupported mask transfers, calibrated luminosity masks and TK=false
text (reported as `transparency`), unsupported tint functions
(drawn as grey), annotation
appearances, and optional-content visibility (everything is drawn). The
`text-*` entries name the fonts concerned. Check `complete()` before treating a
render as the whole page.

Rendering is bounded like reading: the pixel count is judged before anything
is allocated, every operator counts against `maxOperators`, forms nest at most
`maxFormDepth` deep and never into themselves, and each render has its own
time budget (see [Hostile input](#hostile-input)).
Group/mask buffers are cropped in device coordinates and share the live pixel
cap; normal invariant groups need no extra buffer. Raster, clip, group/mask
and JPEG allocations also reserve row overhead and PHP's hard
memory-limit headroom before allocating. Unsupported nested or Matte image
masks produce a reported placeholder.

## Laravel page images

The optional Laravel 13 adapter serves PNG pages behind your app's own auth
and file policy. It never serves the PDF bytes. Register it in a service
provider's `boot()` so the handler binding also exists when routes are cached:

```php
use App\Models\Document as StoredDocument;
use FinePrint\Laravel\PageImages;
use FinePrint\Laravel\PageSource;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Storage;

public function boot(Router $router, Repository $cache): void
{
    PageImages::register(
        $router,
        '/private-pdf/{file}/{page}.png',
        function (Request $request, string $id): ?PageSource {
            $document = StoredDocument::find($id);
            if (! $document || ! $request->user()->can('view', $document)) {
                return null;
            }

            $stream = Storage::disk('private')->readStream($document->path);
            if (! is_resource($stream)) {
                return null;
            }
            try {
                return PageSource::fromStream(
                    $stream,
                    key: $document->tenant_id.':'.$document->getKey(),
                    version: (string) $document->updated_at->getTimestamp(),
                );
            } finally {
                fclose($stream);
            }
        },
        $cache,
    )->name('private-pdf.page');
}
```

Replace the model and policy with your app's. The resolver runs on **every
request**, including cache hits. `PageSource` takes already-authorised bytes,
a server file identity and a server version. `fromStream()` instead reads an
already-open, host-authorised stream, at most the byte limit plus one byte,
with memory preflights and a two-second reading budget. It leaves ownership
of the resource with the caller and never opens a path or URL.

The clean raster cache includes the file identity, version, content hash,
page, canonical scale and limits. It contains compressed RGB and validated
render metadata, with a version/checksum and bounded streaming decompression;
no PHP objects are deserialised. The cache backend is your app's private store.
Watermarks are applied to a copy per authorised request, so cached clean pages
can be shared without reusing another viewer's identity.

By default the watermark tiles the authenticated user's name, email (or ID)
and the server's request time in UTC into the actual pixels. Pass one to three
trusted text lines as `PageSource`'s `watermark` argument to customise them. Lines are
bounded and encoded as glyph data, never PDF operators. The bundled font uses
WinAnsi; use an email/ID when a name needs characters outside that font.

Serving defaults (override with the final `options` array):

| option | default |
|---|---|
| `middleware` | `['auth']`; your app may supply its own auth middleware |
| `limits.renderTimeSeconds` | 3 seconds for cache/render/watermark/PNG together |
| `limits.maxFileBytes` | 32 MB |
| `limits.maxRenderPixels` | 8 million |
| `maxDimension` | 8192 pixels per side |
| `maxScale` | 4; client scales quantise to quarter steps from 0.25 |
| `ttl` | 300 seconds for clean raster entries |
| `namespace` | `fine-print.raster.v1` |
| `watermark` | `true` |
| `requireComplete` | `true`; incomplete renderer output returns 422 |

Authentication/source I/O and cache-backend network timeouts remain the app's
responsibility. The rendering budget covers our cache, renderer, watermark and
PNG work; source-stream reading has its separate budget. Size violations return
413, invalid scales 400, inaccessible files/pages 404, and other clean refusals
422, with stable reasons and no file content in the error. PNG responses are
`private, no-store` with `nosniff`. If the host explicitly sets
`requireComplete: false`, partial images retain `X-FinePrint-Complete: 0`.

For `react-fancy`'s `PdfViewer`, obtain the count on the server after the same
file authorisation (`Agent::pageCount($pdfBytes)`), then provide image URLs:

```tsx
<PdfViewer pages={{
  count: pageCount,
  url: (page, scale) => `/private-pdf/${encodeURIComponent(fileId)}/${page}.png?scale=${scale}`,
}} />
```

All Illuminate packages are optional suggestions for consumers. They are test
dependencies here so the route/cache integration is exercised against Laravel
13. A separate child process tests the core with no vendor/framework autoloader.

## License

MIT
