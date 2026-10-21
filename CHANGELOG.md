# Changelog

All notable changes to `particle-academy/fine-print` are recorded here, in
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

**Pre-1.0: breaking changes may land in a MINOR release.** Every one is marked
BREAKING and says what a consumer must do.

## [Unreleased]

### Added

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

### Changed

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

### Fixed

- An empty Flate stream (a blank page's content) was retried as raw deflate,
  which raised a suppressed zlib data-error warning. It now decodes to nothing,
  quietly. Found by the writer's own blank pages.
- The memory-budget test built its fixture from `random_bytes()` and failed
  about one run in fifteen, when the random stream lexed into too few tokens
  for the budget to be sampled. The fixture is now deterministic.
