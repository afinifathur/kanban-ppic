# LAPORAN IMPLEMENTASI: PENYESUAIAN ATURAN BISNIS LIFECYCLE HASIL COR & OVER-PLAN SAND CASTING

**Repository:** `C:\laragon\www\kanban-ppic`  
**Tanggal:** 9 November 2026  
**Status:** IMPLEMENTED & TESTED (86 Passed, 0 Failed across all Sand Casting Core Suites)  

---

## 1. LATAR BELAKANG & ATURAN BISNIS FINAL

Berdasarkan fakta operasional pabrik Sand Casting:
1. Satu kode produksi dapat memiliki beberapa nomor Heat (*pouring events*).
2. Qty hasil cor aktual diperbolehkan melebihi sisa kuota Perintah Cor (*PCOR*) pada **Heat terakhir** (misal: rencana 110 pcs, penuangan Heat 1 = 40, Heat 2 = 40, Heat 3 = 40 -> total 120 pcs).
3. Setelah total hasil cor mencapai atau melampaui rencana cor (`qty_remaining_to_cast <= 0`), status PCOR secara otomatis berubah menjadi **`COMPLETED`**.
4. Dalam operasional normal, **PCOR `COMPLETED` tidak boleh menerima penambahan Heat baru** lagi, baik melalui antarmuka umum, parameter URL `?casting_order_id=...`, maupun direct HTTP POST.
5. Jika terjadi kerusakan/scrap berlebihan di tahap lanjutan dan diperlukan penuangan cor ulang (*recast*), perusahaan membuat **kode produksi baru** (misal: kode awal `2610AB21` -> kode cor ulang `2610AB21X`). PCOR baru diterbitkan untuk kode baru tersebut dan tidak mencampuradukkan hasil cor ulang ke kode produksi awal.

### Matriks Aturan Status Dokumen PCOR

| Status PCOR | Boleh Mencatat Heat Baru? (`recordResult`) | Muncul di Candidate Lines Form Create? | Boleh Mengalami Over-Plan? | Boleh Dikoreksi Record Existing? (`correctResult`) |
| :--- | :---: | :---: | :---: | :---: |
| **`ISSUED`** | **YA** | **YA** | **YA** (Warning amber non-blocking) | **YA** |
| **`PARTIALLY_COMPLETED`** | **YA** | **YA** | **YA** (Warning amber non-blocking) | **YA** |
| **`COMPLETED`** | **TIDAK** (Ditolak Exception / 422) | **TIDAK** (Difilter keluar) | N/A (Sudah selesai) | **YA** (Hanya koreksi record yang sudah ada, tanpa menambah Heat baru) |
| **`DRAFT`** | **TIDAK** (Harus di-issued dahulu) | **TIDAK** | N/A | **TIDAK** |
| **`CANCELLED`** | **TIDAK** (Order dibatalkan) | **TIDAK** | N/A | **TIDAK** |

---

## 2. FILE YANG DIUBAH & DETAIL PERUBAHAN

### A. `app/Services/SandCasting/SandCastingCastingResultService.php`

1. **Method `recordResult()`:**
   - Menolak pencatatan Heat baru untuk PCOR berstatus `COMPLETED` dengan pesan pengecualian spesifik:
     ```php
     if ($order->status === 'COMPLETED') {
         throw new InvalidArgumentException("Perintah Cor {$order->casting_order_number} sudah selesai (COMPLETED) dan tidak dapat menerima penambahan Heat baru.");
     }

     if (! in_array($order->status, ['ISSUED', 'PARTIALLY_COMPLETED'], true)) {
         throw new InvalidArgumentException("Perintah Cor {$order->casting_order_number} tidak dalam status yang valid untuk pencatatan Hasil Cor.");
     }
     ```
   - Tetap mempertahankan izin pencatatan hasil cor melebihi sisa kuota (*over-plan*) tanpa batasan artifisial `$qtyGood > $remainingQuota` jika statusnya `ISSUED` atau `PARTIALLY_COMPLETED`.
   - Menjaga seluruh proteksi integritas: non-negatif, product scope, domain validation, deteksi duplikasi KTR intra/cross-request, DB transaction, dan row locking (`lockForUpdate`).

2. **Method `correctResult()` (Perbedaan Jelas dengan `recordResult`):**
   - **Tujuan `recordResult()`:** Menerbitkan nomor KTR baru untuk Heat baru. Hanya diizinkan pada PCOR `ISSUED` dan `PARTIALLY_COMPLETED`.
   - **Tujuan `correctResult()`:** Memperbaiki kesalahan input (typo kuantitas, reject, berat timbang, catatan, dsb.) pada KTR yang **sudah ada sebelumnya**.
   - **Aturan Lifecycle pada `correctResult()`:**
     - Mengoreksi baris KTR yang sudah ada pada PCOR yang berstatus `COMPLETED` **tetap diizinkan** (misal memperbaiki kesalahan timbang atau menurunkan Qty Good dari 40 ke 30 pcs). Jika Qty Good diturunkan dan total menjadi kurang dari rencana cor, order status secara otomatis turun kembali (*auto-reverts*) menjadi `PARTIALLY_COMPLETED`.
     - Namun jika user mencoba memindahkan (*reassign*) baris KTR ke PCOR lain yang sudah berstatus `COMPLETED`, sistem **menolak mutasi** tersebut (`Perintah Cor {$orderLine->code} tidak dalam status yang valid untuk menerima alokasi baris Hasil Cor`).
     - Seluruh koreksi pre-gate dan post-gate execution locking tetap berlaku ketat, dan setiap perubahan tercatat di tabel audit `sand_casting_casting_result_corrections`.

### B. `app/Http/Controllers/SandCasting/CastingResultController.php`

1. **Proteksi Parameter `preselectedOrderId`:**
   Jika query parameter `?casting_order_id=...` diberikan tetapi PCOR tersebut berstatus `COMPLETED`, `DRAFT`, atau `CANCELLED`, controller langsung melakukan redirect kembali ke form create bersih dengan flash message error:
   ```php
   if ($preselectedOrderId) {
       $preselectedOrder = \App\Models\SandCastingCastingOrder::find($preselectedOrderId);
       if (! $preselectedOrder || ! in_array($preselectedOrder->status, ['ISSUED', 'PARTIALLY_COMPLETED'], true)) {
           $statusDesc = $preselectedOrder ? $preselectedOrder->status : 'tidak ditemukan';

           return redirect()->route('sand-casting.casting-results.create')
               ->with('error', "Perintah Cor #{$preselectedOrderId} ({$statusDesc}) tidak dapat menerima pencatatan Hasil Cor baru.");
       }
   }
   ```
2. **Scoping Query Candidate Lines:**
   - Query Eloquent `$linesQuery` secara eksplisit hanya memuat baris dari order dengan status `['ISSUED', 'PARTIALLY_COMPLETED']`.
   - Baris dari PCOR `COMPLETED` tidak akan pernah terkirim ke frontend `AVAILABLE_LINES`.
3. **Store Endpoint Backend Protection:**
   - Jika request HTTP POST langsung ditembak ke `/sand-casting/casting-results` dengan target baris dari PCOR `COMPLETED`, domain service melempar `InvalidArgumentException`, yang direspons dengan HTTP 422 (jika JSON) atau redirect back dengan `session('error')` (jika form submit).

### C. Frontend `resources/views/sand-casting/casting-results/create.blade.php`

1. Warning amber non-blocking tetap dipertahankan untuk menginformasikan jika hasil cor melebihi sisa kuota rencana pada Heat aktif (`+N pcs over kuota`).
2. Tidak ada atribut HTML `max`, tidak ada *value clamping*, dan tidak ada modal/alert pemblokiran.
3. PCOR `COMPLETED` tidak ditawarkan sebagai kandidat line pada autocomplete maupun form staging.

### D. Model A Immutability & Recast Handling

1. `ProductionPlan::getQtyCastingScheduledAttribute()` dan controller subquery mengunci `max(qty_ordered, total_good)`.
2. Ketika over-plan terjadi pada PCOR aktif, komitmen kuota mengunci nilai aktual yang lebih tinggi sehingga tidak ada kebocoran kuota ke rencana cor.
3. Alur Cor Ulang (*Recast*): Menggunakan kode produksi baru (misal `2610AB21X`), membuat `ProductionPlan` baru, menerbitkan PCOR baru berstatus `ISSUED`, dan mencatat Heat baru secara terisolasi tanpa mengotori PCOR lama `2610AB21` yang sudah `COMPLETED`.

---

## 3. HASIL VERIFIKASI AUTOMATED TESTS

Pengujian otomatis dijalankan secara menyeluruh menggunakan PHPUnit / Pest pada database in-memory:

### A. Test Suite Baru: `OverPlanCastingResultTest` (13 Passed)

| Skenario Pengujian | Hasil | Catatan |
| :--- | :---: | :--- |
| `test_1_plan_110_actual_120_in_final_heat_saves_successfully` | **PASS** | Plan 110, penuangan 120 pcs pada heat terakhir berhasil disimpan dan order menjadi `COMPLETED`. |
| `test_2_accumulation_of_three_heats_40_40_40_for_plan_110_succeeds` | **PASS** | Akumulasi 3 Heat (40 + 40 + 40 = 120 pcs) berhasil, menerbitkan 3 KTR terpisah, order menjadi `COMPLETED`. |
| `test_3_over_plan_warning_does_not_block_submission_on_web_form` | **PASS** | Web form submission dengan over-plan tidak terblokir dan redirect dengan status sukses. |
| `test_4_after_pcor_becomes_completed_new_heat_is_rejected_by_service` | **PASS** | Setelah PCOR `COMPLETED`, penambahan Heat baru ditolak dengan exception. |
| `test_5_completed_pcor_does_not_appear_in_candidate_lines` | **PASS** | PCOR `COMPLETED` tidak muncul dalam daftar candidate lines di form create. |
| `test_6_completed_pcor_cannot_be_bypassed_via_preselected_order_id` | **PASS** | Akses via `?casting_order_id={completed_id}` ditolak dan redirect dengan flash error. |
| `test_7_direct_post_on_completed_pcor_is_rejected` | **PASS** | Direct HTTP POST (web & JSON 422) pada baris PCOR `COMPLETED` ditolak. |
| `test_8_issued_and_partially_completed_pcor_accept_over_plan` | **PASS** | PCOR `ISSUED` dan `PARTIALLY_COMPLETED` dapat menerima over-plan tanpa kendala. |
| `test_9_correction_of_existing_result_on_completed_pcor_follows_lifecycle_and_records_audit_trail` | **PASS** | Koreksi KTR existing pada PCOR `COMPLETED` diizinkan, tercatat di audit log, dan mutasi ke PCOR `COMPLETED` lain ditolak. |
| `test_10_new_production_code_for_recast_creates_new_pcor_normally` | **PASS** | Kode cor ulang (`2610AB21X`) dapat membuat PCOR baru secara independen dari PCOR lama yang `COMPLETED`. |
| `test_11_model_a_commits_higher_quantity_and_prevents_duplicate_pcor` | **PASS** | Model A mengunci `max(ordered, good)` sehingga mencegah penerbitan PCOR ganda. |
| `test_12_outstanding_po_never_becomes_negative` | **PASS** | Sisa kuota rencana cor dan outstanding PO tetap clamped `>= 0`. |
| `test_13_final_quality_lock_and_downstream_stages_have_no_regression` | **PASS** | KTR terbit pada tahap Netto dengan data akurat tanpa regresi pada Final Quality Lock. |

### B. Hasil Regresi Test Suite Lainnya

1. **`CastingOrderModelATest`:** 12 Passed (56 assertions)
2. **`CastingOrderTest`:** 24 Passed (383 assertions)
3. **`CastingResultServiceAndControllerTest`:** 24 Passed (105 assertions)
4. **`FinalQualityLockTest`:** 13 Passed (75 assertions)
5. **`DefectAdjustmentTest`:** 18 Passed (82 assertions)
6. **Total:** **104 tests passed, 0 failed** pada seluruh fungsionalitas Sand Casting.

---

## 4. PANDUAN PENGUJIAN USER ACCEPTANCE TEST (UAT)

Langkah-langkah pengujian manual pada lingkungan Laragon lokal:

### Skenario 1: Over-Plan pada Heat Terakhir
1. Buka halaman Rencana Cor: `/sand-casting/casting-orders/plans`.
2. Buat PCOR baru dengan target 110 pcs, lalu terbitkan dokumen (*status ISSUED*).
3. Masuk ke halaman Input Hasil Cor: `/sand-casting/casting-results/create`.
4. Pilih kode produksi dokumen tersebut.
5. Masukkan Heat 1 = 40 pcs -> Simpan. Status PCOR berubah menjadi `PARTIALLY_COMPLETED`.
6. Masukkan Heat 2 = 40 pcs -> Simpan. Status PCOR tetap `PARTIALLY_COMPLETED`.
7. Masukkan Heat 3 = 40 pcs (total menjadi 120 pcs, melebihi target 110 pcs):
   - Perhatikan bahwa antarmuka menampilkan badge peringatan amber non-blocking `+10 pcs over kuota`.
   - Tekan tombol **Simpan Hasil Cor**.
   - **Hasil yang diharapkan:** Data berhasil disimpan tanpa popup penolakan, KTR ke-3 berhasil terbit, dan status PCOR berubah menjadi `COMPLETED`.

### Skenario 2: Pencegahan Penambahan Heat pada PCOR `COMPLETED`
1. Setelah PCOR pada Skenario 1 berstatus `COMPLETED`, buka kembali form Input Hasil Cor `/sand-casting/casting-results/create`.
2. Cari kode produksi awal pada kolom pencarian kode produk.
   - **Hasil yang diharapkan:** Sistem menampilkan pesan bahwa tidak ditemukan Perintah Cor aktif untuk kode tersebut.
3. Buka halaman detail PCOR tersebut: `/sand-casting/casting-orders/{id}`.
   - **Hasil yang diharapkan:** Tombol **Input Hasil Cor** tidak ditampilkan.
4. Coba akses paksa melalui URL: `/sand-casting/casting-results/create?casting_order_id={id_completed}`.
   - **Hasil yang diharapkan:** Halaman langsung redirect kembali dengan pesan peringatan: *"Perintah Cor #{id} (COMPLETED) tidak dapat menerima pencatatan Hasil Cor baru."*

### Skenario 3: Alur Cor Ulang (*Recast*) dengan Kode Produksi Baru
1. Buat kode produksi baru dengan akhiran X (misal `2610AB21X`) dengan target 20 pcs.
2. Buat dokumen PCOR baru untuk kode `2610AB21X` dan terbitkan (*status ISSUED*).
3. Buka form Input Hasil Cor dan ketik kode `2610AB21X`.
   - **Hasil yang diharapkan:** PCOR baru `2610AB21X` langsung terdeteksi sebagai kandidat aktif, dapat dicatat penuangan cornya secara normal, dan tidak mempengaruhi PCOR lama `2610AB21` yang sudah selesai.

### Skenario 4: Koreksi Hasil Cor Existing pada PCOR `COMPLETED`
1. Buka salah satu KTR/Heat dari PCOR yang sudah `COMPLETED`.
2. Klik menu Edit/Koreksi Hasil Cor (`/sand-casting/casting-results/{id}/edit`).
3. Ubah catatan atau lakukan penyesuaian kuantitas sebelum ada eksekusi fisik (pre-gate), lalu simpan dengan alasan koreksi.
   - **Hasil yang diharapkan:** Koreksi berhasil disimpan, riwayat audit tersimpan di tabel koreksi, dan jika kuantitas diturunkan di bawah rencana cor, status PCOR otomatis kembali ke `PARTIALLY_COMPLETED`.

---

> [!NOTE]
> Seluruh pekerjaan diselesaikan hanya pada repository lokal Laragon `C:\laragon\www\kanban-ppic`. Tidak ada akses ke server production, tidak ada migrasi skema database baru, dan tidak dilakukan deployment. Sesuai instruksi, pekerjaan berhenti di tahap ini dan menunggu evaluasi pengguna.

