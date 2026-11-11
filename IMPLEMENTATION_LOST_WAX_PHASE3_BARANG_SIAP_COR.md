# LAPORAN IMPLEMENTASI LOST WAX PHASE 3 — BARANG SIAP COR

**Repository:** `C:\laragon\www\kanban-ppic`  
**Fitur:** Antrean & Dokumen Kontrol Barang Siap Cor (Lost Wax Phase 3)  
**Dokumen Acuan:** `AUDIT_LOST_WAX_PHASE3_BARANG_SIAP_COR.md`  
**Status:** **SELESAI (100% Verified, 15/15 Tests Passed, Clean Pint Style)**  
**Tanggal Implementasi:** 10 November 2026  

---

## 1. Daftar File Baru dan File yang Diubah

### A. File Baru Dibuat:
1. `app/Services/LostWax/ReadyForCastingService.php`  
   Domain service terisolasi yang mengelola pengambilan data antrean WIP Barang Siap Cor, penerapan 4 aturan kelayakan data, urutan FIFO murni berdasarkan timestamp scan siap cor, resolusi multi-alokasi tanpa duplikasi, kalkulasi ringkasan KPI, dan filter AND.
2. `app/Http/Controllers/LostWax/ReadyForCastingController.php`  
   Controller yang menangani endpoint tampilan web antrean (`index`) dan pratinjau dokumen cetak A4 fisik lembar kontrol pengecoran (`print`).
3. `resources/views/lost-wax/reports/ready-for-casting/index.blade.php`  
   Tampilan antarmuka web yang menyajikan KPI ringkas, toolbar filter dropdown (Kode Produksi & Customer) + pencarian teks, bar indikator filter aktif, dan tabel rincian per Traveler Card (1 pohon fisik = 1 baris).
4. `resources/views/lost-wax/reports/ready-for-casting/print.blade.php`  
   Layout dokumen cetak fisik A4 Portrait berorientasi operasional lapangan dengan kop resmi `PT. PERONI KARYA SENTRA`, judul `LEMBAR KONTROL PENGECORAN — BARANG SIAP COR (LOST WAX)`, baris tinggi longgar (32px), kotak kosong bertulang tegas untuk penulisan tangan manual `HEAT NUMBER` dan `KETERANGAN / TUNGKU / PARAF`, serta 3 kolom tanda tangan formal.
5. `tests/Feature/LostWax/ReadyForCastingReportTest.php`  
   Suite pengujian fitur otomatis (15 test methods, 56 assertions) yang menguji seluruh skenario kelayakan data, penolakan pohon batal/void/afkir, urutan FIFO, multi-alokasi, filter pencarian, sifat read-only (zero mutation pada WIP), serta hak akses permission & role.
6. `IMPLEMENTATION_LOST_WAX_PHASE3_BARANG_SIAP_COR.md`  
   Laporan dokumentasi implementasi komprehensif ini.

### B. File Eksisting yang Diubah:
1. `routes/web.php`  
   Mendaftarkan 2 endpoint GET berizin `permission:access_execution`:
   - `GET /lost-wax/reports/ready-for-casting` (`lost-wax.reports.ready-for-casting.index`)
   - `GET /lost-wax/reports/ready-for-casting/print` (`lost-wax.reports.ready-for-casting.print`)
2. `resources/views/layouts/app.blade.php`  
   Menambahkan navigasi menu **Barang Siap Cor** dengan ikon `fas fa-clipboard-check` pada kelompok menu Lost Wax, ditempatkan **tepat setelah menu Scan Siap Cor**, dan dapat diakses oleh role yang relevan (`admin`, `ppic`, `spv`, `admin_qc_fitting`).

---

## 2. Penjelasan Query dan Aturan Kelayakan WIP

Query pada `ReadyForCastingService::getQueueData()` dirancang secara efisien menggunakan Eloquent eager loading untuk mengeliminasi potensi query N+1:

```php
$query = LostWaxTree::query()
    ->with([
        'defects',
        'printOrderLine.productionPlan',
        'printOrderLine.printOrder',
        'rangkaiExecution.workOrder',
        'workOrder.itemReference',
        'allocations.printOrderLine.productionPlan',
        'scanEvents' => function ($q) {
            $q->where('stage', 'siap_cor')
                ->where('result', 'success')
                ->whereDoesntHave('void')
                ->orderByDesc('scanned_at')
                ->orderByDesc('id');
        },
    ])
    ->where('status', '!=', 'cancelled')
    ->where('current_stage', 'siap_cor')
    ->whereHas('scanEvents', function ($q) {
        $q->where('stage', 'siap_cor')
            ->where('result', 'success')
            ->whereDoesntHave('void');
    });
```

### Parameter Kelayakan WIP yang Ditegakkan:
1. **`current_stage = 'siap_cor'`:** Menjamin pointer tahapan aktif pohon memang berada pada fase siap cor.
2. **`status != 'cancelled'`:** Pohon yang telah dibatalkan dieleminasi dari antrean.
3. **Scan Event Aktif & Non-Void:** Wajib memiliki event `LostWaxScanEvent` dengan `stage = 'siap_cor'`, `result = 'success'`, dan belum memiliki relasi pembatalan (`whereDoesntHave('void')`).
4. **`usable_quantity > 0`:** Pohon yang mengalami kerusakan 100% afkir ($usable = quantity - defects \le 0$) dieleminasi sehingga tidak dikirim ke lantai pengecoran.
5. **Kesiapan Phase 4:** Sesuai hasil audit, saat ini database belum memiliki kolom pencatatan hasil cor Lost Wax. Struktur query dirancang bersih sehingga ketika Phase 4 diimplementasikan, penambahan filter pengecualian pohon yang sudah dicor dapat langsung diterapkan.

---

## 3. Cara FIFO Ditentukan

1. Waktu pemindaian Siap Cor diambil langsung dari event aktif non-void:
   ```php
   $siapCorEvent = $tree->scanEvents
       ->where('stage', 'siap_cor')
       ->where('result', 'success')
       ->sortByDesc('scanned_at')
       ->first();
   $scannedAt = $siapCorEvent->scanned_at;
   ```
2. Pengurutan koleksi data dilakukan secara deterministik:
   - **Primary Ordering:** `scanned_at ASC` (pohon yang discan siap cor terawal diletakkan paling atas / No. 1).
   - **Secondary Ordering:** `tree_id ASC` (sebagai tie-breaker bila waktu pemindaian persis sama hingga detik).
3. Urutan FIFO ini konsisten antara antarmuka web dan dokumen cetak A4.

---

## 4. Cara Multi-Alokasi Ditangani

Pada modul Lost Wax, satu pohon fisik dapat membawa potongan lilin dari beberapa pesanan melalui tabel alokasi (`LostWaxTreeAllocation`). Ketentuan bisnis Phase 3 ditegakkan secara ketat:
- **Satu Traveler Card Fisik = Tepat Satu Baris:** Setiap pohon fisik hanya dimunculkan satu kali dalam tabel dan dokumen cetak. Tidak ada duplikasi baris pohon.
- **Tidak Ada Penggandaan PCS:** Kuantitas pohon pada baris tabel dan KPI ringkasan secara mutlak menggunakan `$tree->usable_quantity` fisik asli pohon.
- **Transparansi Metadata:** Bila pohon dialokasikan ke lebih dari satu kode produksi atau customer:
  - Kode produksi menampilkan seluruh kode terkait (misal: `268IL435, 268IL508`) dengan lencana `Multi`.
  - Customer dan nama barang menampilkan seluruh entitas terkait.
  - Setiap alokasi disimpan dalam array rincian `allocations` untuk audit trail internal.
  - Pada filter dropdown, pohon multi-alokasi akan cocok (*match*) bila salah satu kodenya dipilih oleh pengguna, tanpa menduplikasi kartu tersebut.

---

## 5. Penjelasan Halaman Web dan Filter

**URL:** `/lost-wax/reports/ready-for-casting`  
**Judul:** `Lost Wax — Barang Siap Cor`  

### A. KPI Ringkas (Sederhana & Fokus):
1. **Total Traveler Card:** Menampilkan jumlah fisik kartu/pohon yang siap dicor.
2. **Total Qty Siap Cor:** Menampilkan akumulasi PCS layak (`usable_quantity`) disertai rincian bruto dan afkir.
3. **Total Kode Produksi:** Menampilkan jumlah variasi kode produksi aktif dalam antrean.

### B. Toolbar & Mekanisme Filter (Pola Dropdown Phase 1.3):
- **Kode Produksi:** Multi-select checkbox dropdown mengambang (*floating*) dengan fitur pencarian inline, `Select All`, dan `Clear`.
- **Customer:** Multi-select checkbox dropdown mengambang dengan pencarian inline, `Select All`, dan `Clear`.
- **Pencarian Teks Bebas:** Mencari kecocokan pada barcode fisik, kode produksi, customer, SPK/WO, dan nama barang.
- **Logika AND:** Seluruh filter aktif digabungkan dengan logika AND.
- **Opsi Filter Bersumber dari Data Aktif:** Daftar pilihan checkbox pada dropdown hanya memuat kode dan customer yang saat ini benar-benar ada di antrean siap cor, bukan data historis masa lalu.
- **Bar Indikator Filter Aktif:** Menampilkan pill tag filter yang sedang berlaku lengkap dengan tombol silang (*remove*) individual dan tombol *Clear All*.
- **Tombol Reset Filter:** Tersedia untuk mengembalikan tampilan ke seluruh antrean awal.

---

## 6. Penjelasan Dokumen Cetak

**URL:** `/lost-wax/reports/ready-for-casting/print`  
**Media:** Kertas A4 Portrait  

### A. Header Dokumen:
- **Nama Perusahaan Resmi:** `PT. PERONI KARYA SENTRA • DIVISI FOUNDRY & LOST WAX`
- **Judul Dokumen:** `LEMBAR KONTROL PENGECORAN — BARANG SIAP COR (LOST WAX)`
- **Metadata Dokumen:** Tanggal cetak, jam cetak (WIB), nama admin yang mencetak, dan parameter filter aktif.

### B. Desain Tabel Ramah Tulisan Tangan Lapangan:
1. **No.** Nomor urut antrean FIFO (1, 2, 3, ...).
2. **Kode Produksi & Customer:** Kode produksi tebal berukuran 10px disertai nama customer dan part name di bawahnya.
3. **Traveler Card / Barcode:** Format monospace human barcode (misal: `P 26-08-25 0001`) dan nomor barcode fisik.
4. **Qty (PCS):** Angka kuantitas layak tebal warna hijau gelap.
5. **HEAT NUMBER (Manual Tangan):** Kotak bergaris putus-putus lebar (~135px) dengan tinggi baris longgar (32px) yang sangat lapang untuk ditulisi pulpen/spidol oleh Kepala Bagian Pengecoran.
6. **KETERANGAN / TUNGKU / PARAF:** Kotak kosong lebar (~135px) untuk mencatat nomor tungku, tanggal penuangan, atau paraf verifikasi.

### C. Kolom Tanda Tangan Formal (3 Pihak):
- **Diserahkan Oleh:** Admin PPIC / Produksi
- **Diterima & Dituang Oleh:** Kepala Bagian Pengecoran
- **Diperiksa & Diinput Oleh:** PPIC / QC Casting

---

## 7. Penjelasan Penambahan Sidebar dan Permission

### A. Penempatan Menu:
Pada file `resources/views/layouts/app.blade.php`:
- Menu **Barang Siap Cor** diletakkan pada grup **Lost Wax**, **tepat setelah menu Scan Siap Cor**.
- Ikon menggunakan `fas fa-clipboard-check`.
- Active state: `request()->routeIs('lost-wax.reports.ready-for-casting.*') ? 'text-white font-medium border-l-2 border-amber-400' : 'text-slate-300'`.

### B. Pengaturan Hak Akses (Role & Permission):
- Route di `routes/web.php` dilindungi oleh middleware `permission:access_execution`.
- Berdasarkan audit peran aplikasi, permission `access_execution` dimiliki oleh peran:
  - `admin`
  - `ppic`
  - `spv`
  - `admin_qc_fitting`
- Pada view sidebar, blok menu dibungkus dengan guard:
  ```blade
  @if(Auth::user() && (Auth::user()->roles->contains('name', 'admin') || Auth::user()->roles->contains('name', 'ppic') || Auth::user()->roles->contains('name', 'spv') || Auth::user()->roles->contains('name', 'admin_qc_fitting')))
  ```
  Hal ini menjamin bahwa Admin PPIC dapat langsung melihat menu dan membuka route tanpa hambatan hak akses, sementara role yang tidak memiliki izin (seperti `sales` atau akun tamu) akan ditolak dengan respons HTTP `403 Forbidden`.

---

## 8. Hasil Automated Tests

Pengujian dilakukan menggunakan suite PHPUnit/Pest bawaan repository. Seluruh 15 skenario uji baru pada `ReadyForCastingReportTest` serta suite regresi Lost Wax berhasil lulus 100%:

```
PASS  Tests\Feature\LostWax\ReadyForCastingReportTest
✓ 1 valid traveler card with successful scan siap cor appears (0.94s)
✓ 2 tree not yet scanned siap cor does not appear (0.05s)
✓ 3 tree with voided siap cor event does not appear (0.05s)
✓ 4 cancelled tree does not appear (0.04s)
✓ 5 tree with zero usable quantity does not appear (0.05s)
✓ 6 tree with defect uses correct usable quantity (0.05s)
✓ 7 fifo ordered by active scan siap cor timestamp (0.06s)
✓ 8 filter kode produksi works (0.06s)
✓ 9 filter customer works (0.06s)
✓ 10 search barcode works (0.07s)
✓ 11 combined filters work with and logic (0.05s)
✓ 12 multi allocation tree does not duplicate rows or pcs (0.06s)
✓ 13 web page does not alter wip (0.06s)
✓ 14 print page does not alter wip (0.06s)
✓ 15 role permission and access control (0.09s)

Tests:    15 passed (56 assertions)
Duration: 2.56s
```

### Hasil Uji Regresi Modul Lost Wax:
- `Tests\Feature\LostWax\ScanSiapCorTest`: **17 passed (73 assertions)**.
- `Tests\Feature\LostWax\ReadyForOvenPhase1Test`: **44 passed (354 assertions)**.
- `Tests\Feature\LostWax\ReadyForOvenReportTest`: **19 passed (97 assertions)**.
- **Total Pengujian Lost Wax Terverifikasi:** **95 test passing (580 assertions)** tanpa kegagalan.

---

## 9. Hasil Formatter (Laravel Pint)

Pemeriksaan gaya penulisan kode menggunakan standard PHP Pint repository:

```
PASS   ................................................................................................... 3 files
- app/Services/LostWax/ReadyForCastingService.php
- app/Http/Controllers/LostWax/ReadyForCastingController.php
- tests/Feature/LostWax/ReadyForCastingReportTest.php
```

Semua file baru dan modifikasi memenuhi standar PSR-12 / Laravel Code Style tanpa peringatan (*0 issues*).

---

## 10. Konfirmasi Zero Migration

- **Tidak ada file migration database baru yang dibuat.**
- Seluruh kebutuhan pembacaan data antrean Barang Siap Cor sepenuhnya memanfaatkan tabel dan kolom yang telah tersedia di schema database (`lost_wax_trees`, `lost_wax_scan_events`, `lost_wax_scan_event_voids`, `lost_wax_tree_defects`, `production_plans`).

---

## 11. Konfirmasi Keutuhan Alur Scanner & Void

- File `ScanSiapCorService.php`, `ScanService.php`, dan `ScanVoidService.php` **sama sekali tidak disentuh atau dimodifikasi**.
- Logika pemindaian oven (Phase 1) dan scanner siap cor (Phase 2) tetap berjalan normal tanpa interupsi atau perubahan perilaku.
- Mekanisme pembatalan pemindaian (`ScanVoidService`) tetap merekonstruksi status pohon ke tahap sebelumnya secara sempurna.

---

## 12. Konfirmasi Sifat Stateless & Zero Mutation WIP

- Membuka halaman web `/lost-wax/reports/ready-for-casting` maupun dokumen cetak `/lost-wax/reports/ready-for-casting/print` murni mengeksekusi HTTP `GET` dan query `SELECT`.
- Tidak ada operasi `UPDATE`, tidak ada perubahan nilai kolom `current_stage`, tidak ada pembuatan event scan baru, dan tidak ada pengurangan kuantitas barang.
- Mencetak dokumen atau mencetak ulang dokumen **tidak membuat reservasi** dan tidak mengunci Traveler Card.
- Jika dokumen berisi 50 Traveler Card dicetak dan diserahkan ke Kepala Cor, lalu hanya 40 yang dituangkan dan diinput pada Phase 4 nantinya, maka 10 kartu sisanya tetap otomatis tersedia sebagai WIP aktif Siap Cor dan akan muncul pada lembar cetak berikutnya.

