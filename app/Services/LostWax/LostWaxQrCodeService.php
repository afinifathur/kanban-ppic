<?php

namespace App\Services\LostWax;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use InvalidArgumentException;

class LostWaxQrCodeService
{
    /**
     * Generate an SVG string representation of a QR code for a given barcode string.
     *
     * @param  string  $barcode  The plain text Traveler Card barcode identifier (e.g. "3110826005")
     * @param  int  $size  Dimension in units/pixels (default: 120)
     * @param  int  $margin  Quiet zone margin in modules (default: 1)
     * @return string Raw SVG markup
     */
    public function generateSvg(string $barcode, int $size = 120, int $margin = 1): string
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            throw new InvalidArgumentException('Barcode identifier tidak boleh kosong.');
        }

        $renderer = new ImageRenderer(
            new RendererStyle($size, $margin),
            new SvgImageBackEnd
        );

        $writer = new Writer($renderer);

        return $writer->writeString($barcode, 'UTF-8', ErrorCorrectionLevel::M());
    }

    /**
     * Generate a Base64-encoded Data URI SVG string suitable for direct use in <img src="...">.
     *
     * @return string Data URI (e.g. "data:image/svg+xml;base64,...")
     */
    public function generateBase64DataUri(string $barcode, int $size = 120, int $margin = 1): string
    {
        $svg = $this->generateSvg($barcode, $size, $margin);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Batch generate Base64 Data URIs mapped by tree ID.
     *
     * @param  iterable<\App\Models\LostWaxTree>  $trees
     * @return array<int, string> Map of [ tree_id => data_uri ]
     */
    public function generateBatchDataUris(iterable $trees, int $size = 120, int $margin = 1): array
    {
        $map = [];
        foreach ($trees as $tree) {
            if ($tree && ! empty($tree->barcode)) {
                $map[$tree->id] = $this->generateBase64DataUri($tree->barcode, $size, $margin);
            }
        }

        return $map;
    }
}
