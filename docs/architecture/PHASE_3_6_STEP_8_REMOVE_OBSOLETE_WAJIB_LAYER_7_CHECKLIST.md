# PHASE 3.6 STEP 8 — REMOVE OBSOLETE "WAJIB LAYER 7" CHECKLIST

## 1. Executive Summary & Audit Findings

### Business Requirement
The "Wajib Layer 7 (Melalui coating lapisan ke-7)" checkbox on the Assembly Work Order Create form (`/lost-wax/assemblies/{line}/create`) was obsolete. The determination of whether a physical tree undergoes 6 or 7 coating layers is dictated by the **actual barcode scanning flow**:
1. **6 Layers Path:** If scanned directly into the Oven after Layer 6 (`/scan-oven`), the tree finishes with 6 coating layers.
2. **7 Layers Path:** If scanned for additional coating after Layer 6, it reaches Layer 7 before being scanned into the Oven.

### Audit of Dependencies
- **UI Element:** Checkbox card `<input type="checkbox" name="require_layer_7" ...>` in `resources/views/lost-wax/assemblies/create.blade.php`.
- **Controller Validation & Store:** Handled in `AssemblyController::storeWorkOrder`.
- **Database Schema:** `require_layer_7` boolean column exists on legacy tables (`lost_wax_work_orders`, `lost_wax_trees`, `lost_wax_rangkai_work_orders`).
- **Database Integrity:** No destructive migration or dropping of columns was executed, preserving all historical records.

---

## 2. Changes Made
1. **`resources/views/lost-wax/assemblies/create.blade.php`**
   - Removed the "Wajib Layer 7" card, checkbox input, and description block.
   - Cleaned up the form layout: `Quantity Diperintahkan` and `Kapasitas Standar / Pedoman Tree` are now immediately followed by `Catatan untuk SPV & Operator` without any empty gap.
2. **`app/Http/Controllers/LostWax/AssemblyController.php`**
   - Removed `require_layer_7` validation rule from `storeWorkOrder`.
   - Explicitly defaulted `require_layer_7 => false` in the service payload.
3. **`tests/Feature/LostWax/AssemblySimplifiedWOTest.php`**
   - Added test `test_assembly_create_does_not_render_wajib_layer_7_checkbox`.

---

## 3. Verification & Scanning Progression
- Scanning workflow remains 100% intact:
  - `ScanOvenTest::test_scan_layer_6_to_oven_succeeds` (PASS)
  - `ScanOvenTest::test_scan_layer_7_to_oven_succeeds` (PASS)
  - `AssemblySimplifiedWOTest` (15/15 PASS)
  - `ScanEngineTest` (28/28 PASS)
  - `ScanVoidTest` (17/17 PASS)
  - `vendor/bin/pint --test` (PASS, 207 files)
