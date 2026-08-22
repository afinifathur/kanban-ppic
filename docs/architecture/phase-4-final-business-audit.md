# Phase 4 Final Business Audit — Lost Wax Rangkai Module

**Date:** 2026-08-22  
**Auditor:** Antigravity  
**Final Verdict:** READY WITH WARNINGS (Critical Finding in Layer 7 Accessor)

---

## 1. Executive Summary
Audit bisnis menyeluruh telah dilakukan secara read-only terhadap implementasi Phase 4 (Lost Wax Rangkai Work Order & Execution). Semua 196 test case unit & integration passed dengan sukses. Alur pemisahan PLAN $\neq$ EXECUTION $\neq$ PHYSICAL UNIT diimplementasikan secara benar dan atomic. Namun, teridentifikasi satu temuan kritis terkait penentuan status Skip Layer 7 pada entitas pohon fisik (`LostWaxTree`).

---

## 2. Print → Rangkai Quantity Audit
Evaluasi terhadap `qty_available_for_rangkai` pada model `LostWaxPrintOrderLine.php`:
- **Formula:**
  $$\text{qty\_available\_for\_rangkai} = \max(0, \text{qty\_executed\_good} - \text{allocated\_tree\_quantity})$$
- **Skenario Evaluasi:**
  - Print Plan = 100 pcs, Print Execution (FINALIZED) Good = 70, Defect = 5.
    - `qty_executed_good` = 70.
    - Rangkai Available = 70 pcs (Benar - Defect 5 pcs diisolasi secara linier).
  - Rangkai Execution #1 = 60 pcs.
    - `allocated_tree_quantity` (jumlah tree quantity) = 60 pcs.
    - Sisa `qty_available_for_rangkai` = 10 pcs.
    - Rangkai Outstanding = $70 - 60 = 10$ pcs.
  - Rangkai Execution #2 = 10 pcs.
    - `allocated_tree_quantity` = 70 pcs.
    - Rangkai Outstanding = 0.
  - Print Outstanding tetap = 25 (karena dihitung dari Print Plan (100) - Print Good (70) - Print Defect (5) = 25).
- **Verifikasi:** Formula di model `LostWaxPrintOrderLine` dan penanganan di `RangkaiExecutionService` terbukti akurat dan terisolasi secara sempurna.

---

## 3. Defect Isolation Audit
- Defect print (`qty_executed_defect`) **TIDAK PERNAH** masuk ke alur Rangkai.
- `RangkaiExecutionService::createWorkOrder` membatasi quantity rencana berdasarkan `$line->qty_available_for_rangkai` yang bersumber dari `qty_executed_good` (tidak memuat defect).
- `RangkaiExecutionService::recordExecution` kembali memvalidasi kuantitas yang diajukan terhadap `$line->qty_available_for_rangkai`.
- Kuantitas rusak terisolasi penuh di level print.

---

## 4. Partial Execution & Tree Creation Audit
- Skenario cetak Good = 100, Rangkai WO = 100 pcs (Tree Capacity = 20):
  - **Saat Work Order Rangkai dibuat:** Jumlah `LostWaxTree` fisik yang terbuat = **0** (Hanya record perencanaan `lost_wax_rangkai_work_orders` yang dibuat).
  - **Day 1 (Eksekusi 4 trees @ 20 pcs = 80 pcs):** 4 `LostWaxTree` fisik terbuat dan berelasi dengan Rangkai Execution #1. Sisa Outstanding = 20 pcs.
  - **Day 2 (Eksekusi 1 tree @ 20 pcs = 20 pcs):** 1 `LostWaxTree` fisik terbuat dan berelasi dengan Rangkai Execution #2. Sisa Outstanding = 0 pcs (Status WO menjadi `COMPLETED`).
- **Verifikasi:** Pohon lilin fisik *hanya* terbit secara bertahap saat eksekusi riil dilaporkan.

---

## 5. Tree Creation Timing & Transaction Audit
Aktivitas database di `RangkaiExecutionService::recordExecution()` dibungkus dalam transaction boundary yang ketat:
```
Start DB Transaction
  1. Lock Rangkai Work Order (lockForUpdate)
  2. Lock Print Order Line (lockForUpdate)
  3. Validate Requested Qty against Outstanding & Print Available
  4. Create LostWaxRangkaiExecution
  5. Loop & create physical LostWaxTrees with unique barcodes
  6. Recalculate Work Order Status
Commit DB Transaction (Rollback on any DB / Validation Exception)
```
- **Verifikasi:** Jika salah satu pembuatan record tree gagal (misal duplikasi barcode unik), seluruh eksekusi dan tree lainnya di-rollback secara atomik (tidak ada partial tree creation).

---

## 6. Traceability Audit
Setiap pohon lilin fisik baru dapat ditelusuri ke hulu secara lengkap tanpa ada relasi yang terputus:
- `LostWaxTree` $\rightarrow$ `rangkaiExecution` (`rangkai_execution_id`)
- `LostWaxRangkaiExecution` $\rightarrow$ `workOrder` (`rangkai_work_order_id`)
- `LostWaxRangkaiWorkOrder` $\rightarrow$ `printOrderLine` (`lost_wax_print_order_line_id`)
- `LostWaxPrintOrderLine` $\rightarrow$ `printOrder` (`lost_wax_print_order_id`)
- `LostWaxPrintOrderLine` $\rightarrow$ `productionPlan` (`production_plan_id`)
- `LostWaxPrintOrderLine` $\rightarrow$ `executions` (`lost_wax_print_executions` - print executions)

---

## 7. Multi-Day Partial Flow (Layer 1 Scan Eligibility)
- Begitu eksekusi Rangkai Day 1 selesai dicatat (4 trees dibuat), status tree diset `generated` dan `current_stage = null`.
- Operator dapat langsung men-scan keempat tree tersebut di stasiun Layer 1 tanpa perlu menunggu status Rangkai Work Order `COMPLETED` (tidak ada hambatan hilir/downstream).

---

## 8. Concurrency Audit
- Pemanfaatan `lockForUpdate()` pada row Work Order dan Print Order Line di `recordExecution()` menjamin operator ganda tidak dapat mengalokasikan kuantitas melebihi sisa outstanding (Operator kedua akan diblokir dengan exception `InvalidArgumentException`).

---

## 9. Existing Trees Safety
- Barcode dan data `LostWaxTree` legacy (yang dibuat sebelum Phase 4) tidak disentuh atau diubah secara massal.
- Backward compatibility berjalan mulus.

---

## 10. Legacy Field Dependency Audit
- Alur Rangkai yang baru sepenuhnya menggunakan finalized `LostWaxPrintExecution` (melalui aggregate `qty_executed_good`).
- Legacy fields `qty_actual_good` dipertahankan murni sebagai cached aggregate demi backward compatibility view & unit test lama.

---

## 11. Barcode Audit
- Mekanisme penentuan sequence harian dan format barcode pada `RangkaiExecutionService` dipertahankan identik dengan generator legacy (Unik, format `familyCode + dmy + Str::pad(seq, 3, '0')`, fully-compatible dengan scanner pabrik).

---

## 12. Layer 1–6 Compatibility
- `LostWaxTree` yang baru terbit tetap kompatibel 100% dengan alur scan `ScanController.php` dan `ScanService.php`.

---

## 13. Layer 7 Readiness Observation
- Kolom `require_layer_7` berhasil disimpan di database pada record `LostWaxTree` saat eksekusi rangkai dicatat.

---

## 14. Status Audit
- Status Work Order Rangkai (`OPEN` $\rightarrow$ `IN_PROGRESS` $\rightarrow$ `COMPLETED`) diturunkan secara dinamis dari outstanding pcs cetak yang telah selesai dirangkai.

---

## 15. Test Coverage Audit
- **Exclusion Defect:** TESTED (`test_cannot_create_rangkai_work_order_beyond_available`)
- **Partial/Second Execution:** TESTED (`test_rangkai_executions_and_physical_trees_creation`)
- **Tree Creation Timing:** TESTED (Trees created only on finalize)
- **Concurrency & Transaction:** TESTED (`test_concurrency_double_allocation_prevention`)
- **Barcode Uniqueness:** TESTED
- **Regression Suite:** TESTED & GREEN (196 passed).

---

## 16. Critical Findings

### [CRITICAL] `require_layer_7` Accessor Override Bug
- **Lokasi:** `app/Models/LostWaxTree.php` (Line 177-180)
- **Masalah:** Accessor `getRequireLayer7Attribute()` meng-override nilai kolom `require_layer_7` tabel `lost_wax_trees` dengan:
  ```php
  return (bool) ($this->workOrder?->require_layer_7 ?? false);
  ```
  Karena tree baru hasil Rangkai Work Order menggunakan relasi `rangkaiExecution` (bukan `$this->workOrder` yang merupakan legacy Work Order), maka `$this->workOrder` bernilai `null`. Hal ini menyebabkan accessor selalu mengembalikan `false`, melompati stasiun Layer 7 (Skip Layer 7) secara otomatis meskipun di database kolom `require_layer_7` diset `true`.
- **Dampak:** Alur validasi scanning di stasiun Layer 6 akan melompati stasiun Layer 7 secara tidak sengaja untuk seluruh pohon baru.
- **Rekomendasi Perbaikan:** Ubah accessor agar membaca dari kolom database `require_layer_7` terlebih dahulu:
  ```php
  public function getRequireLayer7Attribute(): bool
  {
      if (isset($this->attributes['require_layer_7'])) {
          return (bool) $this->attributes['require_layer_7'];
      }
      return (bool) ($this->workOrder?->require_layer_7 ?? false);
  }
  ```

---

## 17. Final Verdict
**READY WITH WARNINGS**
Sistem siap dilanjutkan ke Phase 5, dengan rekomendasi perbaikan kritis pada accessor `LostWaxTree::getRequireLayer7Attribute()` di awal Phase 5.
