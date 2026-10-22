<?php

declare(strict_types=1);

namespace FinePrint\Render\Images;

use FinePrint\Budget;
use FinePrint\Exceptions\ImageException;
use FinePrint\Exceptions\RefusedException;

/**
 * Our baseline sequential Huffman JPEG decoder. No external codec or code.
 * T.81 Annexes B/C/F and A.3.3: https://www.w3.org/Graphics/JPEG/itu-t81.pdf
 * Progressive, arithmetic and lossless processes are explicitly unsupported.
 */
final class JpegDecoder
{
    private const ZIGZAG = [0, 1, 8, 16, 9, 2, 3, 10, 17, 24, 32, 25, 18, 11, 4, 5,
        12, 19, 26, 33, 40, 48, 41, 34, 27, 20, 13, 6, 7, 14, 21, 28, 35, 42, 49, 56,
        57, 50, 43, 36, 29, 22, 15, 23, 30, 37, 44, 51, 58, 59, 52, 45, 38, 31, 39,
        46, 53, 60, 61, 54, 47, 55, 62, 63];

    private int $pos = 2;
    private int $width = 0;
    private int $height = 0;
    private int $maxH = 1;
    private int $maxV = 1;
    private int $restartInterval = 0;
    private ?int $adobe = null;
    private array $components = [];
    private array $quant = [];
    private array $huffman = [];
    private int $byte = 0;
    private int $bits = 0;
    private bool $allocated = false;

    private function __construct(private readonly string $data, private readonly Budget $budget) {}

    /** @return array{width: int, height: int, components: int, data: string} */
    public static function decode(string $data, Budget $budget, ?int $transform = null): array
    {
        if (! str_starts_with($data, "\xFF\xD8")) {
            throw new ImageException('Not a JPEG image.');
        }

        return (new self($data, $budget))->run($transform);
    }

    private function run(?int $transform): array
    {
        $ended = false;
        while ($this->pos < strlen($this->data)) {
            $this->budget->tick();
            $marker = $this->marker();
            if ($marker === 0xD9) {
                $ended = true;
                break;
            }
            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                throw new ImageException('Unexpected JPEG marker.');
            }
            $length = $this->word();
            if ($length < 2 || $length - 2 > strlen($this->data) - $this->pos) {
                throw new ImageException('Truncated JPEG segment.');
            }
            $body = substr($this->data, $this->pos, $length - 2);
            $this->pos += $length - 2;
            switch ($marker) {
                case 0xC0:
                    $this->frame($body);
                    break;
                case 0xDB:
                    $this->quantisation($body);
                    break;
                case 0xC4:
                    $this->tables($body);
                    break;
                case 0xDD:
                    if (strlen($body) !== 2) {
                        throw new ImageException('Invalid JPEG restart interval.');
                    }
                    $this->restartInterval = self::u16($body, 0);
                    break;
                case 0xEE:
                    if (strlen($body) >= 12 && str_starts_with($body, 'Adobe')) {
                        $this->adobe = ord($body[11]);
                    }
                    break;
                case 0xDA:
                    $this->scan($body);
                    break;
                default:
                    if ($marker >= 0xC0 && $marker <= 0xCF) {
                        throw new ImageException('Unsupported JPEG coding process.');
                    }
                    // APPn and COM carry metadata only. Other structural
                    // extensions (DNL, DHP, EXP, JPGn) are not interpreted.
                    if (! ($marker >= 0xE0 && $marker <= 0xEF) && $marker !== 0xFE) {
                        throw new ImageException('Unsupported JPEG marker.');
                    }
            }
        }
        if (! $ended || $this->components === []) {
            throw new ImageException('JPEG ended without a complete frame.');
        }
        foreach ($this->components as $component) {
            if (! $component['done']) {
                throw new ImageException('JPEG component is missing a scan.');
            }
        }

        return $this->samples($transform);
    }

    private function frame(string $body): void
    {
        if ($this->components !== [] || strlen($body) < 6 || ord($body[0]) !== 8) {
            throw new ImageException('Invalid baseline JPEG frame.');
        }
        $this->height = self::u16($body, 1);
        $this->width = self::u16($body, 3);
        SampledImage::dimensions($this->width, $this->height, $this->budget);
        $n = ord($body[5]);
        if (! in_array($n, [1, 3, 4], true) || strlen($body) !== 6 + 3 * $n) {
            throw new ImageException('Unsupported JPEG component count.');
        }
        $units = 0;
        for ($i = 0; $i < $n; $i++) {
            $id = ord($body[6 + 3 * $i]);
            $hv = ord($body[7 + 3 * $i]);
            $h = $hv >> 4;
            $v = $hv & 15;
            $q = ord($body[8 + 3 * $i]);
            if ($h < 1 || $h > 4 || $v < 1 || $v > 4 || $q > 3 || isset($this->components[$id])) {
                throw new ImageException('Invalid JPEG sampling factors.');
            }
            $units += $h * $v;
            $this->maxH = max($this->maxH, $h);
            $this->maxV = max($this->maxV, $v);
            $this->components[$id] = ['h' => $h, 'v' => $v, 'q' => $q, 'dc' => 0, 'done' => false];
        }
        if ($units > 10) {
            throw new ImageException('Too many JPEG blocks in an MCU.');
        }
    }

    private function quantisation(string $body): void
    {
        for ($p = 0, $len = strlen($body); $p < $len;) {
            $this->budget->tick();
            $info = ord($body[$p++]);
            if (($info >> 4) !== 0 || ($info & 15) > 3 || $len - $p < 64) {
                throw new ImageException('Invalid baseline JPEG quantisation table.');
            }
            $table = array_fill(0, 64, 0);
            for ($i = 0; $i < 64; $i++) {
                $v = ord($body[$p++]);
                if ($v === 0) {
                    throw new ImageException('Zero JPEG quantisation value.');
                }
                $table[self::ZIGZAG[$i]] = $v;
            }
            $this->quant[$info & 15] = $table;
        }
    }

    private function tables(string $body): void
    {
        for ($p = 0, $len = strlen($body); $p < $len;) {
            $this->budget->tick();
            if ($len - $p < 17) {
                throw new ImageException('Truncated JPEG Huffman table.');
            }
            $info = ord($body[$p++]);
            $class = $info >> 4;
            $id = $info & 15;
            if ($class > 1 || $id > 3) {
                throw new ImageException('Invalid JPEG Huffman table selector.');
            }
            $counts = [];
            $total = 0;
            for ($i = 1; $i <= 16; $i++) {
                $counts[$i] = ord($body[$p++]);
                $total += $counts[$i];
            }
            if ($total === 0 || $total > 256 || $total > $len - $p) {
                throw new ImageException('Invalid JPEG Huffman symbol count.');
            }
            $table = [];
            $code = 0;
            for ($size = 1; $size <= 16; $size++) {
                $n = $counts[$size];
                if ($code + $n > (1 << $size) || ($n > 0 && $code + $n === (1 << $size))) {
                    throw new ImageException('Oversubscribed JPEG Huffman table.');
                }
                for ($i = 0; $i < $n; $i++) {
                    $symbol = ord($body[$p++]);
                    if (($class === 0 && $symbol > 11)
                        || ($class === 1 && (($symbol & 15) > 10 || (($symbol & 15) === 0 && $symbol !== 0 && $symbol !== 0xF0)))) {
                        throw new ImageException('Invalid baseline JPEG Huffman symbol.');
                    }
                    $table[(1 << $size) | $code++] = $symbol;
                }
                $code <<= 1;
            }
            $this->huffman[$class][$id] = $table;
        }
    }

    private function allocate(): void
    {
        if ($this->allocated) {
            return;
        }
        $cols = intdiv($this->width + 8 * $this->maxH - 1, 8 * $this->maxH);
        $rows = intdiv($this->height + 8 * $this->maxV - 1, 8 * $this->maxV);
        $total = 0.0;
        foreach ($this->components as &$c) {
            $c['stride'] = $cols * $c['h'] * 8;
            $c['rows'] = $rows * $c['v'] * 8;
            $total += (float) $c['stride'] * $c['rows'];
        }
        unset($c);
        if ($total > $this->budget->limits->maxStreamBytes) {
            throw new RefusedException('too_large', 'JPEG component planes exceed the decoded stream limit.');
        }
        if ($total + (float) $this->width * $this->height * count($this->components) > $this->budget->limits->memoryBytes) {
            throw new RefusedException('memory_budget', 'JPEG component planes exceed the memory budget.');
        }
        $this->budget->decoded((int) $total);
        foreach ($this->components as &$c) {
            $this->budget->check();
            $c['plane'] = str_repeat("\0", $c['stride'] * $c['rows']);
        }
        unset($c);
        $this->allocated = true;
    }

    private function scan(string $body): void
    {
        if ($this->components === [] || $body === '') {
            throw new ImageException('JPEG scan has no frame.');
        }
        $n = ord($body[0]);
        if ($n < 1 || $n > count($this->components) || strlen($body) !== 1 + 2 * $n + 3
            || substr($body, -3) !== "\0\x3F\0") {
            throw new ImageException('Unsupported JPEG scan.');
        }
        $scan = [];
        for ($i = 0; $i < $n; $i++) {
            $id = ord($body[1 + 2 * $i]);
            $selector = ord($body[2 + 2 * $i]);
            if (! isset($this->components[$id]) || $this->components[$id]['done'] || isset($scan[$id])
                || ! isset($this->quant[$this->components[$id]['q']])
                || ! isset($this->huffman[0][$selector >> 4], $this->huffman[1][$selector & 15])) {
                throw new ImageException('JPEG scan has missing or invalid tables/components.');
            }
            $scan[$id] = [$selector >> 4, $selector & 15];
            $this->components[$id]['dc'] = 0;
        }
        $this->allocate();
        $one = $this->components[array_key_first($scan)];
        $cols = $n === 1 ? (int) ceil($this->width * $one['h'] / ($this->maxH * 8))
            : (int) ceil($this->width / ($this->maxH * 8));
        $rows = $n === 1 ? (int) ceil($this->height * $one['v'] / ($this->maxV * 8))
            : (int) ceil($this->height / ($this->maxV * 8));
        $this->bits = 0;
        $mcu = $restart = 0;
        for ($my = 0; $my < $rows; $my++) {
            for ($mx = 0; $mx < $cols; $mx++) {
                foreach ($scan as $id => [$dc, $ac]) {
                    $c = &$this->components[$id];
                    $hs = $n === 1 ? 1 : $c['h'];
                    $vs = $n === 1 ? 1 : $c['v'];
                    for ($vy = 0; $vy < $vs; $vy++) {
                        for ($hx = 0; $hx < $hs; $hx++) {
                            $block = $this->block($id, $dc, $ac);
                            for ($row = 0; $row < 8; $row++) {
                                $offset = (($my * $vs + $vy) * 8 + $row) * $c['stride'] + ($mx * $hs + $hx) * 8;
                                for ($x = 0; $x < 8; $x++) {
                                    $c['plane'][$offset + $x] = $block[$row * 8 + $x];
                                }
                            }
                        }
                    }
                    unset($c);
                }
                if (++$mcu < $cols * $rows && $this->restartInterval > 0 && $mcu % $this->restartInterval === 0) {
                    $this->bits = 0;
                    if ($this->marker() !== 0xD0 + ($restart++ % 8)) {
                        throw new ImageException('Missing or out-of-order JPEG restart marker.');
                    }
                    foreach ($scan as $id => $_) {
                        $this->components[$id]['dc'] = 0;
                    }
                }
            }
        }
        $this->bits = 0;
        foreach ($scan as $id => $_) {
            $this->components[$id]['done'] = true;
        }
    }

    private function block(int $id, int $dcTable, int $acTable): string
    {
        $this->budget->tick(128);
        $c = &$this->components[$id];
        $q = $this->quant[$c['q']];
        $category = $this->symbol($this->huffman[0][$dcTable]);
        $c['dc'] += $this->signed($category);
        if (abs($c['dc']) > 2047) {
            throw new ImageException('Invalid JPEG DC prediction.');
        }
        $coeff = array_fill(0, 64, 0);
        $coeff[0] = $c['dc'] * $q[0];
        $nonzero = false;
        for ($k = 1; $k < 64;) {
            $rs = $this->symbol($this->huffman[1][$acTable]);
            if ($rs === 0) {
                break;
            }
            if ($rs === 0xF0) {
                $k += 16;
                if ($k > 64) {
                    throw new ImageException('JPEG zero run exceeds its block.');
                }
                continue;
            }
            $k += $rs >> 4;
            if ($k >= 64) {
                throw new ImageException('JPEG AC coefficient exceeds its block.');
            }
            $at = self::ZIGZAG[$k++];
            $coeff[$at] = $this->signed($rs & 15) * $q[$at];
            $nonzero = true;
        }
        if (! $nonzero) {
            return str_repeat(chr(self::clamp($coeff[0] / 8 + 128)), 64);
        }

        return self::idct($coeff);
    }

    private function symbol(array $table): int
    {
        $code = 0;
        for ($n = 1; $n <= 16; $n++) {
            $code = ($code << 1) | $this->bit();
            $key = (1 << $n) | $code;
            if (isset($table[$key])) {
                return $table[$key];
            }
        }

        throw new ImageException('Invalid JPEG Huffman code.');
    }

    private function signed(int $n): int
    {
        $value = 0;
        for ($i = 0; $i < $n; $i++) {
            $value = ($value << 1) | $this->bit();
        }

        return $n > 0 && $value < (1 << ($n - 1)) ? $value - (1 << $n) + 1 : $value;
    }

    private function bit(): int
    {
        if ($this->bits === 0) {
            if (! isset($this->data[$this->pos])) {
                throw new ImageException('Truncated JPEG entropy data.');
            }
            $this->byte = ord($this->data[$this->pos++]);
            if ($this->byte === 255) {
                if (! isset($this->data[$this->pos]) || ord($this->data[$this->pos++]) !== 0) {
                    throw new ImageException('Unexpected marker inside JPEG entropy data.');
                }
            }
            $this->bits = 8;
        }

        return ($this->byte >> --$this->bits) & 1;
    }

    private static function idct(array $coeff): string
    {
        static $basis = null;
        if ($basis === null) {
            $basis = [];
            for ($x = 0; $x < 8; $x++) {
                for ($u = 0; $u < 8; $u++) {
                    $basis[$x][$u] = ($u === 0 ? 1 / sqrt(2) : 1) * cos((2 * $x + 1) * $u * M_PI / 16);
                }
            }
        }
        $temp = [];
        for ($v = 0; $v < 8; $v++) {
            for ($x = 0; $x < 8; $x++) {
                $sum = 0.0;
                for ($u = 0; $u < 8; $u++) {
                    $sum += $coeff[$v * 8 + $u] * $basis[$x][$u];
                }
                $temp[$v][$x] = $sum;
            }
        }
        $out = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $sum = 0.0;
                for ($v = 0; $v < 8; $v++) {
                    $sum += $temp[$v][$x] * $basis[$y][$v];
                }
                $out .= chr(self::clamp($sum / 4 + 128));
            }
        }

        return $out;
    }

    private function samples(?int $transform): array
    {
        $components = array_values($this->components);
        $n = count($components);
        // ISO 32000-1 table 13: Adobe APP14 overrides DecodeParms.
        $transform = $this->adobe ?? $transform ?? ($n === 3 ? 1 : 0);
        if (! in_array($transform, [0, 1, 2], true) || ($transform === 2 && $n !== 4)) {
            throw new ImageException('Unsupported JPEG colour transform.');
        }
        $out = '';
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                $this->budget->tick();
                $values = [];
                foreach ($components as $c) {
                    $sx = intdiv($x * $c['h'], $this->maxH);
                    $sy = intdiv($y * $c['v'], $this->maxV);
                    $values[] = ord($c['plane'][$sy * $c['stride'] + $sx]);
                }
                if ($transform !== 0 && $n >= 3) {
                    [$yy, $cb, $cr] = $values;
                    $cb -= 128;
                    $cr -= 128;
                    $r = self::clamp($yy + 1.402 * $cr);
                    $g = self::clamp($yy - 0.344136 * $cb - 0.714136 * $cr);
                    $b = self::clamp($yy + 1.772 * $cb);
                    $values = $n === 4 ? [255 - $r, 255 - $g, 255 - $b, $values[3]] : [$r, $g, $b];
                }
                foreach ($values as $v) {
                    $out .= chr($v);
                }
            }
        }

        return ['width' => $this->width, 'height' => $this->height, 'components' => $n, 'data' => $out];
    }

    private function marker(): int
    {
        if (! isset($this->data[$this->pos]) || ord($this->data[$this->pos++]) !== 255) {
            throw new ImageException('Missing JPEG marker.');
        }
        do {
            $this->budget->tick();
            if (! isset($this->data[$this->pos])) {
                throw new ImageException('Truncated JPEG marker.');
            }
            $code = ord($this->data[$this->pos++]);
        } while ($code === 255);
        if ($code === 0) {
            throw new ImageException('Unexpected stuffed JPEG byte.');
        }

        return $code;
    }

    private function word(): int
    {
        if (strlen($this->data) - $this->pos < 2) {
            throw new ImageException('Truncated JPEG length.');
        }
        $value = self::u16($this->data, $this->pos);
        $this->pos += 2;

        return $value;
    }

    private static function u16(string $data, int $at): int
    {
        return (ord($data[$at]) << 8) | ord($data[$at + 1]);
    }

    private static function clamp(float|int $value): int
    {
        return (int) max(0, min(255, round($value)));
    }
}
