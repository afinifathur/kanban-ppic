# Phase 5 — Lost Wax Scan Void & Correction Report

**Date:** 2026-08-22  
**Status:** PHASE 5 COMPLETED — ALL TESTS PASS

---

## 1. Files Changed
1. **Model:**
   - [`app/Models/LostWaxTree.php`](file:///C:/laragon/www/kanban-ppic/app/Models/LostWaxTree.php) — Memperbaiki accessor `require_layer_7` dengan prioritas db true, lalu fallback ke legacy workOrder; menambahkan `require_layer_7` ke fillable & casts.
   - [`app/Models/LostWaxScanEvent.php`](file:///C:/laragon/www/kanban-ppic/app/Models/LostWaxScanEvent.php) — Menambahkan relasi `void()` hasOne ke model `LostWaxScanEventVoid` dan helper attribute `is_voided`.
   - [`app/Models/LostWaxScanEventVoid.php`](file:///C:/laragon/www/kanban-ppic/app/Models/LostWaxScanEventVoid.php) (NEW) — Model untuk log logis pembatalan scan.
2. **Service:**
   - [`app/Services/ScanVoidService.php`](file:///C:/laragon/www/kanban-ppic/app/Services/ScanVoidService.php) (NEW) — Bisnis logic void scan event, row-locking (`lockForUpdate`), dan dynamic state reconstruction pada tree.
   - [`app/Services/ScanService.php`](file:///C:/laragon/www/kanban-ppic/app/Services/ScanService.php) — Memfilter voided scan events dari check Oven.
3. **Controller & Route:**
   - [`app/Http/Controllers/LostWax/ScanController.php`](file:///C:/laragon/www/kanban-ppic/app/Http/Controllers/LostWax/ScanController.php) — Menambahkan action `voidEvent` dengan check role authorization Spatie.
   - [`app/Http/Controllers/LostWax/DashboardController.php`](file:///C:/laragon/www/kanban-ppic/app/Http/Controllers/LostWax/DashboardController.php) — Menyaring voided events pada metrik tracking dan aging anomaly.
   - [`app/Http/Controllers/LostWax/ProductionStatusController.php`](file:///C:/laragon/www/kanban-ppic/app/Http/Controllers/LostWax/ProductionStatusController.php) — Menyaring voided events pada status live per tree untuk PPIC.
   - [`routes/web.php`](file:///C:/laragon/www/kanban-ppic/routes/web.php) — Mendaftarkan route POST `/scan-events/{event}/void`.
4. **View:**
   - [`resources/views/lost-wax/trees/history.blade.php`](file:///C:/laragon/www/kanban-ppic/resources/views/lost-wax/trees/history.blade.php) — Menampilkan badge voided (batal), detail alasan/pembuat void, serta tombol "Void Scan" berbasis SweetAlert2.

---

## 2. Business Rules Implemented
- **Latest Event Only Policy:** Hanya event sukses terbaru (`MAX(id)` sukses yang tidak di-void) yang diizinkan untuk di-void. Pembatalan event lampau di tengah antrean ditolak mentah-mentah secara server-side untuk menghindari ketidaksinkronan fisik.
- **Non-Destructive Ledger:** Record scan event asli di `lost_wax_scan_events` tidak pernah dihapus atau dimutasi. Pembatalan dicatat secara append-only di `lost_wax_scan_event_voids`.
- **Oven Safety:** Oven menutup tree lifecycle secara permanen. Event Oven hanya boleh di-void jika merupakan event terbaru. Event sebelum Oven tidak boleh di-void jika status tree sudah Oven.
- **Mandatory Reason:** Setiap pembatalan wajib menyertakan alasan yang di-validate secara server-side (tidak boleh kosong atau hanya whitespace).

---

## 3. Layer 7 Accessor Bug Fixed
Accessor `LostWaxTree::getRequireLayer7Attribute()` diperbaiki agar:
1. Prioritas utama: jika kolom database `require_layer_7` bernilai `true`, kembalikan `true` (authoritative).
2. Fallback: jika legacy tree (`work_order_id !== null`), gunakan `$this->workOrder?->require_layer_7`.
3. Default: kembalikan `false`.
Bug ini sukses diselesaikan tanpa merusak kompatibilitas data/test legacy.

---

## 4. Authorization Spatie
Otorisasi void scan event dikontrol ketat di level server-side:
- Role yang diizinkan: `ppic` dan `admin`.
- Role operator (`operator`) dilarang men-void scan event.

---

## 5. Recalculation & State Reconstruction
Setelah event terbaru berhasil di-void, `current_stage` dan `last_scan_at` pada `LostWaxTree` otomatis dihitung ulang ke event sukses non-voided terakhir yang ada. Jika tidak ada event yang tersisa, status tree dikembalikan ke state awal (`null`).

---

## 6. Concurrency Safety
Menerapkan `lockForUpdate()` pada entitas `LostWaxTree` dan `LostWaxScanEvent` di dalam `DB::transaction()` untuk mencegah operator ganda memicu race-condition pembatalan secara simultan.

---

## 7. Test Results
- **Feature Test File:** [`tests/Feature/LostWax/ScanVoidTest.php`](file:///C:/laragon/www/kanban-ppic/tests/Feature/LostWax/ScanVoidTest.php) (17 test cases komprehensif passed).
- **Full Suite Run:** `composer test`
- **Verdict:** **213 tests passed** (1358 assertions) — *ALL REGRESSION TESTS PASS (100% GREEN)*.

---

## 8. Warning & Limitations
- Logika void tidak menghapus baris fisik tree atau print job; ia murni meluruskan ledger tracking scan pelapisan lilin harian.
