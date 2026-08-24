# Phase 2 — Rack Monitor Foundation

**Tanggal:** 2026-08-24  
**Scope:** Aggregation layer & aging configuration for Lost Wax Coating Rack Dashboard  
**Status:** IMPLEMENTASI SELESAI (Phase 1 & Phase 2) — Test suite hijau 100%

---

## 1. Files Changed

1. **`config/lost_wax.php`**
   - Diperluas dengan parameter `aging.stages` untuk mendefinisikan batas minimum (`min_hours`), maksimum (`max_hours`), dan toleransi buffer (`buffer_hours`) per-stage.
   - Retain legacy fallback global (`min_hours` dan `max_hours`) untuk backward compatibility.

2. **`app/Services/LostWax/RackMonitorService.php` [NEW]**
   - Service baru sebagai aggregation layer/translator dari state level tree (`LostWaxTree`) ke level operational monitor rack (`LostWaxCoatingRack`).
   - Menyediakan interface: `getActiveRacks()`, `getRackDetail(int $rackId)`, dan `getUnassignedTreeCount()`.

3. **`tests/Feature/LostWax/RackMonitorServiceTest.php` [NEW]**
   - Berisi 10 test case fungsionalitas dan performa aggregation layer (termasuk deteksi mix stage, split layer 7, void safety, dan N+1 query guard).

---

## 2. Business Rules Implemented

- **Coating Rack Scope:** Unit monitoring utama adalah `LostWaxCoatingRack` (aktif & berisi tree). Rack kosong tidak muncul di active priority list.
- **Tree-level source of truth:** `current_stage` dan `last_scan_at` pada `LostWaxTree` tetap menjadi baseline state data.
- **Rack Dominant Stage:** Ditentukan berdasarkan stage dengan jumlah tree terbanyak dalam rack tersebut. Jika terjadi tie (seri), ditentukan berdasarkan urutan stage di config (tertinggi/terawal di config prioritas pertama).
- **Rack Aging Status:**
  - `age < min_hours` → **`normal`** (belum matang)
  - `age >= min_hours` dan `age <= buffer_hours` → **`ready`** (siap diproses/dikeluarkan)
  - `age > buffer_hours` → **`late`** (terlambat/overdue)
- **Void Safety:** Aggregation menggunakan relationship state tree (`current_stage` & `last_scan_at`), di mana `ScanVoidService` secara otomatis memperbarui state ini setelah void dilakukan, sehingga query tetap konsisten secara void-safe.
- **Layer 7 Split:** Jika rack berisi campuran stage yang melibatkan `layer_7` bersama stage lain (seperti `layer_6` or `oven`), atau jika ada campuran stage karena skip logic (`require_layer_7 = false`), maka `is_layer7_split` diset `true`.
- **Rack Movement:** Pemindahan rack (update `rack_id` di tree) tidak mereset timestamp `last_scan_at`, melestarikan akumulasi umur aging proses.

---

## 3. Aggregation Logic & Query Strategy

### Last Valid Scan Rule (MAX)
Untuk menghindari false-ready, rack baru dianggap mulai aging setelah tree terakhir yang relevan masuk ke stage tersebut:
$$\text{rack\_stage\_started\_at} = \max(\text{last\_scan\_at}) \quad \text{dimana } \text{current\_stage} = \text{dominant\_stage}$$

### N+1 Query Guard
Query data dilakukan secara bounded dengan startegi batching:
1. Load tree yang memiliki `rack_id IS NOT NULL` dan `coatingRack.status = 'active'`.
2. Gunakan `with('coatingRack')` agar relasi ke master rack ter-eager-load.
3. Lakukan pengelompokan (`groupBy('rack_id')`) dan kalkulasi agregat sepenuhnya di memori PHP.
Hal ini membatasi jumlah query database ke jumlah tetap konstan (2 query utama) tanpa bergantung linear terhadap jumlah rack di lapangan.

---

## 4. Test Results

Hasil eksekusi `php artisan test --filter=RackMonitorServiceTest` menunjukkan 100% PASS:
- **Test 1:** 35 rack tersedia & aktif.
- **Test 2:** Dominant stage terdeteksi benar (`layer_1` untuk 30 tree).
- **Test 3:** Mixed rack terdeteksi dengan `is_mixed = true` dan distribusi stage tepat.
- **Test 4:** `rack_stage_started_at` menggunakan `MAX(last_scan_at)` (LAST scan).
- **Test 5:** Integration dengan `ScanVoidService` berjalan lancar, event void otomatis diabaikan dan state ter-reconstruct secara void-safe.
- **Test 6:** Layer 7 aging mematuhi batas 24 jam / 26 jam, bukan global fallback 6 jam.
- **Test 7:** Rack movement tidak mengganggu/mengubah timestamp aging.
- **Test 8:** Tree tanpa rack dihitung sebagai `unassigned_tree_count` dan tidak masuk ke rack aggregation.
- **Test 9:** Empty racks (rack tanpa tree aktif) dikecualikan dari `getActiveRacks()`.
- **Test 10:** Query count dibatasi (2 query) untuk mencegah regresi N+1.

*Seluruh test suite project (`composer test`, 262 tests) lulus sukses.*

---

## 5. Known Limitations

- **Moulding vs Coating Rack:** Tabel `lost_wax_racks` tetap milik legacy Moulding (cetak). Implementasi ini menggunakan tabel `lost_wax_coating_racks` yang terpisah secara fisik dan logical.
- **Dynamic Clock:** Tes menggunakan `Carbon::setTestNow` untuk manipulasi waktu. Aplikasi di server harus tersinkronisasi NTP untuk menjamin keakuratan aging dalam hitungan menit.

---

## 6. Recommendation for Phase 3 (Dashboard UI)

1. **New Controller:** Buat `RackMonitorController` terpisah dari `DashboardController` existing untuk menangani routing dashboard SPV Lapisan (`/lost-wax/rack-monitor`).
2. **High-Density UI:** Tampilkan visualisasi board rack dengan sorting prioritas: `LATE` (merah) -> `READY` (kuning/hijau stabil) -> `NORMAL` (biru/netral). Urutkan berdasarkan sisa waktu atau lama waktu overdue.
3. **Layer 7 Split Handlers:** Tampilkan badge "SPLIT" pada baris rack jika `is_layer7_split = true` dan visualisasikan distribusi tree-nya (misal: "15 tree di Lapisan 7, 10 tree langsung ke Oven").
4. **Header Alert:** Tampilkan warning badge di header dashboard jika `unassigned_tree_count > 0` untuk mendisiplinkan operator melakukan assignment rack sebelum scanning dimulai.
