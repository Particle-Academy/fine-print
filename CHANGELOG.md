# Changelog

All notable changes to `particle-academy/fine-print` are recorded here, in
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

**Pre-1.0: breaking changes may land in a MINOR release.** Every one is marked
BREAKING and says what a consumer must do.

## [Unreleased]

### Added

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
  codecs (DCT, JPX, CCITT, JBIG2) are kept as opaque bytes and never decoded.
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
