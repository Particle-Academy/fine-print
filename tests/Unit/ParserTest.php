<?php

declare(strict_types=1);

use FinePrint\Objects\PdfDict;
use FinePrint\Objects\PdfName;
use FinePrint\Objects\PdfRef;
use FinePrint\Objects\PdfString;
use FinePrint\Parser\Parser;

/*
 * The object syntax, ISO 32000-1 7.3. Every value type, plus the escapes that
 * matter for security: a name's #xx escape is decoded BEFORE anything compares
 * it, so /J#61vaScript and /JavaScript are the same name to every reader.
 */

it('parses the scalar types', function () {
    expect(Parser::value('null'))->toBeNull()
        ->and(Parser::value('true'))->toBeTrue()
        ->and(Parser::value('false'))->toBeFalse()
        ->and(Parser::value('42'))->toBe(42)
        ->and(Parser::value('-17'))->toBe(-17)
        ->and(Parser::value('+3'))->toBe(3)
        ->and(Parser::value('3.5'))->toBe(3.5)
        ->and(Parser::value('-.25'))->toBe(-0.25)
        ->and(Parser::value('4.'))->toBe(4.0);
});

it('decodes name escapes before anything compares them', function () {
    $n = Parser::value('/J#61vaScript');

    expect($n)->toBeInstanceOf(PdfName::class)
        ->and($n->value)->toBe('JavaScript')
        ->and(Parser::value('/A#20B')->value)->toBe('A B');
});

it('decodes literal string escapes, octal and balanced parentheses', function () {
    $s = Parser::value('(a\\(b\\)c (nested) \\101\\102 tab\\t\\\\ end)');

    expect($s)->toBeInstanceOf(PdfString::class)
        ->and($s->bytes)->toBe("a(b)c (nested) AB tab\t\\ end");
});

it('joins a literal string across an escaped line break', function () {
    expect(Parser::value("(one\\\ntwo)")->bytes)->toBe('onetwo');
});

it('decodes hex strings, padding an odd final digit', function () {
    $s = Parser::value('<48 65 6C6C 6F7>');

    expect($s->bytes)->toBe("Hello\x70")
        ->and($s->hex)->toBeTrue();
});

it('parses arrays, dictionaries and references', function () {
    $d = Parser::value('<< /Type /Page /Kids [1 0 R 2 0 R] /N 3 /Nested << /A [1 [2 (x)]] >> >>');

    expect($d)->toBeInstanceOf(PdfDict::class)
        ->and($d->name('Type'))->toBe('Page')
        ->and($d->get('Kids')[0])->toEqual(new PdfRef(1, 0))
        ->and($d->get('N'))->toBe(3)
        ->and($d->get('Nested')->get('A')[1][1]->bytes)->toBe('x');
});

it('does not read two numbers as a reference without R', function () {
    expect(Parser::value('[1 0 2 0 R 7]'))->toEqual([1, 0, new PdfRef(2, 0), 7]);
});

it('skips comments', function () {
    expect(Parser::value("[1 % a comment\n 2]"))->toBe([1, 2]);
});
