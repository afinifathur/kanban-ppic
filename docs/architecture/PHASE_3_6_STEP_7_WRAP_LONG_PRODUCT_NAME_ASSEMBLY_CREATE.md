# PHASE 3.6 STEP 7 — WRAP LONG PRODUCT NAME ON ASSEMBLY CREATE

## 1. Problem & Root Cause Analysis

### Problem
On the Assembly Work Order Create page (`/lost-wax/assemblies/{line}/create`), long product names (e.g., `SS304 SORF ANSI 150LBS 1/2" ...`) were truncated with ellipsis (`...`), making the full specification unreadable without inspecting HTML attributes.

### Root Cause
In `resources/views/lost-wax/assemblies/create.blade.php` (line 46):
```html
<strong class="text-slate-800 font-bold block truncate" title="{{ $line->item_name }}">{{ $line->item_name }}</strong>
```
The Tailwind `truncate` utility sets `overflow: hidden; text-overflow: ellipsis; white-space: nowrap;`, which directly prevented the text from wrapping to subsequent lines.

---

## 2. Implemented Fix
Replaced the `truncate` class with `break-words leading-tight`:
```html
<strong class="text-slate-800 font-bold block break-words leading-tight" title="{{ $line->item_name }}">{{ $line->item_name }}</strong>
```
- Allows standard and long words to wrap onto 2–3 lines within the grid card.
- Retained card structure, borders, colors, and responsive grid layout across desktop, tablet, and mobile.
- Zero changes to business logic, queries, database, or data values.

---

## 3. Files Changed
1. **`resources/views/lost-wax/assemblies/create.blade.php`**
   - Replaced `truncate` with `break-words leading-tight` on the "Nama Produk" card.
2. **`tests/Feature/LostWax/AssemblySimplifiedWOTest.php`**
   - Added test `test_assembly_create_renders_long_product_name_with_wrapping`.
3. **`docs/architecture/PHASE_3_6_STEP_7_WRAP_LONG_PRODUCT_NAME_ASSEMBLY_CREATE.md`** *(New)*
   - Architecture documentation.

---

## 4. Test Results
- `php artisan test --filter=AssemblySimplifiedWOTest`: **14 passed** (60 assertions)
- `vendor/bin/pint --test`: **PASS** (207 files)
