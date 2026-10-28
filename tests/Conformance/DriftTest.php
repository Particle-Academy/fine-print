<?php

declare(strict_types=1);

use FinePrint\Tests\Conformance\Export;
use ParticleAcademy\Conformance\Conformance;

/*
 * The exporter, run NOW into a temporary directory, must reproduce the pinned
 * fixture set exactly: the same cases.json and the same fixture files, each
 * decoding to the same bytes.
 *
 * Red here means the PHP writer (or the exporter) changed what the ports are
 * held to. That may be intended, and then it is a CONTRACT change: run
 * `composer conformance:export`, review the diff in fancy-conformance, release
 * it, and move the pin. It is never fixed by editing a row by hand.
 *
 * Fixture files compare by DECODED content, never by stored bytes: a `.gz` is
 * deflated by the exporting machine's zlib, and two zlib builds write
 * different bytes for the same data. The digests inside cases.json are of the
 * decoded bytes, so they are exact on every platform.
 */

function fpcDecoded(string $path): string
{
    $bytes = (string) file_get_contents($path);

    return str_ends_with($path, '.gz') ? (string) gzdecode($bytes) : $bytes;
}

/** @return array<string, string> file name => sha256 of the decoded bytes */
function fpcFixtureDigests(string $dir): array
{
    $out = [];
    foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
        if ($name !== '.' && $name !== '..') {
            $out[$name] = hash('sha256', fpcDecoded($dir.'/'.$name));
        }
    }
    ksort($out);

    return $out;
}

it('re-exports the pinned fine-print suites without a byte of drift', function (): void {
    $tmp = sys_get_temp_dir().'/fp-drift-'.bin2hex(random_bytes(6));
    try {
        $counts = Export::write($tmp);
        $installed = Conformance::root().'/suites';

        foreach (Export::SUITES as $suite) {
            $fresh = str_replace("\r\n", "\n", (string) file_get_contents("{$tmp}/{$suite}/cases.json"));
            $pinned = str_replace("\r\n", "\n", (string) file_get_contents("{$installed}/{$suite}/cases.json"));

            expect($fresh === $pinned)->toBeTrue("{$suite}/cases.json drifted: run composer conformance:export and review the diff")
                ->and(fpcFixtureDigests("{$tmp}/{$suite}/fixtures"))->toBe(fpcFixtureDigests("{$installed}/{$suite}/fixtures"))
                ->and($counts[$suite])->toBeGreaterThan(0);
        }
    } finally {
        fpcRemoveTree($tmp);
    }
});

function fpcRemoveTree(string $path): void
{
    if (is_file($path)) {
        unlink($path);

        return;
    }
    if (! is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            fpcRemoveTree($path.'/'.$entry);
        }
    }
    rmdir($path);
}

it('canon() is stable under a different deflate of the same PDF', function (): void {
    // The canon tier's claim, on the PHP side: recompressing every stream at
    // another level changes the raw bytes and leaves canon() alone.
    $doc = json_decode((string) file_get_contents(dirname(__DIR__).'/fixtures/canonical.json'), true, flags: JSON_THROW_ON_ERROR);
    $pdf = FinePrint\Agent::toBytes($doc);
    $recompressed = fpcRecompress($pdf, 1);

    expect($recompressed)->not->toBe($pdf)
        ->and(substr_count($recompressed, '/FlateDecode'))->toBe(substr_count($pdf, '/FlateDecode'))
        ->and(Export::canon($recompressed))->toBe(Export::canon($pdf));
});

/** The same writer PDF with every FlateDecode stream deflated at `$level`, offsets and ID rebuilt. */
function fpcRecompress(string $pdf, int $level): string
{
    $canon = Export::canon($pdf);
    $at = strpos($canon, "1 0 obj\n");
    $body = substr($canon, 0, (int) $at);
    $offsets = [];
    while (preg_match('/\G(\d+) 0 obj\n/', $canon, $m, 0, (int) $at) === 1) {
        $offsets[] = strlen($body);
        $at += strlen($m[0]);
        $eol = (int) strpos($canon, "\n", $at);
        $line = substr($canon, $at, $eol - $at);
        if (preg_match('#/Length (\d+) >>$#D', $line, $len) === 1 && substr($canon, $eol + 1, 7) === "stream\n") {
            $data = substr($canon, $eol + 8, (int) $len[1]);
            if (str_contains($line, '/Filter /FlateDecode')) {
                $data = (string) gzcompress($data, $level);
                $line = substr($line, 0, -strlen($len[0])).'/Length '.strlen($data).' >>';
            }
            $body .= $m[0].$line."\nstream\n".$data."\nendstream\nendobj\n";
            $at = $eol + 8 + (int) $len[1] + 18;

            continue;
        }
        $end = (int) strpos($canon, "\nendobj\n", $at);
        $body .= $m[0].substr($canon, $at, $end + 8 - $at);
        $at = $end + 8;
    }
    $trailer = substr($canon, $at + strlen("trailer\n"), -strlen("\n%%EOF\n"));
    $id = md5($body);
    $out = $body.'xref'."\n".'0 '.(count($offsets) + 1)."\n0000000000 65535 f\r\n";
    foreach ($offsets as $off) {
        $out .= sprintf('%010d 00000 n', $off)."\r\n";
    }

    return $out.'trailer'."\n".str_replace('/ID [<> <>]', '/ID [<'.$id.'> <'.$id.'>]', $trailer)."\nstartxref\n".strlen($body)."\n%%EOF\n";
}
