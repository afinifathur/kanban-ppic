<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use App\Services\AssemblyPhotoService;
use Illuminate\Http\Request;

class AssemblyPhotoController extends Controller
{
    public function __construct(private readonly AssemblyPhotoService $photoService) {}

    public function index(Request $request)
    {
        $selectedCode = $request->query('product_code');
        $selectedName = $request->query('product_name');
        $currentPhoto = null;
        $history = collect();

        if ($selectedCode && ! AssemblyPhotoService::isInvalidProductCode($selectedCode)) {
            $currentPhoto = $this->photoService->getCurrentPhoto($selectedCode);
            $history = $this->photoService->getHistory($selectedCode);
        }

        return view('settings.assembly-photos.index', compact(
            'selectedCode',
            'selectedName',
            'currentPhoto',
            'history'
        ));
    }

    public function auditIndex(Request $request)
    {
        $search = (string) $request->input('q', '');
        $statusFilter = (string) $request->input('status', 'all');
        $page = (int) $request->input('page', 1);

        $auditData = $this->photoService->getAuditList($search, $statusFilter, 50, $page);

        $items = $auditData['items'];
        $counts = $auditData['counts'];

        return view('settings.assembly-photos.audit', compact(
            'items',
            'counts',
            'search',
            'statusFilter'
        ));
    }

    public function search(Request $request)
    {
        $query = (string) $request->input('q', '');
        $products = $this->photoService->searchProducts($query);

        return response()->json([
            'success' => true,
            'products' => $products,
        ]);
    }

    public function detail(Request $request)
    {
        $code = (string) $request->input('product_code', '');
        if ($code === '' || AssemblyPhotoService::isInvalidProductCode($code)) {
            return response()->json([
                'success' => false,
                'message' => 'Product code tidak valid atau kosong.',
            ], 422);
        }

        $current = $this->photoService->getCurrentPhoto($code);
        $history = $this->photoService->getHistory($code);

        return response()->json([
            'success' => true,
            'current' => $current ? [
                'id' => $current->id,
                'version' => $current->version,
                'product_code' => $current->product_code,
                'product_name' => $current->product_name,
                'front_image_url' => $current->front_image_url,
                'side_image_url' => $current->side_image_url,
                'notes' => $current->notes,
                'created_at' => $current->created_at?->format('d M Y H:i'),
                'uploader_name' => $current->uploader?->name ?? 'System',
            ] : null,
            'history' => $history->map(fn ($item) => [
                'id' => $item->id,
                'version' => $item->version,
                'is_current' => $item->is_current,
                'front_image_url' => $item->front_image_url,
                'side_image_url' => $item->side_image_url,
                'notes' => $item->notes,
                'created_at' => $item->created_at?->format('d M Y H:i'),
                'uploader_name' => $item->uploader?->name ?? 'System',
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'product_code' => 'required|string|max:100',
            'product_name' => 'nullable|string|max:255',
            'front_photo' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:20480',
            'side_photo' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:20480',
            'notes' => 'nullable|string|max:255',
        ]);

        $productCode = trim($request->input('product_code'));
        $productName = $request->input('product_name');
        $frontFile = $request->file('front_photo');
        $sideFile = $request->file('side_photo');
        $notes = $request->input('notes');

        if (AssemblyPhotoService::isInvalidProductCode($productCode)) {
            $msg = 'Product Code tidak valid (placeholder seperti XX tidak diperbolehkan).';
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }

            return back()->withInput()->with('error', $msg);
        }

        try {
            $photo = $this->photoService->storePhoto(
                $productCode,
                $productName,
                $frontFile,
                $sideFile,
                auth()->user(),
                $notes
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => "Foto Rangkai {$productCode} (Versi {$photo->version}) berhasil disimpan.",
                    'photo' => $photo,
                ]);
            }

            return redirect()->route('settings.assembly-photos.index', [
                'product_code' => $productCode,
                'product_name' => $productName,
            ])->with('success', "Foto Rangkai {$productCode} (Versi {$photo->version}) berhasil diperbarui.");
        } catch (\Exception $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()->withInput()->with('error', $e->getMessage());
        }
    }
}
