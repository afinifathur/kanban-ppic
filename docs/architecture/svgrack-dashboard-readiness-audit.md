# Lost Wax SPV Rack Dashboard Readiness Audit

**Tanggal:** 2026-08-24  
**Scope:** SPV Lapisan — Rack-centric Monitoring Dashboard  
**Status Audit:** READ-ONLY — tidak ada perubahan kode

---

## 1. Executive Verdict

### ✅ READY WITH MODIFICATIONS

Fondasi teknis sudah solid. Sejak audit sebelumnya (2026-08-23), **ketiga gap utama telah diselesaikan** via additive migrations yang sudah diterapkan:

- `rack_id` + `rack_assigned_at` → sudah ada di `lost_wax_trees`
- `lost_wax_coating_racks` (35 rack, seeder sudah jalan) → tabel baru yang benar, terpisah dari moulding rack legacy
- Void-safe scan event ledger → sudah proven di production

**Yang masih perlu diselesaikan sebelum BUILD PHASE:**

1. **MUST:** Aging config masih flat 1 threshold global — belum per-stage
2. **MUST:** Tidak ada `rack_stage_started_at` tersimpan — harus on-the-fly query atau denormalisasi baru
3. **SHOULD:** Tidak ada rack-level aggregation service — harus dibuat baru
4. **OPTIONAL:** Tidak ada rack movement history — `rack_id` adalah current position saja

---

## 2. Current Architecture

```
Scan Barcode (Operator)
    ↓
ScanController::process()
    ↓
ScanService::process()  [DB transaction + lockForUpdate]
    ↓ writes
LostWaxScanEvent  {tree_id, stage, scanned_at, result, aging_minutes, aging_status}
    ↓ updates
LostWaxTree       {current_stage, last_scan_at}
    │
    ├── rack_id ──────────→ LostWaxCoatingRack {rack_number, label, status}
    └── require_layer_7     (per tree, boolean)

Aging Classification (ScanService::classifyAging):
    aging_minutes = now - tree.last_scan_at
    → compares against config('lost_wax.aging.min_hours') & max_hours
    → result: 'too_fast' | 'normal' | 'too_long'

Void Safety:
    LostWaxScanEventVoid {scan_event_id}
    → all dashboard queries filter: whereDoesntHave('void')
    → ScanVoidService rebuilds current_stage + last_scan_at after void
```

---

## 3. Source of Truth Matrix

| Information | Source | Table | Field/Event | Confidence |
|---|---|---|---|---|
| Current rack | `LostWaxTree.rack_id` | `lost_wax_trees` | `rack_id` (FK nullable) | ✅ HIGH |
| Current stage | `LostWaxTree.current_stage` | `lost_wax_trees` | `current_stage` (varchar 20, nullable) | ✅ HIGH |
| Stage entry time | Derived | `lost_wax_scan_events` | `MAX(scanned_at)` WHERE stage=X AND rack | ⚠️ DERIVED — no stored field |
| Scan timestamp | `LostWaxScanEvent.scanned_at` | `lost_wax_scan_events` | `scanned_at` (server-side Carbon::now) | ✅ HIGH — immutable ledger |
| Production code | Dual-source | `lost_wax_trees` | via `work_order_id` or `lost_wax_print_order_line_id` | ✅ HIGH |
| Quantity | `LostWaxTree.quantity` | `lost_wax_trees` | `quantity` (integer) | ✅ HIGH |
| Require Layer 7 | `LostWaxTree.require_layer_7` | `lost_wax_trees` | `require_layer_7` (boolean, with WO fallback) | ✅ HIGH |
| Aging status | `LostWaxScanEvent.aging_status` | `lost_wax_scan_events` | `aging_status` per scan event | ⚠️ TREE-LEVEL ONLY, not rack-level |
| Rack assigned at | `LostWaxTree.rack_assigned_at` | `lost_wax_trees` | `rack_assigned_at` (timestamp) | ✅ HIGH |

---

## 4. Rack Aging Analysis — Timestamp Candidate Comparison

**Context:** Satu rack ~30 tree. Operator scan batch-by-batch (3-6 kelompok per rack). Gap antar batch ~20-30 menit.

| Kandidat | Pro | Con | FIFO Impact | False Late | False Ready | Complexity | Cocok SOP? |
|---|---|---|---|---|---|---|---|
| **FIRST scan** | Sederhana | Ignores batch tail; rack dianggap "mulai" sebelum semua tree selesai | Keuntungan palsu — rack terlihat lebih tua | Rendah | Tinggi (ready padahal tree terakhir baru discan) | Rendah | ❌ Tidak |
| **LAST scan** | Rack dianggap "ready" hanya setelah tree terakhir selesai; paling aman untuk FIFO | Hukuman batch besar; satu tree telat block seluruh rack | Paling ketat — mendorong operator menyelesaikan batch | Tinggi (maks) | Rendah | Rendah | ✅ Ya |
| **MEDIAN scan** | Tahan terhadap outlier scan awal/akhir | Kompleks untuk dihitung (ROW_NUMBER atau PHP sort) | Netral | Sedang | Sedang | Tinggi | Tidak jelas |
| **AVERAGE scan** | Smooth terhadap distribusi batch | Sensitive terhadap outlier; waktu fraksional | Netral | Sedang | Sedang | Sedang | Tidak jelas |
| **Explicit event** | Paling akurat operasionally; SPV/operator tandai "rack masuk stage" | Membutuhkan UX baru + disiplin operator | Paling terkontrol | Dapat dikonfigurasi | Dapat dikonfigurasi | Tinggi (UX baru) | Butuh training |

### ⭐ RECOMMENDATION: LAST scan (`MAX(scanned_at)`)

**Alasan dari evidence codebase:**

1. SOP menyatakan operator scan batch per batch — artinya tree terakhir discan mendekati saat semua tree selesai. Gap hanya terjadi di akhir batch, bukan di tengah.
2. Dashboard existing (`DashboardController::buildHotList`) sudah menggunakan pattern `MAX(id)` untuk latest event per tree — pattern ini dapat langsung diextend ke level rack.
3. Void-safe query pattern existing (`whereDoesntHave('void')`) kompatibel dengan `MAX(scanned_at)` — konsistensi arsitektur terjaga.
4. False-ready risk LAST scan lebih baik daripada false-ready risk FIRST scan — lebih aman operasionally untuk aging management.

```sql
-- rack_stage_started_at (void-safe LAST scan)
SELECT MAX(se.scanned_at)
FROM lost_wax_scan_events se
JOIN lost_wax_trees t ON t.id = se.tree_id
WHERE t.rack_id = :rack_id
  AND se.stage = :stage
  AND se.result = 'success'
  AND se.id NOT IN (SELECT scan_event_id FROM lost_wax_scan_event_voids)
```

---

## 5. Aging Configuration Gap

### Yang Sudah Ada

```php
// config/lost_wax.php (SAAT INI)
'aging' => [
    'min_hours' => (float) env('LOST_WAX_AGING_MIN_HOURS', 4),
    'max_hours' => (float) env('LOST_WAX_AGING_MAX_HOURS', 6),
],
```

**SATU threshold flat untuk SEMUA stage.** Tidak ada per-stage config.

`ScanService::classifyAging()` membandingkan terhadap satu pasang min/max ini.

### Yang Harus Diubah (MUST)

Config aging harus diperluas ke per-stage:

```php
// PROPOSED STRUCTURE (jangan implementasi sekarang)
'aging' => [
    'stages' => [
        'layer_1' => ['min_hours' => 4, 'max_hours' => 6, 'buffer_hours' => 8],
        'layer_2' => ['min_hours' => 4, 'max_hours' => 6, 'buffer_hours' => 8],
        'layer_3' => ['min_hours' => 6, 'max_hours' => 6, 'buffer_hours' => 8],
        'layer_4' => ['min_hours' => 6, 'max_hours' => 6, 'buffer_hours' => 8],
        'layer_5' => ['min_hours' => 8, 'max_hours' => 8, 'buffer_hours' => 10],
        'layer_6' => ['min_hours' => 8, 'max_hours' => 8, 'buffer_hours' => 10],
        'layer_7' => ['min_hours' => 24, 'max_hours' => 24, 'buffer_hours' => 26],
    ],
    // fallback global
    'min_hours' => 4,
    'max_hours' => 6,
],
```

### Yang Jangan Disentuh

- `ScanService::classifyAging()` logic — hanya ubah data input dari config
- `LostWaxScanEvent.aging_status` schema — field existing tetap valid
- Tree-level aging calculation — sudah benar, cukup diberi per-stage threshold
- Semua scan events yang sudah tersimpan — immutable, tidak perlu backfill

### Gap Kritis

Saat ini `aging_status = 'too_long'` untuk Layer 7 akan trigger setelah 6 jam — padahal minimum Layer 7 adalah 24 jam. Ini akan menghasilkan **false positive overdue** yang sangat tinggi untuk SPV Dashboard jika tidak diperbaiki terlebih dahulu.

---

## 6. Rack Distribution Readiness

### Pertanyaan: Apakah distribusi rack per stage dapat dihitung?

**✅ YA — dapat dihitung dari data existing dengan query sederhana.**

```sql
-- Distribusi rack per stage (jumlah rack unik per stage majority)
SELECT
    COALESCE(t.current_stage, 'sebelum_scan') as stage,
    COUNT(DISTINCT t.rack_id) as rack_count,
    COUNT(t.id) as tree_count
FROM lost_wax_trees t
WHERE t.rack_id IS NOT NULL
GROUP BY t.current_stage
ORDER BY FIELD(t.current_stage, NULL,'layer_1','layer_2','layer_3',
               'layer_4','layer_5','layer_6','layer_7','oven')
```

**Caveat:** `current_stage` pada tree adalah stage TERAKHIR yang discan, bukan stage "sedang diaging." Untuk SPV, ini sudah benar karena:
- Tree di layer_3 berarti tree sudah selesai layer_3, sedang menunggu untuk layer_4
- Rack yang semua tree-nya di layer_3 = rack sedang aging menuju layer_4

**Tree tanpa rack (rack_id IS NULL):** Tidak akan muncul di distribusi rack. Perlu counter terpisah "tree belum di-rack."

---

## 7. Priority Engine Readiness

### Apakah sistem dapat menghasilkan OVERDUE / READY / NORMAL?

**⚠️ PARTIALLY READY — butuh per-stage config terlebih dahulu.**

**Logika yang dapat dibangun:**

```
rack_stage_started_at = MAX(scanned_at) WHERE rack_id=X AND stage=current_stage
rack_age_minutes      = NOW() - rack_stage_started_at
stage_min_minutes     = config per stage
stage_buffer_minutes  = config per stage

Status:
  rack_age < stage_min  → NORMAL (belum matang)
  rack_age >= stage_min → READY (siap diproses)
  rack_age > stage_buffer → LATE (terlambat)
```

**Ranking untuk display:**

```
ORDER BY:
  1. status ('late' first, then 'ready', then 'normal')
  2. rack_age_minutes DESC (yang paling terlambat duluan)
  3. stage (layer number, ascending)
```

**Bottleneck detection:** Dapat dihitung (jumlah rack per stage), tetapi **threshold bottleneck adalah BUSINESS RULE YANG BELUM DITETAPKAN.** Jangan hardcode threshold ini tanpa input dari SPV/PPIC.

---

## 8. Edge Cases

| Edge Case | Status Saat Ini | Rekomendasi |
|---|---|---|
| **Mixed production codes dalam 1 rack** | ✅ Didukung — `rack_id` di tree level, bukan WO level | Dashboard SPV tampilkan sebagai summary, detail di drilldown |
| **Mixed scan timestamps** | ✅ Resolved dengan LAST scan recommendation | Gunakan MAX(scanned_at) per rack per stage |
| **Rack movement (tree pindah rack)** | ⚠️ Aging TIDAK berubah saat rack_id diubah — `last_scan_at` tetap | Correct behavior: pindah rack ≠ scan ulang. Aging tetap lanjut dari last_scan_at sebelum pindah |
| **Partial rack (tidak semua tree di stage yang sama)** | ⚠️ Bisa terjadi jika scan tidak serentak | Tampilkan stage mayoritas + badge "MIXED" jika ada split |
| **Layer 7 skip (require_layer_7=false)** | ✅ `nextStage()` sudah menangani — skip ke oven setelah layer_6 | Dashboard rack: deteksi split, tampilkan dua sub-baris |
| **Rack kosong (tidak ada tree)** | `LostWaxCoatingRack` tidak memiliki tree | Jangan tampilkan di dashboard aktif. Filter: racks WHERE EXISTS (trees) |
| **Tree tanpa rack (rack_id NULL)** | ✅ `rack_id` nullable — tree boleh tidak punya rack | Tampilkan counter "X tree belum di-rack" di header dashboard |
| **Tree belum scan (current_stage NULL)** | ✅ Stage distribution menyebut ini 'sebelum_scan' | Tampilkan sebagai "Sebelum Scan" di distribusi rack |
| **Void scan event** | ✅ `ScanVoidService` rebuild `current_stage` + `last_scan_at` setelah void | Query rack aging harus tetap void-safe: `NOT IN (scan_event_voids)` |
| **Rack movement + aging** | ⚠️ Jika tree dipindah setelah scanning, aging tetap dari last_scan_at | Ini CORRECT — perpindahan rack bukan event scan ulang |

### Detail: Rack Movement & Aging

Dari `TreeController::updateRack` (line 361-363):
```php
$tree->update([
    'rack_id' => $validated['rack_id'],
    'rack_assigned_at' => $validated['rack_id'] ? now() : null,
]);
```

Perpindahan rack hanya mengubah `rack_id` dan `rack_assigned_at`. **`current_stage` dan `last_scan_at` tidak berubah.** Ini sudah benar: aging terus berjalan dari scan terakhir, tidak di-reset oleh perpindahan fisik rack.

---

## 9. Required Changes

### MUST CHANGE

1. **`config/lost_wax.php` — per-stage aging thresholds**
   - Saat ini: satu flat min/max global
   - Perlu: per-stage min, max, dan buffer
   - Impact: tanpa ini, SPV Dashboard akan false-positive semua layer 5-7

2. **New: Rack Aggregation Service / Query Layer**
   - Tidak ada service existing yang mengagregasi data per rack
   - Perlu: method/service yang hitung `rack_stage_started_at`, `rack_age_minutes`, status per rack
   - Tidak boleh on-the-fly naif (N+1) untuk 35 rack

### SHOULD CHANGE

3. **SPV Dashboard Controller — new controller**
   - `DashboardController.php` existing adalah PPIC dashboard (tree-centric)
   - SPV butuh rack-centric controller yang berbeda
   - Tidak perlu memodifikasi existing controller

4. **`ScanService::classifyAging()` — aware of stage context**
   - Saat ini tidak menerima stage parameter
   - Perlu menerima stage agar threshold per-stage bisa diaplikasikan
   - Method signature change tapi backward compatible dengan default fallback

### OPTIONAL

5. **Rack movement history table**
   - Saat ini tidak ada audit trail perpindahan tree antar rack
   - Untuk MVP tidak perlu
   - Jika dibutuhkan di masa depan: tabel `lost_wax_tree_rack_histories` append-only

6. **Denormalized `rack_current_stage` pada `lost_wax_coating_racks`**
   - Untuk performa jika on-the-fly query lambat dengan banyak rack
   - Tidak perlu untuk MVP (35 rack manageable)

---

## 10. Proposed Data Flow — Dashboard SPV

```
GET /lost-wax/spv-dashboard
    ↓
RackMonitorController::index()
    ↓
1. Load active racks (lost_wax_coating_racks WHERE status='active')
2. JOIN trees (WHERE rack_id IS NOT NULL)
3. Aggregate per rack:
   - dominant_stage = MODE(current_stage) atau GROUP BY rack_id, current_stage
   - rack_stage_started_at = MAX(scan_events.scanned_at) WHERE stage=dominant_stage
   - rack_age_minutes = NOW() - rack_stage_started_at
   - aging_status = classifyRackAging(rack_age_minutes, dominant_stage)
   - layer7_split = rack contains mixed require_layer_7?
4. Sort: LATE first → READY → NORMAL; within group by rack_age DESC
5. Return rack list to view

View: rack-monitor/index.blade.php
    ├── Header: distribusi rack per stage (count)
    ├── Priority list: 🔴 LATE / 🟡 READY / 🟢 NORMAL per rack
    └── Click rack → drilldown tree list (existing tree data)
```

---

## 11. Implementation Phases

### Phase 1 — Aging/Config Foundation
- Ubah `config/lost_wax.php`: tambah per-stage aging thresholds (min, max, buffer)
- Update `ScanService::classifyAging()` untuk menerima stage context
- Pastikan semua existing test tetap hijau
- Tidak ada UI change

### Phase 2 — Rack Aggregation Service
- Buat `RackMonitorService` atau query class
- Method: `getActiveRacks()` → rack list dengan stage, age, status, tree count
- Method: `getRackDetail($rack_id)` → tree list untuk drilldown
- Unit test aggregation logic
- Void-safe semua query

### Phase 3 — SPV Dashboard UI
- Buat `RackMonitorController` (baru, jangan modifikasi DashboardController existing)
- Buat view `lost-wax/rack-monitor/index.blade.php`
- Route: `GET /lost-wax/rack-monitor` (auth middleware)
- Tampilan: priority list per rack + stage distribution header
- Tree detail drilldown: reuse existing tree data (no new queries needed)

### Phase 4 — Validation & UAT
- Manual validation: seed test data dengan variasi stage, rack, require_layer_7
- SPV walkthrough: mock operational scenario (rack late, rack ready, rack split L7)
- Edge case testing: tree tanpa rack, rack kosong, void event effect pada aging
- Confirm aging thresholds dengan SPV sebelum go-live

---

## 12. Risks

| # | Risiko | Sumber dari Codebase | Dampak | Mitigasi |
|---|---|---|---|---|
| 1 | **False overdue Layer 7** | `classifyAging` flat 4-6j threshold — Layer 7 butuh 24j | Semua rack di Layer 7 terlihat "LATE" padahal normal | Phase 1 — per-stage config wajib sebelum build |
| 2 | **N+1 rack aggregation** | Tidak ada existing aggregation service — harus dibuat dari awal | Dashboard lambat jika 35 rack on-the-fly naif | Phase 2 — satu query batch, bukan loop per rack |
| 3 | **Void → stale rack state** | `ScanVoidService` rebuild tree state, tapi on-the-fly rack query otomatis mengikuti | Jika denormalisasi digunakan, bisa stale | Gunakan on-the-fly query untuk MVP; hindari denormalisasi sampai perlu |
| 4 | **Layer 7 split display** | `require_layer_7` tidak uniform dalam satu rack | UI status rack ambiguous | Phase 3 — deteksi split, tampilkan dua sub-baris |
| 5 | **Operator tidak assign rack sebelum scan** | Tidak ada enforcement saat ini — `rack_id` nullable | Tree muncul di scan tapi tidak di rack dashboard | Tambah warning di scan view jika tree belum punya rack |
| 6 | **Dominant stage logic** | Satu rack bisa punya tree di 2-3 stage berbeda jika scan tidak serentak | Stage rack yang ditampilkan bisa menyesatkan | Definisikan: dominant = stage dengan COUNT tree terbanyak; tampilkan badge "MIXED" |
| 7 | **Aging tidak di-reset saat rack pindah** | `updateRack` hanya ubah `rack_id`, bukan `last_scan_at` | SPV mungkin mengira aging reset saat pindah rack | Dokumentasikan SOP: perpindahan rack bukan event scan |
| 8 | **Multi-code rack + PPIC scope** | Tree dalam satu rack bisa berasal dari berbagai ProductionPlan | PPIC scope filter mungkin hanya melihat sebagian tree rack | SPV Dashboard tidak perlu PPIC scope filter — SPV melihat semua |

---

*Audit ini dihasilkan dari pembacaan langsung kode: `LostWaxTree.php`, `LostWaxCoatingRack.php`, `LostWaxScanEvent.php`, `ScanService.php`, `ScanVoidService.php`, `TreeController.php`, `DashboardController.php` (LostWax), `ProductionStatusController.php`, `config/lost_wax.php`, semua migrations terkait, `RackAssignmentTest.php`, `ScanEngineTest.php`, dan audit sebelumnya.*
