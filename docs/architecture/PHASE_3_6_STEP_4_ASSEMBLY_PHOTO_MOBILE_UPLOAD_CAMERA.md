# PHASE 3.6 STEP 4 — ASSEMBLY PHOTO MOBILE UPLOAD & CAMERA SUPPORT

## 1. Overview & Problem Analysis

### Problem Encountered on Mobile UAT
When uploading photos via mobile phone on `/settings/assembly-photos/index` (or `/settings/assembly-photos`), users encountered:
```
"File bukan format gambar yang valid."
```

### Root Cause
1. **Source of Exception**: `AssemblyPhotoService::compressAndStore()` (line 480).
2. **Failure Mechanism**:
   - Phone cameras (especially modern Android 48MP/108MP and iOS devices) produce uncompressed photos ranging from 12MB to 25MB, exceeding the previous 10MB limit (`max:10240`).
   - Phone camera images or gallery photos in HEIC format or containing custom OEM EXIF headers caused `@imagecreatefromstring()` in PHP GD to return `false`.
   - The view had only a single hidden input with `accept="image/jpeg,image/png,image/webp"` and **no** `capture="environment"` attribute, preventing the browser from launching the device camera directly.

---

## 2. Implemented Solution

### A. Dual Action Controls (Camera & Gallery)
Both **Tampak Depan (Front View)** and **Tampak Samping (Side View)** now offer distinct, touch-friendly buttons:
- **`[ 📷 Ambil Foto ]`**: Opens the device's back camera directly via HTML5 `<input type="file" accept="image/*" capture="environment">`.
- **`[ 🖼 Pilih Galeri ]`**: Opens the native image/file library picker via `<input type="file" accept="image/jpeg,image/png,image/webp,image/*">`.
- Synchronized seamlessly via JavaScript with live image preview and file size badge.

### B. Controller Validation Upgrade
- Updated `AssemblyPhotoController::store()` validation:
  ```php
  'front_photo' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:20480',
  'side_photo' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:20480',
  ```
- Permits raw mobile camera captures up to 20MB, which are then compressed down to ~1600px WebP images (~100-300KB) by PHP GD.

### C. Image Decoder Fallback
- `AssemblyPhotoService::compressAndStore()` now uses fallback decoders (`imagecreatefromjpeg`, `imagecreatefrompng`, `imagecreatefromwebp`, `imagecreatefrombmp`) if `imagecreatefromstring()` fails on specific image streams.

---

## 3. Files Changed

1. **`app/Http/Controllers/LostWax/AssemblyPhotoController.php`**
   - Increased validation file size limit from `10240` to `20480` KB (20MB).
2. **`app/Services/AssemblyPhotoService.php`**
   - Added image decoder fallback mechanisms.
3. **`resources/views/settings/assembly-photos/index.blade.php`**
   - Added dual Camera and Gallery action buttons, environment camera capture attributes, and JavaScript file synchronization.
4. **`tests/Feature/LostWax/AssemblyPhotoTest.php`**
   - Added tests for camera capture HTML attributes, WebP/PNG support, large file uploads (15MB), oversized rejections (>20MB), and invalid file rejection.
5. **`docs/architecture/PHASE_3_6_STEP_4_ASSEMBLY_PHOTO_MOBILE_UPLOAD_CAMERA.md`** *(New)*
   - Architecture documentation.

---

## 4. Test Results & Verification

- `php artisan test --filter=AssemblyPhotoTest`: **19 passed** (102 assertions)
- `php artisan test`: **606 passed** (3157 assertions)
- `vendor/bin/pint --test`: **PASS** (206 files)
