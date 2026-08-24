# Rack Flow Architecture Readiness Audit

**Tanggal Audit:** 2026-08-23  
**Scope:** Lost Wax module — kesiapan menambahkan Rack Monitoring  
**Status:** AUDIT SAJA — tidak ada perubahan kode

---

## 1. Executive Summary

**READY WITH MODIFICATIONS**

Arsitektur existing sudah menyediakan fondasi yang cukup kuat. Scan event ledger bersifat append-only, tree sudah menyimpan `current_stage` dan `last_scan_at`, dan tabel `lost_wax_racks` sudah ada di database. Namun terdapat tiga gap utama yang harus diisi sebelum Rack Monitor dapat dibangun:

1. `lost_wax_trees` belum memiliki `rack_id` — relasi Tree → Rack belum ada
2. `lost_wax_racks` yang ada didesain untuk `LostWaxMouldingInstance` (legacy moulding), bukan untuk Coating Flow
3. Tidak ada mekanisme `rack_stage_started_at` — aging rack belum dapat dihitung tanpa additive field atau on-the-fly query baru

Semua gap ini dapat diselesaikan dengan **additive migrations** tanpa merusak data atau business logic existing.

---

## 2. Existing Architecture

```
ProductionPlan
  └── PrintOrderLine (lost_wax_print_order_lines)
        ├── LostWaxPrintOrder
        ├── RangkaiWorkOrder (lost_wax_rangkai_work_orders)
        │     └── RangkaiExecution (lost_wax_rangkai_executions)
        │           └── LostWaxTree (via rangkai_execution_id)
        └── LostWaxTree (via lost_wax_print_order_line_id)
                └── LostWaxScanEvent (tree_id, stage, scanned_at, result)
                      └── LostWaxScanEventVoid (scan_event_id) [Phase 5]

LostWaxTree fields (scan state):
  current_stage  → stage terakhir yang aktif (nullable)
  last_scan_at   → timestamp scan sukses terakhir (nullable)

LostWaxScanEvent fields (ledger):
  tree_id, barcode, stage, scanned_at, operator_id,
  result ('success'|'rejected'), anomaly_reason,
  aging_minutes, aging_status
```

**Alur data scan:**  
Operator scan barcode → `ScanController::process` → `ScanService::process` → tulis `LostWaxScanEvent` + update `LostWaxTree.current_stage` & `last_scan_at` dalam satu DB transaction.

**Legacy flow (LostWaxWorkOrder) tetap hidup** berdampingan dengan new flow (PrintOrderLine) — keduanya masih dilayani oleh scan dan dashboard yang sama.

---

## 3. Wave Audit

### Skema

`wave_number` ada di tabel `lost_wax_work_order_plans` (migrasi `2026_08_09_000006`):

```sql
CREATE TABLE lost_wax_work_order_plans (
  id, work_order_id, wave_number UNSIGNED INT,
  plan_type, planned_quantity, status, reason, notes, timestamps
  UNIQUE (work_order_id, wave_number)
)
```

### Business Meaning

Wave adalah **urutan rencana pengerjaan dalam satu Work Order (legacy flow)**. Satu WO dapat memiliki beberapa Wave (Wave 001, Wave 002, dst.) yang masing-masing merepresentasikan planning batch terpisah.

- **Bukan** pengiriman/shipment batch
- **Bukan** kelompok fisik tree di lapangan
- **Bukan** timing slot (tidak terkait timestamp produksi)

### Producer

`WorkOrderController::storePlan` (~line 164) — dibuat manual oleh pengguna saat menambah plan ke WO. Auto-increment dari `MAX(wave_number) + 1`.

### Consumer

- View `work-orders/show.blade.php` — menampilkan "Wave 001", "Wave 002"
- View `trees/index.blade.php` — kolom "Wave" via `$tree->plan->wave_number`
- View `trees/traveler.blade.php` — label "Wave XXX" pada dokumen cetak
- `TreeController` — menampilkan wave saat generate tree
- Tests — digunakan sebagai required field saat membuat `LostWaxWorkOrderPlan`

### Lifecycle

- Dibuat saat plan ditambahkan ke WO (immutable in practice)
- `LostWaxTree.work_order_plan_id` menyimpan relasi ke plan — wave dapat ditrace dari tree
- Hanya relevan untuk legacy flow (`LostWaxWorkOrder`) — **new flow (PrintOrderLine) tidak mengenal Wave**

### Safety untuk Rack

Wave **tidak boleh direpurpose sebagai Rack**:

- Wave hanya ada di legacy flow — tree dari new flow tidak memiliki plan/wave sama sekali
- Wave adalah planning unit (jumlah pcs yang direncanakan), bukan operational grouping fisik
- Nilai Wave adalah integer urutan (1, 2, 3), bukan nomor fisik seperti nomor rack

**Rekomendasi:** Pisahkan sepenuhnya. Wave tetap sebagai planning artifact untuk legacy flow. Rack adalah operational grouping baru yang harus mendukung kedua flow.

---

## 4. Rack Readiness

### Tabel `lost_wax_racks` yang ada

```sql
-- Migrasi: 2026_08_09_000002
id, code VARCHAR(50) UNIQUE, label, location, status('active'), notes, timestamps
```

Relasi yang ada: `LostWaxRack` → `hasMany(LostWaxMouldingInstance)` via `rack_id`.

**Masalah:** Tabel ini didesain untuk Moulding (cetak), bukan untuk Coating rack. Relasi eksistingnya ke `LostWaxMouldingInstance` tidak berkaitan dengan scan/coating flow.

### Kesiapan `lost_wax_trees`

`LostWaxTree` saat ini **tidak memiliki `rack_id`**. Tidak ada FK ke rack manapun.

**Gap:** Perlu migration additive untuk menambahkan `rack_id` nullable ke `lost_wax_trees`.

### Opsi

**Opsi A:** Reuse tabel `lost_wax_racks` dengan menambahkan `rack_number` integer dan FK `rack_id` ke trees.  
**Opsi B:** Buat tabel rack baru yang terpisah untuk coating.

Opsi A lebih ekonomis. Opsi B lebih bersih secara domain. Perlu **keputusan bisnis**: apakah rack moulding dan rack coating adalah rack fisik yang sama?

---

## 5. Scan Event Readiness

### Append-only ledger

`lost_wax_scan_events` bersifat append-only by design — setiap scan menghasilkan baris baru. Ini ideal untuk audit trail dan cohort reconstruction.

### Kemampuan cohort reconstruction

Dari scan events existing, cohort rack **dapat direkonstruksi** on-the-fly:

```sql
-- rack_stage_started_at untuk Rack 17 di Layer 3
SELECT MAX(se.scanned_at)
FROM lost_wax_scan_events se
JOIN lost_wax_trees t ON t.id = se.tree_id
WHERE t.rack_id = 17
  AND se.stage = 'layer_3'
  AND se.result = 'success'
  AND se.id NOT IN (SELECT scan_event_id FROM lost_wax_scan_event_voids)
```

`MAX(scanned_at)` dari seluruh tree dalam rack untuk stage tertentu = `rack_stage_started_at` yang diinginkan. Ini secara otomatis menggunakan waktu batch terakhir selesai, mengatasi spread 20-30 menit.

### Gap

Tidak ada `rack_id` di tree maupun di scan event. Query di atas hanya dapat dilakukan setelah `rack_id` ditambahkan ke `lost_wax_trees`. Scan event itu sendiri **tidak perlu diubah**.

---

## 6. Aging Readiness

### Tree aging existing

Aging dihitung **per-tree, per-scan** di `ScanService::process`:

```php
$agingMinutes = round($tree->last_scan_at->diffInMinutes($scannedAt));
$agingStatus  = $this->classifyAging($agingMinutes);
```

`classifyAging` menggunakan threshold **flat dan global** dari config:

```php
// config/lost_wax.php — SATU threshold untuk SEMUA stage
'min_hours' => env('LOST_WAX_AGING_MIN_HOURS', 4),  // default 4j
'max_hours' => env('LOST_WAX_AGING_MAX_HOURS', 6),  // default 6j
```

**Gap kritis:** Config aging tidak memiliki per-stage threshold. Business requirement menyatakan Layer 1-4: 4-6j, Layer 5-6: 8j, Layer 7: 24j. Ini sudah merupakan masalah di tree aging sekarang, terlepas dari rack.

### Rack aging konseptual

```
rack_stage_started_at = MAX(scanned_at) dari seluruh tree rack di stage tersebut
rack_age_minutes      = NOW() - rack_stage_started_at
ETA_minutes           = stage_standard_minutes - rack_age_minutes
```

Status aging rack:
- `rack_age_minutes < stage_min` → belum matang
- `rack_age_minutes in [min, max]` → normal / matang
- `rack_age_minutes in (max, overdue_threshold]` → warning
- `rack_age_minutes > overdue_threshold` → overdue / terlambat

### Risiko timestamp spread

Tidak menjadi masalah jika menggunakan `MAX(scanned_at)` — rack baru dianggap "masuk stage" setelah seluruh tree selesai discan.

### Gap yang perlu diperbaiki

1. Config aging perlu per-stage thresholds
2. `rack_stage_started_at` harus dihitung on-the-fly atau disimpan sebagai denormalisasi

---

## 7. Layer 7 Risk

### Mekanisme existing

`LostWaxTree.require_layer_7` (boolean, default false) sudah ada. `nextStage()` sudah menangani skip:

```php
// LostWaxTree.php:221-223
if ($this->current_stage === 'layer_6' && $nextStage === 'layer_7' && !$this->require_layer_7) {
    $nextStage = $stages[$nextIdx + 1] ?? null; // skip ke oven
}
```

### Implikasi untuk Rack

Rack 17 dengan 30 tree: 24 tree `require_layer_7=true`, 6 tree `require_layer_7=false`. Setelah Layer 6, **satu rack berada di dua stage sekaligus**.

**Jangan hardcode "satu rack = satu stage."** Model data dan UI harus mengizinkan split state.

Rekomendasi tampilan:
- Deteksi split: `require_layer_7` tidak uniform di seluruh tree rack
- Tampilkan sebagai "SPLIT: 24 tree → Layer 7 | 6 tree → Oven-ready"

---

## 8. Void Compatibility

### Phase 5 logic

`ScanVoidService::void`:
1. Lock tree dan event
2. Verifikasi hanya latest active success event yang dapat di-void
3. Buat `LostWaxScanEventVoid` record
4. Rekonstruksi `current_stage` + `last_scan_at` dari event non-voided terbaru

### Kompatibilitas dengan Rack

Void **aman** selama rack aging dihitung on-the-fly karena:

- Query `rack_stage_started_at` menggunakan pattern `id NOT IN (SELECT scan_event_id FROM lost_wax_scan_event_voids)` — sama dengan existing dashboard queries
- Setelah void, tree kembali ke stage sebelumnya, dan query rack akan otomatis mencerminkan state baru

**Risiko:** Jika `rack_stage_started_at` disimpan sebagai denormalized field (bukan on-the-fly), void akan membuat field tersebut stale. Solusi: gunakan on-the-fly query, atau trigger recompute saat void dipanggil.

---

## 9. Dashboard Readiness

### PPIC: Production Status (`/lost-wax/production-status`)

Sudah cukup. Menampilkan per-PrintOrderLine atau per-LegacyWO dengan stage distribution. Tree-centric, cocok untuk PPIC.

### SPV: Rack Monitor (belum ada)

| Kebutuhan | Tersedia? | Gap |
|---|---|---|
| Nomor Rack | ❌ | Perlu `rack_id` di trees |
| Stage saat ini per Rack | ❌ | Derivable setelah rack_id ada |
| `rack_stage_started_at` | ❌ | Derivable dari MAX(scanned_at) per rack per stage |
| Aging rack (ETA/overdue) | ❌ | Perlu per-stage threshold config |
| Tree list per Rack | ❌ | Derivable setelah rack_id ada |
| Layer 7 split detection | ❌ | Perlu logic di query |
| `current_stage` per tree | ✅ | Sudah ada |
| `last_scan_at` per tree | ✅ | Sudah ada |
| `aging_minutes` & `aging_status` | ✅ | Sudah ada |
| Void-safe query pattern | ✅ | Sudah ada |
| `require_layer_7` per tree | ✅ | Sudah ada |

---

## 10. Database Gap

| Entitas | Field | Status | Keterangan |
|---|---|---|---|
| `lost_wax_trees` | `rack_id` | 🟡 Perlu additive | FK nullable ke rack |
| `lost_wax_trees` | `rack_assigned_at` | 🟡 Opsional | Audit kapan tree dimasukkan rack |
| `lost_wax_racks` | `rack_number` (integer) | 🟡 Perlu additive | Untuk sorting numerik |
| `config/lost_wax.php` | Per-stage aging thresholds | 🟡 Perlu additive | Saat ini flat 4-6j semua stage |
| `lost_wax_scan_events` | — | 🟢 Tidak perlu diubah | |
| `lost_wax_scan_event_voids` | — | 🟢 Tidak perlu diubah | |
| `ScanService` | — | 🟢 Tidak perlu diubah | |
| `ScanVoidService` | — | 🟢 Tidak perlu diubah | |

---

## 11. Recommended Architecture

### Rack Identity

Rack diidentifikasi oleh **nomor fisik** yang dicat. Tambahkan `rack_number` integer ke `lost_wax_racks` untuk kemudahan sorting dan query.

### Tree → Rack Relation

```sql
-- Additive migration ke lost_wax_trees:
rack_id          BIGINT UNSIGNED NULL  FK → lost_wax_racks
rack_assigned_at TIMESTAMP NULL
```

Assignment dilakukan oleh operator/SPV sebelum atau saat coating dimulai. Satu tree hanya boleh berada di satu rack pada satu waktu. Untuk MVP, cukup field di tree. Jika dibutuhkan history pemindahan, buat tabel `lost_wax_tree_rack_assignments` append-only.

### Rack Stage Aggregation (On-the-fly)

```sql
-- Current stage rack = stage mayoritas tree aktif
SELECT se.stage, COUNT(*) as tree_count
FROM lost_wax_trees t
JOIN (
  SELECT tree_id, MAX(id) as event_id
  FROM lost_wax_scan_events
  WHERE result = 'success'
    AND id NOT IN (SELECT scan_event_id FROM lost_wax_scan_event_voids)
  GROUP BY tree_id
) latest ON latest.tree_id = t.id
JOIN lost_wax_scan_events se ON se.id = latest.event_id
WHERE t.rack_id = ?
GROUP BY se.stage

-- rack_stage_started_at
SELECT MAX(se.scanned_at)
FROM lost_wax_scan_events se
JOIN lost_wax_trees t ON t.id = se.tree_id
WHERE t.rack_id = ? AND se.stage = ? AND se.result = 'success'
  AND se.id NOT IN (SELECT scan_event_id FROM lost_wax_scan_event_voids)
```

### Scan Cohorts

Tidak perlu disimpan sebagai entitas untuk MVP. Scan events individual sudah mengandung timestamp yang cukup untuk merekonstruksi cohort kapanpun.

### Layer 7 Split

Rack dengan tree mixed `require_layer_7` ditampilkan sebagai **"SPLIT"** dengan dua sub-baris:
- N tree → Layer 7 (aging Layer 7 terpisah)
- M tree → Oven-ready

---

## 12. Implementation Phases

### Phase R1: Foundation (Prerequisite)
- Migration: tambah `rack_number` integer ke `lost_wax_racks`
- Migration: tambah `rack_id` + `rack_assigned_at` ke `lost_wax_trees`
- Config: tambah per-stage aging thresholds ke `config/lost_wax.php`
- **Keputusan bisnis terlebih dahulu:** reuse `lost_wax_racks` atau tabel baru?

### Phase R2: Rack Assignment UI
- Form assignment: tree → rack (bulk per production_date atau per rangkai_execution)
- Validasi: satu tree = satu rack, rack harus aktif
- Tampilkan rack number di tree index dan tree detail

### Phase R3: Rack Monitor Backend
- `RackMonitorController` dengan query aggregasi per rack per stage
- Hitung `rack_stage_started_at`, `rack_age_minutes`, aging status
- Handle Layer 7 split detection

### Phase R4: SPV Dashboard
- View: Rack Monitor — sorted by stage, then overdue/warning dulu
- Tampilkan ETA/overdue label per rack
- Drilldown: klik rack → list tree beserta stage individual
- Semua query menggunakan void-safe pattern

---

## 13. Risks

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| 1 | Rack fisik vs digital tidak sinkron — operator lupa update assignment | SPV lihat data salah | Buat alert jika tree belum punya rack saat scan Layer 1 |
| 2 | Ambiguitas `lost_wax_racks` — tabel untuk moulding, bukan coating | Domain confusion | Klarifikasi dengan tim sebelum Phase R1 |
| 3 | Flat aging config — threshold 4-6j tidak mencerminkan Layer 5-7 | Aging status salah untuk layer atas | Perbaiki config sebelum Phase R3 |
| 4 | Layer 7 split — rack terpecah di dua stage | UI status ambiguous | Desain "SPLIT" view sejak Phase R4 |
| 5 | On-the-fly aggregation cost — query MAX per rack per page load | Dashboard lambat saat banyak rack aktif | Cache atau denormalized `rack_current_stage` jika perlu |
| 6 | Void stale rack state — jika rack state di-denormalize | Cache stale setelah void | Trigger recompute atau gunakan on-the-fly |
| 7 | Multi-code rack — satu rack berisi dua kode produksi | PPIC tidak dapat drill per kode dari rack view | Rack Monitor SPV dan Production Status PPIC tetap view terpisah |
| 8 | Legacy WO tree — perlu support rack juga | Rack Monitor tidak cover semua tree | `rack_id` nullable, tidak mandatory untuk legacy |
| 9 | Wave vs Rack confusion — nama "wave" mungkin disalahartikan | Desain salah | Dokumentasikan bahwa Wave = planning legacy, Rack = operational grouping |
| 10 | Rack assignment history — tidak ada audit trail pemindahan | Tidak dapat debug history | Pertimbangkan tabel assignment append-only dari awal |

---

## 14. Final Verdict

### **READY WITH MODIFICATIONS**

Sistem existing memiliki fondasi yang memadai:
- ✅ Scan event ledger append-only, void-safe, per-tree timestamp
- ✅ `current_stage` dan `last_scan_at` per tree sudah tersedia
- ✅ `require_layer_7` per tree sudah menangani Layer 7 exception
- ✅ Void-safe query pattern sudah terbukti di dashboard existing
- ✅ Tabel `lost_wax_racks` sudah ada (perlu keputusan domain)

Yang **harus ditambahkan** sebelum Rack Monitor dapat dibangun:
- 🟡 `rack_id` (FK nullable) di `lost_wax_trees`
- 🟡 Per-stage aging thresholds di config
- 🟡 Keputusan bisnis: reuse `lost_wax_racks` atau tabel baru?

Tidak ada **architectural change** yang dibutuhkan. Semua perubahan bersifat additive dan backward-compatible. Scan flow existing tidak perlu diubah sama sekali.
