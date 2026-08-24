# Walkthrough — Lost Wax Print Planning & PDF Wrapping Features

Semua perubahan untuk mendukung **Daily Print Order, Overprint, dan PDF Text Wrapping** pada Lost Wax telah selesai diimplementasikan secara aman dan diuji menggunakan test suite lengkap (seluruh **280 tes** dalam keadaan **GREEN**).

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

### C. Downstream Safety
- **Rangkai / Tree Generation**: Terjamin aman karena `qty_available_for_rangkai` menggunakan `qty_executed_good - allocated_trees`. Ketika overprint terjadi, jumlah good yang tersedia bertambah, yang mana secara fisik dan bisnis memang boleh dirangkai.
- **Over-Scheduling Protection**: Tetap aman dan dipertahankan. Pembuatan Print Order baru melebihi Planned Qty akan memicu warning di UI dan dihitung sebagai negatif di `qty_remaining_scheduled`.

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

## 3. Test Suite Verification

Seluruh test suite berhasil dijalankan dan berstatus **GREEN** (280 Passed, 1691 Assertions):

```powershell
Tests:    280 passed (1691 assertions)
Duration: 16.25s
```

Test cases berikut berhasil ditambahkan dan disesuaikan:
- **`test_actual_can_exceed_command_qty`**: Memverifikasi Command=200, Actual=201 berhasil disimpan dengan status `COMPLETED`.
- **`test_large_overprint_is_allowed`**: Memverifikasi Command=200, Actual=250 berhasil disimpan tanpa data korup.
- **`test_overprint_does_not_change_planned_qty`**: Memverifikasi overprint actual (250) tidak merubah `Planned Qty` (330) maupun sisa `Remaining to Schedule` (130).
- **`test_negative_quantity_rejected`**: Memverifikasi input negatif memicu `InvalidArgumentException`.
- **`test_print_view_renders_untruncated_long_specifications_and_aisi`**: Memverifikasi data kode item panjang `4.1091150LB.A0025`, nama produk, size, customer, dan properti `aisi` dirender 100% lengkap tanpa mengandung class CSS `truncate` di halaman cetak.
- **`test_outcome_validation_allows_exceed_ordered`** (di [`OutcomeAndAssemblyTest`](file:///c:/laragon/www/kanban-ppic/tests/Feature/LostWax/OutcomeAndAssemblyTest.php)): Menyesuaikan test case lama agar menerima overprint.

---

## 4. Dokumentasi Audit

Laporan audit lengkap dapat diakses di:
1. Daily Print & Overprint: [`docs/architecture/print-order-daily-planning-audit.md`](file:///c:/laragon/www/kanban-ppic/docs/architecture/print-order-daily-planning-audit.md)
2. PDF Text Wrapping: [`docs/architecture/print-order-pdf-wrapping-audit.md`](file:///c:/laragon/www/kanban-ppic/docs/architecture/print-order-pdf-wrapping-audit.md)

> **Status Database Migration**: **NO MIGRATION REQUIRED**
> **Final Verdict**: **READY FOR PRODUCTION**
