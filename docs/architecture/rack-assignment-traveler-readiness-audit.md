# Rack Assignment & Traveler Gate Readiness Audit

**Tanggal Audit:** 2026-08-23  
**Scope:** Lost Wax — Rack Assignment + Traveler Gate  
**Status:** AUDIT SAJA — tidak ada perubahan kode

---

## 1. Executive Summary

**READY WITH MODIFICATIONS**

Infrastruktur existing sudah sangat dekat dengan target. Checkpoint utama:

| Area | Status |
|---|---|
| Tabel `lost_wax_racks` existing | ❌ Ini Moulding Rack, bukan Coating Rack |
| `lost_wax_trees` punya `rack_id` | ❌ Belum ada |
| `/lost-wax/trees` controller | ✅ Siap, tinggal tambah filter rack |
| Pagination + `withQueryString` | ✅ Sudah ada |
| Checkbox bulk print sudah ada | ✅ Sudah ada di index blade |
| Traveler A4 (Epson) | ✅ Ada, tapi tanpa Rack Gate |
| Traveler Thermal (TSPL via PrintJobService) | ✅ Ada, tapi tanpa Rack Gate |
| `print_jobs` punya `tree_id` FK | ✅ Sudah ada (migration 2026_08_22_125309) |
| Server-side Rack Gate | ❌ Belum ada |
| Partial-success pada bulk print | ❌ Belum ada (saat ini fail-fast atau all-or-nothing) |
| Authorization roles | ✅ Sudah ada: admin / ppic / spv |
| Wave vs Rack separation | ✅ Aman, wave hanya di legacy |

Diperlukan: **1 tabel baru + 1 migration additive ke trees + perubahan minimal di TreeController + config**.

---

## 2. Current Architecture

```
PrintOrderLine
  └── RangkaiWorkOrder
        └── RangkaiExecution
              └── LostWaxTree ──── (rack_id BELUM ADA)
                    ├── LostWaxScanEvent (append-only ledger)
                    │     └── LostWaxScanEventVoid
                    └── PrintJob (via tree_id FK) ← tracking thermal print
```

**`/lost-wax/trees` flow:**

```
TreeController::index
  → LostWaxTree::with([workOrder, plan, printOrderLine, ...])
  → filter: barcode, code, customer, item
  → paginate(50)->withQueryString()
  → view('lost-wax.trees.index')
      └── checkbox per row
      └── bulk print (A4 traveler + thermal)
      └── single print buttons per row
```

**Traveler flow (dua jalur):**

```
Jalur A — Epson A4:
  GET /lost-wax/trees/{tree}/traveler?ids=1,2,3&auto_print=1
  → TreeController::traveler (single) ATAU
  → traveler.blade.php mengambil sendiri dari request()->input('ids')
  → Browser auto-print via window.print()
  → TIDAK ada database record per-print (kecuali via TSPL)

Jalur B — Thermal (TSPL):
  POST /lost-wax/trees/print-thermal
  → TreeController::printThermal
  → TsplRenderer::render($tree)
  → PrintJobService::createTscJob → PrintJob::create (DB record)
  → Print agent di Windows polling PrintJob
```

---

## 3. Tree ↔ Rack Relationship

**Yang dibutuhkan:**

```
LostWaxTree
  rack_id BIGINT UNSIGNED NULL  FK → [tabel Coating Rack]
  rack_assigned_at TIMESTAMP NULL
```

**Karakteristik:**
- **Nullable** — tree boleh hidup tanpa rack
- **Mutable** — tree bisa pindah rack (NULL→17, 17→18, 18→12)
- **Current location** — bukan history, bukan immutable
- **Tidak perlu history** untuk MVP (future enhancement)

**Implikasi Traveler snapshot:**
- Traveler adalah dokumen cetak pada saat diterbitkan
- `rack_id` di tree adalah current location yang bisa berubah
- Traveler A4: render dari blade pada saat request — jika rack berubah setelah print, traveler lama tidak berubah (karena sudah jadi PDF di browser)
- Traveler Thermal: `TsplRenderer::render` dipanggil saat `createTscJob` — payload TSPL tersimpan di `print_jobs.payload_tspl` sebagai snapshot immutable
- **Kesimpulan:** Keduanya sudah bersifat snapshot secara natural, tidak perlu mekanisme tambahan

---

## 4. Existing Rack Architecture

### `lost_wax_racks` (migrasi 2026_08_09_000002)

```sql
id, code VARCHAR(50) UNIQUE, label, location, status('active'), notes, timestamps
```

**Relasi:** `LostWaxRack` → `hasMany(LostWaxMouldingInstance, 'rack_id')`

**Kesimpulan tegas:** Ini adalah **Moulding Rack** — digunakan untuk menyimpan moulding instance (cetakan), bukan tree coating. Tidak ada controller, route, atau view yang menghubungkan `lost_wax_racks` dengan `lost_wax_trees` atau scan flow.

**Tidak boleh direpurpose.** Rack moulding dan rack coating adalah entitas bisnis yang berbeda.

### Rekomendasi

Buat tabel baru: **`lost_wax_coating_racks`**

```sql
id
rack_number   SMALLINT UNSIGNED  -- nomor fisik yang dicat (1-99), UNIQUE
label         VARCHAR(100) NULL  -- keterangan tambahan opsional
status        ENUM('active','inactive') DEFAULT 'active'
notes         TEXT NULL
timestamps
```

Keuntungan tabel baru:
- Domain terpisah, tidak mencampur Moulding
- `rack_number` integer memudahkan sorting: `ORDER BY rack_number`
- Tidak merusak legacy `lost_wax_racks`

---

## 5. `/lost-wax/trees` Readiness

### Controller (`TreeController::index`)

**Filter yang sudah ada:** `barcode`, `code`, `customer`, `item`  
**Filter yang belum ada:** `rack_id` / `rack_number` / `stage`  

**Query:**
```php
LostWaxTree::with([...])
    ->paginate(50)
    ->withQueryString()
```

`withQueryString()` sudah ada — filter rack baru otomatis akan preserved saat pagination.

**Gap:** Tidak ada filter rack. Perlu ditambahkan:
```php
if ($request->filled('rack')) {
    $treesQuery->where('rack_id', $request->rack);
}
```

### Blade (`trees/index.blade.php`)

**Yang sudah ada:**
- Checkbox per row (`tree-checkbox`)
- `select-all` checkbox
- Counter `checked-count`
- `bulk-print-btn` (A4 traveler)
- `bulk-print-thermal-btn` (thermal)
- `triggerThermalPrint(ids)` function via Axios POST
- Pagination via `$trees->links()`

**Gap di UI:**
- Tidak ada kolom Rack (kolom Wave ada, Rack belum)
- Tidak ada inline Rack assignment widget
- Tidak ada bulk Rack assignment panel
- Tidak ada filter Rack

**Penting:** Struktur checkbox dan bulk action sudah matang. Menambahkan Rack assignment tidak perlu mengubah logika bulk print yang sudah ada — hanya menambahkan UI baru di samping.

### AJAX existing

Bulk thermal sudah via Axios POST ke `lost-wax.trees.print-thermal`. Endpoint ini AJAX-ready. Rack assignment akan memerlukan endpoint AJAX baru (PATCH atau POST).

### Pagination

Sudah menggunakan `paginate(50)->withQueryString()`. Aman — filter baru yang ditambahkan ke form GET akan otomatis preserved.

---

## 6. Traveler Printing Readiness

### Jalur A — Epson A4 (blade)

**Route:** `GET /lost-wax/trees/{tree}/traveler`  
**Controller:** `TreeController::traveler` (single) — blade mengambil `ids` dari `request()->input('ids')`  
**Authorization:** `authorizeTree($tree)` — PPIC scope check

**Isi blade:**
```php
// traveler.blade.php:93-101
if (request()->has('ids')) {
    $ids = explode(',', request()->input('ids'));
    $treesList = LostWaxTree::whereIn('id', $ids)->get();
} else {
    $treesList = collect([$tree]);
}
```

**MASALAH KRITIS — Rack Gate belum ada:**

Blade langsung mengambil semua tree dari ID tanpa mengecek `rack_id IS NOT NULL`. Jika user membuka traveler dengan 7 tree (4 punya rack, 3 tidak), semua 7 akan muncul di traveler — tanpa peringatan.

**MASALAH KRITIS — Authorization di blade tidak konsisten:**

`authorizeTree($tree)` dipanggil di controller untuk tree pertama saja. Tree tambahan dari `?ids=...` diambil langsung di blade tanpa authorization check. Ini adalah **security gap existing** (unrelated to rack, but noted).

**Tidak ada database record** untuk cetak A4 — tidak ada history siapa yang mencetak dan kapan.

### Jalur B — Thermal TSPL

**Route:** `POST /lost-wax/trees/print-thermal`  
**Controller:** `TreeController::printThermal`  
**Flow:**
1. Loop per ID, `authorizeTree($tree)` dipanggil per tree ✅
2. `TsplRenderer::render($tree)` — generate payload TSPL
3. `PrintJobService::createTscJob` → `PrintJob::create`
4. **Database record created** per tree: `print_jobs.tree_id` FK

**Transaksional:** Setiap tree dibuat PrintJob secara independen dalam loop. Jika tree ke-3 gagal (misalnya `authorizeTree` abort), tree 1 dan 2 sudah masuk DB. Ini adalah **partial-state risk**.

**TSPL Rack Placeholder (ditemukan!):**  
Di `TsplRenderer::render`, baris 81:
```php
$rackPlaceholder = $this->sanitize('_____________');
// ...
$cmds[] = 'TEXT 380,332,"'.self::FONT_NORMAL.'",0,1,1,"KODE RAK : '.$rackPlaceholder.'"';
```

**Ini sangat signifikan:** Saat ini label thermal sudah memiliki field `KODE RAK` — tapi diisi dengan underscore placeholder. Setelah `rack_id` tersedia di tree, renderer ini tinggal mengganti placeholder dengan nilai aktual.

### Rack Gate — Di mana harus ditempatkan?

**Untuk Thermal (Jalur B):**  
Di `TreeController::printThermal`, sebelum loop create PrintJob:

```php
// BEFORE (loop dimulai):
$errors = [];
$validTrees = [];
foreach ($trees as $tree) {
    if (!$tree->rack_id) {
        $errors[] = "Tree {$tree->barcode}: Nomor Rack belum diisi.";
    } else {
        $validTrees[] = $tree;
    }
}
// Lanjutkan hanya dengan $validTrees
// Return partial success + error list
```

**Untuk A4 Traveler (Jalur A):**  
Filter di blade sebelum render, atau di controller sebelum return view. Server-side validation wajib — tidak boleh hanya di JavaScript.

**Partial-success behavior:**  
Saat ini `printThermal` adalah "all or nothing" secara implicit (fail-fast jika ada yang tidak ditemukan). Untuk partial-success, harus diubah menjadi:
```json
{
    "success": true,
    "printed_count": 4,
    "skipped_count": 3,
    "printed": [...],
    "skipped": [
        {"barcode": "...", "reason": "Nomor Rack belum diisi."}
    ]
}
```

---

## 7. Authorization

### Roles dan permissions existing

| Role | Permissions | Keterangan |
|---|---|---|
| `admin` | `access_planning` + `access_execution` | Full access |
| `ppic` | `access_planning` + `access_execution` | PPIC dengan product_scope filter |
| `spv` | `access_execution` ONLY | Operator/SPV lapisan |

**Siapa yang dapat mengakses `/lost-wax/trees`?**  
Route berada di `middleware(['permission:access_execution'])` group. Semua role (admin, ppic, spv) dapat mengakses.

**Siapa yang seharusnya boleh assign Rack?**  
Berdasarkan business context (SPV Lapisan yang menempatkan tree ke rack fisik), **role `spv` seharusnya bisa assign rack**. Role `admin` dan `ppic` juga bisa.

**Rekomendasi:** Tidak perlu permission baru. Rack assignment mengikuti `access_execution` — semua yang bisa membuka `/lost-wax/trees` bisa assign rack.

**Catatan PPIC product_scope:**  
PPIC dengan `product_scope` hanya bisa lihat tree dari scope mereka. Rack assignment untuk PPIC harus tetap dalam scope — `authorizeTree($tree)` sudah menangani ini.

**`authorizeTree` existing:**
```php
protected function authorizeTree(LostWaxTree $tree)
{
    $scope = auth()->user()->product_scope;
    if (auth()->user()->hasRole('ppic') && $scope) {
        $plan = $tree->printOrderLine?->productionPlan;
        if (!$plan || $plan->product_scope !== $scope) {
            abort(403, 'Unauthorized.');
        }
    }
}
```

Ini cukup untuk rack assignment — tinggal memanggil `authorizeTree` sebelum update `rack_id`.

---

## 8. Data Integrity Risks

### 1. Traveler A4 bypass authorization
Tree tambahan dari `?ids=...` diambil langsung di blade tanpa memanggil `authorizeTree`. Ini adalah existing gap, bukan diperkenalkan oleh rack feature. Namun jika rack gate ditambahkan, harus diimplementasikan di blade atau di controller dedicated endpoint, bukan hanya di JavaScript.

### 2. Thermal print partial-state
Loop di `printThermal` tidak dalam satu transaksi. Jika tree ke-5 dari 7 gagal karena exception, 4 PrintJob sudah tersimpan. Untuk partial-success, ini justru diinginkan — tapi response harus mencerminkan kondisi sebenarnya.

### 3. Race condition rack assignment
Jika dua SPV mencoba assign tree yang sama ke dua rack berbeda secara bersamaan:
- Terakhir yang `UPDATE` menang (last-write-wins)
- Tidak ada conflict detection

Ini acceptable untuk MVP (35 rack, ~30 tree per rack, bukan high-concurrency scenario).

### 4. Rack assignment saat tree sudah scan
Tree yang sudah melewati Layer 1 tetap boleh dipindah rack secara bisnis (`rack_id` adalah current location). Scan events tidak terpengaruh karena tidak menyimpan `rack_id`. Ini aman.

### 5. Rack delete cascade
Jika `lost_wax_coating_racks` dihapus (misalnya rack retired), FK `nullOnDelete` di trees akan set `rack_id = NULL`. Trees kembali ke state "belum ada rack". Ini behavior yang diinginkan.

### 6. Void compatibility
`ScanVoidService` tidak menyentuh `rack_id`. Void hanya mengubah `current_stage` dan `last_scan_at`. Aman sepenuhnya.

---

## 9. Legacy Compatibility

### Wave (`wave_number`)

- `wave_number` ada di `lost_wax_work_order_plans`, bukan di `lost_wax_trees`
- Tree menyimpan `work_order_plan_id` (FK nullable ke plan)
- Trees dari new flow (`lost_wax_print_order_line_id`) **tidak punya** plan/wave sama sekali
- Di blade `trees/index.blade.php` baris 126: `{{ optional($tree->plan)->wave_number ? 'Wave ...' : '-' }}`
- Penambahan `rack_id` tidak mengubah logika wave sama sekali

**Kesimpulan:** Wave dan Rack adalah kolom yang sepenuhnya orthogonal. Tidak ada risiko konflik.

### Legacy Work Order trees

Trees dari legacy flow (`work_order_id`, tanpa `lost_wax_print_order_line_id`) juga muncul di `/lost-wax/trees`. Mereka perlu bisa di-assign rack juga. `rack_id` nullable memastikan compatibility — tree legacy yang belum di-assign rack tetap valid.

### Existing `lost_wax_racks`

Tidak ada perubahan pada tabel ini. Relasi ke `LostWaxMouldingInstance` tetap utuh.

---

## 10. Recommended Data Model

### Tabel baru: `lost_wax_coating_racks`

```sql
CREATE TABLE lost_wax_coating_racks (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rack_number   SMALLINT UNSIGNED NOT NULL,
    label         VARCHAR(100) NULL,
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    notes         TEXT NULL,
    created_at    TIMESTAMP NULL,
    updated_at    TIMESTAMP NULL,

    UNIQUE KEY uq_coating_rack_number (rack_number),
    INDEX idx_status (status)
);
```

**Alasan `SMALLINT UNSIGNED`:** Nomor rack 1-65535, cukup untuk 35 rack fisik. Lebih efisien dari BIGINT.

**Pre-populate:** Isi 35 baris (rack_number 1-35 atau sesuai nomor aktual) via seeder, bukan migration.

### Additive migration ke `lost_wax_trees`

```sql
ALTER TABLE lost_wax_trees
    ADD COLUMN rack_id BIGINT UNSIGNED NULL
        AFTER rangkai_execution_id,
    ADD COLUMN rack_assigned_at TIMESTAMP NULL
        AFTER rack_id,
    ADD CONSTRAINT fk_lw_tree_coating_rack
        FOREIGN KEY (rack_id)
        REFERENCES lost_wax_coating_racks(id)
        ON DELETE SET NULL,
    ADD INDEX idx_lw_tree_rack_id (rack_id);
```

**Behavior:**
- `rack_id NULL` = tree belum di-assign rack (boleh, valid)
- `rack_id NOT NULL` = tree berada di rack ini saat ini
- `ON DELETE SET NULL` = jika rack dihapus, tree kembali ke unassigned
- `rack_assigned_at` = audit kapan terakhir di-assign (bukan history)

### Perubahan di `TsplRenderer` (setelah migration)

Baris 29-30 saat ini:
```php
$rackPlaceholder = $this->sanitize('_____________');
```

Setelah migration, ganti dengan:
```php
$rackNumber = $tree->coatingRack?->rack_number 
    ? 'RAK-'.str_pad($tree->coatingRack->rack_number, 2, '0', STR_PAD_LEFT)
    : '(Belum diisi)';
```

---

## 11. Recommended Workflow

```
Print Order selesai
    → Rangkai WO dibuat
    → RangkaiExecution dilakukan
    → LostWaxTree dibuat (rack_id = NULL)
    → Tree muncul di /lost-wax/trees
          ↓
    SPV/Admin pilih tree dari tabel
    → Inline: isi nomor rack → [Save] → PATCH /trees/{tree}/rack
    → atau Bulk: centang beberapa tree → pilih Rack → [Tetapkan Rack]
          ↓
    Tree.rack_id = 17, rack_assigned_at = now()
          ↓
    TRAVELER GATE:
    User pilih tree untuk cetak Traveler
    → Server check: tree.rack_id IS NOT NULL?
          ├── YES → Proses print (thermal / A4)
          └── NO  → Skip, tambahkan ke daftar error
    → Return: partial success + daftar yang gagal + alasan
          ↓
    Traveler dicetak dengan KODE RAK: 17
          ↓
    Tree masuk scan Layer 1 → Layer 2 → ... → Oven
    (rack_id tidak berubah kecuali SPV memindahkan)
```

---

## 12. Implementation Boundaries

### MUST HAVE

- **Migration:** Tabel `lost_wax_coating_racks` baru
- **Migration:** Kolom `rack_id` + `rack_assigned_at` di `lost_wax_trees`
- **Seeder:** Pre-populate 35 rack
- **Model:** `LostWaxCoatingRack` dengan `hasMany(LostWaxTree)`
- **Model:** `LostWaxTree` tambah `belongsTo(LostWaxCoatingRack, 'rack_id')` dan `rack_id` ke `$fillable`
- **Controller:** Route + method `assignRack(Request, LostWaxTree)` — inline single
- **Controller:** Route + method `bulkAssignRack(Request)` — bulk
- **Controller:** Server-side Rack Gate di `printThermal` (partial success)
- **Blade:** Kolom Rack di `trees/index.blade.php`
- **Blade:** Inline Rack assignment widget
- **Blade:** Bulk Rack assignment panel
- **Blade:** Filter Rack di form filter
- **Blade:** Rack Gate di `traveler.blade.php` (filter tree tanpa rack, tampilkan warning)
- **TSPL:** `TsplRenderer` gunakan `rack_number` aktual, bukan placeholder
- **Config:** Per-stage aging thresholds (gap dari audit sebelumnya)

### SHOULD HAVE

- Rack assignment gate di Traveler A4 — warning di UI jika ada tree tanpa rack dalam batch
- Partial-success response di `printThermal` (saat ini all-or-nothing)
- Filter stage (`current_stage`) di `/lost-wax/trees`
- Rack capacity warning (soft warning jika rack sudah > 30 tree, bukan hard limit)
- Fix authorization gap di `traveler.blade.php` (tree tambahan dari `?ids` tidak di-authorize)

### FUTURE

- History pemindahan rack (tabel `lost_wax_tree_rack_assignments` append-only)
- Rack Monitor dashboard untuk SPV (phase terpisah — sudah diaudit sebelumnya)
- Configurable capacity per rack
- Rack status tracking (rack sedang di mana, apakah sedang dalam proses)

---

## 13. Test Plan

| # | Test Case | Assertion |
|---|---|---|
| 1 | **Single rack assign** — PATCH `/trees/{tree}/rack` dengan rack valid | tree.rack_id diupdate, rack_assigned_at terisi |
| 2 | **Single rack assign** — rack tidak ada / tidak aktif | 422 Unprocessable / 404 |
| 3 | **Bulk assign** — 5 tree ke Rack 17 | Semua 5 tree.rack_id = Rack17.id |
| 4 | **Bulk assign** — campuran valid dan invalid tree ID | Valid diupdate, invalid dilewati dengan error |
| 5 | **Move rack** — tree di Rack 17, assign ke Rack 18 | rack_id berubah ke 18, scan events tidak berubah |
| 6 | **rack_id NULL** — cetak thermal | Response: skipped, message "Nomor Rack belum diisi" |
| 7 | **Print with rack** — thermal | PrintJob dibuat, payload_tspl mengandung nomor rack |
| 8 | **Print without rack** — Epson A4 | Warning di traveler blade, tree tanpa rack tidak terrender (atau ditandai) |
| 9 | **Mixed batch** — 7 tree, 4 punya rack, 3 tidak | printed_count=4, skipped_count=3, alasan di response |
| 10 | **Authorization** — SPV assign rack milik PPIC scope lain | 403 Unauthorized (authorizeTree) |
| 11 | **Authorization** — PPIC assign rack tree di luar scope | 403 Unauthorized |
| 12 | **Legacy Wave** — tree dari legacy WO (punya wave) assign rack | rack_id terisi, wave_number tidak berubah |
| 13 | **Concurrent assignment** — dua request assign tree ke rack berbeda simultan | Last-write-wins, tidak ada data corruption |
| 14 | **Pagination filter preservation** — filter rack + pindah halaman | `?rack=17&page=2` menggunakan withQueryString |
| 15 | **Rack delete** — rack 17 dihapus | tree.rack_id menjadi NULL (ON DELETE SET NULL) |
| 16 | **TSPL snapshot** — assign rack 17, cetak, pindah ke rack 18 | PrintJob lama tetap mengandung "RAK-17" di payload_tspl |

---

## 14. Final Recommendation

### Apa yang sudah siap

- `LostWaxTree` model — clean, siap menerima `rack_id` FK
- `TreeController::index` — pagination, `withQueryString`, filter pattern sudah ada, tinggal tambah rack filter
- Checkbox dan bulk print UI — sudah matang, tinggal tambahkan Rack column dan assignment widget
- `PrintJobService` + `PrintJob.tree_id` — tracking thermal print per-tree sudah ada
- `TsplRenderer` — field `KODE RAK` sudah ada (dengan placeholder), tinggal isi dengan data aktual
- Authorization `authorizeTree()` — cukup untuk rack assignment, tidak perlu permission baru
- Phase 5 Void — aman, tidak terdampak

### Apa yang harus diubah

1. **Buat tabel `lost_wax_coating_racks`** — jangan reuse `lost_wax_racks` (itu Moulding Rack)
2. **Tambah `rack_id` + `rack_assigned_at` ke `lost_wax_trees`** via additive migration
3. **Update `TsplRenderer`** — gunakan `rack_number` aktual, bukan placeholder underscore
4. **Update `TreeController::printThermal`** — implementasikan partial-success + Rack Gate
5. **Update `traveler.blade.php`** — filter tree tanpa rack, tampilkan warning / skip
6. **Update `trees/index.blade.php`** — tambah kolom Rack, inline widget, bulk assignment panel, filter rack

### Apa yang jangan disentuh

- `lost_wax_racks` dan `LostWaxRack` — biarkan untuk Moulding
- `wave_number` dan `LostWaxWorkOrderPlan` — tidak ada hubungan dengan rack
- `ScanService` dan `ScanVoidService` — tidak perlu diubah
- `lost_wax_scan_events` — tidak perlu field rack (derivable dari tree)
- Struktur paginate/filter existing di `TreeController::index` — hanya tambah, jangan ubah

### Migration yang benar-benar diperlukan

```
[1] create_lost_wax_coating_racks_table
    → tabel master rack fisik (35 baris)

[2] add_rack_to_lost_wax_trees
    → rack_id BIGINT UNSIGNED NULL FK → lost_wax_coating_racks (ON DELETE SET NULL)
    → rack_assigned_at TIMESTAMP NULL
    → INDEX(rack_id)
```

Hanya dua migration. Semuanya additive. Tidak ada data yang diubah.
