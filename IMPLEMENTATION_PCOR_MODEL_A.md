# LAPORAN IMPLEMENTASI
## PERBAIKAN INTEGRITAS PERINTAH COR (PCOR) SAND CASTING — MODEL A
**PT Peroni Karya Sentosa — Kanban PPIC**  
**Tanggal:** 06 November 2026  
**Status Implementasi:** SELESAI (Local Environment Laragon)  
**Metodologi:** Model A (PCOR Berkelanjutan / Continuous PCOR)

---

## 1. DAFTAR FILE YANG DIUBAH / DITAMBAHKAN

| No | File | Jenis Perubahan | Deskripsi Singkat |
| :---: | :--- | :---: | :--- |
| 1 | `app/Models/ProductionPlan.php` | Edit | Mengubah accessor `getQtyCastingScheduledAttribute()` agar status `PARTIALLY_COMPLETED` tetap mengunci `qty_ordered`. |
| 2 | `app/Http/Controllers/SandCasting/CastingOrderController.php` | Edit | • Mengubah SQL subquery komitmen kuota pada method `plans()`.<br>• Menyelaraskan perhitungan kuota di method `update()`.<br>• Membungkus method `updateStatus()` dalam `DB::transaction()` dengan row locking `lockForUpdate()` dan validasi ulang sisa kuota rencana sebelum status menjadi `ISSUED`. |
| 3 | `app/Services/SandCasting/SandCastingCastingResultService.php` | Edit | • Menambahkan validasi keras server-side pada `recordResult()`: akumulasi Good tidak boleh melebihi `qty_ordered` (`new_good <= remaining`).<br>• Menambahkan validasi batas kuota pada `correctResult()` saat edit/koreksi hasil cor. |
| 4 | `resources/views/sand-casting/casting-results/create.blade.php` | Edit | • Mengembalikan atribut `max` pada input Good.<br>• Memperketat validasi JavaScript agar mencegah penambahan item atau submit jika `qtyGood > remainingQuota`. |
| 5 | `tests/Feature/SandCasting/CastingOrderModelATest.php` | File Baru | 12 skenario pengujian Model A lengkap sesuai spesifikasi wajib. |
| 6 | `tests/Feature/SandCasting/CastingOrderTest.php` | Edit | Menyelaraskan ekspektasi status penguncian kuota `PARTIALLY_COMPLETED` dan alur kelanjutan cor sesuai Model A. |
| 7 | `tests/Feature/SandCasting/CastingResultServiceAndControllerTest.php` | Edit | Menyelaraskan ekspektasi penolakan *over-casting* pada pengujian service dan controller. |

---

## 2. PENJELASAN PERUBAHAN SETIAP METHOD

### A. `ProductionPlan::getQtyCastingScheduledAttribute()`
- **Sebelumnya:** Status `PARTIALLY_COMPLETED` hanya mengunci sebesar `SUM(qty_good)`. Sisa yang belum dicor dilepaskan kembali ke kuota rencana.
- **Sesudah:** Status `PARTIALLY_COMPLETED` digabungkan bersama `DRAFT` dan `ISSUED`, sehingga selalu mengunci seluruh kuantitas pesanan awal (`qty_ordered`). Sisa pekerjaan tetap terikat pada dokumen PCOR tersebut sampai selesai.

### B. `CastingOrderController::plans()`
- **Sebelumnya:** SQL Subquery `committedSubquerySql` memiliki klausa `WHEN o.status = 'PARTIALLY_COMPLETED' THEN (SELECT SUM(r.qty_good)...)`.
- **Sesudah:** Diubah menjadi `WHEN o.status IN ('DRAFT', 'ISSUED', 'PARTIALLY_COMPLETED') THEN l.qty_ordered`. Hal ini menjamin angka kuota di tampilan web tab Rencana Cor dan kalkulator komposisi konsisten dengan perhitungan model.

### C. `CastingOrderController::update()`
- **Sebelumnya:** Filter pengecekan kuota saat edit DRAFT mengecualikan sisa dari order `PARTIALLY_COMPLETED`.
- **Sesudah:** Status `PARTIALLY_COMPLETED` tetap memperhitungkan `qty_ordered` saat menghitung alokasi order lain.

### D. `CastingOrderController::updateStatus()`
- **Sebelumnya:** Perubahan status berjalan tanpa database transaction, tanpa pessimistic locking, dan tanpa memvalidasi apakah kuota rencana masih cukup saat tombol *Terbitkan (ISSUE)* diklik.
- **Sesudah:**
  1. Dibungkus dalam `DB::transaction()`.
  2. Mengunci baris dokumen PCOR dengan `lockForUpdate()`.
  3. Saat beralih ke `ISSUED`: melakukan verifikasi dan penguncian baris pada seluruh `ProductionPlan` terkait. Jika kuota rencana ternyata sudah tidak mencukupi (misal terpakai oleh order lain), penerbitan dibatalkan seketika dengan pesan error.
  4. Mencegah status `COMPLETED` langsung dipicu dari `DRAFT`.
  5. Memastikan pembatalan (`CANCELLED`) ditolak keras jika dokumen sudah memiliki hasil cor aktual.

### E. `SandCastingCastingResultService::recordResult()`
- **Sebelumnya:** Tidak ada batasan kuantitas Good terhadap sisa pesanan PCOR. Berapapun angka Good yang diinput diterima selama $\ge 0$.
- **Sesudah:**
  1. Menghitung akumulasi Good yang sudah tercatat sebelumnya pada baris order terkait (`$existingGood`).
  2. Menghitung kuota sisa: `remaining = max(0, qty_ordered - existingGood)`.
  3. Memvalidasi: `if ($qtyGood > $remainingQuota)` $\rightarrow$ melempar `InvalidArgumentException` dengan pesan rinci menyebutkan item, nomor PCOR, dan jumlah kuota sisa yang sah.
  4. Dilindungi oleh pessimistic row locking `$orderLine = SandCastingCastingOrderLine::lockForUpdate()->find($lineId)`.

### F. `SandCastingCastingResultService::correctResult()`
- Menambahkan validasi serupa pada tahap edit data leburan (pre-gate): kuantitas Good hasil koreksi tidak boleh melebihi sisa kapasitas order baris tersebut.

---

## 3. PERUBAHAN ATURAN KUOTA (BUSINESS RULES)

| Status PCOR | Aturan Komitmen Kuota Rencana Produksi | Dampak di Tab Rencana Cor |
| :--- | :--- | :--- |
| **`DRAFT`** | Mengunci `qty_ordered` | Kuota terpotong, item tidak muncul di tab Rencana Cor jika kuota habis. |
| **`ISSUED`** | Mengunci `qty_ordered` | Kuota terpotong penuh. |
| **`PARTIALLY_COMPLETED`** | **MENGUNCI `qty_ordered` (Penuh)** | **TIDAK DILEPASKAN.** Sisa outstanding TIDAK muncul di tab Rencana Cor dan tidak dapat dibuatkan PCOR baru. |
| **`COMPLETED`** | Mengunci $\max(\text{qty\_ordered}, \text{qty\_good})$ | Kuota terpotong permanen. |
| **`CANCELLED`** | Mengunci 0 (Dilepaskan) | Kuota kembali tersedia jika pembatalan sah (hanya dokumen tanpa hasil cor). |

---

## 4. VALIDASI GOOD DAN CONCURRENCY

- **Formula Backend:**
  $$\text{ExistingGood} = \sum(\text{qty\_good})_{\text{PCOR Line}}$$
  $$\text{RemainingQuota} = \max(0, \text{qty\_ordered} - \text{ExistingGood})$$
  $$\text{Validasi:} \quad \text{NewGood} \le \text{RemainingQuota}$$
- **Row Locking:** Baris `SandCastingCastingOrderLine` dikunci dengan klausa SQL `SELECT ... FOR UPDATE` di dalam `DB::transaction()`.
- **Independensi Reject:** Kuota hanya menghitung `qty_good`. Kuantitas cacat/afkir (`qty_reject`) tidak mengurangi target Good yang harus dicor.

---

## 5. PERUBAHAN PROSES ISSUED

1. **Prasyarat Status:** Hanya dokumen berstatus `DRAFT` yang dapat dinaikkan ke `ISSUED`.
2. **Atomic Quota Re-check:** Setiap baris item dicek ulang ke tabel induk `production_plans` menggunakan `lockForUpdate()`. Jika kuota rencana berubah atau ditutup (*closed*), proses *issue* dibatalkan secara atomik.
3. **Tanpa Digital Approval Tambahan:** Sesuai arahan, otorisasi penerbitan tidak menambah field `approved_by` atau tabel persetujuan digital baru; verifikasi fisik tanda tangan pada lembar PDF tetap berlaku sebagai prosedur pabrik.

---

## 6. HASIL AUTOMATED TESTS

Pengujian dilakukan menggunakan suite pengujian Laravel (`php artisan test`).

### Ringkasan Hasil Uji:

| Test Suite | File Pengujian | Jumlah Test | Jumlah Assertion | Status |
| :--- | :--- | :---: | :---: | :---: |
| **Model A Scenarios (Wajib)** | `tests/Feature/SandCasting/CastingOrderModelATest.php` | 12 | 51 | **100% PASS** |
| **Casting Order Core** | `tests/Feature/SandCasting/CastingOrderTest.php` | 24 | 383 | **100% PASS** |
| **Result Service & Controller** | `tests/Feature/SandCasting/CastingResultServiceAndControllerTest.php` | 24 | 96 | **100% PASS** |
| **TOTAL** | — | **60 Tests** | **530 Assertions** | **ALL PASSED** |

### Rincian 12 Skenario Uji Wajib (Semua Lulus):
1. `test_scenario_1_production_plan_200_pcor_issued_200_available_quota_is_zero` — **PASS**
2. `test_scenario_2_pcor_partially_completed_with_good_100_available_quota_remains_zero` — **PASS**
3. `test_scenario_3_pcor_partially_completed_can_receive_additional_100_good_becoming_completed` — **PASS**
4. `test_scenario_4_after_full_good_additional_1_good_is_strictly_rejected` — **PASS**
5. `test_scenario_5_concurrency_locking_prevents_exceeding_good_quota` — **PASS**
6. `test_scenario_6_reject_does_not_reduce_outstanding_good` — **PASS**
7. `test_scenario_7_draft_pcor_cannot_receive_casting_results` — **PASS**
8. `test_scenario_8_completed_and_cancelled_pcor_cannot_receive_new_results` — **PASS**
9. `test_scenario_9_pcor_with_actual_results_cannot_be_cancelled` — **PASS**
10. `test_scenario_10_valid_cancelled_pcor_releases_quota` — **PASS**
11. `test_scenario_11_model_accessor_and_plans_sql_subquery_produce_exact_same_available_quota` — **PASS**
12. `test_scenario_12_two_pcors_cannot_lock_production_plan_exceeding_remaining_quota` — **PASS**

---

## 7. KETERBATASAN TEST CONCURRENCY

> [!NOTE]
> Lingkungan automated test menggunakan SQLite `:memory:` (`phpunit.xml`).  
> **Keterbatasan:** SQLite menerapkan penguncian tingkat basis data (*database-level lock*), bukan *row-level lock* granular seperti InnoDB MySQL di server produksi.  
> Meskipun struktur transaksi di kode telah menggunakan `lockForUpdate()`, pengujian simulasi multithreading nyata dengan koneksi paralel independen (*race condition*) secara penuh tetap membutuhkan database MySQL/MariaDB aktif.

---

## 8. RISIKO YANG MASIH TERSISA

1. **Disiplin Pencatatan Lembar Fisik:**  
   Karena lembar kerja fisik PCOR dicetak satu kali dan berlaku berkelanjutan, pengawas tanur harus disiplin menulis akumulasi hasil cor pada baris manual lembar PCOR yang sama setiap hari agar tidak tercecer.
2. **Kebutuhan Short-Close Manual:**  
   Jika pesanan customer dibatalkan di tengah jalan saat statusnya masih `PARTIALLY_COMPLETED`, kuota sisa yang belum dicor akan tetap terkunci sampai dokumen tersebut diselesaikan secara manual oleh admin/PPIC melalui tombol *Selesaikan (COMPLETE)*.

---

## 9. PANDUAN MANUAL USER ACCEPTANCE TEST (UAT)

Langkah-langkah berikut wajib diuji secara manual oleh PPIC dan Admin Hasil Cor di lingkungan Laragon sebelum rilis:

### Skenario UAT 1: Siklus Pengecoran Berkelanjutan (Model A)
1. **PPIC:** Buka menu Rencana Cor (`/sand-casting/casting-orders/plans`). Pilih item yang memiliki sisa kuota (misal 200 pcs).
2. **PPIC:** Buat Perintah Cor (Draft), lalu klik **Terbitkan (ISSUE)**.
3. **PPIC:** Buka tab *Rencana Cor*. Pastikan item tersebut **hilang dari daftar rencana aktif** (kuota sisa = 0).
4. **Admin Hasil Cor:** Buka menu *Input Hasil Cor* (`/sand-casting/casting-results/create`).
5. **Admin Hasil Cor:** Masukkan Heat 1 untuk PCOR tersebut sebesar 100 pcs Good. Klik Simpan.
6. **PPIC & Admin:** Buka detail PCOR. Pastikan status dokumen berubah menjadi **`PARTIALLY_COMPLETED`**.
7. **PPIC:** Kembali ke tab *Rencana Cor*. **Verifikasi bahwa sisa 100 pcs TIDAK muncul kembali** di tab Rencana Cor.
8. **Admin Hasil Cor:** Buka kembali form *Input Hasil Cor*. Cari nomor PCOR yang sama. Pastikan sistem menampilkan **Sisa Kuota: 100 pcs**.
9. **Admin Hasil Cor:** Masukkan sisa 100 pcs Good pada Heat 2. Klik Simpan.
10. **PPIC & Admin:** Buka detail PCOR. Pastikan status dokumen otomatis berubah menjadi **`COMPLETED`**.

### Skenario UAT 2: Pengujian Blokir Over-Casting
1. Pada PCOR yang memiliki sisa kuota 50 pcs, Admin Hasil Cor mencoba menginput hasil Good sebanyak 51 pcs.
2. **Hasil yang Diharapkan:**
   - Di form web: muncul peringatan dan input dicegah.
   - Jika form di-submit: server menolak dengan pesan: *"Kuantitas Good (51 pcs) ... melebihi sisa yang belum dicor pada Perintah Cor ... (Sisa kuota: 50 pcs)."*
   - Tidak ada data Heat atau KTR baru yang tersimpan ke database.

### Skenario UAT 3: Cetak Ulang PCOR Berkelanjutan
1. Pada PCOR berstatus `PARTIALLY_COMPLETED` (misal dari 200 pcs sudah dicor 100 pcs), klik tombol **Cetak PDF**.
2. **Hasil yang Diharapkan:**
   - Dokumen PDF tetap mempertahankan nomor PCOR dan tanggal rencana cor asli.
   - Kolom *Qty Perintah (Sisa)* menampilkan angka outstanding terbaru (**100 pcs**).
   - Lembar cetak dapat langsung diserahkan ke tanur untuk jadwal pengecoran hari berikutnya.

