# Using particle-academy/fine-print — guide for agents

How an agent installs and uses this package in a consumer app. What the package
is, is in [README.md](./README.md). Notes for working ON this repo's code are in
[CONTRIBUTING.md](./CONTRIBUTING.md).

## Install

```bash
composer require particle-academy/fine-print
```

- **PHP:** `^8.4` with the bundled `zlib`, `mbstring`, `ctype` and `json`.
  No other runtime dependency.
- The Laravel 13 page-image adapter (`FinePrint\Laravel\PageImages`) is
  optional; it needs `illuminate/cache` and `illuminate/routing`, which the
  package only suggests.
- A Python port of the reader is `fancy-fine-print` on PyPI.

## Find it on the Fancy MCP server

The catalog server is `https://ui.particle.academy/mcp` (streamable HTTP, no API
key; MCP id `fancy-ui`). The package is the registry entry `fine-print`; find it
with `search-backend-packages` (stack `php`), then `install-instructions`.

## The agent guide

`agent-guide/building-pdfs.md` ships in the package and is returned by
`Agent::guide()`. Give it to the model together with `Agent::jsonSchema()`
when an agent is going to write PDFs; it is the step-by-step recipe (plan,
build the JSON, validate, lint, write).

## API essentials

`FinePrint\Agent` is the agent surface; every method is static.

- **Read:** `Agent::read($pathOrBytes, $options)` returns `ok`, `pageCount`,
  `pages` (`text` in reading order, guarded), `guard` findings,
  `activeContent`, `notice`, `redacted`, ... Options: `policy`
  (`redact` default, `flag`, `discard`), `threshold` (`low`, `medium`
  default, `high`), `excerpts`, `limits`, and `words => true` for per-word boxes.
- `Agent::readUnredacted(...)` is the deliberate second call for the original
  text; `Agent::pageCount($bytes)` throws `RefusedException` on a bad file.
- **Write:** the document model is last-word's (`title`, `page`, `blocks`).
  `Agent::validate($doc)`, `validateAndRepair($doc)`, `lint($doc)`, then
  `write($doc, $path)` or `toBytes($doc)`. `Agent::fromArray($rows)` builds a
  table document from rows. Output is deterministic.
- **Exchange:** `extract($pdf, $options)` returns portable content;
  `fromPortable`, `toPortable`, a `portable` block; `export($pdf, $target,
  $options, $policy)` with an `ExportPolicy`; `describe($doc)`.
- **Render:** pages to PNG in pure PHP, and authenticated, watermarked page
  images through the Laravel adapter (see the README).

## Letterhead on a PDF

The supported way to put a `.docx` letterhead on a fine-print PDF goes through
the shared document model. Install both packages (`particle-academy/last-word`
reads the `.docx`, this package writes the PDF), then:

1. **Read the letterhead:** `$read = LastWord\Agent::read($docx);`
2. **Merge `$read['page']` into your document** (`size`, `margins`, `header`,
   `footer`, `firstHeader`, `firstFooter`). The document's own values win, the
   same rule as last-word's `templateParts`: `size`, `orientation` and each
   margin override that one value, and a document `header` / `footer` replaces
   the letterhead's of that kind, its first-page variant included.

   ```php
   $page = $read['page'] ?? [];
   $own = $doc['page'] ?? [];
   foreach (['header', 'footer'] as $kind) {
       if (isset($own[$kind])) {
           unset($page[$kind], $page['first'.ucfirst($kind)]);
       }
   }
   $margins = array_replace($page['margins'] ?? [], $own['margins'] ?? []);
   $doc['page'] = array_replace($page, $own);
   if ($margins !== []) {
       $doc['page']['margins'] = $margins;
   }
   ```
3. **Write it:** `$r = FinePrint\Agent::validateAndRepair($doc);` then, when
   `$r['ok']`, `FinePrint\Agent::toBytes($r['schema'])`.

The band images pass through byte for byte: a JPEG is embedded as it is, and a
PNG's image data goes into the PDF unchanged. `LetterheadRouteTest` guards this
route against the released last-word.

- **The portable exchange does NOT carry the letterhead.**
  `LastWord\Agent::toPortable` / `FinePrint\Agent::fromPortable` move content
  only: no page size, no margins and no header or footer bands. Use the route
  above.
- **Check `$read['unrepresented']`.** A Word-authored letterhead may hold VML
  shapes, EMF, SVG or linked pictures, or text boxes; the reader drops those
  from a band and lists them there. A floating (anchored) logo is read as an
  inline image, so its position is not kept.

## Traps

- **Check `$read['ok']` first.** A refused file returns
  `['ok' => false, 'refused' => ['reason' => ..., 'message' => ...]]`, not an
  exception.
- **Never give an agent `FinePrint\Document`.** It is the raw host view:
  hidden text included, nothing redacted. Only `Agent` is safe to expose.
- **`excerpts => true` is for a human audit log only.** An excerpt of an
  injection is still an injection; never hand that report to an agent.
- **Use `discard` for untrusted uploads.** The guard is a detector, not a
  proof: its patterns are English, and hidden-text detection assumes a white
  page.
- Expose `readUnredacted` as a tool only where showing unredacted text is
  acceptable.
- **Exports are off by default.** Only a host-built `ExportPolicy` enables a
  target; nothing an agent passes can.
- **Fonts come from the host.** A document names a family; it can never supply
  a file or a path. Register TrueType fonts through the `fonts` option of
  `write`. `.otf` (CFF) and collections are not supported yet.
- Links must be `http`, `https` or `mailto`. The writer never emits active
  content; read `warnings` from `write` for anything it could not honour.
- Multi-column pages read across the columns.
- Encrypted files open with the empty password only.
