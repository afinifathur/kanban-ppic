# IMPLEMENTATION REPORT — LOST WAX PHASE 1.1 REFINEMENT
## Laporan Barang Siap Oven: Integrasi PO, Penajaman Kuantitas & Penghapusan Kolom Berat

**Repository:** `C:\laragon\www\kanban-ppic`  
**Halaman Fokus:**
- Web View: `/lost-wax/reports/ready-for-oven`
- Print A4: `/lost-wax/reports/ready-for-oven/print`  
**Tanggal Implementasi:** 10 November 2026  
**Status:** **SELESAI (100% PASS — 46 Tests, 269 Assertions)**  
**Prinsip Utama:** Zero Migration (struktur DB tidak diubah), kalkulasi berat backend tetap dipertahankan untuk KPI, logika scan Layer 6/7 dan FIFO tidak disentuh.

---

## 1. LATAR BELAKANG & TUJUAN REFINEMENT PHASE 1.1

Berdasarkan evaluasi penggunaan operasional di bagian Lost Wax (Rangkai, Coating, dan Oven) serta tim PPIC, ditemukan kebutuhan penyempurnaan tampilan laporan Barang Siap Oven:

1. **Kolom Berat (KG) di Tabel Kurang Relevan bagi Operator Oven:**
   Operator penuangan/oven memerlukan perbandingan kuantitas unit (PCS) secara langsung terhadap Surat Pesanan (PO), bukan berat per baris tabel. Berat per baris mengambil ruang horizontal tabel yang berharga.
2. **Singkatan Ambigu pada Kuantitas PCS:**
   Format sebelumnya seperti `B:48 | A:0` membingungkan personel lapangan baru dan memicu misinterpretasi antara Bruto vs Afkir vs Layak Pakai.
3. **Ketiadaan Komparasi PO Langsung:**
   PPIC dan operator harus membuka dokumen terpisah atau menghafal kuantitas pesanan untuk mengetahui apakah barang yang siap masuk oven sudah mencukupi target pesanan atau masih kurang.
4. **Tujuan Utama Phase 1.1:**
   - Menghapus kolom **Berat (KG)** dari tabel utama dan layout cetak A4.
   - Menjaga kalkulasi berat tetap aktif di backend dan menyajikannya pada **KPI Summary Cards**.
   - Mengubah kolom Kuantitas menjadi **SIAP OVEN / PO** (format `220 / 200 PCS`).
   - Menyajikan badge selisih informatif: `Lebih [X] PCS` (emerald), `Kurang [X] PCS` (rose), atau `Sesuai PO` (emerald).
   - Menghilangkan singkatan ambigu dengan label eksplisit: `Siap Oven: [X] PCS`, `Bruto: [Y] PCS`, `Afkir: [Z] PCS`.
   - Memastikan kuantitas PO tidak terduplikasi saat satu kode produksi terbagi ke dalam beberapa Work Order di antrean.
   - Mempertahankan independensi **Status Batch** (`FULL BATCH` vs `PARTIAL BATCH`) dari status pemenuhan PO.

---

## 2. AUDIT SUMBER DATA PO & RELASI DATABASE

### A. Sumber Data Resmi PO (Zero Migration)

Sistem melacak kuantitas PO langsung dari tabel master transaksi yang telah ada:

```
[Modern Flow]
LostWaxTree
   ├── lost_wax_print_order_line_id
   │      └── LostWaxPrintOrderLine
   │             └── production_plan_id ──> ProductionPlan
   │                                          ├── po_number (varchar, nullable)
   │                                          └── po_quantity (unsigned int, nullable)
   └── LostWaxTreeAllocation (Multi-Allocation)
          └── lost_wax_print_order_line_id
                 └── LostWaxPrintOrderLine
                        └── production_plan_id ──> ProductionPlan
                                                     ├── po_number
                                                     └── po_quantity

[Legacy Flow]
LostWaxTree
   └── lost_wax_work_order_id
          └── LostWaxWorkOrder
                 ├── po_number (varchar, nullable)
                 └── po_quantity (unsigned int, nullable)
```

### B. Penanganan Kasus Khusus Data PO

1. **PO Null / Belum Diinput:**
   Jika `po_quantity` bernilai `null` atau `0`, sistem **tidak berasumsi** atau menebak angka. UI menampilkan `[Siap Oven] / - PCS` dengan badge kuning peringatan `PO belum ditentukan`.
2. **Pencegahan Duplikasi PO (Duplicate Guard):**
   Satu Kode Produksi (`ProductionPlan`) dapat memiliki beberapa Surat Perintah Kerja (`LostWaxRangkaiWorkOrder`) atau beberapa alokasi rangkai. Di antrean, masing-masing Work Order ditampilkan sebagai baris kelompok tersendiri untuk menjaga ketertelusuran SPK.
   - Jika sistem hanya menjumlahkan `po_quantity` dari setiap baris, kuantitas PO akan terlipatgandakan (misal PO 500 pcs pada 2 Work Order akan menjadi 1.000 pcs).
   - **Solusi Implementasi:** `ReadyForOvenService` menghimpun `distinctCodePoMap` berdasarkan `production_code`. Pada ringkasan (`summary.total_po_pcs`), setiap kode produksi hanya dihitung **satu kali**.

---

## 3. FILE YANG DIUBAH & RINGKASAN PERUBAHAN

| File | Peran & Modifikasi |
|---|---|
| `app/Services/LostWax/ReadyForOvenService.php` | - Mengekstrak `po_number` & `po_quantity` dari Plan dan Work Order.<br>- Mengakumulasi total PCS layak per kode produksi di seluruh antrean (`siap_oven_code_pcs`).<br>- Menghitung variansi PO per baris (`po_variance_pcs`, `po_variance_status`, `po_variance_text`).<br>- Menghitung ringkasan PO ter-deduplikasi (`total_po_pcs`, `overall_po_variance_text`).<br>- Tetap menghitung berat (`weight_kg`, `total_weight_kg`, `coverage_days`) untuk KPI. |
| `resources/views/lost-wax/reports/ready-for-oven/index.blade.php` | - Menghapus kolom header dan data `Estimasi Berat`.<br>- Mengubah kolom ke-6 menjadi `SIAP OVEN / PO`.<br>- Format baris: Komparasi `220 / 200 PCS`, badge selisih warna, dan rincian eksplisit `Siap Oven: X PCS`, `Bruto: Y PCS`, `Afkir: Z PCS`.<br>- KPI Card 2 diperbarui menjadi `Total Siap Oven / PO` dengan variansi keseluruhan.<br>- Footer disesuaikan menjadi 8 kolom. |
| `resources/views/lost-wax/reports/ready-for-oven/print.blade.php` | - Menghapus kolom `Berat (Kg)` dari tabel A4 portrait.<br>- Mengubah kolom menjadi `SIAP OVEN / PO` dengan styling cetak minimalis dan jelas.<br>- Mengganti singkatan `B:` dan `A:` dengan label eksplisit `Siap Oven`, `Bruto`, dan `Afkir`.<br>- Header toolbar dan KPI summary print diperbarui dengan komparasi PO. |
| `config/lost_wax.php` | Menyediakan konfigurasi default target harian (`daily_oven_target_kg = 2000`). |
| `tests/Feature/LostWax/ReadyForOvenPhase1Test.php` | Menambahkan 8 test method spesifik untuk memverifikasi logika variansi PO, independensi status batch, deduplikasi PO, serta konsistensi preview dan cetak. |
| `tests/Feature/LostWax/ReadyForOvenReportTest.php` | Menyesuaikan asersi kolom tabel cetak A4 dari `Jumlah Pcs` & `Berat (Kg)` ke `SIAP OVEN / PO`. |

---

## 4. FORMULA & LOGIKA BISNIS

### A. Akumulasi dan Variansi PO per Kode Produksi

1. **Total Siap Oven per Kode:**
   $$\text{siap\_oven\_code\_pcs} = \sum_{\text{antrean, kode sama}} \text{quantity\_pcs}$$
2. **Selisih terhadap PO:**
   $$\Delta = \text{siap\_oven\_code\_pcs} - \text{po\_quantity}$$
3. **Status dan Label Variansi:**
   - Jika $\Delta > 0$: Status `LEBIH`, teks: `Lebih [abs(Δ)] PCS` (Badge Hijau/Emerald)
   - Jika $\Delta < 0$: Status `KURANG`, teks: `Kurang [abs(Δ)] PCS` (Badge Merah/Rose)
   - Jika $\Delta = 0$: Status `SESUAI`, teks: `Sesuai PO` (Badge Hijau/Emerald)
   - Jika `po_quantity == null`: Status `NULL`, teks: `PO belum ditentukan` (Badge Kuning/Amber)

### B. Independensi Status Batch vs Pemenuhan PO

Status Batch merefleksikan **kelengkapan fisik pelapisan pohon dalam satu Work Order**, bukan ketercapaian target PO:
- **FULL BATCH:** $100\%$ pohon dari Work Order terkait telah tuntas proses pelapisan keramik (Layer 6 atau Layer 7). Kondisi ini tetap `FULL BATCH` meskipun kuantitas PCS masih di bawah PO (`Kurang X PCS`).
- **PARTIAL BATCH:** Sebagian pohon dalam Work Order masih dalam proses dipping/pengeringan. Kondisi ini tetap `PARTIAL BATCH` meskipun pohon yang sudah tuntas lapisannya secara kebetulan sudah melebihi PO (`Lebih X PCS`).

---

## 5. PERBANDINGAN TAMPILAN (SEBELUM VS SESUDAH)

### A. Tabel Utama (Web View)

| Aspek | Sebelum (Phase 1.0) | Sesudah (Phase 1.1) |
|---|---|---|
| **Jumlah Kolom** | 9 Kolom | **8 Kolom** (Tabel lebih lega) |
| **Kolom Berat** | Ada kolom tersendiri `Estimasi Berat` | **Dihapus dari tabel** (Disajikan ringkas pada KPI & Footer) |
| **Header Kolom Kuantitas** | `Kuantitas (Pcs)` | `SIAP OVEN / PO` |
| **Penyajian Angka Utama** | `48` Pcs Layak | `220 / 200 PCS` |
| **Indikator Variansi PO** | Tidak ada | Badge interaktif: `+ Lebih 20 PCS` / `- Kurang 20 PCS` / `Sesuai PO` |
| **Rincian Komponen** | `B: 48 \| A: 0` (Singkatan ambigu) | **Eksplisit:**<br>`Siap Oven: 48 PCS`<br>`Bruto: 48 PCS`<br>`Afkir: 0 PCS` |
| **Catatan Baris Split** | Tidak ada | `*Baris ini: X dari total Y Siap Oven` (jika kode terbagi di beberapa SPK) |

### B. Format Cetak A4 Portrait

| Aspek | Sebelum (Phase 1.0) | Sesudah (Phase 1.1) |
|---|---|---|
| **Kolom Tabel Cetak** | No, Kode & SPK, Item, Customer, Rangkai, Jumlah Pcs, **Berat (Kg)**, Status, Waktu | No, Kode & SPK, Item, Customer, Rangkai, **SIAP OVEN / PO**, Status, Waktu |
| **Lebar Kolom Item** | Tertekan oleh kolom Berat (sering wrap baris) | Lebih luas dan nyaman dibaca saat diprint |
| **Rincian Pcs Cetak** | `B: 48 \| A: 0` | Label penuh: `Siap Oven: 48 PCS`, `Bruto: 48 PCS`, `Afkir: 0 PCS` |
| **Ringkasan KPI Cetak** | Kotak Berat, Coverage, Rangkai | Ditambahkan KPI Box `Siap Oven / PO` dengan variansi |

---

## 6. HASIL PENGUJIAN OTOMATIS (8 SKENARIO WAJIB)

Seluruh 8 skenario pengujian bisnis yang disyaratkan telah dibuat dan lulus verifikasi 100% pada `tests/Feature/LostWax/ReadyForOvenPhase1Test.php`:

```powershell
php artisan test --filter=ReadyForOven
```

### Rincian Hasil Eksekusi Test:

1. **`test_siap_oven_above_po_displays_lebih`** (PASS)
   - Setup: PO = 200 PCS, Siap Oven = 220 PCS.
   - Asersi: `siap_oven_code_pcs = 220`, `po_quantity = 200`, `po_variance_status = LEBIH`, `po_variance_text = 'Lebih 20 PCS'`.
   - HTML: Merender `220 / 200`, `Lebih 20 PCS`, `Siap Oven: 220 PCS`, `Bruto: 220 PCS`, `Afkir: 0 PCS`.
2. **`test_siap_oven_below_po_displays_kurang`** (PASS)
   - Setup: PO = 200 PCS, Siap Oven = 180 PCS.
   - Asersi: `po_variance_status = KURANG`, `po_variance_text = 'Kurang 20 PCS'`.
   - HTML: Merender `180 / 200`, `Kurang 20 PCS`, `Siap Oven: 180 PCS`.
3. **`test_siap_oven_equal_po_displays_sesuai_po`** (PASS)
   - Setup: PO = 200 PCS, Siap Oven = 200 PCS.
   - Asersi: `po_variance_status = SESUAI`, `po_variance_text = 'Sesuai PO'`, selisih = 0.
   - HTML: Merender `200 / 200`, `Sesuai PO`.
4. **`test_full_batch_with_pcs_below_po`** (PASS)
   - Setup: 5 dari 5 pohon tuntas lapisannya (40 pcs/pohon = 200 pcs total). Target PO = 300 PCS.
   - Asersi: Status Batch tetap `FULL BATCH` (100% pohon tuntas), sedangkan variansi PO menampilkan `Kurang 100 PCS`. Membuktikan independensi kedua status.
5. **`test_full_batch_with_pcs_above_po`** (PASS)
   - Setup: 4 dari 4 pohon tuntas lapisannya (50 pcs/pohon = 200 pcs total). Target PO = 150 PCS.
   - Asersi: Status Batch `FULL BATCH`, variansi PO menampilkan `Lebih 50 PCS`.
6. **`test_partial_batch_with_pcs_above_po`** (PASS)
   - Setup: Work Order berisi 6 pohon. 3 pohon tuntas (50 pcs/pohon = 150 pcs siap oven), 3 pohon masih celup layer 2. Target PO = 120 PCS.
   - Asersi: Status Batch tetap `PARTIAL BATCH` (3 / 6 TREE COATED), meskipun kuantitas siap oven sudah `Lebih 30 PCS` dari PO.
7. **`test_single_code_multiple_work_orders_deduplicates_po_in_summary`** (PASS)
   - Setup: Plan PO = 500 PCS. Terbagi ke WO-1 (150 pcs) dan WO-2 (200 pcs).
   - Asersi: Antrean membagi menjadi 2 baris terpisah (`tree_count` 3 & 4), keduanya mengetahui akumulasi total kode adalah 350 pcs. Pada Summary Total, `total_po_pcs = 500` (TIDAK terduplikasi menjadi 1.000). Variansi total: `Kurang 150 PCS`.
8. **`test_preview_and_print_render_identical_siap_oven_po_metrics`** (PASS)
   - Setup: Endpoint web index dan cetak dokumen A4.
   - Asersi: Kedua view mengembalikan HTTP 200, merender kolom `SIAP OVEN / PO`, menampilkan angka dan badge yang identik, serta tidak lagi memuat tag header `Berat (Kg)` / `Estimasi Berat`.

---

## 7. PANDUAN VERIFIKASI LOKAL & UAT

Untuk memverifikasi secara langsung di lingkungan lokal:

1. Pastikan server dev lokal berjalan:
   ```powershell
   php artisan serve --port=8000
   ```
2. Login sebagai pengguna dengan hak akses PPIC / Admin:
   - URL: `http://localhost:8000/login`
   - Kredensial: `adminppicpf@peroniks.com` / `password`
3. Buka halaman Laporan Barang Siap Oven:
   - URL Web: `http://localhost:8000/lost-wax/reports/ready-for-oven`
   - Perhatikan kolom ke-6 berjudul **SIAP OVEN / PO**.
   - Periksa ketiadaan kolom **Estimasi Berat** pada tabel.
   - Periksa KPI Card ke-2 bertuliskan **Total Siap Oven / PO**.
4. Klik tombol merah **Cetak Dokumen A4**:
   - URL Cetak: `http://localhost:8000/lost-wax/reports/ready-for-oven/print`
   - Periksa tabel A4: tidak ada kolom Berat, kolom `SIAP OVEN / PO` menyajikan angka komparasi dan rincian `Siap Oven: ...`, `Bruto: ...`, `Afkir: ...`.
5. Jalankan suite test kapan pun:
   ```powershell
   php artisan test --filter=ReadyForOven
   ```
   Output: **46 passed (269 assertions)**.

---
*Laporan ini disusun sebagai dokumentasi resmi implementasi Phase 1.1 Modul Lost Wax Kanban PPIC.*

