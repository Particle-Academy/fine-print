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
        $bandSlot = ['type' => 'string', 'description' => 'Text. `{page}` is the current page number and `{pages}` the page count: "Page {page} of {pages}".'];
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
                    'language' => ['type' => 'string'],
                    'text' => ['type' => 'string'],
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
                    'alt' => ['type' => 'string'],
                ],
            ],
            'pageBreak' => ['type' => 'object', 'required' => ['type'], 'properties' => ['type' => ['const' => 'pageBreak']]],
            'hr' => ['type' => 'object', 'required' => ['type'], 'properties' => ['type' => ['const' => 'hr']]],
        ];

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'FinePrint Document',
            'description' => 'A document: an optional title plus a flat list of blocks. Written to PDF by particle-academy/fine-print. The same model as particle-academy/last-word (.docx), without the chart block.',
            'type' => 'object',
            'required' => ['blocks'],
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'The document title, stored as PDF metadata (not drawn).'],
                'blocks' => ['type' => 'array', 'items' => $blockRef],
                'page' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'description' => 'Page geometry. One-inch margins by default.',
                    'properties' => [
                        'size' => ['enum' => array_keys(self::PAGE_SIZES)],
                        'orientation' => ['enum' => ['portrait', 'landscape']],
                        'margins' => ['$ref' => '#/$defs/boxSides'],
                        'header' => ['$ref' => '#/$defs/band', 'description' => 'A running header on every page.'],
                        'footer' => ['$ref' => '#/$defs/band', 'description' => 'A running footer on every page: where a multi-page document carries its page numbers.'],
                        'background' => $hex + ['description' => 'Page colour, painted full-bleed on every page under everything else, e.g. "#FDFAF3". White (none) by default.'],
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
                    ],
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
                'band' => $band,
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
     * Margins in points, one inch where unset.
     *
     * @param  array<string, mixed>  $doc
     * @return array{top: float, right: float, bottom: float, left: float}
     */
    public static function margins(array $doc): array
    {
        $m = is_array($doc['page']['margins'] ?? null) ? $doc['page']['margins'] : [];
        $out = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $v = $m[$side] ?? null;
            $out[$side] = (is_int($v) || is_float($v)) && $v >= 0 ? (float) $v : 72.0;
        }

        return $out;
    }
}
