# PHASE 3.5 STEP 7 — PRODUCTION STATUS PER-STAGE DEFECT INDICATOR

**Target Route:** `/lost-wax/production-status`  
**Related Services:** `ProductionStatusController`, `LostWaxQualityService`  
**Status:** COMPLETED & VERIFIED  

---

## 1. Executive Summary

In Phase 3.5 Step 7, we implemented explicit per-stage defect indicators (`R`) across all production steps in the Lost Wax Production Status board without altering the underlying WIP Snapshot inventory model.

### Key Enhancements:
1. **Per-Stage Defect Rendering**: Replaced placeholder `-` cells in columns `L1` through `L7` and added the `R` indicator for `Oven`.
2. **Distinct Red Warning Aesthetic**: When defect > 0 on any stage (`CTK`, `RGKI`, `L1..L7`, `Oven`), the cell is highlighted with `font-bold text-red-600 bg-red-50/50`. If defect is 0, a compact `-` is displayed.
3. **Strict Invariant & Model Integrity**: Preserved the WIP Snapshot model ($\sum \text{WIP} = \text{Print Good}$). No throughput reduction math ($A - B = C$) was applied to the current WIP display.
4. **Zero N+1 Query Overhead**: Defect aggregates for active trees utilize the already eager-loaded `tree.defects` relationship.

---

## 2. Defect Source Mapping

| Stage | UI Column | Database Source | Mapping Field / Condition |
|---|---|---|---|
| **Cetak** | `R` (after `CTK`) | `lost_wax_print_executions` | `sum(qty_defect)` where `status = 'FINALIZED'` |
| **Rangkai** | `R` (after `RGKI`) | `lost_wax_tree_defects` | `sum(defect_qty)` where `stage = 'assembly'` |
| **Lapisan 1** | `R` (after `L1`) | `lost_wax_tree_defects` | `sum(defect_qty)` where `stage = 'layer_1'` |
| **Lapisan 2** | `R` (after `L2`) | `lost_wax_tree_defects` | `sum(defect_qty)` where `stage = 'layer_2'` |
| **Lapisan 3** | `R` (after `L3`) | `lost_wax_tree_defects` | `sum(defect_qty)` where `stage = 'layer_3'` |
| **Lapisan 4** | `R` (after `L4`) | `lost_wax_tree_defects` | `sum(defect_qty)` where `stage = 'layer_4'` |
| **Lapisan 5** | `R` (after `L5`) | `lost_wax_tree_defects` | `sum(defect_qty)` where `stage = 'layer_5'` |
| **Lapisan 6** | `R` (after `L6`) | `lost_wax_tree_defects` | `sum(defect_qty)` where `stage = 'layer_6'` |
| **Lapisan 7** | `R` (after `L7`) | `lost_wax_tree_defects` | `sum(defect_qty)` where `stage = 'layer_7'` |
| **Oven** | `R` (after `Oven`) | `lost_wax_tree_defects` | `sum(defect_qty)` where `stage = 'oven'` |
| **Tot Rsk** | `Tot Rsk` | `lost_wax_tree_defects` | `q_tree_defect = sum(defect_qty)` across tree lifetime |

---

## 3. UI Layout & Table Structure

The table structure now features 28 columns matching both Screen and Print views:

```
Kode Cust | Product Name | AISI | PO | Plan | Tot Lap | Tot Rsk | CTK | R | RGKI | R | L1 | R | L2 | R | L3 | R | L4 | R | L5 | R | L6 | R | L7 | R | Oven | R | Status
```

### Visual Display Rules:
- **Quantity Cells (`CTK`, `RGKI`, `L1..L7`, `Oven`)**: Active values render in `cell-layer-active` / `cell-oven`.
- **Defect Cells (`R`)**:
  - `0`: Displays compact `-` in `text-slate-400`.
  - `> 0`: Displays count in `font-bold text-red-600 bg-red-50/50` (or `ps-cell-red` for print).

---

## 4. Verification & Testing

- **Feature Tests Added**: `test_per_stage_defect_indicators_rendered_in_production_status` in `tests/Feature/LostWax/ProductionStatusTest.php`.
- **Verification Results**:
  - `php artisan test --filter=ProductionStatusTest`: 39 passed (213 assertions).
  - `php artisan test`: 568 passed (3023 assertions).
  - `vendor/bin/pint --test`: Clean (203 files passed).
