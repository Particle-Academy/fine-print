<?php

declare(strict_types=1);

namespace FinePrint\Tests\Support;

/**
 * A deliberately small PDF WRITER, for fixtures only.
 *
 * Every PDF this suite reads is made here, by our own code. No downloaded
 * files and no third-party producer sit anywhere in the fixture chain, so a
 * fixture can never smuggle in behaviour we did not write down.
 *
 * It is not the P2 writer and must not grow into one. It writes exactly the
 * byte shapes a test asks for — including the broken ones — and nothing more.
 *
 * Objects are numbered from 1 in the order they are added. Bodies are raw PDF
 * syntax, because the point of a fixture is to control the bytes.
 */
final class PdfBuilder
{
    /** @var array<int, array{body: string, stream: ?string}> */
    private array $objects = [];

    private string $header = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";

    /** Reserve the next object number without giving it a body yet. */
    public function reserve(): int
    {
        $num = count($this->objects) + 1;
        $this->objects[$num] = ['body' => 'null', 'stream' => null];

        return $num;
    }

    /** Add (or fill a reserved) plain object. Returns its number. */
    public function object(string $body, ?int $num = null): int
    {
        $num ??= $this->reserve();
        $this->objects[$num] = ['body' => $body, 'stream' => null];

        return $num;
    }

    /**
     * Add a stream object. `$dict` is the dictionary body WITHOUT /Length,
     * which is computed. `$flate` compresses with zlib and adds the filter.
     */
    public function stream(string $data, string $dict = '', bool $flate = false, ?int $num = null): int
    {
        if ($flate) {
            $data = (string) gzcompress($data);
            $dict = '/Filter /FlateDecode '.$dict;
        }
        $num ??= $this->reserve();
        $this->objects[$num] = ['body' => '<< '.$dict.' /Length '.strlen($data).' >>', 'stream' => $data];

        return $num;
    }

    /** Add a stream whose data is already encoded; `$dict` must name its filter. */
    public function rawStream(string $encoded, string $dict, ?int $num = null): int
    {
        $num ??= $this->reserve();
        $this->objects[$num] = ['body' => '<< '.$dict.' /Length '.strlen($encoded).' >>', 'stream' => $encoded];

        return $num;
    }

    public function header(string $header): self
    {
        $this->header = $header;

        return $this;
    }

    /**
     * Serialise.
     *
     * @param  array{
     *     root?: int,
     *     info?: int,
     *     xref?: 'table'|'stream'|'none',
     *     objstm?: bool,
     *     trailer?: string,
     *     predictor?: bool,
     * }  $opts
     */
    public function build(array $opts = []): string
    {
        $root = $opts['root'] ?? 1;
        $mode = $opts['xref'] ?? 'table';
        $trailerExtra = $opts['trailer'] ?? '';
        if (isset($opts['info'])) {
            $trailerExtra .= ' /Info '.$opts['info'].' 0 R';
        }

        $out = $this->header;
        $offsets = [];
        $compressed = [];

        $objects = $this->objects;
        if (! empty($opts['objstm'])) {
            // Every non-stream object goes into ONE object stream.
            $inside = [];
            foreach ($objects as $num => $o) {
                if ($o['stream'] === null) {
                    $inside[$num] = $o['body'];
                }
            }
            $stmNum = count($objects) + 1;
            $pairs = [];
            $body = '';
            $index = 0;
            foreach ($inside as $num => $b) {
                $pairs[] = $num.' '.strlen($body);
                $body .= $b."\n";
                $compressed[$num] = [$stmNum, $index++];
                unset($objects[$num]);
            }
            $head = implode(' ', $pairs)."\n";
            $data = (string) gzcompress($head.$body);
            $objects[$stmNum] = [
                'body' => '<< /Type /ObjStm /N '.count($inside).' /First '.strlen($head).' /Filter /FlateDecode /Length '.strlen($data).' >>',
                'stream' => $data,
            ];
        }

        ksort($objects);
        foreach ($objects as $num => $o) {
            $offsets[$num] = strlen($out);
            $out .= $num." 0 obj\n".$o['body'];
            if ($o['stream'] !== null) {
                $out .= "\nstream\n".$o['stream']."\nendstream";
            }
            $out .= "\nendobj\n";
        }

        $size = max(array_keys($offsets + $compressed)) + 1;

        if ($mode === 'none') {
            return $out."trailer\n<< /Size ".$size.' /Root '.$root.' 0 R'.$trailerExtra." >>\n%%EOF\n";
        }

        if ($mode === 'table' && $compressed === []) {
            $xrefAt = strlen($out);
            $out .= "xref\n0 ".$size."\n0000000000 65535 f \n";
            for ($i = 1; $i < $size; $i++) {
                $out .= isset($offsets[$i])
                    ? sprintf("%010d 00000 n \n", $offsets[$i])
                    : "0000000000 65535 f \n";
            }
            $out .= "trailer\n<< /Size ".$size.' /Root '.$root.' 0 R'.$trailerExtra." >>\nstartxref\n".$xrefAt."\n%%EOF\n";

            return $out;
        }

        // Cross-reference STREAM, W = [1 4 2].
        $xrefNum = $size;
        $size++;
        $xrefAt = strlen($out);
        $offsets[$xrefNum] = $xrefAt;
        $rows = [];
        for ($i = 0; $i < $size; $i++) {
            if ($i === 0) {
                $rows[] = "\x00".pack('N', 0)."\xFF\xFF";
            } elseif (isset($compressed[$i])) {
                $rows[] = "\x02".pack('N', $compressed[$i][0]).pack('n', $compressed[$i][1]);
            } elseif (isset($offsets[$i])) {
                $rows[] = "\x01".pack('N', $offsets[$i])."\x00\x00";
            } else {
                $rows[] = "\x00".pack('N', 0)."\x00\x00";
            }
        }
        $dict = '/Type /XRef /Size '.$size.' /W [1 4 2] /Root '.$root.' 0 R'.$trailerExtra;
        if (! empty($opts['predictor'])) {
            // PNG "Up" predictor, as most producers write it.
            $prev = str_repeat("\x00", 7);
            $raw = '';
            foreach ($rows as $row) {
                $enc = '';
                for ($j = 0; $j < 7; $j++) {
                    $enc .= chr((ord($row[$j]) - ord($prev[$j])) & 0xFF);
                }
                $raw .= "\x02".$enc;
                $prev = $row;
            }
            $dict .= ' /DecodeParms << /Predictor 12 /Columns 7 >>';
        } else {
            $raw = implode('', $rows);
        }
        $data = (string) gzcompress($raw);
        $out .= $xrefNum." 0 obj\n<< ".$dict.' /Filter /FlateDecode /Length '.strlen($data)." >>\nstream\n".$data."\nendstream\nendobj\n";
        $out .= "startxref\n".$xrefAt."\n%%EOF\n";

        return $out;
    }

    /**
     * Append an incremental update to an existing file: new bodies for the
     * given object numbers, a fresh xref section and a trailer with /Prev.
     *
     * @param  array<int, string>  $objects  number => full "<<...>>" body or "<<...>>\nstream\n...\nendstream"
     */
    public static function appendUpdate(string $pdf, array $objects, int $size, int $root = 1): string
    {
        preg_match_all('/startxref\s+(\d+)/', $pdf, $m);
        $prev = (int) end($m[1]);

        $out = $pdf;
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= $num." 0 obj\n".$body."\nendobj\n";
        }
        $xrefAt = strlen($out);
        $out .= "xref\n";
        foreach ($offsets as $num => $off) {
            $out .= $num." 1\n".sprintf("%010d 00000 n \n", $off);
        }
        $out .= "trailer\n<< /Size ".$size.' /Root '.$root.' 0 R /Prev '.$prev." >>\nstartxref\n".$xrefAt."\n%%EOF\n";

        return $out;
    }

    /** A PDF literal string, escaped. */
    public static function lit(string $s): string
    {
        return '('.strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r']).')';
    }

    /** A UTF-16BE text string, as a hex string with BOM. */
    public static function utf16(string $utf8): string
    {
        return '<FEFF'.strtoupper(bin2hex((string) mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8'))).'>';
    }

    /**
     * The common case: a catalog, a page tree and one page per content string,
     * every page using Helvetica as /F1 (and any extra resources given).
     *
     * Object 1 is the catalog, 2 the page tree. Returns the builder so a test
     * can add more objects before building; page object numbers are in $pages.
     *
     * @param  list<string>  $contents
     * @param  array{catalog?: string, page?: string, resources?: string, flate?: bool, mediaBox?: string}  $opts
     * @param  list<int>  $pages
     */
    public static function textPdf(array $contents, array $opts = [], ?array &$pages = null): self
    {
        $b = new self();
        $catalog = $b->reserve();
        $tree = $b->reserve();
        $font = $b->object('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $pages = [];
        foreach ($contents as $content) {
            $c = $b->stream($content, '', $opts['flate'] ?? false);
            $pages[] = $b->object(
                '<< /Type /Page /Parent '.$tree.' 0 R /Contents '.$c.' 0 R'
                .' /Resources << /Font << /F1 '.$font.' 0 R >> '.($opts['resources'] ?? '').' >> '
                .($opts['page'] ?? '').' >>'
            );
        }
        $kids = implode(' ', array_map(fn (int $p) => $p.' 0 R', $pages));
        $b->object('<< /Type /Pages /Kids ['.$kids.'] /Count '.count($pages).' /MediaBox '.($opts['mediaBox'] ?? '[0 0 612 792]').' >>', $tree);
        $b->object('<< /Type /Catalog /Pages '.$tree.' 0 R '.($opts['catalog'] ?? '').' >>', $catalog);

        return $b;
    }

    /** One line of Helvetica text at (x, y). */
    public static function line(string $text, float $x = 72, float $y = 720, float $size = 12, string $font = 'F1'): string
    {
        return 'BT /'.$font.' '.$size.' Tf '.$x.' '.$y.' Td '.self::lit($text)." Tj ET\n";
    }
}
