<?php

namespace App\Services;

use App\Contracts\ItemMasterRepository;
use App\Models\LostWaxAssemblyPhoto;
use App\Models\LostWaxItemReference;
use App\Models\ProductionPlan;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AssemblyPhotoService
{
    public function __construct(private readonly ItemMasterRepository $itemMasterRepository) {}

    /**
     * Check if a product code is a placeholder, null, or invalid code.
     */
    public static function isInvalidProductCode(?string $code): bool
    {
        if ($code === null) {
            return true;
        }

        $trimmed = strtoupper(trim($code));
        if ($trimmed === '' || $trimmed === '-' || $trimmed === 'NULL') {
            return true;
        }

        // Filter out common dummy/placeholder values
        return in_array($trimmed, ['XX', 'X09', 'XXX', 'NONE', 'N/A', 'NA', 'TBD', 'UNKNOWN', 'DUMMY'], true);
    }

    /**
     * Get the current active assembly photo for a product.
     * Matching order:
     * 1. Exact Product Code
     * 2. Fallback: Exact Product Name (normalized)
     */
    public function getCurrentPhoto(?string $productCode, ?string $productName = null): ?LostWaxAssemblyPhoto
    {
        $cleanedCode = $productCode !== null ? trim($productCode) : '';
        $cleanedName = $productName !== null ? trim($productName) : '';

        if ($cleanedCode !== '' && ! self::isInvalidProductCode($cleanedCode)) {
            $photo = LostWaxAssemblyPhoto::where('product_code', $cleanedCode)
                ->where('is_current', true)
                ->first();

            if ($photo) {
                return $photo;
            }
        }

        if ($cleanedName !== '') {
            $photo = LostWaxAssemblyPhoto::where('product_name', $cleanedName)
                ->where('is_current', true)
                ->first();

            if ($photo) {
                return $photo;
            }
        }

        return null;
    }

    /**
     * Get the version history for a given product code.
     */
    public function getHistory(string $productCode): Collection
    {
        $cleanedCode = trim($productCode);
        if ($cleanedCode === '' || self::isInvalidProductCode($cleanedCode)) {
            return collect();
        }

        return LostWaxAssemblyPhoto::with('uploader')
            ->where('product_code', $cleanedCode)
            ->orderByDesc('version')
            ->get();
    }

    /**
     * Store or replace assembly photos for a product.
     */
    public function storePhoto(
        string $productCode,
        ?string $productName,
        ?UploadedFile $frontFile = null,
        ?UploadedFile $sideFile = null,
        ?User $user = null,
        ?string $notes = null
    ): LostWaxAssemblyPhoto {
        $productCode = trim($productCode);
        if ($productCode === '' || self::isInvalidProductCode($productCode)) {
            throw new InvalidArgumentException('Product Code tidak valid (placeholder seperti XX tidak diperbolehkan).');
        }

        $current = $this->getCurrentPhoto($productCode);

        if (! $frontFile && ! $sideFile && ! $current) {
            throw new InvalidArgumentException('Minimal upload salah satu foto (Depan atau Samping).');
        }

        // Determine if we are completing an existing incomplete version or creating a new version
        $isCompletingCurrent = false;
        if ($current) {
            $currentIncomplete = empty($current->front_image_path) || empty($current->side_image_path);
            if ($currentIncomplete) {
                if (empty($current->front_image_path) && ! empty($current->side_image_path) && $frontFile && ! $sideFile) {
                    $isCompletingCurrent = true;
                } elseif (! empty($current->front_image_path) && empty($current->side_image_path) && $sideFile && ! $frontFile) {
                    $isCompletingCurrent = true;
                }
            }
        }

        $version = $current ? ($isCompletingCurrent ? $current->version : $current->version + 1) : 1;
        $cleanSlug = Str::slug($productCode, '_');
        $randomSuffix = Str::lower(Str::random(6));

        // Process Front Photo
        $frontPath = $isCompletingCurrent ? $current->front_image_path : null;
        if ($frontFile && $frontFile->isValid()) {
            $frontTarget = "assembly_photos/{$cleanSlug}_v{$version}_front_{$randomSuffix}.webp";
            $frontPath = $this->compressAndStore($frontFile, $frontTarget);
        }

        // Process Side Photo
        $sidePath = $isCompletingCurrent ? $current->side_image_path : null;
        if ($sideFile && $sideFile->isValid()) {
            $sideTarget = "assembly_photos/{$cleanSlug}_v{$version}_side_{$randomSuffix}.webp";
            $sidePath = $this->compressAndStore($sideFile, $sideTarget);
        }

        return DB::transaction(function () use ($productCode, $productName, $version, $frontPath, $sidePath, $user, $notes, $isCompletingCurrent, $current) {
            if ($isCompletingCurrent && $current) {
                $current->update([
                    'product_name' => $productName ? trim($productName) : $current->product_name,
                    'front_image_path' => $frontPath ?? $current->front_image_path,
                    'side_image_path' => $sidePath ?? $current->side_image_path,
                    'notes' => $notes ? trim($notes) : $current->notes,
                ]);

                return $current->fresh();
            }

            // Demote existing active versions for this product code
            LostWaxAssemblyPhoto::where('product_code', $productCode)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            return LostWaxAssemblyPhoto::create([
                'product_code' => $productCode,
                'product_name' => $productName ? trim($productName) : null,
                'version' => $version,
                'front_image_path' => $frontPath,
                'side_image_path' => $sidePath,
                'is_current' => true,
                'created_by' => $user?->id,
                'notes' => $notes ? trim($notes) : null,
            ]);
        });
    }

    /**
     * Search products for autocomplete across ItemMasterRepository, LostWaxItemReference, and ProductionPlan.
     * Invalid placeholder codes (like XX, X09, -) are strictly filtered out.
     */
    public function searchProducts(string $query, int $limit = 20): Collection
    {
        $queryLower = strtolower(trim($query));
        $results = collect();

        // 1. Primary: ItemMasterRepository (md_items)
        try {
            $masterItems = $this->itemMasterRepository->allActive();
            foreach ($masterItems as $item) {
                $code = (string) ($item['code'] ?? '');
                $name = (string) ($item['name'] ?? '');

                if (self::isInvalidProductCode($code)) {
                    continue;
                }

                if ($queryLower === '' || str_contains(strtolower($code), $queryLower) || str_contains(strtolower($name), $queryLower)) {
                    $results->put($code, [
                        'code' => $code,
                        'name' => $name,
                        'aisi' => $item['aisi'] ?? null,
                        'standard' => $item['standard'] ?? null,
                    ]);
                }
            }
        } catch (\Throwable) {
            // MasterData repository might be unavailable in local / offline, fallback to local tables below
        }

        // 2. Supplement from LostWaxItemReference
        try {
            $references = LostWaxItemReference::query()
                ->when($queryLower !== '', function ($q) use ($queryLower) {
                    $q->where(DB::raw('LOWER(item_code_snapshot)'), 'like', "%{$queryLower}%")
                        ->orWhere(DB::raw('LOWER(item_name_snapshot)'), 'like', "%{$queryLower}%");
                })
                ->get();

            foreach ($references as $ref) {
                $code = (string) ($ref->item_code_snapshot ?? '');
                if (! self::isInvalidProductCode($code) && ! $results->has($code)) {
                    $results->put($code, [
                        'code' => $code,
                        'name' => $ref->item_name_snapshot ?? '',
                        'aisi' => $ref->aisi_snapshot,
                        'standard' => $ref->standard_snapshot,
                    ]);
                }
            }
        } catch (\Throwable) {
        }

        // 3. Supplement from ProductionPlan (strict valid code filter)
        try {
            $plans = ProductionPlan::query()
                ->select('item_code', 'item_name', 'aisi')
                ->whereNotNull('item_code')
                ->whereNotIn('item_code', ['', '-', 'XX', 'X09', 'null', 'NULL', 'None', 'NONE'])
                ->when($queryLower !== '', function ($q) use ($queryLower) {
                    $q->where(DB::raw('LOWER(item_code)'), 'like', "%{$queryLower}%")
                        ->orWhere(DB::raw('LOWER(item_name)'), 'like', "%{$queryLower}%");
                })
                ->distinct()
                ->get();

            foreach ($plans as $plan) {
                $code = (string) $plan->item_code;
                if (! self::isInvalidProductCode($code) && ! $results->has($code)) {
                    $results->put($code, [
                        'code' => $code,
                        'name' => $plan->item_name ?? '',
                        'aisi' => $plan->aisi,
                        'standard' => null,
                    ]);
                }
            }
        } catch (\Throwable) {
        }

        // Check photo availability for each result
        $existingCodes = LostWaxAssemblyPhoto::whereIn('product_code', $results->keys()->all())
            ->where('is_current', true)
            ->pluck('product_code')
            ->flip();

        return $results->values()->take($limit)->map(function ($item) use ($existingCodes) {
            $item['has_photo'] = isset($existingCodes[$item['code']]);

            return $item;
        });
    }

    /**
     * Compute deterministic photo audit status for a collection of photos belonging to a product.
     *
     * @param  Collection<int, LostWaxAssemblyPhoto>  $photos
     * @return array{status_key: string, label: string, detail: string, badge_class: string, version: int, photo_count: int, max_photos: int}
     */
    public function computeStatusForPhotos(Collection $photos): array
    {
        if ($photos->isEmpty()) {
            return [
                'status_key' => 'none',
                'label' => 'BELUM ADA',
                'detail' => '0 foto',
                'badge_class' => 'bg-slate-100 text-slate-600 border-slate-200',
                'version' => 0,
                'photo_count' => 0,
                'max_photos' => 0,
            ];
        }

        $sorted = $photos->sortBy('version');
        $latest = $sorted->last();
        $latestVersion = (int) ($latest->version ?? 1);

        $totalPhotos = 0;
        $allComplete = true;

        foreach ($sorted as $p) {
            $hasFront = ! empty($p->front_image_path);
            $hasSide = ! empty($p->side_image_path);
            $count = ($hasFront ? 1 : 0) + ($hasSide ? 1 : 0);
            $totalPhotos += $count;

            if ($count < 2) {
                $allComplete = false;
            }
        }

        $expectedTotal = $latestVersion * 2;

        if ($allComplete && $totalPhotos === $expectedTotal) {
            return [
                'status_key' => 'complete',
                'label' => "FOTO TERSEDIA v.{$latestVersion}",
                'detail' => "{$totalPhotos} foto",
                'badge_class' => 'bg-emerald-50 text-emerald-700 border-emerald-300 font-bold',
                'version' => $latestVersion,
                'photo_count' => $totalPhotos,
                'max_photos' => $expectedTotal,
            ];
        }

        return [
            'status_key' => 'incomplete',
            'label' => "INCOMPLETE v.{$latestVersion}",
            'detail' => "{$totalPhotos}/{$expectedTotal} foto",
            'badge_class' => 'bg-amber-50 text-amber-700 border-amber-300 font-bold',
            'version' => $latestVersion,
            'photo_count' => $totalPhotos,
            'max_photos' => $expectedTotal,
        ];
    }

    /**
     * Get paginated audit list of master products with deterministic photo status.
     *
     * @return array{items: LengthAwarePaginator, counts: array{total: int, complete: int, incomplete: int, none: int}}
     */
    public function getAuditList(string $search = '', string $statusFilter = 'all', int $perPage = 50, int $page = 1): array
    {
        $searchLower = strtolower(trim($search));

        // 1. Gather all master items
        $masterMap = collect();

        try {
            $activeItems = $this->itemMasterRepository->allActive();
            foreach ($activeItems as $item) {
                $code = (string) ($item['code'] ?? '');
                if (self::isInvalidProductCode($code)) {
                    continue;
                }
                $masterMap->put($code, [
                    'code' => $code,
                    'name' => (string) ($item['name'] ?? ''),
                    'aisi' => $item['aisi'] ?? null,
                    'standard' => $item['standard'] ?? null,
                ]);
            }
        } catch (\Throwable) {
        }

        // Supplement from LostWaxItemReference
        try {
            $references = LostWaxItemReference::query()
                ->whereNotNull('item_code_snapshot')
                ->get();

            foreach ($references as $ref) {
                $code = (string) $ref->item_code_snapshot;
                if (! self::isInvalidProductCode($code) && ! $masterMap->has($code)) {
                    $masterMap->put($code, [
                        'code' => $code,
                        'name' => (string) ($ref->item_name_snapshot ?? ''),
                        'aisi' => $ref->aisi_snapshot,
                        'standard' => $ref->standard_snapshot,
                    ]);
                }
            }
        } catch (\Throwable) {
        }

        // 2. Fetch all photos in a SINGLE query and group by product_code (Zero N+1)
        $allPhotosGrouped = LostWaxAssemblyPhoto::query()
            ->orderBy('version')
            ->get()
            ->groupBy('product_code');

        // Also include any valid product_codes in assembly_photos that might not be in masterMap
        foreach ($allPhotosGrouped->keys() as $photoCode) {
            $codeStr = (string) $photoCode;
            if (! self::isInvalidProductCode($codeStr) && ! $masterMap->has($codeStr)) {
                $firstPhoto = $allPhotosGrouped->get($codeStr)?->first();
                $masterMap->put($codeStr, [
                    'code' => $codeStr,
                    'name' => $firstPhoto?->product_name ?? '-',
                    'aisi' => null,
                    'standard' => null,
                ]);
            }
        }

        // 3. Compute statuses & count totals
        $counts = [
            'total' => $masterMap->count(),
            'complete' => 0,
            'incomplete' => 0,
            'none' => 0,
        ];

        $list = $masterMap->values()->map(function ($item) use ($allPhotosGrouped, &$counts) {
            $photos = $allPhotosGrouped->get($item['code'], collect());
            $status = $this->computeStatusForPhotos($photos);

            $item['status'] = $status;
            $item['photos_count'] = $status['photo_count'];
            $item['version'] = $status['version'];
            $item['has_photos'] = $status['status_key'] !== 'none';

            if ($status['status_key'] === 'complete') {
                $counts['complete']++;
            } elseif ($status['status_key'] === 'incomplete') {
                $counts['incomplete']++;
            } else {
                $counts['none']++;
            }

            return $item;
        });

        // 4. Apply search filter
        if ($searchLower !== '') {
            $list = $list->filter(function ($item) use ($searchLower) {
                return str_contains(strtolower($item['code']), $searchLower)
                    || str_contains(strtolower($item['name']), $searchLower)
                    || str_contains(strtolower((string) ($item['aisi'] ?? '')), $searchLower);
            });
        }

        // 5. Apply status filter
        if ($statusFilter !== 'all') {
            $list = $list->filter(function ($item) use ($statusFilter) {
                return $item['status']['status_key'] === $statusFilter;
            });
        }

        // 6. Paginate results
        $totalFiltered = $list->count();
        $offset = ($page - 1) * $perPage;
        $pageItems = $list->slice($offset, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $pageItems,
            $totalFiltered,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );

        return [
            'items' => $paginator,
            'counts' => $counts,
        ];
    }

    /**
     * Compress and store image with max dimensions (1600px) and quality 80 using PHP GD.
     */
    public function compressAndStore(UploadedFile $file, string $targetRelativePath, int $maxDimension = 1600, int $quality = 80): string
    {
        $realPath = $file->getRealPath();
        $sourceData = file_get_contents($realPath);
        if ($sourceData === false) {
            throw new InvalidArgumentException('Gagal membaca file gambar.');
        }

        $image = @imagecreatefromstring($sourceData);

        // Fallbacks for specific formats if imagecreatefromstring fails
        if (! $image) {
            $mime = strtolower((string) $file->getMimeType());
            if (str_contains($mime, 'jpeg') || str_contains($mime, 'jpg')) {
                $image = @imagecreatefromjpeg($realPath);
            } elseif (str_contains($mime, 'png')) {
                $image = @imagecreatefrompng($realPath);
            } elseif (str_contains($mime, 'webp') && function_exists('imagecreatefromwebp')) {
                $image = @imagecreatefromwebp($realPath);
            } elseif (str_contains($mime, 'bmp') && function_exists('imagecreatefrombmp')) {
                $image = @imagecreatefrombmp($realPath);
            }
        }

        if (! $image) {
            throw new InvalidArgumentException('File bukan format gambar yang valid.');
        }

        // Handle EXIF orientation if available (e.g. mobile photo capture)
        if (function_exists('exif_read_data')) {
            try {
                $exif = @exif_read_data($realPath);
                if (! empty($exif['Orientation'])) {
                    switch ($exif['Orientation']) {
                        case 3:
                            $image = imagerotate($image, 180, 0);
                            break;
                        case 6:
                            $image = imagerotate($image, -90, 0);
                            break;
                        case 8:
                            $image = imagerotate($image, 90, 0);
                            break;
                    }
                }
            } catch (\Throwable) {
            }
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // Scale if larger than maxDimension
        if ($width > $maxDimension || $height > $maxDimension) {
            if ($width > $height) {
                $newWidth = $maxDimension;
                $newHeight = (int) round(($height / $width) * $maxDimension);
            } else {
                $newHeight = $maxDimension;
                $newWidth = (int) round(($width / $height) * $maxDimension);
            }

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            // Retain alpha transparency for png/webp
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        // Save to public storage disk
        $fullPath = Storage::disk('public')->path($targetRelativePath);
        $directory = dirname($fullPath);
        if (! file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        // Output as WebP (or JPEG if WebP is unsupported)
        if (function_exists('imagewebp')) {
            imagewebp($image, $fullPath, $quality);
        } else {
            imagejpeg($image, $fullPath, $quality);
        }

        imagedestroy($image);

        return $targetRelativePath;
    }
}
