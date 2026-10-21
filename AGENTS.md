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

P1 is the reader plus the security layer; P2 is the writer (`src/Writer`);
P3a is the renderer core (`src/Render`: vector paths to PNG); P3b draws text
from embedded TrueType fonts (`src/Render/Glyphs`). CFF / Type 1 / Type 3
fonts, images, clipping and transparency in the renderer (P3b-d) and Laravel
serving (P4) are later. See `.ai/plans/fine-print.md` in the envelope.

| layer | where | trusts its input? |
|---|---|---|
| `Agent` | `src/Agent.php` | no. This is the only surface an agent may be given |
| `AgentReadGuard` | `src/Security/` | no |
| `ActiveContentScanner` | `src/Security/` | no |
| `Document` | `src/Document.php` | no. It is the HOST's raw view, unguarded |
| parser, filters, fonts | `src/Parser`, `src/Filters`, `src/Text` | no |
| writer: model, layout, PDF syntax | `src/Writer` | no. The document comes from an agent, which may have copied it from a hostile file |
| TrueType reader, PNG/JPEG readers | `src/Writer/Fonts`, `src/Writer/Images` | no. Host fonts and image data URLs may be user uploads |
| renderer: interpreter, rasteriser, stroker, PNG | `src/Render` | no. It runs every operator of a hostile page |

## The renderer (P3a)

`Renderer::render()` → page box (CropBox ∩ MediaBox, `/Rotate`) and the base
matrix → `Interpreter` (content operators, `GraphicsState`, `Path` in user
space, `ColourSpace`) → fills through `Rasterizer`, strokes through `Stroker`
(user space, so a non-uniform CTM gives an elliptical pen) then `Rasterizer`
→ `Raster` (one RGB byte string per row) → `PngEncoder`.

Renderer invariants:

- **Judge the pixel count before allocating.** `maxRenderPixels` is checked on
  floats, before any int cast or `Raster`. A MediaBox of 10^7 points must cost
  nothing.
- **Every operator counts**, page and forms together, against `maxOperators`;
  forms carry a visited stack and the `maxFormDepth` cap; the `q` stack is
  capped (overflow is counted, not stored). The render has its OWN `Budget`
  (`renderTimeSeconds`), separate from the document's.
- **Everything not drawn is reported in `skipped`.** Adding a kind means adding
  it to `Rendering::KINDS` and the README. Never draw less silently: a page
  image missing content that claims to be complete is the failure this exists
  to prevent.
- **Work per edge is bounded by the canvas, not by coordinates.** The
  rasteriser clips rows to the canvas and projects anything left of it onto
  x = 0; Bézier flattening is capped at `Bezier::MAX_SEGMENTS`; a dash pattern
  that would make more than `Stroker::MAX_DASHES` dashes is drawn solid. Every
  operand passes `Interpreter::numbers()`, which turns INF/NAN into 0.
- **Stroke polygons are all emitted with one orientation.** Nonzero filling
  then unions them; mixed orientations would cancel where a join overlaps a
  segment.

## Text (P3b)

`Tf` → `Glyphs\RenderFont::load()` (once per font object per render; at most
256 fonts / 64 MB of programs, then the cache starts over) → per show
operator, `RenderFont::glyphs()` yields [gid, width, is-space] → each glyph's
`Glyphs\TrueTypeGlyphs::outline()` (font units, cached) is mapped glyph →
text (1/unitsPerEm) → `[Tfs*Th 0 0 Tfs 0 Ts] x Tm` → CTM, culled against
the canvas, filled nonzero glyph by glyph, stroked through the P3a stroker.

- **Reuse, don't duplicate.** The font program is read by P2's
  `Writer\Fonts\TrueType` (`parse(..., requireUnicodeCmap: false)`,
  `cmapGlyph()`, `glyphData()`, `leftSideBearing()`); widths, `/Differences`
  and code splitting come from P1's `Text\Font` and `Text\CMap` (which now
  also read `cidrange`/`cidchar`).
- **Glyphs stream.** `glyphs()` and `Font::codes()` are generators. A show
  string can hold millions of codes; building them into a list once took a
  3-million-glyph `Tj` past PHP's memory limit (a fatal error). Never turn
  them back into arrays. Every glyph ticks the render budget.
- **Hinting is never executed.** Glyph instructions are skipped by length;
  `fpgm`, `prep` and `cvt ` are not read. Outlines are drawn unhinted.
- **Glyph limits** (`TrueTypeGlyphs::MAX_*`): 1024 contours, 16384 points
  (composites multiplied out), 128 components, nesting 8, plus a self-
  containment check. A glyph over a limit or malformed is a `FontException`,
  counted as `text-glyph`, remembered (refused once, not re-parsed), and the
  rest of the text is drawn.
- **Placement follows FreeType**: the outline moves by `lsb - xMin` (phantom
  point pp1) at the top level only. Measured in Chromium before it was
  written.
- **Font names in reports** are BaseFont stripped to `[A-Za-z0-9+_.-]`, at
  most 63 characters, at most 16 per kind (`RenderFont::cleanName()`).
- **Non-embedded standard-14 text draws from bundled fonts**
  (`Glyphs\StandardFonts`, owner ruling 2026-10-21): Helvetica/Arial from
  Liberation Sans 2.1.5, Times/Times New Roman from Tinos, Courier/Courier New
  from Cousine, in `resources/fonts`, UNMODIFIED, their SHA-256 pinned in
  `NOTICE` by a test. Chosen by measured WinAnsi widths against
  `StandardMetrics` (Arimo differs on florin, so the sans is Liberation).
  Only simple fonts (Type1/TrueType/MMType1) whose name is one of those faces
  plus style words are substituted; Narrow, Black, Neue, Symbol,
  ZapfDingbats and anything else stay `text-unembedded`. Without `/Widths`,
  advances come from the standard widths by character, not from the bundled
  font. Never subset, rename or edit a bundled file; replacing one means a
  new NOTICE entry and hash.

## Encryption (owner ruling 2026-10-21: open empty-password files)

`Security\StandardSecurity` authenticates with the EMPTY user password only
and derives the key; `Crypto\Rc4` and `Crypto\Aes` are ours (no openssl).
`Document::secure()` runs right after the xref is read and BEFORE any object is
resolved, then clears the cache, so nothing is ever cached still encrypted.
`parseAt()` decrypts every object read from the file except the `/Encrypt`
dictionary itself; cross-reference streams, objects inside object streams
(their container was decrypted), a signature's `/Contents` and, under
`EncryptMetadata false`, the metadata stream stay as they are.

- Needs a password, another handler, or an unknown V/R/CFM: `encrypted`.
  Malformed (types, lengths, key length, a missing crypt filter): `corrupt`.
- Never try any password but "". Never put the key, `/O`, `/U` or anything
  derived from them in a report or a warning.
- Permissions are reported (`Document::encryption()`), never enforced here.
- A damaged AES string or stream decrypts to empty with a warning.

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
- **Show strings and page text stream; nothing lists them.** `Font::decode()`
  and `Font::codes()` are generators, the extractor ticks the budget per code
  weighted by `Font::codeCost()` (ToUnicode lookups are linear in ranges),
  and `AgentReadGuard::normalise()` walks the text byte by byte with a packed
  breakpoint map. A list per code or per character once took a 55 KB file
  past PHP's memory limit in both places (a fatal error). `Font::split()` and
  `CMap::split()` still build lists and must not be put back on a hostile
  path.
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
- **Chromium's PDF viewer is an exact oracle only at some geometries**
  (measured 2026-10-21, Chromium 156). At zoom 150 with device scale 1, one
  point is exactly 2 px, but only for some page sizes: 200x150 pt comes out
  0.34% taller and 0.06 px off the pixel grid. At zoom 75 a 200 pt page is
  drawn 201 px wide. Golden fixtures use measured-exact sizes, scale-1 goldens
  are the zoom-150 render averaged 2x2, and `tests/oracle/crop.php` refuses an
  off-grid page. Calibrate with anti-aliased TRIANGLES, never bars.
- **PDFium snaps axis-aligned rectangle fills to whole pixels** (no
  anti-aliasing on their edges, by its own rounding). We anti-alias them by
  exact coverage. That difference is most of the remaining error in the
  `rects` goldens. Do not "fix" it by copying PDFium's rounding without
  deciding it is wanted.
- **PDFium spaces zero-length dashes slightly apart** (`[0 12]` gives dots
  every 12.1 pt), so a long dotted line drifts against Chromium. Ours are at
  exact multiples, which is what the spec says.
- **Chromium is a coarse oracle for TEXT** (measured 2026-10-21, Chromium
  156). It snaps every glyph origin to a whole device pixel; its glyphs bleed
  past their outlines (about 0.3 px right and top at its 2x, ~0 left and
  bottom); and FreeType runs TrueType hinting, which moves a hinted font's
  stems and baselines by up to half a pixel. Against it a 2x text golden
  cannot see a one-pixel error. So: the `glyphs` golden keeps glyph origins
  on half points (whole pixels at the oracle's 2x) and uses an unhinted
  font, and exact placement is pinned by `TextRenderTest`'s pixel tests. Do
  not "fix" our text to snap, bleed or hint to please Chromium without
  deciding it is wanted. Turning off PDFium's Skia renderer changes nothing.
- **A test file must run on its own.** Shared text helpers live in
  `tests/Support/text-helpers.php` (loaded by `tests/Pest.php`). When they
  lived in `TextRenderTest.php`, `TextHostileTest` passed in the full suite
  and errored alone, and three sabotage runs read that error as "red".

## Tests

Pest, test-first. **Every PDF a test reads is written by `tests/Support`**
(`PdfBuilder`, `Encoders`) **or by the writer itself**, and every font and
image by `FontBuilder` / `ImageBuilder`. No downloaded files and no
third-party producer. The bundled `resources/fonts` are read as the package
reads them (`StandardFontsTest`, the `std14` golden). The other exception is the `local` group, which reads
`C:/Windows/Fonts/DejaVuSans.ttf` when it exists and skips otherwise.
Each security behaviour has a test that was red before it existed, and
`tests/Unit/HostileInputTest.php` holds one fixture per attack. `composer test`
runs the suite.

Renderer goldens: `tests/golden/<name>.pdf` (written by
`tests/Support/RenderFixtures`) and `<name>@<scale>x.png` (Chromium's
rendering of it). `GoldenTest` rebuilds each PDF and fails if it no longer
matches the committed one, then compares with `tests/Support/ImageDiff` (mean
absolute error and share of pixels off by more than 64, a 2 px frame
excluded); `tests/Support/PngReader` is a PNG decoder written separately from
our encoder. Numbers and diff images land in `tests/.out/`. To regenerate,
run the three scripts in `tests/oracle/` (Chromium + playwright-core are a
TEST ORACLE on the developer's machine, never a dependency); `ONLY=a,b`
limits `build.php` to some fixtures so the others' goldens are untouched.
**The oracle never shows a window.** `chromium.cjs` launches only through
Playwright, headless, with `--no-first-run`, Playwright's throwaway profile
and a `finally` close, and refuses to start otherwise. Never run `chrome.exe`
directly, not even `--version`: on Windows that opens a visible window with
first-run onboarding on the owner's desktop.

Text goldens: `glyphs` (written by `RenderFixtures`, snap-immune, strict
bounds) and `p2-text` (written by the P2 writer) embed
`FontBuilder::outlined()`, a font drawn in our own code. `p2-dejavu` is in
the `local` group: it is built at test time from the machine's
`DejaVuSans.ttf`, so **no font byte is committed**, only Chromium's PNG and
the PDF's SHA-256 (`p2-dejavu.pdf.sha256`); a different font version skips.
The text bounds were revised after the first run; `GoldenTest` says why, and
its "wrong on purpose" controls must keep failing.
