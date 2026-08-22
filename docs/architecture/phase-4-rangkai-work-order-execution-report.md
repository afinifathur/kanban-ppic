# Phase 4 — Lost Wax Rangkai Work Order & Rangkai Execution Report

**Date:** 2026-08-22  
**Status:** SUCCESSFUL (All 196 tests passed)

---

## 1. Scope
Phase 4 merealisasikan pemisahan konseptual antara **PLAN (Rangkai Work Order)**, **EXECUTION (Rangkai Execution)**, dan **PHYSICAL UNIT (LostWaxTree)** pada modul Lost Wax.

---

## 2. Existing Rangkai Architecture Audit
Tabel database Rangkai yang telah dibuat pada Phase 1 dibaca secara teliti:
- `lost_wax_rangkai_work_orders`: menyimpan target rencana trees planned dan standard capacity.
- `lost_wax_rangkai_executions`: menyimpan pencatatan realisasi trees created, tanggal eksekusi, dan family code.
- `lost_wax_trees` memiliki kolom link `rangkai_execution_id` untuk menghubungkan pohon fisik ke eksekusi perangkaiannya.

---

## 3. Existing AssemblyController Audit
Controller legacy `AssemblyController.php` sebelumnya langsung membagi sisa good pcs menjadi beberapa record `LostWaxTree` seketika tombol diklik (tanpa pencatatan histori harian / append-only). Ketergantungan legacy ini telah diaudit dan diarahkan menggunakan arsitektur baru.

---

## 4. LostWaxTree Creation Audit
Mekanisme barcode harian unik:
`barcode = familyCode + dmy + Str::pad(seq, 3, '0')`
telah dipertahankan secara utuh demi konsistensi pembacaan scanner produksi.

---

## 5. Architectural Decisions
- **PLAN ≠ EXECUTION:** Supervisor membuat `Rangkai Work Order` terlebih dahulu yang menunjuk ke target line. Pohon fisik **tidak dibuat** pada fase perencanaan ini.
- **EXECUTION:** Operator melakukan perangkaian harian secara append-only. Kuantitas masing-masing pohon dimasukkan secara dinamis.
- **PHYSICAL UNIT:** Setiap `LostWaxTree` fisik dibuat secara dinamis dan atomic *hanya* pada saat eksekusi rangkai tersebut disave (`status = FINALIZED` secara database transaction).
- **SQLite & MySQL Compatibility:** Constraint foreign key menggunakan penamaan pendek `fk_lw_rwo_line` dan `fk_lw_re_wo` untuk mencegah error identifier length MySQL 8.

---

## 6. Schema Used
Tabel existing dari Phase 1 digunakan tanpa destructive modification:
- `lost_wax_rangkai_work_orders`
- `lost_wax_rangkai_executions`
- Kolom `rangkai_execution_id` pada `lost_wax_trees`

---

## 7. Models Created/Modified
- **NEW (Created):**
  - [`app/Models/LostWaxRangkaiWorkOrder.php`](file:///C:/laragon/www/kanban-ppic/app/Models/LostWaxRangkaiWorkOrder.php) (Pemetaan model Work Order Rangkai)
  - [`app/Models/LostWaxRangkaiExecution.php`](file:///C:/laragon/www/kanban-ppic/app/Models/LostWaxRangkaiExecution.php) (Pemetaan model Eksekusi Rangkai harian)
- **MODIFY (Modified):**
  - [`app/Models/LostWaxTree.php`](file:///C:/laragon/www/kanban-ppic/app/Models/LostWaxTree.php) (Menambahkan link `rangkai_execution_id` dan relasi)
  - [`app/Models/LostWaxPrintOrderLine.php`](file:///C:/laragon/www/kanban-ppic/app/Models/LostWaxPrintOrderLine.php) (Mengubah `qty_available_for_rangkai` menggunakan `qty_executed_good` dan menambah relasi rangkaiWorkOrder)

---

## 8. Services Created
- **NEW (Created):**
  - [`app/Services/RangkaiExecutionService.php`](file:///C:/laragon/www/kanban-ppic/app/Services/RangkaiExecutionService.php) (Mengelola lifecycle pembuatan WO, validasi sisa outstanding cetak good, sequence barcode unik harian, atomicity transaction, dan recalculate status WO).

---

## 9. Controllers & Routes Modified
- **AssemblyController.php:** Menambahkan action `storeWorkOrder`, `showWorkOrder`, dan `storeExecution`.
- **routes/web.php:** Mendaftarkan endpoint post store WO, get show WO detail, dan post store execution.

---

## 10. Views Modified
- **create.blade.php:** Diubah menjadi form pembuatan Rangkai Work Order baru dengan live preview estimasi trees.
- **show_wo.blade.php (NEW):** View detail Rangkai Work Order yang memuat metrik real-time, form eksekusi rangkai pohon lilin fisik, serta riwayat eksekusi kronologis dengan barcode link tree.
- **index.blade.php:** Mengadopsi sistem Tab: Tab 1 (Hasil Cetak Siap Rangkai) dan Tab 2 (Daftar WO Rangkai aktif).

---

## 11. Concurrency & Transaction Safety
- `LostWaxRangkaiWorkOrder::lockForUpdate()` dan `LostWaxPrintOrderLine::lockForUpdate()` digunakan di dalam `DB::transaction` untuk mencegah race-condition double allocation kuantitas rangkai oleh operator.
- Jika pembuatan salah satu tree fisik gagal, seluruh eksekusi dan database state otomatis ter-rollback secara aman.

---

## 12. Tree Capacity Rule
- Sesuai dengan business rule, expected trees dihitung dari:
  $$\text{expected\_trees} = \text{ceil}(\text{qty\_available} / \text{standard\_capacity})$$
- Operator dapat menginput jumlah tree dan membagi kuantitasnya secara dinamis di form eksekusi.

---

## 13. Outstanding Formula
- **Rangkai Outstanding:**
  $$\text{outstanding} = \max(0, \text{qty\_planned\_pcs} - \sum \text{qty\_executed\_pcs})$$

---

## 14. Test Results
- **Test Command:** `composer test`
- **Result:** **196 passed** (1333 assertions) — *ALL TESTS PASS*.
- **Rangkai Test Suite Coverage:** Mencakup validasi available good limit, defect exclusion, partial & second execution, concurrency lock, dan auto-transition status WO.

---

## 15. Known Limitations
- Rangkai Execution tidak memiliki status DRAFT di database schema Phase 1 (langsung FINALIZED/atomic).
- Tidak ada backfill data perangkaian lama (Rangkai) untuk meminimalkan risiko pada physical unit trees.

---

## 16. Recommendation for Phase 5
- Melanjutkan ke **Phase 5 — Scan Void & Correction** untuk mengelola mekanisme pembatalan event scan (Void) secara non-destructive.
