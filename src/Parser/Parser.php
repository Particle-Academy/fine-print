<?php

declare(strict_types=1);

namespace FinePrint\Parser;

use Closure;
use FinePrint\Budget;
use FinePrint\Exceptions\RefusedException;
use FinePrint\Limits;
use FinePrint\Objects\PdfDict;
use FinePrint\Objects\PdfName;
use FinePrint\Objects\PdfRef;
use FinePrint\Objects\PdfStream;
use FinePrint\Objects\PdfString;

/**
 * Lexer + parser for PDF object syntax (ISO 32000-1 7.2, 7.3), over a byte
 * string with a cursor.
 *
 * Used three ways: on the file (indirect objects, with references), on a
 * decoded object stream (values, with references) and on a content stream or
 * CMap (values and operator keywords, references OFF — `0 0 R` is not a
 * reference in a content stream, and reading it as one would swallow operands).
 */
final class Parser
{
    private const WS = " \t\r\n\f\0";

    private const DELIM_OR_WS = " \t\r\n\f\0()<>[]{}/%";

    private int $pos = 0;

    private readonly int $len;

    private readonly int $maxDepth;

    /**
     * @param  ?Closure(PdfRef): mixed  $resolve  resolves a stream's indirect /Length
     */
    public function __construct(
        private readonly string $data,
        private readonly bool $refs = true,
        private readonly ?Closure $resolve = null,
        private readonly ?Budget $budget = null,
    ) {
        $this->len = strlen($data);
        $this->maxDepth = $budget?->limits->maxDepth ?? (new Limits())->maxDepth;
    }

    /** Parse one value from a source string. For tests and small inputs. */
    public static function value(string $src): mixed
    {
        return (new self($src))->parseValue();
    }

    public function position(): int
    {
        return $this->pos;
    }

    public function seek(int $pos): void
    {
        $this->pos = max(0, min($pos, $this->len));
    }

    public function data(): string
    {
        return $this->data;
    }

    public function atEnd(): bool
    {
        $this->skipWhitespace();

        return $this->pos >= $this->len;
    }

    public function skipWhitespace(): void
    {
        while ($this->pos < $this->len) {
            $this->pos += strspn($this->data, self::WS, $this->pos);
            if ($this->pos < $this->len && $this->data[$this->pos] === '%') {
                $this->pos += strcspn($this->data, "\r\n", $this->pos);

                continue;
            }

            break;
        }
    }

    /**
     * The next value, or a {@see Keyword} for a bare word.
     *
     * @throws PdfSyntaxException
     */
    public function parseValue(int $depth = 0): mixed
    {
        if ($depth > $this->maxDepth) {
            throw new RefusedException('too_deep', 'Arrays and dictionaries nest deeper than the limit of '.$this->maxDepth.'.');
        }
        $this->budget?->tick();
        $this->skipWhitespace();
        if ($this->pos >= $this->len) {
            throw new PdfSyntaxException('Unexpected end of data');
        }

        $c = $this->data[$this->pos];

        return match (true) {
            $c === '/' => $this->parseName(),
            $c === '(' => $this->parseLiteral(),
            $c === '<' => ($this->data[$this->pos + 1] ?? '') === '<'
                ? $this->parseDict($depth)
                : $this->parseHex(),
            $c === '[' => $this->parseArray($depth),
            $c === ']' || $c === '>' || $c === ')' || $c === '{' || $c === '}' => $this->stray($c),
            default => $this->parseWord(),
        };
    }

    /**
     * An indirect object at the cursor: `num gen obj <value> [stream ... endstream] endobj`.
     *
     * @return array{num: int, gen: int, value: mixed}
     *
     * @throws PdfSyntaxException
     */
    public function parseIndirect(): array
    {
        $num = $this->parseValue();
        $gen = $this->parseValue();
        $kw = $this->parseValue();
        if (! is_int($num) || ! is_int($gen) || ! $kw instanceof Keyword || $kw->word !== 'obj') {
            throw new PdfSyntaxException('Not an indirect object header');
        }

        $value = $this->parseValue();
        if ($value instanceof Keyword) {
            // `N 0 obj endobj` — an empty object is null.
            return ['num' => $num, 'gen' => $gen, 'value' => null];
        }

        $this->skipWhitespace();
        if ($value instanceof PdfDict && substr_compare($this->data, 'stream', $this->pos, 6) === 0) {
            $this->pos += 6;
            $value = $this->readStreamBody($value, $num);
        }

        return ['num' => $num, 'gen' => $gen, 'value' => $value];
    }

    private function readStreamBody(PdfDict $dict, int $num): PdfStream
    {
        // The keyword is followed by CRLF or LF (a lone CR is tolerated).
        if (($this->data[$this->pos] ?? '') === "\r") {
            $this->pos++;
        }
        if (($this->data[$this->pos] ?? '') === "\n") {
            $this->pos++;
        }
        $start = $this->pos;

        $length = $dict->get('Length');
        if ($length instanceof PdfRef && $this->resolve !== null) {
            $length = ($this->resolve)($length);
        }

        if (is_int($length) && $length >= 0 && $start + $length <= $this->len) {
            $after = $start + $length;
            $after += strspn($this->data, self::WS, $after);
            if (substr_compare($this->data, 'endstream', $after, 9) === 0) {
                $this->pos = $after + 9;

                return new PdfStream($dict, substr($this->data, $start, $length), $num);
            }
        }

        // /Length is missing or lies: find the end marker instead.
        $end = strpos($this->data, 'endstream', $start);
        if ($end === false) {
            $end = strpos($this->data, 'endobj', $start);
            $end = $end === false ? $this->len : $end;
            $this->pos = $end;
        } else {
            $this->pos = $end + 9;
        }
        $raw = substr($this->data, $start, $end - $start);
        if (str_ends_with($raw, "\r\n")) {
            $raw = substr($raw, 0, -2);
        } elseif (str_ends_with($raw, "\n") || str_ends_with($raw, "\r")) {
            $raw = substr($raw, 0, -1);
        }

        return new PdfStream($dict, $raw, $num);
    }

    private function stray(string $c): never
    {
        $this->pos++;

        throw new PdfSyntaxException('Unexpected '.$c);
    }

    private function parseName(): PdfName
    {
        $this->pos++;
        $n = strcspn($this->data, self::DELIM_OR_WS, $this->pos);
        $raw = substr($this->data, $this->pos, $n);
        $this->pos += $n;

        if (str_contains($raw, '#')) {
            $raw = (string) preg_replace_callback(
                '/#([0-9A-Fa-f]{2})/',
                static fn (array $m): string => chr((int) hexdec($m[1])),
                $raw,
            );
        }

        return new PdfName($raw);
    }

    private function parseLiteral(): PdfString
    {
        $this->pos++;
        $depth = 1;
        $out = '';
        while ($this->pos < $this->len) {
            $n = strcspn($this->data, "()\\\r", $this->pos);
            $out .= substr($this->data, $this->pos, $n);
            $this->pos += $n;
            if ($this->pos >= $this->len) {
                break;
            }
            $c = $this->data[$this->pos++];
            if ($c === '(') {
                $depth++;
                $out .= '(';
            } elseif ($c === ')') {
                if (--$depth === 0) {
                    return new PdfString($out);
                }
                $out .= ')';
            } elseif ($c === "\r") {
                // An unescaped end-of-line is a single LF.
                if (($this->data[$this->pos] ?? '') === "\n") {
                    $this->pos++;
                }
                $out .= "\n";
            } else {
                $out .= $this->escape();
            }
        }

        return new PdfString($out);
    }

    private function escape(): string
    {
        $e = $this->data[$this->pos] ?? '';
        $this->pos++;

        switch ($e) {
            case 'n': return "\n";
            case 'r': return "\r";
            case 't': return "\t";
            case 'b': return "\x08";
            case 'f': return "\f";
            case '(': return '(';
            case ')': return ')';
            case '\\': return '\\';
            case "\r":
                if (($this->data[$this->pos] ?? '') === "\n") {
                    $this->pos++;
                }

                return '';
            case "\n":
                return '';
        }

        if ($e >= '0' && $e <= '7') {
            $oct = $e;
            for ($i = 0; $i < 2; $i++) {
                $d = $this->data[$this->pos] ?? '';
                if ($d < '0' || $d > '7') {
                    break;
                }
                $oct .= $d;
                $this->pos++;
            }

            return chr(octdec($oct) & 0xFF);
        }

        // An unknown escape: the backslash is ignored.
        return $e;
    }

    private function parseHex(): PdfString
    {
        $this->pos++;
        $end = strpos($this->data, '>', $this->pos);
        $end = $end === false ? $this->len : $end;
        $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', substr($this->data, $this->pos, $end - $this->pos));
        $this->pos = min($this->len, $end + 1);
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }

        return new PdfString((string) hex2bin($hex), true);
    }

    /** @return list<mixed> */
    private function parseArray(int $depth): array
    {
        $this->pos++;
        $items = [];
        while (true) {
            $this->skipWhitespace();
            if ($this->pos >= $this->len) {
                throw new PdfSyntaxException('Unterminated array');
            }
            if ($this->data[$this->pos] === ']') {
                $this->pos++;

                return $items;
            }
            $v = $this->parseValue($depth + 1);
            if (! $v instanceof Keyword) {
                $items[] = $v;
            }
        }
    }

    private function parseDict(int $depth): PdfDict
    {
        $this->pos += 2;
        $entries = [];
        while (true) {
            $this->skipWhitespace();
            if ($this->pos >= $this->len) {
                throw new PdfSyntaxException('Unterminated dictionary');
            }
            if ($this->data[$this->pos] === '>' && ($this->data[$this->pos + 1] ?? '') === '>') {
                $this->pos += 2;

                return new PdfDict($entries);
            }
            $key = $this->parseValue($depth + 1);
            if (! $key instanceof PdfName) {
                if ($key instanceof Keyword && in_array($key->word, ['endobj', 'stream', 'obj'], true)) {
                    throw new PdfSyntaxException('Unterminated dictionary');
                }

                continue;
            }
            $this->skipWhitespace();
            if ($this->pos < $this->len && $this->data[$this->pos] === '>' && ($this->data[$this->pos + 1] ?? '') === '>') {
                $entries[$key->value] = null;

                continue;
            }
            $value = $this->parseValue($depth + 1);
            $entries[$key->value] = $value instanceof Keyword ? null : $value;
        }
    }

    private function parseWord(): mixed
    {
        $n = strcspn($this->data, self::DELIM_OR_WS, $this->pos);
        if ($n === 0) {
            // A lone delimiter we do not otherwise handle.
            $this->pos++;

            return new Keyword($this->data[$this->pos - 1]);
        }
        $word = substr($this->data, $this->pos, $n);
        $this->pos += $n;

        $c = $word[0];
        if (($c >= '0' && $c <= '9') || $c === '+' || $c === '-' || $c === '.') {
            $number = self::number($word);
            if ($number === null) {
                return new Keyword($word);
            }
            if (is_int($number) && $number >= 0 && $this->refs) {
                return $this->maybeRef($number);
            }

            return $number;
        }

        return match ($word) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => new Keyword($word),
        };
    }

    private static function number(string $word): int|float|null
    {
        if (preg_match('/^[+-]?\d+$/', $word)) {
            // Beyond PHP's int range the cast saturates; read it as a float instead.
            return strlen(ltrim($word, '+-0')) > 18 ? (float) $word : (int) $word;
        }
        if (preg_match('/^[+-]?(\d+\.\d*|\.\d+)$/', $word)) {
            return (float) $word;
        }
        // Producers write `--5` and `1.2.3`; treat a numeric-looking mistake as 0
        // rather than letting it derail the surrounding structure.
        if (preg_match('/^[+\-.\d]+$/', $word)) {
            return 0;
        }

        return null;
    }

    private function maybeRef(int $num): int|PdfRef
    {
        $save = $this->pos;
        $this->skipWhitespace();
        $n = strspn($this->data, '0123456789', $this->pos);
        if ($n > 0 && $n <= 10) {
            $gen = (int) substr($this->data, $this->pos, $n);
            $after = $this->pos + $n;
            if (strspn($this->data, self::WS, $after) > 0) {
                $p = $after + strspn($this->data, self::WS, $after);
                if (($this->data[$p] ?? '') === 'R') {
                    $next = $this->data[$p + 1] ?? ' ';
                    if (strspn($next, self::DELIM_OR_WS) === 1) {
                        $this->pos = $p + 1;

                        return new PdfRef($num, $gen);
                    }
                }
            }
        }
        $this->pos = $save;

        return $num;
    }
}
