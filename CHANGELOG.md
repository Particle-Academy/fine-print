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
  `ok: false`. **Encrypted files are refused.**
- **Repair**: a missing, truncated or lying cross-reference table is rebuilt by
  scanning, including objects inside object streams. Cycles in references, the
  page tree, the outline, form XObjects and `/Prev` chains are survived and
  listed in `warnings`.
