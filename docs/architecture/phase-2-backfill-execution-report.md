# Phase 2 — Backfill Execution Report

**Date:** 2026-08-22  
**Backfill Execution Timestamp:** 2026-08-22 13:07:33  
**Auditor:** Antigravity  

---

## 1. Summary of Execution
Proses backfill data cetak historis untuk 4 record `lost_wax_print_order_lines` (ID 9, 10, 11, 12) telah diselesaikan dengan aman dan sukses.

- **Records Inserted:** 4 records (pada jalankan pertama)
- **Records Skipped:** 4 records (pada jalankan kedua, membuktikan idempotensi 100%)

---

## 2. Before/After Quantities & Status

| Line ID | Qty Ordered | Before Good | After Good (Finalized) | Before Defect | After Defect | Before Outstanding | After Outstanding | Status Line |
|---------|-------------|-------------|-------------------------|---------------|--------------|--------------------|-------------------|-------------|
| **9**   | 11          | 0           | 11                      | 0             | 0            | 11                 | 0                 | `COMPLETED` |
| **10**  | 17          | 0           | 17                      | 0             | 0            | 17                 | 0                 | `COMPLETED` |
| **11**  | 100         | 0           | 100                     | 0             | 0            | 100                | 0                 | `COMPLETED` |
| **12**  | 120         | 0           | 120                     | 0             | 0            | 120                | 0                 | `COMPLETED` |

---

## 3. Physical Database Integritas Verification (Before vs After)

Tabel berikut menunjukkan count data fisik untuk membuktikan tidak ada side effect ganda:

| Data Type | Count Sebelum Backfill | Count Sesudah Backfill | Status |
|-----------|------------------------|------------------------|--------|
| **LostWaxTree (Count)** | 57                     | 57                     | **IDENTIK (SAFE)** |
| **LostWaxTree (Qty Sum)** | 540 pcs                | 540 pcs                | **IDENTIK (SAFE)** |
| **PrintJob (Count)** | 12                     | 12                     | **IDENTIK (SAFE)** |
| **LostWaxScanEvent (Count)**| 139                    | 139                    | **IDENTIK (SAFE)** |

---

## 4. Status Transition Verification
- **Order ID 3:** Berubah status dari `ISSUED` menjadi `COMPLETED` (memuat Line 9 & 10).
- **Order ID 4:** Berubah status dari `ISSUED` menjadi `COMPLETED` (memuat Line 11 & 12).
- Perubahan ini otomatis ditrigger oleh `PrintExecutionService::updateLineAggregates()` dan secara logis benar karena seluruh line di dalamnya sudah terselesaikan.
- Tidak ada side effect pemicuan data fisik (tidak membuat tree baru, tidak mencetak job baru, dsb).

---

## 5. Idempotency Verification
Script backfill dijalankan ulang pada `13:09:10` dan log output menunjukkan:
```
Line ID 9: synthetic execution sudah ada. SKIPPED.
Line ID 10: synthetic execution sudah ada. SKIPPED.
Line ID 11: synthetic execution sudah ada. SKIPPED.
Line ID 12: synthetic execution sudah ada. SKIPPED.
```
Sistem aman dari duplikasi records jika script dipicu ulang di masa mendatang.

---

## 6. Test Suite Result
- **Command:** `composer test`
- **Result:** `191 passed (1301 assertions) — Duration: 11.50s` (Semua tes tetap hijau).

---

## 7. Rangkai Module Dependencies
Setelah backfill ini selesai, outstanding untuk Rangkai adalah sebagai berikut:
- **Line 9 (Plan 11):** Rangkai outstanding = 11 (belum dirangkai sama sekali).
- **Line 11 (Plan 100):** Rangkai outstanding = 100 (belum dirangkai sama sekali).
- **Line 10 & 12:** Rangkai outstanding = 0 (sudah dirangkai penuh secara fisik).
- Data ini akan diproses di Phase 4 saat arsitektur Rangkai Work Order mulai diimplementasikan.
