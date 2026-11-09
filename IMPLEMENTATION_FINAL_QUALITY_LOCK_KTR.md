# LAPORAN IMPLEMENTASI TEKNIS
# FINAL QUALITY LOCK KTR — SAND CASTING

**Aplikasi:** Kanban PPIC (PT Peroni Karya Sentosa)  
**Repository:** `C:\laragon\www\kanban-ppic`  
**Tanggal Implementasi:** 06 November 2026  
**Dokumen Acuan:** `AUDIT_FINAL_QUALITY_LOCK_KTR.md`  
**Status Eksekusi:** SELESAI & TERVERIFIKASI (Lokal Laragon)

---

## 1. RINGKASAN IMPLEMENTASI

Fitur **Final Quality Lock KTR** pada alur produksi Sand Casting telah berhasil diimplementasikan sesuai dengan seluruh aturan bisnis yang ditetapkan:

1. **Penguncian Kualitas Hulu Pasca-Gudang Jadi:**  
   Ketika SPV Gudang Jadi menekan tombol **"SELESAI"** pada scanner Gudang Jadi (`/sand-casting/scan/gudang-jadi`), proses fisik KTR dinyatakan selesai (`current_stage = 'completed'`). Seluruh mutasi kuantitas defect (pencatatan awal, penambahan, koreksi positif/negatif, simpan draft QC, dan verifikasi QC) pada 5 tahap hulu:
   - `NETTO`
   - `BUBUT_OD`
   - `BUBUT_CNC`
   - `BOR`
   - `QC`  
   **DITOLAK SECARA PERMANEN** di tingkat backend database row-locked transaction.
2. **Pengecualian Mutu Tahap Gudang Jadi:**  
   Tahap `GUDANG_JADI` tetap diizinkan untuk mencatat, menambah, dan mengoreksi defect reject fisik gudang meskipun KTR telah berstatus selesai.
3. **Granularitas Per-KTR:**  
   Penguncian berlaku murni per-KTR (`traveler_number`), bukan per-Heat Number. KTR lain yang masih berjalan dalam batch/heat yang sama tetap dapat diproses dan dimutasi secara normal.
4. **Tanpa Migrasi Database:**  
   Implementasi menggunakan sumber kebenaran status existing:
   - `sand_casting_casting_result_lines.current_stage === 'completed'`
   - Atau keberadaan record pada `sand_casting_stage_executions` dengan `checkpoint_code === 'GUDANG_RECEIVE'` dan `physical_done_at IS NOT NULL`.

---

## 2. DAFTAR FILE YANG DIUBAH / DIBUAT

| No | Berkas | Tipe | Perubahan Utama |
|---|---|---|---|
| 1 | [`app/Services/SandCasting/SandCastingStageExecutionService.php`](file:///c:/laragon/www/kanban-ppic/app/Services/SandCasting/SandCastingStageExecutionService.php) | Backend Service | Menambahkan guard terpusat `assertStageQualityNotLocked()` dan helper `isStageQualityLocked()`. Menerapkan pemanggilan guard di dalam transaksi row-lock pada `recordDefectQty()`, `adjustDefectQty()`, `saveQcBreakdownDraft()`, dan `verifyQcBreakdown()`. |
| 2 | [`app/Services/SandCasting/SandCastingProductionFloorQueryService.php`](file:///c:/laragon/www/kanban-ppic/app/Services/SandCasting/SandCastingProductionFloorQueryService.php) | Backend Query DTO | Menambahkan atribut boolean `'is_quality_locked'` pada method `formatDefectRecordingCard()` dan `formatQcVerificationCard()`. |
| 3 | [`resources/views/sand-casting/defects/index.blade.php`](file:///c:/laragon/www/kanban-ppic/resources/views/sand-casting/defects/index.blade.php) | Frontend Blade & JS | Mengganti tombol aksi aktif dengan badge disabled `<span class="..."><i class="fas fa-lock"></i> TERKUNCI</span>` pada card terkunci. Menambahkan proteksi pencegahan buka modal pada fungsi JS `openDefectModal()` dan `openAddDefectModal()`. |
| 4 | [`resources/views/sand-casting/qc-defects/index.blade.php`](file:///c:/laragon/www/kanban-ppic/resources/views/sand-casting/qc-defects/index.blade.php) | Frontend Blade & JS | Mengganti tombol aksi draft/verifikasi QC dengan badge disabled `TERKUNCI` jika KTR telah selesai Gudang Jadi. Menambahkan proteksi pembukaan modal pada fungsi JS `openQcModal()`. |
| 5 | [`tests/Feature/SandCasting/FinalQualityLockTest.php`](file:///c:/laragon/www/kanban-ppic/tests/Feature/SandCasting/FinalQualityLockTest.php) | Automated Test Suite | Test suite komprehensif menguji skenario TC-01 s/d TC-11 (11 tests, 71 assertions, 100% PASS). |
| 6 | [`tests/Feature/SandCasting/DefectAdjustmentTest.php`](file:///c:/laragon/www/kanban-ppic/tests/Feature/SandCasting/DefectAdjustmentTest.php) | Existing Test Suite | Menyesuaikan urutan setup `test_14` agar koreksi defect Netto diselesaikan sebelum KTR difinalisasi ke Gudang Jadi (18 tests, 100% PASS). |

---

## 3. PENJELASAN GUARD FINAL QUALITY LOCK

Guard terpusat diimplementasikan pada `SandCastingStageExecutionService`:

```php
/**
 * Final Quality Lock Guard:
 * When a KTR is completed in Gudang Jadi (current_stage === 'completed' or has GUDANG_RECEIVE execution with physical_done_at),
 * defect mutations on prior stages (netto, bubut_od, bubut_cnc, bor, qc) are permanently locked.
 * Exception: stage 'gudang_jadi' remains editable.
 *
 * @throws InvalidArgumentException
 */
public function assertStageQualityNotLocked(
    SandCastingStageExecution $execution,
    ?SandCastingCastingResultLine $line = null
): void {
    $canonicalStage = SandCastingStageAuthorizationService::normalizeStage($execution->stage);

    // Exception: stage gudang_jadi is never locked by Final Quality Lock
    if ($canonicalStage === 'gudang_jadi') {
        return;
    }

    $parentLine = $line ?? $execution->castingResultLine;
    if (! $parentLine) {
        $executionId = $execution->id ?? 'unknown';
        throw new InvalidArgumentException(
            "Data KTR induk tidak ditemukan untuk eksekusi ID {$executionId}. Mutasi defect ditolak demi integritas mutu (Fail-Closed)."
        );
    }

    // Finalization check on the parent KTR
    $hasGudangReceive = $parentLine->relationLoaded('stageExecutions')
        ? ($parentLine->stageExecutions->contains(fn ($se) => $se->checkpoint_code === 'GUDANG_RECEIVE' && $se->physical_done_at !== null)
            || $parentLine->stageExecutions()->where('checkpoint_code', 'GUDANG_RECEIVE')->whereNotNull('physical_done_at')->exists())
        : $parentLine->stageExecutions()->where('checkpoint_code', 'GUDANG_RECEIVE')->whereNotNull('physical_done_at')->exists();

    $isCompleted = ($parentLine->current_stage === 'completed') || $hasGudangReceive;

    if ($isCompleted) {
        $stageLabel = strtoupper(str_replace('_', ' ', (string) ($canonicalStage ?? $execution->stage)));
        $travelerNumber = $parentLine->traveler_number ?? 'KTR';
        throw new InvalidArgumentException(
            "Tahap {$stageLabel} pada KTR {$travelerNumber} telah TERKUNCI karena proses Gudang Jadi sudah selesai. Koreksi defect hanya diperbolehkan pada tahap Gudang Jadi."
        );
    }
}
```

### Logika & Aturan Eksekusi:
1. Mengidentifikasi `$parentLine` (`SandCastingCastingResultLine`).
2. Menormalisasi stage eksekusi target (`$execution->stage`).
3. **Pengecualian Gudang Jadi:** Jika stage adalah `'gudang_jadi'`, guard langsung melakukan `return` (diizinkan).
4. **Pemeriksaan Status Finalisasi:**
   - Memeriksa apakah `current_stage === 'completed'`.
   - Atau memeriksa apakah terdapat record eksekusi dengan `checkpoint_code === 'GUDANG_RECEIVE'` dan `physical_done_at !== null`.
5. Jika finalisasi terdeteksi dan stage yang ingin dimutasi adalah salah satu dari 5 tahap hulu (`netto`, `bubut_od`, `bubut_cnc`, `bor`, `qc`), guard langsung melempar `InvalidArgumentException`.

---

## 4. PENJELASAN MEKANISME ROW LOCKING & RACE CONDITION

Untuk mencegah kondisi balapan (*race condition*) di mana koreksi defect dan penyelesaian fisik Gudang Jadi dilakukan secara bersamaan oleh dua pengguna yang berbeda, sistem menerapkan penguncian baris database tingkat baris (*pessimistic row locking*):

```mermaid
sequenceDiagram
    autonumber
    participant ClientA as Thread A (SPV Gudang Jadi)
    participant ClientB as Thread B (Admin PPIC Edit Netto)
    participant DB as MySQL DB (InnoDB)

    Note over ClientA,DB: Skenario 1: Gudang Jadi Selesai Lebih Dulu
    ClientA->>DB: BEGIN TRANSACTION
    ClientA->>DB: SELECT * FROM sand_casting_casting_result_lines WHERE id = X FOR UPDATE
    Note over DB: Row Line X terkunci secara eksklusif oleh Thread A

    ClientB->>DB: BEGIN TRANSACTION
    ClientB->>DB: SELECT * FROM sand_casting_casting_result_lines WHERE id = X FOR UPDATE
    Note over DB: Thread B WAITING (Antri menunggu lock dilepas)

    ClientA->>DB: INSERT GUDANG_RECEIVE execution
    ClientA->>DB: UPDATE lines SET current_stage = 'completed' WHERE id = X
    ClientA->>DB: COMMIT (Lock dilepas)

    Note over DB: Thread B memperoleh lock dengan data Line X yang FRESH ('completed')
    ClientB->>ClientB: assertStageQualityNotLocked() mengecek current_stage
    ClientB-->>ClientB: THROW InvalidArgumentException ("Tahap NETTO telah TERKUNCI...")
    ClientB->>DB: ROLLBACK
```

### Jaminan Arsitektur Concurrency:
1. **Penguncian Induk Konsisten:**  
   Seluruh operasi mutasi defect (`recordDefectQty`, `adjustDefectQty`, `saveQcBreakdownDraft`, `verifyQcBreakdown`) dan proses finalisasi scanner (`markPhysicalDone`) mengunci baris induk KTR yang sama (`SandCastingCastingResultLine`) menggunakan `lockForUpdate()`.
2. **Fresh Read Pasca-Lock:**  
   Pengecekan status KTR dilakukan di dalam transaksi **setelah** row lock berhasil diperoleh, bukan menggunakan data cache atau state sebelum transaksi.
3. **Atomisitas:**  
   - Jika finalisasi Gudang Jadi selesai lebih dahulu, koreksi defect hulu dijamin membaca status `completed` dan langsung ditolak.
   - Jika koreksi defect hulu selesai lebih dahulu, finalisasi Gudang Jadi akan membaca input quantity yang sudah disesuaikan secara akurat melalui `SandCastingQuantityResolverService`.

---

## 5. DAFTAR ENDPOINT YANG TERLINDUNGI

| No | Endpoint HTTP | Method Controller | Respons Saat Terkunci |
|---|---|---|---|
| 1 | `POST /sand-casting/defects/{id}/record` | `DefectRecordingController::record` | HTTP 422 JSON / Flash Error: `Tahap {STAGE} pada KTR {NO} telah TERKUNCI...` |
| 2 | `POST /sand-casting/defects/{id}/add` | `DefectRecordingController::add` | HTTP 422 JSON / Flash Error: `Tahap {STAGE} pada KTR {NO} telah TERKUNCI...` |
| 3 | `POST /sand-casting/defects/{id}/adjust` | `DefectRecordingController::adjust` | HTTP 422 JSON / Flash Error: `Tahap {STAGE} pada KTR {NO} telah TERKUNCI...` |
| 4 | `POST /sand-casting/qc-defects/{id}/save-draft` | `QcDefectVerificationController::saveDraft` | HTTP 422 JSON / Flash Error: `Tahap {STAGE} pada KTR {NO} telah TERKUNCI...` |
| 5 | `POST /sand-casting/qc-defects/{id}/verify` | `QcDefectVerificationController::verify` | HTTP 422 JSON / Flash Error: `Tahap {STAGE} pada KTR {NO} telah TERKUNCI...` |

---

## 6. BUKTI PENGECUALIAN GUDANG JADI TETAP AKTIF

Pengecualian tahap `gudang_jadi` telah diverifikasi secara menyeluruh baik melalui unit service maupun HTTP feature test:

1. **Kode Guard:**
   ```php
   $canonicalStage = SandCastingStageAuthorizationService::normalizeStage($execution->stage);
   if ($canonicalStage === 'gudang_jadi') {
       return; // Selalu diizinkan
   }
   ```
2. **Hasil Uji Otomatis (`TC-07`):**
   - KTR berstatus `current_stage = 'completed'`.
   - Admin PPIC mencatat defect awal Gudang Jadi (2 pcs reject handling) $\rightarrow$ **STATUS 200 OK** (`good_qty` menjadi 88 pcs).
   - Admin PPIC menambah defect susulan (+1 pcs) $\rightarrow$ **STATUS 200 OK** (`good_qty` menjadi 87 pcs).
   - Admin PPIC melakukan penyesuaian/koreksi (-1 pcs) $\rightarrow$ **STATUS 200 OK** (`good_qty` kembali ke 88 pcs).
   - Seluruh histori defect log Gudang Jadi tercatat dengan benar di `sand_casting_stage_execution_defect_logs`.

---

## 7. HASIL SELURUH AUTOMATED TEST

Pengujian dijalankan pada lingkungan lokal Laragon menggunakan database SQLite in-memory (`phpunit.xml`):

### A. Test Suite Baru: `FinalQualityLockTest`
Perintah: `php artisan test --filter=FinalQualityLockTest`

```
   PASS  Tests\Feature\SandCasting\FinalQualityLockTest
  ✓ tc01 uncompleted ktr can record netto defect                           0.88s  
  ✓ tc02 uncompleted ktr can adjust qc defect                              0.07s  
  ✓ tc03 gudang jadi finalization activates final quality lock             0.08s  
  ✓ tc04 netto defect mutation rejected after gudang jadi completed        0.09s  
  ✓ tc05 machining stages defect mutation rejected after finalization      0.10s  
  ✓ tc06 qc defect draft and verify rejected after finalization            0.09s  
  ✓ tc07 gudang jadi defect mutation remains allowed after finalization    0.11s  
  ✓ tc08 other ktr in same heat remains editable                           0.09s  
  ✓ tc09 ui renders locked badge for completed ktr stages                  0.15s  
  ✓ tc10 transaction and row locking guarantees race safety                0.07s  
  ✓ tc11 existing workflow and quantity resolution unaffected              0.11s  
  ✓ tc12 guard is fail closed and rejects mutation when parent ktr is missing 0.05s  
  ✓ tc13 is stage quality locked returns true for orphaned execution       0.08s  

  Tests:    13 passed (75 assertions)
  Duration: 2.75s
```

### B. Test Suite Terkait (Regression Check):
1. **`DefectRecordingTest`**: 26 passed (174 assertions)
2. **`DefectAdjustmentTest`**: 18 passed (82 assertions)
3. **`QcDefectVerificationTest`**: 31 passed (397 assertions)
4. **`ProductionFloorScanControllerTest`**: 16 passed (38 assertions)

**Total Assertions Terverifikasi: 104 tests, 766 assertions, 0 failures, 0 errors (100% PASS).**
4. **`ProductionFloorScanControllerTest`**: 16 passed (38 assertions)

**Total Assertions Terverifikasi: 762 assertions, 0 failures, 0 errors.**

---

## 8. TEST YANG BELUM DAPAT DIJALANKAN BESERTA ALASANNYA

- **Pengujian Multi-Thread Concurrency Riil (True Parallel Multi-Process Threads):**  
  PHPUnit pada lingkungan Windows CLI mengeksekusi test runner secara single-process (kecuali menggunakan Paratest). Pengujian concurrency balapan sejati (*sub-millisecond parallel network race*) disimulasikan melalui transaksi sekuensial pada `tc10` yang membuktikan bahwa perolehan lock eksklusif memaksa thread kedua membaca status finalisasi terbaru dan memicu guard penolakan.

---

## 9. PANDUAN MANUAL USER ACCEPTANCE TESTING (UAT) DI LARAGON

Pengguna dapat melakukan verifikasi manual pada lingkungan lokal Laragon (`http://localhost/kanban-ppic` atau domain lokal yang dikonfigurasi):

### Langkah UAT 1: Verifikasi KTR yang Masih Berjalan (Belum Selesai)
1. Login sebagai Admin PPIC (`adminppicpf@peroniks.com` atau `adminppicfl@peroniks.com`).
2. Buka menu **Pencatatan Kerusakan (PPIC)**: `/sand-casting/defects`.
3. Pilih KTR yang saat ini berada pada tahap Netto atau QC (belum selesai Gudang Jadi).
4. Klik tombol **"TAMBAH / KOREKSI"**.
5. Input penambahan defect (misal: 1 pcs) dan simpan.
6. **Ekspektasi:** Data berhasil disimpan tanpa hambatan.

### Langkah UAT 2: Finalisasi Gudang Jadi
1. Buka scanner Gudang Jadi: `/sand-casting/scan/gudang-jadi`.
2. Scan nomor KTR yang sudah selesai di QC.
3. Tekan tombol **"SELESAI"**.
4. **Ekspektasi:** Pesan sukses muncul dan status KTR berpindah menjadi `completed`.

### Langkah UAT 3: Verifikasi Penguncian Tahap Hulu (Netto s/d QC)
1. Buka kembali menu `/sand-casting/defects?mode=recorded`.
2. Cari nomor KTR yang baru saja diselesaikan di Gudang Jadi pada filter tahap `NETTO` atau `QC`.
3. **Ekspektasi Visual:** Tombol "TAMBAH / KOREKSI" **hilang** dan digantikan oleh badge abu-abu:  
   `<i class="fas fa-lock"></i> TERKUNCI` dengan tooltip *"Terkunci permanen karena KTR telah selesai di Gudang Jadi."*
4. Coba tembus menggunakan request API / Postman / Inspect Element ke endpoint `POST /sand-casting/defects/{id}/add`.
5. **Ekspektasi Backend:** Request ditolak dengan kode status **422 Unprocessable Entity** dan pesan error:  
   *"Tahap NETTO pada KTR {KTR-XXX} telah TERKUNCI karena proses Gudang Jadi sudah selesai. Koreksi defect hanya diperbolehkan pada tahap Gudang Jadi."*

### Langkah UAT 4: Verifikasi Pengecualian Tahap Gudang Jadi
1. Pada menu `/sand-casting/defects`, pilih filter tahap **Gudang Jadi**.
2. Cari KTR yang sudah selesai tersebut.
3. **Ekspektasi Visual:** Tombol **"CATAT RUSAK"** atau **"TAMBAH / KOREKSI"** **tetap aktif**.
4. Klik dan simpan defect reject gudang sebesar 1 pcs.
5. **Ekspektasi Backend:** Berhasil disimpan, saldo good berkurang 1 pcs, dan catatan log tersimpan rapi.

---

## 10. KONFIRMASI KEPATUHAN BATASAN IMPLEMENTASI

- [x] **Repository Lokal Laragon Saja:** Seluruh pekerjaan dilakukan di `C:\laragon\www\kanban-ppic`.
- [x] **Zero Touch Production:** Tidak ada koneksi, akses SSH, manipulasi file, atau perintah ke server production (`10.88.8.46`).
- [x] **Zero Production Database Modification:** Database production tidak disentuh.
- [x] **Zero Migration / Table Creation:** Tidak ada file migration baru, tidak ada perubahan skema tabel.
- [x] **Zero Git Push / Deployment:** Tidak ada `git push` ke GitHub (`origin/main`) maupun auto-deploy ke server staging/production.
- [x] **Zero Code Formatting Regressions:** Seluruh file yang diubah telah diformat menggunakan Laravel Pint.
- [x] **Standby State:** Eksekusi selesai dan siap untuk dievaluasi oleh pengguna.

