# Lost Wax Production Status — Legacy & XLSX Audit

**Date:** 2026-08-24  
**Scope:** `/lost-wax/production-status` — read-only audit, no code changes made  
**Test Result:** `composer test --filter=ProductionStatusTest` → **33 passed, 0 failed**

---

## Executive Verdict

| Area | Status |
|---|---|
| Core aggregation (Tree → Stage) | ✅ WORKING CORRECTLY |
| Void safety | ✅ WORKING CORRECTLY (via `current_stage`) |
| Legacy WIP (moulding/assembly) on CTK/RGKI columns | ⚠️ LEGACY BUT MISLEADING |
| N+1 query: `$wo->trees()->count()` | 🔴 HIGH RISK N+1 BUG |
| Dual-path (legacy WO + new Plan) filter ordering | ⚠️ MEDIUM RISK |
| ACTIVE/COMPLETED definition | ⚠️ MEDIUM RISK (partial completion edge case) |
| CSV export: `filter` default mismatch | ⚠️ MEDIUM RISK |
| CSV header: R spacer columns included | ⚠️ LOW RISK / COSMETIC |
| XLSX library: **not installed** | 🔴 BLOCKS FEATURE |
| Performance: N+1 + full-table scan | 🔴 HIGH RISK |
| Offline compatibility | ✅ FULLY SERVER-SIDE (safe) |

---

## Current Architecture

### Dependency Map

```
GET /lost-wax/production-status
→ ProductionStatusController::index()
  → getAggregatedRows()
    → LostWaxWorkOrder (legacy path)
      → LostWaxItemReference (eager)
      → LostWaxWorkOrderPlan (eager)
      → LostWaxWorkOrderWip (lazy! N+1 via accessors)
      → LostWaxTree (one batch query per all WOs — ok)
    → ProductionPlan (new flow path)
      → LostWaxPrintOrderLine (eager)
      → LostWaxTree (one query per plan — potential N+1)
  → View: lost-wax/production-status/index.blade.php
    → Inline JS (fetch → trees endpoint)
    → Print CSS (A4 landscape, no-nav)
    → Export link → exportCsv()

GET /lost-wax/production-status/export
→ ProductionStatusController::exportCsv()
  → getAggregatedRows() [same method]
  → stream CSV via fputcsv

GET /lost-wax/production-status/trees
→ ProductionStatusController::trees()
  → LostWaxTree (eager: workOrder / printOrderLine)
  → LostWaxScanEvent (void-safe: whereDoesntHave('void'))
  → JSON response (modal detail)
```

### Files Involved

| Type | File |
|---|---|
| Route | `routes/web.php` lines 167–169 |
| Controller | [`ProductionStatusController.php`](file:///c:/laragon/www/kanban-ppic/app/Http/Controllers/LostWax/ProductionStatusController.php) |
| View | [`production-status/index.blade.php`](file:///c:/laragon/www/kanban-ppic/resources/views/lost-wax/production-status/index.blade.php) |
| Model (legacy) | [`LostWaxWorkOrder.php`](file:///c:/laragon/www/kanban-ppic/app/Models/LostWaxWorkOrder.php) |
| Model (legacy) | [`LostWaxWorkOrderWip.php`](file:///c:/laragon/www/kanban-ppic/app/Models/LostWaxWorkOrderWip.php) |
| Model (current) | [`LostWaxTree.php`](file:///c:/laragon/www/kanban-ppic/app/Models/LostWaxTree.php) |
| Model (current) | `ProductionPlan`, `LostWaxPrintOrderLine`, `LostWaxScanEvent` |
| Config | [`config/lost_wax.php`](file:///c:/laragon/www/kanban-ppic/config/lost_wax.php) |
| Tests | [`ProductionStatusTest.php`](file:///c:/laragon/www/kanban-ppic/tests/Feature/LostWax/ProductionStatusTest.php) |

No standalone Service class — all logic is inline in the controller.

---

## Source of Truth Audit

| Display Field | DB Source | Query / Logic | Current SoT | Risk |
|---|---|---|---|---|
| **PO** (planned_qty) | `lost_wax_work_orders.po_quantity` (legacy) / `production_plans.qty_planned` (new) | Direct field read | Tree count not involved | ✅ LOW |
| **Plan** (scheduled_qty) | `lost_wax_work_order_wips` stage='moulding' sum (legacy) / `lost_wax_print_order_lines.qty_ordered` (new) | Accessor via eager `plans`, but WIP is **lazy** | WIP table (legacy) vs Print Line (new) | ⚠️ MEDIUM – legacy uses WIP table |
| **Tot Lap** | `lost_wax_trees.quantity` SUM where `current_stage` in layer_1–7 | `SUM(quantity) GROUP BY current_stage` | `LostWaxTree.current_stage` ✅ | ✅ LOW |
| **Tot Rsk** | `cetak_defect + rangkai_defect` (legacy) / `print_line.qty_actual_defect + rangkai_defect` (new) | Computed from WIP/print data | Mixed legacy/new | ⚠️ MEDIUM |
| **CTK** | Moulding output from `lost_wax_work_order_wips` | Via `getMouldingOutputQuantityAttribute()` accessor (lazy!) | Legacy WIP table | ⚠️ MEDIUM / LEGACY |
| **RGKI** | Assembly output from `lost_wax_work_order_wips` | Via `getAssemblyOutputQuantityAttribute()` accessor (lazy!) | Legacy WIP table | ⚠️ MEDIUM / LEGACY |
| **L1–L7** | `SUM(lost_wax_trees.quantity)` where `current_stage = 'layer_N'` | Batch query, no per-row issues | `LostWaxTree.current_stage` ✅ | ✅ LOW |
| **Oven** | `SUM(lost_wax_trees.quantity)` where `current_stage = 'oven'` | Same batch query | `LostWaxTree.current_stage` ✅ | ✅ LOW |
| **Status** | Derived: `ovenQty === totalTreeQty` → COMPLETED, else ACTIVE | Pure PHP computation from tree quantities | `LostWaxTree.current_stage` ✅ | ⚠️ MEDIUM (edge case) |
| **tree_count** (internal) | `$wo->trees()->count()` | **Lazy dynamic relationship call — N+1!** | — | 🔴 HIGH N+1 |

---

## Legacy Logic Findings

### Finding 1 — Dual Data Path (LEGACY BUT SAFE)
The controller has two separate data fetching paths that are **explicitly and correctly separated**:
- **Legacy path**: `LostWaxWorkOrder` → uses `$wo->moulding_output_quantity` / `$wo->assembly_output_quantity` (from `lost_wax_work_order_wips`)
- **New path**: `ProductionPlan` → uses `qty_ordered` / `qty_actual_good` from `lost_wax_print_order_lines`

Stage aggregation (`layer_1` through `oven`) for **both paths** correctly reads from `lost_wax_trees.current_stage`. This is architecturally sound — only the CTK/RGKI upstream values differ.

**Wave confusion risk: NONE.** `wave_number` appears only in `LostWaxWorkOrderPlan` (legacy planning batch), and is **never used** by `ProductionStatusController`. The Tree aggregation uses `work_order_id`, not wave.

### Finding 2 — CTK/RGKI Columns are Conditionally Hidden (LEGACY BUT DESIGNED)
CTK (`ctk_display`) and RGKI (`rgki_display`) are **intentionally set to 0 and hidden** once Trees exist:
- `ctk_display` shows moulding good qty only if no trees exist yet *or* if tree qty < moulding qty
- `rgki_display` shows assembly qty only if no trees have been scanned yet

This is a display-flow design, not a bug. It reads as: "once rangkai is complete and coating has started, hide CTK/RGKI to reduce noise." However, it is **undocumented** and counterintuitive to new users.

### Finding 3 — Legacy CTK/RGKI Data From WIP Table (MEDIUM RISK)
For legacy WOs, `moulding_output_quantity` and `assembly_output_quantity` come from the `lost_wax_work_order_wips` table. If this table was never populated for a given WO (i.e., WO was entered directly into the new flow before WIP was tracked), CTK and RGKI will display as 0 or `-` even if production has occurred. This is **legacy-safe** but potentially misleading for older WOs.

### Finding 4 — `$wo->trees()->count()` N+1 BUG (HIGH RISK) 
[Line 316 in controller](file:///c:/laragon/www/kanban-ppic/app/Http/Controllers/LostWax/ProductionStatusController.php#L316):
```php
'tree_count' => $wo->trees()->count(),
```
This calls `->trees()->count()` dynamically **inside the foreach loop** for each WO. With N legacy work orders, this issues N additional SQL queries. The result is assigned to `tree_count` in the row array, but `tree_count` is **not rendered anywhere in the Blade view or CSV export**. This is a silent N+1 doing wasted work.

### Finding 5 — New Flow: N+1 on planTrees (MEDIUM RISK)
[Lines 378 in controller](file:///c:/laragon/www/kanban-ppic/app/Http/Controllers/LostWax/ProductionStatusController.php#L378):
```php
$planTrees = LostWaxTree::whereIn('lost_wax_print_order_line_id', $lineIds)->get();
```
This is inside a `foreach ($plans as $plan)` loop. Each plan runs its own query. If there are N plans with trees, this becomes N extra queries. The legacy path avoids this by batching all WO tree stats into a single grouped query upfront. The new flow does **not** batch, making it an O(N) query pattern.

---

## Stage Aggregation Audit

### Stage Key Mapping (CORRECT)
The code uses `snake_case` keys `layer_1`…`layer_7` and `oven`, matching `config/lost_wax.stages`. There is no mismatch between the config and the aggregation keys.

| Config key | Controller key | Blade label | Status |
|---|---|---|---|
| `layer_1` | `layer_1` | L1 | ✅ Match |
| `layer_2` | `layer_2` | L2 | ✅ Match |
| `layer_3` | `layer_3` | L3 | ✅ Match |
| `layer_4` | `layer_4` | L4 | ✅ Match |
| `layer_5` | `layer_5` | L5 | ✅ Match |
| `layer_6` | `layer_6` | L6 | ✅ Match |
| `layer_7` | `layer_7` | L7 | ✅ Match |
| `oven` | `oven_qty` | Oven | ✅ Match |
| `null` (before any scan) | `sebelum_scan` | (not in table) | ✅ Handled via COALESCE |

### Double-Counting Risk (NONE)
The legacy path uses a single `GROUP BY work_order_id, current_stage` query to pre-aggregate all tree quantities. The new path iterates trees in memory. In both cases, **each tree is counted exactly once** based on its current `current_stage` value. No join multiplication, no duplicate tree rows are possible.

---

## Quantity Semantics

The table columns use `pcs` quantities (from `lost_wax_trees.quantity`), not tree counts. This is correct and consistent.

| Column | Semantic | Unit |
|---|---|---|
| PO | PO quantity from WO or Plan | pcs |
| Plan | Moulding plan / print order qty | pcs |
| Tot Lap | SUM of pcs currently in any layer | pcs |
| Tot Rsk | Total defect pcs (cetak + rangkai) | pcs |
| CTK | Moulding output pcs (conditionally shown) | pcs |
| R (after CTK) | Moulding defect pcs | pcs |
| RGKI | Assembly/rangkai output pcs (conditionally shown) | pcs |
| R (after RGKI) | Rangkai defect pcs | pcs |
| L1–L7 | SUM of pcs whose tree is at that layer | pcs |
| R (after each Ln) | Always `-`, placeholder column | N/A |
| Oven | SUM of pcs whose tree is at oven | pcs |

> [!NOTE]
> The `R` spacer columns after L1–L7 in both web table and CSV are always `-`. They were presumably intended to hold layer-specific defect counts but are not populated. This is a **cosmetic legacy placeholder** — not a bug, but potentially confusing.

---

## Void Safety

The **main aggregation table** (L1–L7, Oven) reads from `lost_wax_trees.current_stage`. The `current_stage` on the tree record is **authoritatively managed by ScanService and ScanVoidService**:

- `ScanService::scan()` writes `current_stage = $nextStage` on the tree atomically.
- `ScanVoidService` reconstructs the tree's stage from remaining non-voided events and writes the correct `current_stage` back to the tree.

Therefore: **Production Status inherits void safety for free** — it never reads scan events directly for aggregation. After a void, `current_stage` is corrected by `ScanVoidService`, and the next page load reflects the correct stage.

The **detail modal** (`trees` endpoint) also correctly filters scan events with `whereDoesntHave('void')` before reading aging data.

**Void safety verdict: WORKING CORRECTLY. No risk of double-counting voided stages.**

---

## Active/Completed/All Logic

### Definition
- **ACTIVE**: Trees exist but not all of them are at `oven` stage, OR no trees exist yet (for legacy WOs, falls back to `strtoupper($wo->status)`)
- **COMPLETED**: `$ovenQty > 0 && $ovenQty === $totalTreeQty` — all tree quantity is in oven
- **ALL**: No filter applied

### Edge Case Risk (MEDIUM)
The COMPLETED condition `$ovenQty === $totalTreeQty` uses strict integer equality. Consider this scenario:
- Tree A (20 pcs) → Oven ✅  
- Tree B (15 pcs) → Layer 5  

→ `ovenQty = 20`, `totalTreeQty = 35` → Status = ACTIVE ✅ Correct.

Now if Tree B is corrected to 0 pcs (hypothetical quantity correction): `ovenQty = 20`, `totalTreeQty = 20` → COMPLETED even though Tree B is still physically at Layer 5. This is an extreme edge case and unlikely in normal operation.

### Legacy WO without trees:
If a legacy WO has no trees at all, status falls back to `strtoupper($wo->status)`. If `$wo->status = 'active'`, it shows ACTIVE. This is consistent but means legacy WOs without trees are always ACTIVE regardless of whether they were actually completed physically.

---

## Filter Audit

### Filters Applied BEFORE Aggregation (Correct)
- `search`, `customer`, `po_number`, `aisi` — applied directly to the WO/Plan queries before fetching rows.
- RBAC scope — applied before fetching. ✅

### Filter Applied AFTER Aggregation (Design Choice)
- `active` / `completed` / `all` — applied as a PHP `array_filter()` on the already-aggregated `$rows` array.

> [!WARNING]
> This is architecturally correct but means **all rows are fetched and computed before filtering by status**. For large datasets this wastes computation. This is a **performance risk, not a correctness bug**.

### exportCsv() Filter Default Mismatch (MEDIUM RISK)
In `index()`, the default filter is `'active'`. In `exportCsv()`, the default filter is `'all'`.

```php
// index():      $filter = $request->input('filter', 'active');
// exportCsv():  $filter = $request->input('filter', 'all');
```

The export link passes `request()->query()` which includes the current `filter` parameter, so in normal use this mismatch is harmless. However, if the export URL is called directly without a `filter` parameter (e.g., from an API client or automation), the export will return ALL rows while the page would show ACTIVE rows. This is a subtle **semantic inconsistency**.

---

## Print Audit

- Print uses `window.print()` — no separate route, controller, or query.
- CSS `@media print` hides `.production-status-web` and shows `.production-status-print`.
- The print section renders the same `$rows` variable already loaded by the page request.
- Print is **identical in data** to the web view for the current filter/search state.
- Print layout: A4 landscape, 27 columns, `colgroup` defined.
- **No broken routes or stale data risk.** ✅

---

## CSV Export Audit

| Property | Value |
|---|---|
| Route | `GET /lost-wax/production-status/export` (named: `lost-wax.production-status.export`) |
| Method | `exportCsv()` |
| Query | Same `getAggregatedRows()` as the main page |
| Content-Type | `text/csv; charset=UTF-8` |
| Filename | `lost-wax-production-status-{YYYYmmdd-His}.csv` |
| BOM | **None** — risk of Excel garbling UTF-8 characters (product names, customer names) |
| Delimiter | Comma (default `fputcsv`) |
| Pagination | None — exports all rows matching current filter |
| Encoding | UTF-8 without BOM |

### CSV Column Layout
```
Kode Cust | Product Name | AISI | PO Qty | Plan Qty | Total Lap. | Total Rusak |
Cetak | R | Rangkai | R |
Lap.1 | R | Lap.2 | R | Lap.3 | R | Lap.4 | R | Lap.5 | R | Lap.6 | R | Lap.7 | R |
Oven | Status
```
(27 columns total — matches web table column count)

> [!WARNING]
> The `R` columns after each `Lap.N` are always written as literal `-` string in the CSV. This wastes columns and reduces machine-readability of the export. For XLSX, these should be removed or merged.

> [!CAUTION]
> **No UTF-8 BOM** in the CSV. On Windows, Excel opens CSV without BOM and may display Indonesian characters (if any) incorrectly. This should be fixed in the XLSX conversion.

---

## XLSX Readiness

### Library Status
**No XLSX library is installed.** `composer.json` contains only:
- `laravel/framework ^12.0`
- `laravel/tinker ^2.10.1`
- `picqer/php-barcode-generator`
- `spatie/laravel-permission ^8.3`

Neither `phpoffice/phpspreadsheet` nor `maatwebsite/excel` is present.

### Recommended Library
**`phpoffice/phpspreadsheet`** — recommended over `maatwebsite/excel` for this project because:
1. Direct dependency, no hidden abstraction layer
2. Laravel 12 compatible without additional config
3. Better memory control for streaming large exports
4. No extra service provider needed
5. `maatwebsite/excel` wraps PhpSpreadsheet anyway, adding overhead

Install command (pending approval):
```bash
composer require phpoffice/phpspreadsheet
```

---

## Offline Compatibility

The current CSV export is **100% server-side** — no CDN or browser-side library involved.  
XLSX via `phpoffice/phpspreadsheet` would also be entirely server-side PHP.  
**Offline compatibility: SAFE for both current CSV and proposed XLSX.**

---

## Performance Audit

| Issue | Location | Risk |
|---|---|---|
| N+1: `$wo->trees()->count()` per WO in foreach | Controller line 316 | 🔴 HIGH — useless query, result never rendered |
| N+1: `$wo->wipEntries` accessed via accessor | Accessors: `getMouldingOutputQuantityAttribute()` | 🔴 HIGH — WIP not eager loaded, fires per WO |
| N+1: `$wo->plans` accessed via accessor | `getPlannedQuantityAttribute()` | ⚠️ MEDIUM — `plans` is eager loaded (`with(['plans'])`) so this is safe |
| N+1: new flow `planTrees` query per plan | Controller line 378 in `foreach ($plans)` | ⚠️ MEDIUM — 1 query per plan |
| Full tree table scan | `LostWaxTree::whereIn(work_order_id, ...)` | ⚠️ MEDIUM — acceptable if indexed |
| `$planQuery->has('printOrderLines')` with eager | Loads all lines + trees in memory | ⚠️ MEDIUM for large datasets |
| Status filter after full aggregation | `array_filter($rows, ...)` | ⚠️ LOW–MEDIUM |

**Estimated worst case:** 100 legacy WOs → 100 extra queries for `trees()->count()` + 100 extra queries for `wipEntries`. This is a 200-query overhead on top of the base queries.

---

## UI/UX Legacy Findings

| Finding | Severity | Notes |
|---|---|---|
| `R` columns after each layer column always show `-` | LOW | Placeholder from early design, never populated. Confusing. |
| `CTK` and `RGKI` abbreviations — not explained anywhere in UI | MEDIUM | Users unfamiliar with the abbreviations may not understand what they mean. No tooltip. |
| `Tot Lap` / `Tot Rsk` are abbreviated without legend | MEDIUM | Especially confusing for new operators. |
| Status badge shows `SELESAI` (Indonesian) but filter tab shows `COMPLETED` (English) | LOW | Inconsistent language mixing. |
| Table has 27 columns — horizontal scroll required on most screens | HIGH (UX) | Extremely dense for a small screen factory environment. |
| The 8 `R` columns in CSV export (always `-`) make the export confusing for analysis | MEDIUM | Machine-readability suffers. |
| No link from Production Status row directly to `/lost-wax/trees` filtered by that WO/Plan | MEDIUM | Detail modal exists (JS fetch) but no persistent link to Trees page. |
| Print header hardcodes company name `PT. PERONI KARYA SENTRA` | LOW | Not configurable, not a bug but not good practice. |

---

## Missing Links

| Expected Link | Current State | Risk |
|---|---|---|
| Row → `/lost-wax/trees?et_code=...` | **No direct link** — only JS modal via `et-detail-link` | MEDIUM — modal is read-only, cannot take action from it |
| Modal → `/lost-wax/trees/{id}` tree detail | **Not present** in modal HTML | MEDIUM |
| Modal → `/lost-wax/rack-monitor` | **Not present** | LOW |
| CSV export link → XLSX | Does not exist yet | LOW (planned) |

The modal detail is loaded via AJAX to `production-status.trees`, which returns JSON rendered inline. No broken routes were found — all named routes resolve correctly.

---

## Cross-Page Consistency

| Concern | Verdict |
|---|---|
| `current_stage` semantics vs RackMonitor | ✅ CONSISTENT — both read `lost_wax_trees.current_stage` |
| Stage key naming (`layer_1`, `oven`) | ✅ CONSISTENT — same keys across Production Status, Rack Monitor, Scan Service |
| "ACTIVE" meaning | ⚠️ SLIGHTLY DIFFERENT — RackMonitor shows all non-oven racks as active; Production Status ACTIVE means "not all trees are in oven" |
| Quantity unit (pcs vs trees) | ✅ CONSISTENT — both show pcs (SUM of `quantity`) |
| Trees index `/lost-wax/trees` | ✅ CONSISTENT — same `current_stage` source |

---

## Risk Matrix

| Item | Classification | Priority |
|---|---|---|
| N+1: `$wo->trees()->count()` (unused) | 🔴 HIGH — N+1 bug | Fix immediately |
| N+1: WIP accessors not eager loaded | 🔴 HIGH — N+1 bug | Fix with eager loading |
| N+1: `planTrees` query per plan | 🔴 HIGH — N+1 for new flow | Fix with batch query |
| XLSX library missing | 🔴 BLOCKS FEATURE | Install `phpspreadsheet` |
| CSV default filter mismatch | ⚠️ MEDIUM | Fix default to `'active'` |
| CSV missing UTF-8 BOM | ⚠️ MEDIUM | Add BOM or switch to XLSX |
| ACTIVE/COMPLETED edge case (qty correction) | ⚠️ MEDIUM | Document or guard |
| CTK/RGKI based on legacy WIP table | ⚠️ LEGACY BUT SAFE | Document; will naturally fade as WOs complete |
| R placeholder columns (always `-`) | LOW | Remove in XLSX, keep in current view |
| Missing direct link to Trees page | LOW | Nice to have |
| UI density / abbreviations | LOW | UX improvement |

---

## Recommended Fix Order

1. **[HIGH] Fix N+1: remove `$wo->trees()->count()`** — the result `tree_count` is never rendered. Delete line 316.
2. **[HIGH] Fix N+1: eager load `wipEntries`** — add `'wipEntries'` to `with(['itemReference', 'plans', 'wipEntries'])` in the legacy WO query.
3. **[HIGH] Fix N+1: batch `planTrees` query** — move the per-plan tree query outside the `foreach`, using a single `whereIn('lost_wax_print_order_line_id', $allLineIds)` query, then group in PHP.
4. **[MEDIUM] Fix `exportCsv` default filter** — change default from `'all'` to `'active'` for consistency.
5. **[MEDIUM] Install `phpoffice/phpspreadsheet`** and implement XLSX export (pending user approval).
6. **[LOW] Add UTF-8 BOM** to existing CSV export as interim fix.
7. **[LOW] Remove `R` placeholder columns** from XLSX export.
8. **[LOW] Add direct link** from production status row to `/lost-wax/trees` filtered by code.

---

## XLSX Design Recommendation

### Sheet Structure

**Sheet 1: Production Status**

| Column | Header (XLSX) | Notes |
|---|---|---|
| A | Kode Cust | Keep — primary identifier |
| B | Product Name | Keep |
| C | AISI | Keep |
| D | PO Qty | Rename from `PO` for clarity |
| E | Plan Qty | Rename from `Plan` for clarity |
| F | Total Lapisan (pcs) | Expand from `Tot Lap` |
| G | Total Rusak (pcs) | Expand from `Tot Rsk` |
| H | Cetak (pcs) | Expand from `CTK` |
| I | Rangkai (pcs) | Expand from `RGKI` |
| J | Lapisan 1 (pcs) | Expand from `L1` |
| K | Lapisan 2 (pcs) | Expand from `L2` |
| L | Lapisan 3 (pcs) | Expand from `L3` |
| M | Lapisan 4 (pcs) | Expand from `L4` |
| N | Lapisan 5 (pcs) | Expand from `L5` |
| O | Lapisan 6 (pcs) | Expand from `L6` |
| P | Lapisan 7 (pcs) | Expand from `L7` |
| Q | Oven (pcs) | Keep |
| R | Status | Keep (`ACTIVE` / `SELESAI`) |

**Recommendation: Use full names (option B)** — factory floor users printing and sharing reports will benefit from clear column headers, especially Lapisan 1–7 vs L1–L7. Machine readability for analytics is also improved.

**Remove R spacer columns** — they carried no data and only confused the layout.

**Add header row freeze** and **zebra striping** for readability in large exports.

**Add metadata row** at top (print date, filter applied) before the data.

---

## Final Recommendation

The Production Status page is **fundamentally sound** in its core logic:
- Stage aggregation is correct and void-safe.
- Source of truth is `LostWaxTree.current_stage` — aligned with the new architecture.
- Wave is not used anywhere in this page.
- Filters work correctly for search/customer/PO/AISI.
- All 33 existing tests pass.

The **most urgent issue** before any further feature work is fixing the three N+1 query patterns, particularly the `$wo->trees()->count()` on line 316 which fires one SQL query per legacy work order and produces data that is never even displayed.

XLSX export requires installing `phpoffice/phpspreadsheet` — no other blocker exists for the implementation.

---

## Implementation Result

### N+1 #1 Status: RESOLVED
- **Problem**: `$wo->trees()->count()` called in a loop for each legacy Work Order.
- **Fix**: Removed dynamic count call. Instead, added `withCount('trees')` to the base query and read the eagerly computed `$wo->trees_count` attribute directly. This reduces O(N) database queries to O(1) query.
- **Verification**: Verified using a new database query logging test case.

### N+1 #2 Status: RESOLVED
- **Problem**: Accessing `wipEntries` in legacy Work Order accessors (`moulding_output_quantity` and `assembly_output_quantity`) caused N+1 lazy-loaded database queries.
- **Fix**: Added `'wipEntries'` to the eager loaded relations in the controller: `with(['itemReference', 'plans', 'wipEntries'])`. Accessors now read the already loaded collection in memory instead of executing queries.
- **Verification**: Verified via test suite.

### N+1 #3 Status: RESOLVED
- **Problem**: Eager-loaded trees for new ProductionPlans were queried per-plan in a foreach loop via `LostWaxTree::whereIn('lost_wax_print_order_line_id', $lineIds)->get()`.
- **Fix**: Changed the logic to collect `flatMap(fn ($line) => $line->trees)` in memory from the eager loaded relations. Since `$planQuery` eager loads `printOrderLines.trees`, these models are already loaded. Collecting in-memory avoids O(N) queries entirely.
- **Verification**: Verified via regression tests and query logging test case.

### Export Filter Consistency: RESOLVED
- Default filter parameter for the export endpoint is now `'active'` to perfectly match the main web view dashboard default parameter, preventing data inconsistency when the download URL is requested directly.

### XLSX Library: INSTALLED
- Installed package `phpoffice/phpspreadsheet` (v5.9.0) using composer.

### XLSX Structure: IMPLEMENTED
- Replaced CSV export stream with native XLSX generation.
- **Columns**: Consolidated to 18 clean columns. All empty spacer column placeholders `R` have been removed.
- **Headers**: Set to full readable labels (e.g. `PO Qty`, `Total Lapisan (pcs)`, `Total Rusak (pcs)`, `Lapisan 1 (pcs)`, etc.) instead of abbreviations.
- **Formatting**:
  - Bold headers with dark slate background fill.
  - Frozen header row (row 6) and auto filter enabled.
  - Number format set to `#,##0;(#,##0);"-"` for numeric columns to cleanly format zeroes as dashes.
  - Auto-adjusted column widths and text wrap on Product Name.
  - Slate-50 zebra striping on alternating rows.
  - Landscape page setup orientation.

### Offline Compatibility: CONFIRMED
- XLSX generation is performed entirely server-side in PHP using PhpSpreadsheet. No CDNs, external web dependencies, or client-side packages are used. Fully works on factory local LAN.

### Tests: GREEN
- Modified `ProductionStatusTest` to load the downloaded binary content, write it to a temp file, load it using PhpSpreadsheet, and verify the correct structure, headers, row metadata, data values, and N+1 query limits.
- Added test case `test_n_plus_one_prevention` to explicitly check that database query counts are low and constant, verifying no N+1 query regression.
- All 271 tests in the suite pass successfully.

### Final Verdict: READY for local deployment.
