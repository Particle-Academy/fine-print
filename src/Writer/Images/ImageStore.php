<?php

declare(strict_types=1);

namespace FinePrint\Writer\Images;

use FinePrint\Writer\Pdf\PdfFile;

/**
 * The images a document uses, each embedded once however often it appears,
 * named `/Im1`, `/Im2`, ... in first-use order.
 */
final class ImageStore
{
    /** @var array<string, array{name: string, image: Image}> */
    private array $images = [];

    public function __construct(private readonly int $maxPixels = Image::MAX_PIXELS) {}

    /** @return array{name: string, image: Image} */
    public function add(string $src): array
    {
        $key = hash('sha256', $src);

        return $this->images[$key] ??= ['name' => 'Im'.(count($this->images) + 1), 'image' => Image::fromDataUrl($src, $this->maxPixels)];
    }

    /**
     * Write the images a page drew; returns resource name => object number.
     *
     * @param  array<string, true>  $drawn  by resource name
     * @return array<string, int>
     */
    public function emit(PdfFile $file, array $drawn): array
    {
        $out = [];
        foreach ($this->images as $entry) {
            if (! isset($drawn[$entry['name']])) {
                continue;
            }
            $x = $entry['image']->xobject();
            $dict = $x['dict'];
            if ($x['smask'] !== null) {
                $mask = $file->stream($x['smask']['dict'], $x['smask']['data'], false);
                $dict .= ' /SMask '.$mask.' 0 R';
            }
            $out[$entry['name']] = $file->stream($dict, $x['data'], false);
        }

        return $out;
    }
}
