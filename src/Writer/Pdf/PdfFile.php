<?php

declare(strict_types=1);

namespace FinePrint\Writer\Pdf;

/**
 * The object store and serialiser: numbered objects, a classic
 * cross-reference table, a trailer. Deterministic: no dates, and the file ID
 * is a hash of the body, so the same objects always give the same bytes.
 *
 * Object bodies arrive as finished PDF syntax built by the writer from
 * {@see Syntax}; nothing here interprets them.
 */
final class PdfFile
{
    /** @var array<int, string> */
    private array $objects = [];

    public function __construct(private readonly bool $compress = true) {}

    public function reserve(): int
    {
        $num = count($this->objects) + 1;
        $this->objects[$num] = 'null';

        return $num;
    }

    public function add(string $body, ?int $num = null): int
    {
        $num ??= $this->reserve();
        $this->objects[$num] = $body;

        return $num;
    }

    /**
     * A stream. `$dict` is the dictionary's entries without `/Length`.
     * `$compressible` streams are Flate-compressed when compression is on;
     * data that is already encoded (images, font files) passes as it is.
     */
    public function stream(string $dict, string $data, bool $compressible = true, ?int $num = null): int
    {
        if ($compressible && $this->compress) {
            $data = (string) gzcompress($data, 9);
            $dict = '/Filter /FlateDecode'.($dict === '' ? '' : ' '.$dict);
        }
        $body = '<< '.($dict === '' ? '' : $dict.' ').'/Length '.strlen($data)." >>\nstream\n".$data."\nendstream";

        return $this->add($body, $num);
    }

    public function output(int $root, ?int $info): string
    {
        $out = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        ksort($this->objects);
        foreach ($this->objects as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= $num." 0 obj\n".$body."\nendobj\n";
        }
        $id = md5($out);
        $xref = strlen($out);
        $count = count($this->objects) + 1;
        $out .= "xref\n0 {$count}\n0000000000 65535 f\r\n";
        for ($i = 1; $i < $count; $i++) {
            $out .= sprintf('%010d 00000 n', $offsets[$i])."\r\n";
        }
        $out .= "trailer\n<< /Size {$count} /Root {$root} 0 R"
            .($info !== null ? " /Info {$info} 0 R" : '')
            .' /ID [<'.$id.'> <'.$id.">] >>\n";
        $out .= "startxref\n{$xref}\n%%EOF\n";

        return $out;
    }
}
