# PHASE 3.6 STEP 9.5 — TOT RSK (TOTAL RUSAK) SEMANTIC AUDIT & IMPLEMENTATION

## 1. Executive Summary

Kolom **Tot Rsk (Total Rusak)** pada menu `/lost-wax/production-status` telah diperbaiki agar secara akurat merepresentasikan:
$$\text{Tot Rsk} = \text{Defect Cetak} + \text{Defect Rangkai} + \sum_{n=1}^{7} \text{Defect Layer } n + \text{Defect Oven}$$

- **Sebelumnya:** Baris Production Plan hanya menampilkan `$qTreeDefect` (akumulasi defect Rangkai + Layer 1-7 + Oven), sehingga defect cetak (`qPrintDefect`) belum terhitung di dalam kolom `Tot Rsk` (hanya tampil di sub-kolom `R CTK`).
- **Sekarang:** `overall_defect` dihitung secara authoritatif sebagai `$qPrintDefect + $qTreeDefect` (atau `$breakdown['q_total_defect']`).
- **Independensi Total (Net Good):** `Tot Rsk` berfungsi sebagai indikator defect/loss reporting murni, dan **tidak mengurangi ulang** kuantitas `Total` atau WIP stage manapun.

---

## 2. Canonical Defect Sources Mapping

| Stage | Defect Source | Field Asal |
|---|---|---|
| **Cetak (CTK)** | `lost_wax_print_order_lines.qty_executed_defect` | Sum `lost_wax_print_executions.qty_defect` (status=FINALIZED) |
| **Rangkai (RGKI)** | `lost_wax_tree_defects` | `stage = 'assembly'` |
| **Layer 1–7** | `lost_wax_tree_defects` | `stage IN ('layer_1', ..., 'layer_7')` |
| **Oven** | `lost_wax_tree_defects` | `stage = 'oven'` |

*Bukti bebas double counting:* `$qPrintDefect` dan `$qTreeDefect` berasal dari tabel yang berbeda dan tidak saling memasukkan data satu sama lain.

---

## 3. Hasil UAT 268L651

- Cetak Gross = 840
- Cetak Defect = 10 (`CTK R = 10`)
- Net Good = 830
- Alokasi Rangkai = 144
- Standby CTK = 686
- RGKI = 144
- Downstream defects = 0
- **Total (Net Good):** 830
- **Tot Rsk:** 10 + 0 = **10**

---

## 4. Hasil Test Suite

- `php artisan test --filter=TotalRusakSemanticTest`: **6 passed (39 assertions)**
- `php artisan test --filter=ProductionStatus`: **53 passed (294 assertions)**
- `php artisan test`: **633 passed (3343 assertions)**
- `vendor/bin/pint --test`: **PASS (212 files, 0 issues)**
