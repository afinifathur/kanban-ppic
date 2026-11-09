# LAPORAN IMPLEMENTASI: PCOR REOPENING SETELAH KOREKSI KTR & KOREKSI RELASI KODE PRODUKSI

**Repository:** `C:\laragon\www\kanban-ppic`  
**Tanggal:** 9 November 2026  
**Status:** IMPLEMENTED & TESTED (88 Passed, 0 Failed pada seluruh Sand Casting Regression Suites)  

---

## 1. AUDIT AWAL SEBELUM IMPLEMENTASI

Berdasarkan pemeriksaan teknis terhadap kode awal:

1. **Apakah koreksi kuantitas pada PCOR COMPLETED menghitung ulang status dengan benar?**  
   - **Audit:** Method `SandCastingCastingResultService::syncCastingOrderStatus()` mengevaluasi `$allCompleted` (`$line->qty_remaining_to_cast <= 0`) dan `$hasAnyOutcome` (`qty_cast_good > 0 || qty_cast_reject > 0`).  
   - **Temuan:** Ketika koreksi menurunkan `qty_good` sehingga total Good < Qty Rencana, `$allCompleted` bernilai `false`, dan status PCOR otomatis berubah menjadi `PARTIALLY_COMPLETED`. Jika seluruh hasil dihapus/dinolkan, status kembali menjadi `ISSUED` (bukan `PARTIALLY_COMPLETED` tanpa hasil).  
   - **Kekurangan awal:** Sebelumnya pada method `correctResult()`, verifikasi status PCOR tujuan dibatasi hanya `['ISSUED', 'PARTIALLY_COMPLETED']`, dan penangkapan ID order lama/baru belum sepenuhnya terisolasi dari relasi cache model.

2. **Apakah perubahan production code/PCOR pada KTR existing sudah didukung?**  
   - **Audit:** Pada `CastingResultController::edit()`, form menyediakan `<select name="items[...][sand_casting_casting_order_line_id]">`.  
   - **Temuan:** Form telah mendukung pemilihan order line lain, tetapi filter candidate lines di controller sebelumnya membatasi hanya order dengan `qty_remaining_to_cast > 0`. Akibatnya, PCOR berstatus `COMPLETED` tidak muncul di dropdown jika admin ingin merealokasikan KTR ke dokumen tersebut.

3. **Apakah total Good PCOR asal dan tujuan dihitung ulang dengan benar?**  
   - **Audit:** Relasi `SandCastingCastingOrderLine::resultLines()` mengagregasi baris hasil berdasarkan foreign key `sand_casting_casting_order_line_id`.  
   - **Temuan:** Ketika foreign key diubah dan disimpan, total Good PCOR asal otomatis berkurang dan PCOR tujuan otomatis bertambah.

4. **Apakah perubahan tersebut atomik dalam database transaction?**  
   - **Audit:** Seluruh operasi di `correctResult()` dibungkus dalam `DB::transaction(function () { ... })`.  
   - **Temuan:** Atomik penuh. Jika salah satu baris, validasi, atau kueri gagal, transaksi di-rollback secara otomatis tanpa meninggalkan mutasi parsial.

5. **Apakah audit trail menyimpan identitas PCOR dan production code sebelum/sesudah?**  
   - **Audit:** Sebelumnya tabel `sand_casting_casting_result_corrections` hanya mencatat `sand_casting_casting_order_line_id` dan `production_plan_id` (berupa integer ID).  
   - **Temuan:** Belum mencatat nama kode produksi asal/tujuan (`26AB` -> `26AC`) dan nomor PCOR asal/tujuan (`PCOR-26AB-001` -> `PCOR-26AC-001`) secara eksplisit dan mudah dibaca manusia.

6. **Apakah Model A menghitung ulang komitmen kuota setelah koreksi?**  
   - **Audit:** `ProductionPlan::getQtyCastingScheduledAttribute()` menghitung komitmen kuota secara dinamis:  
     `$totalCommitted += max($ordered, $good)`.  
   - **Temuan:** Jika order asal berkurang total Good-nya (misal dari 120 menjadi 80 pada rencana 100), komitmen kuota kembali ke `max(100, 80) = 100`, dan kuota tetap terkunci di Model A tanpa kebocoran.

7. **Apakah correction flow tetap tunduk pada Final Quality Lock & Post-Gate Locks?**  
   - **Audit:** Di awal loop `correctResult()`, terdapat pengecekan `hasPhysicalExecution()`.  
   - **Temuan:** Jika KTR sudah memiliki eksekusi fisik (Netto, Machining, QC, Gudang Jadi), perubahan `sand_casting_casting_order_line_id` dan perubahan `qty_good` **ditolak keras** dengan `InvalidArgumentException`. Final Quality Lock pada tahap Gudang Jadi juga tetap memblokir manipulasi defect downstream.

---

## 2. PERUBAHAN YANG DIIMPLEMENTASIKAN

### A. `app/Services/SandCasting/SandCastingCastingResultService.php`

1. **Penyesuaian Validasi Status Target Order pada `correctResult()`:**
   - Memperbolehkan pemindahan KTR ke PCOR tujuan dengan status `['ISSUED', 'PARTIALLY_COMPLETED', 'COMPLETED']`.
   - Menolak keras pemindahan ke dokumen `DRAFT` atau `CANCELLED`.
   ```php
   if (! $lineHasExecution && $newLineOrderLineId !== (int) $line->sand_casting_casting_order_line_id) {
       $order = $orderLine->castingOrder;
       if (! $order || ! in_array($order->status, ['ISSUED', 'PARTIALLY_COMPLETED', 'COMPLETED'], true)) {
           throw new InvalidArgumentException("Perintah Cor {$orderLine->code} tidak dalam status yang valid untuk menerima alokasi baris Hasil Cor.");
       }
   }
   ```

2. **Pencatatan Audit Trail Lengkap (Termasuk Kode Produksi & Nomor PCOR):**
   - Sebelum mutasi disimpan, sistem mengambil snapshot entitas asal (`$oldCode`, `$oldPcorNumber`, `$oldOrderId`, `$oldPlanId`) dan entitas tujuan (`$newCode`, `$newPcorNumber`, `$newOrderId`, `$planId`).
   - Merekam log koreksi eksplisit di `sand_casting_casting_result_corrections`:
     - `field_name = 'production_code'` (misal `old_value = '26AB'`, `new_value = '26AC'`).
     - `field_name = 'casting_order_number'` (misal `old_value = 'PCOR-26AB-001'`, `new_value = 'PCOR-26AC-001'`).
     - `field_name = 'sand_casting_casting_order_line_id'` (ID lama -> ID baru).
     - `field_name = 'production_plan_id'` (ID plan lama -> ID plan baru).
     - Disertai `reason`, `corrected_by`, dan `corrected_at`.

3. **Sinkronisasi Dua Arah Status PCOR Asal & Tujuan:**
   - Mengumpulkan seluruh `$affectedOrderIds` (`$oldOrderId` dan `$newOrderId`).
   - Memanggil `syncCastingOrderStatus()` untuk setiap PCOR unik yang terdampak dengan row lock (`lockForUpdate`).
   - Menghitung ulang status masing-masing PCOR:
     - Jika total Good baru >= Qty Rencana: Status menjadi/tetap `COMPLETED`.
     - Jika total Good baru > 0 dan < Qty Rencana: Status reopens menjadi `PARTIALLY_COMPLETED`.
     - Jika tidak ada hasil Good dan Reject tersisa: Status kembali menjadi `ISSUED` (tidak menjadi `PARTIALLY_COMPLETED` tanpa hasil).

4. **Pencegahan Pembuatan Heat Baru pada PCOR COMPLETED:**
   - `recordResult()` tetap menolak penerbitan Heat baru pada PCOR `COMPLETED`.
   - Setelah PCOR dibuka kembali menjadi `PARTIALLY_COMPLETED` melalui koreksi KTR, `recordResult()` kembali mengizinkan pencatatan Heat baru secara normal.

### B. `app/Http/Controllers/SandCasting/CastingResultController.php`

1. **Candidate Lines pada Halaman Edit Koreksi (`edit()`):**
   - Mengizinkan dropdown item/PCOR memuat seluruh baris Sand Casting yang berstatus `['ISSUED', 'PARTIALLY_COMPLETED', 'COMPLETED']`.
   - Memungkinkan admin memilih PCOR tujuan dengan leluasa saat mengoreksi kesalahan kode produksi pada KTR pre-gate.

---

## 3. KEPUTUSAN TEKNIS & ARSITEKTUR

1. **Status `COMPLETED` Bersifat Dinamis & Reversible:**
   Status dokumen dihitung dari agregasi aktual `resultLines` terhadap `qty_ordered`. Ketika penuangan aktual dikurangi (akibat koreksi timbangan, salah hitung reject cor, atau relokasi KTR), PCOR secara konsisten turun statusnya ke `PARTIALLY_COMPLETED`.
2. **Pemisahan Tegas Antara Pre-Gate dan Post-Gate:**
   - **Pre-Gate (KTR belum memiliki eksekusi fisik downstream):** Bebas dikoreksi kuantitasnya, kode produksinya, PCOR tujuannya, maupun catatan dan berat timbangnya.
   - **Post-Gate (KTR sudah discan/dikerjakan di Netto, Machining, QC, Gudang Jadi):** Kuantitas Good dan relasi PCOR/Item **terkunci permanen**. Koreksi hanya diizinkan untuk data non-struktural (operator, shift, furnace, notes) dengan alasan koreksi wajib.
3. **Penyimpanan Audit Trail Human-Readable:**
   Alih-alih hanya mencatat ID database yang sulit dipahami saat audit operasional, sistem mencatat string kode produksi (`26AB` -> `26AC`) dan nomor dokumen PCOR langsung ke kolom `field_name`, `old_value`, dan `new_value`.

---

## 4. HASIL PENGUJIAN OTOMATIS (AUTOMATED TESTS)

Dibuat automated test suite baru di:  
`tests/Feature/SandCasting/PcorReopeningCorrectionTest.php`

Seluruh 8 skenario yang diminta berhasil lolos (**PASS, 0 Failures**):

| Test Case | Deskripsi Pengujian | Hasil |
| :--- | :--- | :---: |
| **Test 1** | Koreksi kuantitas yang membuat `COMPLETED` tetap `COMPLETED` (120 pcs -> 110 pcs >= 100 pcs rencana). | **PASS** |
| **Test 2** | Koreksi kuantitas yang membuat `COMPLETED` turun menjadi `PARTIALLY_COMPLETED` (105 pcs -> 90 pcs < 100 pcs rencana). | **PASS** |
| **Test 3** | Heat baru berhasil dicatat setelah PCOR dibuka kembali menjadi `PARTIALLY_COMPLETED` (Heat 2 dicatat 30 pcs -> total 110 pcs -> kembali `COMPLETED`). | **PASS** |
| **Test 4** | Koreksi production code dan PCOR dari `26AB` ke `26AC` berhasil memindahkan foreign key dan `production_plan_id`. | **PASS** |
| **Test 5** | Recalculation total dan status kedua PCOR: PCOR asal (26AB) turun dari 100 ke 80 (reopens to `PARTIALLY_COMPLETED`), PCOR tujuan (26AC) naik dari 80 ke 100 (becomes `COMPLETED`), Model A mengunci kuota kedua plan secara akurat. | **PASS** |
| **Test 6** | Rollback atomik seluruh transaksi database jika terjadi kesalahan (misal ID baris tidak valid), memastikan tidak ada data setengah tersimpan. | **PASS** |
| **Test 7** | Audit trail lengkap mencatat `production_code` (old: `26AB`, new: `26AC`), `casting_order_number` (old: `PCOR-AUDIT-A`, new: `PCOR-AUDIT-B`), `sand_casting_casting_order_line_id`, `production_plan_id`, `qty_good`, alasan koreksi, dan user pelaksana. | **PASS** |
| **Test 8** | Penolakan keras koreksi yang melanggar execution gate: KTR dengan eksekusi fisik Netto menolak perubahan Qty Good dan menolak pemindahan PCOR. | **PASS** |

### Hasil Regresi Seluruh Test Suites Sand Casting:
- `PcorReopeningCorrectionTest`: 8 Passed (57 assertions)
- `OverPlanCastingResultTest`: 13 Passed (75 assertions)
- `CastingOrderModelATest`: 12 Passed (56 assertions)
- `CastingOrderTest`: 24 Passed (383 assertions)
- `CastingResultServiceAndControllerTest`: 24 Passed (105 assertions)
- `FinalQualityLockTest`: 13 Passed (75 assertions)
- `DefectAdjustmentTest`: 18 Passed (82 assertions)
- **Total: 88 Passed, 0 Failed.**

---

## 5. KETERBATASAN YANG MASIH ADA (KNOWN BOUNDARIES)

1. **KTR yang Sudah Bergerak Fisik Tidak Dapat Dipindahkan:**  
   Jika KTR sudah discan di Netto atau tahap berikutnya, sistem sengaja menolak pemindahan kode produksi / PCOR. Hal ini sesuai aturan industri manufaktur: barang fisik yang sudah diproses di lini produksi tidak boleh diubah identitas lot/travelernya secara digital karena fisik barang sudah bertanda nomor KTR terkait.
2. **Koreksi Tidak Boleh Menghasilkan Kuantitas Nol Total:**  
   Sistem mensyaratkan setiap KTR memiliki kuantitas Good atau Reject > 0. KTR tidak dapat dikoreksi menjadi Good = 0 DAN Reject = 0 (jika seluruh barang reject, catat Good = 0 dan Reject = N).
3. **Pencatatan Heat Baru Tetap Memerlukan Status Aktif:**  
   Pencatatan Heat baru tetap tidak diizinkan pada PCOR yang masih berstatus `COMPLETED` sampai dokumen tersebut secara sah dibuka kembali (*reopened*) melalui koreksi KTR.

---

> [!NOTE]  
> Seluruh pekerjaan diselesaikan hanya di repository lokal Laragon `C:\laragon\www\kanban-ppic`. Tidak ada perubahan pada server production, tidak ada SSH, tidak ada git push, dan tidak ada migrasi skema database. Sistem telah siap dan menunggu evaluasi pengguna.

