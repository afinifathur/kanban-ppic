# IMPLEMENTASI LOST WAX PHASE 1.2
## Simplifikasi Laporan Barang Siap Oven — Grouping Kode Produksi & Master-Detail

**Tanggal:** 10 November 2026  
**Repository:** `C:\laragon\www\kanban-ppic`  
**Target URLs:**  
- Web: `/lost-wax/reports/ready-for-oven`  
- Print: `/lost-wax/reports/ready-for-oven/print`  
**Referensi Audit:** `AUDIT_LOST_WAX_GROUPING.md` & `IMPLEMENTATION_LOST_WAX_PHASE1_1.md`  
**Status:** **SELESAI (100% PASS, Zero Migration)**

---

## 1. RINGKASAN IMPLEMENTASI

Phase 1.2 menyelesaikan masalah kebingungan operator pada laporan Barang Siap Oven akibat munculnya satu kode produksi pada beberapa baris tabel. Pada Phase 1.1, pengelompokan menyertakan `work_order_key` (SPK Rangkai), sehingga kode produksi yang terbagi ke dalam banyak SPK (seperti `268IL435` yang memiliki 5 SPK) muncul berulang kali dengan angka agregat `320 / 1.440 PCS` yang sama persis di setiap barisnya, disertai label ambigu seperti `48 dari 320` dan status `FULL BATCH` (karena SPK individu selesai coating) padahal total PCS masih kurang dari PO.

### Solusi yang Diimplementasikan:
1. **Grouping 1 Baris per Kode Produksi:**
   Tabel utama di web dan cetak A4 kini menyajikan tepat **1 baris master per Kode Produksi** (`production_code`). Tidak ada lagi baris ganda untuk item/kode yang sama.
2. **Penyajian Kuantitas Bersih:**
   Angka Siap Oven dan PO ditampilkan satu kali secara elegan:
   `320 / 1.440 PCS` disertai badge status selisih:
   - `Lebih X PCS` (Hijau) jika Siap Oven > PO
   - `Kurang X PCS` (Merah/Rose) jika Siap Oven < PO
   - `Sesuai PO` (Hijau) jika Siap Oven == PO
   - `PO belum ditentukan` (Kuning/Amber) jika rencana belum memiliki kuantitas PO
3. **Penyederhanaan UI & Penghapusan `Status Batch` dari Tabel:**
   - Kolom `Status Batch` (`FULL BATCH` / `PARTIAL BATCH`) dihilangkan dari tabel web dan cetak A4 karena membingungkan operator (SPK selesai coating disalahartikan sebagai PO terpenuhi).
   - Logika dan kalkulasi backend coating completion tetap dipertahankan utuh pada service untuk kebutuhan analytics/audit.
   - Catatan berulang seperti `Baris: 48 dari 320`, `Bruto: 48 PCS`, `Afkir: 0 PCS` dihapus dari baris utama.
4. **Master-Detail SPK Rangkai (Collapsible):**
   - Kolom baru **Rincian** menyediakan tombol `Lihat Rincian` yang membuka sub-tabel rincian SPK secara collapsible (default tertutup/rapi).
   - Rincian SPK menampilkan: Nomor SPK, Jumlah Pohon Fisik, PCS Siap Oven, Tanggal/Jam Selesai Coating, Nomor Rak (jika ada), dan Daftar Barcode Pohon Fisik.
5. **Layout Cetak A4 Compact Master-Detail:**
   - Pada lembar cetak A4, setiap kode produksi disajikan dalam 1 baris ringkas, diikuti baris rincian SPK yang kompak di bawahnya tanpa memakan banyak ruang vertikal.
6. **Integritas FIFO & Zero Migration:**
   - Baris master diurutkan berdasarkan pohon fisik yang paling awal menyelesaikan lapisan (`finished_at ASC`).
   - Rincian SPK diurutkan berdasarkan SPK yang paling awal selesai.
   - Tidak ada migrasi database (`0 migration`).

---

## 2. STRUKTUR DATA BARU (TIER 1 VS TIER 2)

Pipeline pengelompokan data pada `app/Services/LostWax/ReadyForOvenService.php` ditata ulang menjadi arsitektur dua tingkat (Two-Tier Aggregation):

```
Queue Members (Pohon Fisik Layer 6/7)
   │
   ▼
[Tier 1: Parent / Master Grouping]
   Key: {production_code}|{item_name}|{customer}|{finished_stage}
   ├── production_code, item_name, customer, po_number, po_quantity
   ├── tree_count (Total pohon fisik semua SPK)
   ├── quantity_pcs (Total PCS siap oven akumulatif)
   ├── po_variance_pcs, po_variance_status, po_variance_text
   ├── finished_at (Timestamp pohon terawal untuk pengurutan FIFO Master)
   ├── work_order_count (Jumlah SPK)
   │
   └──► [Tier 2: Children / SPK Sub-Collection: 'work_orders']
           Key: {work_order_key}
           ├── work_order_number (cth: RWO-20261104-0030)
           ├── work_order_type (RANGKAI / INJECTION)
           ├── tree_count (Pohon pada SPK ini)
           ├── quantity_pcs (PCS pada SPK ini)
           ├── finished_at, finished_at_formatted
           ├── is_full_batch, coating_status, coating_progress_text
           ├── racks_compact (cth: RAK-01, RAK-02)
           ├── barcodes (Daftar barcode fisik pada SPK ini)
           └── members (Detail pohon pada SPK ini)
```

---

## 3. RUMUS AGREGASI DAN PENANGANAN KUANTITAS

### A. Pada Baris Master (Per Kode Produksi)
- $\text{tree\_count} = \sum \text{pohon fisik unik pada seluruh SPK kode tersebut}$
- $\text{quantity\_pcs} = \sum (\text{gross\_pcs} - \text{defect\_pcs})$
- $\text{po\_quantity} = \text{kuantitas PO dari Production Plan}$
- $\text{po\_variance\_pcs} = |\text{quantity\_pcs} - \text{po\_quantity}|$
- **Variance Status:**
  - `LEBIH` jika $\text{quantity\_pcs} > \text{po\_quantity}$
  - `KURANG` jika $\text{quantity\_pcs} < \text{po\_quantity}$
  - `SESUAI` jika $\text{quantity\_pcs} == \text{po\_quantity}$
  - `BELUM_DITENTUKAN` jika $\text{po\_quantity} \text{ null}$

### B. Pada Sub-Collection SPK (Rincian)
- $\text{SPK quantity\_pcs} = \sum \text{PCS pohon fisik milik SPK tersebut}$
- $\text{SPK tree\_count} = \text{jumlah pohon fisik milik SPK tersebut}$
- $\sum (\text{SPK quantity\_pcs}) = \text{Master quantity\_pcs}$ (Konsisten tanpa selisih)

### C. Pada Summary KPI (Footer & Header)
- $\text{total\_po\_pcs} = \sum_{\text{distinct plan}} \text{po\_quantity}$  
  *(Deduplikasi ketat: jika 1 kode memiliki 5 SPK, PO hanya dihitung 1 kali)*
- $\text{total\_usable\_pcs} = \sum \text{quantity\_pcs}$
- $\text{total\_groups} = \text{jumlah baris master (Kode Produksi unik)}$
- $\text{total\_spk\_count} = \text{jumlah seluruh SPK Rangkai yang aktif di antrean}$

---

## 4. PENELUSURAN KASUS KODE `268IL435`

### A. Mengapa Sebelumnya Muncul Berulang?
Pada database aktual, kode produksi `268IL435` (`BRACKET HOUSING 435`, Customer `E01`, PO `1.440 PCS`) aktif di antrean Siap Oven sebanyak 20 pohon fisik (@ 16 pcs = 320 pcs). Ke-20 pohon ini berasal dari **5 SPK Rangkai yang berbeda**:
1. `RWO-20261104-0030`: 3 pohon = 48 pcs (selesai 04/11/2026 15:30)
2. `RWO-20261104-0040`: 7 pohon = 112 pcs (selesai 04/11/2026 18:00)
3. `RWO-20261104-0056`: 1 pohon = 16 pcs (selesai 04/11/2026 21:15)
4. `RWO-20261104-0058`: 3 pohon = 48 pcs (selesai 05/11/2026 08:30)
5. `RWO-20261105-0018`: 6 pohon = 96 pcs (selesai 05/11/2026 14:00)

Pada Phase 1.1, karena `work_order_key` masuk ke kunci pengelompokan utama, data dipecah menjadi baris-baris terpisah. Ketika tabel menampilkan angka akumulasi `320 / 1.440 PCS` pada baris pertama (SPK 48 pcs), operator mengira baris tersebut berisi 320 pcs padahal kontribusinya hanya 48 pcs, dan angka `320 / 1.440 PCS` muncul lagi di baris lainnya.

### B. Mengapa Sebelumnya Berstatus `FULL BATCH`?
Masing-masing dari ke-5 SPK tersebut telah menyelesaikan seluruh pohon yang direncanakan pada SPK-nya masing-masing (misal SPK 0058 membuat 3 pohon dan ke-3 pohonnya sudah selesai coating = $3/3$ coated). Indikator batch coating menganggap SPK tersebut `FULL BATCH`. Namun operator menyangka `FULL BATCH` berarti seluruh pesanan PO 1.440 PCS sudah selesai, padahal baru 320 PCS.

### C. Tampilan Baru pada Phase 1.2
- **Tabel Utama (Web & Print):** Tepat **1 baris** untuk `268IL435`.
  - Jumlah Rangkai: **20 pohon**
  - SIAP OVEN / PO: **`320 / 1.440 PCS`**
  - Badge Status: **`Kurang 1.120 PCS`** (Merah)
  - Sub-label Kode: **5 SPK Rangkai**
  - Kolom Status Batch: **Dihapus**
- **Detail (Klik 'Lihat Rincian'):** Terbuka sub-tabel rapi merinci ke-5 SPK secara urut FIFO (SPK 0030 selesai terawal s/d SPK 0018).

---

## 5. PENANGANAN FIFO (MASTER VS DETAIL)

1. **Pengurutan Baris Master (Tabel Utama):**
   - Baris master diurutkan berdasarkan `finished_at ASC` dari pohon fisik yang **paling awal selesai coating** di antara seluruh SPK kode tersebut.
   - Dengan aturan ini, kode produksi yang pohon pertamanya sudah mengantre lebih lama akan selalu berada di urutan atas (prinsip FIFO murni tidak terganggu).
2. **Pengurutan Sub-Collection SPK (Rincian):**
   - Di dalam baris master, daftar SPK diurutkan berdasarkan `finished_at ASC` dari masing-masing SPK.
   - Operator dapat langsung mengetahui SPK mana yang harus dimasukkan ke dalam Oven terlebih dahulu.

---

## 6. DAFTAR FILE YANG DIMODIFIKASI

| No | File | Deskripsi Perubahan |
|:---|:---|:---|
| 1 | `app/Services/LostWax/ReadyForOvenService.php` | - Mengubah pengelompokan Tier 1 menjadi per `production_code\|item_name\|customer\|finished_stage`.<br>- Mengelompokkan Tier 2 (`work_orders`) per `work_order_key`.<br>- Menambahkan field `work_orders`, `work_order_count`, `has_multiple_spk`.<br>- Menambahkan `total_spk_count` pada summary.<br>- Mempertahankan backend coating progress & FIFO sorting. |
| 2 | `resources/views/lost-wax/reports/ready-for-oven/index.blade.php` | - Menyajikan 1 baris per Kode Produksi.<br>- Menghapus kolom `Status Batch`.<br>- Memformat angka dengan standar Indonesia (`320 / 1.440 PCS`).<br>- Menambahkan tombol dan baris detail collapsible `Lihat Rincian`.<br>- Merapikan sub-tabel rincian SPK. |
| 3 | `resources/views/lost-wax/reports/ready-for-oven/print.blade.php` | - Layout A4 portrait 1 baris master per kode.<br>- Menghapus kolom `Status Batch`.<br>- Menyajikan sub-baris rincian SPK yang kompak di bawah baris master.<br>- Menyesuaikan card KPI summary (Kode Produksi & SPK Rangkai). |
| 4 | `tests/Feature/LostWax/ReadyForOvenPhase1Test.php` | - Memperbarui test grouping ganda menjadi 1 baris master dengan sub-collection SPK.<br>- Memperbarui verifikasi HTML preview dan print (menghapus ekspektasi `Status Batch` dan label redundan).<br>- Menambahkan test komprehensif `test_phase1_2_code_268il435_exact_uat_scenario` mereplikasi kasus nyata. |

---

## 7. HASIL PENGUJIAN OTOMATIS (AUTOMATED TESTING)

### A. Test Suite `ReadyForOvenPhase1Test`
```powershell
php artisan test --filter=ReadyForOvenPhase1Test
```
**Hasil:**
- **Tests:** 28 passed
- **Assertions:** 222 assertions
- **Status:** **100% PASS**

Daftar skenario pengujian utama:
1. `test_two_different_work_orders_do_not_mix_into_same_row`: Dua SPK berbeda untuk kode yang sama menghasilkan 1 baris master dengan 2 entri di sub-collection `work_orders`.
2. `test_single_code_multiple_work_orders_deduplicates_po_in_summary`: Total PO pada summary tidak terduplikasi (tetap 500 pcs untuk 2 SPK 150 & 200 pcs).
3. `test_preview_and_print_render_identical_siap_oven_po_metrics`: Sinkronisasi angka layar dan cetak, memastikan kolom `Status Batch` tidak muncul di antarmuka operator.
4. `test_phase1_2_code_268il435_exact_uat_scenario`: Replikasi penuh kasus `268IL435` (PO 1.440, 5 SPK, 20 pohon, 320 pcs siap oven, Kurang 1.120 PCS, urutan FIFO SPK terverifikasi, deduplikasi KPI summary).

### B. Test Suite `ReadyForOvenReportTest`
```powershell
php artisan test --filter=ReadyForOvenReportTest
```
**Hasil:**
- **Tests:** 19 passed
- **Assertions:** 97 assertions
- **Status:** **100% PASS**

### C. Code Formatting (Laravel Pint)
```powershell
vendor/bin/pint app/Services/LostWax/ReadyForOvenService.php tests/Feature/LostWax/ReadyForOvenPhase1Test.php
```
**Hasil:** `PASS (2 files cleanly formatted)`.

---

## 8. PERBANDINGAN SEBELUM VS SESUDAH

| Aspek | Phase 1.1 (Sebelum) | Phase 1.2 (Sesudah) |
|:---|:---|:---|
| **Baris per Kode** | Kode `268IL435` muncul berulang di beberapa baris (tergantung jumlah SPK). | Tepat **1 baris master** per kode produksi. |
| **Penyajian SIAP OVEN / PO** | `320 / 1.440 PCS` diulang di setiap baris SPK, membingungkan operator. | `320 / 1.440 PCS` disajikan **satu kali** pada baris master dengan badge `Kurang 1.120 PCS`. |
| **Status Batch** | Muncul kolom `Status Batch` bertuliskan `FULL BATCH` (salah diartikan pesanan PO selesai). | **Dihapus dari tabel operator**, perhitungan coating progress dipindahkan ke detail/backend. |
| **Rincian SPK** | Teks redundan `Baris: 48 dari 320`, `B:48`, `A:0` memenuhi baris utama. | Tersimpan rapi di dalam tombol collapsible **`Lihat Rincian`** (default collapsed). |
| **Lembar Cetak A4** | Baris panjang berulang per SPK dengan kolom Status Batch. | 1 baris master per kode dengan rincian kompak SPK di bawahnya. |

---

## 9. RESIDUAL RISK & REKOMENDASI UNTUK PHASE 2 (CASTING / COR)

1. **Kesiapan Menuju Scan Cor & Input Hasil Cor:**
   Struktur master-detail ini memisahkan secara tegas entitas **Kode Produksi / PO (Kebutuhan Casting)** dengan **Pohon Fisik / Barcode (WIP Aktual)**. Ketika nanti operator melakukan Scan Masuk Cor atau Input Hasil Cor, alokasi pohon fisik dapat langsung ditautkan ke heat cor tanpa risiko salah tafsir kuantitas PO.
2. **Kuantitas PO Kosong (`po_quantity == null`):**
   Sistem telah menangani kondisi ini secara anggun dengan status `PO belum ditentukan` (badge kuning). Namun direkomendasikan agar tim PPIC melengkapi kuantitas PO pada Production Plan sebelum pohon fisik mencapai tahap oven.
3. **Penyimpanan State Toggle:**
   Saat ini tombol `Lihat Rincian` menggunakan pure vanilla JavaScript toggle. Tampilan awal selalu rapi (collapsed), sehingga memudahkan operator membaca ringkasan antrean oven secara sekilas tanpa scrolling panjang.

