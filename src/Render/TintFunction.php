<?php

declare(strict_types=1);

namespace FinePrint\Render;

use FinePrint\Budget;
use FinePrint\Document;
use FinePrint\Exceptions\RefusedException;
use FinePrint\Objects\PdfDict;
use FinePrint\Objects\PdfStream;

/** Bounded numeric PDF functions: exponential 2, linear sampled 0, stitching 3. */
final class TintFunction
{
    private function __construct(
        private readonly int $type,
        private readonly int $inputs,
        private readonly int $outputs,
        private readonly array $domain,
        private readonly ?array $range,
        private readonly array $options,
        private readonly string $data,
        private readonly Budget $budget,
    ) {}

    public static function load(mixed $value, Document $doc, Budget $budget, int $inputs, int $outputs): ?self
    {
        $nodes = 0;

        return self::parse($value, $doc, $budget, $inputs, $outputs, 0, $nodes);
    }

    private static function parse(mixed $value, Document $doc, Budget $budget, int $inputs, int $outputs, int $depth, int &$nodes): ?self
    {
        $budget->tick();
        if ($depth > 8 || ++$nodes > 256) {
            return null;
        }
        $budget->allocation(4096);
        $value = $doc->resolve($value);
        $dict = $value instanceof PdfStream ? $value->dict : $value;
        if (! $dict instanceof PdfDict || $inputs < 1 || $inputs > 4 || $outputs < 1 || $outputs > 4) {
            return null;
        }
        $get = fn (string $key) => $doc->resolve($dict->get($key));
        $type = $get('FunctionType');
        $domain = self::numbers($get('Domain'), 2 * $inputs, $doc);
        $range = $dict->has('Range') ? self::numbers($get('Range'), 2 * $outputs, $doc) : null;
        if ($domain === null || ($dict->has('Range') && $range === null)) {
            return null;
        }
        foreach ($domain as $i => $number) {
            if ($i % 2 === 1 && ($number <= $domain[$i - 1] || ! is_finite($number - $domain[$i - 1]))) {
                return null;
            }
        }
        if ($range !== null) {
            foreach ($range as $i => $number) {
                if ($i % 2 === 1 && ($number < $range[$i - 1] || ! is_finite($number - $range[$i - 1]))) {
                    return null;
                }
            }
        }
        if ($type === 3 && $inputs === 1) {
            $functions = $get('Functions');
            if (! is_array($functions) || count($functions) < 1 || count($functions) > 32) {
                return null;
            }
            $bounds = self::numbers($get('Bounds'), count($functions) - 1, $doc);
            $encode = self::numbers($get('Encode'), count($functions) * 2, $doc);
            if ($bounds === null || $encode === null) {
                return null;
            }
            $edges = [$domain[0], ...$bounds, $domain[1]];
            for ($i = 1; $i < count($edges); $i++) {
                if ($edges[$i] <= $edges[$i - 1] || ! is_finite($edges[$i] - $edges[$i - 1])
                    || ! is_finite($encode[2 * ($i - 1) + 1] - $encode[2 * ($i - 1)])) {
                    return null;
                }
            }
            $children = [];
            foreach ($functions as $function) {
                $child = self::parse($function, $doc, $budget, 1, $outputs, $depth + 1, $nodes);
                if ($child === null) {
                    return null;
                }
                $children[] = $child;
            }

            return new self(3, 1, $outputs, $domain, $range, [$children, $edges, $encode], '', $budget);
        }
        if ($type === 2 && $inputs === 1) {
            $c0 = self::numbers($get('C0') ?? [0], $outputs, $doc);
            $c1 = self::numbers($get('C1') ?? [1], $outputs, $doc);
            $n = $get('N');
            if ($c0 === null || $c1 === null || (! is_int($n) && ! is_float($n)) || ! is_finite((float) $n) || $n < 0) {
                return null;
            }
            $largest = max(abs($domain[0]), abs($domain[1]));
            if (($domain[0] < 0 && floor((float) $n) !== (float) $n)
                || ($largest > 1 && $n * log($largest) > 700)) {
                return null;
            }
            foreach ($c0 as $i => $v) {
                if (! is_finite($c1[$i] - $v) || ! is_finite($v + pow($largest, $n) * ($c1[$i] - $v))) {
                    return null;
                }
            }

            return new self(2, 1, $outputs, $domain, $range, [$c0, $c1, $n], '', $budget);
        }
        if ($type !== 0 || ! $value instanceof PdfStream || $range === null || ($get('Order') ?? 1) !== 1) {
            return null;
        }
        $sizes = $get('Size');
        $bits = $get('BitsPerSample');
        if (! is_array($sizes) || count($sizes) !== $inputs || ! in_array($bits, [1, 2, 4, 8, 12, 16, 24, 32], true)) {
            return null;
        }
        $count = 1.0;
        $encode = [];
        foreach ($sizes as $i => &$size) {
            $size = $doc->resolve($size);
            if (! is_int($size) || $size < 1) {
                return null;
            }
            $count *= $size;
            array_push($encode, 0.0, (float) ($size - 1));
        }
        unset($size);
        $bytes = ceil($count * $outputs * $bits / 8);
        if (! is_finite($bytes) || $bytes > min($budget->limits->maxStreamBytes, PHP_INT_MAX / 64)) {
            throw new RefusedException('too_large', 'A sampled function exceeds the decoded stream limit.');
        }
        $encode = $dict->has('Encode') ? self::numbers($get('Encode'), $inputs * 2, $doc) : $encode;
        $decode = $dict->has('Decode') ? self::numbers($get('Decode'), $outputs * 2, $doc) : $range;
        if ($encode === null || $decode === null) {
            return null;
        }
        foreach ([$encode, $decode] as $pairs) {
            for ($i = 0; $i < count($pairs); $i += 2) {
                if (! is_finite($pairs[$i + 1] - $pairs[$i])) {
                    return null;
                }
            }
        }
        $data = $doc->streamData($value, $budget);
        if ($data === null || strlen($data) < $bytes) {
            return null;
        }

        return new self(0, $inputs, $outputs, $domain, $range, [$sizes, $bits, $encode, $decode], $data, $budget);
    }

    public function evaluate(array $values): array
    {
        $this->budget->tick($this->type === 0 ? (1 << $this->inputs) * $this->outputs * $this->options[1] : $this->outputs);
        for ($i = 0; $i < $this->inputs; $i++) {
            $values[$i] = max($this->domain[2 * $i], min($this->domain[2 * $i + 1], $values[$i] ?? 0));
        }
        if ($this->type === 3) {
            [$children, $edges, $encode] = $this->options;
            $i = 0;
            while ($i < count($children) - 1 && $values[0] >= $edges[$i + 1]) {
                $this->budget->tick();
                $i++;
            }
            $fraction = ($values[0] - $edges[$i]) / ($edges[$i + 1] - $edges[$i]);
            $out = $children[$i]->evaluate([$encode[2 * $i] + $fraction * ($encode[2 * $i + 1] - $encode[2 * $i])]);
        } elseif ($this->type === 2) {
            [$c0, $c1, $n] = $this->options;
            $power = pow($values[0], $n);
            $out = [];
            for ($i = 0; $i < $this->outputs; $i++) {
                $out[] = $c0[$i] + $power * ($c1[$i] - $c0[$i]);
            }
        } else {
            [$sizes, $bits, $encode, $decode] = $this->options;
            $lo = $fraction = $strides = [];
            $stride = 1;
            for ($i = 0; $i < $this->inputs; $i++) {
                $t = ($values[$i] - $this->domain[2 * $i]) / ($this->domain[2 * $i + 1] - $this->domain[2 * $i]);
                $mapped = max(0, min($sizes[$i] - 1, $encode[2 * $i] + $t * ($encode[2 * $i + 1] - $encode[2 * $i])));
                $lo[$i] = (int) floor($mapped);
                $fraction[$i] = $mapped - $lo[$i];
                $strides[$i] = $stride;
                $stride *= $sizes[$i];
            }
            $out = array_fill(0, $this->outputs, 0.0);
            $max = (1 << $bits) - 1;
            for ($corner = 0; $corner < (1 << $this->inputs); $corner++) {
                $weight = 1.0;
                $index = 0;
                for ($i = 0; $i < $this->inputs; $i++) {
                    $high = ($corner >> $i) & 1;
                    $weight *= $high ? $fraction[$i] : 1 - $fraction[$i];
                    $index += min($sizes[$i] - 1, $lo[$i] + $high) * $strides[$i];
                }
                if ($weight === 0.0) {
                    continue;
                }
                for ($i = 0; $i < $this->outputs; $i++) {
                    $bit = ($index * $this->outputs + $i) * $bits;
                    $sample = 0;
                    for ($j = 0; $j < $bits; $j++, $bit++) {
                        $sample = ($sample << 1) | ((ord($this->data[intdiv($bit, 8)]) >> (7 - ($bit & 7))) & 1);
                    }
                    $out[$i] += $weight * $sample / $max;
                }
            }
            foreach ($out as $i => &$value) {
                $value = $decode[2 * $i] + $value * ($decode[2 * $i + 1] - $decode[2 * $i]);
            }
            unset($value);
        }
        if ($this->range !== null) {
            foreach ($out as $i => &$v) {
                $v = max($this->range[2 * $i], min($this->range[2 * $i + 1], $v));
            }
            unset($v);
        }

        return $out;
    }

    private static function numbers(mixed $value, int $count, Document $doc): ?array
    {
        if (! is_array($value) || count($value) !== $count) {
            return null;
        }
        $out = [];
        foreach ($value as $v) {
            $v = $doc->resolve($v);
            if ((! is_int($v) && ! is_float($v)) || ! is_finite((float) $v)) {
                return null;
            }
            $out[] = (float) $v;
        }

        return $out;
    }
}
