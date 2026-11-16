<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use App\Models\LostWaxCastingResult;
use App\Services\LostWax\LostWaxKitirResolverService;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Picqer\Barcode\BarcodeGeneratorPNG;

class LostWaxKitirController extends Controller
{
    public function __construct(
        protected LostWaxKitirResolverService $kitirService
    ) {}

    /**
     * Display printable Kitir Produksi Fitting (A4 Landscape) for a specific Production Code group.
     */
    public function print(Request $request, LostWaxCastingResult $castingResult, string $code)
    {
        // Decode code in case it contains URL-encoded slashes or commas
        $productionCode = urldecode($code);

        try {
            $kitir = $this->kitirService->issueOrGetKitir($castingResult, $productionCode, auth()->user());
            $this->kitirService->recordPrint($kitir, auth()->user());

            $renderedItem = $this->prepareKitirRenderData($kitir, $castingResult);
            $kitirItems = [$renderedItem];
            $isBatch = false;

            return view('lost-wax.casting-results.kitir', compact('castingResult', 'kitirItems', 'isBatch'));
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('lost-wax.casting-results.show', $castingResult)
                ->withErrors(['general' => $e->getMessage()]);
        }
    }

    /**
     * Display printable Kitir Produksi Fitting (A4 Landscape) for ALL Production Code groups in a Casting Result.
     * Each Kitir prints on its own clean A4 Landscape page.
     */
    public function printAll(Request $request, LostWaxCastingResult $castingResult)
    {
        try {
            $allKitirs = $this->kitirService->issueAllKitirs($castingResult, auth()->user());

            $kitirItems = [];
            foreach ($allKitirs as $kitir) {
                $this->kitirService->recordPrint($kitir, auth()->user());
                $kitirItems[] = $this->prepareKitirRenderData($kitir, $castingResult);
            }

            $isBatch = true;

            return view('lost-wax.casting-results.kitir', compact('castingResult', 'kitirItems', 'isBatch'));
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('lost-wax.casting-results.show', $castingResult)
                ->withErrors(['general' => $e->getMessage()]);
        }
    }

    /**
     * Prepare SVG QR Code, Code 128 PNG Barcode, and Process rows for a Kitir item.
     *
     * @return array{
     *     kitir: \App\Models\LostWaxKitir,
     *     barcodeBase64: string,
     *     qrCodeSvg: string,
     *     processRows: array
     * }
     */
    protected function prepareKitirRenderData(\App\Models\LostWaxKitir $kitir, LostWaxCastingResult $castingResult): array
    {
        // 1. Barcode Code 128 PNG
        $generator = new BarcodeGeneratorPNG;
        $barcodeBase64 = base64_encode(
            $generator->getBarcode($kitir->traveler_number, $generator::TYPE_CODE_128, 2, 45)
        );

        // 2. QR Code SVG (ECC Level H - High 30% recovery)
        $qrRenderer = new ImageRenderer(
            new RendererStyle(200, 1),
            new SvgImageBackEnd
        );
        $qrWriter = new Writer($qrRenderer);
        $rawQrSvg = $qrWriter->writeString(
            $kitir->traveler_number,
            Encoder::DEFAULT_BYTE_MODE_ENCODING,
            ErrorCorrectionLevel::H()
        );
        $qrCodeSvg = preg_replace('/^<\?xml[^>]*\?>\s*/i', '', $rawQrSvg);

        // 3. Process Rows (6 fitting processes)
        $processRows = $this->kitirService->buildFittingProcessRows();

        return [
            'kitir' => $kitir,
            'barcodeBase64' => $barcodeBase64,
            'qrCodeSvg' => $qrCodeSvg,
            'processRows' => $processRows,
        ];
    }
}

