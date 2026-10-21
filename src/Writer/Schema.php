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

    /** Boolean run flags (all optional). */
    public const RUN_FLAGS = ['bold', 'italic', 'underline', 'strike', 'code', 'smallCaps'];

    public const MAX_HEADING_LEVEL = 6;

    /** Smallest text size the writer draws; anything smaller reads as hidden. */
    public const MIN_TEXT_SIZE = 1;

    public const MAX_TEXT_SIZE = 400;

    /** The only link schemes a PDF from this writer can carry. */
    public const LINK_PATTERN = '#^(https?://|mailto:)[\x21-\x7E]+$#';

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
        $blockRef = ['$ref' => '#/$defs/block'];

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
