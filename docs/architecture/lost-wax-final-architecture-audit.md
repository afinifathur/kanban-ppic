# Lost Wax Module — Final Architecture Audit & Implementation Blueprint

**Date:** 2026-08-22
**Status:** APPROVED FOR IMPLEMENTATION (pending Open Decisions section)
**Baseline:** Previous audit document `production_execution_audit.md` — do not repeat.
**Scope:** Production Plan → Print → Rangkai → Tree → Layer 1-7 → Oven only.

---

## 1. Current Architecture Summary

### What is CORRECT (Preserve As-Is)
| Component | Why Correct |
|-----------|-------------|
| `LostWaxTree` as physical unit | Tree = 1 physical basket. Tree-based, not order-based. |
| `LostWaxScanEvent` as append-only log | Each scan = 1 new record. Never overwritten. |
| `AssemblyController` concurrency | `lockForUpdate` + unique-constraint retry prevents over-allocation. |
| `PrintJob` as async queue | Decoupled from business logic. Python agent polls it. |
| `TsplRenderer` / `PrintJobService` | Stateless, correct TSPL output. Do not change. |
| RBAC / `product_scope` | Recently added. Correct. Preserve. |
| Snapshot columns on `PrintOrderLine` | `item_name`, `aisi`, `code`, `customer` are frozen at order time. Correct. |

### What is WRONG (Must Be Fixed)
| # | Problem | Severity |
|---|---------|----------|
| P1 | `qty_actual_good` / `qty_actual_defect` on `PrintOrderLine` are mutable single values — no execution history | CRITICAL |
| P2 | PLAN and EXECUTION are in the same row (`PrintOrderLine`) | CRITICAL |
| P3 | No outstanding-work visibility after partial execution | CRITICAL |
| P4 | `PrintOrder` has no `PARTIALLY_COMPLETED` or `COMPLETED` status | HIGH |
| P5 | No `Rangkai Work Order` entity — assembly is ad-hoc | HIGH |
| P6 | Concurrent outcome submissions have no idempotency guard | HIGH |
| P7 | `require_layer_7` only exists on `LostWaxWorkOrder` — new-path trees always skip Layer 7 | HIGH |
| P8 | `LostWaxScanEvent` has no void/correction mechanism | HIGH |
| P9 | `LostWaxTree.is_correctable` always false for new-path trees | MEDIUM |
| P10 | `PrintJob` has no FK to `LostWaxTree` — no print audit trail per tree | MEDIUM |
| P11 | `qty_remaining` on `ProductionPlan` is orphaned/stale | MEDIUM |
| P12 | `LostWaxPrintOrder.status` includes undocumented `PRINTED` value used in `DashboardController` | LOW |

---

## 2. Existing Functionality That Must Be Preserved

### Multi-Print-Order Per Plan (Already Works)
A `ProductionPlan` can have multiple `LostWaxPrintOrderLine` rows across multiple `LostWaxPrintOrder`s. This IS the continuation mechanism. Creating PC-0002 for the same plan is already possible and correct. **Do not invent a separate continuation concept.**

### Outstanding Calculation (Replace, Not Remove)
`getQtyRemainingScheduledAttribute()` on `ProductionPlan` currently computes scheduling headroom. This accessor logic should continue to work, feeding from the new `PrintExecution` model.

### Layer 7 Skip Logic
`nextStage()` in `LostWaxTree` correctly skips layer_7 when `require_layer_7 === false`. The bug is the source of this flag: currently reads from `workOrder->require_layer_7` (legacy only). Fix = move the flag to the tree level.

### Oven Scan Separation
`ScanService::processOvenScan()` is intentionally separate from the layer scan path. `nextStage()` returns `null` when next stage is `oven` — this forces the operator to use the dedicated oven scanner. **Correct design. Preserve.**

### `DashboardController` `PRINTED` Status
The Dashboard queries `whereIn('status', ['DRAFT', 'ISSUED', 'PRINTED'])`. The `PRINTED` status is not in the current migration's enum. This is a latent bug/future placeholder. Align this when adding `PARTIALLY_COMPLETED` and `COMPLETED`.

---

## 3. Business Rules (Confirmed)

### Outstanding Calculation Rule
```
outstanding = qty_ordered - SUM(executions.qty_good) - SUM(executions.qty_defect)
```
Defects ARE deducted. Rationale: if 5 pcs are defective, those 5 are "consumed" from the plan. If management disagrees and wants defects reproduced, they can create a new Print Order for the same plan.

### Continuation Rule
Outstanding work stays on the ORIGINAL `PrintOrderLine`. New `PrintExecution` records are appended to the same line across multiple days. No new Print Order is created for continuation.

### PrintOrder Completion Rule (Automatic)
When ALL lines of a `PrintOrder` satisfy: `SUM(exec.qty_good) + SUM(exec.qty_defect) >= qty_ordered`
→ `PrintOrder.status` automatically transitions to `COMPLETED`.

### Exceptional Closure Rule
If outstanding will never be fulfilled (customer changed order, etc.):
- Supervisor/admin explicitly closes the line with `CLOSED_WITH_EXCEPTION` status
- Requires: reason, actor, timestamp
- Original `PrintOrderLine.qty_ordered` is NOT modified
- All existing executions are preserved
- `PrintOrder` transitions to `COMPLETED` after all lines are either completed or closed-with-exception

### Execution Correction Rule
`PrintExecution` has lifecycle: `DRAFT → FINALIZED`
- `DRAFT`: Recorded but not yet locked. Operator may correct within same session.
- `FINALIZED`: Locked. Correction requires a supervisor/role with `finalize_execution` permission and an audit reason.
- Correction creates a new `PrintExecutionCorrection` record (or a void + replacement pair) — **do not overwrite the original row**.

### Reprint Policy
- `PrintJob` failure → new `PrintJob` created with `parent_print_job_id` referencing the failed job
- `printed_count` on `LostWaxTree` is incremented only when a new physical label is generated (first print), not on reprint
- Production quantity is NEVER changed by a reprint event

### Layer 7 Rule
- Default: `require_layer_7 = false` → layer_6 → oven
- If item requires Layer 7: `require_layer_7 = true` → layer_6 → layer_7 → oven
- This flag lives at the **`LostWaxPrintOrderLine`** level (new path) so it can be set during print planning
- The flag propagates to `LostWaxTree` when the tree is created (snapshot, not live FK)
- The existing `nextStage()` logic in `LostWaxTree` remains correct — only the source of the flag changes

### Scan Void Rule
- Incorrect scan events are NOT deleted
- A `LostWaxScanEventVoid` record is created: who, when, reason
- `LostWaxScanEvent.is_voided` (computed via join or cached bool) is used to exclude voided events from state derivation
- `LostWaxTree.current_stage` is recalculated from the most recent non-voided successful scan event
- Only users with `override_scan` permission may void events
- Oven scans can only be voided by admin (stricter authority)

### Rangkai Plan vs Execution Rule
- `RangkaiWorkOrder.qty_trees_planned = ceil(qty_good / tree_capacity)`
- Trees are created incrementally — each `RangkaiExecution` creates N trees
- `qty_trees_executed = SUM(rangkai_executions.trees_created)`
- `outstanding_trees = qty_trees_planned - qty_trees_executed`
- Trees from Day 1 advance to Layer 1 immediately; Day 2's tree advances only when created

---

## 4. Target Domain Model

```
ProductionPlan [1]
    qty_planned, status
    has_many PrintOrderLines (via PrintOrders)
    computed: qty_scheduled, qty_executed_good, qty_outstanding

LostWaxPrintOrder [1..N per plan]
    status: DRAFT|ISSUED|PARTIALLY_COMPLETED|COMPLETED|CANCELLED
    has_many PrintOrderLines

LostWaxPrintOrderLine [1..N per order]   ← PLAN entity (immutable after ISSUED)
    qty_ordered (plan)
    require_layer_7 (NEW — snapshot flag for coating path)
    execution_status: PENDING|IN_PROGRESS|COMPLETED|CLOSED_WITH_EXCEPTION
    qty_executed_good (cached aggregate — derived from PrintExecutions)
    qty_executed_defect (cached aggregate — derived from PrintExecutions)
    has_many PrintExecutions

LostWaxPrintExecution [0..N per line]    ← EXECUTION entity (append-only)
    execution_date, qty_good, qty_defect
    status: DRAFT|FINALIZED
    recorded_by, recorded_at
    has_one PrintExecutionCorrection (if corrected)

LostWaxPrintExecutionCorrection [0..1 per execution]   ← CORRECTION AUDIT
    original_qty_good, original_qty_defect
    corrected_qty_good, corrected_qty_defect
    corrected_by, corrected_at, reason

LostWaxRangkaiWorkOrder [0..N per PrintOrderLine]   ← NEW
    print_order_line_id
    rangkai_order_number
    qty_trees_planned, tree_capacity
    status: OPEN|PARTIALLY_COMPLETED|COMPLETED
    require_layer_7 (inherited from PrintOrderLine, snapshottable)
    reference_image_path (nullable — future)
    created_by

LostWaxRangkaiExecution [1..N per RangkaiWorkOrder]   ← NEW (append-only)
    rangkai_work_order_id
    execution_date
    trees_created (count of trees made in this session)
    recorded_by, recorded_at
    has_many LostWaxTrees (via print_execution_id → rangkai_execution_id)

LostWaxTree [0..N per RangkaiExecution]   ← PHYSICAL UNIT (preserve)
    rangkai_execution_id (NEW FK, replaces lost_wax_print_order_line_id in new path)
    lost_wax_print_order_line_id (keep for backward compat + direct query)
    require_layer_7 (NEW — snapshot from RangkaiWorkOrder at creation time)
    printed_count (NEW — incremented on first thermal print only)
    barcode, quantity, current_stage, last_scan_at

LostWaxScanEvent [0..N per tree]   ← IMMUTABLE AUDIT LOG (preserve schema)
    tree_id, barcode, stage, scanned_at, operator_id
    result: success|rejected
    anomaly_reason, aging_minutes, aging_status
    has_one LostWaxScanEventVoid (if voided)

LostWaxScanEventVoid [0..1 per event]   ← NEW
    scan_event_id
    voided_by, voided_at, void_reason
    requested_by (optional — who requested the void)
    approved_by (optional — for two-step approval if needed)

PrintJob [0..N per tree]   ← PRINT QUEUE (minimal change)
    tree_id (NEW nullable FK — links job to tree)
    parent_print_job_id (NEW nullable FK — for reprints)
    is_reprint (NEW boolean default false)
```

---

## 5. Relationships Between Entities

```
ProductionPlan ──< LostWaxPrintOrderLine >──< LostWaxPrintOrder
LostWaxPrintOrderLine ──< LostWaxPrintExecution
LostWaxPrintExecution ──0..1── LostWaxPrintExecutionCorrection
LostWaxPrintOrderLine ──< LostWaxRangkaiWorkOrder
LostWaxRangkaiWorkOrder ──< LostWaxRangkaiExecution
LostWaxRangkaiExecution ──< LostWaxTree
LostWaxTree ──< LostWaxScanEvent
LostWaxScanEvent ──0..1── LostWaxScanEventVoid
LostWaxTree ──< PrintJob
PrintJob ──0..1── PrintJob (parent_print_job_id self-reference for reprints)
```

---

## 6. PLAN vs EXECUTION Separation

| Entity | Layer | Mutable? | Who writes? |
|--------|-------|---------|-------------|
| `ProductionPlan` | PLAN | Editable before scheduling | PPIC |
| `LostWaxPrintOrderLine.qty_ordered` | PLAN | Immutable after ISSUED | PPIC |
| `LostWaxPrintExecution` | EXECUTION | Append-only; DRAFT editable, FINALIZED immutable | Operator/PPIC |
| `LostWaxPrintExecutionCorrection` | CORRECTION | Append-only, supervisor only | Supervisor |
| `LostWaxRangkaiWorkOrder` | PLAN | Editable while OPEN | PPIC |
| `LostWaxRangkaiExecution` | EXECUTION | Append-only | Operator |
| `LostWaxTree` | PHYSICAL | Immutable after creation (except `current_stage`, `last_scan_at`, `printed_count`) | System |
| `LostWaxScanEvent` | AUDIT | Immutable (never modified) | System |
| `LostWaxScanEventVoid` | OVERRIDE | Append-only | Supervisor/Admin |

---

## 7. Outstanding Calculation Rules

### Print Level
```
outstanding_print = qty_ordered
                  - SUM(executions.qty_good WHERE NOT voided)
                  - SUM(executions.qty_defect WHERE NOT voided)
```
Cached on `PrintOrderLine` as `qty_executed_good` + `qty_executed_defect`. Recomputed after each execution insert or correction.

### Rangkai Level
```
outstanding_trees = qty_trees_planned - SUM(rangkai_executions.trees_created)
```
Note: `qty_trees_planned = ceil(qty_executed_good_from_print / tree_capacity)`
Recalculated when `qty_executed_good` changes.

### Production Plan Level
```
plan_outstanding = qty_planned
                 - SUM(all_print_order_lines.qty_executed_good WHERE order.status != CANCELLED)
```

---

## 8. Print Order State Machine

```
DRAFT
  │
  ▼ (updateStatus → ISSUED)
ISSUED
  │
  ▼ (first PrintExecution recorded, outstanding > 0)
PARTIALLY_COMPLETED
  │
  ├──▼ (all lines: SUM(exec.good) + SUM(exec.defect) >= qty_ordered)
  │  COMPLETED  ◄─────────────────────────────────────────────────────
  │
  └──▼ (admin: CLOSE_WITH_EXCEPTION on last outstanding line)
     COMPLETED (exception closure)

DRAFT ──▼ (no executions recorded yet)
         CANCELLED

ISSUED ──▼ (no executions recorded yet, admin override)
           CANCELLED
```

**Invariant:** Once any `PrintExecution` is `FINALIZED`, the Print Order cannot be CANCELLED.
**Auto-trigger:** After each PrintExecution insert/finalize → check if all lines satisfy → if yes, transition to COMPLETED.

---

## 9. Print Order Line Execution Status

```
PENDING
  ▼ (first PrintExecution recorded)
IN_PROGRESS
  ▼ (outstanding = 0 via normal executions)
COMPLETED
  ▼ (admin exceptional closure)
CLOSED_WITH_EXCEPTION
```

`CLOSED_WITH_EXCEPTION` feeds into the Print Order auto-completion check (a line that is CLOSED_WITH_EXCEPTION counts as "done" for the parent order's completion check).

---

## 10. Rangkai Work Order State Machine

```
OPEN
  ▼ (first RangkaiExecution recorded, outstanding_trees > 0)
PARTIALLY_COMPLETED
  ▼ (outstanding_trees = 0)
COMPLETED

OPEN ──▼ (no executions, admin voids)
         CANCELLED
```

**Invariant:** Cannot delete a `RangkaiWorkOrder` that has any `RangkaiExecution` records.
**Note:** Multiple `RangkaiWorkOrders` can exist for the same `PrintOrderLine`. This handles cases where PPIC creates a separate Rangkai work order for different operator assignments or different dates.

---

## 11. Tree Lifecycle

```
(created by RangkaiExecution)
status = 'generated'
current_stage = NULL
require_layer_7 = snapshot from RangkaiWorkOrder

    ▼ (scan layer_1)
current_stage = 'layer_1'

    ▼ (scan layer_2 ... layer_6)
current_stage = 'layer_6'

    ├── if require_layer_7 = false:
    │       ▼ (oven scan)
    │   current_stage = 'oven'
    │
    └── if require_layer_7 = true:
            ▼ (scan layer_7)
        current_stage = 'layer_7'
            ▼ (oven scan)
        current_stage = 'oven'

status = 'ready_for_coating' (set when? → inspect existing; currently unused on new path)
```

**Physical Unit Rules:**
- A tree is physically real the moment it is created in the DB.
- `current_stage = NULL` means: assembled but not yet scanned at Layer 1.
- Trees from the same Rangkai Work Order may be at different stages simultaneously — this is correct.
- A tree cannot be deleted after creation (hard invariant). Errors must be corrected via void/correction mechanisms.

---

## 12. Layer 1–6 + Optional Layer 7 State Machine

### Current Behavior (CONFIRMED FROM CODE)
`LostWaxTree.nextStage()` at `layer_6`:
- If `require_layer_7 = true` → next = `layer_7`
- If `require_layer_7 = false` → next = `layer_7`'s successor (currently `oven` via config)
- Then if `nextStage = 'oven'` → returns `null` → oven must be scanned via `processOvenScan()`

**This logic is correct.** The bug is NOT in `nextStage()` logic — it is in **where `require_layer_7` is sourced**.

### Fix Required
1. Add `require_layer_7 boolean default false` to `lost_wax_print_order_lines` (set during print planning)
2. Add `require_layer_7 boolean default false` to `lost_wax_trees` (snapshot at tree creation)
3. Modify `LostWaxTree.getRequireLayer7Attribute()`:
   ```
   return (bool) ($this->attributes['require_layer_7'] ?? false);
   ```
   (Read from own column instead of `workOrder->require_layer_7`)
4. Modify `AssemblyController::store()` (current Rangkai path) and future `RangkaiExecutionController::store()` to copy `require_layer_7` from `RangkaiWorkOrder` → `LostWaxTree` at creation time
5. Legacy path: `LostWaxTree` created by `TreeGenerationService` still reads `require_layer_7` from `LostWaxWorkOrder` — **no change to legacy path**

### Oven Scan (No Change)
`ScanService::processOvenScan()` remains the dedicated path. `nextStage()` returning `null` before `oven` is intentional. The oven is a separate physical station with a separate UI. This design is preserved.

---

## 13. Scan Correction / Void Architecture

### Current State
`LostWaxScanEvent` has no void mechanism. No correction table exists.

### Proposed: Non-Destructive Void

**New table: `lost_wax_scan_event_voids`**
```
id
scan_event_id (FK → lost_wax_scan_events, unique — one void per event)
voided_by (FK → users)
voided_at (timestamp)
void_reason (text, required)
requested_by (FK → users, nullable — for 2-step approval if needed later)
```

**`LostWaxScanEvent` schema changes:**
- No column additions to `lost_wax_scan_events` itself (preserve immutability)
- Add eager-loadable relationship: `hasOne(LostWaxScanEventVoid, 'scan_event_id')`
- Add computed attribute: `getIsVoidedAttribute()` → checks if `void` relation exists

**State derivation rule:**
```
LostWaxTree.current_stage = latest STAGE from scan_events
    WHERE result = 'success'
    AND NOT EXISTS (SELECT 1 FROM scan_event_voids WHERE scan_event_id = scan_events.id)
    ORDER BY scanned_at DESC
    LIMIT 1
```

**When a void is submitted:**
1. Insert `LostWaxScanEventVoid` row
2. Recompute `LostWaxTree.current_stage` from non-voided events
3. Update `LostWaxTree.current_stage` and `last_scan_at` to match recomputed state
4. Log: who, when, reason (in the void record itself — no separate audit table needed)

**Permission:**
- `void_scan`: can void non-oven scan events for trees in own product_scope
- `void_oven_scan`: admin only — can void oven scan events

**Invariant:** An event that is already voided cannot be voided again (unique constraint on `scan_event_id`). An oven scan void restores tree to `layer_6` or `layer_7` depending on `require_layer_7`.

---

## 14. PrintJob vs Production Execution Separation

### Rule (Confirmed)
`PrintJob` = label print queue. `PrintExecution` = production output record. These are independent domains.

### Required Changes to PrintJob

**Additions to `print_jobs` table:**
```
tree_id              bigint nullable FK → lost_wax_trees
parent_print_job_id  bigint nullable FK → print_jobs (self-reference)
is_reprint           boolean default false
```

**Logic:**
- When `TreeController::printThermal()` creates a `PrintJob` → set `tree_id`
- When a supervisor triggers a reprint → create new `PrintJob` with `is_reprint=true`, `parent_print_job_id = original_job.id`
- `LostWaxTree.printed_count` increments only when a NON-reprint job transitions to `status=printed`

**Invariant:** A reprint does NOT change any production quantity. A failed job does NOT decrement production output.

---

## 15. Human Override Policy

Principle: **"Automation by default. Human override by controlled exception."**

| Scenario | System Behavior | Override Mechanism |
|----------|----------------|-------------------|
| All lines executed | Auto-transition PrintOrder → COMPLETED | N/A |
| Partial execution | Auto-transition to PARTIALLY_COMPLETED | N/A |
| Outstanding will never be produced | System shows as outstanding | Admin: CLOSE_WITH_EXCEPTION + reason |
| Wrong scan recorded | Scan event remains; state derived ignoring voided | Supervisor: void + reason |
| Wrong execution qty recorded (DRAFT) | Operator edits DRAFT execution directly | Standard edit |
| Wrong execution qty recorded (FINALIZED) | Cannot overwrite | Supervisor: correction record with original + new + reason |
| Reprint needed | New PrintJob created referencing original | Operator/Supervisor: reprint action |
| Tree quantity wrong (status=generated) | `is_correctable = true` (new path must be fixed) | Authorized user: adjust quantity |

**Invariant across all overrides:** The original record is NEVER deleted or overwritten. Every correction creates an additional audit record.

---

## 16. Required UI Changes

| Screen | Change |
|--------|--------|
| Print Order list | Add status badges: PARTIALLY_COMPLETED, COMPLETED |
| Print Order detail | Show per-line execution history (date, qty_good, qty_defect, outstanding) |
| Print Order detail | Add "Record Execution" button per line |
| Print Order line | Show outstanding quantity prominently |
| Outcome entry form | Replace single-value form with append-execution form (DRAFT → FINALIZE) |
| Outcome entry form | Show execution history list below form |
| Outstanding dashboard | New section: all PrintOrderLines with outstanding > 0 |
| Rangkai (Assembly) index | New parent: show RangkaiWorkOrders instead of raw PrintOrderLines |
| Rangkai create flow | Create RangkaiWorkOrder first, then execute trees from it |
| Tree index | Show `rangkai_execution_id` for traceability |
| Tree detail | Show which execution session created this tree |
| Scan history | Show void indicator on voided events; show void reason on hover |
| Scan UI | No change — `ScanService` auto-derives stage from non-voided events |
| Admin: void scan | New form: select event, enter reason, confirm |
| Tree list/filter | Add filter by outstanding_trees (Rangkai incomplete) |

---

## 17. Required Controller / Service Changes

### New Controllers
| Controller | Methods |
|-----------|--------|
| `PrintExecutionController` | `store()`, `finalize()`, `correct()` |
| `RangkaiWorkOrderController` | `index()`, `create()`, `store()`, `show()`, `update()` |
| `RangkaiExecutionController` | `store()` (appends execution + creates trees) |
| `ScanEventVoidController` | `store()` (voids an event + recomputes tree stage) |
| `PrintJobReprintController` | `store()` (creates reprint job) |

### Modified Controllers
| Controller | Change |
|-----------|--------|
| `OutcomeController` | Rewrite `updateOutcome()` → insert `PrintExecution`, recompute cached aggregates, trigger PrintOrder status check |
| `AssemblyController` | `index()` now shows `RangkaiWorkOrders`; `store()` becomes `RangkaiExecutionController::store()` |
| `TreeController` | `printThermal()` sets `tree_id` on `PrintJob`; add `reprint()` action |
| `LostWaxTree` (model) | Fix `is_correctable`, fix `getRequireLayer7Attribute()` |
| `LostWaxTree` (model) | `nextStage()` reads `$this->attributes['require_layer_7']` not `workOrder` |
| `ScanService` | `process()` and `processOvenScan()` derive current_stage from non-voided events |
| `PrintOrderController` | `updateStatus()`: add new allowed statuses; add auto-completion check |

### New Services
| Service | Responsibility |
|--------|----------------|
| `PrintExecutionService` | Validate, insert, finalize, correct executions; update cached aggregates; trigger PrintOrder status transitions |
| `RangkaiService` | Create RangkaiWorkOrder, record RangkaiExecution, calculate outstanding trees |
| `ScanVoidService` | Void a scan event; recompute tree stage; check permissions |
| `PrintOrderCompletionService` | Check all lines; auto-transition PrintOrder status |

---

## 18. Required Migrations

All migrations must be additive and idempotent (`Schema::hasColumn` guards).

| Migration | Type | Risk |
|-----------|------|------|
| `add_execution_fields_to_print_order_lines` | ADD columns: `execution_status varchar(30) default 'PENDING'`, `require_layer_7 boolean default false`, `qty_executed_good int nullable`, `qty_executed_defect int nullable` | LOW |
| `add_status_values_to_print_orders` | Alter enum to add `PARTIALLY_COMPLETED`, `COMPLETED` (MySQL: `MODIFY COLUMN`) | MEDIUM — test on prod MySQL 8 |
| `create_lost_wax_print_executions` | NEW table | LOW |
| `create_lost_wax_print_execution_corrections` | NEW table | LOW |
| `create_lost_wax_rangkai_work_orders` | NEW table | LOW |
| `create_lost_wax_rangkai_executions` | NEW table | LOW |
| `add_rangkai_execution_id_to_lost_wax_trees` | ADD nullable FK column | LOW |
| `add_require_layer_7_to_lost_wax_trees` | ADD `require_layer_7 boolean default false` | LOW |
| `add_printed_count_to_lost_wax_trees` | ADD `printed_count int default 0` | LOW |
| `create_lost_wax_scan_event_voids` | NEW table | LOW |
| `add_tree_id_to_print_jobs` | ADD nullable FK + `parent_print_job_id` + `is_reprint` | LOW |

**MySQL 8 enum alteration note:** MySQL requires full column redefinition to add enum values. Use:
```sql
ALTER TABLE lost_wax_print_orders MODIFY COLUMN status ENUM('DRAFT','ISSUED','PARTIALLY_COMPLETED','COMPLETED','CANCELLED') DEFAULT 'DRAFT';
```
Include `Schema::hasColumn` guard. Test on staging before production deploy.

---

## 19. Required Tests

### New Feature Tests
| Test Class | Key Scenarios |
|-----------|--------------|
| `PrintExecutionTest` | Partial execution → outstanding correct; Day 2 execution → outstanding 0 → line COMPLETED; Exceed qty_ordered → rejected; DRAFT edit; FINALIZED correction; Concurrent submissions |
| `PrintOrderCompletionTest` | All lines COMPLETED → order COMPLETED; Mixed lines (one CLOSED_WITH_EXCEPTION) → order COMPLETED; Still-open line → order stays PARTIALLY_COMPLETED |
| `RangkaiWorkOrderTest` | Create with qty_trees_planned; Partial execution (4/5 trees); Day 2 execution (last tree); COMPLETED transition |
| `RangkaiTreeTraceabilityTest` | Tree.rangkai_execution_id set correctly; Tree.require_layer_7 snapshot from RangkaiWorkOrder |
| `ScanVoidTest` | Void valid scan; Tree stage recomputed; Oven scan void requires admin; Cannot void already-voided event |
| `Layer7NewPathTest` | require_layer_7=false → layer_6 → oven (no layer_7); require_layer_7=true → layer_6 → layer_7 → oven |
| `PrintJobReprintTest` | Reprint creates new PrintJob with parent_id; printed_count unchanged; production qty unchanged |
| `OutstandingDashboardTest` | Outstanding items appear after partial execution; Disappear after completion |

### Regression Tests (Must Still Pass)
- All existing `TreeTest`, `ScanEngineTest`, `ScanOvenTest`, `WorkOrderTest`, `PrintOrderTest`, `ThermalPrintTest`
- New-path trees: `is_correctable = true` when `status IN ['generated', 'ready_for_coating']` (fix legacy-only condition)

---

## 20. Data Migration / Backward Compatibility

### Existing `qty_actual_good` Records
After creating `lost_wax_print_executions`:

```sql
-- Backfill existing outcomes as FINALIZED executions
INSERT INTO lost_wax_print_executions
  (lost_wax_print_order_line_id, execution_date, qty_good, qty_defect, status,
   recorded_by, recorded_at, created_at, updated_at)
SELECT
  id,
  COALESCE(DATE(actual_recorded_at), DATE(created_at)),
  qty_actual_good,
  COALESCE(qty_actual_defect, 0),
  'FINALIZED',
  actual_recorded_by,
  COALESCE(actual_recorded_at, created_at),
  NOW(), NOW()
FROM lost_wax_print_order_lines
WHERE qty_actual_good IS NOT NULL;
```

After backfill: `qty_executed_good` and `qty_executed_defect` on lines should be updated to match (already correct since they equal the backfilled values).

### Existing Trees Without `rangkai_execution_id`
Leave as NULL. New-path trees reference `lost_wax_print_order_line_id` directly (still valid). The new `rangkai_execution_id` is added only going forward for trees created via the new Rangkai flow.

### `require_layer_7` on Existing Trees
Default to `false` — safe because all new-path trees currently skip Layer 7 anyway.

### Legacy Work Order Path
Unchanged. `TreeGenerationService` continues to create trees linked to `work_order_id`. `require_layer_7` on those trees is populated from `LostWaxWorkOrder.require_layer_7` (unchanged).

### `LostWaxTree.is_correctable` Fix
Change condition from `$this->work_order_id !== null` to:
```php
return in_array($this->status, ['generated', 'ready_for_coating']);
```
This correctly allows correction for both legacy and new-path trees.

---

## 21. New Table Schemas

### `lost_wax_print_executions`
```
id                              bigint PK
lost_wax_print_order_line_id    bigint FK (lost_wax_print_order_lines)
execution_date                  date NOT NULL
qty_good                        int NOT NULL
qty_defect                      int NOT NULL DEFAULT 0
status                          varchar(20) DEFAULT 'DRAFT'  -- DRAFT|FINALIZED
notes                           text nullable
recorded_by                     bigint FK (users) nullable
recorded_at                     timestamp NOT NULL
finalized_by                    bigint FK (users) nullable
finalized_at                    timestamp nullable
created_at, updated_at          timestamps
INDEX(lost_wax_print_order_line_id, execution_date)
```

### `lost_wax_print_execution_corrections`
```
id                              bigint PK
print_execution_id              bigint FK (lost_wax_print_executions) UNIQUE
original_qty_good               int NOT NULL
original_qty_defect             int NOT NULL
corrected_qty_good              int NOT NULL
corrected_qty_defect            int NOT NULL
corrected_by                    bigint FK (users)
corrected_at                    timestamp NOT NULL
reason                          text NOT NULL
created_at, updated_at          timestamps
```

### `lost_wax_rangkai_work_orders`
```
id                              bigint PK
rangkai_order_number            varchar UNIQUE
lost_wax_print_order_line_id    bigint FK (lost_wax_print_order_lines)
qty_trees_planned               int NOT NULL
tree_capacity                   int NOT NULL DEFAULT 20
require_layer_7                 boolean DEFAULT false
status                          varchar(30) DEFAULT 'OPEN'  -- OPEN|PARTIALLY_COMPLETED|COMPLETED|CANCELLED
notes                           text nullable
reference_image_path            varchar nullable  -- extension point for future image
created_by                      bigint FK (users)
created_at, updated_at          timestamps
INDEX(lost_wax_print_order_line_id)
```

### `lost_wax_rangkai_executions`
```
id                              bigint PK
rangkai_work_order_id           bigint FK (lost_wax_rangkai_work_orders)
execution_date                  date NOT NULL
trees_created                   int NOT NULL  -- count of trees added in this session
family_code                     varchar(10) NOT NULL
recorded_by                     bigint FK (users)
recorded_at                     timestamp NOT NULL
created_at, updated_at          timestamps
INDEX(rangkai_work_order_id, execution_date)
```

### `lost_wax_scan_event_voids`
```
id                              bigint PK
scan_event_id                   bigint FK (lost_wax_scan_events) UNIQUE
voided_by                       bigint FK (users)
voided_at                       timestamp NOT NULL
void_reason                     text NOT NULL
requested_by                    bigint FK (users) nullable
created_at, updated_at          timestamps
```

---

## 22. Risks and Edge Cases

| Risk | Mitigation |
|------|-----------|
| MySQL enum alteration locks table briefly | Run during low-traffic window; use `pt-online-schema-change` or blue-green if table is large |
| Backfill creates duplicate executions if migration runs twice | Guard with `WHERE NOT EXISTS` on backfill insert |
| Void leaves `current_stage` stale if recompute fails mid-transaction | Wrap void + recompute in single DB transaction |
| Two supervisors void the same event simultaneously | UNIQUE constraint on `scan_event_id` in void table prevents duplicate voids |
| `PrintOrder` auto-completion fires before all corrections are applied | Apply completion check only after `PrintExecution.status = FINALIZED` |
| `RangkaiWorkOrder.qty_trees_planned` becomes stale if `qty_executed_good` changes (via correction) | Recalculate `qty_trees_planned` when parent `PrintExecution` is corrected; show warning if trees already created exceed new plan |
| Reprint increments `printed_count` incorrectly | Filter `is_reprint = false` when counting |
| `require_layer_7` on tree mismatches printed traveler label | Label is generated at print time from tree data — if `require_layer_7` is changed after print, label is outdated. **Policy: require_layer_7 on tree is immutable after first successful scan event.** |
| Outstanding calculation shows negative if qty_good was over-recorded and then corrected | Cap at 0: `outstanding = MAX(0, qty_ordered - qty_executed_good - qty_executed_defect)` |
| Legacy `DashboardController` queries `status = 'PRINTED'` which doesn't exist in current enum | Fix: replace `PRINTED` with `PARTIALLY_COMPLETED` in Dashboard query when adding new statuses |

---

## 23. Phased Implementation Sequence

### PHASE 1 — Schema Foundation (Non-destructive, production-safe)
Migrations only, no application logic changes:
1. `add_execution_fields_to_print_order_lines` (execution_status, require_layer_7, cached aggregates)
2. `create_lost_wax_print_executions`
3. `create_lost_wax_print_execution_corrections`
4. `add_status_values_to_print_orders` (enum expansion)
5. `create_lost_wax_rangkai_work_orders`
6. `create_lost_wax_rangkai_executions`
7. `add_rangkai_execution_id_to_lost_wax_trees` + `add_require_layer_7_to_lost_wax_trees` + `add_printed_count`
8. `create_lost_wax_scan_event_voids`
9. `add_tree_id_to_print_jobs` + `parent_print_job_id` + `is_reprint`

**Run: `composer test` before and after. All existing tests must pass.**

### PHASE 2 — Print Execution (Replace OutcomeController write path)
1. `PrintExecutionService`: validate, insert, update cached aggregates, trigger auto-completion
2. `PrintExecutionController`: `store()`, `finalize()`, `correct()`
3. `PrintOrderCompletionService`: auto-transition logic
4. Update `OutcomeController` to delegate to `PrintExecutionService`
5. Backfill existing `qty_actual_good` data into `PrintExecution` records
6. Fix `LostWaxPrintOrder` model: add new statuses, auto-completion relation
7. Fix `LostWaxTree.is_correctable` condition
8. **Tests:** `PrintExecutionTest`, `PrintOrderCompletionTest`

### PHASE 3 — Rangkai Work Order
1. `RangkaiService`: create RangkaiWorkOrder, record RangkaiExecution, create trees with correct FKs
2. `RangkaiWorkOrderController` + `RangkaiExecutionController`
3. Fix `LostWaxTree`: `require_layer_7` from own column + `rangkai_execution_id`
4. Fix `nextStage()`: read from `$this->attributes['require_layer_7']`
5. Fix `AssemblyController`: route new-path to `RangkaiWorkOrder` flow
6. Add `require_layer_7` to `PrintOrderLine` create/edit form
7. **Tests:** `RangkaiWorkOrderTest`, `Layer7NewPathTest`, `RangkaiTreeTraceabilityTest`

### PHASE 4 — Scan Void Architecture
1. `ScanVoidService`: insert void, recompute tree stage within transaction
2. `ScanEventVoidController`: `store()`
3. Modify `ScanService`: filter voided events when reading `current_stage`
4. `LostWaxScanEvent` model: add `isVoided` accessor, `void` relationship
5. UI: void button on scan history (admin/supervisor only)
6. **Tests:** `ScanVoidTest`

### PHASE 5 — PrintJob Traceability + Reprint
1. Update `TreeController::printThermal()` to set `print_jobs.tree_id`
2. `PrintJobReprintController::store()`
3. `LostWaxTree.printed_count` increment on first successful non-reprint job
4. **Tests:** `PrintJobReprintTest`

### PHASE 6 — UI / Dashboard
1. Outstanding Work dashboard section (PPIC view)
2. Print Order detail: execution history list per line
3. Rangkai Work Order management screens
4. Scan history: void indicator + reason
5. Admin: void-scan form
6. **Tests:** `OutstandingDashboardTest`

---

## OPEN DECISIONS

Only genuinely unresolved items based on the requirements provided:

1. **PrintExecution DRAFT window:** How long can a PrintExecution stay in DRAFT before requiring finalization? Is there a time-based auto-finalization (e.g., auto-finalize at end of day)? Or is DRAFT purely session-scoped until operator clicks "Finalize"?

2. **Scan Void Approval — Two-Step?** Does voiding a scan require just one authorized person, or should it optionally require both a requester and a separate approver? The schema has `requested_by` nullable — but the business rule for when a second approval is required is not specified.

3. **RangkaiWorkOrder `reference_image_path`:** The field extension point is included. Is the image uploaded to the server or referenced from an external system? No implementation required yet, but the upload mechanism should be decided before Phase 6 UI work.

4. **Exceptional Print Order Closure Authorization:** What role/permission is required to perform a `CLOSE_WITH_EXCEPTION` action on a PrintOrderLine? Is this `admin` only, or can a senior PPIC role do it?

5. **`PrintOrder.status = 'PARTIALLY_COMPLETED'` vs keeping `ISSUED`:** Should the status automatically move to `PARTIALLY_COMPLETED` after the first execution, or remain `ISSUED` until fully completed? (The blueprint currently proposes `PARTIALLY_COMPLETED` for UI clarity, but this adds complexity to the enum.) If `ISSUED` is acceptable for "in progress," the state machine can be simplified to `ISSUED → COMPLETED`.
