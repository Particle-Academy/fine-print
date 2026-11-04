<?php

declare(strict_types=1);

namespace FinePrint\Writer;

/**
 * The document model: last-word's, so the JSON an agent writes for a .docx
 * writes a PDF too. Same blocks, same run flags, same page bands.
 *
 * Where PDF differs, the model is stricter rather than different:
 *   - no `chart` block yet (validation says so; repair drops it with a note)
 *   - links must be http, https or mailto: a PDF link is an action, and every
 *     other scheme is a way to make one do something else
 *   - text under 1pt is refused, because a reader (ours included) flags it as
 *     hidden text
 */
final class Schema
{
    /** @var list<string> */
    public const BLOCK_TYPES = ['heading', 'paragraph', 'list', 'table', 'code', 'quote', 'image', 'pageBreak', 'hr'];

    /** @var list<string> */
    public const ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    /** @var list<string> */
    public const BORDER_STYLES = ['single', 'double', 'dashed', 'dotted', 'none'];

    /** Page sizes in points, portrait. */
    public const PAGE_SIZES = [
        'letter' => [612.0, 792.0],
        'legal' => [612.0, 1008.0],
        'a4' => [595.28, 841.89],
    ];

    /** Points from the page edge to the outside of a page frame, by default. */
    public const PAGE_FRAME_INSET = 24.0;

    /** What a page margin is when the document does not say: one inch a side. */
    public const PAGE_MARGIN_DEFAULT = 72.0;

    /** Points from the page edge to the header, and to the footer, when the document does not say. */
    public const PAGE_BAND_DISTANCE_DEFAULT = 36.0;

    /** The page's running bands, in the order the schema lists them. */
    public const PAGE_BANDS = ['header', 'footer', 'firstHeader', 'firstFooter', 'evenHeader', 'evenFooter'];

    /**
     * Every key of a page object. On a break all of them but `background` are
     * allowed (Word has one page colour per document).
     */
    public const PAGE_KEYS = ['size', 'orientation', 'margins', 'header', 'footer', 'firstHeader', 'firstFooter', 'evenHeader', 'evenFooter', 'background', 'border'];

    /** Block types a band refuses, at any depth. */
    public const BAND_REFUSED_TYPES = ['chart', 'pageBreak', 'portable'];

    /** Block types a footnote refuses, at any depth. */
    public const FOOTNOTE_REFUSED_TYPES = ['chart', 'pageBreak', 'portable'];

    /**
     * The token grammar of a band, last-word's `Schema::FIELD_TOKEN`: lower-case
     * names, exact, and `{date}` / `{time}` may take `:FORMAT`. Anything else in
     * braces is literal text. {@see fields()} says which ones a PDF resolves.
     */
    public const FIELD_TOKEN = '/\{(?:(page|pages|section|sectionpages|author|title|filename)|(date|time)(?::([^{}"\\\\]+))?)\}/';

    /** Boolean run flags (all optional). */
    public const RUN_FLAGS = ['bold', 'italic', 'underline', 'strike', 'code', 'smallCaps'];

    public const MAX_HEADING_LEVEL = 6;

    /** Smallest text size the writer draws; anything smaller reads as hidden. */
    public const MIN_TEXT_SIZE = 1;

    public const MAX_TEXT_SIZE = 400;

    /** The only link schemes a PDF from this writer can carry. */
    public const LINK_PATTERN = '#^(https?://|mailto:)[\x21-\x7E]+$#D';

    /**
     * JSON Schema for the model, for LLM tool registration.
     *
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        $hex = ['type' => 'string', 'pattern' => '^#[0-9A-Fa-f]{6}$'];
        $points = ['type' => 'number', 'description' => 'Points.'];

        $border = [
            'type' => 'object',
            'additionalProperties' => false,
            'description' => 'One border edge. {"style":"none"} REMOVES a border; a zero width is not it.',
            'properties' => [
                'style' => ['enum' => self::BORDER_STYLES],
                'width' => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'Points. Defaults to 0.5 (a hairline).'],
                'color' => $hex,
            ],
        ];
        $borderRef = ['$ref' => '#/$defs/border'];
        $boxBorders = [
            'type' => 'object',
            'additionalProperties' => false,
            'description' => 'Box edges. Anything omitted is left alone rather than reset.',
            'properties' => array_fill_keys(['top', 'right', 'bottom', 'left'], $borderRef),
        ];
        $tableBorders = [
            'type' => 'object',
            'additionalProperties' => false,
            'description' => 'Table edges: the box, plus the two inside directions.',
            'properties' => array_fill_keys(['top', 'right', 'bottom', 'left', 'insideH', 'insideV'], $borderRef),
        ];
        $boxSides = [
            'type' => 'object',
            'additionalProperties' => false,
            'description' => 'Box spacing in points. Anything omitted is left alone.',
            'properties' => array_fill_keys(['top', 'right', 'bottom', 'left'], $points),
        ];
        $bandSlot = ['type' => 'string', 'description' => 'Text with live tokens: {page} and {pages} ("Page {page} of {pages}", the document\'s own numbering and total), {section} (which stretch of pages this is, counting one per pageBreak with a page) and {sectionpages} (that stretch\'s page count), {title} and {author} (meta.author). A PDF has no live date: {date}, {time} and {filename} are written as literal text and reported in warnings. Anything else in braces is literal text.'];
        $band = [
            'type' => 'object',
            'additionalProperties' => false,
            'description' => 'A running header or footer: one line with a left, a centre and a right slot, e.g. {"left": "Q3 Board Memo | Confidential", "right": "Page {page} of {pages}"}. 9pt grey unless size/color say otherwise.',
            'properties' => [
                'left' => $bandSlot,
                'center' => $bandSlot,
                'right' => $bandSlot,
                'size' => ['type' => 'number', 'minimum' => self::MIN_TEXT_SIZE, 'description' => 'Points; 9 by default.'],
                'color' => ['type' => 'string', 'pattern' => '^#[0-9A-Fa-f]{6}$', 'description' => 'Text colour; #6B7280 by default.'],
            ],
        ];
        $bandRef = fn (string $description): array => ['oneOf' => [['$ref' => '#/$defs/band'], ['$ref' => '#/$defs/blocksBand']], 'description' => $description];
        $keepNext = ['type' => 'boolean', 'description' => 'Keep on the same page as the block after it: a chart with its caption, a table with the note under it.'];
        // The page object. A break takes the same keys, less `background`.
        $pageProps = [
            'size' => ['enum' => array_keys(self::PAGE_SIZES)],
            'orientation' => ['enum' => ['portrait', 'landscape']],
            'margins' => ['$ref' => '#/$defs/pageMargins'],
            'header' => $bandRef('A running header on every page: a one-line band, or blocks (a letterhead with a logo). With a firstHeader, every page but the first; with an evenHeader, the odd pages.'),
            'footer' => $bandRef('A running footer on every page: where a multi-page document carries its page numbers. With a firstFooter, every page but the first; with an evenFooter, the odd pages.'),
            'firstHeader' => $bandRef('The header of the FIRST page only, in place of header there. {"blocks": []} leaves that page with no header. On a pageBreak it means the first page after that break. Never inherited by a later break.'),
            'firstFooter' => $bandRef('The footer of the first page only, in place of footer there. {"blocks": []} leaves that page with no footer. On a pageBreak it means the first page after that break. Never inherited by a later break.'),
            'evenHeader' => $bandRef('The header of even pages; header is then the odd pages\'. Follows the page number, which runs through the whole document and does not restart at a break.'),
            'evenFooter' => $bandRef('The footer of even pages; footer is then the odd pages\'. Follows the page number, which runs through the whole document and does not restart at a break.'),
            'background' => $hex + ['description' => 'Page colour, painted full-bleed on every page under everything else, e.g. "#FDFAF3". White (none) by default. One per document: refused on a pageBreak.'],
            'border' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'A frame around every page, measured from the page edge, so it is the same whatever the page holds (a certificate border). e.g. {"style": "double", "width": 6, "color": "#B08D57", "inset": 18}. Margins are separate: keep them wider than inset + width so text clears the frame.',
                'properties' => [
                    'style' => ['enum' => self::BORDER_STYLES, 'description' => 'single by default; double draws two rules with a gap, each a third of the width; none draws nothing.'],
                    'width' => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'Points, the whole frame (both rules and the gap, for double). 0.5 by default.'],
                    'color' => $hex + ['description' => 'Black by default.'],
                    'inset' => ['type' => 'number', 'minimum' => 0, 'description' => 'Points from the page edge to the outside of the frame. '.self::PAGE_FRAME_INSET.' by default.'],
                ],
            ],
        ];

        $paragraphProps = [
            'align' => ['enum' => self::ALIGNMENTS],
            'spaceBefore' => $points,
            'spaceAfter' => ['type' => 'number', 'description' => 'Points. Zero is meaningful: the default puts 8pt under every paragraph.'],
            'lineHeight' => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'A multiple of single spacing.'],
            'indentLeft' => $points,
            'indentRight' => $points,
            'keepNext' => ['type' => 'boolean', 'description' => 'Keep on the same page as the block after it. Headings keep with their next block by default.'],
            'shading' => $hex,
            'borders' => ['$ref' => '#/$defs/boxBorders'],
        ];

        $run = [
            'type' => 'object',
            'required' => ['text'],
            'additionalProperties' => false,
            'properties' => [
                'text' => ['type' => 'string', 'description' => 'The text. A "\n" starts a new line within the paragraph.'],
                'bold' => ['type' => 'boolean'],
                'italic' => ['type' => 'boolean'],
                'underline' => ['type' => 'boolean'],
                'strike' => ['type' => 'boolean'],
                'code' => ['type' => 'boolean', 'description' => 'Inline code: Courier on a light grey ground.'],
                'smallCaps' => ['type' => 'boolean', 'description' => 'Accepted for last-word compatibility; not drawn in a PDF yet (lint says so).'],
                'link' => ['type' => 'string', 'pattern' => '^(https?://|mailto:)', 'description' => 'Hyperlink target: an http://, https:// or mailto: URL. No other scheme is accepted.'],
                'color' => $hex,
                'highlight' => $hex,
                'size' => ['type' => 'number', 'minimum' => self::MIN_TEXT_SIZE, 'maximum' => self::MAX_TEXT_SIZE, 'description' => 'Font size in points, at least 1.'],
                'font' => ['type' => 'string', 'description' => 'Font family: Helvetica, Times or Courier (and their usual aliases, such as Arial, Times New Roman, Courier New), or a family the host registered.'],
                'letterSpacing' => ['type' => 'number', 'description' => 'Tracking in points; may be negative.'],
                'footnote' => ['$ref' => '#/$defs/footnote'],
            ],
        ];
        $runs = ['type' => 'array', 'items' => ['$ref' => '#/$defs/run']];

        $listItem = [
            'type' => 'object',
            'required' => ['runs'],
            'additionalProperties' => false,
            'properties' => [
                'runs' => $runs,
                'children' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/listItem']],
            ] + $paragraphProps,
        ];
        // Wherever a block may go, a portable node may go too. `$defs.block` is
        // the document model; `portable` is an input that validateAndRepair and
        // the writer expand into model blocks, so it is kept beside the model.
        $blockRef = ['$ref' => '#/$defs/blockOrPortable'];

        $blocks = [
            'heading' => [
                'type' => 'object',
                'required' => ['type', 'level', 'runs'],
                'description' => 'A heading is a paragraph and takes the same properties. Bold, 18/16/14/13/12/11pt by level.',
                'properties' => [
                    'type' => ['const' => 'heading'],
                    'level' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_HEADING_LEVEL],
                    'runs' => $runs,
                ] + $paragraphProps,
            ],
            'paragraph' => [
                'type' => 'object',
                'required' => ['type', 'runs'],
                'properties' => ['type' => ['const' => 'paragraph'], 'runs' => $runs] + $paragraphProps,
            ],
            'list' => [
                'type' => 'object',
                'required' => ['type', 'items'],
                'properties' => [
                    'type' => ['const' => 'list'],
                    'ordered' => ['type' => 'boolean'],
                    'items' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/listItem']],
                ],
            ],
            'table' => [
                'type' => 'object',
                'required' => ['type', 'rows'],
                'properties' => [
                    'type' => ['const' => 'table'],
                    'widths' => [
                        'type' => 'array',
                        'items' => ['type' => 'number', 'minimum' => 0],
                        'description' => 'Relative column weights: [30,40,30] and [3,4,3] are the same table.',
                    ],
                    'width' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 100, 'description' => 'Table width as a percentage of the text column.'],
                    'align' => ['enum' => ['left', 'center', 'right']],
                    'borders' => ['$ref' => '#/$defs/tableBorders'],
                    'cellPadding' => ['$ref' => '#/$defs/boxSides'],
                    'keepNext' => $keepNext,
                    'rows' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['cells'],
                            'properties' => [
                                'header' => ['type' => 'boolean', 'description' => 'A header row: bold, filled #E7E7E7 unless the cell says otherwise, and repeated at the top of every page the table continues on.'],
                                'cells' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'required' => ['blocks'],
                                        'properties' => [
                                            'blocks' => ['type' => 'array', 'items' => $blockRef],
                                            'shading' => $hex,
                                            'borders' => ['$ref' => '#/$defs/boxBorders'],
                                            'padding' => ['$ref' => '#/$defs/boxSides'],
                                            'valign' => ['enum' => ['top', 'center', 'bottom']],
                                            'colSpan' => ['type' => 'integer', 'minimum' => 1],
                                            'rowSpan' => [
                                                'type' => 'integer',
                                                'minimum' => 1,
                                                'description' => 'Written HTML-style: the cell appears ONCE, and the rows it covers list only their own remaining cells.',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'code' => [
                'type' => 'object',
                'required' => ['type', 'text'],
                'properties' => [
                    'type' => ['const' => 'code'],
                    'language' => ['type' => 'string', 'description' => 'Not drawn in a PDF yet (warnings say so).'],
                    'text' => ['type' => 'string'],
                    'keepNext' => $keepNext,
                ],
            ],
            'quote' => [
                'type' => 'object',
                'required' => ['type', 'blocks'],
                'properties' => [
                    'type' => ['const' => 'quote'],
                    'blocks' => ['type' => 'array', 'items' => $blockRef],
                ],
            ],
            'image' => [
                'type' => 'object',
                'required' => ['type', 'src'],
                'properties' => [
                    'type' => ['const' => 'image'],
                    'src' => ['type' => 'string', 'pattern' => '^data:image/(png|jpe?g);base64,', 'description' => 'PNG or JPEG data URL.'],
                    'widthPx' => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'Width in px at 96 dpi; capped at the text column.'],
                    'heightPx' => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'Height in px at 96 dpi. With only one of the two, the other keeps the aspect ratio.'],
                    'alt' => ['type' => 'string', 'description' => 'Not carried by a PDF from this writer yet (warnings say so).'],
                    'keepNext' => $keepNext,
                ],
            ],
            'pageBreak' => [
                'type' => 'object',
                'required' => ['type'],
                'properties' => [
                    'type' => ['const' => 'pageBreak'],
                    'page' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'description' => 'Change the page from here on: {"type": "pageBreak", "page": {"orientation": "landscape", "margins": {"left": 28.8, "right": 28.8}}}. Pages after the break keep every setting until another break names a change; portrait does not come back by itself. margins merge side by side, every other key is replaced whole. {} starts a new stretch with nothing changed. Only on a top-level break, not one in a quote or a table cell.',
                        'properties' => array_diff_key($pageProps, ['background' => true]),
                    ],
                ],
            ],
            'hr' => ['type' => 'object', 'required' => ['type'], 'properties' => ['type' => ['const' => 'hr'], 'keepNext' => $keepNext]],
        ];

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'FinePrint Document',
            'description' => 'A document: an optional title plus a flat list of blocks. Written to PDF by particle-academy/fine-print. The same model as particle-academy/last-word (.docx), without the chart block.',
            'type' => 'object',
            'required' => ['blocks'],
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'The document title, stored as PDF metadata and shown by a {title} token in a band. Not drawn in the body; write a heading for that.'],
                'meta' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'description' => 'Document properties beyond the title (which is the top-level `title`).',
                    'properties' => [
                        'author' => ['type' => 'string', 'description' => 'The PDF\'s Author, and what an {author} token in a band shows.'],
                    ],
                ],
                'blocks' => ['type' => 'array', 'items' => $blockRef],
                'page' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'description' => 'Page geometry. One-inch margins by default. Mixed page shapes: a pageBreak with its own `page`.',
                    'properties' => $pageProps,
                ],
                'defaultFont' => ['type' => 'string', 'description' => 'Font every run inherits unless it names its own. Helvetica by default.'],
                'defaultSize' => ['type' => 'number', 'minimum' => self::MIN_TEXT_SIZE, 'maximum' => self::MAX_TEXT_SIZE, 'description' => 'Size in points every run inherits; 11 by default.'],
            ],
            '$defs' => [
                'run' => $run,
                'listItem' => $listItem,
                'border' => $border,
                'boxBorders' => $boxBorders,
                'tableBorders' => $tableBorders,
                'boxSides' => $boxSides,
                'pageMargins' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'description' => 'Page margins in points, 72 (one inch) each side by default, and the header and footer DISTANCES: how far the header sits from the top edge of the page and the footer from the bottom edge, 36 by default. A blocks band taller than the margin leaves room for moves the body down (or up), on the pages that draw it; a one-line band never does.',
                    'properties' => [
                        'top' => $points,
                        'right' => $points,
                        'bottom' => $points,
                        'left' => $points,
                        'header' => ['type' => 'number', 'minimum' => 0, 'description' => 'Points from the top edge of the page to the top of the header. 36 by default.'],
                        'footer' => ['type' => 'number', 'minimum' => 0, 'description' => 'Points from the bottom edge of the page to the bottom of the footer. 36 by default.'],
                    ],
                ],
                'footnote' => [
                    'type' => 'object',
                    'required' => ['blocks'],
                    'additionalProperties' => false,
                    'description' => 'A footnote, carried by a run whose text is "" and which sits where the reference mark goes: {"text": "", "footnote": {"blocks": [{"type": "paragraph", "runs": [{"text": "Audited figures, FY2026."}]}]}}. Marks number 1, 2, 3 in reading order, and each note is set at the foot of the page that holds its mark, at 10pt. The blocks are the body vocabulary less pageBreak, with no footnote inside a footnote. Not in a header or footer.',
                    'properties' => [
                        'blocks' => [
                            'type' => 'array',
                            'items' => ['allOf' => [
                                ['$ref' => '#/$defs/block'],
                                ['not' => ['properties' => ['type' => ['enum' => ['pageBreak']]], 'required' => ['type']]],
                            ]],
                        ],
                    ],
                ],
                'band' => $band,
                'blocksBand' => [
                    'type' => 'object',
                    'required' => ['blocks'],
                    'additionalProperties' => false,
                    'description' => 'A running header or footer that holds blocks, the body\'s own vocabulary less pageBreak at any depth, for what one line cannot: a logo, a table, a line with a rule under it. e.g. {"blocks": [{"type": "image", "src": "data:image/png;base64,...", "widthPx": 96}, {"type": "paragraph", "align": "right", "runs": [{"text": "Page {page}", "size": 8}]}]}. Tokens in run text are live, in a table cell too. Set at the page\'s own width, between its left and right margins. Never mixed with left/center/right/size/color.',
                    'properties' => [
                        'blocks' => [
                            'type' => 'array',
                            'items' => ['allOf' => [
                                ['$ref' => '#/$defs/block'],
                                ['not' => ['properties' => ['type' => ['enum' => ['pageBreak']]], 'required' => ['type']]],
                            ]],
                        ],
                    ],
                ],
                'block' => ['oneOf' => array_values($blocks)],
                'portable' => self::portableSchema(),
                'portableRun' => [
                    'type' => 'object',
                    'required' => ['text'],
                    'additionalProperties' => false,
                    'properties' => [
                        'text' => ['type' => 'string'],
                        'bold' => ['type' => 'boolean'],
                        'italic' => ['type' => 'boolean'],
                        'underline' => ['type' => 'boolean'],
                        'strike' => ['type' => 'boolean'],
                        'code' => ['type' => 'boolean'],
                        'link' => ['type' => 'string', 'pattern' => '^(https?://|mailto:)[!-~]+$', 'maxLength' => 2048],
                    ],
                ],
                'portableListItem' => [
                    'type' => 'object',
                    'required' => ['runs'],
                    'additionalProperties' => false,
                    'properties' => [
                        'runs' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/portableRun']],
                        'children' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/portableListItem']],
                    ],
                ],
                'blockOrPortable' => ['oneOf' => [['$ref' => '#/$defs/block'], ['$ref' => '#/$defs/portable']]],
            ],
        ];
    }

    /**
     * The inline portable node and the `fancyContent: 1` format it carries
     * (fancy-conformance `suites/exchange/SPEC.md`). Every object is closed.
     * The validator enforces what JSON Schema cannot say: the grid is a
     * rectangle, runs spell the cell's display text, image bytes match their
     * mime, and the exchange limits.
     *
     * @return array<string, mixed>
     */
    private static function portableSchema(): array
    {
        $runs = ['type' => 'array', 'items' => ['$ref' => '#/$defs/portableRun']];
        $closed = fn (array $props, array $required = []) => ['type' => 'object', 'additionalProperties' => false, 'properties' => $props] + ($required === [] ? [] : ['required' => $required]);
        $cell = $closed([
            't' => ['enum' => ['string', 'number', 'bool', 'date', 'datetime', 'empty', 'error']],
            'v' => ['description' => 'The typed value: a string, number or boolean; date YYYY-MM-DD; datetime YYYY-MM-DDTHH:MM:SSZ; error a string starting with #. Absent for empty.'],
            'text' => ['type' => 'string', 'description' => 'The display string the source showed, when it differs from the value.'],
            'format' => $closed([
                'kind' => ['enum' => ['number', 'currency', 'percent', 'date', 'datetime', 'text']],
                'decimals' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10],
                'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
            ], ['kind']),
            'formula' => ['type' => 'string', 'minLength' => 1, 'description' => 'Metadata only: never evaluated, never written. The value is written.'],
            'colSpan' => ['type' => 'integer', 'minimum' => 1],
            'rowSpan' => ['type' => 'integer', 'minimum' => 1],
            'align' => ['enum' => ['left', 'center', 'right']],
            'runs' => $runs,
        ], ['t']);
        $listItem = $closed(['runs' => $runs, 'children' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/portableListItem']]], ['runs']);

        $content = $closed([
            'fancyContent' => ['const' => 1],
            'source' => ['type' => 'object', 'description' => 'Where the content came from: {package, kind, version, sourceKind, selector}.'],
            'items' => ['type' => 'array', 'items' => ['oneOf' => [
                $closed([
                    'type' => ['const' => 'table'],
                    'caption' => ['type' => 'string'],
                    'headerRows' => ['type' => 'integer', 'minimum' => 0],
                    'columns' => ['type' => 'array', 'items' => $closed(['width' => ['type' => 'number', 'exclusiveMinimum' => 0], 'align' => ['enum' => ['left', 'center', 'right']]])],
                    'rows' => ['type' => 'array', 'minItems' => 1, 'items' => $closed(['cells' => ['type' => 'array', 'minItems' => 1, 'items' => $cell]], ['cells'])],
                ], ['type', 'rows']),
                $closed([
                    'type' => ['const' => 'text'],
                    'role' => ['enum' => ['heading', 'paragraph']],
                    'level' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 6, 'description' => 'Required for a heading, forbidden otherwise.'],
                    'runs' => $runs,
                ], ['type', 'role', 'runs']),
                $closed([
                    'type' => ['const' => 'list'],
                    'ordered' => ['type' => 'boolean'],
                    'items' => ['type' => 'array', 'minItems' => 1, 'items' => ['$ref' => '#/$defs/portableListItem']],
                ], ['type', 'items']),
                $closed([
                    'type' => ['const' => 'image'],
                    'ref' => ['type' => 'string', 'description' => 'A key of `images`. Never a URL or a path.'],
                    'alt' => ['type' => 'string'],
                    'widthPx' => ['type' => 'number', 'exclusiveMinimum' => 0],
                    'heightPx' => ['type' => 'number', 'exclusiveMinimum' => 0],
                ], ['type', 'ref']),
            ]]],
            'images' => [
                'type' => 'object',
                'propertyNames' => ['pattern' => '^[A-Za-z0-9_-]{1,64}$'],
                'additionalProperties' => $closed(['mime' => ['enum' => ['image/png', 'image/jpeg']], 'data' => ['type' => 'string', 'description' => 'Standard base64 of the PNG or JPEG bytes.']], ['mime', 'data']),
            ],
            'report' => ['type' => 'object', 'description' => 'What the reader lost or approximated: {dropped, approximated, guard}.'],
        ], ['fancyContent', 'items']);

        return [
            'type' => 'object',
            'required' => ['type', 'content'],
            'additionalProperties' => false,
            'description' => 'Portable content (fancyContent 1) from another document package, e.g. a spreadsheet range from holy-sheet extract(). Placed as is: validateAndRepair and the writer expand it into headings, paragraphs, lists, images and tables (numbers right-aligned, header rows marked). Pass it through unchanged rather than retyping it.',
            'properties' => [
                'type' => ['const' => 'portable'],
                'content' => $content,
            ],
        ];
    }

    /** Whether a link target is one this writer will emit. */
    public static function linkAllowed(mixed $link): bool
    {
        return is_string($link) && strlen($link) <= 2048 && preg_match(self::LINK_PATTERN, $link) === 1;
    }

    /**
     * Page width and height in points, after orientation.
     *
     * @param  array<string, mixed>  $doc
     * @return array{0: float, 1: float}
     */
    public static function pageSize(array $doc): array
    {
        $page = is_array($doc['page'] ?? null) ? $doc['page'] : [];
        [$w, $h] = self::PAGE_SIZES[$page['size'] ?? 'letter'] ?? self::PAGE_SIZES['letter'];
        if (($page['orientation'] ?? 'portrait') === 'landscape') {
            [$w, $h] = [$h, $w];
        }

        return [$w, $h];
    }

    /**
     * The page frame with its defaults filled in, or null when there is none
     * (absent, or style "none"). Values that would not validate fall back to
     * their defaults; the validator is what refuses them.
     *
     * @param  array<string, mixed>  $doc
     * @return array{style: string, width: float, color: string, inset: float}|null
     */
    public static function pageFrame(array $doc): ?array
    {
        $b = is_array($doc['page'] ?? null) ? ($doc['page']['border'] ?? null) : null;
        if (! is_array($b)) {
            return null;
        }
        $style = in_array($b['style'] ?? null, self::BORDER_STYLES, true) ? $b['style'] : 'single';
        if ($style === 'none') {
            return null;
        }
        $width = $b['width'] ?? null;
        $inset = $b['inset'] ?? null;
        $color = $b['color'] ?? null;

        return [
            'style' => $style,
            'width' => (is_int($width) || is_float($width)) && $width > 0 && is_finite((float) $width) ? (float) $width : 0.5,
            'color' => is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? $color : '#000000',
            'inset' => (is_int($inset) || is_float($inset)) && $inset >= 0 && is_finite((float) $inset) ? (float) $inset : self::PAGE_FRAME_INSET,
        ];
    }

    /**
     * Margins in points, one inch where unset, plus the header and footer
     * distances, half an inch where unset.
     *
     * @param  array<string, mixed>  $doc
     * @return array{top: float, right: float, bottom: float, left: float, header: float, footer: float}
     */
    public static function margins(array $doc): array
    {
        $m = is_array($doc['page']['margins'] ?? null) ? $doc['page']['margins'] : [];
        $out = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $v = $m[$side] ?? null;
            $out[$side] = (is_int($v) || is_float($v)) && $v >= 0 ? (float) $v : self::PAGE_MARGIN_DEFAULT;
        }
        foreach (['header', 'footer'] as $distance) {
            $v = $m[$distance] ?? null;
            $out[$distance] = (is_int($v) || is_float($v)) && $v >= 0 ? (float) $v : self::PAGE_BAND_DISTANCE_DEFAULT;
        }

        return $out;
    }

    /**
     * The document split at its top-level `pageBreak.page`s: each stretch's
     * blocks (keys kept, so a finding's path still names the document's own
     * index), the document as that stretch sees it, and where each of its bands
     * was set. Never fails on a malformed document; the validator is what
     * refuses one. A document with no such break is one stretch whose page is
     * the document's own, untouched.
     *
     * The rules, the owner's: pages after a break keep every setting until
     * another break names a change. `margins` merge side by side and every
     * other key is replaced whole. `firstHeader` and `firstFooter` are never
     * inherited: at the top level they mean page 1, on a break the first page
     * after it. A null names nothing.
     *
     * @param  array<string, mixed>  $doc
     * @return list<array{doc: array<string, mixed>, blocks: array<int, mixed>, break: ?int, origins: array<string, string>}>
     */
    public static function stretches(array $doc): array
    {
        $blocks = is_array($doc['blocks'] ?? null) ? $doc['blocks'] : [];
        $page = is_array($doc['page'] ?? null) ? $doc['page'] : null;
        $origins = array_combine(self::PAGE_BANDS, array_map(fn (string $k) => "page.{$k}", self::PAGE_BANDS));

        $out = [];
        $current = [];
        $at = null;
        foreach ($blocks as $i => $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'pageBreak' || ! self::isPageObject($block['page'] ?? null)) {
                $current[$i] = $block;

                continue;
            }
            $out[] = self::stretch($doc, $page, $current, $at, $origins);
            $current = [];
            $at = is_int($i) ? $i : null;
            unset($origins['firstHeader'], $origins['firstFooter']);
            $page = self::mergePage($page ?? [], $block['page']);
            foreach (self::PAGE_BANDS as $key) {
                if (isset($block['page'][$key])) {
                    $origins[$key] = "blocks[{$i}].page.{$key}";
                }
            }
        }
        $out[] = self::stretch($doc, $page, $current, $at, $origins);

        return $out;
    }

    /** Whether a break's `page` is a page object: `{}` decodes to `[]`, and a non-empty list is not one. */
    public static function isPageObject(mixed $page): bool
    {
        return is_array($page) && ($page === [] || ! array_is_list($page));
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private static function mergePage(array $page, array $change): array
    {
        unset($page['firstHeader'], $page['firstFooter']);
        foreach ($change as $key => $value) {
            if ($value === null || $key === 'background') {
                continue;
            }
            if ($key === 'margins' && is_array($value) && is_array($page['margins'] ?? null)) {
                $page['margins'] = array_replace($page['margins'], array_filter($value, fn ($v) => $v !== null));

                continue;
            }
            $page[$key] = $value;
        }

        return $page;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>|null  $page
     * @param  array<int, mixed>  $blocks
     * @param  array<string, string>  $origins
     * @return array{doc: array<string, mixed>, blocks: array<int, mixed>, break: ?int, origins: array<string, string>}
     */
    private static function stretch(array $doc, ?array $page, array $blocks, ?int $break, array $origins): array
    {
        $view = $doc;
        unset($view['blocks']);
        if ($page !== null) {
            $view['page'] = $page;
        }

        return ['doc' => $view, 'blocks' => $blocks, 'break' => $break, 'origins' => $origins];
    }

    /**
     * Whether a band says anything: a variant that is present and empty blanks
     * its page rather than falling back, as `bands.ts` has it.
     */
    public static function bandHasContent(mixed $band): bool
    {
        if (! is_array($band)) {
            return false;
        }
        if (is_array($band['blocks'] ?? null)) {
            return $band['blocks'] !== [];
        }
        foreach (['left', 'center', 'right'] as $slot) {
            if (is_string($band[$slot] ?? null) && $band[$slot] !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Every text a token could sit in: a one-line band's slots, a blocks band's
     * run text at any depth.
     *
     * @return list<string>
     */
    public static function bandTexts(mixed $band): array
    {
        if (! is_array($band)) {
            return [];
        }
        $out = [];
        foreach (['left', 'center', 'right'] as $slot) {
            if (is_string($band[$slot] ?? null)) {
                $out[] = $band[$slot];
            }
        }
        self::collectRunText($band['blocks'] ?? null, $out, 0);

        return $out;
    }

    /** @param list<string> $out */
    private static function collectRunText(mixed $node, array &$out, int $depth): void
    {
        if (! is_array($node) || $depth > 64) {
            return;
        }
        foreach ($node as $key => $value) {
            if ($key === 'runs' && is_array($value)) {
                foreach ($value as $run) {
                    if (is_array($run) && is_string($run['text'] ?? null)) {
                        $out[] = $run['text'];
                    }
                }
            } elseif ($key !== 'src' && is_array($value)) {
                self::collectRunText($value, $out, $depth + 1);
            }
        }
    }
}
