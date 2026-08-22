# Phase 5 Pre-Implementation Safety Review — Scan Void & Correction

**Date:** 2026-08-22  
**Author:** Antigravity  
**Final Verdict:** PLAN SAFE WITH MODIFICATIONS (Strict Latest-Event-Only Void Rule)

---

## 1. Executive Summary
Laporan ini adalah pre-implementation safety review untuk Phase 5. Fokus utama audit adalah memastikan mekanisme **Scan Void & Correction** berjalan secara aman, sinkron dengan kondisi fisik di pabrik, dan tidak merusak data historis. Hasil review menyimpulkan bahwa memulihkan status database secara acak (misal men-void event di tengah antrean) sangatlah berbahaya bagi tracking fisik. Oleh karena itu, diusulkan modifikasi aturan kebijakan void yang lebih ketat: **Hanya scan terbaru (the latest active event) yang boleh di-void.**

---

## 2. Layer State Machine Audit
Dari pemeriksaan actual code pada `LostWaxTree`, `ScanService`, dan `ScanController`:
- **Penyimpanan State:** Kolom `current_stage` dan `last_scan_at` pada tabel `lost_wax_trees` bertindak sebagai active state tracker.
- **State Transition:** Ditentukan secara berurutan berdasarkan urutan array key di config `lost_wax.stages`:
  `lapisan_1` $\rightarrow$ `lapisan_2` $\rightarrow$ `lapisan_3` $\rightarrow$ `lapisan_4` $\rightarrow$ `lapisan_5` $\rightarrow$ `lapisan_6` $\rightarrow$ `lapisan_7` (juga opsional) $\rightarrow$ `oven` (via separate Oven process).
- **Oven Closure:** Ketika tree di-scan Oven, `current_stage` diset ke `'oven'`. Status `'oven'` memblokir seluruh scan berikutnya secara permanen.
- **Append-Only Ledger:** Setiap scan event mencatat baris baru di tabel `lost_wax_scan_events` dan tidak memutasi baris sebelumnya.

---

## 3. Void Reconstruction Audit
Mari kita simulasikan skenario penghapusan logis:
- **Kasus A: L1 → L2 → L3 → L4 → L5. Void L5 (terbaru).**
  - Status aktif kembali ke L4. Ini **sangat aman** karena secara fisik pohon baru saja di-scan L5 dan belum masuk ke L6.
- **Kasus B: L1 → L2 → L3 → L4 → L5 → L6. Void L6 (terbaru).**
  - Status aktif kembali ke L5. Ini **sangat aman**.
- **Kasus C: L1 → L2 → L3 → L4 → L5 → L6 → L7 → Oven. Mencoba void L4 (bukan terbaru).**
  - **Analisis:** Jika L4 di-void padahal fisik pohon sudah sampai Oven, maka memundurkan database status ke L3 akan menyebabkan kekacauan besar. Operator akan dipaksa men-scan ulang L4, L5, L6, L7, Oven padahal secara fisik pohon tersebut sudah dicelup berkali-kali dan sudah selesai di dalam Oven. Hal ini merusak perhitungan aging dan memicu data tracking sampah.
  - **Keputusan Kebijakan:** Void pada event yang sudah lampau (bukan event terakhir yang aktif) **harus ditolak secara mutlak**.

---

## 4. Oven Safety
- **Permanency:** Oven secara fisik dan sistem menutup siklus pelapisan tree secara permanen.
- **Void Policy pada Oven:**
  - Hanya event Oven itu sendiri yang boleh di-void (jika itu merupakan event terbaru dari tree tersebut).
  - Setelah Oven di-void, status tree kembali ke stage aktif terakhir (`layer_6` atau `layer_7`).
  - Men-void event layer sebelum Oven ketika status tree sudah `'oven'` harus ditolak demi keamanan data produksi fisik.

---

## 5. Layer 7 Safety
- Layer 7 bersifat opsional berdasarkan status `require_layer_7` pada physical tree.
- Logika skipping Layer 7 pada `LostWaxTree::nextStage()` bergantung langsung pada `require_layer_7` accessor (yang diperbaiki di awal Phase 5).
- Mekanisme void tidak mengubah config atau state state machine Layer 7, melainkan hanya memulihkan stage sebelumnya secara linier.

---

## 6. Voided Event Query Audit
Untuk mencegah metrik tracking di dashboard menjadi bias akibat data yang sudah di-void, query-query berikut wajib disesuaikan agar mengecualikan voided events (`whereDoesntHave('void')`):

1. **DashboardController.php (Line 89, 111):** Penghitungan anomaly aging.
   ```php
   // Wajib mengecualikan voided events pada subquery SELECT MAX(id)
   id IN (SELECT MAX(id) FROM lost_wax_scan_events WHERE result = 'success' AND id NOT IN (SELECT scan_event_id FROM lost_wax_scan_event_voids) GROUP BY tree_id)
   ```
2. **DashboardController.php (Line 173):** Metrik penuaan di dashboard.
   ```php
   $latestEvents = LostWaxScanEvent::where('result', 'success')->whereDoesntHave('void')...
   ```
3. **DashboardController.php (Line 216 & 220):** Query Hotlist penuaan tree.
   ```php
   $latestEventIds = LostWaxScanEvent::where('result', 'success')->whereDoesntHave('void')...
   ```
4. **ProductionStatusController.php (Line 81):** Pelacakan status live per tree untuk PPIC.
   ```php
   $latestEventIds = LostWaxScanEvent::whereIn('tree_id', $treeIds)->where('result', 'success')->whereDoesntHave('void')...
   ```

---

## 7. Authorization Audit
- Menggunakan Spatie Permission yang ada di model `User`.
- Hanya user dengan role `'ppic'` atau `'admin'` yang diotorisasi untuk men-void scan event.
- Operator produksi biasa (`role('operator')`) tidak memiliki akses ke fitur ini.

---

## 8. Critical Business Safety Question
- **Pertanyaan:** Apakah "recalculate `current_stage` setelah void" selalu aman?
- **Jawaban:** **Hanya aman jika yang di-void adalah event terbaru.** Jika event lampau di-void, recalculation status akan memicu ketidaksinkronan antara data digital dan wujud fisik pohon di lapangan. Oleh karena itu, kita membatasi hak void hanya untuk event terbaru.

---

## 9. Recommended Void Policy Table

| Tree State | Event Being Voided | Allowed? | Result | Rationale |
|------------|---------------------|----------|--------|-----------|
| **Active** | Latest Event | **YES** | Revert `current_stage` & `last_scan_at` to previous non-voided event | Safe; physical tree has not progressed. |
| **Active** | Older Event | **NO** | Rejected | Unsafe; physical tree has progressed beyond this event. |
| **Layer 6**| Layer 6 (latest) | **YES** | Revert state to Layer 5 | Safe; cancels Layer 6 scan. |
| **Layer 6**| Layer 4 (older) | **NO** | Rejected | Unsafe; physical tree has completed L5 and L6. |
| **Layer 7**| Layer 7 (latest) | **YES** | Revert state to Layer 6 | Safe; cancels Layer 7 scan. |
| **Oven**   | Oven Event (latest) | **YES** | Revert state to Layer 6 / Layer 7 | Safe; cancels Oven closure. |
| **Oven**   | Prior Layer (older)| **NO** | Rejected | Unsafe; tree is physically completed and closed. |

---

## 10. Final Verdict
**PLAN SAFE WITH MODIFICATIONS**

### Modifikasi yang Wajib Diterapkan:
1. **Rule Validation:** Batasi aksi void hanya pada event terbaru (`id` event yang akan di-void harus sama dengan `MAX(id)` event sukses yang dimiliki tree tersebut).
2. **Dashboard Filters:** Terapkan filter `whereDoesntHave('void')` pada query detail dashboard dan tracker di `DashboardController` dan `ProductionStatusController`.
