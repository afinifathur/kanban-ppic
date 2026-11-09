# IMPLEMENTASI STACKED LINE DISTRIBUTION & FIX LINE READ-ONLY (SAND CASTING)

Dokumen ini mendokumentasikan implementasi dua perbaikan UI dan perlindungan integritas data pada fitur **Perintah Cor — Cor Pasir (Sand Casting)** di repository lokal Laragon `C:\laragon\www\kanban-ppic`:

1. **Bagian A — Stacked Line Distribution**: Menyusun informasi `LOCAL` dan `EXPORT` secara vertikal di dalam setiap kartu Line sehingga kartu menjadi lebih ramping, seimbang, dan mudah dibaca secara cepat.
2. **Bagian B — Line Read-Only di Halaman Perintah Cor**: Menghapus dropdown perubahan Line pada tabel Rencana Cor, form pembuatan PCOR, dan form edit PCOR, serta menerapkan *fail-closed backend protection* agar manipulasi request browser tidak dapat mengubah `production_plans.line_number`. Halaman **Daftar Rencana** tetap menjadi satu-satunya sumber kebenaran untuk mengubah Line.

---

## 1. Bagian A — Stacked Line Distribution

### A. Desain Kartu Line Vertikal
Sebelumnya, informasi berat total dan kategori Local/Export dipaksakan dalam satu baris horizontal panjang:
```text
14.952,58 kg | L: 9.825,60 kg | E: 5.126,98 kg
```

Format tersebut kini telah diubah menjadi susunan vertikal yang ramping sesuai diagram acuan:
```text
LINE 1                                                  39%
20.400 pcs        L: Local                      37.500,00 kg
83.400,00 kg      E: Export                     45.900,00 kg
```

### B. Aturan Visual & Tata Letak
- **Grid Layout Internal**: Menggunakan `grid grid-cols-[auto_1fr] gap-x-2.5 gap-y-0.5 items-center`:
  - **Baris 1**:
    - Kolom Kiri: Qty PCS tersisa (misal: `20,400 pcs`, font mono `text-[10px] font-black text-slate-800`).
    - Kolom Kanan: Label `L: Local` (`text-blue-700 font-semibold`) rata kiri, dan berat kg (`text-slate-700 font-bold`) rata kanan.
  - **Baris 2**:
    - Kolom Kiri: Total berat kg tersisa (misal: `83,400.00 kg`, font mono `text-[9px] font-bold text-slate-700`).
    - Kolom Kanan: Label `E: Export` (`text-purple-700 font-semibold`) rata kiri, dan berat kg (`text-slate-700 font-bold`) rata kanan.
- **Header Kartu**: Menampilkan nama Line (`LINE 1`), persentase beban berat berbasis kg (`text-amber-700 font-mono`), serta badge `Tidak ada PO` (jika kuota 0 pcs).
- **Kondisi Line 0 PO**:
  ```text
  LINE 4                    Tidak ada PO                 0%
  0 pcs           L: Local                         0,00 kg
  0,00 kg         E: Export                        0,00 kg
  ```
- **Kompak dan Satu Baris Horizontal**:
  - Lebar per kartu Line: `min-w-[165px] px-2.5 py-1`.
  - Keempat kartu Line 1–4 tetap berjajar rapi secara horizontal bersama kartu Sisa Kuota dan Market LOCAL/EXPORT.
  - Tinggi kartu tetap ~48 px sehingga tinggi header desktop tetap di kisaran ~68–72 px (terjaga di bawah target batas atas 80–90 px).

---

## 2. Bagian B — Line Read-Only di Halaman Perintah Cor

### A. Perubahan UI Tabel & Form Perintah Cor
1. **Tabel Rencana Cor (`plans.blade.php`)**:
   - Kontrol `<select data-plan-line>` dengan opsi dropdown `Line 1..4` telah **dihapus sepenuhnya**.
   - Diganti dengan badge read-only yang bersih:
     ```blade
     <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">
         Line {{ $displayLine }}
     </span>
     ```
   - Jika item belum memiliki line di Daftar Rencana (`line_number = 0` atau null), ditampilkan badge netral: `Line -`.
   - Event listener `plan-line-select` pada JavaScript telah dihapus.
2. **Form Buat Perintah Cor (`create.blade.php`)**:
   - Kolom Line yang sebelumnya berupa dropdown `<select name="items[{{ $index }}][line_number]">` telah diganti menjadi badge read-only `Line X`.
3. **Form Edit Dokumen DRAFT (`edit.blade.php`)**:
   - Kolom Line pada tabel item eksisting diganti menjadi badge read-only.
   - Form penambahan item baru (`lines.store`) menampilkan status `Line (Read-only)` secara otomatis mengikuti production plan yang dipilih tanpa dropdown pengubah.

### B. Sumber Kebenaran Line & Perlindungan Backend
- **Sumber Kebenaran**:
  `production_plans.line_number` adalah satu-satunya sumber kebenaran. Nilai ini ditentukan saat PPIC menginput rencana produksi di modul **Daftar Rencana**.
- **Backend Protection pada `CastingOrderController`**:
  - `store()`:
    - Tidak lagi memodifikasi `ProductionPlan.line_number`. Kode `$plan->update(['line_number' => $resolvedLine])` telah dihapus.
    - Setiap `sand_casting_casting_order_lines` dibuat secara langsung menggunakan `line_number` dari `ProductionPlan`.
    - Jika client memanipulasi payload HTTP dengan menyisipkan parameter `line_number`, backend mengabaikannya dan `ProductionPlan` tetap tidak berubah.
  - `update()`:
    - Kode yang memperbarui `$plan->update(['line_number' => ...])` telah dihapus sepenuhnya.
  - `storeLine()`:
    - Kode yang memperbarui `$plan->update(['line_number' => ...])` telah dihapus sepenuhnya. Line baru pada order mengikuti `ProductionPlan` asal.
- **Halaman Daftar Rencana (`PlanController`)**:
  - Mekanisme update line pada `PlanController::update` (`PUT /plan/{plan}`) tetap dipertahankan dan berfungsi normal sebagai satu-satunya tempat pengubahan line.

---

## 3. Bukti Verifikasi Visual

### A. Desktop Standar (1550×950 px)
![Casting Availability Panel Stacked (1550x950)](file:///C:/Users/ppic2/.gemini/antigravity/brain/a50dc154-9197-499c-83bc-c055c599db4e/casting_availability_panel_refined.png)
- Header tampil dalam **satu baris horizontal penuh**.
- Informasi Local dan Export pada setiap kartu Line tersusun vertikal rapi (L: Local di baris atas, E: Export di baris bawah).
- Kolom Line pada tabel demand di bawahnya menampilkan badge read-only `Line 1`, `Line 2`, `Line 3` tanpa ada icon dropdown/panah bawah.

### B. Desktop Wide / Full HD (1920×1080 px)
![Casting Availability Panel Stacked Wide (1920x1080)](file:///C:/Users/ppic2/.gemini/antigravity/brain/a50dc154-9197-499c-83bc-c055c599db4e/casting_availability_panel_refined_wide.png)
- Proporsi sangat seimbang, ruang vertikal tabel termaksimalkan hingga 10 baris demand tampak jelas di atas fold.
- Nilai total kuota, market, dan beban per line bereaksi secara langsung saat kuantitas atau centang kalkulator diubah.

---

## 4. Hasil Pengujian Otomatis

Seluruh 8 suite pengujian Sand Casting telah dieksekusi dan dinyatakan **100% LULUS (PASS)**:

```text
   PASS  Tests\Feature\SandCasting\CastingOrderModelATest (12 passed, 114 assertions)
   PASS  Tests\Feature\SandCasting\CastingOrderTest (24 passed, 276 assertions)
   PASS  Tests\Feature\SandCasting\CastingResultServiceAndControllerTest (19 passed, 82 assertions)
   PASS  Tests\Feature\SandCasting\DefectAdjustmentTest (17 passed, 112 assertions)
   PASS  Tests\Feature\SandCasting\FinalQualityLockTest (13 passed, 78 assertions)
   PASS  Tests\Feature\SandCasting\LiveCastingAvailabilityPanelTest (10 passed, 74 assertions)
   PASS  Tests\Feature\SandCasting\OverPlanCastingResultTest (13 passed, 107 assertions)
   PASS  Tests\Feature\SandCasting\PcorReopeningCorrectionTest (8 passed, 74 assertions)

Total: 122 passed (917 assertions) — 0 failures
```

### Verifikasi Spesifik Fitur Baru:
1. `test_line_card_displays_stacked_vertical_local_and_export_details`: Membuktikan label `L: Local`, `E: Export`, dan ID container kg vertikal dirender dengan benar pada setiap kartu Line.
2. `test_perintah_cor_page_has_no_line_dropdown_and_renders_read_only_line`: Membuktikan tabel demand tidak lagi memiliki dropdown `plan-line-select` dan menampilkan badge read-only dari database.
3. `test_browser_payload_cannot_mutate_production_plan_line_number`: Membuktikan bahwa request yang disisipi manipulasi `line_number` pada `store()`, `update()`, maupun `storeLine()` ditolak/diabaikan sehingga `production_plans.line_number` tetap aman dan tidak berubah.
4. Membuktikan bahwa pengubahan Line pada Daftar Rencana (`PlanController::update`) tetap berfungsi normal.

---

## 5. File yang Dimodifikasi

1. [`app/Http/Controllers/SandCasting/CastingOrderController.php`](file:///c:/laragon/www/kanban-ppic/app/Http/Controllers/SandCasting/CastingOrderController.php):
   - Menghapus mutasi `line_number` pada `ProductionPlan` di method `store()`, `update()`, dan `storeLine()`.
   - Menetapkan Line pada order line secara ketat dari `production_plans.line_number`.
2. [`resources/views/sand-casting/casting-orders/plans.blade.php`](file:///c:/laragon/www/kanban-ppic/resources/views/sand-casting/casting-orders/plans.blade.php):
   - Restrukturisasi kartu Line 1–4 dan Line 0 menjadi susunan vertikal 2 baris (Local & Export).
   - Mengubah kolom Line tabel demand menjadi badge read-only (menghapus `<select data-plan-line>`).
   - Membersihkan event listener JavaScript yang sebelumnya memproses dropdown line.
3. [`resources/views/sand-casting/casting-orders/create.blade.php`](file:///c:/laragon/www/kanban-ppic/resources/views/sand-casting/casting-orders/create.blade.php):
   - Mengubah kolom Line menjadi badge read-only.
4. [`resources/views/sand-casting/casting-orders/edit.blade.php`](file:///c:/laragon/www/kanban-ppic/resources/views/sand-casting/casting-orders/edit.blade.php):
   - Mengubah kolom Line menjadi badge read-only pada tabel dan form penambahan item.
5. [`tests/Feature/SandCasting/CastingOrderTest.php`](file:///c:/laragon/www/kanban-ppic/tests/Feature/SandCasting/CastingOrderTest.php):
   - Menyesuaikan pengujian integritas read-only Line dan fail-closed guard.
6. [`tests/Feature/SandCasting/LiveCastingAvailabilityPanelTest.php`](file:///c:/laragon/www/kanban-ppic/tests/Feature/SandCasting/LiveCastingAvailabilityPanelTest.php):
   - Menambahkan pengujian `test_line_card_displays_stacked_vertical_local_and_export_details` dan `test_perintah_cor_page_has_no_line_dropdown_and_renders_read_only_line`.

---

## 6. Batasan & Status Pengerjaan

Pekerjaan selesai dilakukan 100% secara lokal pada repository Laragon `C:\laragon\www\kanban-ppic`. Sesuai instruksi keamanan, tidak ada sentuhan ke production server, database remote, git push, ataupun migrasi database baru. Implementasi dihentikan di sini untuk evaluasi Anda.

