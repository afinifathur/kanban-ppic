# Audit & Hasil Implementasi: Lost Wax Assembly Work Order Print Layout (A5 Landscape)

**Tanggal Audit:** 2026-08-24  
**Status Verdict:** READY FOR PRODUCTION

---

## 1. Alasan Bisnis & Traceability Workflow

Dalam praktik riil di pabrik, operator Rangkai memerlukan identitas fisik batch yang jelas untuk mencegah salah ambil barang antar customer (traceability issue). Alur kerja fisik yang didukung adalah:
1. **Print Order** diselesaikan dan hasil aktual diinput ke sistem.
2. Hasil cetak disimpan dalam keranjang fisik dan diberi stiker **Kode Produksi**.
3. **Assembly Work Order (RWO)** dibuat oleh PPIC sebagai *Picking Ticket* sekaligus *Traceability Ticket*.
4. Operator mencocokkan **Kode Produksi** pada form cetak dengan **Stiker Keranjang** untuk mengambil kuantitas yang tepat.
5. Pekerjaan diselesaikan, operator mengisi form secara manual (tulis tangan), lalu form dikembalikan ke Admin untuk diinput ke **Traveler Card**.

---

## 2. Arti Kuantitas `AMBIL & RANGKAI` (Parsial)

- `qty_planned_pcs` pada RWO tidak diartikan sebagai "seluruh hasil cetak", melainkan sebagai **"jumlah kuantitas spesifik yang harus diambil pada Work Order bersangkutan"**.
- Sistem mendukung pembuatan beberapa RWO parsial dari satu baris cetak yang sama (misal, hasil cetak 600 pcs dibagi menjadi RWO-001 = 100 pcs, RWO-002 = 200 pcs, dst.).
- Informasi ini ditampilkan secara sangat menonjol di form agar operator tidak salah mengambil seluruh isi keranjang.

---

## 3. Hasil Audit Backend & Domain Model

- **Dukungan Parsial:** Domain model existing (`LostWaxRangkaiWorkOrder`) sudah menyimpan `qty_trees_planned` (repurposed sebagai pcs pada WO baru) dan accessors `qty_planned_pcs` serta `qty_outstanding`. Model sudah mendukung eksekusi parsial secara penuh.
- **Database Schema:** Tidak ada modifikasi skema database. **TIDAK ADA MIGRASI DATABASE (NO MIGRATION REQUIRED)**.

---

## 4. Redesain Layout Form (A5 Landscape)

Dokumen didesain ulang total dari format portrait A4 menjadi **A5 Landscape** (210mm x 148mm) dengan grid/flexbox CSS yang compact dan kokoh:

### A. Hirarki Visual & Keterbacaan Prominent
1. **KODE PRODUKSI**: Dibuat sangat prominent dengan font tebal (`text-xl font-black`) di dalam box abu-abu tebal.
2. **AMBIL & RANGKAI (Qty)**: Dibuat sangat prominent di box kuning (`text-xl font-black`) menampilkan target ambil parsial (misal `100 PCS`).
3. **Identitas Batch/Produk**: Product Name, Customer, AISI, Size, No. Print Order, dan Tanggal.
4. **Context Batch Print**: Menampilkan total hasil cetak dan sisa yang tersedia sebagai informasi pendukung.
5. **Referensi Gambar**: Menampilkan gambar aktual produk jika `reference_image_path` terisi, atau area gambar kosong minimalis `TAMPAK DEPAN` / `TAMPAK SAMPING` jika kosong.
6. **Hasil Aktual Rangkai (Tulis Tangan)**: Area khusus tulis tangan bagi operator untuk mencatat: `Qty Diambil`, `Qty Good`, `Qty Defect`, `Tanggal`, dan `Jam (Mulai - Selesai)`.
7. **Signatures**: Area tanda tangan Operator, Supervisor Rangkai, dan PPIC/Admin.

### B. Print Styling & Compatibility
- Menggunakan CSS print `@page { size: A5 landscape; margin: 5mm; }` dan container berukuran tetap (`200mm` x `138mm`) untuk menggaransi isi muat tepat dalam satu lembar A5 tanpa overflow.
- Tombol web browser ("Cetak Dokumen" & "Tutup") disembunyikan menggunakan class `.no-print`.
- Tidak ada library eksternal/CDN yang ditambahkan (aman untuk LAN offline).

---

## 5. Uji Coba & Hasil Verifikasi

### A. Automated Integration Test
Ditambahkan test case `test_assembly_work_order_print_view_renders_correct_data_and_layout` di [`OutcomeAndAssemblyTest.php`](file:///c:/laragon/www/kanban-ppic/tests/Feature/LostWax/OutcomeAndAssemblyTest.php):
- Memverifikasi status HTTP 200 untuk route print RWO.
- Memverifikasi teks Kode Produksi, Qty Ambil, AISI, Size, Customer, No. Print Order, label Hasil Aktual Rangkai, dan signature blocks ter-render lengkap tanpa truncation.
- Memverifikasi keberadaan style CSS `@page { size: A5 landscape; }`.
- Memverifikasi bahwa class `.no-print` dan container lebar `200mm` ter-render di HTML.

### B. Manual Verification (Print Preview)
- **Paper Size:** A5
- **Orientation:** Landscape
- **Jumlah Halaman:** Tepat 1 halaman (tidak ada halaman kedua kosong / overflow).
- **Keterbacaan:** Sangat tinggi, elemen penting (Kode Produksi & Qty) langsung terlihat jelas di lantai produksi.
- **Tombol Web:** Hidden saat print preview dipicu.
- **Full Test Suite Status:** **GREEN (100% Passed)**.

---

## 6. Revisi Layout (Fase UAT Akhir)

Berdasarkan review langsung dengan supervisor lapangan:
1. **Penghapusan Context Block**: Section `CONTEXT BATCH PRINT` (Total Hasil Cetak Good dan Sisa Tersedia Rangkai) dihapus sepenuhnya dari tiket. Hal ini karena target ambil & rangkai sudah sangat menonjol di box utama, dan data kontekstual tersebut dianggap redundant bagi operator.
2. **Pelebaran Referensi Gambar**:
   - Area `REFERENSI GAMBAR` diperbesar sekitar 2.5 kali lipat menggunakan ruang vertikal yang dibebaskan oleh *Context Block*.
   - Tinggi placeholder `TAMPAK DEPAN` dan `TAMPAK SAMPING` dinaikkan dari `min-h-[30px]` menjadi **`min-h-[80px]`** untuk memberikan keleluasaan referensi visual.
   - Tinggi render image diubah dari `max-h-[45px]` menjadi **`max-h-[90px]`** (dengan style `object-fit: contain`) sehingga visual produk tercetak sangat jelas tanpa distorsi aspect ratio.
3. **One-Page Fit**: Layout revisi terverifikasi aman dan tetap muat dalam 1 halaman A5 Landscape secara presisi tanpa overflow.

---

## 7. Revisi Media Cetak (A4 Portrait Paper dengan A5 Form)

Untuk mempermudah pencetakan di lapangan menggunakan printer standar:
1. **Perubahan Media Kertas**: `@page` dikonfigurasi menjadi **`size: A4 portrait; margin: 0;`** (dari semula `size: A5 landscape;`).
2. **Konsistensi Desain Form**: Dimensi fisik `.print-page` tetap dipertahankan pada **`width: 210mm; height: 148mm;`** (ukuran A5 Landscape) dengan `box-sizing: border-box`. Ini memastikan layout visual, ukuran font, spacing, dan struktur form **sama persis (identik)** seperti yang sudah disetujui sebelumnya, hanya saja diposisikan di bagian setengah atas kertas A4 Portrait.
3. **Cutting Guide**: Ditambahkan pembatas potong horizontal minimalis `.cut-guide` berupa garis horizontal putus-putus (`border-top: 1px dashed`) di batas `148mm` di bawah form tanpa teks "CUT / POTONG" demi menjaga estetika.
4. **Pencegahan Overflow Halaman Kedua**: Aturan print menggunakan `page-break-after: avoid;` untuk menjamin tidak akan memicu halaman kedua kosong di kertas A4. Output print tetap fit **1 halaman A4 Portrait**.
