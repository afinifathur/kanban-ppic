# IMPLEMENTASI RESTRUKTURISASI HEADER & PANEL AVAILABILITY PERINTAH COR (SAND CASTING)

Dokumen ini mendokumentasikan implementasi restrukturisasi UI pada header halaman **Perintah Cor — Cor Pasir (Sand Casting)** (`resources/views/sand-casting/casting-orders/plans.blade.php`) di repository lokal Laragon `C:\laragon\www\kanban-ppic`.

Tujuan utama restrukturisasi ini adalah:
1. Meringkas judul halaman dan menghapus deskripsi panjang yang tidak diperlukan.
2. Menempatkan seluruh informasi ketersediaan (Sisa Kuota, Market LOCAL/EXPORT, Line 1–4) dalam **satu baris horizontal** di sebelah kanan judul pada desktop.
3. Menjaga tinggi header tetap sangat kompak (target 80–90 px, tercapai ~65–70 px) sehingga ruang vertikal untuk navigasi tab dan tabel alokasi termaksimalkan.
4. Mempertahankan seluruh logika interaktif, kalkulator komposisi, pembagian beban line berbasis berat (kg), serta integritas Model A dan PCOR lifecycle.

---

## 1. Perubahan Desain dan Layout

### A. Judul Halaman yang Ringkas
- **Sebelumnya**:
  Judul: `"Perintah Cor — Cor Pasir (Sand Casting)"`
  Deskripsi: `"Alokasi dan penerbitan instruksi penuangan cor pasir berdasarkan rencana produksi"`
- **Sesudah**:
  - Judul Utama: **Perintah Cor** (`text-base font-extrabold text-slate-900`)
  - Subjudul: **Cor Pasir (Sand Casting)** (`text-[10px] font-semibold text-slate-500`)
  - Badge Status Filter: Ditampilkan tepat di samping judul utama (`Filter Aktif`) jika terdapat filter aktif.
  - Deskripsi panjang: **Dihapus sepenuhnya** untuk menghemat ruang vertikal dan horizontal.

### B. Posisi Panel Availability (Satu Baris Horizontal)
Seluruh kartu ketersediaan kini berada di dalam container flex horizontal tunggal (`flex items-center gap-1.5 overflow-x-auto no-scrollbar`) yang sejajar dengan judul di sisi kiri dan profil pengguna di sisi kanan atas:

1. **Sisa Kuota**:
   - Label: `SISA KUOTA`
   - Angka utama: Total kuota pcs tersisa (misal: `40,900 pcs`)
   - Angka sekunder: Total kuota kg tersisa (misal: `212,400.00 kg`)
   - Dimensi kompak: `min-w-[95px] px-2.5 py-1`
2. **Market LOCAL & EXPORT**:
   - Container kartu ganda terintegrasi dengan separator vertikal.
   - Kolom LOCAL: `LOCAL` (biru), qty pcs tersisa, berat kg tersisa (`min-w-[75px]`).
   - Kolom EXPORT: `EXPORT` (ungu), qty pcs tersisa, berat kg tersisa (`min-w-[75px]`).
   - Kolom LAINNYA (jika ada customer non-diklasifikasikan).
3. **Line 1 s/d 4 (Distribusi Beban)**:
   - 4 kartu individual (`min-w-[125px] px-2 py-1`):
     - Baris 1: `LINE X` + Badge `Tidak ada PO` (jika kuota 0) + Persentase Beban Line (`%` berbasis kg terhadap total kg).
     - Baris 2: Qty pcs tersisa (font mono bold).
     - Baris 3: Total kg tersisa + breakdown `L: ... kg` (biru) dan `E: ... kg` (ungu).
4. **Line 0 / Unassigned**:
   - Ditampilkan hanya jika terdapat item dengan `line_number = 0` atau null (`min-w-[110px]`).

### C. Anggaran Lebar Horizontal Desktop (Width Budget)
Pada layar 1550 px:
- Sidebar aplikasi (lebar standar): ~240 px.
- User profile & actions di kanan header: ~80 px.
- Ruang horizontal tersedia untuk judul + panel: ~1.200 px.
- Alokasi lebar:
  - Judul & Subjudul: ~130 px.
  - Sisa Kuota: ~95 px.
  - Market LOCAL + EXPORT: ~170 px.
  - Line 1–4 ($4 \times 125\text{px}$): ~500 px.
  - Total ruang terpakai: ~920 px.
- **Hasil**: Pas dan nyaman dalam **1 baris horizontal tunggal** tanpa wrapping atau mendorong elemen ke baris baru, baik pada resolusi 1550 px maupun 1920 px (Full HD).

---

## 2. Optimasi Ruang Vertikal Header & Tabel

### A. Pengukuran Tinggi Header
- Target user: 80–90 px (termasuk padding).
- Konfigurasi CSS `#app-main-header`:
  ```css
  #app-main-header {
      height: auto !important;
      min-height: 3.5rem;
      padding-top: 0.25rem;
      padding-bottom: 0.25rem;
  }
  #app-main-content {
      padding-top: 0 !important;
  }
  ```
- **Tinggi Aktual Terukur**: **~64–70 px** (lebih hemat daripada target batas atas 90 px).

### B. Peningkatan Visibilitas Tabel
- Padding atas container konten dipadatkan dari `space-y-3 pt-2 sm:pt-4` menjadi `space-y-2 pt-1 sm:pt-1.5`.
- Margin antara header dan tab navigasi demand dipersempit.
- **Keuntungan Ruang Vertikal**:
  - Menghemat sekitar **70–80 px** ruang vertikal dibandingkan layout sebelumnya.
  - Pada layar standar 1550×950: **6 baris tabel pertama** langsung terlihat jelas di atas fold tanpa perlu menggulir.
  - Pada layar 1920×1080 (Full HD): **10 baris tabel pertama** langsung terlihat jelas bersama kalkulator rencana cor yang sticky.

---

## 3. Bukti Verifikasi Visual

### A. Resolusi 1550×950 px (Desktop Standar)
![Casting Availability Panel Refined (1550x950)](file:///C:/Users/ppic2/.gemini/antigravity/brain/a50dc154-9197-499c-83bc-c055c599db4e/casting_availability_panel_refined.png)
- Header tampil dalam satu baris horizontal rapi di samping judul `Perintah Cor`.
- Tab `Rencana Cor (Demand)` dan filter berada tepat di bawah header.
- Kalkulator dan baris tabel 1–6 langsung terlihat penuh.

### B. Resolusi 1920×1080 px (Wide Desktop / Full HD)
![Casting Availability Panel Refined Wide (1920x1080)](file:///C:/Users/ppic2/.gemini/antigravity/brain/a50dc154-9197-499c-83bc-c055c599db4e/casting_availability_panel_refined_wide.png)
- Proporsi sangat seimbang, ruang horizontal terpakai secara efektif tanpa memakan tinggi vertikal.
- Menampilkan hingga 10 baris data demand secara bersamaan.

---

## 4. Hasil Pengujian Otomatis

Seluruh pengujian unit dan integrasi dijalankan dan **100% LULUS (PASS)**:

```
   PASS  Tests\Feature\SandCasting\LiveCastingAvailabilityPanelTest
  ✓ total local and export equals overall total accounting for unclassified
  ✓ total line 1 to 4 equals overall total accounting for unassigned lines
  ✓ kg and pcs consistency with product weight resolver
  ✓ line with zero po displays zero pcs and tidak ada po
  ✓ filter on customer or code updates baseline correctly with filter active true
  ✓ plans page view renders all panel elements successfully
  ✓ items already completed in pcor are excluded from available quota
  ✓ line distribution percentage is based on weight kg including unassigned

  Tests:    8 passed (70 assertions)
```

Suite pengujian terkait lainnya juga terverifikasi hijau:
- `CastingOrderModelATest`: 12 passed
- `CastingOrderTest`: 24 passed
- `OverPlanCastingResultTest`: 13 passed
- `PcorReopeningCorrectionTest`: 8 passed
- **Total**: 65 passed (641 assertions), 0 failures.

---

## 5. File yang Dimodifikasi

- [`resources/views/sand-casting/casting-orders/plans.blade.php`](file:///c:/laragon/www/kanban-ppic/resources/views/sand-casting/casting-orders/plans.blade.php):
  - Penataan ulang section `@section('top_bar')` menjadi single-row flex layout.
  - Pemadatan styling `#app-main-header` (`py-1` / `0.25rem`).
  - Penyesuaian spacing content (`space-y-2 pt-1 sm:pt-1.5`).
  - Penyelarasan reactive JavaScript untuk update DOM secara instant saat item dicentang atau diubah di kalkulator.

---

## 6. Status dan Batasan

Pekerjaan diselesaikan sepenuhnya di repository lokal `C:\laragon\www\kanban-ppic`. Sesuai instruksi keamanan, tidak ada interaksi dengan server production, git push ke remote, atau migrasi database production. Pekerjaan dihentikan di sini untuk evaluasi user.

