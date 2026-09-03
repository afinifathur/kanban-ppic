# PHASE 3.6 STEP 3 — RACK MONITOR ITEM NAME DISPLAY

## 1. Overview & Objective
In the Lost Wax Coating Rack Monitor (`/lost-wax/rack-monitor`), operator human readability was enhanced by displaying the **Nama Item** prominently on rack cards and modal overviews, while preserving **Kode Produksi** and **Barcode** for backend execution and traceability.

---

## 2. Source Code Trace & Audit Findings

1. **Production Code Resolution:**
   - Evaluated via `LostWaxTree::getSourceCode()`, querying `lost_wax_print_order_lines.code` / `lost_wax_work_orders.et_code`.
2. **Item Name Availability & Source of Truth:**
   - Evaluated via `LostWaxTree::getSourceProduct()`, querying `lost_wax_print_order_lines.item_name` / `lost_wax_work_order_items.item_name_snapshot`.
3. **Eager Loading Optimization:**
   - Enhanced tree queries in `RackMonitorService` with `with(['coatingRack', 'printOrderLine', 'workOrder.itemReference'])` to prevent N+1 query regression.

---

## 3. Display Changes

| Location | Previous Display | Updated Display |
| :--- | :--- | :--- |
| **Rack Card** (`index.blade.php`) | Dominant stage + tree count + pcs | Dominant stage + tree count + pcs + **Item Name Badges** (truncated with tooltip on long text) |
| **Modal Section D** (`Ringkasan Item & Kode Produksi`) | Production Codes list | **Item Names list** with tree count tags |
| **Modal Section E** (`Tabel Barcode Tree`) | `Barcode`, `Kode Produksi`, `Qty`, `Stage`, `Scan` | `Barcode`, **`Nama Item`**, `Kode Produksi`, `Qty`, `Stage`, `Scan` |

---

## 4. Traceability & Zero Business Logic Risk

- **Barcode:** Displayed in full human-readable and barcode scanner formats.
- **Production Code:** Retained in table columns, backend collections, and API responses.
- **Quantity & Stages:** Untouched; drying status calculation and stage aging remain 100% intact.

---

## 5. Files Changed

1. **`app/Services/LostWax/RackMonitorService.php`**
   - Eager-loaded `['coatingRack', 'printOrderLine', 'workOrder.itemReference']`.
   - Aggregated `$itemNames` map and assigned `'item_name'` to each tree detail.
2. **`resources/views/lost-wax/rack-monitor/index.blade.php`**
   - Added item name badges to rack cards.
   - Updated modal Section D & E to display item names alongside production code and barcode.
3. **`tests/Feature/LostWax/RackMonitorItemNameTest.php`** *(New)*
   - Automated tests for item name display, multi-item aggregation, traceability preservation, and N+1 prevention.
4. **`docs/architecture/PHASE_3_6_STEP_3_RACK_MONITOR_ITEM_NAME_DISPLAY.md`** *(New)*
   - Architecture documentation.

---

## 6. Test Results & Pint Result

- `php artisan test --filter=RackMonitorItemNameTest`: **4 passed** (20 assertions)
- `php artisan test --filter=RackMonitorDashboardTest`: **7 passed** (31 assertions)
- `php artisan test`: **601 passed** (3133 assertions)
- `vendor/bin/pint --test`: **PASS** (206 files)
