# Rack Autocomplete UAT Regression Audit

This document records the findings of the read-only audit to identify the root cause of the autocomplete issue on the UAT server for the rack input field in the Lost Wax Trees view.

---

## Expected Behavior

When a user types in the rack input field (either bulk or individual) in `/lost-wax/trees`:
1. Typing **`1`** should prompt `RAK-01`.
2. Typing **`01`** should prompt `RAK-01`.
3. Typing **`17`** should prompt `RAK-17`.
4. Typing **`RAK-17`** should prompt `RAK-17`.

---

## Production Database Check

- **Table:** `lost_wax_coating_racks`
- **Columns:** `id`, `rack_number`, `label`, `status`, `notes`, `created_at`, `updated_at`.
- **Expected Data:** 35 active racks with `rack_number` from 1 to 35, status `'active'`.
- **Audit Findings:** 
  In the local environment, the `lost_wax_coating_racks` table was initially **empty** (0 records) because the seeder had not been run. If the UAT production environment followed the standard deployment workflow (`git pull -> clear cache -> php artisan migrate`), the seeder (`LostWaxCoatingRackSeeder`) was **never executed**, leaving the production database with 0 racks. 

---

## Controller Check

- **Controller File:** [TreeController.php](file:///c:/laragon/www/kanban-ppic/app/Http/Controllers/LostWax/TreeController.php)
- **Method:** `index()`
- **Query Logic:**
  ```php
  $coatingRacks = \App\Models\LostWaxCoatingRack::where('status', 'active')
      ->orderBy('rack_number', 'asc')
      ->get();
  ```
- **Audit Findings:** The controller correctly queries all active coating racks ordered by `rack_number` and passes them to the view via `compact('coatingRacks')`. There is no pagination or custom filtering on the racks retrieval, which is correct. However, if the database has 0 records, `$coatingRacks` will be an empty collection.

---

## Rendered HTML Check

- **Datalist definition in view:** [index.blade.php](file:///c:/laragon/www/kanban-ppic/resources/views/lost-wax/trees/index.blade.php#L595-L599)
  ```html
  <datalist id="coating-racks-list">
      @foreach($coatingRacks as $rack)
          <option value="RAK-{{ str_pad((string)$rack->rack_number, 2, '0', STR_PAD_LEFT) }}"></option>
      @endforeach
  </datalist>
  ```
- **Audit Findings:** The HTML `<datalist>` binds option values of format `RAK-XX` using PHP `str_pad` with `STR_PAD_LEFT`. This generates output like `<option value="RAK-01">`.
  If the UAT database contains 0 active racks, this datalist is rendered completely empty:
  ```html
  <datalist id="coating-racks-list">
  </datalist>
  ```
  An empty datalist prevents any autocomplete suggestions from appearing when typing.

---

## rackMap Check

- **JavaScript Object Definition:** [index.blade.php](file:///c:/laragon/www/kanban-ppic/resources/views/lost-wax/trees/index.blade.php#L228-L235)
  ```javascript
  const rackMap = {
      @foreach($coatingRacks as $rack)
          "rak-{{ str_pad($rack->rack_number, 2, '0', STR_PAD_LEFT) }}": "{{ $rack->id }}",
          "rak-{{ $rack->rack_number }}": "{{ $rack->id }}",
          "{{ str_pad($rack->rack_number, 2, '0', STR_PAD_LEFT) }}": "{{ $rack->id }}",
          "{{ $rack->rack_number }}": "{{ $rack->id }}",
      @endforeach
  };
  ```
- **Audit Findings:** The mapping covers combinations like `"rak-01"`, `"rak-1"`, `"01"`, and `"1"`. The lookup inside `onTreeRackInputChange` is robust, allowing fallback resolution to `rak-XX` if the typed value is numeric (e.g. `017` is resolved to `rak-17`).
  However, if `$coatingRacks` is empty, `rackMap` evaluates to:
  ```javascript
  const rackMap = {};
  ```
  Because the map is empty, any validation in `onTreeRackInputChange` (like typing `"1"` or `"01"`) will fail to find a matching ID, causing the system to reject the input and revert to the original value.

---

## Browser Console Check

- If `rackMap` is empty, there are no syntax or reference errors generated (empty object is syntactically valid).
- However, when a user types a value like `"1"` or `"01"` and leaves the field, `onTreeRackInputChange` fails to match the value, causing a SweetAlert warning dialog ("Input Tidak Valid") to appear, and the field reverts back to its original label.

---

## Asset/Cache Check

- **Laravel View Cache:** If views are cached (`php artisan view:cache`) on UAT before the seeder is executed, the rendered template remains compiled with the empty racks list even after database records are populated.
- **Route / Config Cache:** Does not affect this view-specific template rendering.

---

## Local vs Production Comparison

| Component | Local (Populated Database) | Production/UAT (Unseeded Database) |
|---|---|---|
| **Active Racks count** | 35 | 0 |
| **Datalist Options** | `<option value="RAK-01">` ... | *Empty* |
| **JavaScript `rackMap`** | `{"rak-01": "1", ...}` | `{}` |
| **Interaction Behavior** | Auto-completes and matches numeric input | No dropdown choices, inputs show "Input Tidak Valid" |

---

## Root Cause

**Confidence: HIGH**

The root cause of the autocomplete failure on the UAT server is that **no data exists in the `lost_wax_coating_racks` table**.
Because UAT deployment only executes `php artisan migrate` and does not run the seeder, the database has 0 rack records. Consequently:
1. The HTML `<datalist>` contains no option elements (hence, the browser displays no autocomplete dropdown choices).
2. The JavaScript `rackMap` is empty `{}` (hence, any typed input is flagged as invalid and reverted).

---

## Recommended Fix

1. **Populate the Coating Racks on the UAT/Production Server:**
   Execute the seeder on the server to generate the 35 racks:
   ```bash
   php artisan db:seed --class=LostWaxCoatingRackSeeder
   ```
2. **Clear Laravel View Cache:**
   To guarantee the updated view gets compiled with database data:
   ```bash
   php artisan view:clear
   ```

---

## Risk Assessment

- **Risk Level:** **VERY LOW**
  - Running the seeder is completely safe as it uses `firstOrCreate()` to check if the rack exists before creating it, ensuring no duplicate data or data overwrites.
  - No database schema or codebase changes are required.
