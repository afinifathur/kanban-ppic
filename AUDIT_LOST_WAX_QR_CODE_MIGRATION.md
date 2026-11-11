# AUDIT & BRAINSTORMING TEKNIS
## MIGRASI BARCODE 1D KE QR CODE PADA MODUL LOST WAX

Repository: `C:\laragon\www\kanban-ppic`  
Tanggal Audit: 11 November 2026  
Status: **DRAFT ARSITEKTUR & HASIL AUDIT READ-ONLY**

---

## DAFTAR ISI
1. [Latar Belakang & Prinsip Utama Koeksistensi Dual-Identifier](#1-latar-belakang--prinsip-utama-koeksistensi-dual-identifier)
2. [Kondisi Arsitektur Barcode Saat Ini](#2-kondisi-arsitektur-barcode-saat-ini)
3. [Audit File & Komponen Terkait Traveler Card](#3-audit-file--komponen-terkait-traveler-card)
4. [Audit File & Komponen Terkait Scan Siap Cor](#4-audit-file--komponen-terkait-scan-siap-cor)
5. [Audit Library yang Tersedia di Repositori](#5-audit-library-yang-tersedia-di-repositori)
6. [Rekomendasi Library QR Code Generation (Backend)](#6-rekomendasi-library-qr-code-generation-backend)
7. [Rekomendasi Library QR Scanner Android (Frontend)](#7-rekomendasi-library-qr-scanner-android-frontend)
8. [Analisis Teknis Akses Kamera Android, HTTP LAN & Chrome Flags](#8-analisis-teknis-akses-kamera-android-http-lan--chrome-flags)
9. [Keputusan Payload & Integritas Identitas Tunggal](#9-keputusan-payload--integritas-identitas-tunggal)
10. [Dampak Terhadap Database, Model & Alur Bisnis](#10-dampak-terhadap-database-model--alur-bisnis)
11. [Dampak Terhadap Desain Fisik & Layout Cetak Traveler Card](#11-dampak-terhadap-desain-fisik--layout-cetak-traveler-card)
12. [Diagram Alur Pemindaian (Dual-Mode Scanner)](#12-diagram-alur-pemindaian-dual-mode-scanner)
13. [Rencana Implementasi Bertahap (Roadmap)](#13-rencana-implementasi-bertahap-roadmap)
14. [Analisis Risiko & Strategi Mitigasi](#14-analisis-risiko--strategi-mitigasi)
15. [Daftar File yang Akan Diubah pada Tahap Implementasi](#15-daftar-file-yang-akan-diubah-pada-tahap-implementasi)

---

## 1. Latar Belakang & Prinsip Utama Koeksistensi Dual-Identifier

### 1.1 Masalah Operasional Lapangan
Pada area produksi Lost Wax yang luas dan dinamis (terutama area dipping, cooling rack, oven pembakaran, dan antrean siap cor), operator menghadapi kendala fisik:
- **Keterbatasan Jarak Scanner Bluetooth:** Scanner nirkabel Bluetooth yang saat ini digunakan memiliki jangkauan efektif maksimal sekitar 10 meter dari terminal PC (AIO PC). Jika posisi pohon lilin berada di ujung rak atau di dekat oven, koneksi Bluetooth sering terputus (*dropped signal*).
- **Ketiadaan Layar Informasi pada Scanner:** Scanner barcode genggam konvensional tidak memiliki display/layar. Operator tidak dapat langsung memverifikasi apakah scan berhasil, kuantitas layak yang tercatat, atau alasan penolakan sistem tanpa berjalan kembali melihat layar PC.
- **Mobilitas Operator:** Operator membutuhkan perangkat genggam mandiri (smartphone Android) yang dapat dibawa langsung ke dekat pohon untuk memindai kode dan langsung melihat umpan balik (*real-time visual & audio feedback*) di genggaman tangan.

### 1.2 Prinsip Inti: Barcode 1D dan QR Code Berjalan Bersamaan
Sesuai arahan bisnis dan addendum yang ditetapkan, migrasi ini **TIDAK MENGGANTIKAN** barcode lama, melainkan menambahkan metode pembacaan:
1. **Koeksistensi Fisik:** Traveler Card fisik mencetak barcode 1D (Code 128) dan QR Code secara berdampingan.
2. **Backward Compatibility:** Traveler Card lama yang sudah beredar di lantai produksi tetap dapat diproses menggunakan barcode scanner USB/Bluetooth seperti biasa.
3. **Identitas Tunggal (Single Identity):** Barcode 1D dan QR Code membawa nilai teks yang **100% IDENTIK** (contoh: `1101126024`). QR Code bukan entitas data baru.
4. **Backend Agnostik:** Backend controller dan service tidak membedakan apakah request datang dari pemindai laser USB, scanner Bluetooth, input keyboard manual, atau kamera Android. Nilai string barcode yang diterima diproses melalui pintu validasi yang sama persis.

---

## 2. Kondisi Arsitektur Barcode Saat Ini

```
[LostWaxTree Model]
  └─ $tree->barcode (string 10 char, e.g. "1101126024")
        │
        ├─► [Web Route: /lost-wax/trees/{tree}/barcode]
        │     └─► Picqer\Barcode\BarcodeGeneratorPNG (Code 128, PNG Image)
        │           └─► Blade: /resources/views/lost-wax/trees/traveler.blade.php (A4 Sheet 6-grid)
        │
        └─► [Direct Thermal: /lost-wax/trees/print-thermal]
              └─► App\Services\Barcode\Renderers\TsplRenderer
                    └─► TSPL Command: BARCODE 80,195,"128",100,0,0,2,4,"{barcode}"
                          └─► Printer: TSC TE200 (Thermal Label 90x50 mm)
```

### Karakteristik Format Barcode:
- **Tipe Simbologi:** Code 128 Auto (`TYPE_CODE_128`).
- **Panjang Nilai:** 10 karakter numerik/alfanumerik (format: `yymm` + sequence, e.g., `1101126024` atau `P` + date + seq).
- **Representasi Teks:** Plain text murni tanpa enkapsulasi URL, query string, atau JSON.

---

## 3. Audit File & Komponen Terkait Traveler Card

### 3.1 Routing & Controller
- **Route:**
  - GET `/lost-wax/trees` (`lost-wax.trees.index`)
  - GET `/lost-wax/trees/{tree}/traveler` (`lost-wax.trees.traveler`) — Cetak form A4 sheet.
  - GET `/lost-wax/trees/{tree}/barcode` (`lost-wax.trees.barcode`) — Endpoint streaming PNG barcode 1D.
  - POST `/lost-wax/trees/print-thermal` (`lost-wax.trees.print-thermal`) — Pengiriman job cetak ke printer thermal TSC.
- **Controller:**
  `app/Http/Controllers/LostWax/TreeController.php`
  - Method `traveler()`: Menyiapkan kumpulan tree aktif yang sudah memiliki `rack_id`, lalu me-render view `lost-wax.trees.traveler`.
  - Method `barcode()`: Menggunakan `Picqer\Barcode\BarcodeGeneratorPNG` untuk menghasilkan gambar barcode 1D on-the-fly (`$generator->getBarcode($tree->barcode, $generator::TYPE_CODE_128, 2, 60)`).
  - Method `printThermal()`: Memanggil `TsplRenderer::render($tree)` dan mendaftarkan job ke `PrintJobService` untuk printer TSC TE200.

### 3.2 Template Cetak & Dimensi Fisik Kartu

Terdapat **2 (dua) jenis template cetak fisik** yang aktif di modul Lost Wax:

#### Template A: Cetak Kertas HVS A4 Grid (Epson Printer)
- **File:** `resources/views/lost-wax/trees/traveler.blade.php`
- **Media Cetak:** Kertas HVS A4 Portrait (`210 mm × 297 mm`).
- **Tata Letak Halaman:** 1 lembar A4 memuat **6 Traveler Card** (Grid: 2 kolom × 3 baris).
- **Dimensi Fisik per Kartu:** Lebar `92 mm` × Tinggi `88 mm`.
- **Struktur Isi Kartu (Vertikal):**
  1. Header (Tinggi ~20mm): Judul `LOST WAX TRAVELER`, Print Order No, Kode Produksi, Nama Item, AISI.
  2. Area Barcode Tengah (Tinggi ~25mm): `<img>` barcode 1D (`height: 48px; max-width: 100%`) + Teks Barcode (`text-[10px]`).
  3. Footer Rincian (Tinggi ~30mm): Tabel garis batas berisi `TREE`, `QTY`, `DATE`, `PLAN`, `RACK`, `CODE`.
- **Ruang Kosong Tersedia untuk QR Code:**
  Di area tengah kartu (`lebar 92mm`), barcode 1D dengan 10 digit angka hanya memakan lebar horizontal sekitar `45–50mm`. Terdapat ruang kosong sekitar `30–35mm` di sebelah kanan barcode 1D. Hal ini memungkinkan penempatan QR Code secara berdampingan (*side-by-side*) tanpa menambah tinggi kartu maupun mengubah ukuran font teks.

#### Template B: Cetak Label Thermal Stiker 90×50 mm (TSC TE200 Printer)
- **File:** `app/Services/Barcode\Renderers\TsplRenderer.php`
- **Media Cetak:** Label stiker thermal kontinu ukuran `90 mm × 50 mm`.
- **Resolusi Printer:** 203 DPI (8 dots per mm), total area `720 dots × 400 dots`.
- **Struktur Perintah TSPL Aktual:**
  - `BOX 20,10,700,390,3` (Garis tepi luar).
  - `BAR 20,190,680,3` s/d `BAR 20,320,680,3` (Area Barcode: Tinggi 130 dots / ~16mm).
  - Kolom Kiri: Barcode 1D `BARCODE 80,195,"128",100,0,0,2,4,"'.$barcode.'"` + teks nomor di bawahnya.
  - Kolom Kanan: `BAR 460,190,3,130` membatasi kotak "ISI RANGKAIAN" dan "XX PCS".
- **Ruang Tersedia untuk QR Code:**
  Printer TSC TE200 memiliki perintah bawaan hardware: `QRCODE x,y,ecc,cell_width,mode,rotation,"content"`. Dengan menggeser sedikit area barcode 1D atau memposisikan QR Code di area koordinat yang tepat, printer thermal dapat mencetak QR Code beresolusi tajam tanpa perlu rendering grafis bitmap dari PHP.

---

## 4. Audit File & Komponen Terkait Scan Siap Cor

### 4.1 Routing & Controller
- **Route:**
  - GET `/lost-wax/scan-siap-cor` (`lost-wax.scan-siap-cor.index`)
  - POST `/lost-wax/scan-siap-cor` (`lost-wax.scan-siap-cor.process`)
- **Controller:**
  `app/Http/Controllers/LostWax/ScanSiapCorController.php`
  - Method `index()`: Mengambil 10 riwayat event scan terakhir dengan stage `siap_cor`.
  - Method `process(Request $request)`: Menerima JSON payload `{ barcode: "..." }`, melakukan pengecekan RBAC Product Scope, lalu memanggil `ScanSiapCorService::processScan($barcode, $operatorId)`.

### 4.2 Domain Service (`ScanSiapCorService.php`)
- Melakukan transaksi atomik `DB::transaction()` dengan `lockForUpdate()` pada row `LostWaxTree`.
- Validasi berurutan:
  1. Barcode ditemukan dalam database.
  2. Status pohon bukan `cancelled`.
  3. Pohon belum pernah di-scan siap cor (mencegah double scan).
  4. Pohon telah menyelesaikan tahap oven (`current_stage == 'oven'` dan memiliki scan event oven yang sah).
  5. Kuantitas layak (*usable quantity*) > 0 PCS.
- Jika lolos: mencatat `LostWaxScanEvent` baru dengan `stage = 'siap_cor'`, `result = 'success'`, lalu memperbarui status pohon: `current_stage = 'siap_cor'`.

### 4.3 Frontend Interface & JavaScript (`scan-siap-cor/index.blade.php`)
- **Mekanisme Input Saat Ini (Mode A):**
  - Menggunakan elemen `<input type="text" id="scanInput" autofocus>` di dalam form.
  - Scanner kabel/Bluetooth bertindak sebagai *keyboard wedge* (mengetikkan string barcode lalu mengirim karakter `Enter`).
  - Event listener `scanForm.addEventListener('submit', ...)` mencegat submit, mencegah reload halaman, dan mengirim request via `fetch()` asinkron ke server.
  - Sistem memiliki visual feedback layar penuh (hijau untuk sukses, merah untuk gagal) dan pemutar file audio (`success-bell.mp3` / `failed-buzzer.mp3`).
- **Autofocus Loop:**
  Setiap selesai memproses request (sukses maupun gagal), JavaScript otomatis mengembalikan kursor ke `#scanInput` menggunakan `scanInput.focus()`.

---

## 5. Audit Library yang Tersedia di Repositori

Pemeriksaan file `composer.json` menghasilkan temuan yang sangat menguntungkan:

### 5.1 Library PHP (Composer)
```json
"require": {
    "php": "^8.3",
    "bacon/bacon-qr-code": "^3.1",
    "laravel/framework": "^12.0",
    "phpoffice/phpspreadsheet": "^5.9",
    "picqer/php-barcode-generator": "*",
    "spatie/laravel-permission": "^8.3"
}
```

> [!IMPORTANT]
> Library **`bacon/bacon-qr-code: ^3.1` SUDAH TERINSTAL SECARA RESMI** di repositori ini (berada di direktori `vendor/bacon/bacon-qr-code`). Repositori **TIDAK MEMERLUKAN** instalasi package PHP tambahan apa pun untuk menghasilkan QR Code!

### 5.2 Library Frontend (JavaScript)
Direktori `public/js`:
- `axios.min.js`
- `chart.min.js`
- `handsontable.full.min.js`
- `sweetalert2.all.min.js`
- `tailwindcss.js` (Standalone browser runtime)

*Catatan Kritis Arsitektur:*  
Aplikasi **tidak menggunakan build Vite** (`resources/js/app.js` tidak di-bundle). Seluruh pustaka JavaScript dimuat secara mandiri (*vendored standalone script*) di folder `public/js/`.

---

## 6. Rekomendasi Library QR Code Generation (Backend)

Karena `bacon/bacon-qr-code` v3.1 sudah ada di repositori, kita dapat memanfaatkannya secara langsung untuk menghasilkan QR Code.

### Pilihan Format Output:
1. **SVG Vector Image (Sangat Direkomendasikan):**
   - Menggunakan `BaconQrCode\Renderer\Image\SvgImageBackEnd`.
   - **Kelebihan:** 
     - 100% pure PHP, **tidak memerlukan ekstensi PHP `gd` atau `imagick`**.
     - Berformat vektor: hasil cetak di kertas A4 memiliki ketajaman tepi tak terbatas (*crisp at any DPI*), tidak pecah saat diperbesar atau diperkecil.
     - Ukuran byte sangat kecil (< 2 KB per kode).
     - Dapat disematkan langsung sebagai Inline SVG atau Data URI Base64 di Blade template.
2. **PNG Bitmap Image:**
   - Menggunakan `BaconQrCode\Renderer\Image\ImagickImageBackEnd` (memerlukan `ext-imagick`). Karena di server produksi Docker mungkin tidak selalu ada Imagick, format **SVG adalah pilihan paling aman, ringan, dan portabel**.

### Contoh Implementasi Helper/Service (Konsep):
```php
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

public function generateQrCodeSvg(string $barcode, int $size = 90): string
{
    $renderer = new ImageRenderer(
        new RendererStyle($size, 1), // Ukuran 90px, margin 1 modul
        new SvgImageBackEnd()
    );
    $writer = new Writer($renderer);
    return $writer->writeString($barcode);
}
```

---

## 7. Rekomendasi Library QR Scanner Android (Frontend)

Untuk membaca QR Code melalui kamera browser Android, dievaluasi 3 kandidat:

| Kriteria | **`html5-qrcode`** (Rekomendasi) | `jsQR` | Native `BarcodeDetector` |
|---|---|---|---|
| **Ukuran File** | ~55 KB (Minified) | ~42 KB | 0 KB (Browser Native) |
| **Dukungan Kamera Android** | Sangat Baik (Otomatis deteksi rear camera) | Perlu implementasi WebRTC manual | Tergantung versi Chrome Android |
| **Kontrol Senter (Torch)** | Ada API bawaan (`applyVideoConstraints`) | Harus dikodekan manual via MediaStream | Tergantung browser |
| **Deteksi Frame Otomatis** | Bawaan (Web Worker + Canvas) | Harus membuat loop `requestAnimationFrame` sendiri | Bawaan |
| **Kompatibilitas Standalone** | File tunggal di `public/js/` | File tunggal di `public/js/` | Chrome Android 83+ |

### Kesimpulan Rekomendasi:
Gunakan **`html5-qrcode.min.js`** (versi 2.3.8+).  
Library ini berlisensi Apache-2.0, cukup diunduh dan disimpan di `public/js/html5-qrcode.min.js`. Tidak membutuhkan npm, bundler, atau dependensi eksternal.

---

## 8. Analisis Teknis Akses Kamera Android, HTTP LAN & Chrome Flags

Ini adalah bagian paling krusial dari audit ini.

### 8.1 Standar Keamanan W3C MediaDevices API
Menurut spesifikasi resmi W3C Media Capture and Streams, pemanggilan method kamera:
```javascript
navigator.mediaDevices.getUserMedia({ video: true })
```
**HANYA DIIZINKAN PADA SECURE CONTEXT** (`window.isSecureContext === true`).

Pada browser Chrome Android standar:
- `https://...` $\rightarrow$ **Secure Context** (Kamera diizinkan).
- `http://localhost` atau `http://127.0.0.1` $\rightarrow$ **Secure Context** (Kamera diizinkan).
- `http://10.88.8.97/...` (IP LAN lokal via HTTP) $\rightarrow$ **INSECURE CONTEXT**!
  Pada origin ini, objek `navigator.mediaDevices` bernilai `undefined`. Jika dipanggil, browser akan melempar error:
  `TypeError: Cannot read properties of undefined (reading 'getUserMedia')`.

### 8.2 Solusi Chrome Android: `unsafely-treat-insecure-origin-as-secure`
Sesuai addendum yang disetujui untuk tahap uji coba, Android Chrome menyediakan fasilitas debug internal untuk memperlakukan IP HTTP lokal sebagai Secure Context.

#### Langkah Konfigurasi pada Smartphone Android:
1. Buka browser **Google Chrome** pada perangkat Android.
2. Pada address bar, ketik URL:  
   `chrome://flags/#unsafely-treat-insecure-origin-as-secure`
3. Temukan pengaturan **Insecure origins treated as secure**.
4. Ubah status dari **Disabled** menjadi **Enabled**.
5. Pada kotak input teks di bawahnya, masukkan origin server LAN (hanya protokol, IP, dan port, **tanpa path folder**):  
   `http://10.88.8.97`
6. Tekan tombol **Relaunch** di pojok kanan bawah layar untuk me-restart Chrome.
7. Setelah Chrome terbuka kembali, buka alamat aplikasi:  
   `http://10.88.8.97/kanban-ppic/public/index.php/lost-wax/scan-siap-cor`
8. Saat tombol "Buka Kamera QR" ditekan, browser Android akan menampilkan prompt standar:  
   *"10.88.8.97 wants to use your camera"* $\rightarrow$ Pilih **Allow (Izinkan)**.

#### Keterbatasan & Risiko Konfigurasi Chrome Flags:
1. **Konfigurasi per Perangkat:** Flag harus diaktifkan secara manual di setiap ponsel Android operator yang akan digunakan.
2. **Ketergantungan IP Server:** Jika alamat IP server `10.88.8.97` berubah, flag harus diperbarui di setiap ponsel.
3. **Eksklusif Chromium:** Hanya berlaku untuk Google Chrome Android, Edge Android, atau Brave Android (tidak bekerja di Firefox Android).
4. **Kelayakan:** Solusi ini **sangat layak dan ideal untuk Tahap Uji Coba Pilot (Tahap 1 & 2)** karena tidak membutuhkan perubahan konfigurasi server Nginx/Apache atau sertifikat SSL produksi. Namun, untuk peluncuran skala penuh (*full production*), disarankan menyiapkan HTTPS internal menggunakan domain lokal (misal `https://kanban.peroniks.local`) dengan reverse proxy.

---

## 9. Keputusan Payload & Integritas Identitas Tunggal

Sesuai Addendum Keputusan Bisnis:

| Parameter | Ketentuan Barcode 1D | Ketentuan QR Code |
|---|---|---|
| **Isi Payload** | `1101126024` | `1101126024` |
| **Tipe Data** | Plain text string | Plain text string |
| **URL Web** | TIDAK ADA | TIDAK ADA |
| **JSON / Struktur** | TIDAK ADA | TIDAK ADA |
| **Metadata Tambahan** | TIDAK ADA | TIDAK ADA |
| **Model Database** | `LostWaxTree.barcode` | `LostWaxTree.barcode` |
| **Tabel Terkait** | `lost_wax_trees` | `lost_wax_trees` |

### Keuntungan Arsitektur Keputusan Ini:
1. **Zero Database Impact:** Tidak ada kolom baru, tidak ada migrasi tabel, dan tidak ada perubahan query Eloquent.
2. **Interchangeability:** Operator dapat menembak barcode 1D dengan scanner Bluetooth, atau memindai QR Code dengan kamera ponsel. Keduanya menghasilkan input string yang sama ke controller.
3. **Ukuran QR Code Sangat Ringkas:** String 10 digit numerik hanya membutuhkan QR Code **Version 1 (21×21 modul)** atau **Version 2 (25×25 modul)**. Titik-titik modul QR sangat besar dan renggang, sehingga sangat cepat terbaca oleh kamera ponsel berspesifikasi rendah sekalipun dalam kondisi pencahayaan temaram.

---

## 10. Dampak Terhadap Database, Model & Alur Bisnis

- **Migration Baru:** **TIDAK ADA**.
- **Perubahan Skema Tabel:** **TIDAK ADA**.
- **Model `LostWaxTree`:** Tidak berubah. Kolom `barcode` tetap menjadi acuan identitas fisik.
- **Service `ScanSiapCorService`:** Tidak ada perubahan logika bisnis. Validasi tahap, pencegahan double scan, pencatatan scan event, dan voiding tetap berjalan 100% seperti semula.
- **Event Audit Log:** Riwayat pemindaian tetap tercatat di tabel `lost_wax_scan_events`.

---

## 11. Dampak Terhadap Desain Fisik & Layout Cetak Traveler Card

### 11.1 Desain Layout Kertas HVS A4 Grid (6 Kartu per Halaman)
Dimensi fisik kartu: `92 mm × 88 mm`.

```
+-------------------------------------------------------------+
|                      LOST WAX TRAVELER                      |
| PO-2026-0881 / 268IL435                                     |
| FLANGE BLIND 2 INCH ANSI 150                                |
| AISI: CF8M                                                  |
+-------------------------------------------------------------+
|                                                             |
|   +-----------------------+         +-------------------+   |
|   |                       |         | #######   ####### |   |
|   |  |||||||||||||||||||  |         | #   # #   # #   # |   |
|   |  |||||||||||||||||||  |         | #######   ####### |   |
|   |  |||||||||||||||||||  |         |                   |   |
|   |                       |         |   QR CODE 22x22   |   |
|   +-----------------------+         +-------------------+   |
|          1101126024                      (SCAN KAMERA)      |
|                                                             |
+-------------------------------------------------------------+
| TREE: 001                      | QTY: 48 PCS                |
| DATE: 11-11-2026               | PLAN: Wave 001             |
| RACK: RAK-04                   | CODE: 1101126024           |
+-------------------------------------------------------------+
```

- **Posisi Barcode 1D:** Digeser ke sisi kiri (lebar `~50mm`, tinggi `44px`).
- **Posisi QR Code:** Diletakkan di sisi kanan area tengah (ukuran `22mm × 22mm`, approx `85px × 85px`).
- **Jarak Pemindaian:** Ukuran `22mm × 22mm` untuk payload 10 digit dapat dipindai oleh kamera Android pada jarak `15 cm – 40 cm` secara instan (< 200 ms).
- **Keterbacaan Scanner Lama:** Jarak pemisah antara Barcode 1D dan QR Code adalah `~12mm`, cukup lebar sehingga sinar laser scanner 1D tidak terganggu oleh pola QR Code.

### 11.2 Desain Label Thermal 90×50 mm (TSC TE200)
Jika suatu saat dicetak pada label thermal:
- Perintah TSPL:
  - Barcode 1D: `BARCODE 40,195,"128",90,0,0,2,3,"1101126024"`
  - QR Code Hardware: `QRCODE 460,195,L,5,A,0,"1101126024"`
  - Keduanya muat dalam satu baris horizontal tanpa saling tumpang tindih.

---

## 12. Diagram Alur Pemindaian (Dual-Mode Scanner)

Berikut adalah diagram alur integrasi pemindaian pada halaman Scan Siap Cor:

```
                      +-----------------------------+
                      |   TRAVELER CARD FISIK       |
                      |  [Barcode 1D] + [QR Code]   |
                      +--------------+--------------+
                                     |
               +---------------------+---------------------+
               |                                           |
               v                                           v
      [MODE A: BARCODE 1D]                        [MODE B: QR CODE]
   Scanner Bluetooth / USB Kabel               Kamera Smartphone Android
               |                                           |
   Input Keyboard Wedge (Auto Enter)           HTML5-QRCode JS (Video Stream)
               |                                           |
               |                                 Ekstraksi Teks: "1101126024"
               |                                           |
               |                                 Debounce / In-Flight Guard
               |                                 (Cegah Scan Berulang di Kamera)
               |                                           |
               +---------------------+---------------------+
                                     |
                                     v
                        [JavaScript Form Dispatcher]
                   POST /lost-wax/scan-siap-cor { barcode }
                                     |
                                     v
                       [ScanSiapCorController@process]
                                     |
                                     v
                       [ScanSiapCorService@processScan]
                                     |
                        +------------+------------+
                        | Validasi Database:      |
                        | 1. Barcode ditemukan?   |
                        | 2. Not Cancelled?       |
                        | 3. Belum Siap Cor?      |
                        | 4. Lolos Scan Oven?     |
                        | 5. Usable Qty > 0?      |
                        +------------+------------+
                                     |
                        +------------+------------+
                        |                         |
                        v [LOLOS]                 v [GAGAL]
              +-------------------+     +-------------------+
              | Update Stage:     |     | Catat Rejection   |
              | current_stage =   |     | Event di Database |
              | 'siap_cor'        |     |                   |
              | + Create Event    |     +---------+---------+
              +---------+---------+               |
                        |                         |
                        +------------+------------+
                                     |
                                     v
                        [JSON Response to Client]
                                     |
               +---------------------+---------------------+
               |                                           |
               v                                           v
      [Visual Feedback Layar]                     [Audio Feedback]
      - Layar Hijau / Merah                       - Bel Berhasil / Buzzer Gagal
      - Detail Pohon & Usable PCS                 - Suara di Speaker HP Android
```

---

## 13. Rencana Implementasi Bertahap (Roadmap)

### Tahap 1: Penambahan QR Code pada Template Traveler Card
1. Membuat helper/service di `app/Services/LostWax/QrCodeService.php` yang memanfaatkan `bacon/bacon-qr-code` untuk menghasilkan data SVG string murni.
2. Memperbarui Blade view `resources/views/lost-wax/trees/traveler.blade.php`:
   - Mengatur tata letak side-by-side (Barcode 1D di kiri, QR Code di kanan).
   - Menjaga ukuran kartu tetap `92mm × 88mm` (A4 6-grid).
3. Pengujian Cetak Fisik:
   - Mencetak beberapa lembar Traveler Card ke kertas HVS A4.
   - Menguji keterbacaan barcode 1D menggunakan scanner fisik eksisting.
   - Menguji keterbacaan QR Code menggunakan aplikasi kamera standar Android.
   - **Kriteria Keberhasilan:** Barcode 1D terbaca normal, QR Code terbaca instan, tata letak rapi tidak terpotong.

### Tahap 2: Implementasi Scanner Kamera pada Halaman Scan Siap Cor
1. Menempatkan file pustaka `public/js/html5-qrcode.min.js`.
2. Memodifikasi view `resources/views/lost-wax/scan-siap-cor/index.blade.php`:
   - Menambahkan tombol **"Buka Kamera QR"** dan **"Tutup Kamera"**.
   - Menyediakan kontainer `<div id="qrReader">` dengan aspek rasio yang proporsional untuk layar smartphone.
   - Menambahkan tombol kontrol lampu senter (*torch toggle*) jika perangkat keras kamera mendukung.
3. Mengembangkan logika JavaScript kontrol pemindaian:
   - Default kamera belakang: `facingMode: { exact: "environment" }` dengan fallback `facingMode: "environment"`.
   - **In-flight Guard / Anti-Multi-Scan:** Saat satu QR Code terdeteksi, scanner dijeda sementara (`html5QrCode.pause()`) selama proses AJAX request berlangsung.
   - Setelah respons diterima (sukses atau gagal), scanner memberikan jeda *cooldown* selama 1,5 detik sebelum mengaktifkan kembali pemindaian kartu berikutnya.
   - Mengarahkan hasil pembacaan QR Code ke fungsi pemrosesan AJAX yang sudah ada tanpa mengubah backend.
4. Uji Coba Lapangan dengan Smartphone Android (menggunakan Chrome Flags):
   - Menguji scan di area oven dan area siap cor.
   - Memastikan umpan balik suara (*audio bell/buzzer*) dan warna layar bekerja dengan baik di smartphone operator.

### Tahap 3: Evaluasi & Perluasan Modul
1. Evaluasi performa pemindaian oleh operator di lapangan.
2. Jika terbukti stabil dan meningkatkan kecepatan kerja, evaluasi penambahan fitur kamera QR pada:
   - Halaman **Scan Oven** (`/lost-wax/scan-oven`).
   - Halaman **Scan Lapisan / Coating** (`/lost-wax/scan`).
   - Template printer thermal TSC TE200 (`TsplRenderer.php`).

---

## 14. Analisis Risiko & Strategi Mitigasi

| No | Potensi Risiko | Tingkat Dampak | Strategi Mitigasi |
|:---:|---|:---:|---|
| 1 | **Kamera tidak aktif di Chrome Android karena HTTP** | Kritis | Menggunakan Chrome Flag `chrome://flags/#unsafely-treat-insecure-origin-as-secure` dengan origin `http://10.88.8.97` pada ponsel operator uji coba. |
| 2 | **Scan Berulang (Multi-Triggering)** | Tinggi | Mengimplementasikan state guard `isProcessing = true` dan memanggil `html5QrCode.pause()` seketika setelah frame pertama terdeteksi, dengan jeda cooldown 1.5 detik. |
| 3 | **Kamera Salah Arah (Kamera Depan Aktif)** | Sedang | Menetapkan konfigurasi eksplisit: `videoConstraints: { facingMode: { exact: "environment" } }` dengan fallback seleksi perangkat `Html5Qrcode.getCameras()`. |
| 4 | **Pencahayaan Redup di Area Oven** | Sedang | Memanfaatkan API Flashlight/Torch dari `html5-qrcode` jika perangkat HP mendukung lampu flash kamera. |
| 5 | **Sinar Scanner 1D Terganggu QR Code** | Rendah | Memberikan jarak batas (*quiet zone*) minimal 12 mm antara barcode 1D dan QR Code pada template kartu cetak. |
| 6 | **Audio Feedback Tidak Berbunyi di HP** | Rendah | Audio HTML5 di Android memerlukan interaksi pengguna (*user gesture*). Memulai pemutaran audio saat operator menekan tombol "Buka Kamera QR" agar browser memberikan izin audio context. |

---

## 15. Daftar File yang Akan Diubah pada Tahap Implementasi

*(Catatan: Tidak ada perubahan yang dilakukan pada tahap audit ini)*

### File Baru yang Akan Dibuat (Tahap 1 & 2):
1. `app/Services/LostWax/LostWaxQrCodeService.php` — Service rendering SVG QR Code berbasis `bacon/bacon-qr-code`.
2. `public/js/html5-qrcode.min.js` — File aset standalone QR scanner client-side.

### File Eksisting yang Akan Diubah:
1. `resources/views/lost-wax/trees/traveler.blade.php`:
   - Menambahkan elemen QR Code SVG di samping barcode 1D pada template kartu A4.
2. `resources/views/lost-wax/scan-siap-cor/index.blade.php`:
   - Menambahkan tombol toggle kamera QR dan container preview video.
   - Menambahkan logika inisialisasi `Html5Qrcode`, guard anti-duplicate, dan integrasi dengan audio feedback.
3. `app/Http/Controllers/LostWax/TreeController.php`:
   - Menambahkan pemanggilan service QR Code untuk dioper ke view traveler.

---

## 16. Kesimpulan Hasil Audit

1. **Kelayakan Teknis:** Migrasi penambahan QR Code **sangat layak dan aman** untuk diterapkan pada modul Lost Wax.
2. **Ketersediaan Library:** Library PHP backend `bacon/bacon-qr-code` **sudah tersedia di dalam proyek**. Tidak diperlukan penambahan package Composer baru.
3. **Integritas Bisnis:** Tidak ada perubahan pada skema database, tabel, relasi, maupun `ScanSiapCorService`. QR Code murni membawa string nomor identitas pohon yang identik dengan barcode 1D.
4. **Solusi Jaringan HTTP:** Akses kamera Android pada IP lokal `http://10.88.8.97` dapat langsung dijalankan selama fase uji coba menggunakan flag bawaan Chrome Android `unsafely-treat-insecure-origin-as-secure` tanpa mengganggu konfigurasi server produksi.
5. **Keselamatan Operasional:** Penerapan bertahap memastikan operasional pemindai kabel/Bluetooth eksisting tetap berjalan 100% tanpa risiko downtime.

