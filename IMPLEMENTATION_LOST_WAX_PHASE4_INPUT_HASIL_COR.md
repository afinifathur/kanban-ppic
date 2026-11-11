# LAPORAN IMPLEMENTASI LOST WAX PHASE 4 — INPUT HASIL COR

Dokumen ini mencatat seluruh implementasi teknis untuk fitur **Input Hasil Cor** pada modul Lost Wax di repositori `C:\laragon\www\kanban-ppic`.

---

## 1. File Baru dan File yang Diubah

### A. File Baru (Created)
1. **Migration:**
   - `database/migrations/2026_11_11_100000_create_lost_wax_casting_results_tables.php`
2. **Models:**
   - `app/Models/LostWaxCastingResult.php`
   - `app/Models/LostWaxCastingResultTree.php`
3. **Domain Service:**
   - `app/Services/LostWax/LostWaxCastingResultService.php`
4. **Controller:**
   - `app/Http/Controllers/LostWax/LostWaxCastingResultController.php`
5. **Views (Blade):**
   - `resources/views/lost-wax/casting-results/index.blade.php` (Riwayat Transaksi Hasil Cor)
   - `resources/views/lost-wax/casting-results/create.blade.php` (Form Input & Checklist FIFO)
   - `resources/views/lost-wax/casting-results/show.blade.php` (Detail Transaksi & Pembatalan)
   - `resources/views/lost-wax/casting-results/print.blade.php` (Dokumen Cetak A4 Portrait)
6. **Automated Feature Test:**
   - `tests/Feature/LostWax/LostWaxCastingResultTest.php` (20 skenario pengujian lengkap)
7. **Dokumentasi:**
   - `IMPLEMENTATION_LOST_WAX_PHASE4_INPUT_HASIL_COR.md`

### B. File yang Diubah (Modified)
1. `app/Models/LostWaxTree.php`:
   - Menambahkan relasi `castingResultTrees()`, `castingResultTree()`, dan `activeCastingResultTree()`.
2. `app/Services/LostWax/ReadyForCastingService.php`:
   - Menambahkan filter pengecualian konsumsi aktif (`whereDoesntHave('castingResultTrees', fn($q) => $q->whereHas('castingResult', fn($hq) => $hq->where('status', 'COMPLETED')))`).
3. `routes/web.php`:
   - Menambahkan resource routes untuk `lost-wax.casting-results.*` di bawah proteksi permission `access_planning|access_execution`.
4. `resources/views/layouts/app.blade.php`:
   - Menambahkan menu sidebar **Input Hasil Cor** dengan icon `fa-fire` tepat setelah menu **Barang Siap Cor**.

---

## 2. Struktur Migration Aktual

Dibuat migration idempotent: `2026_11_11_100000_create_lost_wax_casting_results_tables.php`

### Tabel Header: `lost_wax_casting_results`
```sql
CREATE TABLE lost_wax_casting_results (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    heat_number VARCHAR(255) NOT NULL,
    cast_date DATE NOT NULL,
    furnace VARCHAR(255) NULL,
    shift VARCHAR(255) NULL,
    operator_name VARCHAR(255) NULL,
    total_trees INT UNSIGNED NOT NULL DEFAULT 0,
    total_pcs INT UNSIGNED NOT NULL DEFAULT 0,
    notes TEXT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'COMPLETED',
    recorded_by BIGINT UNSIGNED NOT NULL,
    voided_by BIGINT UNSIGNED NULL,
    voided_at TIMESTAMP NULL,
    void_reason TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX (heat_number),
    INDEX (cast_date),
    INDEX (status),
    CONSTRAINT fk_lw_cr_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id),
    CONSTRAINT fk_lw_cr_voided_by FOREIGN KEY (voided_by) REFERENCES users (id)
);
```

### Tabel Detail: `lost_wax_casting_result_trees`
```sql
CREATE TABLE lost_wax_casting_result_trees (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lost_wax_casting_result_id BIGINT UNSIGNED NOT NULL,
    lost_wax_tree_id BIGINT UNSIGNED NOT NULL,
    active_tree_id BIGINT UNSIGNED NULL,
    barcode VARCHAR(255) NOT NULL,
    production_code VARCHAR(255) NOT NULL,
    customer VARCHAR(255) NULL,
    item_name VARCHAR(255) NULL,
    quantity_pcs INT UNSIGNED NOT NULL,
    gross_pcs INT UNSIGNED NOT NULL DEFAULT 0,
    defect_pcs INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX (barcode),
    INDEX (production_code),
    UNIQUE KEY uk_lw_crt_active_tree (active_tree_id),
    CONSTRAINT fk_lw_crt_result FOREIGN KEY (lost_wax_casting_result_id) REFERENCES lost_wax_casting_results (id) ON DELETE CASCADE,
    CONSTRAINT fk_lw_crt_tree FOREIGN KEY (lost_wax_tree_id) REFERENCES lost_wax_trees (id),
    CONSTRAINT fk_lw_crt_active_tree FOREIGN KEY (active_tree_id) REFERENCES lost_wax_trees (id) ON DELETE SET NULL
);
```

---

## 3. Relasi Model

- `LostWaxCastingResult`:
  - `hasMany(LostWaxCastingResultTree::class)` (`trees()`)
  - `belongsTo(User::class, 'recorded_by')` (`recorder()`)
  - `belongsTo(User::class, 'voided_by')` (`voider()`)
- `LostWaxCastingResultTree`:
  - `belongsTo(LostWaxCastingResult::class, 'lost_wax_casting_result_id')` (`castingResult()`)
  - `belongsTo(LostWaxTree::class, 'lost_wax_tree_id')` (`tree()`)
- `LostWaxTree`:
  - `hasMany(LostWaxCastingResultTree::class, 'lost_wax_tree_id')` (`castingResultTrees()` — mencakup seluruh histori termasuk transaksi yang dibatalkan)
  - `hasOne(LostWaxCastingResultTree::class, 'lost_wax_tree_id')->whereHas('castingResult', fn($q) => $q->where('status', 'COMPLETED'))` (`castingResultTree()` — mengarah pada transaksi aktif)
  - `hasOne(LostWaxCastingResultTree::class, 'active_tree_id')` (`activeCastingResultTree()`)

---

## 4. Cara Kerja Checklist

- Menggunakan `ReadyForCastingService::getQueueData()` sebagai *single source of truth* WIP.
- Antrean disajikan dalam urutan FIFO murni berdasarkan timestamp scan siap cor.
- Fitur live filtering di sisi browser memudahkan pencarian instan (barcode, kode produksi, customer, nama item).
- Tombol **Pilih Semua (Terlihat)** hanya memilih baris yang saat ini tampil di layar, mencegah seleksi pohon yang tersembunyi karena filter.
- Dynamic Counter menghitung total pohon dan total PCS yang terpilih secara real-time.
- Tombol **Simpan Hasil Cor** otomatis dinonaktifkan (disabled) jika belum ada pohon yang dipilih.
- Konfirmasi sebelum submit menampilkan Heat Number, total pohon, dan total PCS secara jelas.

---

## 5. Cara Kerja Transaksi Atomik dan Locking

Proses penyimpanan dan pembatalan dibungkus dalam `DB::transaction()`:
1. Mengunci baris kandidat `LostWaxTree` menggunakan `lockForUpdate()`.
2. Validasi ulang status setiap pohon secara independen di server:
   - Status pohon bukan `cancelled`.
   - `current_stage == 'siap_cor'`.
   - Memiliki riwayat event Scan Siap Cor sukses yang belum di-void.
   - Belum dikonsumsi oleh transaksi aktif lain.
3. Menghitung ulang `usable_quantity` dari database (`usable_quantity = max(0, quantity - total_defect_quantity)`). Kuantitas dari browser diabaikan.
4. Mengambil snapshot metadata aktual (barcode, kode produksi, customer, nama item).
5. Menyimpan header `LostWaxCastingResult` (`status = 'COMPLETED'`).
6. Menyimpan seluruh baris detail `LostWaxCastingResultTree` dengan `active_tree_id = $tree->id`.
7. Memperbarui stage pohon: `current_stage = 'cor'`.
8. Jika ada satu pohon saja yang gagal atau berubah status saat proses berlangsung, transaksi di-rollback secara penuh.

---

## 6. Cara Mencegah Konsumsi Ganda

Pencegahan konsumsi ganda dijamin melalui strategi **Double-Layer Protection**:
1. **Layer Aplikasi (Pessimistic Lock & Service Query):**
   - Menggunakan `lockForUpdate()` pada entitas `LostWaxTree`.
   - Melakukan pengecekan `where('active_tree_id', $tree->id)->exists()`.
2. **Layer Storage Engine Database (Native UNIQUE Index):**
   - Kolom `active_tree_id` pada tabel detail memiliki constraint `UNIQUE KEY uk_lw_crt_active_tree (active_tree_id)`.
   - Jika terjadi dua request concurrent (race condition) yang mencoba mengonsumsi pohon yang sama, database engine (MySQL InnoDB / SQLite) langsung menolak request kedua dengan `Integrity constraint violation (1062 Duplicate entry / UNIQUE constraint failed)`.

---

## 7. Cara Pembatalan dan Penggunaan Ulang Traveler Card

Sesuai ketentuan teknis, tidak digunakan *hard delete*:
1. Transaksi dibatalkan secara atomik menggunakan `cancelCastingResult()`:
   - Header dikunci dengan `lockForUpdate()`.
   - Status header diubah menjadi `CANCELLED`.
   - Mencatat `voided_by`, `voided_at`, dan `void_reason`.
2. **Pelepasan Constraint:**
   - Kolom `active_tree_id` pada seluruh detail baris transaksi tersebut di-update menjadi `NULL`.
   - Mengikuti standar SQL, multiple `NULL` diizinkan dalam kolom ber-indeks `UNIQUE`.
   - Seluruh data snapshot detail transaksi lama (`lost_wax_tree_id`, `barcode`, `production_code`, `quantity_pcs`, dll) **tetap tersimpan utuh dan permanen** untuk keperluan audit trail.
3. **Pengembalian WIP:**
   - Status pohon dikembalikan: `current_stage = 'siap_cor'`.
   - Pohon otomatis muncul kembali di antrean Barang Siap Cor dan dapat dicor ke Heat Number lain.

---

## 8. Integrasi dengan Barang Siap Cor (Phase 3)

- `ReadyForCastingService` diperbarui dengan menambahkan klausa:
  ```php
  ->whereDoesntHave('castingResultTrees', function ($q) {
      $q->whereHas('castingResult', function ($hq) {
          $hq->where('status', 'COMPLETED');
      });
  })
  ```
- Dampak langsung:
  - Pohon yang disimpan pada Hasil Cor langsung keluar dari antrean web, dokumen cetak, dan KPI summary Barang Siap Cor.
  - Pohon dari transaksi yang dibatalkan langsung muncul kembali ke antrean Barang Siap Cor tanpa perlu scan ulang.
  - Alur `ScanSiapCorService`, `ScanService`, dan `ScanVoidService` tetap 100% utuh tanpa modifikasi.

---

## 9. Desain Dokumen Cetak

Halaman cetak: `/lost-wax/casting-results/{id}/print`
- Ukuran: A4 Portrait sesuai CSS cetak presisi.
- Kop:
  **PT. PERONI KARYA SENTRA • DIVISI FOUNDRY & LOST WAX**
  **BUKTI PENCATATAN HASIL COR — LOST WAX**
- Menampilkan nomor transaksi format: `LW-COR-YYYYMMDD-ID`.
- Menampilkan Heat Number, Tanggal Cor, Tungku, Shift, Operator, Waktu Input, dan Admin.
- Tabel rincian Traveler Card snapshot: Barcode, Kode Produksi, Customer, Nama Item, Qty Siap Cor (PCS).
- Ringkasan total pohon dan total PCS.
- Kolom tanda tangan 3 pihak:
  1. Dibuat Oleh (Admin PPIC)
  2. Dituang Oleh (Operator Cor)
  3. Diterima Oleh (Netto / Finishing)
- Jika transaksi berstatus `CANCELLED`, dokumen otomatis menampilkan watermark / banner bertuliskan:
  `*** DOKUMEN INI TELAH DIBATALKAN (VOID) ***` lengkap dengan alasan dan waktu pembatalan.

---

## 10. Sidebar dan Permission

- Menu **Input Hasil Cor** ditambahkan pada sidebar `resources/views/layouts/app.blade.php` tepat setelah **Barang Siap Cor**.
- Ikon: `fa-fire`
- Active state: `request()->routeIs('lost-wax.casting-results.*')`
- Route dilindungi dengan middleware:
  `permission:access_planning|access_execution`
- Seluruh role yang berwenang (`admin`, `ppic`, `spv`, `admin_qc_fitting`) dapat mengakses menu ini secara penuh, sedangkan role tanpa izin (misal `sales`) ditolak dengan HTTP 403 Forbidden.

---

## 11. Penyempurnaan Desain Antarmuka (UI/UX Refinement)

Berdasarkan evaluasi visual, antarmuka disempurnakan mengadopsi konsep **Industrial Modern — Neutral Grey, Navy & Orange**:
- **Background Utama:** Neutral grey sangat muda (`#F3F4F6` / `bg-slate-50`).
- **Surface / Cards:** Putih bersih (`#FFFFFF` / `bg-white`) dengan border tipis (`border-slate-200`) dan shadow ringan (`shadow-sm`).
- **Heading:** Charcoal / Navy (`#1E293B` / `text-slate-900`) untuk kontras tajam dan keterbacaan tinggi.
- **Teks Utama:** Abu-abu gelap (`#374151` / `text-slate-700`).
- **Teks Sekunder:** Abu-abu sedang (`#6B7280` / `text-slate-500`).
- **Aksi Utama (Primary):** Orange Lost Wax (`#F59E0B` / `bg-amber-500 hover:bg-amber-600 text-white`).
- **Aksi Sekunder (Secondary):** Navy (`bg-slate-800 hover:bg-slate-900 text-white`) atau tombol netral (`bg-white border border-slate-300 text-slate-700`).
- **Kartu KPI:** Didesain ulang dari kartu gelap menjadi kartu putih bersih dengan angka bold charcoal, label terstruktur, dan aksen icon lembut (Emerald, Amber, Blue, Slate).
- **Tabel Transaksi & Form:** Thead berwarna abu-abu muda (`bg-slate-50`), divider tipis (`divide-slate-100`), badge status lembut (`bg-emerald-50 text-emerald-700 border-emerald-200` untuk SELESAI, `bg-rose-50 text-rose-700 border-rose-200` untuk DIBATALKAN).
- **Fungsi Aplikasi:** 100% dipertahankan tanpa perubahan logika bisnis.

---

## 12. Hasil Automated Tests

Pengujian fitur Phase 4 (`tests/Feature/LostWax/LostWaxCastingResultTest.php`):
```
PASS  Tests\Feature\LostWax\LostWaxCastingResultTest
✓ 01 checklist displays only valid ready for casting wip
✓ 02 heat number is required
✓ 03 one heat number can accept multiple traveler cards
✓ 04 one traveler card cannot be consumed twice
✓ 05 database unique constraint blocks concurrent active consumption
✓ 06 quantity snapshot uses usable quantity accurately
✓ 07 total pcs is calculated strictly server side
✓ 08 multi allocation tree does not duplicate tree or pcs
✓ 09 header and details storage is atomic
✓ 10 failure in one tree rolls back entire transaction
✓ 11 saved trees are immediately removed from ready for casting wip
✓ 12 unselected trees remain in ready for casting wip
✓ 13 cancelled transaction returns trees to siap cor wip
✓ 14 old transaction detail history is preserved after void
✓ 15 tree from cancelled transaction can be reused in new heat
✓ 16 two active transactions cannot consume same tree
✓ 17 print document uses stored snapshots
✓ 18 reprinting document does not alter wip
✓ 19 role permission and access control
✓ 20 cancelled transaction displays cancelled status clearly

Tests:    20 passed (60 assertions)
Duration: 2.98s
```

### Hasil Uji Regresi Lengkap:
| Test Suite | Jumlah Test | Assertions | Status |
| :--- | :---: | :---: | :---: |
| `LostWaxCastingResultTest` (Phase 4) | 20 | 60 | **PASS** |
| `ReadyForCastingReportTest` (Phase 3) | 15 | 56 | **PASS** |
| `ScanSiapCorTest` (Phase 2) | 17 | 73 | **PASS** |
| `ReadyForOvenPhase1Test` (Phase 1.1 - 1.3) | 44 | 354 | **PASS** |
| `ReadyForOvenReportTest` (Baseline) | 19 | 97 | **PASS** |
| **Total** | **115** | **640** | **100% PASS** |

---

## 12. Hasil Formatter

Eksekusi `vendor/bin/pint` dijalankan pada repositori dan memastikan seluruh file baru serta file yang dimodifikasi telah sesuai dengan standar kode Laravel Pint.

---

## 13. Konfirmasi Pengamanan Lingkungan & Data

- Migration `2026_11_11_100000_create_lost_wax_casting_results_tables.php` **hanya dijalankan pada database development lokal** (`kanban-ppic` di Laragon MySQL).
- **TIDAK ADA** perintah `migrate:fresh`, `db:wipe`, atau perintah destruktif data lainnya yang dijalankan.
- **TIDAK ADA** akses ke server produksi, network staging, maupun remote database.
- **TIDAK ADA** eksekusi `git push` ke repositori remote.

---

## 14. Risiko dan Keterbatasan yang Tersisa

1. **Proses Lanjutan (Netto / QC):** Sesuai batasan Phase 4, sistem belum mencakup input defect pasca-penuangan (cacat cor seperti misrun, porosity, retak). Cacat fisik cor akan ditangani pada modul berikutnya (Netto/Finishing).
2. **Konektivitas Printer Lapangan:** Dokumen Bukti Hasil Cor menggunakan rendering browser standar (CSS `@media print` A4 portrait). Operator disarankan memastikan skala cetak browser disetel ke 100% atau "Fit to Page" agar hasil cetak pas pada satu lembar A4.

