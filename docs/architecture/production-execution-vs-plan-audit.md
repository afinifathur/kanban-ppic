# Production Execution vs Plan — Architecture Audit
## Kanban PPIC / Lost Wax FIFO Tracking

**Audit Date:** 2026-08-22
**Status:** DRAFT — Awaiting Business Approval Before Implementation

---

## 1. Executive Summary

The current application has a **partially complete Plan → Execution separation** for the new Production Plan workflow, but contains several critical architectural gaps that prevent true partial/multi-day execution:

1. **Outcome recording is a single-write, overwritable field** (`qty_actual_good` on `LostWaxPrintOrderLine`). There is no execution history table — editing destroys the prior value.
2. **The Print Order Line is both the plan record AND the execution record**, combining intended quantity (`qty_ordered`) and actual result (`qty_actual_good`) in one row. This conflates PLAN with EXECUTION.
3. **There is no partial-execution / continuation model** for Print outcomes. If 70 of 100 pcs are produced today, the system has no entity to represent "30 still outstanding."
4. **The Rangkai (Assembly/Tree generation) process does support partial execution** — trees are generated incrementally against `qty_available_for_rangkai` — but there is no parent "Rangkai Order" entity to group partial executions.
5. **Physical trees (`LostWaxTree`) are correctly tree-based, not order-based** for downstream layer scanning. This part of the system is sound.
6. **No Rangkai Order / Rangkai Execution table exists.** Assembly is triggered directly from `PrintOrderLine`, skipping an intermediate execution record.
7. **The Legacy Work Order path** has different (more complex) problems with moulding/assembly WIP stages that are out of scope for the new path but must remain backward-compatible.
8. **The thermal print infrastructure (PrintJob + Python agent) is architecturally sound and must not be changed.**

The fundamental principle **PLAN ≠ EXECUTION ≠ PHYSICAL UNIT** is partially respected (physical trees are separate from the plan), but the print execution layer is missing.

---

## 2. Current Architecture Map

```
[Legacy Path]
ProductionPlan → (not used for LW)
LostWaxWorkOrder → LostWaxWorkOrderPlan → LostWaxWorkOrderWip (stage=moulding/assembly)
                                         → LostWaxTree (physical unit, via TreeGenerationService)
                                                     → LostWaxScanEvent (layer_1..layer_7, oven)

[New Path — Current State]
ProductionPlan
    ↓
LostWaxPrintOrder (order document)
    ↓
LostWaxPrintOrderLine (plan + execution combined — PROBLEM)
    ↓ (after qty_actual_good is filled)
LostWaxTree (physical unit, via AssemblyController)
    ↓
LostWaxScanEvent (layer_1..layer_7, oven)

[Print Infrastructure — DO NOT CHANGE]
LostWaxTree → TsplRenderer → PrintJob → Python Print Agent → TSC TE244
```

---

## 3. Current Data Model Analysis

### 3.1 `production_plans`

| Column | Type | Plan/Actual | Mutable? | Problem? |
|--------|------|-------------|----------|---------|
| `code` | string | PLAN | No | |
| `item_name`, `item_code` | string | PLAN | No | |
| `po_number` | string | PLAN | No | |
| `qty_planned` | integer | PLAN | Editable | |
| `qty_remaining` | integer | **ORPHANED** | Never auto-updated | ⚠️ Misleading |
| `status` | enum(planning/active/completed) | PLAN | Manual only | ⚠️ Never auto-transitions |
| `is_closed` | boolean | PLAN | Manual | |

**Problems:**
- `qty_remaining` is set at creation and **never automatically decremented** as Print Orders are created. The actual remaining is computed dynamically via accessor `getQtyRemainingScheduledAttribute()`.
- `status` enum has three values but no logic automatically transitions them.
- No `qty_actual_executed` field exists at plan level.

### 3.2 `lost_wax_print_orders`

**Status state machine (current):**
- `DRAFT` → `ISSUED` (irreversible)
- `DRAFT` → `CANCELLED`
- `ISSUED` → cannot be reverted, **never auto-completes**

> ⚠️ **Problem:** There is no `COMPLETED` status. A Print Order is never marked done. All ISSUED orders appear "active" indefinitely.

### 3.3 `lost_wax_print_order_lines` — THE CRITICAL PROBLEM

| Column | Type | Plan/Actual | Mutable? | Problem? |
|--------|------|-------------|----------|---------|
| `qty_ordered` | integer | PLAN | Yes (DRAFT only) | |
| `qty_actual_good` | integer nullable | **ACTUAL** | **YES — overwrites history** | ❌ CRITICAL |
| `qty_actual_defect` | integer nullable | **ACTUAL** | **YES — overwrites history** | ❌ CRITICAL |
| `actual_recorded_at` | timestamp | META | **YES — overwritten** | ❌ |
| `actual_recorded_by` | FK users | META | **YES — overwritten** | ❌ |

**Critical problems:**
1. `qty_actual_good` and `qty_actual_defect` are **single-value fields**. Editing destroys previous values. **Multi-day execution is not supported.**
2. This single row **combines ORDER (qty_ordered) with EXECUTION (qty_actual_good)**. No Plan/Execution separation.
3. No partial execution model: if 70 of 100 pcs are printed today, the 30 outstanding is invisible.

### 3.4 `lost_wax_trees` — THE PHYSICAL UNIT (SOUND ✅)

Trees represent actual physical units. They are:
- ✅ Created only after `qty_actual_good` is recorded
- ✅ Independent of `qty_ordered` — created from `qty_available_for_rangkai`
- ✅ Each has a unique barcode for physical traceability

**Minor problems:**
- `is_correctable` only returns `true` for legacy (`work_order_id`) trees — new-path trees cannot be corrected
- No `print_execution_id` to link a tree to the specific execution session it came from

### 3.5 `lost_wax_scan_events` — IMMUTABLE AUDIT LOG (SOUND ✅)

Each scan = one new append-only record. No editing. Correct architecture.

### 3.6 `print_jobs` — SOUND BUT INCOMPLETE

- ✅ Clean async queue for thermal printing
- ❌ **No FK to `lost_wax_trees`** — after printing, you cannot trace which job printed which tree, or confirm a specific tree's label was successfully printed

---

## 4. Current Workflow Trace (New Path)

```
Step 1  ProductionPlan created (PLAN entity)
Step 2  PrintOrderController::create() — user selects plans, previews
Step 3  PrintOrderController::store() — LostWaxPrintOrder (DRAFT) + lines created
          - qty_ordered from user input
          - item_name, code, aisi, size as SNAPSHOTS (correct ✅)
Step 4  PrintOrderController::updateStatus() → ISSUED
Step 5  ❌ MISSING — factory printing physically happens, NO record created
Step 6  OutcomeController::updateOutcome()
          - Records qty_actual_good, qty_actual_defect
          - ❌ OVERWRITES — no history
          - ✅ lockForUpdate on line
          - ❌ No idempotency: two concurrent submits both succeed
Step 7  AssemblyController::index() — lists lines with qty_available_for_rangkai > 0
Step 8  AssemblyController::create() — previews proposed tree distribution
Step 9  AssemblyController::store()
          - ✅ lockForUpdate on line prevents over-allocation
          - ✅ unique constraint + retry for barcode collisions
          - Creates LostWaxTree rows linked to PrintOrderLine
Step 10 TreeController::printThermal() → PrintJob queue
Step 11 ScanController::process() — advances tree.current_stage per scan
Step 12 ScanController::processOven() — validates and records oven entry
```

---

## 5. Problems Identified

### Problem 1: No Print Execution History Table ❌ CRITICAL
The `LostWaxPrintOrderLine` stores actual results in mutable columns. A second execution on a different day destroys the first execution's record.

**Impact:** Audit trail violation. No date-based reporting. No multi-day execution support.

### Problem 2: No "Outstanding Work" Concept ❌ CRITICAL
If `qty_ordered = 100` and `qty_actual_good = 70`, the 30 outstanding are invisible. No status, no alert, no dashboard entry.

**Impact:** Outstanding work can silently disappear from operator attention.

### Problem 3: No Print Order COMPLETED Status ❌ HIGH
The Print Order only has DRAFT/ISSUED/CANCELLED. An order is never marked complete. All ISSUED orders appear "active" indefinitely.

### Problem 4: No Rangkai Order Entity ❌ HIGH
Assembly (Rangkai) is triggered directly from `PrintOrderLine` without a parent document. Cannot plan or group Rangkai sessions.

### Problem 5: PrintJob Not Linked to Tree ❌ MEDIUM
`print_jobs` has no FK to `lost_wax_trees`. Cannot trace which job printed which tree, or confirm printing succeeded.

### Problem 6: `qty_remaining` on ProductionPlan is Orphaned ⚠️ MEDIUM
`production_plans.qty_remaining` is set at creation and never updated. Real remaining is computed dynamically. The stored field is misleading.

### Problem 7: Defect Semantics Ambiguous ❓ MEDIUM
The code allows `qty_actual_good + qty_actual_defect <= qty_ordered`, treating defects as written off (no reproduction). **This is an unresolved business question** — must be confirmed before implementation.

### Problem 8: `is_correctable` Wrong for New-Path Trees ⚠️ MEDIUM
`LostWaxTree.is_correctable` returns `false` for new-path trees because it checks `work_order_id !== null` (only set in legacy path).

### Problem 9: Layer 7 Always Skipped for New-Path Trees ❓ MEDIUM
`require_layer_7` only exists on `LostWaxWorkOrder`. New-path trees always skip Layer 7. **Business decision: intentional or not?**

### Problem 10: Concurrent Outcome Recording Race ⚠️ HIGH
Two supervisors can submit outcomes simultaneously. Both `lockForUpdate` on the line then write their values. Last write wins silently — no idempotency.

---

## 6. Legacy Work Order Dependencies

| Location | Dependency | Classification |
|----------|-----------|----------------|
| `LostWaxTree.getIsCorrectableAttribute()` | Only true if `work_order_id !== null` | **ACCIDENTAL — must fix** |
| `LostWaxTree.getRequireLayer7Attribute()` | Returns `workOrder?.require_layer_7` — always false for new trees | **Intentional or bug?** |
| `TreeController::generate()` | Only routes to legacy `WorkOrderController` | **LEGACY ONLY** |
| `TreeGenerationService` | Only accepts `LostWaxWorkOrderPlan` | **LEGACY ONLY** |
| `ProductionStatusController` | Explicitly two-path rendering | **Required compat** |
| `getSourceProduct()`, `getSourceCode()` etc. | Now prioritize new path (fixed this session ✅) | **Fixed** |

---

## 7. Answers to Audit Questions

| Question | Current Answer |
|----------|---------------|
| Source of truth for planned quantity | `PrintOrderLine.qty_ordered` |
| Source of truth for actual execution | `PrintOrderLine.qty_actual_good` (overwritable) |
| Can execution be partial? | YES technically — but no outstanding record |
| Can execution occur another day? | YES — but history is destroyed |
| Can continuation be created? | NO — no entity for it |
| Are travelers generated prematurely? | NO ✅ |
| Does downstream depend on planned qty? | NO ✅ — layer scanning is tree-based |
| Is execution history preserved? | NO ❌ |
| Can work appear accidentally completed? | YES ❌ — no status transitions |
| Can outstanding work become invisible? | YES ❌ |

---

## 8. Quantity Semantics (Current)

| Field | Location | Meaning | Problem? |
|-------|----------|---------|---------|
| `qty_planned` | ProductionPlan | Total to produce per customer PO | Correct |
| `qty_remaining` | ProductionPlan | **Orphaned** — never auto-updated | ⚠️ |
| `qty_ordered` | PrintOrderLine | Planned quantity for this print batch | Correct |
| `qty_actual_good` | PrintOrderLine | **Cumulative or single-day?** | ⚠️ Ambiguous |
| `qty_actual_defect` | PrintOrderLine | **Written off or re-produce?** | ⚠️ Ambiguous |
| `quantity` | LostWaxTree | Pcs on this physical tree | Correct ✅ |

---

## 9. Recommended Architecture

### Core Principle: PLAN ≠ EXECUTION ≠ PHYSICAL UNIT

```
[ORDER/PLAN]                [EXECUTION]              [PHYSICAL UNIT]
PrintOrderLine              PrintExecution           LostWaxTree
    qty_ordered         →       date                     barcode
                                qty_good             →   current_stage
                                qty_defect               quantity
                                operator                 scan_events (immutable)
                                outstanding ←────────────────────────────────
                                (calculated)
```

### 9.1 New Entity: `lost_wax_print_executions`

```sql
CREATE TABLE lost_wax_print_executions (
    id              bigint PRIMARY KEY,
    lost_wax_print_order_line_id bigint NOT NULL REFERENCES lost_wax_print_order_lines(id),
    execution_date  date NOT NULL,
    qty_good        integer NOT NULL,
    qty_defect      integer NOT NULL DEFAULT 0,
    notes           text,
    recorded_by     bigint REFERENCES users(id),
    recorded_at     timestamp NOT NULL,
    created_at      timestamp,
    updated_at      timestamp,
    INDEX(lost_wax_print_order_line_id, execution_date)
);
```

**Rules:**
- Append-only (no editing or deleting)
- Application enforces: `SUM(qty_good) + SUM(qty_defect) <= qty_ordered` inside a row-locked transaction
- `outstanding = qty_ordered - SUM(executions.qty_good)` — computed dynamically

### 9.2 Addition: `print_execution_id` on `lost_wax_trees`

```sql
ALTER TABLE lost_wax_trees
    ADD COLUMN print_execution_id bigint NULL REFERENCES lost_wax_print_executions(id);
```

Links each physical tree to the specific execution session it was produced in.

### 9.3 Proposed Hierarchy

```
ProductionPlan [1]
    ├── [1..N] LostWaxPrintOrders
    │
LostWaxPrintOrder [1]
    ├── [1..N] LostWaxPrintOrderLines
    │
LostWaxPrintOrderLine [1]         (ORDER DOCUMENT — immutable after ISSUED)
    ├── [0..N] PrintExecutions    (EXECUTION RECORDS — append-only)
    │               ├── qty_good
    │               └── qty_defect
    │
    └── [0..N] LostWaxTrees       (PHYSICAL UNITS — via execution)
                    ├── print_execution_id → which session made this tree
                    └── [0..N] ScanEvents  (immutable audit log)
```

---

## 10. Proposed State Machines

### Print Order Line Status (NEW FIELD)

```
PENDING → IN_PROGRESS → COMPLETED
                          (auto when SUM(exec.qty_good) >= qty_ordered)
```

### Print Order Status (ENHANCED)

```
DRAFT → ISSUED → COMPLETED
              → CANCELLED (only if no executions recorded)
```

Auto-transition: `ISSUED → COMPLETED` when all lines reach `COMPLETED`.

### Production Plan Status (ENHANCED)

```
planning → active → fully_scheduled → fully_executed → completed
```

---

## 11. Proposed Quantity Model (After Implementation)

```
PrintOrderLine.qty_ordered              = planned for this batch (immutable after ISSUED)
PrintOrderLine.qty_executed_good        = SUM(executions.qty_good) [cached aggregate]
PrintOrderLine.qty_executed_defect      = SUM(executions.qty_defect) [cached aggregate]
PrintOrderLine.qty_outstanding          = qty_ordered - qty_executed_good [computed]
PrintOrderLine.qty_available_for_rangkai = qty_executed_good - SUM(tree.quantity) [computed]

PrintExecution.qty_good                 = actual good from this session [immutable]
PrintExecution.qty_defect               = actual defect this session [immutable]

LostWaxTree.quantity                    = pcs loaded on this physical tree [immutable]
```

---

## 12. Continuation Model (Concrete Example)

```
PrintOrderLine: PC-20260821-0001 / SS316 SORF 1" / qty_ordered=100

    Execution #1 (2026-08-21):  qty_good=70, qty_defect=5
        outstanding_after = 100 - 70 = 30
        Trees #1..#4 created from this execution (execution_id=1)

    Execution #2 (2026-08-22):  qty_good=30, qty_defect=0
        outstanding_after = 100 - 70 - 30 = 0 → LINE COMPLETED
        Tree #5 created from this execution (execution_id=2)

    PrintOrder auto-transitions to COMPLETED (all lines done)
```

Trees from Day 1 and Day 2 move through Layer scanning independently — already supported ✅.

---

## 13. Migration Strategy (Non-Destructive)

### Phase A: Schema Additions (Safe to run on production)

```sql
-- 1. New table (additive, no risk)
CREATE TABLE lost_wax_print_executions (...)

-- 2. Nullable FK on trees (additive, null-safe)
ALTER TABLE lost_wax_trees ADD COLUMN print_execution_id bigint NULL FK

-- 3. Execution status on lines (additive with default)
ALTER TABLE lost_wax_print_order_lines
    ADD COLUMN execution_status varchar(20) DEFAULT 'pending'
```

### Phase B: Data Backfill

For existing `PrintOrderLine` rows that already have `qty_actual_good`:

```sql
INSERT INTO lost_wax_print_executions
    (lost_wax_print_order_line_id, execution_date, qty_good, qty_defect, recorded_by, recorded_at)
SELECT id, DATE(actual_recorded_at), qty_actual_good, COALESCE(qty_actual_defect, 0),
       actual_recorded_by, actual_recorded_at
FROM lost_wax_print_order_lines
WHERE qty_actual_good IS NOT NULL;
```

Then update tree links:
```sql
UPDATE lost_wax_trees t
SET print_execution_id = (
    SELECT e.id FROM lost_wax_print_executions e
    WHERE e.lost_wax_print_order_line_id = t.lost_wax_print_order_line_id LIMIT 1
)
WHERE t.lost_wax_print_order_line_id IS NOT NULL AND t.print_execution_id IS NULL;
```

### Phase C: Application Logic Changes

- Modify `OutcomeController::updateOutcome()` to INSERT into `PrintExecution` (not UPDATE `PrintOrderLine`)
- Keep `qty_actual_good` as a cached aggregate (update it after each execution for backward compat)
- Add PrintOrderLine status transitions
- Add PrintOrder auto-completion logic

### Backward Compatibility

All changes are additive. Existing `qty_actual_good` kept as cached aggregate. All legacy Work Order tables are unchanged.

---

## 14. Concurrency Requirements

| Location | Current | Required |
|----------|---------|----------|
| `OutcomeController::updateOutcome()` | `lockForUpdate` | + idempotency check before insert |
| `AssemblyController::store()` | `lockForUpdate` + retry ✅ | No change needed |
| PrintExecution insert | Not implemented | Row lock on line before insert + sum validation |

**Idempotency rule:** Before inserting a `PrintExecution`, validate inside a row-locked transaction that `existing_total + new_qty_good <= qty_ordered`.

---

## 15. Backward Compatibility Plan

| Entity | Action |
|--------|--------|
| `LostWaxWorkOrder`, plans, wips | Keep unchanged |
| `TreeGenerationService` | Keep for legacy only |
| `ProductionStatusController` | Keep dual-path rendering |
| `qty_actual_good` on lines | Keep as cached aggregate (do not remove) |
| All existing APIs | No breaking changes |
| `print_jobs` | Optionally add `tree_id` FK (nullable, backward-safe) |

---

## 16. Missing Test Coverage

| Test Scenario | Status |
|--------------|--------|
| Partial print execution (70 of 100) → outstanding 30 visible | ❌ Missing |
| Second execution (30 pcs) → outstanding 0, line COMPLETED | ❌ Missing |
| Prevent execution where `total + new > qty_ordered` | ❌ Missing |
| PrintExecution append-only (no edit, no delete) | ❌ Missing |
| PrintOrder auto-transitions to COMPLETED | ❌ Missing |
| PrintOrderLine status PENDING → IN_PROGRESS → COMPLETED | ❌ Missing |
| `print_execution_id` on tree links correctly | ❌ Missing |
| Concurrent outcome submissions (idempotency) | ❌ Missing |
| Outstanding dashboard shows partial orders | ❌ Missing |
| Layer 7 behavior for new-path trees | ❌ Missing |
| Multi-execution date-based reporting | ❌ Missing |

---

## 17. Summary: What Must Change

| Area | Current | Problem | Required Change | Priority |
|------|---------|---------|-----------------|----------|
| Print Execution Recording | Mutable single field | Destroys history | New `lost_wax_print_executions` table (append-only) | **CRITICAL** |
| Print Order Status | No COMPLETED | Orders never close | Add COMPLETED, auto-transition | **HIGH** |
| PrintOrderLine Status | No status field | Outstanding invisible | Add `execution_status` field | **HIGH** |
| Outstanding Work Dashboard | Does not exist | Supervisors blind to outstanding | New UI section | **HIGH** |
| Concurrent Outcome Recording | No idempotency | Double-write race | Execution uniqueness check | **HIGH** |
| PrintJob → Tree link | No FK | No print audit trail | Add `tree_id` FK to `print_jobs` | **MEDIUM** |
| `qty_remaining` on ProductionPlan | Orphaned | Misleading | Mark deprecated, compute dynamically | **MEDIUM** |
| `is_correctable` on new-path trees | Always false | Cannot correct new trees | Fix condition | **MEDIUM** |
| Layer 7 for new-path trees | Always skipped | Unknown intent | Confirm + add explicit flag | **MEDIUM** |

---

## 18. What Must NOT Change

| Component | Reason |
|-----------|--------|
| `print_jobs` table core columns | Python agent depends on this |
| Python Print Agent API | Working and deployed |
| `TsplRenderer` layout and TSPL coordinates | Proven on TSC TE244 |
| `LostWaxScanEvent` append-only design | Correct — must not become editable |
| `ScanService` tree-based progression | Correct — trees move independently |
| `LostWaxTree` barcode format | Used by existing physical labels |
| `AssemblyController` concurrency protection | Correctly prevents over-allocation |
| All legacy `LostWaxWorkOrder` tables | Required for backward compatibility |
| RBAC / permission system | Recently added, correctly scoped |

---

## 19. Open Business Questions (MUST ANSWER BEFORE CODING)

> [!IMPORTANT]
> The following questions MUST be answered before any implementation begins.

1. **Defect Semantics:** If `qty_defect = 5`, does that 5 need to be re-produced by the factory, or is it written off as a loss (customer gets fewer pieces)?

2. **Layer 7 for New Path:** Should any production-plan-sourced tree ever go through Layer 7? If yes, how is this determined at the line level?

3. **Rangkai Order:** Does the business want a formal "Rangkai Order" document with its own number, or is ad-hoc tree generation from the Assembly screen acceptable?

4. **Print Order Auto-Completion:** Should a Print Order automatically become COMPLETED once all lines have sufficient executions, or should a supervisor manually confirm completion?

5. **Execution Edit Window:** Can a recorded PrintExecution be edited within the same working day? Or is it immediately immutable once submitted?

6. **Multi-Line Print Order Partial Completion:** If a Print Order has 3 lines and only 2 are fully executed, is the order status `PARTIAL` or do we track status per-line only?

7. **Outstanding Work UI:** What format should the outstanding work dashboard use — a separate dedicated page, or inline alert badges on the existing Print Order list?

8. **Reprint Policy:** If a tree's thermal label fails to print (PrintJob fails), can it be reprinted from the tree detail page? Should we track "confirmed printed" separately from "sent to queue"?

---

## 20. Recommended Implementation Phases

| Phase | Scope | Risk |
|-------|-------|------|
| **PHASE 0** | Audit review, answer 8 business questions above | None |
| **PHASE 1** | Schema: add `lost_wax_print_executions`, `print_execution_id` on trees, `execution_status` on lines; backfill data | LOW |
| **PHASE 2** | Application: rewrite `OutcomeController` to insert into `PrintExecution`; add line/order status transitions; outstanding calculation | MEDIUM |
| **PHASE 3** | UI: Outstanding work dashboard, execution history list, status badges | LOW |
| **PHASE 4** | Rangkai Enhancement: link trees to execution; optionally add `lost_wax_rangkai_orders` | MEDIUM |
| **PHASE 5** | Fix `is_correctable` for new-path trees; Layer 7 rule for new path; `print_jobs.tree_id` FK | LOW |
| **PHASE 6** | Reporting: date-based execution reports, plan fulfillment status | LOW |
| **PHASE 7** | Oven downstream tracking (if needed) | MEDIUM |
| **PHASE 8** | Legacy deprecation: mark Work Order creation as read-only | LOW |

> [!CAUTION]
> Do NOT begin Phase 1 (schema changes) until the 8 business questions in Section 19 are answered and Phase 0 is formally approved.
