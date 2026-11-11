# LAPORAN IMPLEMENTASI PHASE 2 — SCAN SIAP COR LOST WAX

**Repository:** `C:\laragon\www\kanban-ppic`  
**Fitur:** Scan Siap Cor (Lost Wax Phase 2)  
**Dokumen Acuan:** `AUDIT_LOST_WAX_PHASE2_SCAN_SIAP_COR.md`  
**Status:** **SELESAI (100% Verified, Tested, Clean Style)**  
**Tanggal Implementasi:** 10 November 2026  

---

## 1. Ringkasan Eksekutif

Fitur **Scan Siap Cor** telah selesai diimplementasikan secara lokal pada modul Lost Wax. Scanner ini bertugas mencatat perpindahan fisik Traveler Card (`LostWaxTree`) setelah proses pembakaran di oven selesai, memindahkan status digital pohon dari tahap `oven` ke tahap `siap_cor`.

### Poin Utama Implementasi:
1. **Transisi Status:** `current_stage = 'oven'` $\rightarrow$ `current_stage = 'siap_cor'`.
2. **Dedicated Service Terisolasi:** Dibuat service baru `app/Services/LostWax/ScanSiapCorService.php`. File `ScanService.php` eksisting **sama sekali tidak disentuh**, menjaga scanner lapisan 1–7 dan scanner oven tetap steril dari risiko regresi.
3. **Pemisahan Logika & Akurasi WIP:**
   - Kuantitas pada laporan *Ready for Oven* sudah berkurang saat *Scan Oven* dilakukan pada transisi sebelumnya.
   - Scan Siap Cor **tidak mengurangi Ready for Oven lagi**.
   - Scan Siap Cor memindahkan pohon dari status `oven` ke `siap_cor`.
   - Pohon yang belum Scan Siap Cor tetap berada di status `oven`.
   - Pohon yang sudah Scan Siap Cor menjadi bagian dari WIP antrean **Barang Siap Cor**.
   - Tidak terjadi *double-counting*.
4. **Kuantitas Presisi:** Menggunakan `usable_quantity` (Kuantitas Bruto dikurangi akumulasi cacat dari `LostWaxTreeDefect`). Kuantitas cacat tidak diubah dan tidak dihitung ulang saat pemindaian.
5. **Zero Migration:** Memanfaatkan kolom `VARCHAR(20)` yang telah tersedia pada `lost_wax_trees` dan `lost_wax_scan_events`. Tidak ada file migration database baru yang dibuat.
6. **Integrasi Penuh dengan Void Service:** Pembatalan event `siap_cor` melalui `ScanVoidService` secara otomatis merekonstruksi pohon kembali ke status `oven` secara transaksional.

---

## 2. File yang Dibuat dan Diubah

### A. File Baru yang Dibuat

| No | File Path | Peran & Deskripsi |
|:---|:---|:---|
| 1 | `app/Services/LostWax/ScanSiapCorService.php` | Domain service untuk validasi, *pessimistic locking* (`lockForUpdate`), eksekusi transisi status, pencatatan event sukses/rejected, dan formatting informasi kartu pohon. |
| 2 | `app/Http/Controllers/LostWax/ScanSiapCorController.php` | Controller antarmuka scanner: merender tampilan (`index`) dan melayani request AJAX pemindaian barcode (`process`). |
| 3 | `resources/views/lost-wax/scan-siap-cor/index.blade.php` | Blade view scanner dengan input autofocus, audio feedback (bell sukses & buzzer gagal), banner status visual 10 meter (hijau/merah), kartu detail pohon, dan tabel riwayat 10 scan terakhir yang diperbarui *real-time*. |
| 4 | `tests/Feature/LostWax/ScanSiapCorTest.php` | Automated test suite mencakup 17 skenario uji (routing, otentikasi, validasi, duplikasi, pembatalan, usable quantity, rollback, void, dan isolasi WIP). |

### B. File Eksisting yang Dimodifikasi (Surgical & Non-Breaking)

| No | File Path | Perubahan yang Dilakukan |
|:---|:---|:---|
| 1 | `config/lost_wax.php` | Menambahkan `'siap_cor' => 'Siap Cor'` pada konfigurasi daftar `stages`. |
| 2 | `routes/web.php` | Mendaftarkan route `GET /lost-wax/scan-siap-cor` dan `POST /lost-wax/scan-siap-cor` di bawah middleware `auth` dan `permission:access_execution`. |
| 3 | `resources/views/layouts/app.blade.php` | Menambahkan menu navigasi sidebar **Scan Siap Cor** dengan ikon `fas fa-check-double` di bawah menu **Scan Oven**. |

---

## 3. Alur Transaksi, Locking & Validasi

### A. Pola Transaksi Atomik (*Pessimistic Locking*)

Eksekusi pemindaian dibungkus di dalam `DB::transaction()` dengan kunci baris database `lockForUpdate()`:

```php
return DB::transaction(function () use ($barcode, $operatorId) {
    $tree = LostWaxTree::with([
        'workOrder.itemReference',
        'printOrderLine.printOrder',
        'printOrderLine.productionPlan',
        'defects',
    ])
        ->where('barcode', $barcode)
        ->lockForUpdate()
        ->first();
    // Validasi bertingkat ...
    // Pembuatan scan event sukses ...
    // Update status pohon atomik ...
});
```

### B. Matriks Validasi dan Penolakan

| Skenario Pemindaian | Kode Internal | HTTP Status | Pesan Operator (UI Copy) | Catatan Database |
|:---|:---:|:---:|:---|:---|
| Input Barcode Kosong / Spasi | `EMPTY_BARCODE` | 200 | **`GAGAL — BARCODE TIDAK BOLEH KOSONG`** | Tidak ada record disimpan. |
| Barcode Tidak Dikenal di Sistem | `NOT_FOUND` | 200 | **`GAGAL — BARCODE TIDAK DITEMUKAN`** | Tidak membuat tree palsu; tidak ada record disimpan. |
| Traveler Card Dibatalkan (`status = 'cancelled'`) | `CANCELLED` | 200 | **`GAGAL — TRAVELER CARD TELAH DIBATALKAN`** | Disimpan event `rejected` (`anomaly_reason`). Status pohon tidak berubah. |
| Sudah Pernah Scan Siap Cor (`current_stage = 'siap_cor'`) | `ALREADY_SCANNED` | 200 | **`GAGAL — SUDAH PERNAH SCAN SIAP COR`** | Disimpan event `rejected`. Status pohon tetap `siap_cor`. |
| Belum Scan Oven (Masih di Lapisan 1–7 / belum dipping) | `NOT_OVEN_READY` | 200 | **`GAGAL — BELUM SCAN SIAP OVEN`** | Disimpan event `rejected`. Status pohon tetap pada lapisan asalnya. |
| Event Oven Tidak Aktif / Pernah di-Void | `NOT_OVEN_READY` | 200 | **`GAGAL — BELUM SCAN SIAP OVEN`** | Disimpan event `rejected`. Status pohon tidak berubah. |
| Kuantitas Bersih Nol (`usable_quantity <= 0`) | `ZERO_USABLE` | 200 | **`GAGAL — 0 PCS LAYAK`** | Disimpan event `rejected`. Status pohon tidak berubah. |
| **Pohon Sah (Valid Oven)** | `SUCCESS` | 200 | **`BERHASIL — SIAP COR`** | Dibuat event `success` stage `siap_cor`, update tree `current_stage = 'siap_cor'`, `last_scan_at = now()`. |

### C. Jaminan Ketahanan Kegagalan (*Rollback Safety*)
- Jika terjadi *exception* atau gangguan koneksi database saat penyimpanan event atau pembaruan status pohon, `DB::transaction()` secara otomatis melakukan *rollback*.
- Pohon tetap berada di status `oven` tanpa ada data parsial atau inkonsisten.

---

## 4. Integrasi Audit Trail dan Mekanisme Void

Integrasi dengan `App\Services\ScanVoidService` berjalan sempurna secara *native*:
1. **Aturan Pembatalan:** Sesuai aturan `ScanVoidService`, hanya scan event sukses **terakhir** yang dapat dibatalkan (`$latestEvent->id === $lockedEvent->id`).
2. **Rekonstruksi Mundur Otomatis:**
   - Ketika event `siap_cor` dibatalkan (di-void) oleh Admin/PPIC via `POST /lost-wax/scan-events/{event}/void`, service secara otomatis mencari event sukses terakhir sebelumnya, yaitu event `stage = 'oven'`.
   - Kolom `current_stage` pada `LostWaxTree` langsung direkonstruksi kembali ke `'oven'`, dan `last_scan_at` dikembalikan ke timestamp scan oven sebelumnya.
   - Pohon kembali siap dipindai di Scan Siap Cor jika diperlukan.
3. **Perlindungan Event Lampau:**
   - Jika suatu pohon telah memiliki event `siap_cor`, operator/admin **tidak dapat** membatalkan event `oven` yang lama karena event tersebut bukan lagi event terakhir. Percobaan membatalkan event lama akan menghasilkan pengecualian `InvalidArgumentException: Hanya scan event sukses terakhir yang boleh dibatalkan (void)`.

---

## 5. Dampak Terhadap WIP & Laporan Ready for Oven

Perpindahan WIP terbukti terisolasi secara matematis dan logis:

```
[ Antrean Siap Masuk Oven ]  ----( Scan Oven )---->  [ Sedang di Oven ]  ----( Scan Siap Cor )---->  [ Barang Siap Cor ]
  (Ready for Oven Report)                              (current_stage=oven)                            (current_stage=siap_cor)
  Pohon Lapisan 6/7                                    Pohon dalam Tungku                              Pohon Matang Siap Tuang
```

- **Laporan Barang Siap Oven (`/lost-wax/reports/ready-for-oven`):**  
  Hanya menyeleksi pohon dengan kriteria `current_stage != 'oven'` dan `whereDoesntHave('scanEvents', stage='oven')`. Karena pohon yang sudah masuk oven sudah dikeluarkan dari laporan ini sejak Scan Oven dilakukan, maka pemindahan pohon dari `oven` ke `siap_cor` **sama sekali tidak mengubah angka antrean Siap Oven**.
- **Ketiadaan Double-Counting:** Kuantitas pohon hanya berpindah dari ember WIP *In Oven* ke ember WIP *Siap Cor*.
- **Akurasi Kuantitas:** Menggunakan `$tree->usable_quantity` ($Gross - Defects$). Contoh: Pohon 100 pcs bruto dengan 15 pcs afkir menghasilkan tepat 85 pcs Siap Cor.

---

## 6. Antarmuka Pengguna (UI Scanner)

Halaman `/lost-wax/scan-siap-cor` telah dibangun mengikuti standar antarmuka operator lantai pabrik:
- **Autofocus Input:** Kursor selalu berada pada input barcode, otomatis terhapus setelah pemindaian, dan terlindungi dari kehilangan fokus saat operator mengeklik area luar layar.
- **Audio Feedback:** Mendukung audio bell saat berhasil dan buzzer saat gagal menggunakan aset audio eksisting di `public/audio/lost-wax/`. Terdapat tombol toggle kontrol suara di pojok kanan atas.
- **Banner Status 10 Meter:** Banner hijau/merah dengan teks kontras tinggi yang dapat terbaca jelas dari jarak jauh oleh operator.
- **Informasi Lengkap Kartu Pohon:** Menampilkan Barcode, Nomor Pohon, Kode Produksi, Nama Item, Customer, AISI, dan Kuantitas Siap Cor.
- **Tabel Riwayat 10 Scan Terakhir:** Memuat data riwayat pemindaian terkini yang langsung bertambah di baris paling atas tanpa perlu memuat ulang (*reload*) halaman.

---

## 7. Hasil Pengujian Otomatis (*Automated Tests*)

### A. Test Suite Baru: `ScanSiapCorTest`

Seluruh 17 skenario uji yang ditentukan telah diimplementasikan dan **lulus 100%**:

```
PASS  Tests\Feature\LostWax\ScanSiapCorTest
✓ 1 scan siap cor page can be accessed by authorized user                                 0.82s  
✓ 2 unauthenticated user redirected to login                                              0.04s  
✓ 3 scan fails when barcode is empty                                                      0.05s  
✓ 4 scan fails when barcode not found                                                     0.05s  
✓ 5 scan fails when tree has not passed oven scan                                         0.05s  
✓ 6 scan fails when tree in oven stage but no active oven event                           0.04s  
✓ 7 scan siap cor succeeds for valid oven completed tree                                  0.05s  
✓ 8 scan siap cor creates successful scan event                                           0.04s  
✓ 9 scan siap cor records operator id and timestamp                                       0.05s  
✓ 10 duplicate scan siap cor is rejected                                                  0.05s  
✓ 11 cancelled tree is rejected                                                           0.04s  
✓ 12 zero or negative usable quantity is rejected                                         0.04s  
✓ 13 void siap cor event reverts tree to oven stage                                       0.05s  
✓ 14 cannot void older oven event when siap cor event exists                              0.05s  
✓ 15 siap cor trees never appear in ready for oven report                                 0.05s  
✓ 16 quantity siap cor uses usable quantity accurately                                    0.04s  
✓ 17 transaction rollback leaves no partial data on exception                             0.04s  

Tests:    17 passed (73 assertions)
Duration: 2.31s
```

### B. Uji Regresi Modul Eksisting

Memastikan fitur baru tidak merusak laporan Ready for Oven Phase 1, Phase 1.2, dan Phase 1.3:

1. **`ReadyForOvenPhase1Test`:**
   ```
   PASS  Tests\Feature\LostWax\ReadyForOvenPhase1Test
   Tests:    44 passed (354 assertions)
   Duration: 3.99s
   ```
2. **`ReadyForOvenReportTest`:**
   ```
   PASS  Tests\Feature\LostWax\ReadyForOvenReportTest
   Tests:    19 passed (97 assertions)
   Duration: 2.75s
   ```

**Total Uji Terverifikasi:** **80 test passing (524 assertions)** tanpa satupun kegagalan.

### C. Hasil Pemformatan Kode (Laravel Pint)

```
vendor/bin/pint config/lost_wax.php app/Services/LostWax/ScanSiapCorService.php app/Http/Controllers/LostWax/ScanSiapCorController.php tests/Feature/LostWax/ScanSiapCorTest.php
PASS: 4 files formatted cleanly without style issues.
```

---

## 8. Catatan Status & Langkah Fase Berikutnya

1. **Zero Migration:** Dikonfirmasi tidak ada perubahan skema database atau migration baru.
2. **Kesiapan Fase Berikutnya:**
   - Ketersediaan pohon dengan status `current_stage = 'siap_cor'` kini siap digunakan sebagai sumber data saat modul laporan **Barang Siap Cor** (*Ready for Casting Report*) dan form **Input Hasil Cor** (*Lost Wax Heat Casting*) dibangun di fase mendatang.
3. **Lingkup Lokal:** Seluruh perubahan diuji dan tersimpan di lokal `c:\laragon\www\kanban-ppic` tanpa melakukan *deployment*, *push* Git, maupun akses server produksi.

