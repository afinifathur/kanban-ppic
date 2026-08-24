# Walkthrough — Lost Wax Production Print, Overprint, Outcomes & Assembly Redesign

Semua perbaikan dan fitur untuk **Daily Print Order, Overprint, PDF Text Wrapping, Outcomes Cancelled Document Filter, dan Assembly Work Order Print Layout (A4 Portrait)** pada Lost Wax telah selesai diimplementasikan secara kokoh dan diuji menggunakan test suite lengkap (seluruh **283 tes** dalam keadaan **GREEN**).

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

---

## 2. PDF Text Wrapping & Truncation (Presentation Layer)

### A. Perubahan Styling dan CSS Layout
- **[`print.blade.php`](file:///c:/laragon/www/kanban-ppic/resources/views/lost-wax/print-orders/print.blade.php)**:
  - Menghapus class CSS `truncate` dari semua sel informasi teknis pada Form 1 dan Form 2.
  - Menerapkan Tailwind CSS classes `break-all text-[10px] leading-tight` pada kolom data tanpa spasi (`code` / `NO.PO / SPK / Kode Cust`) untuk memaksa perataan vertikal (wrapping) browser yang rapi.
  - Menerapkan `break-words text-[10px] leading-tight` pada kolom deskripsi kata natural (`size`, `aisi`, dan `customer`).

### B. Koreksi Typo Properti AISI
- **Koreksi Typo**: Mengubah properti `$line->isi` yang salah pada template Blade menjadi **`$line->aisi`** agar nilai AISI ditarik langsung dari database dan ter-render di kertas PDF secara lengkap (sebelumnya tercetak kosong `-`).

---

## 3. Outcomes Cancelled Document Filter (Query Layer)

- **[`OutcomeController::index()`](file:///c:/laragon/www/kanban-ppic/app/Http/Controllers/LostWax/OutcomeController.php#L16)**:
  Mengubah penyaringan data pada main query:
  ```php
  // Sebelum
  ->where('status', '!=', 'DRAFT')
  
  // Sesudah
  ->whereNotIn('status', ['DRAFT', 'CANCELLED'])
  ```
- Ini mengeliminasi seluruh dokumen `CANCELLED` dari level database (server-side) sehingga aman dari pencarian, pagination, maupun listing default.

---

## 4. Assembly Work Order Print Layout (A4 Portrait Paper dengan A5 Form)

Format cetak Rangkai Work Order didesain ulang total untuk lantai produksi (shop-floor traceability ticket):

- **KODE PRODUKSI**: Dibuat sangat menonjol dengan font tebal (`text-xl font-black`) di dalam box abu-abu tebal untuk identifikasi stiker keranjang.
- **AMBIL & RANGKAI (Qty)**: Dibuat sangat prominent di box kuning (`text-xl font-black`) menampilkan target ambil parsial (misal `100 PCS`).
- **Detail Spesifikasi Produk**: Product Name, Customer, AISI, Size, No. Print Order, dan Tanggal.
- **Visual Reference Area**: Area `REFERENSI GAMBAR` diperbesar maksimal (`min-h-[80px]` placeholder, `max-h-[90px]` image dengan `object-fit: contain` untuk visual tajam bebas distorsi aspect ratio).
- **Hasil Aktual Rangkai (Tulis Tangan)**: Area khusus tulis tangan bagi operator untuk mencatat: `Qty Diambil`, `Qty Good`, `Qty Defect`, `Tanggal`, dan `Jam (Mulai - Selesai)`.
- **Signatures**: Area tanda tangan Operator, Supervisor Rangkai, dan PPIC/Admin.
- **Revisi Media Cetak (A4 Portrait)**:
  - `@page` diatur ke **`size: A4 portrait; margin: 0;`** agar dicetak di atas kertas A4 Portrait standar.
  - Dimensi fisik form `.print-page` tetap dipertahankan persis **`width: 210mm; height: 148mm;`** (box-sizing: border-box) di bagian setengah atas kertas A4.
  - Penambahan garis potong horizontal minimalis `.cut-guide` (dashed line tipis tanpa teks) tepat di batas `148mm` di bawah form untuk mempermudah pemotongan kertas secara fisik.
  - Penggunaan `page-break-after: avoid;` untuk menjamin tidak memicu halaman kedua kosong di kertas A4.

---

## 5. Test Suite Verification

Seluruh test suite berhasil dijalankan dan berstatus **GREEN** (283 Passed, 1735 Assertions):

```powershell
Tests:    283 passed (1735 assertions)
Duration: 15.85s
```

Test cases baru untuk RWO Print Layout yang ditambahkan & diperbarui:
- **`test_assembly_work_order_print_view_renders_correct_data_and_layout`**: Memverifikasi rendering data di view print RWO berjalan sukses, memastikan rule `@page { size: A4 portrait; margin: 0; }` dan `.print-page` `210mm x 148mm` ter-render dengan benar, serta memverifikasi keberadaan class `.cut-guide` dan ketiadaan teks `"CUT / POTONG"`.

---

## 6. Dokumentasi Audit

Laporan audit lengkap dapat diakses di:
1. Daily Print & Overprint: [`docs/architecture/print-order-daily-planning-audit.md`](file:///c:/laragon/www/kanban-ppic/docs/architecture/print-order-daily-planning-audit.md)
2. PDF Text Wrapping: [`docs/architecture/print-order-pdf-wrapping-audit.md`](file:///c:/laragon/www/kanban-ppic/docs/architecture/print-order-pdf-wrapping-audit.md)
3. Outcomes Cancelled Filter: [`docs/architecture/outcomes-cancelled-filter-audit.md`](file:///c:/laragon/www/kanban-ppic/docs/architecture/outcomes-cancelled-filter-audit.md)
4. Assembly Work Order Print: [`docs/architecture/assembly-work-order-print-audit.md`](file:///c:/laragon/www/kanban-ppic/docs/architecture/assembly-work-order-print-audit.md)

> **Status Database Migration**: **NO MIGRATION REQUIRED**
> **Final Verdict**: **READY FOR PRODUCTION**
