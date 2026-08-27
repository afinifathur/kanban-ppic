<?php

namespace App\Services;

use App\Contracts\ItemMasterRepository;
use App\Models\LostWaxAssemblyPhoto;
use App\Models\LostWaxItemReference;
use App\Models\ProductionPlan;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AssemblyPhotoService
{
    public function __construct(private readonly ItemMasterRepository $itemMasterRepository) {}

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

        if ($cleanedCode !== '') {
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
        return LostWaxAssemblyPhoto::with('uploader')
            ->where('product_code', trim($productCode))
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
        if ($productCode === '') {
            throw new InvalidArgumentException('Product Code tidak boleh kosong.');
        }

        $current = $this->getCurrentPhoto($productCode);

        if (! $frontFile && ! $sideFile && ! $current) {
            throw new InvalidArgumentException('Minimal upload salah satu foto (Depan atau Samping).');
        }

        $nextVersion = $current ? ($current->version + 1) : 1;
        $cleanSlug = Str::slug($productCode, '_');
        $randomSuffix = Str::lower(Str::random(6));

        // Process Front Photo
        $frontPath = $current?->front_image_path;
        if ($frontFile && $frontFile->isValid()) {
            $frontTarget = "assembly_photos/{$cleanSlug}_v{$nextVersion}_front_{$randomSuffix}.webp";
            $frontPath = $this->compressAndStore($frontFile, $frontTarget);
        }

        // Process Side Photo
        $sidePath = $current?->side_image_path;
        if ($sideFile && $sideFile->isValid()) {
            $sideTarget = "assembly_photos/{$cleanSlug}_v{$nextVersion}_side_{$randomSuffix}.webp";
            $sidePath = $this->compressAndStore($sideFile, $sideTarget);
        }

        return DB::transaction(function () use ($productCode, $productName, $nextVersion, $frontPath, $sidePath, $user, $notes) {
            // Demote existing active versions for this product code
            LostWaxAssemblyPhoto::where('product_code', $productCode)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            return LostWaxAssemblyPhoto::create([
                'product_code' => $productCode,
                'product_name' => $productName ? trim($productName) : null,
                'version' => $nextVersion,
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
     */
    public function searchProducts(string $query, int $limit = 20): Collection
    {
        $queryLower = strtolower(trim($query));
        $results = collect();

        // 1. Try ItemMasterRepository
        try {
            $masterItems = $this->itemMasterRepository->allActive();
            foreach ($masterItems as $item) {
                $code = (string) ($item['code'] ?? '');
                $name = (string) ($item['name'] ?? '');

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
                if ($code !== '' && ! $results->has($code)) {
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

        // 3. Supplement from ProductionPlan
        try {
            $plans = ProductionPlan::query()
                ->select('item_code', 'item_name', 'aisi')
                ->whereNotNull('item_code')
                ->where('item_code', '!=', '')
                ->when($queryLower !== '', function ($q) use ($queryLower) {
                    $q->where(DB::raw('LOWER(item_code)'), 'like', "%{$queryLower}%")
                        ->orWhere(DB::raw('LOWER(item_name)'), 'like', "%{$queryLower}%");
                })
                ->distinct()
                ->get();

            foreach ($plans as $plan) {
                $code = (string) $plan->item_code;
                if ($code !== '' && ! $results->has($code)) {
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
