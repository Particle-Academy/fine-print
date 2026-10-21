# AGENTS.md — fine-print (PHP)

This file describes **this repository's code**: its API, its invariants, and the
traps in it. Process rules (release lifecycle, publishing, version policy,
backports) live in the envelope's `RULES.md` and must never be copied here. A
copy on a maintenance branch would freeze a rule that has since changed, with
nothing to flag it.

## What this is

A PDF reader and writer in pure PHP, with **no runtime dependency beyond PHP's bundled
`zlib`, `mbstring`, `ctype` and `json`**. That is an owner ruling, not a
preference: no GD, Imagick, Ghostscript, poppler, MuPDF, smalot/pdfparser,
FPDF, TCPDF or dompdf, and no shelling out to a binary. Needing anything else
is an approval request to the owner. It is never a `composer require`.

P1 is the reader plus the security layer; P2 is the writer (`src/Writer`). The
renderer and Laravel serving are later phases. See `.ai/plans/fine-print.md`
in the envelope.

| layer | where | trusts its input? |
|---|---|---|
| `Agent` | `src/Agent.php` | no. This is the only surface an agent may be given |
| `AgentReadGuard` | `src/Security/` | no |
| `ActiveContentScanner` | `src/Security/` | no |
| `Document` | `src/Document.php` | no. It is the HOST's raw view, unguarded |
| parser, filters, fonts | `src/Parser`, `src/Filters`, `src/Text` | no |
| writer: model, layout, PDF syntax | `src/Writer` | no. The document comes from an agent, which may have copied it from a hostile file |
| TrueType reader, PNG/JPEG readers | `src/Writer/Fonts`, `src/Writer/Images` | no. Host fonts and image data URLs may be user uploads |

## The writer

`Validator` / `Repairer` / `Schema` (last-word's model) → `Layout\Composer`
(model to flowables: `ParagraphFlow`, `TableFlow`, `ContainerFlow`,
`ImageFlow`, `RuleFlow`, `PageBreakFlow`) → `Layout\Paginator` (pages of
`Canvas`) → `PdfWriter` (objects, via `Pdf\PdfFile` and `Pdf\Syntax`).
`Linter` writes, then reads the result back through `Agent::read()`.

Writer invariants:

- **Nothing active exists to be emitted.** The writer writes a catalog, a page
  tree, pages, content streams, fonts, images, `/Link` annotations with a
  `/URI` action, and an Info dictionary. Adding any other object type is a
  security change, and `WriterSecurityTest` searches the bytes for every
  forbidden name.
- **Links are checked twice** (`Composer::style()` and `PdfWriter`), against
  `Schema::linkAllowed()`. Keep both.
- **Model text is only ever written as a hex string** (`Syntax::hex/text`).
  Never write a literal `( )` string from model data.
- **Names come from constants or are sanitised.** A TrueType PostScript name is
  stripped to `[A-Za-z0-9_.-]` before it becomes `/BaseFont`.
- **Deterministic.** No dates, no randomness, no hash-ordered iteration of
  anything that reaches the output. The golden test pins
  `tests/fixtures/canonical.json` uncompressed.
- **Images are bounded**: `Image::MAX_PIXELS`, `MAX_BYTES`, and every PNG is
  inflated once at parse to prove it is exactly what IHDR promises.

## The trust boundary is the whole package

Every byte of a PDF is attacker-authored, and every function here is reachable
by a consumer through a file an end user uploaded. **There is no "internal"
code in this repo for trust purposes.** A CMap parser, a predictor and a
`/Differences` loop are all handling hostile input. Judge a change by "what
does a hostile file get out of this", never by which directory it is in.

## Invariants

- **Nothing executes, follows or fetches.** No code path runs a script, opens
  an embedded file, resolves a URI, follows `/GoToR` or `/Launch`, or submits a
  form. `ActiveContentScanner` reports these and must never gain the ability
  to act on one.
- **No report carries attacker text by default.** Active-content findings
  identify scripts by length + SHA-256 and never include the body, launch
  target or URI. Guard findings carry a location and include an excerpt only
  when the host passes `excerpts: true`. A test pins each with
  `json_encode(...)->not->toContain(...)`. Keep it that way: an excerpt of an
  injection handed to an agent IS the injection.
- **Names are compared decoded.** `Parser::parseName()` decodes `#xx` before
  anything sees the name, which is why `/J#61vaScript` is caught. Never
  compare raw name bytes.
- **Every loop a file can lengthen is bounded.** `Budget::tick()` runs in the
  lexer, every decoder checks `Budget::decoding()` per chunk, and the
  interpreter counts operators. Anything that recurses on file structure (page
  tree, outline, forms, references, `/Prev`) carries a visited set or a depth
  cap. Add the bound in the same change as the loop.
- **Refusals are `RefusedException` with a reason from `REASONS`.** Never a
  fatal error, a hang, or a partial result presented as a whole one. Damage
  that is survived instead goes in `Document::warnings()`, and warnings never
  contain file content.
- **Decompression is judged while it runs.** Flate is fed in 4 KB chunks, so a
  bomb is stopped after a few megabytes rather than after it has expanded.
  Do not "simplify" it to `gzuncompress()`.
- **The extractor keeps invisible characters and hidden text.** Stripping them
  early would hide them from the guard. Only the guard removes anything.

## Traps

- **Do not patch a built fixture with `str_replace` unless the replacement is
  the same length.** Every offset after it moves, the xref then lies, and the
  test silently passes through the REPAIR path instead of the one it meant to
  test. Reserve object numbers with `PdfBuilder::reserve()` or use the known
  numbering from `PdfBuilder::textPdf()` (catalog 1, tree 2, font 3, then
  content + page per page).
- **`strrpos($pdf, 'xref')` finds `startxref`.** Use `strpos($pdf, "\nxref\n")`.
- The hidden-text check assumes a white page. White text is only exempt when a
  non-white fill or an image was painted under it first.
- The XMP packet header's `begin="U+FEFF"` is required by the XMP spec and is
  exempt from `invisible-chars`. Without that exemption, `discard` throws away
  nearly every real PDF (found by the LibreOffice oracle).
- **LibreOffice is a poor judge of text positions.** Its PDF import re-flows
  each line with its own spacing: a correctly justified line renders ragged,
  and an underline drawn where layout put the words looks adrift. Chromium's
  viewer (PDFium) renders the same bytes exactly. Do not "fix" positioning to
  please LibreOffice. Its Draw PNG export also ignores `PageRange` and always
  renders page 1.
- **PHP's `range(0, -1)` is `[0, -1]`**, not `[]`. `TableFlow` builds its
  header-row list explicitly for that reason.
- `StandardMetrics` is generated data. Regenerate it with the envelope's
  `sandbox/fine-print/p2/metrics.php` + `emit-metrics.php`, never by hand.

## Tests

Pest, test-first. **Every PDF a test reads is written by `tests/Support`**
(`PdfBuilder`, `Encoders`) **or by the writer itself**, and every font and
image by `FontBuilder` / `ImageBuilder`. No downloaded files and no
third-party producer. The one exception is the `local` group, which reads
`C:/Windows/Fonts/DejaVuSans.ttf` when it exists and skips otherwise.
Each security behaviour has a test that was red before it existed, and
`tests/Unit/HostileInputTest.php` holds one fixture per attack. `composer test`
runs the suite.
