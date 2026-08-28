# PHASE 3.3 STEP 2 — PRODUCTION PLAN DELETE & PARENT INTEGRITY ADVERSARIAL AUDIT
**Strict Read-Only Lifecycle & Parent-Child Referential Integrity Audit**

---

## 1. Executive Summary

| Audit Dimension | Status / Finding | Severity |
|---|---|:---:|
| **Controller Delete Guard** | Only checks `production_items` (Cor legacy). Does NOT check any Lost Wax entities (`printOrderLines`, `executions`, `trees`, `defects`, `reprints`, `is_closed`). | **CRITICAL** |
| **MySQL Foreign Key Action** | `lost_wax_print_order_lines.production_plan_id` is configured with `ON DELETE SET NULL`. Database does NOT reject parent deletion. | **CRITICAL** |
| **Deletion Mechanism** | Hard delete (`DELETE FROM production_plans`). `ProductionPlan` does NOT use `SoftDeletes`. | **HIGH** |
| **Downstream Data Orphan Risk** | Deleting a `ProductionPlan` leaves Print Orders, Executions, Trees, Tree Allocations, Tree Defects, and Reprint SPKs orphaned with `production_plan_id = NULL`. | **CRITICAL** |
| **Business Impact** | Permanent loss of PO Number, PO Quantity, Customer, and Production Code linkage for historical production batches. | **CRITICAL** |
| **Audit Gate Verdict** | **[FAIL] — CRITICAL VULNERABILITY CONFIRMED IN EXISTING DELETE LIFECYCLE** | **CRITICAL** |

---

## 2. Audit Scope

- **Object Under Audit**: Lifecycle deletion of `App\Models\ProductionPlan` across all endpoints (`/plan/{id}`, `/kanban/rencana_cor/{id}`).
- **Mode**: **STRICT READ-ONLY**. No source code, database tables, constraints, routes, or historical data were modified.
- **Verification Method**: Information Schema inspection, Static Code Analysis, Eloquent model mapping, and isolated transaction-rollback execution on MySQL.

---

## 3. ProductionPlan Dependency & Lineage Graph

```
                            ProductionPlan (Parent Aggregate Root)
                            [code, po_number, po_quantity, qty_planned]
                                            │
               ┌────────────────────────────┴────────────────────────────┐
               ▼                                                         ▼
     [LEGACY COR DOMAIN]                                        [LOST WAX DOMAIN]
       production_items                                    lost_wax_print_order_lines
    (plan_id: ON DELETE SET NULL)                 (production_plan_id: ON DELETE SET NULL)
                                                                         │
                                                ┌────────────────────────┼────────────────────────┐
                                                ▼                        ▼                        ▼
                                      lost_wax_print_orders    lost_wax_print_executions   lost_wax_tree_allocations
                                      [order_type: REGULAR/    [qty_good, qty_defect,      [allocated_qty]
                                       REPRINT, cycle]          status: FINALIZED]                │
                                                                                                  ▼
                                                                                           lost_wax_trees
                                                                                           [barcode, usable_qty, stage]
                                                                                                  │
                                                                         ┌────────────────────────┴────────────────────────┐
                                                                         ▼                                                 ▼
                                                               lost_wax_tree_defects                             lost_wax_scan_events
                                                               [defect_qty, stage, reason]                       [layer_1..7, oven]
```

---

## 4. Eloquent Relationships

In `app/Models/ProductionPlan.php`:
1. `items()` $\rightarrow$ `hasMany(ProductionItem::class, 'plan_id')` *(Legacy Cor)*
2. `printOrderLines()` $\rightarrow$ `hasMany(LostWaxPrintOrderLine::class, 'production_plan_id')` *(Lost Wax)*
3. `closedBy()` $\rightarrow$ `belongsTo(User::class, 'closed_by')`

**Gap**: There is no direct Eloquent relationship defined from `ProductionPlan` to `LostWaxPrintOrder`, `LostWaxPrintExecution`, `LostWaxTree`, `LostWaxTreeDefect`, or `LostWaxTreeAllocation`. All traversals must go through `printOrderLines`.

---

## 5. Actual MySQL Foreign Keys

Direct query results from `INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS` & `KEY_COLUMN_USAGE`:

| Child Table | Foreign Key Column | Parent Table & Column | ON DELETE Rule | ON UPDATE Rule | Constraint Name |
|---|---|---|:---:|:---:|---|
| `lost_wax_print_order_lines` | `production_plan_id` | `production_plans.id` | **`SET NULL`** | `NO ACTION` | `lost_wax_print_order_lines_production_plan_id_foreign` |
| `production_items` | `plan_id` | `production_plans.id` | **`SET NULL`** | `NO ACTION` | `production_items_plan_id_foreign` |
| `lost_wax_print_order_lines` | `lost_wax_print_order_id` | `lost_wax_print_orders.id` | **`CASCADE`** | `NO ACTION` | `lost_wax_print_order_lines_lost_wax_print_order_id_foreign` |
| `lost_wax_print_executions` | `lost_wax_print_order_line_id` | `lost_wax_print_order_lines.id` | **`CASCADE`** | `NO ACTION` | `fk_lw_pe_line` |
| `lost_wax_tree_allocations` | `lost_wax_print_order_line_id` | `lost_wax_print_order_lines.id` | **`RESTRICT`** | `NO ACTION` | `lost_wax_tree_allocations_lost_wax_print_order_line_id_foreign` |
| `lost_wax_tree_allocations` | `lost_wax_tree_id` | `lost_wax_trees.id` | **`CASCADE`** | `NO ACTION` | `lost_wax_tree_allocations_lost_wax_tree_id_foreign` |
| `lost_wax_tree_defects` | `lost_wax_tree_id` | `lost_wax_trees.id` | **`CASCADE`** | `NO ACTION` | `lost_wax_tree_defects_lost_wax_tree_id_foreign` |
| `lost_wax_scan_events` | `tree_id` | `lost_wax_trees.id` | **`CASCADE`** | `NO ACTION` | `lost_wax_scan_events_tree_id_foreign` |
| `lost_wax_trees` | `lost_wax_print_order_line_id` | `lost_wax_print_order_lines.id` | **`SET NULL`** | `NO ACTION` | `lost_wax_trees_lost_wax_print_order_line_id_foreign` |

### Critical Foreign Key Finding:
Because `lost_wax_print_order_lines.production_plan_id` has `ON DELETE SET NULL`, deleting a row in `production_plans` **never triggers a MySQL foreign key constraint failure (`RESTRICT`)**. The database silently sets `production_plan_id = NULL` on all child lines.

---

## 6. Current Delete Controller Logic

In `app/Http/Controllers/PlanController.php` (lines 227–242):

```php
public function destroy(ProductionPlan $plan)
{
    $user = auth()->user();
    if ($user->hasRole('ppic') && $user->product_scope && $plan->product_scope !== $user->product_scope) {
        abort(403, 'Unauthorized.');
    }

    // Check if there are items associated with this plan
    if ($plan->items()->exists()) {
        return back()->with('error', 'Tidak bisa menghapus rencana yang sudah memiliki data produksi.');
    }

    $plan->delete();

    return back()->with('success', 'Rencana berhasil dihapus.');
}
```

### Flaws in Current Controller Logic:
1. **Blind to Lost Wax**: It only checks `$plan->items()->exists()` (`production_items`).
2. **Ignores Print Orders**: Does not check `$plan->printOrderLines()->exists()`.
3. **Ignores Reprints**: Does not check if the plan has active or completed reprint cycles.
4. **Ignores Closed Plans**: Does not check `$plan->is_closed`.

---

## 7. Current Delete Service Logic

There is **no dedicated Domain Service** handling the deletion of `ProductionPlan`. The deletion is invoked directly as Eloquent `$plan->delete()` in `PlanController::destroy`.

---

## 8. Existing "Has Production Data" Rule

The existing message:
> *"Tidak bisa menghapus rencana yang sudah memiliki data produksi."*

is triggered **only** when `production_items.plan_id` matches `$plan->id`.  
Because Step 1 detached Lost Wax from `production_items`, a brand new Lost Wax plan with 100 Print Orders and 5,000 Wax Trees will evaluate `$plan->items()->exists() === false` and **allow immediate, unrestricted deletion**.

---

## 9. Lost Wax Delete Matrix (Adversarial Simulation Results)

| Case | Scenario | Controller Permits Delete? | MySQL Permits Delete? | Child Records Result | Traceability Impact | Risk Level |
|:---:|---|:---:|:---:|---|---|:---:|
| **CASE 1** | Empty Plan (No SPK, no items) | **YES** | **YES** | No children | Clean deletion | **NONE (SAFE)** |
| **CASE 2** | Plan + PO Only (No SPK) | **YES** | **YES** | No children | Clean deletion | **LOW** |
| **CASE 3** | Plan + Regular Print Order | **YES** (Vulnerability) | **YES** (`SET NULL`) | `print_order_lines.production_plan_id` $\rightarrow$ `NULL` | SPK becomes orphan | **CRITICAL** |
| **CASE 4** | Plan + Print Order Line | **YES** (Vulnerability) | **YES** (`SET NULL`) | `production_plan_id` $\rightarrow$ `NULL` | Order Line loses parent | **CRITICAL** |
| **CASE 5** | Plan + Finalized Print Execution | **YES** (Vulnerability) | **YES** (`SET NULL`) | Execution intact, Plan deleted | Good/Defect wax loses parent order | **CRITICAL** |
| **CASE 6** | Plan + Wax Tree | **YES** (Vulnerability) | **YES** (`SET NULL`) | Tree intact, Plan deleted | Physical tree loses PO & Plan | **CRITICAL** |
| **CASE 7** | Plan + Tree Allocation | **YES** (Vulnerability) | **YES** (`SET NULL`) | Allocation intact, Plan deleted | Multi-line allocation detached | **CRITICAL** |
| **CASE 8** | Plan + Tree Defect | **YES** (Vulnerability) | **YES** (`SET NULL`) | Defect intact, Plan deleted | Defect quality history orphaned | **CRITICAL** |
| **CASE 9** | Plan + Layer 1–7 WIP | **YES** (Vulnerability) | **YES** (`SET NULL`) | Tree & Scans intact, Plan deleted | Slurry tracking loses order | **CRITICAL** |
| **CASE 10** | Plan + Oven Scanned | **YES** (Vulnerability) | **YES** (`SET NULL`) | Tree in Oven intact, Plan deleted | Ready-to-cast wax loses parent | **CRITICAL** |
| **CASE 11** | Plan + Recovery Reprint | **YES** (Vulnerability) | **YES** (`SET NULL`) | Reprint SPK intact, Plan deleted | Reprint cycle loses target plan | **CRITICAL** |
| **CASE 12** | Plan + Closed (`is_closed=true`) | **YES** (Vulnerability) | **YES** (`SET NULL`) | Plan permanently deleted | Audit trail of closure destroyed | **CRITICAL** |

---

## 10. Print Order Parent Integrity

- `lost_wax_print_orders` references `created_by` (`users.id`).
- `lost_wax_print_order_lines` links `lost_wax_print_order_id` $\rightarrow$ `lost_wax_print_orders.id` (CASCADE) and `production_plan_id` $\rightarrow$ `production_plans.id` (SET NULL).
- **Consequence of Delete**: The Print Order document still exists in `lost_wax_print_orders`, but its lines lose their `production_plan_id`. The Print Order can no longer aggregate against any `ProductionPlan` in Production Status or Recovery Pool.

---

## 11. Tree Parent Integrity

- `lost_wax_trees` links to `lost_wax_print_order_line_id` (SET NULL).
- `lost_wax_tree_allocations` links `lost_wax_tree_id` (CASCADE) and `lost_wax_print_order_line_id` (RESTRICT).
- **Consequence of Delete**: Trees remain in the database with their barcode and stage progress, but tracing back from Tree $\rightarrow$ Allocation $\rightarrow$ Print Order Line $\rightarrow$ Production Plan fails because `production_plan_id` is `NULL`.

---

## 12. Defect Parent Integrity

- `lost_wax_tree_defects` is linked to `lost_wax_trees.id` (CASCADE).
- **Consequence of Delete**: Defects are not deleted because the Tree is not deleted. However, calculating the defect rate or recovery deficit for the `ProductionPlan` becomes impossible because the parent plan is gone.

---

## 13. Reprint Parent Integrity

- In Phase 3.2, reprint orders have `order_type = 'REPRINT'`, `reprint_cycle = N`, and lines referencing `production_plan_id`.
- **Consequence of Delete**: If the parent `ProductionPlan` is deleted, the reprint order lines have `production_plan_id` set to `NULL`. The Recovery Pool cannot track whether the reprint was completed or calculate `q_reprint_ordered`.

---

## 14. FIFO & Multi-SPK Integrity

In real multi-SPK batches (e.g. `268ETB827` where SPK 1 = 4 pcs, SPK 2 = 26 pcs, combined into 1 Tree of 30 pcs):
- Deleting `ProductionPlan` severs both SPK 1 and SPK 2 lines simultaneously.
- The shared Tree allocation loses its connection to the overall target order.

---

## 15. Closed Plan Behavior (`is_closed = true`)

- When a plan is closed via `Tutup Rencana` (with reason and authorized user ID), it represents a binding management decision (e.g. *"Toleransi customer 3 pcs"*).
- Currently, **there is zero check on `is_closed` in `PlanController::destroy`**.
- Deleting a closed plan completely erases the historical decision, closure reason, timestamp, and closer user ID.

---

## 16. Soft Delete vs Hard Delete

- `ProductionPlan` **does NOT use `Illuminate\Database\Eloquent\SoftDeletes`**.
- There is no `deleted_at` column in `production_plans`.
- Any delete operation is an irreversible **`DELETE FROM production_plans WHERE id = ?`**.

---

## 17. Isolated Delete Experiment Results

The experiment executed on the live schema under an isolated transaction confirmed:
```
CASE 1 (Empty Plan): DELETED - Controller permitted delete and DB executed delete
CASE 2 (Plan + PO Only): DELETED - Controller permitted delete and DB executed delete
CASE 3 (Plan + Print Order & Line): DELETED | Line production_plan_id after delete: NULL
CASE 5 (Plan + Finalized Execution): DELETED | Line production_plan_id: NULL | Exec exists: YES
CASE 6-10 (Plan + Tree + Alloc + Defect + Oven): DELETED | Line production_plan_id: NULL | Tree exists: YES | Defect exists: YES
CASE 11 (Plan + Recovery Reprint): DELETED | Reprint Order status: DRAFT | Reprint Line production_plan_id: NULL
CASE 12 (Closed Plan is_closed=true): DELETED - Controller permitted delete and DB executed delete
```
*Zero database errors were thrown by MySQL because of `ON DELETE SET NULL`.*

---

## 18. Historical Traceability & Reporting Risk

If a `ProductionPlan` with production history is deleted:
1. **Production Status Table (`/lost-wax/production-status`)**: The batch disappears completely from the dashboard.
2. **Recovery Pool (`/lost-wax/print-orders/plans?tab=recovery`)**: The plan disappears from recovery monitoring.
3. **Excel Production Status Export**: Missing entire batch metrics.
4. **Physical Wax in Factory**: Trees on racks or in oven have barcodes pointing to orphaned lines.

---

## 19. Security & Authorization Risk

Any authenticated user with the `ppic` role within their `product_scope` (or Admin) can send a `DELETE /plan/{id}` request and delete an active, in-progress, or closed production plan, immediately destroying all parent linkages across the Lost Wax factory.

---

## 20. Explicit Answers to Audit Questions (Q1 – Q8)

### Q1: Jika ProductionPlan sudah memiliki Print Order / SPK, apakah saat ini masih bisa dihapus?
> **YA, BISA.** `PlanController::destroy` tidak memeriksa keberadaan Print Order / SPK Lost Wax.

### Q2: Jika bisa dihapus, apakah Print Order kehilangan parent `production_plan_id`?
> **YA.** Kolom `lost_wax_print_order_lines.production_plan_id` otomatis diubah menjadi `NULL` oleh MySQL (`ON DELETE SET NULL`).

### Q3: Jika ProductionPlan sudah memiliki Tree, apakah Tree kehilangan lineage parent?
> **YA.** Tree dan Tree Allocation kehilangan sambungan ke `ProductionPlan`.

### Q4: Jika sudah ada Defect, apakah defect menjadi orphan atau ikut terhapus?
> **MENJADI ORPHAN.** Defect tetap ada di tabel `lost_wax_tree_defects`, tetapi lineage ke `ProductionPlan` terputus.

### Q5: Jika sudah ada Reprint SPK, apakah Reprint kehilangan parent?
> **YA.** `lost_wax_print_order_lines.production_plan_id` pada baris Reprint menjadi `NULL`.

### Q6: Apakah database FK sudah melindungi parent-child relationship, atau hanya controller?
> **TIDAK ADA PERLINDUNGAN DI DATABASE MAUPUN CONTROLLER.** Database FK memakai `SET NULL` (bukan `RESTRICT`), dan controller hanya memeriksa `production_items` (Cor).

### Q7: Apakah delete rule saat ini hanya memeriksa ProductionItem Cor dan tidak mengetahui Lost Wax downstream?
> **BENAR.** Rule saat ini 100% hanya memeriksa `$plan->items()->exists()` (tabel `production_items`).

### Q8: Apa kondisi paling awal yang seharusnya membuat ProductionPlan menjadi non-deletable?
> **Kondisi Titik Beku (Freeze Point)**:
> - **Draft / Empty Plan (Zero SPK)**: `DELETABLE` (boleh dihapus).
> - **Saat SPK Pertama Dibuat (`printOrderLines()->exists()`)**: **`NON-DELETABLE` (HARUS DIBLOKIR MUTLAK)**.

---

## 21. Recommended Future Business Rules (For Step 3 / Future Implementation)

```
                                    DELETE REQUEST
                                          │
                                          ▼
                         Apakah plan->printOrderLines()->exists()?
                                         / \
                                   YES  /   \  NO
                                       /     \
                                      ▼       ▼
                               BLOCKED (422)  Apakah plan->items()->exists()? (Legacy Cor)
                          "Tidak bisa hapus;             / \
                           rencana sudah           YES  /   \  NO
                           memiliki SPK"               /     \
                                                      ▼       ▼
                                               BLOCKED (422)  ALLOW DELETE
```

1. **Application Guard**:
   - Blokir jika `$plan->printOrderLines()->exists()`.
   - Blokir jika `$plan->is_closed`.
   - Blokir jika `$plan->items()->exists()` (legacy Cor).
2. **Database Integrity Guard**:
   - Ubah foreign key `lost_wax_print_order_lines.production_plan_id` dari `ON DELETE SET NULL` menjadi `ON DELETE RESTRICT`.

---

## 22. Findings Severity Matrix

| Finding | Severity | Description |
|---|:---:|---|
| **F-01**: Controller blind to Lost Wax children | **CRITICAL** | `PlanController::destroy` deletes plans with active SPK/Tree/Oven data. |
| **F-02**: Database FK is `SET NULL` | **CRITICAL** | MySQL foreign key silently orphans all downstream lines instead of rejecting delete. |
| **F-03**: Closed plans deletable | **HIGH** | Management decisions and audit trails in `is_closed` can be destroyed. |
| **F-04**: No SoftDeletes | **HIGH** | Deletion is physical and irreversible. |

---

```
====================================================================================================
FINAL GATE VERDICT: [FAIL — CRITICAL VULNERABILITY CONFIRMED — READY FOR DESIGN LOCK]
====================================================================================================
```
