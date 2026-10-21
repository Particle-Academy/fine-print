<?php

declare(strict_types=1);

namespace FinePrint\Parser;

/**
 * Skips an inline image (`BI <dict pairs> ID <binary> EI`) in a content
 * stream, for every interpreter that does not draw it.
 *
 * The binary can contain anything, including the bytes "EI", so the end is
 * "EI" between whitespace (or a delimiter / end of data) — the rule viewers
 * use. Call it with the parser just past `BI`.
 */
final class InlineImage
{
    public static function skip(Parser $p): void
    {
        while (true) {
            try {
                $v = $p->parseValue();
            } catch (PdfSyntaxException) {
                return;
            }
            if ($v instanceof Keyword && $v->word === 'ID') {
                break;
            }
            if ($p->atEnd()) {
                return;
            }
        }
        $data = $p->data();
        $pos = $p->position() + 1;
        $len = strlen($data);
        while (($i = strpos($data, 'EI', $pos)) !== false) {
            $before = $data[$i - 1] ?? ' ';
            $after = $data[$i + 2] ?? ' ';
            if (strspn($before, " \t\r\n\f\0") === 1 && ($i + 2 >= $len || strspn($after, " \t\r\n\f\0/[<(") === 1)) {
                $p->seek($i + 2);

                return;
            }
            $pos = $i + 2;
        }
        $p->seek($len);
    }
}
