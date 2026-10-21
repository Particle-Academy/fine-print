<?php

declare(strict_types=1);

namespace FinePrint\Render\Glyphs;

use FinePrint\Text\Encodings;
use FinePrint\Writer\Fonts\StandardMetrics;
use FinePrint\Writer\Fonts\TrueType;

/**
 * Outlines for standard-14 text a PDF does not embed (owner ruling
 * 2026-10-21). The twelve text faces, and the Windows names every viewer
 * treats as the same faces (Arial, Times New Roman, Courier New), draw from
 * metric-compatible fonts bundled UNMODIFIED in resources/fonts; see the
 * NOTICE there for versions, sources and licences.
 *
 * Symbol and ZapfDingbats have no bundled equivalent and stay unembedded, as
 * does any other face (Arial Narrow, Arial Black, Helvetica Neue, ...):
 * substituting a different design would draw text that claims to be what
 * the file asked for and is not.
 *
 * The bundled files are ours to trust (their hashes are pinned by a test),
 * but they are still read through the same TrueType reader as a hostile
 * font, once per process.
 */
final class StandardFonts
{
    public const DIR = __DIR__.'/../../../resources/fonts';

    private const FILES = [
        'sans' => 'LiberationSans',
        'serif' => 'Tinos',
        'mono' => 'Cousine',
    ];

    /** @var array<string, TrueType> file => parsed font */
    private static array $cache = [];

    /**
     * The standard-14 face a BaseFont names, or null when it names none of
     * the twelve text faces.
     */
    public static function match(string $baseFont): ?string
    {
        // A subset tag (ABCDEF+) and the separators producers use: Arial,Bold
        // Arial-BoldMT, "Times New Roman".
        $n = strtolower((string) preg_replace('/^[A-Z]{6}\+/', '', $baseFont));
        $n = str_replace([' ', '-', ',', '_'], '', $n);
        foreach ([
            'timesnewroman' => 'serif', 'times' => 'serif',
            'couriernew' => 'mono', 'courier' => 'mono',
            'helvetica' => 'sans', 'arial' => 'sans',
        ] as $prefix => $family) {
            if (! str_starts_with($n, $prefix)) {
                continue;
            }
            $style = substr($n, strlen($prefix));
            // Only style words may follow the family; anything else (narrow,
            // black, neue, ten, 10pitch) is a different design.
            if (! preg_match('/^(?:bold|italic|oblique|roman|regular|ps|mt)*$/', $style)) {
                return null;
            }
            $bold = str_contains($style, 'bold');
            $italic = str_contains($style, 'italic') || str_contains($style, 'oblique');

            return match ($family) {
                'sans' => 'Helvetica'.($bold || $italic ? '-'.($bold ? 'Bold' : '').($italic ? 'Oblique' : '') : ''),
                'mono' => 'Courier'.($bold || $italic ? '-'.($bold ? 'Bold' : '').($italic ? 'Oblique' : '') : ''),
                default => 'Times-'.($bold || $italic ? ($bold ? 'Bold' : '').($italic ? 'Italic' : '') : 'Roman'),
            };
        }

        return null;
    }

    /** The bundled file for a standard-14 text face (as {@see match()} returns). */
    public static function file(string $face): string
    {
        $family = match (true) {
            str_starts_with($face, 'Times') => 'serif',
            str_starts_with($face, 'Courier') => 'mono',
            default => 'sans',
        };
        $bold = str_contains($face, 'Bold');
        $italic = str_contains($face, 'Italic') || str_contains($face, 'Oblique');
        $style = $bold ? ($italic ? 'BoldItalic' : 'Bold') : ($italic ? 'Italic' : 'Regular');

        return self::DIR.'/'.self::FILES[$family].'-'.$style.'.ttf';
    }

    /**
     * A face's standard widths keyed by character (UTF-8), from
     * {@see StandardMetrics} (WinAnsi-indexed), so a StandardEncoding or
     * /Differences font gets the right width for each glyph.
     *
     * @return array<string, int>
     */
    public static function widths(string $face): array
    {
        $win = Encodings::map('WinAnsiEncoding') ?? [];
        $table = StandardMetrics::widths($face);
        $out = [];
        foreach ($win as $code => $u) {
            $w = $table[$code - 32] ?? 0;
            if ($code >= 32 && $w > 0 && ! isset($out[$u])) {
                $out[$u] = $w;
            }
        }

        return $out;
    }

    /** The parsed bundled font for a face, read once per process; null if the file is missing. */
    public static function font(string $face): ?TrueType
    {
        $file = self::file($face);
        if (isset(self::$cache[$file])) {
            return self::$cache[$file];
        }
        $bytes = @file_get_contents($file);
        if ($bytes === false) {
            return null;
        }

        return self::$cache[$file] = TrueType::parse($bytes, requireUnicodeCmap: false);
    }
}
