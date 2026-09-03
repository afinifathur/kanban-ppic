# PHASE 3.6 STEP 9.1 — CORRECT TOTAL TO NET GOOD AFTER DEFECT

## 1. Executive Summary & Audit Verification

In Phase 3.6 Step 9, the semantic label was updated from "Tot Lap" to "Total".
In Step 9.1, we audited and proved that:
1. **Stage Quantities in View (`CTK, RGKI, L1..L7, Oven`)** already display **NET USABLE** quantities at each physical workstation (`usable_quantity = quantity - stage_defects`).
2. **Total Distributed Gross** is $\text{CTK} + Q_{\text{active\_trees\_gross}}$.
3. **Tot Rsk** is $Q_{\text{tree\_defect}}$.
4. **Total Column Value** is:
   $$\text{Total} = \text{CTK} + \text{RGKI} + \text{L1} + \text{L2} + \text{L3} + \text{L4} + \text{L5} + \text{L6} + \text{L7} + \text{Oven} = \text{Distributed Gross} - \text{Tot Rsk} = \text{Net Good}$$
5. **No Double-Counting:** Because stage quantities are already net of defect, summing them across the table gives **Net Good directly** without subtracting defect a second time.

---

## 2. Real UAT Investigations

### A. Case 268L651
- **PO:** 940 pcs | **Plan:** 1,019 pcs | **Executed Output:** 840 pcs
- **WIP:** CTK Standby = 696 pcs, RGKI Gross = 144 pcs (10 defect in assembly $\rightarrow$ 134 net)
- **Distributed Gross:** $696 + 144 = 840$ pcs
- **Tot Rsk:** 10 pcs
- **Total / Net Good:** $840 - 10 = \mathbf{830}$ pcs
- **Status Evaluation:** $830 < 940\text{ (PO)} \rightarrow$ **`CRITICAL` / RE-PLAN** (PO is broken).

### B. Case 268ETB827 Discrepancy Investigation
- **PO:** 950 pcs | **Plan:** 1,100 pcs | **Executed Good:** 956 pcs
- **Pasted Numbers in UAT:** `22, 6, 96, 224, 192, 128, 192, 96` (Sum = 956)
- **Discrepancy Root Cause:**
  - `22` = Standby CTK
  - `6` = **Tot Rsk** (defect column `G`)
  - `96, 224, 192, 128, 192, 96` = Active tree stage quantities (`L1..L7, Oven` columns)
  - Actual stage sum: $22 + 96 + 224 + 192 + 128 + 192 + 96 = \mathbf{950}$ (Net Good).
  - The sum was 956 because the **Tot Rsk (6)** column was accidentally included in the addition of the columns.
  - The Total column correctly displayed **950** (Net Good after 6 defect pieces).

---

## 3. Status Threshold Rules
- $\text{Net Good} \ge \text{Plan} \rightarrow$ **`NORMAL`** (or **`SELESAI / COMPLETED`** if all usable parts in Oven)
- $\text{PO} \le \text{Net Good} < \text{Plan} \rightarrow$ **`WARNING`**
- $\text{Net Good} < \text{PO} \rightarrow$ **`CRITICAL` / RE-PLAN**
- $\text{Net Good} == \text{PO} \rightarrow$ **`WARNING`** (Boundary: meets PO commitment).

---

## 4. Web, Excel, and Print Consistency
- **Web UI:** Header `Total`, displays `$row['total_lap']` (Net Good).
- **Excel Export:** Header `Total (pcs)`, writes `$row['total_lap']` (Net Good).
- **Print Report:** Header `TOTAL`, prints `$row['total_lap']` (Net Good).

---

## 5. Verification
- `php artisan test --filter=ProductionStatusAlignmentTest`: **14 passed** (81 assertions)
- `php artisan test --filter=ProductionStatusTest`: **39 passed** (213 assertions)
- `vendor/bin/pint --test`: **PASS** (207 files, 0 issues)
