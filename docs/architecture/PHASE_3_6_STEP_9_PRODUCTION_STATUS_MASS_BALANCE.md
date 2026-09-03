# PHASE 3.6 STEP 9 — PRODUCTION STATUS MASS BALANCE & RE-PLAN LOGIC

## 1. Executive Summary & Business Objective

Production Status monitors the distributed location of all pieces printed for each Production Code across the entire lost wax pipeline:
$$\text{CTK} \rightarrow \text{RGKI} \rightarrow \text{L1} \rightarrow \text{L2} \rightarrow \text{L3} \rightarrow \text{L4} \rightarrow \text{L5} \rightarrow \text{L6} \rightarrow \text{L7} \rightarrow \text{Oven}$$

It calculates:
1. **Total Distributed WIP (Total)**: Exactly how many usable pieces are currently distributed across all physical workstations.
2. **Total Accumulated Defect (Tot Rsk)**: Total defect pieces across all physical trees and stations.
3. **Net Good**: Net usable quantity remaining to fulfill demand.
4. **PO Sufficiency & Status**:
   - $\text{Net Good} \ge \text{Plan}$: **`NORMAL`** (or **`SELESAI / COMPLETED`** when 100% in Oven).
   - $\text{PO} \le \text{Net Good} < \text{Plan}$: **`WARNING`** (Deficit against Plan buffer, but PO commitment is safe).
   - $\text{Net Good} < \text{PO}$: **`CRITICAL` / RE-PLAN** (PO quantity is broken; urgent reprint/re-plan needed).

---

## 2. Mass Balance Architecture & Source of Truth

### A. Mutually Exclusive Distribution
Every physical unit is in **EXACTLY ONE** stage at any moment:
- `CTK`: Standby pieces printed good, waiting to be assembled on trees ($Q_{\text{standby}}$).
- `RGKI`: Assembled into a tree, waiting for first coating scan (`sebelum_scan`).
- `L1` to `L7`: Active trees currently scanned at coating layers 1 through 7.
- `Oven`: Trees that completed coating and entered the Oven.

### B. Mass Balance Equation
$$\text{Total} = \text{CTK} + \text{RGKI} + \text{L1} + \text{L2} + \text{L3} + \text{L4} + \text{L5} + \text{L6} + \text{L7} + \text{Oven} = Q_{\text{usable}} = \text{Net Good}$$
$$\text{Tot Rsk} = \sum \text{Tree Defects} = Q_{\text{tree\_defect}}$$
$$\text{Net Good} = Q_{\text{print\_good}} - Q_{\text{tree\_defect}} - Q_{\text{excess\_closed}}$$

---

## 3. Example Calculation: Production Code 268KS103
- **PO Quantity:** $1.000$ pcs
- **Plan Quantity:** $1.100$ pcs
- **Cetak Good:** $1.100$ pcs ($Q_{\text{print\_good}}$)
- **Physical Trees:**
  - $1$ tree at `RGKI / sebelum_scan`: $30$ gross, $2$ defect $\rightarrow$ **$28$ usable** ($R_{\text{RGKI}} = 2$)
  - $1$ tree at `L1`: $100$ gross, $5$ defect $\rightarrow$ **$95$ usable** ($R_{\text{L1}} = 5$)
  - $1$ tree at `L3`: $100$ gross, $3$ defect $\rightarrow$ **$97$ usable** ($R_{\text{L3}} = 3$)
  - $1$ tree at `Oven`: $200$ gross, $10$ defect $\rightarrow$ **$190$ usable** ($R_{\text{Oven}} = 10$)
- **Standby CTK:** $1.100 - (30 + 100 + 100 + 200) = \mathbf{670}$ pcs
- **Total WIP in Flow:**
  $$\text{Total} = 670 + 28 + 95 + 0 + 97 + 0 + 0 + 0 + 0 + 190 = \mathbf{1.080}\text{ pcs}$$
- **Total Rusak (Tot Rsk):** $2 + 5 + 3 + 10 = \mathbf{20}\text{ pcs}$
- **Net Good:** $1.100 - 20 = \mathbf{1.080}\text{ pcs}$
- **Status Evaluation:** $1.080 < 1.100$ (Plan) but $\ge 1.000$ (PO) $\rightarrow$ **`WARNING`**.
- **Re-Plan Trigger:** If defect increases to $101$ pcs $\rightarrow \text{Net Good} = 999 < \text{PO } 1.000 \rightarrow$ **`CRITICAL` / RE-PLAN**.

---

## 4. Files Changed
1. **`app/Http/Controllers/LostWax/ProductionStatusController.php`**
   - Updated `total_lap` to reflect the complete distributed mass balance `CTK + RGKI + L1..L7 + Oven`.
   - Updated Excel export header to `'Total (pcs)'`.
2. **`resources/views/lost-wax/production-status/index.blade.php`**
   - Updated web table header from `Tot Lap` to `Total`.
   - Updated print table header from `TOTAL LAP.` to `TOTAL`.
3. **`tests/Feature/LostWax/ProductionStatusAlignmentTest.php`**
   - Added `test_mass_balance_across_all_distributed_stages` and `test_replan_triggered_when_net_good_below_po`.
4. **`tests/Feature/LostWax/ProductionStatusTest.php`**
   - Updated header assertions to match `Total`.
5. **`docs/architecture/PHASE_3_6_STEP_9_PRODUCTION_STATUS_MASS_BALANCE.md`** *(New)*
   - Architecture documentation.

---

## 5. Verification Results
- `php artisan test --filter=ProductionStatusAlignmentTest`: **12 passed** (62 assertions)
- `php artisan test --filter=ProductionStatusTest`: **39 passed** (213 assertions)
- `php artisan test`: **614 passed** (3207 assertions)
- `vendor/bin/pint --test`: **PASS** (207 files, 0 issues)
