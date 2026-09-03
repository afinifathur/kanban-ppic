# PHASE 3.6 STEP 6 — LIVE KG CALCULATOR PRINT ORDER CREATE

## 1. Overview & Business Problem

When creating a new Print Order from selected Production Plans via `/lost-wax/print-orders/create?plan_ids[]=...`, users modify the `Qty Perintah Cetak` for each plan item.

Previously, the create page lacked the real-time weight and quantity summary widget that existed on:
1. `/lost-wax/print-orders/plans` (Selection Summary Bar)
2. `/lost-wax/print-orders/{id}/edit` (Live Edit Calculator)

Users needed immediate visibility of:
- **Item Terpilih** (`X item`)
- **Total Qty (PCS)** (`X pcs`)
- **Total Berat (KG)** (`X.XX kg`)

updated live as inputs change without page reload.

---

## 2. Audit & Calculation Alignment

### A. Source of Truth for Unit Weight
- Stored as `ProductionPlan::$weight` (column `weight` on `production_plans` table, cast as `decimal:2`).
- Exposed in DOM table row input as `data-weight-per-piece="{{ $plan->weight ?? 0 }}"`.

### B. Formula & Rounding Rules
- **Line Weight:** $\text{Qty} \times \text{Unit Weight}$
- **Total Pcs:** $\sum \text{Qty}$
- **Total Weight (Kg):** $\sum \text{Line Weight}$
- **Rounding:** 2 decimal places with `Math.round(totalKg * 100) / 100` and formatted using `toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })`.
- **Edge cases handled:** Empty strings, non-numeric characters, and negative inputs safely default to 0 to prevent `NaN`.

---

## 3. UI Implementation
- Placed matching visual summary bar in `@section('top_bar')` (`#selection-summary-bar`) with 3 metrics:
  - `Item Terpilih` (`#summary-item-count`)
  - `Total Qty (PCS)` (`#summary-total-pcs`)
  - `Total Berat (KG)` (`#summary-total-kg`)
- Embedded in DOM and linked via `input` event listeners on all `.qty-input` fields.
- Initial calculation executes on `DOMContentLoaded`.

---

## 4. Server-Side Integrity
- The client-side calculator serves solely as a real-time UI assistant.
- Server-side store endpoint (`PrintOrderController::store`) continues to validate and calculate all records authoritatively.

---

## 5. Files Changed
1. **`resources/views/lost-wax/print-orders/create.blade.php`**
   - Added Top Bar selection summary bar (`#selection-summary-bar`).
   - Attached `data-weight-per-piece` on `.qty-input`.
   - Added live calculation and warning validation JavaScript.
2. **`tests/Feature/LostWax/PrintOrderCreateCalculatorTest.php`** *(New)*
   - Feature tests verifying summary bar rendering, unit weight exposure, and store persistence integrity.
3. **`docs/architecture/PHASE_3_6_STEP_6_LIVE_KG_CALCULATOR_PRINT_ORDER_CREATE.md`** *(New)*
   - Architecture documentation.

---

## 6. Verification Results
- `php artisan test --filter=PrintOrderCreateCalculatorTest`: **3 passed** (17 assertions)
- `php artisan test --filter=PrintPlanningSummaryTest`: **7 passed** (30 assertions)
- `php artisan test --filter=PrintOrderTest`: **47 passed** (300 assertions)
- `php artisan test`: **610 passed** (3180 assertions)
- `vendor/bin/pint --test`: **PASS** (207 files)
