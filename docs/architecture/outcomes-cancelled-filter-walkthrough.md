# Walkthrough — Lost Wax Production PPIC Print, Overprint & Outcomes Filter

Semua perubahan untuk mendukung **Daily Print Order, Overprint, PDF Text Wrapping, dan Outcomes Cancelled Document Filter** pada Lost Wax telah selesai diimplementasikan secara aman dan diuji menggunakan test suite lengkap (seluruh **282 tes** dalam keadaan **GREEN**).

---

## 1. Daily Print Order & Overprint (Workflow & Validasi)

### A. Perubahan Validasi
- **`PrintExecutionService::record()` & `update()`**: Menghapus validasi outstanding (`newTotal > currentOutstanding`). Menambahkan validasi preventif kuantitas negatif (`qtyGood < 0 || qtyDefect < 0`).
- **`OutcomeController::adjustExecutionsToMatch()`**: Menghapus validasi overprint (`targetGood + targetDefect > line->qty_ordered`).
- **`outcomes/edit.blade.php` (JS)**: Menghapus warning validation client-side yang menonaktifkan tombol submit apabila input melebihi sisa outstanding.

### B. Kuantitas Semantik & Accessors Baru
- **`qty_produced`** pada [`ProductionPlan`](file:///c:/laragon/www/kanban-ppic/app/Models/ProductionPlan.php):
  Dihitung berdasarkan `SUM(qty_executed_good)` dari seluruh print order lines yang status dokumen parent-nya bukan `CANCELLED`. Ini menjamin hanya barang "Good" yang mengurangi sisa target produksi aktual.
- **`qty_remaining_to_produce`** pada [`ProductionPlan`](file:///c:/laragon/www/kanban-ppic/app/Models/ProductionPlan.php):
  Dihitung dengan `max(0, qty_planned - qty_produced)`.
- **`qty_outstanding`** pada [`LostWaxPrintOrderLine`](file:///c:/laragon/www/kanban-ppic/app/Models/LostWaxPrintOrderLine.php):
  Tetap menggunakan `max(0, qty_ordered - good - defect)` untuk display UI agar sisa outstanding tidak negatif secara visual ke user.
- **Status Completion**:
  Di dalam `PrintExecutionService::updateLineAggregates()`, outstanding murni dihitung secara internal (`qty_ordered - goodSum - defectSum`). Status order berubah menjadi `COMPLETED` apabila outstanding internal tersebut `<= 0`.

---

## 2. PDF Text Wrapping & Truncation (Presentation Layer)

### A. Perubahan Styling dan CSS Layout
- **[`print.blade.php`](file:///c:/laragon/www/kanban-ppic/resources/views/lost-wax/print-orders/print.blade.php)**:
  - Menghapus class CSS `truncate` dari semua sel informasi teknis pada Form 1 dan Form 2.
  - Menerapkan Tailwind CSS classes `break-all text-[10px] leading-tight` pada kolom data tanpa spasi (`code` / `NO.PO / SPK / Kode Cust`) untuk memaksa perataan vertikal (wrapping) browser yang rapi.
  - Menerapkan `break-words text-[10px] leading-tight` pada kolom deskripsi kata natural (`size`, `aisi`, dan `customer`).
  - Hal ini memaksa teks panjang yang melebihi porsi kolom tetap tercetak lengkap dengan turun ke baris berikutnya dan mempertinggi row secara dinamis, alih-alih dipotong dengan `...`.

### B. Koreksi Typo Properti AISI
- **Koreksi Typo**: Mengubah properti `$line->isi` yang salah pada template Blade menjadi **`$line->aisi`** agar nilai AISI ditarik langsung dari database dan ter-render di kertas PDF secara lengkap (sebelumnya tercetak kosong `-`).

---

## 3. Outcomes Cancelled Document Filter (Query Layer)

### A. Perubahan Query Server-Side
- **[`OutcomeController::index()`](file:///c:/laragon/www/kanban-ppic/app/Http/Controllers/LostWax/OutcomeController.php#L16)**:
  Mengubah penyaringan data pada main query:
  ```php
  // Sebelum
  ->where('status', '!=', 'DRAFT')
  
  // Sesudah
  ->whereNotIn('status', ['DRAFT', 'CANCELLED'])
  ```
- Ini mengeliminasi seluruh dokumen `CANCELLED` dari level database (server-side) sehingga aman dari pencarian, pagination, maupun listing default.

### B. Downstream Safety
- Data historis dokumen `CANCELLED` tetap tersimpan aman di database (tidak dihapus/dimodifikasi).
- Tidak ada query tambahan per row (regresi N+1 nihil).

---

## 4. Test Suite Verification

Seluruh test suite berhasil dijalankan dan berstatus **GREEN** (282 Passed, 1697 Assertions):

```powershell
Tests:    282 passed (1697 assertions)
Duration: 15.77s
```

Test cases baru untuk Outcomes Cancelled Filter yang ditambahkan:
- **`test_cancelled_print_orders_are_filtered_from_outcomes_list`**: Memastikan dokumen `ISSUED` tampil di daftar Outcomes sedangkan dokumen `CANCELLED` tidak muncul.
- **`test_searching_for_cancelled_document_returns_empty_result`**: Memastikan pencarian dokumen `CANCELLED` via query string menghasilkan hasil kosong.

---

## 5. Dokumentasi Audit

Laporan audit lengkap dapat diakses di:
1. Daily Print & Overprint: [`docs/architecture/print-order-daily-planning-audit.md`](file:///c:/laragon/www/kanban-ppic/docs/architecture/print-order-daily-planning-audit.md)
2. PDF Text Wrapping: [`docs/architecture/print-order-pdf-wrapping-audit.md`](file:///c:/laragon/www/kanban-ppic/docs/architecture/print-order-pdf-wrapping-audit.md)
3. Outcomes Cancelled Filter: [`docs/architecture/outcomes-cancelled-filter-audit.md`](file:///c:/laragon/www/kanban-ppic/docs/architecture/outcomes-cancelled-filter-audit.md)

> **Status Database Migration**: **NO MIGRATION REQUIRED**
> **Final Verdict**: **READY FOR PRODUCTION**
