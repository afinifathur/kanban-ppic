# ASSEMBLY PHOTO MASTER DATA & STORAGE FIX IMPLEMENTATION REPORT

**Date:** 2026-08-29  
**Application:** Laravel 12 — FIFO Tracking / Kanban PPIC (Lost Wax Subsystem)  
**Status:** IMPLEMENTED & VERIFIED  

---

## 1. EXECUTIVE SUMMARY

Implementasi perbaikan master data foto rangkai (`/settings/assembly-photos`) dan penambahan halaman audit (`/settings/assembly-photos/index`) telah selesai dilakukan secara presisi tanpa modifikasi skema tabel DDL baru dan tanpa menghapus data historis.

---

## 2. ROOT CAUSE RESOLUTION & CODE CHANGES

### A. Product Identity Fix (`ItemMasterRepository` Priority & Placeholder Filtering)
- **Problem:** Entri kotor dari `production_plans` yang memiliki `item_code = 'XX'` diekspos oleh `searchProducts()` sebagai identitas produk `XX — Nama Produk`.
- **Solution:**
  1. Menjadikan `md_items` (`ItemMasterRepository`) sebagai *source of truth* utama.
  2. Menambahkan metode filter statis [`AssemblyPhotoService::isInvalidProductCode()`](file:///c:/laragon/www/kanban-ppic/app/Services/AssemblyPhotoService.php) untuk memblokir placeholder (`XX`, `X09`, `XXX`, `-`, `NULL`, `NONE`, dll.).
  3. Memblokir seluruh rute penyimpanan (`storePhoto()` dan `AssemblyPhotoController::store()`) dari upaya menyimpan `product_code` berupa placeholder.
  4. Tidak ada automatic cleanup atau penghapusan paksa terhadap data historis.

### B. Storage & Symlink Resolution
- **Problem:** Folder [`public/storage`](file:///c:/laragon/www/kanban-ppic/public/storage) di direktori publik sebelumnya berstatus folder fisik biasa (berisi file `.gitignore`), bukan symbolic link ke `storage/app/public`. Akibatnya web server mengembalikan HTTP 404 ketika browser meminta file `storage/assembly_photos/...`.
- **Solution:**
  1. Folder dummy `public/storage` dihapus dan dihubungkan ulang melalui `php artisan storage:link` (NTFS Junction / Symlink ke `storage/app/public`).
  2. File yang diunggah melalui `Storage::disk('public')` diverifikasi dapat dibaca langsung melalui path publik dan dikonversi ke WebP 80% (maks. 1600px).

### C. Audit Master Foto Index Page (`/settings/assembly-photos/index`)
- **Route:** `GET /settings/assembly-photos/index` (`settings.assembly-photos.audit`).
- **Controller:** [`AssemblyPhotoController::auditIndex()`](file:///c:/laragon/www/kanban-ppic/app/Http/Controllers/LostWax/AssemblyPhotoController.php).
- **View:** [`resources/views/settings/assembly-photos/audit.blade.php`](file:///c:/laragon/www/kanban-ppic/resources/views/settings/assembly-photos/audit.blade.php).
- **Navigation:** Menambahkan tombol `[ Daftar Master Foto ]` di kanan atas halaman kelola foto, serta tombol `[ Upload / Kelola Foto ]` pada halaman audit.
- **Tabel & Kolom:**
  - `No`
  - `Kode Item` (Badge Monospace)
  - `Nama Item` (+ Info AISI & Standard)
  - `Status Foto` (Deterministic badge: `FOTO TERSEDIA v.N`, `INCOMPLETE v.N`, `BELUM ADA`)
  - `Aksi` $\to$ Tombol `[ Kelola ]` mengarah langsung ke form kelola foto produk terkait.
- **Filter & Search:** Filter pencarian teks (`q`), filter status (`Semua`, `Sudah Lengkap`, `Incomplete`, `Belum Ada`), kartu ringkasan metrik, dan pagination.
- **Zero $N+1$ Performance:** Seluruh foto dimuat dalam **1 single database query** (`LostWaxAssemblyPhoto::all()->groupBy('product_code')`) dan dipetakan di memory terhadap master produk.

---

## 3. FILES CHANGED

1. [`app/Services/AssemblyPhotoService.php`](file:///c:/laragon/www/kanban-ppic/app/Services/AssemblyPhotoService.php)
   - Ditambahkan: `isInvalidProductCode()`
   - Diperbarui: `searchProducts()` dengan filter ketat placeholder
   - Diperbarui: `storePhoto()` dengan validasi placeholder dan penyelesaian versi incomplete
   - Ditambahkan: `computeStatusForPhotos()`
   - Ditambahkan: `getAuditList()` dengan single-query eager mapping
2. [`app/Http/Controllers/LostWax/AssemblyPhotoController.php`](file:///c:/laragon/www/kanban-ppic/app/Http/Controllers/LostWax/AssemblyPhotoController.php)
   - Ditambahkan: `auditIndex()`
   - Ditambahkan: validasi error response terhadap placeholder product code
3. [`routes/web.php`](file:///c:/laragon/www/kanban-ppic/routes/web.php)
   - Ditambahkan rute `settings.assembly-photos.audit` pada `/settings/assembly-photos/index`
4. [`resources/views/settings/assembly-photos/index.blade.php`](file:///c:/laragon/www/kanban-ppic/resources/views/settings/assembly-photos/index.blade.php)
   - Ditambahkan tombol `[ Daftar Master Foto ]` pada header
5. [`resources/views/settings/assembly-photos/audit.blade.php`](file:///c:/laragon/www/kanban-ppic/resources/views/settings/assembly-photos/audit.blade.php) *(Baru)*
   - Template view audit status foto perakitan
6. [`tests/Feature/LostWax/AssemblyPhotoTest.php`](file:///c:/laragon/www/kanban-ppic/tests/Feature/LostWax/AssemblyPhotoTest.php)
   - Ditambahkan 14 unit & feature test komprehensif

---

## 4. VERIFICATION & TEST RESULTS

### A. Automated Test Suite
```text
   PASS  Tests\Feature\LostWax\AssemblyPhotoTest
  ✓ assembly photos page can be accessed by authorized user                                                      0.71s  
  ✓ unauthenticated user cannot access assembly photos page                                                      0.04s  
  ✓ product search endpoint returns matching products                                                            0.04s  
  ✓ invalid placeholder xx is filtered from search and not displayed as product identity                         0.04s  
  ✓ master item is primary product identity                                                                      0.03s  
  ✓ future upload cannot save placeholder codes like xx                                                          0.05s  
  ✓ upload both front and side photos creates version 1                                                          0.10s  
  ✓ replacing photo creates new version and preserves history                                                    0.17s  
  ✓ version semantics and incomplete status calculation                                                          0.14s  
  ✓ audit index page loads and displays deterministic status                                                     0.11s  
  ✓ audit index runs without n plus one queries                                                                  0.11s  
  ✓ image is compressed and stored                                                                               0.34s  
  ✓ traveler print page renders current front and side photos by product code                                    0.09s  
  ✓ detail endpoint returns current and history json                                                             0.06s  

  Tests:    14 passed (78 assertions)
  Duration: 2.36s
```

### B. Full Test Suite (`php artisan test`)
```text
  Tests:    521 passed (2851 assertions)
  Duration: 24.99s
```

### C. Code Style (`vendor/bin/pint --test`)
```text
  PASS ................................................................. 196 files
```

---

## 5. QUERY PERFORMANCE & REMAINING HISTORICAL RECORDS

1. **Query Performance (Zero $N+1$):**
   - Halaman audit `/settings/assembly-photos/index` hanya mengeksekusi jumlah query konstan ($\le 5$ query database untuk session, auth, master data, dan 1 query agregat `lost_wax_assembly_photos`).
2. **Storage Link:**
   - Link `public/storage` telah terhubung langsung ke `storage/app/public`.
3. **Historical XX Records in Database:**
   - Database saat ini memiliki **0 record** `product_code = 'XX'` di tabel `lost_wax_assembly_photos`.
   - Tidak ada mutasi atau penghapusan data pada tabel `production_plans` / data historis lainnya.
