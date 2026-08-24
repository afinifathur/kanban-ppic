# Lost Wax Aging Standard

## 1. Purpose
Dokumen ini berfungsi sebagai referensi tunggal (single reference document) untuk standar penuaan (*aging*) yang aktual digunakan oleh sistem Lost Wax pada aplikasi FIFO Tracking. Dokumen ini ditujukan untuk membantu Supervisor Coating (SPV Lapisan) dalam memahami aturan sistem yang menentukan kapan sebuah rak/tree berada dalam status normal, siap diproses, akan siap, atau terlambat.

---

## 2. Source of Truth
Komponen-komponen sistem yang menjadi sumber nilai dan logika penuaan (*aging*) Lost Wax adalah:
1.  **`config/lost_wax.php`**: Konfigurasi nilai ambang batas (*threshold*) jam minimum, maksimum, dan buffer untuk masing-masing tahapan (*stages*).
2.  **`app/Services/LostWax/RackMonitorService.php`**: Mengagregasikan tree ke dalam tingkat rak dan menghitung umur rak serta status penuaan dasar (`normal`, `ready`, `late`).
3.  **`app/Http/Controllers/LostWax/RackMonitorController.php`**: Melakukan pemetaan (*mapping*) status dari service menjadi status presentasi dashboard (`NORMAL`, `NEAR_READY`, `READY`, `LATE`) serta menghitung sisa durasi drying atau durasi overdue.
4.  **`app/Services/ScanService.php`**: Menentukan klasifikasi aging (*ledger scan event*) saat operator melakukan pemindaian barcode tree.

---

## 3. Aging Standard Table

Berikut adalah tabel aturan penuaan aktual yang dikonfigurasi di dalam sistem (`config/lost_wax.php`):

| Stage | Minimum Aging | Standard/Maximum Aging | Buffer | Status Setelah Melewati Buffer | Keterangan |
|---|---:|---:|---:|---|---|
| **Lapisan 1** | 4 jam | 6 jam | 8 jam | LATE (Terlambat) | Batas pengeringan lapisan pertama |
| **Lapisan 2** | 4 jam | 6 jam | 8 jam | LATE (Terlambat) | Batas pengeringan lapisan kedua |
| **Lapisan 3** | 6 jam | 6 jam | 8 jam | LATE (Terlambat) | Batas pengeringan lapisan ketiga |
| **Lapisan 4** | 6 jam | 6 jam | 8 jam | LATE (Terlambat) | Batas pengeringan lapisan keempat |
| **Lapisan 5** | 8 jam | 8 jam | 10 jam | LATE (Terlambat) | Batas pengeringan lapisan kelima |
| **Lapisan 6** | 8 jam | 8 jam | 10 jam | LATE (Terlambat) | Batas pengeringan lapisan keenam |
| **Lapisan 7** | 24 jam | 24 jam | 26 jam | LATE (Terlambat) | Pengeringan lapisan akhir sebelum oven |
| **Oven** | - | - | - | - | Tidak memiliki aturan aging khusus |

*Catatan: Jika ada tahapan yang tidak terdaftar dalam konfigurasi `stages`, sistem menggunakan nilai fallback global yaitu Minimum 4 jam, Standard/Maximum 6 jam, dan Buffer 6 jam.*

---

## 4. Status Classification

Sistem mengklasifikasikan status rak di dalam `RackMonitorController` berdasarkan umur rak ($A$ dalam satuan jam) sebagai berikut:

*   **`NORMAL`** (Sedang Pengeringan):
    $$A < \text{min\_hours} \quad \text{dan} \quad (\text{min\_hours} - A) > 1.0 \text{ jam}$$
    *(Rak masih dalam proses pengeringan reguler dengan sisa waktu menuju siap lebih dari 60 menit).*
*   **`NEAR_READY`** (Akan Siap):
    $$A < \text{min\_hours} \quad \text{dan} \quad (\text{min\_hours} - A) \le 1.0 \text{ jam}$$
    *(Rak belum mencapai batas minimum matang, namun akan siap dalam waktu $\le$ 60 menit).*
*   **`READY`** (Siap Diproses):
    $$\text{min\_hours} \le A \le \text{buffer\_hours}$$
    *(Rak berada di dalam jendela waktu matang yang diperbolehkan untuk proses berikutnya).*
*   **`LATE`** (Terlambat):
    $$A > \text{buffer\_hours}$$
    *(Rak telah melebihi batas waktu toleransi pengeringan).*

---

## 5. Buffer Policy

Kebijakan buffer yang diterapkan di dalam sistem adalah sebagai berikut:
*   **Nilai Buffer:** Setiap tahapan lapisan 1–6 memiliki buffer sebesar **+2 jam** dari batas minimum/maksimumnya (atau +2 jam setelah matang). Lapisan 7 memiliki buffer sebesar **26 jam** (+2 jam setelah minimal 24 jam matang).
*   **Toleransi Keterlambatan:** Buffer bertindak sebagai toleransi durasi pengeringan maksimum sebelum rak dilabeli sebagai **LATE** (Terlambat) pada dashboard monitor.
*   **Pengaruh terhadap Status LATE:** Ya, status LATE dipicu langsung jika umur rak melebihi `buffer_hours` (bukan `max_hours`).
*   **Batasan Transaksi Produksi:** Kebijakan buffer ini **hanya digunakan untuk visualisasi status pada dashboard** (membantu SPV mengatur prioritas pengeluaran). Kebijakan ini **tidak memblokir transaksi scan produksi** di lapangan; operator tetap dapat melakukan scan untuk memindahkan tree/rak meskipun statusnya LATE atau NORMAL.

---

## 6. Rack Aging Logic

Umur rak (*rack aging*) dihitung secara dinamis dari **Last Valid Scan** dari tree-tree yang berada pada *dominant stage* rak tersebut:
*   **Formula Mulai Aging Rak:**
    $$T_{\text{start}} = \max(t_{\text{scan}})$$
    *di mana $t_{\text{scan}}$ adalah timestamp `last_scan_at` dari seluruh tree aktif di dalam rak tersebut yang memiliki tahapan sama dengan dominant stage rak.*
*   **Sebab Penggunaan MAX:**
    Dalam satu rak fisik yang berisi 25–30 tree, operator melakukan scan barcode satu per satu. Menggunakan nilai `MAX(last_scan_at)` memastikan bahwa waktu mulai pengeringan rak dihitung sejak tree terakhir dimasukkan secara fisik ke dalam rak tersebut. Ini mencegah sistem menandakan rak siap/matang secara prematur.
*   **Void-Safe:** Event scan yang dibatalkan (*voided*) secara otomatis diabaikan dari perhitungan `last_scan_at`.

---

## 7. Mixed Stage & Layer 7 Split

*   **Mixed Stage (`is_mixed = true`)**: Terjadi jika terdapat tree dengan tahapan berbeda dalam satu rak. Dominant stage ditentukan dengan mencari stage dengan jumlah tree terbanyak. Jika jumlahnya sama, tie-breaker ditentukan berdasarkan tahapan yang urutan alurnya lebih awal (misal: Lapisan 1 lebih dominan daripada Lapisan 2).
*   **Layer 7 Split (`is_layer7_split = true`)**: Kondisi khusus di mana sebuah rak memiliki campuran tree yang harus melalui Lapisan 7 (karena `require_layer_7 = true`) dan tree yang harus langsung masuk ke Oven (karena `require_layer_7 = false`).
*   **Dampak Terhadap Dashboard**: Jika kondisi ini terdeteksi, badge khusus `MIXED` (oranye) atau `L7 SPLIT` (ungu) akan ditampilkan pada kartu rak. Perhitungan aging rak tetap mengacu pada threshold dari *dominant stage* yang terpilih, namun SPV disarankan membuka modal detail untuk melihat pembagian kerja di dalam rak tersebut.

---

## 8. Physical Production Standard vs System Monitoring Threshold

Terdapat perbedaan mendasar antara standar proses fisik di pabrik dengan threshold yang diimplementasikan di dalam sistem untuk kebutuhan monitoring:

| Parameter | Standard Proses Fisik | System Monitoring Threshold |
|---|---|---|
| **Drying Window L1-L2** | 4 – 6 jam | Min: 4 jam, Max: 6 jam, Buffer: 8 jam |
| **Drying Window L3-L4** | 6 jam | Min: 6 jam, Max: 6 jam, Buffer: 8 jam |
| **Drying Window L5-L6** | 8 jam | Min: 8 jam, Max: 8 jam, Buffer: 10 jam |
| **Drying Window L7** | Min: 24 jam | Min: 24 jam, Max: 24 jam, Buffer: 26 jam |
| **Oven State** | Selesai Pengeringan | Dianggap selesai (tidak di-aging) |

*Penjelasan Perbedaan:*
Sistem membagi drying window menjadi 3 batas: `min_hours` (waktu minimum matang), `max_hours` (target ideal standar fisik), dan `buffer_hours` (toleransi sebelum terlambat). Penambahan `buffer_hours` di sistem berguna agar dashboard tidak langsung berkedip merah (LATE) begitu melewati batas standar fisik, melainkan memberikan toleransi operasional selama 2 jam bagi operator untuk memindahkan rak.

---

## 9. Important Findings

### Confirmed
1.  **Autoritatif Konfigurasi**: `config/lost_wax.php` adalah satu-satunya berkas konfigurasi tempat seluruh threshold aging didefinisikan.
2.  **Kalkulasi Dashboard**: Perhitungan status `NORMAL`, `NEAR_READY`, `READY`, dan `LATE` pada monitor rak dilakukan secara real-time di level Controller menggunakan data agregat `RackMonitorService`.

### Potential Gap
1.  **Ketidakcocokan Logika pada `ScanService`**:
    Metode `ScanService::classifyAging` yang digunakan untuk mencatat status aging pada ledger transaksi (`lost_wax_scan_events.aging_status`) **tidak menggunakan konfigurasi per-stage** (`config('lost_wax.aging.stages')`). Ia hanya menggunakan fallback global (min 4 jam, max 6 jam).
    *Dampak:* Jika operator memindai tree Lapisan 7 setelah 12 jam (yang secara fisik terlalu cepat karena minimal 24 jam), sistem mencatat status transaksinya sebagai `too_long` (karena 12 > 6 jam fallback global) di database, bukan `too_fast`. Hal ini menyebabkan inkonsistensi data riwayat penuaan di database scan event ledger.

### No Assumption
1.  **Aging Tahapan Oven**: Tahapan `oven` tidak memiliki aturan aging di dalam `config/lost_wax.php` maupun dalam filter controller. Oven dianggap sebagai tahapan akhir sehingga tidak masuk dalam monitor antrean aging.

---

## 10. Recommendation

1.  **Penyelarasan Logika Transaksi (`ScanService`)**:
    Disarankan untuk memperbarui `ScanService::classifyAging(int $minutes)` agar menerima parameter tambahan `$stage` sehingga klasifikasi di tabel database `lost_wax_scan_events` selaras dengan aturan stage masing-masing (terutama untuk mendeteksi `too_fast` pada Lapisan 7).

---

## 11. Verification Evidence

| Area | Source | Finding |
|---|---|---|
| **Aging Config** | [lost_wax.php](file:///c:/laragon/www/KANBAN-PPIC/config/lost_wax.php#L33-L73) | Konfigurasi threshold per-stage (`min_hours`, `max_hours`, `buffer_hours`). |
| **Dashboard Mapping** | [RackMonitorController.php](file:///c:/laragon/www/KANBAN-PPIC/app/Http/Controllers/LostWax/RackMonitorController.php#L23-L73) | Logika klasifikasi `NORMAL`, `NEAR_READY`, `READY`, `LATE` dan sisa durasi / overdue. |
| **Service Integration** | [RackMonitorService.php](file:///c:/laragon/www/KANBAN-PPIC/app/Services/LostWax/RackMonitorService.php#L167-L195) | Agregasi dan pencarian dominant stage, `MAX(last_scan_at)`. |
| **Scan Ledger Logic** | [ScanService.php](file:///c:/laragon/www/KANBAN-PPIC/app/Services/ScanService.php#L113-L129) | Fungsi `classifyAging` menggunakan hardcoded fallback global (4 - 6 jam). |
| **Regression Verification** | [RackMonitorDashboardTest.php](file:///c:/laragon/www/KANBAN-PPIC/tests/Feature/LostWax/RackMonitorDashboardTest.php) | Pengujian fungsionalitas threshold, prioritas, mixed, dan split stage (100% Passed). |
