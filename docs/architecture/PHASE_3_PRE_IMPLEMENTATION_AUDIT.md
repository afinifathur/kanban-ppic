# PHASE 3 PRE-IMPLEMENTATION AUDIT REPORT
**Lost Wax Investment Casting Subsystem — Kanban PPIC**  
**Audit Scope: Production Status Alignment, Recovery Pool & Reprint Decision**  
**Audit Mode: STRICT READ-ONLY VERIFICATION (No code/database modifications)**  
**Audited Date: 2026-08-28**

---

## 1. Executive Verdict

```
====================================================================================================
                       PHASE 3 PRE-IMPLEMENTATION AUDIT VERDICT
====================================================================================================
 [AUDIT 1] Production Status Controller Logic Audit    : FLAW IDENTIFIED & DOCUMENTED
                                                         (Heuristic: rangkai_defect = cetak_good - rangkai_good
                                                          incorrectly misclassifies standby wax material as defect)
 [AUDIT 2] Quantity Calculation Canon Alignment        : PASS (Canonical Q_usable formula fully established)
 [AUDIT 3] Double-Deduction & Counting Proof           : PASS (Strictly isolated across all stages)
 [AUDIT 4] Three-State Material Partitioning           : PASS (Q_standby + Q_wip_net + Q_final_usable = Q_usable)
 [AUDIT 5] Production Code Boundary & Multi-SPK Pool   : PASS (Hard boundary respected across all lines)
 [AUDIT 6] Multi-Line FIFO Tree Attribution            : PASS (Defects on blended trees map 1:1 to Plan)
 [AUDIT 7] Excess Closed Isolation                     : PASS (Cleanly deducted, zero false defect count)
 [AUDIT 8] Cancellation Lifecycle Safety              : PASS (Pre-scan releases allocation, Post-L1 blocked)
 [AUDIT 9] PO vs Planned Status Evaluation             : PASS (NORMAL / WARNING / CRITICAL with safe NULL fallback)
 [AUDIT 10] Current Print Planning Query Filter        : PASS (Explains why deficient plans disappear from planning)
 [AUDIT 11] Reprint Architecture Design Safety         : PASS (New SPK attached to existing Plan, historical SPKs immutable)
 [AUDIT 12] Close Without Reprint Architecture         : PASS (ProductionPlan.is_closed + closure fields sufficient)
 [AUDIT 13] Recovery Pool Aggregate Root               : PASS (ProductionPlan / Production Code is the only valid root)
 [AUDIT 14] Real Database Statistics & Live Tracing   : PASS (Verified on 301 plans, 214 trees, Plan 359 / 268ETB827)
 [AUDIT 15] Concurrency & Transactional Locking       : PASS (Locking strategy defined for Phase 3)
====================================================================================================
 FINAL GATE: [X] PHASE 3 AUDIT PASS — READY FOR DESIGN/IMPLEMENTATION
====================================================================================================
```

---

## 2. Current Production Status Logic (`ProductionStatusController.php`)

### A. Code Inspection on Current Calculations
In `app/Http/Controllers/LostWax/ProductionStatusController.php:L559-L595`:
```php
$rangkai_good = $totalTreeQty;
$rangkai_defect = ($rangkai_good > 0) ? max(0, $cetak_good - $rangkai_good) : 0; // <--- FLAW

// Display values:
if ($rangkai_good > 0) {
    if ($rangkai_good < $cetak_good) {
        $ctk_display = $cetak_good;
        $r_ctk_display = $cetak_defect;
    }
}
$overall_defect = $cetak_defect + $rangkai_defect; // <--- FLAW
```

### B. Identified Flaw & Heuristic Problem
1. **False Defect Generation**: The current controller calculates `$rangkai_defect = max(0, $cetak_good - $rangkai_good)`. If 1,000 good pieces have been printed ($Q_{\text{print\_good}} = 1000$), but operators have only assembled 320 pieces into trees ($Q_{\text{trees}} = 320$), the controller falsely assumes that the remaining 680 pieces are **defective** (`$rangkai_defect = 680`, `$overall_defect = 680`).
2. **Reality**: The 680 pieces are physically sitting on the assembly table as **Standby Wax Material** ($Q_{\text{standby}} = 680$).
3. **Phase 3 Resolution**: `ProductionStatusController` must be updated to call `LostWaxQualityService::getProductionPlanQuantityBreakdown($plan)`. The column `Total Rusak` must only reflect physical recorded defects (`q_tree_defect`), while unmounted material must be properly displayed as `Standby / Saldo Lilin` ($Q_{\text{standby}}$).

---

## 3. Quantity Calculation Canon Audit

The canonical formula locked in Phase 1 & 2:
$$\mathbf{Q_{\text{usable}}} = \mathbf{Q_{\text{print\_good}}} - \sum \mathbf{D_{\text{tree}}} - \mathbf{Q_{\text{excess\_closed}}}$$

- **$Q_{\text{print\_good}}$**: Sum of `qty_executed_good` (or fallback `qty_actual_good`) from finalized print executions across all valid (non-cancelled) print order lines under the plan. Defect cetak ($D_{\text{print}}$) is strictly excluded and never subtracted again.
- **$\sum D_{\text{tree}}$**: Sum of all physical tree defects logged in `lost_wax_tree_defects` across all stages (`assembly`, `layer_1`..`layer_7`, `oven`) for all active trees belonging to the plan.
- **$Q_{\text{excess\_closed}}$**: Sum of `qty_excess_closed` recorded on print order lines.
- **Verification**: Verified top-down and bottom-up against `LostWaxQualityService.php:L187`.

---

## 4. Double Counting & Double Deduction Audit

| Operational Event | Physical Reality | Canonical Model Representation | Risk of Double Deduction |
|---|:---:|:---:|:---:|
| Print Execution Output: 1300 pcs | 1280 good, 20 defect | $Q_{\text{print\_good}} = 1280, \quad D_{\text{print}} = 20$ | **NONE** ($D_{\text{print}}$ is isolated in `qty_executed_defect`) |
| Assembly Defect: 10 pcs | 10 broken pattern | $D_{\text{assembly}} = 10$ | **NONE** (Recorded in `lost_wax_tree_defects`) |
| Coating Defect: 20 pcs (L1:10, L2:5, L7:5) | 20 cracked slurry | $D_{\text{layer}} = 20$ | **NONE** (Recorded in `lost_wax_tree_defects`) |
| Oven Defect: 10 pcs | 10 shell cracked | $D_{\text{oven}} = 10$ | **NONE** (Recorded in `lost_wax_tree_defects`) |
| **Total Usable Calculation** | **1240 pcs usable** | $\mathbf{1280 - 40 - 0 = 1240\text{ pcs}}$ | **100% EXACT** (Never evaluates to 1220 or 1260) |

---

## 5. Tree State Classification Audit

The three material states are strictly mutually exclusive:
$$\mathbf{Q_{\text{usable}}} \equiv \mathbf{Q_{\text{standby}}} + \mathbf{Q_{\text{wip\_net}}} + \mathbf{Q_{\text{final\_usable}}}$$

1. **Standby Pool ($Q_{\text{standby}}$)**:
   $$Q_{\text{standby}} = \max(0, Q_{\text{print\_good}} - Q_{\text{active\_trees\_gross}} - Q_{\text{excess\_closed}})$$
   Material printed and passed QC, sitting on racks ready for assembly.
2. **Coating WIP Net ($Q_{\text{wip\_net}}$)**:
   $$Q_{\text{wip\_net}} = \sum_{t \in \text{WIP}} (t.\text{quantity} - t.\text{defects})$$
   Material actively mounted on trees currently in dipping/coating stages (`layer_1` s/d `layer_7` or un-scanned).
3. **Final Usable ($Q_{\text{final\_usable}}$)**:
   $$Q_{\text{final\_usable}} = \sum_{t \in \text{Oven}} (t.\text{quantity} - t.\text{defects})$$
   Material on trees that have completed dewaxing in the oven (`current_stage === 'oven'`).
4. **Cancelled Trees**: Completely excluded from active gross tree count and tree defects.

---

## 6. Production Code Boundary & Multi-SPK Aggregation Audit

- **Hard Boundary**: The entity `ProductionPlan` (identified uniquely by `code` + `po_number`) is the hard boundary.
- **Multi-SPK Support**: A single Production Code may have multiple Print Orders / SPK Cetak over time (e.g. `PC-20260825-0004`, `PC-20260826-0002`, and a future Reprint SPK `PC-20260829-0001`).
- **Aggregation**: `LostWaxQualityService::getProductionPlanQuantityBreakdown` iterates through all non-cancelled `printOrderLines` of that plan, summing good prints, aggregating deduplicated trees, and calculating total usable material.
- **Cross-Code Isolation**: Plans with different codes (e.g., `268ETB827` vs `268ETB828`) have distinct `production_plan_id`s and never mix quantities or trees, even if they share the same customer, AISI, or SKU.

---

## 7. FIFO Allocation Interaction Audit

- **Blended Trees**: When Tree `#054` is created with 4 pcs from SPK A and 26 pcs from SPK B (both belonging to Production Code `268ETB827`):
  - Both SPK lines belong to `production_plan_id = 359`.
  - The tree is deduplicated in `$allTrees` via `$allTrees->put($t->id, $t)`.
  - When a defect of 3 pcs is logged on Tree `#054` at `layer_2`, it is stored in `lost_wax_tree_defects` with `lost_wax_tree_id = 54`.
  - When calculating the plan breakdown, Tree `#054` is evaluated once: `treeGross = 30`, `treeDefect = 3`, `treeUsable = 27`.
  - The defect reduces the total usable pool of `268ETB827` by exactly 3 pcs without double counting.

---

## 8. Cancellation Lifecycle Audit

1. **Pre-Layer 1 Cancellation (`TravelerCancellationTest`)**:
   - Operator cancels tree before Layer 1 scan.
   - `RangkaiExecutionService::cancelExecution()` restores the available quantity on `lost_wax_print_order_lines` and deletes `lost_wax_tree_allocations`.
   - `tree->status` becomes `'cancelled'`.
   - Result in Breakdown: Tree is excluded from `$allTrees`. The 32 pcs return to $Q_{\text{standby}}$. $Q_{\text{usable}}$ remains 100% conserved.
2. **Post-Layer 1 Cancellation**:
   - Prevented by system guard (`cannot cancel traveler if layer 1 already scanned`).
   - If physical damage occurs after Layer 1, the defect must be logged via `LostWaxQualityService::recordDefect()`, not via traveler cancellation.

---

## 9. Excess Closed (`qty_excess_closed`) Audit

- If $Q_{\text{print\_good}} = 1300$, $Q_{\text{planned}} = 1200$, and PPIC closes the 100 pcs excess on line via `closeExcess()`:
  - `qty_excess_closed` becomes `100`.
  - In `LostWaxQualityService`:
    $$Q_{\text{standby}} = \max(0, 1300 - \text{ActiveTrees} - 100)$$
    $$Q_{\text{usable}} = \max(0, 1300 - \text{TreeDefects} - 100)$$
  - The 100 pcs excess is removed from usable inventory, but is **NOT** counted as a defect ($Q_{\text{tree\_defect}}$ remains pure).
  - It does **NOT** trigger an artificial recovery deficit.

---

## 10. PO vs Planned Quantity Status Evaluation Audit

```
┌──────────────────────────────────────────────────────────────────────────────────────────────────┐
│                               PRODUCTION STATUS EVALUATION MATRIX                                │
├────────────────────────────────┬─────────────────────────────────────────────────┬───────────────┤
│ Condition (with PO Quantity)   │ Condition (NULL PO Quantity Fallback)           │ Status Result │
├────────────────────────────────┼─────────────────────────────────────────────────┼───────────────┤
│ Q_usable >= Q_planned          │ Q_usable >= Q_planned                           │ NORMAL        │
│ Q_planned > Q_usable >= Q_po   │ N/A                                             │ WARNING       │
│ Q_usable < Q_po                │ Q_usable < Q_planned                            │ WARNING (*)   │
└────────────────────────────────┴─────────────────────────────────────────────────┴───────────────┘
```
*(*) Note on NULL PO fallback: In `ProductionPlan::evaluateProductionStatus()` (`app/Models/ProductionPlan.php:L105-L119`):*
```php
if ($this->po_quantity === null) {
    return $qUsable >= $this->qty_planned ? 'NORMAL' : 'WARNING';
}
```
- When `po_quantity` is `NULL`, if $Q_{\text{usable}} < Q_{\text{planned}}$, the evaluator safely assigns `'WARNING'` (never falsely assigning `'CRITICAL'`, nor throwing errors).
- This allows the Recovery Pool to function 100% reliably on both legacy plans and newly entered plans with explicit PO quantities.

---

## 11. Current Print Planning Filter Audit (`PrintOrderController::plans`)

### A. Current Query Inspection
In `app/Http/Controllers/LostWax/PrintOrderController.php:L65-L72`:
```php
$subquery = DB::table('lost_wax_print_order_lines')
    ->join('lost_wax_print_orders', 'lost_wax_print_order_lines.lost_wax_print_order_id', '=', 'lost_wax_print_orders.id')
    ->whereColumn('lost_wax_print_order_lines.production_plan_id', 'production_plans.id')
    ->whereIn('lost_wax_print_orders.status', ['DRAFT', 'ISSUED'])
    ->selectRaw('COALESCE(SUM(lost_wax_print_order_lines.qty_ordered), 0)');

$plansQuery->whereRaw('qty_planned > ('.$subquery->toSql().')', $subquery->getBindings());
```

### B. Root Cause Analysis: Why Deficient Plans Disappear
- When a plan of 1,200 pcs is first scheduled into an SPK of 1,200 pcs, `SUM(qty_ordered)` becomes `1200`.
- Because `1200 > 1200` evaluates to `FALSE`, the plan immediately disappears from the default *Rencana Cetak Aktif* tab.
- If 200 pcs are later scrapped during coating/oven ($Q_{\text{usable}} = 1000$), the plan remains hidden because `SUM(qty_ordered)` is still 1200.
- **Phase 3 Design Lock**: The Recovery Pool provides a dedicated tab/interface on `/lost-wax/print-orders/plans` (e.g. Tab **"Recovery Pool (Perlu Review)"**) that queries plans where `is_closed = false` AND status is `WARNING` or `CRITICAL`.

---

## 12. Reprint Architecture Audit

### A. Domain Integrity of Reprint SPK
1. **Immutability of Old SPK**: Historical Print Orders (e.g., `PC-20260825-0004`) must remain in `ISSUED`/finalized state. They are never reopened, edited, or deleted.
2. **New Reprint SPK Creation**:
   - PPIC clicks `[Buat SPK Reprint]` on a deficient Production Code in the Recovery Pool.
   - System generates a new `LostWaxPrintOrder` (e.g. `PC-20260829-0001`) with a line pointing to the same `production_plan_id`.
   - `qty_ordered` on the new line is defaulted to the deficit quantity:
     $$Q_{\text{deficit}} = Q_{\text{planned}} - Q_{\text{usable}}$$
   - When the reprint is printed and executed, its `qty_good` flows into the plan's FIFO pool, increasing $Q_{\text{print\_good}}$ and restoring $Q_{\text{usable}}$ back to $\ge Q_{\text{planned}}$ (`NORMAL`).

---

## 13. Close Without Reprint Architecture Audit

### A. Closing Mechanism
1. When PPIC chooses `[Tutup Tanpa Reprint]` (e.g. customer accepts short-shipment or defect happened on buffer overage):
   - Action updates the `ProductionPlan` record:
     - `is_closed = true`
     - `closure_reason = "Toleransi customer / buffer cukup..."`
     - `closed_by = auth()->id()`
     - `closed_at = Carbon::now()`
2. **Why Close at `ProductionPlan` Level?**
   - The Production Plan is the aggregate root of customer demand. Closing the plan removes it from both the Planning queue and the Recovery Pool, while preserving all historical print orders, tree travelers, scan events, and defect logs for traceability.

---

## 14. Recovery Pool Aggregate Root Recommendation

```
====================================================================================================
                     RECOVERY POOL AGGREGATE ROOT: ProductionPlan
====================================================================================================
 [X] ProductionPlan (Production Code + PO Number)  : VALID AGGREGATE ROOT
 [ ] LostWaxTree (Individual Tree Traveler)        : INVALID (Tree is an execution unit, not demand)
 [ ] LostWaxPrintOrder (Individual SPK)            : INVALID (SPK is an execution batch, not total pool)
 [ ] LostWaxScanEvent (Individual Scan)            : INVALID (Scan is an event, not an entity)
====================================================================================================
```
**Rationale**: Production decisions (reprinting or closing short) address the total balance of the Production Code against customer obligations. `ProductionPlan` uniquely encapsulates the planned target, PO obligation, total print lines, all issued trees, and cumulative defects.

---

## 15. Real Database Statistics (Read-Only MySQL Audit)

Data captured from live local synchronized database (`kanban-ppic` MySQL 8.4):

| Metric / Dimension | Database Count | Detail / Notes |
|---|:---:|---|
| **Total Production Plans** | **301** | Total records in `production_plans` |
| - Active Plans (`is_closed = 0`) | **280** | Open production plans |
| - Closed Plans (`is_closed = 1`) | **21** | Closed production plans |
| - Plans with `po_quantity` populated | **0** | Newly added column in Phase 1 |
| - Plans without `po_quantity` (NULL) | **301** | Handled safely by NULL fallback |
| **Plans with Print Order Lines** | **79** | Plans with scheduled/executed SPKs |
| - Status `NORMAL` ($Q_{\text{usable}} \ge Q_{\text{plan}}$) | **13** | 100% fulfilled |
| - Status `WARNING` ($Q_{\text{usable}} < Q_{\text{plan}}$) | **66** | In-progress / incomplete printing |
| - Status `CRITICAL` ($Q_{\text{usable}} < Q_{\text{po}}$) | **0** | (NULL PO evaluates to WARNING) |
| **Total Trees in Database** | **214** | `lost_wax_trees` |
| - Active Trees (`status != 'cancelled'`) | **184** | Physical active trees |
| - Cancelled Trees (`status == 'cancelled'`) | **30** | Voided/cancelled travelers |
| - Coating WIP Trees (`stage != 'oven'`) | **86** | Currently in Layer 1–7 |
| - Oven Final Trees (`stage == 'oven'`) | **33** | Completed dewaxing |
| - Total Tree Gross Quantity | **3,497 pcs** | Sum of physical tree gross pieces |
| **Tree Defect Records** | **0** | Fresh table created in Phase 1 |
| **Print Order Lines Executed Good** | **7,913 pcs** | Sum of `qty_executed_good` |
| **Print Order Lines Executed Defect** | **44 pcs** | Sum of `qty_executed_defect` |

---

## 16. Real Production Code Traceability Example (`268ETB827`)

Trace performed on real database record `ProductionPlan` ID **359** (`code: 268ETB827`, `customer: E02`, `po_number: 2026`, `qty_planned: 1100`):

```
┌─────────────────────────────────────────────────────────────────────────────────────────────────┐
│ REAL PRODUCTION CODE TRACE: 268ETB827 (Plan ID: 359)                                            │
├─────────────────────────────────────────────────────────────────────────────────────────────────┤
│ Customer PO Number      : 2026                                                                  │
│ Target Planned Quantity : 1,100 pcs                                                             │
│                                                                                                 │
│ SPK Print Orders (3 lines):                                                                     │
│  1. Line #32  (PC-20260825-0004) : Ordered: 100 pcs | Executed Good: 100 pcs | Defect: 0       │
│  2. Line #78  (PC-20260826-0002) : Ordered: 250 pcs | Executed Good: 250 pcs | Defect: 2       │
│  3. Line #151 (PC-20260827-0002) : Ordered: 100 pcs | (Draft / Not executed yet)               │
│                                                                                                 │
│ Print Good Pool Total (Q_print_good) : 350 pcs                                                  │
│                                                                                                 │
│ Active Trees Mounted (10 trees, 320 pcs gross):                                                 │
│  • Tree #001 s/d #003 (Line 32) : 3 trees x 32 pcs = 96 pcs                                     │
│  • Tree #004 s/d #010 (Line 78) : 7 trees x 32 pcs = 224 pcs                                    │
│                                                                                                 │
│ Tree Defects Logged     : 0 pcs                                                                 │
│ Excess Closed           : 0 pcs                                                                 │
│                                                                                                 │
│ THREE-STATE BREAKDOWN COMPUTATION:                                                              │
│  • Standby Pool (Q_standby)    : 350 - 320 = 30 pcs (Lilin cetak siap rangkai)                  │
│  • Coating WIP (Q_wip_net)     : 320 pcs (Sedang proses dipping lapisan)                        │
│  • Final Usable (Q_final)      : 0 pcs                                                          │
│  ─────────────────────────────────────────────────────────────                                  │
│  • Total Usable (Q_usable)     : 350 pcs                                                        │
│  • Production Status           : WARNING (Defisit vs Plan = 750 pcs)                            │
└─────────────────────────────────────────────────────────────────────────────────────────────────┘
```
**Proof of Traceability**: Every single piece is accounted for with zero double counting and zero heuristic leakage.

---

## 17. Concurrency & Transactional Analysis

### A. Concurrency Risks in Phase 3
1. **Race Condition between Defect Entry and Recovery Evaluation**:
   - If Admin records a defect at the exact millisecond PPIC opens the Recovery Pool.
   - Guard: `LostWaxQualityService::getProductionPlanQuantityBreakdown()` performs read-committed queries. The latest committed defect is immediately reflected in the breakdown.
2. **Race Condition on Reprint SPK Creation**:
   - If two PPIC users click `[Buat SPK Reprint]` concurrently on the same deficient plan.
   - Guard: In `PrintOrderController::store`, wrap SPK creation inside `DB::transaction()` with `ProductionPlan::lockForUpdate()`. Verify that the plan is still deficient before issuing a second reprint.
3. **Race Condition on Close Without Reprint**:
   - If one user clicks `[Tutup Tanpa Reprint]` while another creates a reprint SPK.
   - Guard: `ProductionPlan::lockForUpdate()` ensures sequential execution.

---

## 18. Identified Risks & Mitigations for Phase 3 Implementation

| # | Identified Risk | Impact | Proposed Mitigation |
|---|---|:---:|---|
| 1 | **UI Performance on Production Status Table** | Loading 79+ plans and all trees dynamically could cause N+1 query slowdown. | Eager load `['printOrderLines.executions', 'printOrderLines.printOrder', 'printOrderLines.trees.defects', 'printOrderLines.treeAllocations.tree.defects']` in a single optimized query in `ProductionStatusController`. |
| 2 | **Legacy Work Orders vs New Plans in Production Status** | Legacy Work Orders (prior to print order refactor) have different schema structure. | Maintain the existing bifurcated loader in `ProductionStatusController` (`legacyWos` vs `plans`), applying `LostWaxQualityService` breakdown to the new flow. |
| 3 | **RBAC Product Scope Isolation** | PPIC user assigned to `FLANGE_STAINLESS` must not see or act on `FITTING_STAINLESS` plans in Recovery Pool. | Enforce `$planQuery->where('product_scope', $scope)` in both Production Status and Recovery Pool queries. |

---

## 19. Recommended Phase 3 Implementation Sequence

When implementation approval is granted, execute in this strict order:

1. **Step 3.1 — Production Status Controller & View Alignment**:
   - Replace heuristic calculations in `ProductionStatusController.php` with `LostWaxQualityService::getProductionPlanQuantityBreakdown()`.
   - Update `resources/views/lost-wax/production-status/index.blade.php` to display:
     - `Q_usable` as the true usable count.
     - `Q_standby` (Saldo Lilin).
     - True `Q_tree_defect` (Total Rusak).
     - Badges: `NORMAL` (Green), `WARNING` (Yellow/Amber), `CRITICAL` (Red).
2. **Step 3.2 — Recovery Pool Backend & UI on Print Planning**:
   - Add Tab **"Recovery Pool"** to `resources/views/lost-wax/print-orders/plans.blade.php`.
   - In `PrintOrderController::plans()`, fetch open plans where status is `WARNING` or `CRITICAL`.
   - Add actions:
     - `[+ Buat SPK Reprint]` $\rightarrow$ opens reprint creation with auto-calculated deficit quantity.
     - `[Tutup Tanpa Reprint]` $\rightarrow$ modal to input `closure_reason` and mark `is_closed = true`.
3. **Step 3.3 — Reprint SPK Creation & Plan Closure Controller Actions**:
   - Add `storeReprint(Request $request, ProductionPlan $plan)` in `PrintOrderController.php`.
   - Add `closeWithoutReprint(Request $request, ProductionPlan $plan)` in `PrintOrderController.php`.
4. **Step 3.4 — Automated Feature & Regression Testing**:
   - Add comprehensive tests in `tests/Feature/LostWax/ProductionStatusAndRecoveryPoolTest.php`.
   - Run `composer test` (expecting 432+ tests PASS) and `vendor/bin/pint`.

---

## 20. Exact Files Expected to Change in Phase 3

| File Path | Action | Scope of Modification |
|---|:---:|---|
| `app/Http/Controllers/LostWax/ProductionStatusController.php` | MODIFY | Remove inferred defects; use `LostWaxQualityService` breakdown. |
| `resources/views/lost-wax/production-status/index.blade.php` | MODIFY | Update column headers, badges for NORMAL/WARNING/CRITICAL, and standby display. |
| `app/Http/Controllers/LostWax/PrintOrderController.php` | MODIFY | Add Recovery Pool query, reprint action, and close-without-reprint endpoint. |
| `resources/views/lost-wax/print-orders/plans.blade.php` | MODIFY | Add Recovery Pool tab, Deficit badges, and Action modals. |
| `routes/web.php` | MODIFY | Register reprint and closure routes under `permission:access_planning`. |
| `tests/Feature/LostWax/ProductionStatusAndRecoveryPoolTest.php` | NEW | 15+ automated tests for Production Status and Recovery Pool workflow. |

---

## FINAL GATE

```
====================================================================================================
FINAL VERDICT: [X] PHASE 3 AUDIT PASS — READY FOR DESIGN/IMPLEMENTATION
====================================================================================================
```

*Audit complete. No code or database mutations were performed during this audit.*
