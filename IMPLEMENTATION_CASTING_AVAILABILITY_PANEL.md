# IMPLEMENTASI LIVE CASTING AVAILABILITY & LINE DISTRIBUTION PANEL — SAND CASTING

**Dokumen Laporan Pekerjaan:** Implementasi Panel Ketersediaan Produksi dan Distribusi Beban Line  
**Lokasi Repository:** `c:\laragon\www\kanban-ppic` (Lokal Laragon)  
**Halaman Implementasi:** `Perintah Cor — Cor Pasir (Sand Casting)` (`/sand-casting/casting-orders/plans`)  
**Status Eksekusi:** SELESAI & TERVERIFIKASI (Semua 64 automated test passed)

---

## 1. Ringkasan Eksekutif & Tujuan

Sebelum PPIC menerbitkan Dokumen Perintah Cor (PCOR), PPIC memerlukan visibilitas instan terhadap:
1. Berapa total kuota rencana cor pasir yang masih tersedia (dalam PCS dan KG).
2. Proporsi market antara customer **LOCAL** dan **EXPORT** yang siap diperintahkan.
3. Sebaran beban produksi di **Line 1 s/d Line 4**, termasuk identifikasi jika ada line yang tidak memiliki demand sama sekali (**Tidak ada PO**).
4. Bagaimana alokasi item yang sedang dipilih pada kalkulator komposisi secara langsung (live/reaktif) mengurangi sisa kuota yang tersedia di lapangan tanpa memerlukan reload halaman.

Seluruh kebutuhan di atas telah diimplementasikan dalam bentuk **Live Casting Availability & Line Distribution Panel** yang diletakkan pada area kosong horizontal sebelah kanan judul utama halaman tanpa menggeser atau mengubah struktur layout tabel, tab, filter, sticky calculator, maupun sidebar.

---

## 2. Arsitektur Data & Aturan Bisnis

### A. Basis Perhitungan Kuota Tersedia (Model A)
Panel menghitung ketersediaan kuota berdasarkan mekanisme **Model A** yang berlaku di sistem Kanban PPIC:
$$\text{Qty Sisa Dapat Diperintah} = \max(0, \text{qty\_planned} - \text{qty\_casting\_scheduled})$$
Di mana `qty_casting_scheduled` adalah subquery SQL komitmen order dari seluruh PCOR aktif berstatus `DRAFT`, `ISSUED`, `PARTIALLY_COMPLETED`, dan `COMPLETED`.

Item yang sudah tertutup (`is_closed = true`) atau kuotanya sudah terpenuhi secara penuh dieksklusikan dari kuota tersedia.

### B. Cakupan Data Lintas Halaman (Unpaginated Baseline)
Tabel Rencana Cor dipaginasi per 100 baris. Agar panel availability tidak terdistorsi atau terpotong hanya pada 100 baris halaman pertama, controller menghitung `$baselineAvailability` mencakup **seluruh data rencana aktif di database** yang sesuai dengan filter pencarian aktif user:
```php
$baselineItems = (clone $plansQuery)->get();
```

### C. Penanda Filter Aktif (`filter_active`)
Jika PPIC memasukkan parameter pencarian (misal filter kode, customer, tanggal input, atau status non-aktif), panel secara otomatis menyesuaikan baseline ketersediaan dengan parameter tersebut dan memunculkan badge amber informatif:
`<span id="panel-filter-active-badge"><i class="fas fa-filter"></i> Filter Aktif</span>`

### D. Klasifikasi Market Customer
Menggunakan resolver resmi `\App\Models\Customer::resolveCategory($customerName, $customerCategoryMap)`:
- `STOK NON PO` $\rightarrow$ `LOCAL`
- `STOK EXP` $\rightarrow$ `EXPORT`
- Master Customer dengan kategori `LOCAL` $\rightarrow$ `LOCAL`
- Master Customer dengan kategori `EXPORT` $\rightarrow$ `EXPORT`
- Selain itu $\rightarrow$ `UNCLASSIFIED` (ditampilkan di total dan metrik terkait agar jumlah pcs/kg selalu presisi).

### E. Distribusi per Line (Line 1–4 & Line 0)
- Setiap item memiliki `originLine` dari `production_plans.line_number` (1..4).
- Jika `line_number` belum diisi (0 atau di luar 1..4), item dicatat sebagai `Line 0` (unassigned) sehingga total keseluruhan selalu seimbang:
$$\text{Total PCS} = \text{Line}_1 + \text{Line}_2 + \text{Line}_3 + \text{Line}_4 + \text{Line}_0$$
- Jika suatu line memiliki demand 0 PCS, sistem menampilkan nilai `0 pcs`, `0.00 kg`, `0.0%`, dan badge:
`<span class="bg-slate-100 text-slate-400 text-[9px] font-bold px-1.5 py-0.5 rounded">Tidak ada PO</span>`

---

## 3. Desain UI & Layout Responsif

Panel diletakkan pada `@section('top_bar')` di `resources/views/sand-casting/casting-orders/plans.blade.php`:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
| [Header Top Bar]                                                                                                                        |
|                                                                                                                                         |
| [Judul & Subtitle]                                [Live Casting Availability & Line Distribution Panel]                                 |
| Perintah Cor — Cor Pasir (Sand Casting)           +-----------------------+ +---------------------+ +--------------------------------+ |
| Alokasi dan penerbitan instruksi penuangan cor    | TOTAL TERSEDIA        | | MARKET              | | DISTRIBUSI LINE 1–4            | |
| [Filter Aktif]*                                   | Avail: 450 pcs / 1.5t | | LOCAL: 300 (sisa 250)| | L1: 44.4%  Sisa: 150/200 pcs   | |
|                                                   | Sisa : 375 pcs / 1.2t | | EXPORT: 150 (sisa 125)| | L2: 33.3%  Sisa: 100/150 pcs   | |
|                                                   +-----------------------+ +---------------------+ | L3: 22.2%  Sisa: 100/100 pcs   | |
|                                                                                             | L4:  0.0%  [Tidak ada PO]      | |
|                                                                                             +--------------------------------+ |
+-----------------------------------------------------------------------------------------------------------------------------------------+
```

### Karakteristik Desain:
1. **Desktop View (`2xl:`):** Panel berada secara horizontal di area kosong sebelah kanan judul utama.
2. **Narrow / Tablet View:** Kontainer `flex-col 2xl:flex-row` memungkinkan panel turun ke baris berikutnya secara rapi tanpa clipping karena `#app-main-header` telah diatur ke `height: auto !important; min-height: 3.5rem;`.
3. **Card 1 (Ringkasan Total):** Menampilkan Total Tersedia (pcs & kg) dan Sisa Kuota (pcs & kg).
4. **Card 2 (Customer Market):** Menampilkan breakdown LOCAL dan EXPORT (tersedia & sisa pcs/kg).
5. **Card 3 (Distribusi Line 1–4):** Grid 4 card mini untuk Line 1, 2, 3, 4 dengan persentase distribusi dari total, sisa vs total tersedia, total kg, breakdown `L: pcs | E: pcs`, serta badge "Tidak ada PO" bila demand = 0.

---

## 4. Mekanisme Live Reaktif (JavaScript)

Tanpa reload halaman, JavaScript pada `plans.blade.php` bereaksi secara real-time terhadap seluruh interaksi user:
- **Centang / Hapus Centang Baris (`.plan-checkbox`):** Mengurangi atau mengembalikan sisa kuota total, sisa per kategori customer, dan sisa demand per `originLine`.
- **Perubahan Input Qty (`.plan-qty-input`):** Menyesuaikan alokasi kuota secara proporsional.
- **Perubahan Pilihan Line (`.plan-line-select`):** Mengalokasikan target line tanpa merusak `originLine` demand sumber.
- **Pilih Semua (`#check-all`) / Reset Pilihan (`#clear-selection-btn`):** Menghitung ulang seluruh metrik secara instan.
- **Persistensi State Lintas Halaman:** Menyimpan `originLine` di dalam `selectedMeta` dan `sessionStorage` sehingga ketika user berpindah halaman paginasi, kalkulasi ketersediaan tetap konsisten.

---

## 5. Berkas yang Diubah & Dibuat

| Berkas | Perubahan |
|---|---|
| `app/Http/Controllers/SandCasting/CastingOrderController.php` | Menghitung agregasi `$baselineAvailability` (total, categories, lines, items, filter_active) secara unpaginated dari klon query yang sama dan meneruskannya ke view. |
| `resources/views/sand-casting/casting-orders/plans.blade.php` | Menambahkan komponen panel pada `@section('top_bar')`, menyisipkan atribut `data-origin-line` pada checkbox baris, serta memperluas fungsi JS `syncSummary()` untuk memperbarui seluruh elemen DOM panel secara live. |
| `tests/Feature/SandCasting/LiveCastingAvailabilityPanelTest.php` | Test suite baru berisikan 7 test case komprehensif untuk memvalidasi akurasi perhitungan data panel. |

---

## 6. Verifikasi Automated Testing

Pengujian dilakukan menggunakan PHPUnit pada SQLite in-memory:

```
php artisan test --filter="LiveCastingAvailabilityPanelTest|CastingOrderTest|CastingOrderModelATest|OverPlanCastingResultTest|PcorReopeningCorrectionTest"
```

### Hasil Uji:
1. `LiveCastingAvailabilityPanelTest`:
   - `✓ total local and export equals overall total accounting for unclassified`
   - `✓ total line 1 to 4 equals overall total accounting for unassigned lines`
   - `✓ kg and pcs consistency with product weight resolver`
   - `✓ line with zero po displays zero pcs and tidak ada po`
   - `✓ filter on customer or code updates baseline correctly with filter active true`
   - `✓ plans page view renders all panel elements successfully`
   - `✓ items already completed in pcor are excluded from available quota`
2. Seluruh suite regresi (`CastingOrderTest`, `CastingOrderModelATest`, `OverPlanCastingResultTest`, `PcorReopeningCorrectionTest`):
   - **Total Tests: 64 passed (636 assertions)**
   - **Durasi: 8.84s**
   - **Errors / Failures: 0**

---

## 7. Kesimpulan

Fitur **Live Casting Availability & Line Distribution Panel** telah selesai diimplementasikan dengan sempurna di repository lokal Laragon, memenuhi seluruh batasan desain, menjaga keutuhan antarmuka yang ada, dan lulus verifikasi otomatis secara menyeluruh. Tidak ada perubahan yang disentuh pada server atau database production.

