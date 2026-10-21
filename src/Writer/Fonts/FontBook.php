<?php

declare(strict_types=1);

namespace FinePrint\Writer\Fonts;

use FinePrint\Exceptions\FontException;

/**
 * Resolves a run's font family, weight and slant to a {@see Font}, and names
 * each font used as a page resource (`/F1`, `/F2`, ... in first-use order).
 *
 * Families, in order:
 *   1. a TrueType family the HOST registered (`fonts` option), embedded as a
 *      subset. Fonts never come from the model: a document can name a family,
 *      never supply a file or a path.
 *   2. a standard family by name or by a metric-compatible alias (Arial is
 *      Helvetica, Times New Roman is Times, Courier New is Courier): no note.
 *   3. a well-known family that is not available (Calibri, Georgia,
 *      Consolas...): the nearest standard family, with a note for lint.
 *   4. anything else: Helvetica, with a note.
 */
final class FontBook
{
    private const EXACT = [
        'helvetica' => 'Helvetica', 'arial' => 'Helvetica', 'liberation sans' => 'Helvetica', 'arimo' => 'Helvetica',
        'helvetica neue' => 'Helvetica', 'sans-serif' => 'Helvetica', 'sans' => 'Helvetica',
        'times' => 'Times', 'times new roman' => 'Times', 'times-roman' => 'Times', 'liberation serif' => 'Times',
        'tinos' => 'Times', 'serif' => 'Times',
        'courier' => 'Courier', 'courier new' => 'Courier', 'liberation mono' => 'Courier', 'cousine' => 'Courier',
        'monospace' => 'Courier', 'mono' => 'Courier',
    ];

    private const NEAREST = [
        'calibri' => 'Helvetica', 'aptos' => 'Helvetica', 'segoe ui' => 'Helvetica', 'verdana' => 'Helvetica',
        'tahoma' => 'Helvetica', 'inter' => 'Helvetica', 'roboto' => 'Helvetica', 'open sans' => 'Helvetica',
        'lato' => 'Helvetica', 'source sans pro' => 'Helvetica', 'noto sans' => 'Helvetica', 'system-ui' => 'Helvetica',
        'dejavu sans' => 'Helvetica', 'carlito' => 'Helvetica',
        'georgia' => 'Times', 'cambria' => 'Times', 'garamond' => 'Times', 'book antiqua' => 'Times',
        'palatino' => 'Times', 'noto serif' => 'Times', 'dejavu serif' => 'Times', 'caladea' => 'Times',
        'consolas' => 'Courier', 'menlo' => 'Courier', 'monaco' => 'Courier', 'source code pro' => 'Courier',
        'fira code' => 'Courier', 'jetbrains mono' => 'Courier', 'cascadia code' => 'Courier',
        'cascadia mono' => 'Courier', 'dejavu sans mono' => 'Courier',
    ];

    private const FACES = [
        'Helvetica' => ['Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique'],
        'Times' => ['Times-Roman', 'Times-Bold', 'Times-Italic', 'Times-BoldItalic'],
        'Courier' => ['Courier', 'Courier-Bold', 'Courier-Oblique', 'Courier-BoldOblique'],
    ];

    /** @var array<string, array<string, TrueType>> lowercased family => face => font */
    private array $host = [];

    /** @var array<string, string> lowercased family => the name the host gave it */
    private array $hostNames = [];

    /** @var array<string, Font> */
    private array $fonts = [];

    /** @var array<string, string> font id => resource name */
    private array $names = [];

    /**
     * @param  array<string, string|array<string, string>>  $hostFonts
     *
     * @throws FontException
     */
    public function __construct(array $hostFonts = [])
    {
        foreach ($hostFonts as $family => $spec) {
            if (! is_string($family) || trim($family) === '') {
                throw new FontException('A host font needs a family name.');
            }
            $faces = is_string($spec) ? ['regular' => $spec] : $spec;
            if (! is_array($faces) || $faces === []) {
                throw new FontException('Host font "'.$family.'" must be a file path, raw bytes, or a map of faces.');
            }
            $key = strtolower(trim($family));
            foreach ($faces as $face => $source) {
                if (! in_array($face, ['regular', 'bold', 'italic', 'boldItalic'], true) || ! is_string($source)) {
                    throw new FontException('Host font "'.$family.'": faces are regular, bold, italic and boldItalic, each a path or bytes.');
                }
                try {
                    $tt = TrueType::parse(self::bytes($source));
                } catch (FontException $e) {
                    throw new FontException('Host font "'.$family.'" ('.$face.'): '.$e->getMessage());
                }
                if (! $tt->embeddable()) {
                    throw new FontException('Host font "'.$family.'" ('.$face.'): its licence (OS/2 fsType) does not allow embedding.');
                }
                $this->host[$key][$face] = $tt;
            }
            $this->hostNames[$key] = $family;
        }
    }

    /**
     * @return array{font: Font, note: ?string} the note says what was substituted, for lint
     */
    public function resolve(?string $family, bool $bold, bool $italic): array
    {
        $name = $family === null ? 'helvetica' : strtolower(trim($family));
        $note = null;

        if (isset($this->host[$name])) {
            $faces = $this->host[$name];
            $want = $bold && $italic ? 'boldItalic' : ($bold ? 'bold' : ($italic ? 'italic' : 'regular'));
            $order = match ($want) {
                'boldItalic' => ['boldItalic', 'bold', 'italic', 'regular'],
                'bold' => ['bold', 'regular'],
                'italic' => ['italic', 'regular'],
                default => ['regular', 'bold', 'italic', 'boldItalic'],
            };
            foreach ($order as $face) {
                if (isset($faces[$face])) {
                    if ($face !== $want) {
                        $note = 'the host font "'.$this->hostNames[$name].'" has no '.$want.' face; '.$face.' was used';
                    }
                    $id = 'tt:'.$name.':'.$face;

                    return ['font' => $this->fonts[$id] ??= new EmbeddedFont($faces[$face], $name.':'.$face), 'note' => $note];
                }
            }
        }

        if (isset(self::EXACT[$name])) {
            $std = self::EXACT[$name];
        } elseif (isset(self::NEAREST[$name])) {
            $std = self::NEAREST[$name];
            $note = 'font "'.$family.'" is not available; '.$std.' was used. Register it with the host\'s fonts option to embed it.';
        } else {
            $std = 'Helvetica';
            $note = 'font "'.$family.'" is not available; Helvetica was used. Register it with the host\'s fonts option to embed it.';
        }
        $face = self::FACES[$std][($bold ? 1 : 0) + ($italic ? 2 : 0)];

        return ['font' => $this->fonts['std:'.$face] ??= new StandardFont($face), 'note' => $note];
    }

    /** The page-resource name for a font, assigned on first use. */
    public function resourceName(Font $font): string
    {
        return $this->names[$font->id()] ??= 'F'.(count($this->names) + 1);
    }

    /**
     * Every font used, by resource name, in first-use order.
     *
     * @return array<string, Font>
     */
    public function used(): array
    {
        $out = [];
        foreach ($this->names as $id => $name) {
            $out[$name] = $this->fonts[$id];
        }

        return $out;
    }

    private static function bytes(string $source): string
    {
        if (strlen($source) < 4096 && ! str_contains($source, "\0") && is_file($source)) {
            $bytes = file_get_contents($source);
            if ($bytes === false) {
                throw new FontException('could not read the font file');
            }

            return $bytes;
        }

        return $source;
    }
}
