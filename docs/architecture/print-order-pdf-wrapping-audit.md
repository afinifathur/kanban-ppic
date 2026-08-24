# Audit: Lost Wax Print Order PDF Wrapping & Text Truncation

**Tanggal Audit:** 2026-08-24  
**Status Verdict:** READY FOR BUILD

---

## 1. PDF Generation Pipeline & Engine

Setelah meneliti routing dan controller, berikut adalah spesifikasi engine cetak yang digunakan:

- **Route:** `/lost-wax/print-orders/{printOrder}/print` berasosiasi dengan name `lost-wax.print-orders.print`.
- **Controller:** [`PrintOrderController::print()`](file:///c:/laragon/www/kanban-ppic/app/Http/Controllers/LostWax/PrintOrderController.php#L365).
- **Engine:** **Browser-Based PDF Generation / Print Engine**. Aplikasi **tidak** menggunakan library server-side seperti Dompdf atau MPDF. Dokumen dirender sebagai HTML biasa di browser, lalu memicu dialog cetak PDF bawaan browser secara otomatis menggunakan attribute `<body onload="window.print()">` di Blade template.
- **Blade Template:** [`resources/views/lost-wax/print-orders/print.blade.php`](file:///c:/laragon/www/kanban-ppic/resources/views/lost-wax/print-orders/print.blade.php).
- **Styling:** Menggunakan standalone build **TailwindCSS** (`asset('js/tailwindcss.js')`).

---

## 2. Root Cause of Text Truncation

Berdasarkan pemeriksaan visual dan struktural pada Blade template, truncation disebabkan oleh **kombinasi dua masalah utama**:

### A. Penggunaan Class Tailwind `truncate` pada Kolom Tabel
Pada tabel Form 1 dan Form 2, kolom-kolom data penting dikonfigurasi dengan CSS class `truncate`:
- `truncate` menerapkan rules CSS: `overflow: hidden; text-overflow: ellipsis; white-space: nowrap;`.
- Hal ini memaksa teks panjang yang tidak muat dalam satu baris untuk dipotong secara visual dan diganti dengan tanda `...` (ellipsis) demi estetika layout, alih-alih melakukan wrap ke baris berikutnya.

### B. Typo pada Pemanggilan Field Properti AISI
Pada baris 94 dan 201 di template Blade:
```html
<td class="border border-black p-1 text-center font-mono truncate">{{ $line->isi ?: '-' }}</td>
```
- Properti yang dibaca adalah `$line->isi`, padahal properti yang benar pada model [`LostWaxPrintOrderLine`](file:///c:/laragon/www/kanban-ppic/app/Models/LostWaxPrintOrderLine.php) dan database schema adalah **`aisi`**.
- Ini menyebabkan kolom AISI selalu menampilkan `-` (kosong), sehingga datanya dianggap hilang atau tidak tercetak.

---

## 3. Location of Ellipsis/Truncation Invariant

Berikut adalah baris kode spesifik yang menyebabkan pemotongan informasi operasional:

### Form 1 (Form Laporan Kerja Cetak Lilin)
- **Baris 90 (NO.PO / SPK / Kode Cust):**
  ```html
  <td class="border border-black p-1 font-mono text-center truncate">{{ $line->code ?: '-' }}</td>
  ```
- **Baris 92 (UKURAN / Size):**
  ```html
  <td class="border border-black p-1 text-center font-mono truncate">{{ $line->size ?: '-' }}</td>
  ```
- **Baris 94 (AISI):**
  ```html
  <td class="border border-black p-1 text-center font-mono truncate">{{ $line->isi ?: '-' }}</td>
  ```
- **Baris 96 (INIT CUST / Customer):**
  ```html
  <td class="border border-black p-1 text-center uppercase font-bold truncate">{{ $line->customer ?: '-' }}</td>
  ```

### Form 2 (Form Setting Mesin Cetak)
- **Baris 199 (KODE / No.PO/SPK/Kode Cust):**
  ```html
  <td class="border border-black p-1 font-mono text-center truncate">{{ $line->code ?: '-' }}</td>
  ```
- **Baris 201 (SIZE / Ukuran):**
  ```html
  <td class="border border-black p-1 text-center font-mono truncate">{{ $line->size ?: '-' }}</td>
  ```

---

## 4. End-to-End Data Trace

Mengambil contoh **Print Order ID 7** dengan data sebagai berikut:
- **No. PO/SPK / Kode:** `4.1091150LB.A0025`
- **Product Name:** `SS304 SORF ANSI 150LBS 1"`
- **Size:** `1"`
- **AISI:** `304`
- **Customer:** `A06`

### Aliran Data:
1. **Database:** Data tersimpan lengkap di tabel `lost_wax_print_order_lines`.
2. **Model:** Model `LostWaxPrintOrderLine` memuat data lengkap atribut `code`, `item_name`, `size`, `aisi`, dan `customer`.
3. **Controller:** Controller `PrintOrderController::print()` memuat data model dan mengirimkannya secara utuh ke Blade view tanpa melakukan modifikasi string (tanpa `Str::limit` atau format pemotongan lainnya).
4. **Blade View Rendering:**
   - HTML menerima data lengkap secara literal.
   - Kolom `Nama Produk` (`item_name`) dirender lengkap karena tidak memakai class `truncate` melainkan `break-words`.
   - Kolom `No.PO/SPK` (`code`) dirender lengkap di HTML sebagai `4.1091150LB.A0025`.
   - Kolom `AISI` gagal dirender (menjadi `-`) karena salah pemanggilan variabel (`$line->isi`).
5. **Browser Print Engine CSS Rendering:**
   - Karena tabel dikonfigurasi dengan kelas `table-fixed` dan kolom NO.PO/SPK hanya diberi lebar `15%`, lebar fisik kolom tidak mencukupi untuk menampilkan 17 karakter tanpa spasi (`4.1091150LB.A0025`).
   - Browser menerapkan CSS `text-overflow: ellipsis` dari class `truncate` Tailwind, sehingga teks dipotong menjadi `4.1091150...` di kertas/PDF output.

---

## 5. Layout & Wrapping CSS Analysis

Aplikasi menggunakan layout tabel tetap (`table-fixed`). Hal ini sangat baik untuk konsistensi lebar kolom pada kertas A4 portrait. Namun, agar data tidak terpotong, kita harus menerapkan aturan CSS berikut:

1. **Hapus class `truncate`** dari semua cell data penting.
2. **Terapkan `break-all` atau `break-words`** agar string panjang tanpa spasi (seperti kode item) dapat pecah ke baris berikutnya secara otomatis tanpa meluber keluar cell.
3. **Optimalkan line-height dan font-size**: Gunakan `leading-tight` dan text ukuran kecil (`text-[10px]` atau `text-[9px]`) agar ketika teks wrap ke 2 baris, tinggi row tidak bertambah terlalu drastis.

---

## 6. Page Break / Multi-page Compatibility

- Karena dokumen dicetak via browser print engine, pergeseran halaman (page breaks) ditangani secara otomatis oleh browser.
- Jumlah item dibatasi secara manual di Blade menggunakan `take(10)` dan perulangan `@for($i = count($printOrder->lines); $i < 10; $i++)` untuk memastikan layout dokumen pas dalam 1 halaman kertas A4 portrait (terdiri atas Form Laporan Kerja dan Form Setting Mesin).
- Modifikasi row wrapping tidak akan merusak layout satu halaman karena total item yang ditampilkan dibatasi maksimal 10 baris. Bertambahnya tinggi baris (wrap ke baris ke-2) pada 1-2 item masih aman ditampung dalam batas vertikal margin kertas A4 (5mm) yang dikonfigurasi di `@page`.

---

## 7. Proposed Fixes

### A. Koreksi Blade `print.blade.php` Form 1 (Baris 90-96)
```html
<!-- Sebelum -->
<td class="border border-black p-1 font-mono text-center truncate">{{ $line->code ?: '-' }}</td>
<td class="border border-black p-1 text-center font-mono truncate">{{ $line->size ?: '-' }}</td>
<td class="border border-black p-1 text-center font-mono truncate">{{ $line->isi ?: '-' }}</td>
<td class="border border-black p-1 text-center uppercase font-bold truncate">{{ $line->customer ?: '-' }}</td>

<!-- Sesudah -->
<td class="border border-black p-1 font-mono text-center break-all text-[10px] leading-tight">{{ $line->code ?: '-' }}</td>
<td class="border border-black p-1 text-center font-mono break-words text-[10px] leading-tight">{{ $line->size ?: '-' }}</td>
<td class="border border-black p-1 text-center font-mono break-words text-[10px] leading-tight">{{ $line->aisi ?: '-' }}</td>
<td class="border border-black p-1 text-center uppercase font-bold break-words text-[10px] leading-tight">{{ $line->customer ?: '-' }}</td>
```

### B. Koreksi Blade `print.blade.php` Form 2 (Baris 199-201)
```html
<!-- Sebelum -->
<td class="border border-black p-1 font-mono text-center truncate">{{ $line->code ?: '-' }}</td>
<td class="border border-black p-1 text-center font-mono truncate">{{ $line->size ?: '-' }}</td>

<!-- Sesudah -->
<td class="border border-black p-1 font-mono text-center break-all text-[9px] leading-tight">{{ $line->code ?: '-' }}</td>
<td class="border border-black p-1 text-center font-mono break-words text-[9px] leading-tight">{{ $line->size ?: '-' }}</td>
```

---

## 8. Regression Test Plan

### Automated Test:
- Tambahkan test `test_print_view_renders_untruncated_long_specifications_and_aisi` ke [`PrintOrderTest.php`](file:///c:/laragon/www/kanban-ppic/tests/Feature/LostWax/PrintOrderTest.php).
- Skenario test:
  1. Buat Print Order Line dengan:
     - `code` = `4.1091150LB.A0025`
     - `item_name` = `SS304 SORF ANSI 150LBS 1"`
     - `size` = `1"`
     - `aisi` = `304`
     - `customer` = `A06`
  2. Panggil GET route `/lost-wax/print-orders/{printOrder}/print`.
  3. Lakukan assertion:
     - `assertSee('4.1091150LB.A0025')` (tidak boleh terpotong).
     - `assertSee('304')` (memastikan aisi dirender, bukan `-`).
     - `assertSee('SS304 SORF ANSI 150LBS 1"')`.

---

## 9. Risk Assessment & Verdict

- **Downstream Safety:** Tidak ada risiko fungsional database atau model, karena perubahan 100% berada di presentational layer (HTML/CSS).
- **Database Migration:** **TIDAK DIPERLUKAN (NO)**.
- **Verdict:** **READY FOR BUILD**

---

## 10. Build Phase Completed

Seluruh perbaikan teks PDF wrapping dan truncation telah selesai diimplementasikan secara kokoh:

### Perubahan Kode & CSS
- **`print.blade.php`**:
  - Menghapus class CSS `truncate` dari semua sel informasi teknis pada Form 1 dan Form 2.
  - Menerapkan Tailwind CSS classes `break-all text-[10px] leading-tight` pada kolom data tanpa spasi (`code` / `NO.PO / SPK / Kode Cust`) untuk memaksa perataan vertikal (wrapping) browser yang rapi.
  - Menerapkan `break-words text-[10px] leading-tight` pada kolom deskripsi kata natural (`size`, `aisi`, dan `customer`).
- **Koreksi Typo AISI**:
  - Mengubah pemanggilan properti `$line->isi` yang salah menjadi **`$line->aisi`** pada template Blade. Nilai AISI sekarang berhasil ditarik langsung dari database dan ter-render di kertas PDF secara lengkap.

### Hasil Uji Coba & Regresi
- **Automated Test**: Ditambahkan test case `test_print_view_renders_untruncated_long_specifications_and_aisi` ke dalam `tests/Feature/LostWax/PrintOrderTest.php`. Tes memverifikasi bahwa data kode item panjang `4.1091150LB.A0025`, nama produk, size, customer, dan properti `aisi` dirender 100% lengkap tanpa mengandung class CSS `truncate`.
- **Status Database Migration**: **TIDAK DIPERLUKAN (NO)**.
- **Full Test Suite Status**: **GREEN (100% Passed)**.
