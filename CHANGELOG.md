# Changelog

All notable changes to `particle-academy/fine-print` are recorded here, in
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

**Pre-1.0: breaking changes may land in a MINOR release.** Every one is marked
BREAKING and says what a consumer must do.

## [Unreleased]

Tests and tooling only: no change to anything a consumer installs.

### Added

- **The conformance exporter for the ports**, `tests/Conformance/Export.php`
  (`composer conformance:export`). It writes the `fine-print/php-semantics`,
  `writer-model` and `writer-bytes` suites (90 / 57 / 51 rows) and their
  fixtures into the sibling fancy-conformance checkout. These rows are what
  fine-print-js and fine-print-py are held to. `ConformanceTest` proves this
  package passes all of its own exported rows after the trip through JSON and
  the loader. `DriftTest` re-exports to a temporary directory and fails if
  anything moved, so a change to the writer's output is a reviewed contract
  change.
- **The sabotage lists, in-repo** (`tests/Sabotage/lists/`, run with
  `php tests/Sabotage/run.php`). They used to live only in the envelope's
  gitignored sandbox, where 3 of their 73 edits had silently stopped applying.
  `SabotageListsTest` now fails the ordinary suite when an edit no longer
  applies. Full pass on 2026-10-27: 69 of 72 cases go red. `pixel cap removed`,
  `miter limit ignored (golden)` and `no cull` stay green and are open
  findings.

### Changed

- require-dev pins `particle-academy/fancy-conformance` at exactly `0.38.0`
  (from `0.37.0`). That release adds file references and the `fine-print/*`
  suites, and changes no existing suite.

## [0.5.0] - 2026-10-27

Portable content: fine-print reads into, and writes from, the plain-JSON
format the four document packages use to move content between each other
(`fancyContent: 1`, normative in fancy-conformance 0.37.0
`suites/exchange/SPEC.md`). No 0.4.0 was released: 0.5.0 is the floor the
sibling readers' PDF bridges will require (`conflict: <0.5`). Nothing here is
breaking; existing documents write the same bytes.

### Added

- `Agent::fromPortable($content)`: portable content to fine-print blocks,
  `{ok, blocks, report}`. Tables keep header rows, spans and widths; numeric
  columns are right-aligned (header included); typed values become their
  display text (`typed-as-text`); formulas are never carried, only their
  values (`formula-as-value`). Invalid content is refused as
  `{ok: false, refused: {reason, path}}` and produces nothing.
- An inline `{"type": "portable", "content": ...}` block, accepted anywhere a
  block is (the document, a quote, a table cell). `validate()` checks it
  closed and against the exchange limits (`content_invalid`,
  `content_too_many_*`, `image_too_large`, `content_too_large`, with a JSON
  Pointer). `validateAndRepair()`, `toBytes()`, `write()` and `lint()`
  expand it through `fromPortable`, deterministically: `errors` gains one note
  per expansion and the result gains `report`, located into your document.
  Invalid portable content is left in place and refused, never repaired.
- `Agent::extract($pdf, $selector, $options)`: a PDF's pages as portable
  content, through the agent-read guard (redact by default). Every
  zero-width, bidi-control and Unicode tag character left is stripped and
  counted, under any policy; the report carries counts and page locations,
  never document text. `{pages: [first, last]}`, 1-based. Not gated: it is a
  read. `Agent::toPortable($read, ...)` is its pure half.
- `Agent::export($pdf, $target, $options, $policy)` for `text` and `markdown`
  (`## Page n` and escaped paragraphs). OFF by default: a host-built
  `Exchange\ExportPolicy` enables targets, a null policy allows nothing, and
  agent options cannot enable anything (an unknown option throws). Refusals
  in the pinned order: `export_unsupported`, `export_disabled`,
  `export_unavailable`, `export_refused`, then a limit code.
  `Agent::exportToolDefinition($policy)` lists only the enabled targets.
- `Agent::describe($doc)`: a compact summary for the model, as last-word's.
- `Agent::validateContent($content, $options)`.
- The JSON schema describes the portable node as `$defs.portable`, beside the
  model (`$defs.blockOrPortable`); `$defs.block` is unchanged. The agent guide
  has a section on it. Both are now hash-pinned.
- Conformance: every `exchange/*` suite naming fine-print (content 56, limits
  32, policy 25, report-kinds 40, from-fine-print 9, to-fine-print 11 rows)
  and fine-print's half of `doc-model/shared` (20) run in the suite against
  `particle-academy/fancy-conformance` 0.37.0, an exact require-dev pin.
  Development only: no runtime dependency was added.

### Fixed

- A link with a trailing line feed was accepted. `Schema::LINK_PATTERN` ended
  in `$`, which PCRE also matches before a final newline, so
  `"https://example.com\n"` passed both link checks and reached the PDF's
  `/URI`. The pattern is now anchored at the true end (`D`). Upgrade normally;
  only such links change: validation now reports them and repair removes them,
  keeping the text.

## [0.3.0] - 2026-10-27

### Added

- Page decoration. `page.background` (`#RRGGBB`) paints the colour
  full-bleed on every page, before any content. `page.border` draws a frame
  measured from the page edge rather than from the content, so it is the
  same on a page holding one line as on a full one: `style` (single, double,
  dashed, dotted, none; single by default), `width` (the whole frame, 0.5 by
  default; a double is two rules of a third each), `color` (black) and
  `inset` (points from the edge to the outside of the frame, 24 by default).
  Frames are closed rectangles, so corners meet. Validation refuses a frame
  that leaves no room inside it; repair removes an invalid background or
  frame and says so; the JSON schema and agent guide describe both. The
  frame does not move the text: new lint `frame-overlap` warns when a margin
  is narrower than `inset + width`. White text on a dark page background is
  not flagged as hidden. Nothing changes for documents without them; their
  bytes are identical.
- PARITY: `last-word` (and its JS and Python ports), whose document model
  this shares, has neither key yet. Its validator ignores them, so the same
  document writes a .docx without the decoration, and its JSON schema
  (`additionalProperties: false` on `page`) rejects them.

### Fixed

- Letter spacing no longer leaks from one text run into every later line.
  The writer reset its record of character spacing (`Tc`) at each text
  object, but `Tc` is graphics state and persists across `BT`/`ET`, so after
  one run with `letterSpacing` the rest of the page was drawn tracked while
  layout measured it untracked, and lines overflowed their frame. An explicit
  `letterSpacing: 0` did not help, because it matched the stale record and no
  `Tc` was written. Spacing is now tracked per page. Upgrade normally; nothing
  changes for documents that never set `letterSpacing`. The writer emits no
  other persistent text-state operator (`Tw`, `Tz`, `TL`, `Ts`, `Tr`), so
  none had the same defect.

## [0.2.1] - 2026-10-23

### Fixed

- Restore ordinary PDF rendering performance after 0.2.0's transparency work.
  Plain pages use RGB spans with colour converted once per paint, skip alpha
  storage and reuse identical opaque rows in a bounded cache. Images use the
  direct RGB route when equivalent. Upgrade normally; pixels, completeness,
  cache revision and strict page-image defaults stay the same.
- Allocate page alpha only when an actual non-Normal blend needs it. Replay
  once to recover earlier transparency under the same time, memory, pixel and
  operator budgets. Preserve image/stencil component clamping from 0.2.0.
- Deduplicate memory preflight snapshots while retaining copy-on-write guards
  and detecting runtime PHP memory-limit changes. Refuse extremely wide raster
  edges before their sparse cell arrays exhaust PHP's hard memory limit.

### Validation

- Six owned opaque fixtures keep byte-identical RGB output to 0.2.0 and
  allocate no page-alpha, shape or offscreen planes. A fixed 1.75x budget
  against an independent in-run RGB raster reference catches the regression;
  the released 0.2.0 measures 3.35x on the same guard.
- Alternating same-process seven-run medians before/after (ms): blocks
  34.91/13.97, deck 394.88/261.96, P2 report 109.40/71.67,
  P2 text 35.57/21.01, standard fonts 176.86/120.14, sampled image
  168.91/97.53. These are within 9% of 0.1.0 or faster while retaining the
  newer allocation guards; timings are evidence, not absolute CI thresholds.
- All twelve local Quartz page/scale PNGs remain byte-identical to 0.2.0.
  No dependency, font, golden fixture or workflow is changed.

## [0.2.0] - 2026-10-23

Mac Quartz PDFs that use ordinary transparency groups and device-space soft
masks now render complete pages, so the default strict Laravel page-image
route can serve them. Upgrade normally; `requireComplete` stays enabled.
Cached page images are refreshed automatically, including custom namespaces.

### Added

- Form transparency groups with isolated/non-isolated backdrops, knockout,
  native Gray/RGB/CMYK blending and invocation alpha/blend/mask applied once.
  Equivalent normal groups draw directly without an unnecessary buffer.
- ExtGState Alpha and device-space Luminosity masks with BC backdrops,
  Identity, sampled, exponential and bounded stitching transfer functions.
  Mask coordinates are fixed when `gs` installs them. AIS shape semantics,
  explicit/soft image mask precedence and atomic path/glyph fill+stroke.
- All sixteen PDF blend modes, including Hue, Saturation, Color and Luminosity.
  Page groups blend before the white display background is applied.
- Cropped offscreen and soft-mask buffers count against the shared live
  `maxRenderPixels` cap and are checked against the memory budget and PHP's
  hard memory limit before allocation. Recursive masks share the form-depth
  guard. Calculator transfers, calibrated luminosity masks, unknown group
  spaces/modes and TK=false text remain reported approximations.

### Validation

- Own synthetic Quartz fixtures, Chromium goldens for every blend mode and
  alpha/luminosity masks, child-process memory checks and cache-upgrade coverage.
  Bounds remain MAE <=2 and <=1% pixels off by >64. Chromium's measured
  isolation/knockout limitation is explicit; those cases use independently
  expanded ISO equations and tests proven red when I or K is ignored.
- A local, uncommitted six-page Quartz document renders complete at 1x and 2x.
  No third-party runtime dependency or fixture is added.

### Changed

- **BREAKING for custom pixel caps:** `maxRenderPixels` now counts all live
  rendering buffers, rather than only the page. If your cap was exactly one
  page's area, allow room for group/mask buffers or request a lower scale.

## [0.1.0] - 2026-10-23

First release. Install with `composer require particle-academy/fine-print`;
there is no migration. Optional Laravel serving needs an app-owned resolver
that authorises each file; see the README's service-provider example.

Validation: 777 tests, 6049 assertions; reference compatibility against
fancy-labs' document models; no PDF lab lane exists.

### Added

- **Optional Laravel 13 page-image serving:** route-cache-compatible
  `PageImages::register()`, app authentication and per-request file
  authorisation before cache access. `PageSource::fromStream()` reads only
  an already-authorised stream with bounded bytes, memory and time.
- **Private watermarked PNG responses:** a shared clean raster is restored
  from a versioned/checksummed compressed-RGB packet, never PHP object
  deserialisation. The authenticated viewer's name, email or ID and server
  request time are burned into a copy on every request. Labels are bounded
  WinAnsi glyph data. No PDF bytes or another viewer's marked image are served.
- **Serving limits:** three seconds for cache/render/watermark/PNG, 32 MB
  source files, eight million render pixels, 8192 pixels per side and quarter
  scales up to 4x by default. Incomplete pages return 422 unless the host opts
  in; size, time, memory and malformed-input refusals return clean errors.
- Real Laravel 13 routing/cache/http/events components as TEST dependencies
  for the optional serving adapter. This is the framework test-dependency
  exception in workspace RULES.md; no framework runtime requirement is added.
  Laravel v13.38.0 released 2026-10-20 (freshness checked before adding).
- **P3d clipping and transparency subset:** nonzero/even-odd path clips,
  Form BBox, text clips accumulated through ET, constant CA/ca, grayscale
  image soft masks, explicit stencil and colour-key masks. Separation/DeviceN
  uses type 2 exponential or type 0 multilinear sampled tint transforms
  (up to four inputs/outputs, all PDF sample depths). Unsupported groups,
  blend modes, calculator functions and advanced masks remain reported.
- **P3c inline images and our own baseline JPEG decoder:** Huffman DC/AC,
  dequantisation/IDCT, Gray/RGB/YCbCr/CMYK/YCCK, subsampling, separate scans,
  restart markers and byte stuffing. Adobe APP14 overrides ColorTransform;
  PDF Decode is applied afterwards. Inline boundaries preserve binary EI
  bytes, including within compressed samples and JPEG metadata. Progressive
  and other unsupported codecs paint reported checkerboard placeholders.
- **P3c image XObjects (first step):** packed Gray/RGB/CMYK samples at
  1/2/4/8/16 bits, Flate/LZW and PNG/TIFF predictors, Decode arrays, Indexed
  palettes and fill-colour stencil masks, transformed with bounded
  nearest-neighbour sampling. `maxImagePixels` defaults to 16 million,
  checked before decoding. Unsupported/damaged images remain reported.
- **A PDF reader in pure PHP**, with no runtime dependency beyond PHP's bundled
  `zlib`, `mbstring`, `ctype` and `json`. Reads the header, cross-reference
  tables and streams, hybrid files, incremental updates (newest object wins)
  and object streams. Decodes FlateDecode, LZWDecode, ASCII85Decode,
  ASCIIHexDecode and RunLengthDecode, with PNG and TIFF predictors. Image
  codecs remain opaque during text extraction; the renderer decodes baseline
  DCT itself and reports unsupported JPX, CCITT and JBIG2 images.
- **Text extraction in reading order** (`Document::text()`), through the font's
  ToUnicode CMap, its encoding and `/Differences`, including two-byte Type0
  fonts and text inside form XObjects.
- **Page count and page geometry** (`Document::pageCount()`, `pages()`), with
  MediaBox, CropBox and Rotate inherited down the page tree.
- **Metadata, XMP, outline and annotation text** (`metadata()`, `xmp()`,
  `outline()`, `annotations()`).
- **Active-content detection** (`Document::activeContent()`): JavaScript,
  OpenAction, additional actions, Launch, URI, SubmitForm, ImportData, remote
  go-tos, embedded files, RichMedia, XFA and AcroForm, each with its object and
  key path. Detected and reported, **never executed or followed**. Scripts are
  identified by length and SHA-256; no finding carries a script body, a launch
  target or a URI. Name escapes (`/J#61vaScript`) and object streams hide
  nothing. Forms are not supported: they are reported, never filled or
  submitted.
- **`Agent::read()`, the agent-read guard.** Every string handed to an agent
  (page text, metadata, XMP, outline, annotations) is scanned for instructions
  aimed at an AI, hidden text (invisible render mode, white on white, sub-point
  size), off-page text, and zero-width, bidi and Unicode tag characters (tag
  characters are decoded and checked for what they spell). Policies are `flag`,
  `redact` (the default) and `discard`, with a severity `threshold`. Each
  finding reports its location; excerpts are opt-in (`excerpts: true`) and are
  for human audit logs only.
- **`Agent::pageCount()`**, for hosts. It throws `RefusedException` on a bad file.
- **Hostile-input limits** (`FinePrint\Limits`): file size, object count
  (judged on what an xref declares, before allocating), nesting depth,
  per-stream and total decoded bytes, decompression ratio, operators per page,
  form depth, page count, time and memory. A breach is a `RefusedException`
  with a stable `reason`, and `Agent::read()` returns it as
  `ok: false`. **Files that need a password are refused** (empty-password files open, below).
- **Repair**: a missing, truncated or lying cross-reference table is rebuilt by
  scanning, including objects inside object streams. Cycles in references, the
  page tree, the outline, form XObjects and `/Prev` chains are survived and
  listed in `warnings`.
- **A PDF writer** (`Agent::toBytes()`, `Agent::write()`), from the same JSON
  document model as `particle-academy/last-word`: headings, paragraphs with
  styled runs (bold, italic, underline, strike, inline code, colour,
  highlight, size, letter spacing, font, links), nested lists, tables (column
  weights, width, alignment, borders, cell padding, fills, column and row
  spans, header rows repeated on every page), code, quotes, PNG and JPEG
  images, page breaks and rules; letter, legal and A4 in either orientation,
  margins, and running headers and footers with `{page}` / `{pages}`. Text is
  wrapped and justified with real font metrics; headings keep with the block
  after them, and no paragraph leaves a single line at the foot or head of a
  page. No `chart` block yet: validation says so and repair drops it.
- **Fonts**: the standard 14 text faces with their metrics (widths derived from
  the metric-compatible Liberation fonts through this package's own TrueType
  reader, and checked against Adobe's AFM values), written with explicit
  `/Widths`. Host-registered **TrueType fonts are embedded as subsets** (Type0,
  Identity-H, with a ToUnicode CMap, so text extracts exactly, astral
  characters included). Fonts come only from the host's `fonts` option, never
  from the document. CFF (`.otf`) fonts and collections are refused for now,
  as are fonts whose licence forbids embedding.
- **Images**: JPEG embedded as-is; PNG passed through untouched with a PNG
  predictor when PDF can carry it, and decoded with zlib only when it cannot
  (alpha, `tRNS` transparency, interlacing), with an 8-bit soft mask. Every
  PNG is checked to inflate to exactly the size its header promises, so a
  decompression bomb is refused instead of copied into the PDF.
- **Output** is PDF 1.7 with a classic cross-reference table, and is
  deterministic: no timestamps, and the file ID is a hash of the body.
- **The writer never emits active content**: no JavaScript, open or additional
  actions, launch actions, forms, widgets, embedded files, rich media or XFA,
  whatever the document contains. Links must be `http`, `https` or `mailto`
  and are checked twice. Model text is written only as hex strings, and
  zero-width, bidi-control, tag and control characters are removed, so the
  writer cannot carry hidden text.
- **`Agent::validate()` and `Agent::validateAndRepair()`**, in last-word's
  shapes; repair removes a disallowed link (keeping its text), raises sub-1pt
  text to 1pt, and drops charts and unreadable images, reporting each change.
- **`Agent::lint()`**: writes the document, reads it back through the guard,
  and reports what will be wrong: unencodable characters, substituted fonts,
  words broken mid-word, overflowing blocks, stranded headings, sparse pages,
  missing page numbers, placeholders, header/footer overlap, and anything the
  read-back guard flags. Findings never quote the document's text.
- **`Agent::fromArray()`**: rows of data as a finished table document (header
  row, numbers right-aligned with one precision per column, optional caption
  and source), as holy-sheet's `fromArray` builds a workbook.
- **`Agent::jsonSchema()`** and **`Agent::guide()`**, the agent guide in
  `agent-guide/building-pdfs.md`, hash-pinned by a test.

- **The agent is told when content was redacted** (owner ruling, 2026-10-21:
  redact stays the default). `Agent::read()` now returns `redacted` (`count`,
  `byRule`, `where`: one location per item cut, never its text), `notice` (the
  same in plain words, naming `Agent::readUnredacted()` as the way to get the
  original) and `unredacted: false`. Under `discard` the notice says the
  content was withheld and does not offer the second step. Nothing was cut:
  both are null.
- **`Agent::readUnredacted()`**: the deliberate second step. The original text
  with every finding attached, `unredacted: true`, and a notice that the text
  is data, never instructions. Always the `flag` policy; a `policy` option is
  ignored. Excerpts stay off unless the host asks. The agent guide explains the
  flow.

- **Encrypted PDFs that open with an empty user password are read** (owner
  ruling, 2026-10-21). The standard security handler in full: RC4 40-128-bit
  and AES-128 (R2-R4), AES-256 (R5, R6), with this package's own RC4 and AES in
  pure PHP (no openssl). The guard and the active-content scan run on the
  decrypted content. `Agent::read()` and `Document::encryption()` report the
  handler, version, revision, algorithm and the permission flags (print,
  modify, copy, annotate, fill forms, accessibility, assemble, high-quality
  print): reported, never enforced. Checked against LibreOffice in both
  directions: it reads our RC4 and AES fixtures, and we read its
  permission-only export with the right flags.

- **A page renderer, first step: vector paths to PNG** (`FinePrint\Render\Renderer`).
  `Renderer::render($pdf, $page, $scale)` returns a `Rendering` (size,
  `png()`, `pixel()`, `rows()`, `skipped`, `complete()`, `warnings`);
  `Renderer::png()` is the one-call form. Pure PHP: an exact-area
  anti-aliasing scanline rasteriser, nonzero and even-odd fills, Bézier
  flattening, strokes with widths, caps, joins, miter limits and dashes (in
  user space, so a skewed or squashed transform draws the elliptical pen PDF
  specifies), DeviceGray / DeviceRGB / DeviceCMYK colour (CMYK by the simple
  formula), ICC-based / CalGray / CalRGB by channel count, `cm`, `q`/`Q`,
  ExtGState line settings, form XObjects, CropBox cut to MediaBox, and
  `/Rotate`. Compared against Chromium (PDFium) on 16 committed goldens: mean
  absolute error 0.001 to 1.19 per channel (of 255), at most 0.64% of pixels
  off by more than 64. A 17th, CMYK, matches in shape but not colour, because
  PDFium converts CMYK through a press profile.
- **Text in embedded TrueType fonts is drawn** (P3b): glyph outlines read
  from the font program in the file (`glyf`, `loca`, `hmtx`; composite
  glyphs by offset, scale, 2x2 matrix and point matching; quadratic curves
  raised to exact cubics; the nonzero fill), for simple TrueType fonts
  (glyphs chosen through the encoding, `/Differences` and the (3,1), (3,0) or
  (1,0) cmap, as ISO 32000-1 9.6.6.4 says) and Type0 fonts over CIDFontType2
  (Identity-H or an embedded CMap with `cidrange`/`cidchar`, and a
  CIDToGIDMap). The full text state: `BT`/`ET`, `Tf`, `Td`/`TD`/`Tm`/`T*`,
  `Tc`, `Tw` (single-byte code 32 only), `Tz`, `TL`, `Ts`, `Tj`/`TJ`/`'`/`"`,
  through the text matrix and the CTM. Render modes 0-3 (fill, stroke, fill
  then stroke, invisible); modes 4-6 paint and report their clip as `clip`,
  mode 7 paints nothing and reports `clip`. Hinting instructions are never
  executed. Compared against Chromium on 6 new goldens (3 fixtures at 1x and
  2x; the DejaVu one is local-only and commits no font bytes); see the plan
  for the numbers and for why the text bounds are wider than the vector ones.
- **Hostile fonts are bounded**: at most 1024 contours, 16384 points
  (composites multiplied out), 128 components and 8 levels of nesting per
  glyph, a composite that contains itself is refused, instructions and
  coordinates that run past the glyph are refused, and every glyph ticks the
  render budget. A bad glyph is skipped and counted (`text-glyph`); a font
  that cannot be read is `text-font-invalid`. Show strings are streamed, so a
  multi-million-glyph `Tj` costs time (refused by `renderTimeSeconds`), never
  memory.
- **What the renderer does NOT draw yet is reported, never hidden**:
  `skipped` lists text it cannot draw (by kind and font name, below), images, inline images, clipping, shadings, patterns,
  transparency (drawn opaque), Separation/DeviceN colour (drawn as grey),
  annotation appearances, optional-content visibility and forms past the depth
  limit, with counts. `complete()` is false when anything is listed.
- **A PNG encoder** (`FinePrint\Render\PngEncoder`) on the bundled zlib: RGB
  and RGBA, all five row filters plus adaptive selection. Unfiltered is the
  default, by measurement: for rendered pages it is about thirty times faster
  for at most a tenth more bytes.
- **Render limits** in `FinePrint\Limits`: `maxRenderPixels` (25,000,000,
  judged before the raster is allocated; refusal reason `too_many_pixels`) and
  `renderTimeSeconds` (30, the render's own clock). `maxOperators` and
  `maxFormDepth` apply to rendering as they do to reading. `Limits::with()`
  copies limits with some values changed.
- `Document::page()` (one page's dictionary, boxes, rotation and resources) and
  `Document::pageContents()`.
- **Standard-14 text that is not embedded is now drawn** (owner ruling
  2026-10-21). Helvetica, Times and Courier in all four styles, and Arial,
  Times New Roman and Courier New (including `ArialMT`, `Arial,Bold`,
  `TimesNewRomanPS-BoldMT` and the like), draw from fonts bundled UNMODIFIED
  in `resources/fonts`: Liberation Sans 2.1.5 (2021-09-30), Tinos 1.340
  (2026-04-27) and Cousine 1.241 (2026-06-09), all SIL OFL 1.1, with their
  licences and a NOTICE giving sources and SHA-256. Chosen by measuring WinAnsi
  advance widths against the standard-14 tables. Tinos and Cousine match
  exactly. Arimo, the newer sans, differs on florin, so the sans is
  Liberation. Without `/Widths`, text advances by the standard 14's own
  widths. `text-unembedded` no longer appears for these faces; Symbol,
  ZapfDingbats and other faces (Arial Narrow, Helvetica Neue, ...) are still
  reported. The package grows by 5.1 MB of font data. Nothing to do as a
  consumer: pages that reported `text-unembedded` for these fonts are now
  `complete()`.

### Changed

- Text rendering snaps each glyph origin down to the next whole device pixel
  at every scale, as chosen by the owner. Aligned origins stay put, horizontal
  positions and advances stay exact, and fill and stroke move together. The
  2x text goldens now enforce tighter bounds set before the first test run.
- **Encrypted files are no longer all refused.** One that needs a real password
  is still refused as `encrypted`, as is one using a non-standard security
  handler; a MALFORMED encryption dictionary is now `corrupt` instead of
  `encrypted`. If you branched on `reason === 'encrypted'` to reject every
  encrypted upload, check `$read['encryption']` instead.
- `Agent::read()`'s result gains four keys at the end: `redacted`,
  `unredacted`, `notice` and `encryption`. Nothing was removed or renamed, so a
  consumer reading the existing keys needs to do nothing.
- `AgentReadGuard::apply()` also returns `acted`: the findings its policy cut
  or withheld on (empty under `flag`).
- `RefusedException::REASONS` gains `too_many_pixels` (rendering only). A
  consumer matching the existing reasons needs to do nothing.
- Inline-image skipping moved into `Parser\InlineImage`, shared by the text
  extractor and the renderer. No behaviour change.
- The renderer's `skipped` kind `text` (never released) is replaced by kinds
  that say WHY text was not drawn: `text-unembedded` (no font program in the
  file, which includes the standard 14 such as Helvetica: no outlines are
  bundled), `text-cff`, `text-type1`, `text-type3`, `text-vertical`,
  `text-cmap`, `text-font-invalid` and `text-glyph`. These entries also carry
  `fonts`, the BaseFont names concerned (PostScript characters only, at most
  16). `Rendering::KINDS` lists them.
- `Writer\Fonts\TrueType::parse()` takes `requireUnicodeCmap` (default true,
  so the writer is unchanged) and gains `cmapGlyph()`, `hasCmap()`,
  `glyphData()` and `leftSideBearing()` for the renderer; format 4 lookups
  are a binary search. `Text\CMap` reads `cidrange`/`cidchar` and streams
  codes (`each()`); `Text\Font` exposes `codes()`, `explicitWidth()`,
  `defaultWidth()`, `encodingUnicode()` and `hasEncoding()`. Text extraction
  is unchanged.

### Fixed

- An empty Flate stream (a blank page's content) was retried as raw deflate,
  which raised a suppressed zlib data-error warning. It now decodes to nothing,
  quietly. Found by the writer's own blank pages.
- The memory-budget test built its fixture from `random_bytes()` and failed
  about one run in fifteen, when the random stream lexed into too few tokens
  for the budget to be sampled. The fixture is now deterministic.

### Security

- Preallocation memory checks include raster/clip row overhead, JPEG working
  planes and PHP's physical memory limit. Two constrained child-process
  regressions now refuse cleanly instead of exiting 255 on a fatal OOM.
  Saved clipping masks are budgeted; nested image masks cannot recurse;
  sampled-function sizes precede decoding; Decode endpoint overflow is reported.
- JPEG frame dimensions and padded component-plane bytes are bounded before
  allocation. Malformed tables, scans, runs, selectors and entropy stop cleanly;
  decoder and inline-boundary loops use the render budget. Invalid filter
  parameter array entries cannot escape as PHP method-call errors.
- Stream predictors validate dimensions before allocation and use packed
  byte rows instead of PHP arrays per byte. TIFF differencing now handles
  every PDF sample depth and predictor loops tick the supplied budget.

- **`Agent::read()` crashed with a FATAL out-of-memory error on one huge show
  string** (measured 2026-10-21, PHP 8.4, `memory_limit` 512M, default
  limits). A ~55 KB Flate-compressed file holding a single `Tj` of 3 million
  codes peaked at 480 MB and died in `Text\Font::decode()`, which built a
  list of every code (about 160-250 bytes a code); a Type0 version died at
  505 MB. With the extractor fixed, the same Type0 file then died at 486 MB
  inside the guard, whose `normalise()` split the page into a list of every
  character and kept a per-character offset map. A fatal error is not a
  refusal: the caller gets no answer at all. Now `Font::decode()` is a
  generator, the extractor ticks the budget per code, and the guard walks the
  text without listing it, keeping its offset map as packed breakpoints. The
  same files now read successfully at 18.5 MB (simple font) and 32.7 MB
  (Type0) peak. Nothing to do as a consumer.
- **A show string could run far past the time budget.** The budget was only
  checked between operators, never inside one show string, and a ToUnicode
  CMap of 100 000 ranges makes each code's lookup 100 000 steps. A 40 000-code
  `Tj` against a 0.5 s budget ran for 276 s and then returned `ok: true`. Each
  code now ticks the budget weighted by its lookup cost (`Font::codeCost()`),
  and the same file is refused as `time_budget` in about 0.5 s.
