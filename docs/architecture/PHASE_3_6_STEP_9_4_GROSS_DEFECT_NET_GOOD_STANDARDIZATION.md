# PHASE 3.6 STEP 9.4 — IMPLEMENT GROSS → DEFECT → NET GOOD STANDARDIZATION

## 1. Executive Summary

Standarisasi semantik kuantitas pada alur Cetak (Moulding) Lost Wax berhasil diimplementasikan:
- **Cetak Counter/Gross:** Kuantitas mesin/total aktual pekerjaan operator (`qty_gross_output`).
- **Defect/Cacat:** Kuantitas cacat lilin cetak (`qty_defect`).
- **Net Good:** Kuantitas barang bagus yang valid masuk ke Material Pool (`qty_good = max(0, qty_gross_output - qty_defect)`).
- **Material Pool / FIFO:** Menggunakan basis `qty_executed_good` (yang sudah terstandarisasi sebagai NET GOOD).
- **Rangkai, Layer 1–7, Oven:** Tetap melanjutkan konsumsi dan pergerakan NET/usable quantities tanpa perubahan semantik yang tidak perlu.

---

## 2. Database Schema & Migration

- **Migration:** `2026_09_03_170000_add_qty_gross_output_to_lost_wax_print_executions.php`
- **Field Baru:** `lost_wax_print_executions.qty_gross_output` (unsigned integer, nullable).
- Bersifat non-destructive dan 100% backward compatible.

---

## 3. Form Cetak / Outcomes UI Update

Pada modal Catat Hasil (`resources/views/lost-wax/outcomes/edit.blade.php`):
- `Hasil Cetak / Counter (pcs)` (`modalInputGross`)
- `Cacat / Defect (pcs)` (`modalInputDefect`)
- `Hasil Good / Net (pcs)` (`modalInputGood`, auto-calculated real-time: `Counter - Cacat`)

---

## 4. Normalisasi Data Historis

Artisan Command: `php artisan lost-wax:normalize-gross`
- **Zero Defect (149 records):** `qty_gross_output = qty_good`, `qty_good` tetap.
- **GROSS Pattern (16 records):** `qty_gross_output = old_good`, `qty_good = old_good - defect`.
- **NET Pattern (5 records):** `qty_gross_output = old_good + defect`, `qty_good = old_good`.
- Semua agregat pada `lost_wax_print_order_lines` diperbarui otomatis via `PrintExecutionService::updateLineAggregates`.

---

## 5. Hasil Kasus UAT 268L651

- `PC-20260826-0007`: Gross = 210, Defect = 10, Net Good = 200
- `PC-20260827-0003`: Gross = 210, Defect = 0, Net Good = 210
- `PC-20260827-0004`: Gross = 210, Defect = 0, Net Good = 210
- `PC-20260829-0002`: Gross = 210, Defect = 0, Net Good = 210
- **Total Gross Cetak:** 840 pcs
- **Total Defect Cetak:** 10 pcs
- **Total Net Good Cetak:** 830 pcs
- **Alokasi Rangkai (FIFO Konsumsi):** 144 pcs
- **Sisa Line 0007:** 200 - 144 = **56 pcs**
- **Sisa Line 0003, 0004, 0002:** masing-masing **210 pcs**
- **TOTAL AVAILABLE POOL:** 56 + 210 + 210 + 210 = **686 pcs**
- **Production Status / Standby:** 830 - 144 = **686 pcs**

---

## 6. Verifikasi Test Suite

- `php artisan test --filter=GrossDefectNetGoodFlowTest`: 9 passed (40 assertions)
- `php artisan test`: **627 passed (3304 assertions)**
- `vendor/bin/pint --test`: **PASS (211 files, 0 issues)**
