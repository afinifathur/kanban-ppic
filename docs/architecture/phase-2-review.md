# Phase 2 Review — Lost Wax Print Execution Audit

**Date:** 2026-08-22  
**Auditor:** Antigravity  
**Status:** SUCCESSFUL (All 191 tests passed)

---

## 1. Execution Architecture Status
Arsitektur database telah memisahkan antara entitas rencana (**PLAN**) dan realisasi (**EXECUTION**):
- **PLAN:** `LostWaxPrintOrderLine.qty_ordered` tetap bersifat immutable setelah print order diterbitkan (`ISSUED`).
- **ACTUAL:** Disimpan secara inkremental dalam tabel append-only `lost_wax_print_executions` (`qty_good`, `qty_defect`).
- **Outstanding:** Dihitung murni berdasarkan eksekusi berstatus `FINALIZED` saja menggunakan formula:
  $$\text{outstanding} = \max(0, \text{qty\_ordered} - \sum \text{finalized\_good} - \sum \text{finalized\_defect})$$

---

## 2. adjustExecutionsToMatch() Audit
- **A. Kapan dipanggil?** Dipanggil ketika form submission lama (melalui controller legacy update) mengirimkan total good/defect baru yang lebih kecil dari akumulasi saat ini (terjadi pengurangan target cetak / koreksi data).
- **B. Siapa yang memanggilnya?** Dipanggil oleh `OutcomeController::updateOutcome()`.
- **C. Apakah method ini hanya compatibility bridge?** Ya, ia bertindak sebagai compatibility bridge agar form submission legacy dan test suite existing yang belum mengenal interface eksekusi harian tetap dapat berjalan hijau tanpa modifikasi luas.
- **D. Apakah method ini mengubah execution secara otomatis?** Ya. Jika target Good/Defect diturunkan, method ini akan mengupdate record execution terakhir dan secara otomatis menyisipkan record koreksi di tabel `lost_wax_print_execution_corrections` sebagai log audit permanen.
- **E. Apakah method ini menjadikan legacy fields sebagai source of truth?** **Tidak.** Source of truth tetap pada data eksekusi di tabel `lost_wax_print_executions`. Legacy fields (`qty_actual_good`, `qty_actual_defect`, `actual_recorded_at`) diperbarui secara otomatis di `PrintExecutionService::updateLineAggregates` murni sebagai cached aggregate demi backward compatibility.

---

## 3. Legacy Field Dependency Map
Hasil audit penggunaan kolom lama:
- `qty_actual_good`
  - **A. Compatibility:** Diperlukan oleh `LostWaxPrintOrderLine::getQtyAvailableForRangkaiAttribute` yang menghitung ketersediaan unit untuk dirangkai (`qty_actual_good - trees()->sum('quantity')`).
  - **B. Logic Dependency:** Diperlukan oleh `AssemblyController` & view assemblies (`index.blade.php`, `create.blade.php`) untuk memeriksa jumlah yang siap dirangkai. Akan obsolete ketika Phase 4 (Rangkai Work Order) menggunakan relasi eksekusi baru.
- `qty_actual_defect`
  - **A. Logic Dependency:** Diperlukan oleh `ProductionStatusController` (`cetak_defect += $line->qty_actual_defect ?? 0`) untuk menghitung total defect pada dashboard status.
- `actual_recorded_at`
  - **A. Historical Meta Only:** Hanya digunakan sebagai penanda waktu metadata pembuatan cetak pertama, tidak ada dependency alur logika bisnis inti.

---

## 4. Status Calculation Audit
Pembedaan status `DRAFT` dan `FINALIZED` pada eksekusi terbukti berfungsi 100%:
- **Draft Execution:** Tidak ikut dihitung dalam total `qty_executed_good`/`qty_executed_defect`. Kuantitas outstanding tetap utuh. Status baris tetap `PENDING` dan status Print Order tetap `ISSUED`.
- **Finalized Execution:** Menghitung total aktual, memotong outstanding, memicu perubahan status baris (`IN_PROGRESS`/`COMPLETED`), serta mengubah status Print Order menjadi `PARTIALLY_COMPLETED`/`COMPLETED`.
- Unit test `PrintExecutionTest::test_draft_execution_can_be_edited_but_finalized_is_locked` telah memverifikasi behavior ini secara ketat.

---

## 5. Partial Print Order Audit
- Print Order dengan multi-line (misal: 4 lines completed, 1 line outstanding) akan mempertahankan status dokumen asli menjadi `PARTIALLY_COMPLETED`.
- Tidak ada dokumen Print Order baru yang dibuat. Data outstanding tetap menempel pada dokumen rencana semula dan dapat diakses kembali untuk eksekusi hari berikutnya.
- Unit test `PrintExecutionTest::test_multi_line_order_completion_stages` memverifikasi alur ini.

---

## 6. Multi-Day Execution Test Simulation
- Skenario cetak 100 pcs:
  - Day 1: Good = 70, Defect = 5 (Finalized) $\rightarrow$ Outstanding = 25.
  - Day 2: Good = 25, Defect = 0 (Finalized) $\rightarrow$ Outstanding = 0.
- Akumulasi total: Good = 95, Defect = 5.
- Status baris otomatis bertransisi menjadi `COMPLETED`. Hal ini diuji dalam `PrintExecutionTest::test_partial_and_second_execution_transitions_status_to_completed`.

---

## 7. Defect Semantics Verification
- Defect mengurangi outstanding secara linier: jika planned = 100, good = 70, defect = 5, maka outstanding sisa adalah 25.
- Jika kebutuhan cetak fisik ternyata kurang (misal 5 pcs defect harus diproduksi ulang), supervisor dapat membuat perintah cetak tambahan baru yang berasosiasi dengan kode Production Plan yang sama (didukung bawaan oleh `PrintOrderController::store`).

---

## 8. Backfill Candidate Records
Berikut adalah data detail 4 record `lost_wax_print_order_lines` existing yang memiliki actual data:

| ID | Qty Ordered | Qty Good (Actual) | Qty Defect (Actual) | Recorded At | Order Status | Recorded By |
|----|-------------|-------------------|---------------------|-------------|--------------|-------------|
| 9  | 11          | 11                | 0                   | 2026-08-21  | ISSUED       | 3           |
| 10 | 17          | 17                | 0                   | 2026-08-21  | ISSUED       | 3           |
| 11 | 100         | 100               | 0                   | 2026-08-21  | ISSUED       | 3           |
| 12 | 120         | 120               | 0                   | 2026-08-21  | ISSUED       | 3           |

### Proposed Backfill Mapping
Setiap legacy record di atas akan dipetakan ke satu record synthetic `LostWaxPrintExecution` baru dengan aturan:
```
lost_wax_print_order_line_id = lines.id
execution_date               = DATE(lines.actual_recorded_at)
qty_good                     = lines.qty_actual_good
qty_defect                   = lines.qty_actual_defect
status                       = 'FINALIZED'
notes                        = 'Backfill legacy execution data'
recorded_by                  = lines.actual_recorded_by
recorded_at                  = lines.actual_recorded_at
finalized_by                 = lines.actual_recorded_by
finalized_at                 = lines.actual_recorded_at
```

---

## 9. Risks / Issues Found
1. **SQLite Check Constraint:** MySQL ENUM modification tidak langsung mengubah check constraint di SQLite (memory test). Telah dimitigasi dengan melakukan konversi ke `string(30)` secara khusus pada SQLite dalam migrasi status enum.
2. **Constraint Identifier Length:** Nama constraint default MySQL melebihi 64 karakter untuk relasi rangkai. Telah dimitigasi dengan mendefinisikan nama FK secara eksplisit (misal: `fk_lw_rwo_line`).

---

## 10. Recommendation
Seluruh audit review Phase 2 sukses. Kode bekerja sesuai dengan spesifikasi bisnis. Direkomendasikan untuk meminta persetujuan langkah backfill dan transisi ke **Phase 3 — Outstanding & PrintOrder Status UI**.
