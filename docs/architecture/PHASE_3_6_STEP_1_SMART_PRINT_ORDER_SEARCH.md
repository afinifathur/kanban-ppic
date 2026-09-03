# PHASE 3.6 STEP 1 — SMART SEARCH PRINT ORDERS

## 1. Executive Summary

Implemented smart partial search on Dokumen Perintah Cetak (Print Orders) list (`/lost-wax/print-orders/plans?tab=orders`) enabling PPIC users to locate Print Orders by **Production Code (Kode Produksi)** or **Item Name (Nama Item)** without having to open Print Orders one by one.

---

## 2. Relationship Trace & Audit

The data relationship flow from Print Order to Item identity is structured as follows:

```
LostWaxPrintOrder (`lost_wax_print_orders`)
    │ (hasMany `lines`)
    ▼
LostWaxPrintOrderLine (`lost_wax_print_order_lines`)
    │ (belongsTo `productionPlan`, foreignKey: `production_plan_id`)
    ▼
ProductionPlan (`production_plans`)
    ├── `code` (Production Code, e.g. "268KS758", "268CB758", "267AB758")
    ├── `item_name` (Item Name, e.g. "SS304 ELBOW 90 F/F BSP 3/4\"")
    └── `item_code` (Item Code reference)
```

### Table & Column Mapping:
- **Print Order:**
  - Table: `lost_wax_print_orders`
  - Model: `\App\Models\LostWaxPrintOrder`
  - Relation: `lines()` -> `hasMany(LostWaxPrintOrderLine::class, 'lost_wax_print_order_id')`
- **Print Order Line:**
  - Table: `lost_wax_print_order_lines`
  - Model: `\App\Models\LostWaxPrintOrderLine`
  - Foreign Keys: `lost_wax_print_order_id`, `production_plan_id`
  - Snapshot Fields: `code` (Production Code), `item_name` (Item Name)
  - Relation: `productionPlan()` -> `belongsTo(ProductionPlan::class, 'production_plan_id')`
- **Production Plan:**
  - Table: `production_plans`
  - Model: `\App\Models\ProductionPlan`
  - Primary Key: `id`
  - Production Code Field: `code`
  - Item Name Field: `item_name`
  - Item Code Field: `item_code`

---

## 3. Query & Filter Implementation

### Controller Filter Logic (`PrintOrderController::plans`):
```php
if ($request->filled('print_order_number')) {
    $printOrdersQuery->where('print_order_number', 'like', '%'.$request->print_order_number.'%');
}

if ($request->filled('search')) {
    $search = $request->search;
    $printOrdersQuery->whereHas('lines', function ($q) use ($search) {
        $q->where(function ($sub) use ($search) {
            $sub->where('code', 'like', '%'.$search.'%')
                ->orWhere('item_name', 'like', '%'.$search.'%')
                ->orWhereHas('productionPlan', function ($p) use ($search) {
                    $p->where('code', 'like', '%'.$search.'%')
                        ->orWhere('item_name', 'like', '%'.$search.'%');
                });
        });
    });
}
```

### Key Performance & Architectural Qualities:
1. **Partial Match (`LIKE '%...%'`):** Partial inputs match code or item name substring anywhere.
2. **Case-Insensitive:** Standard SQL collation / database matching.
3. **Multi-Item Integrity:** If a Print Order has multiple items and only 1 item matches, the Print Order is included.
4. **No N+1 Queries:** Evaluated via single `whereHas` subquery and eager loaded with `['creator', 'lines']`.
5. **State & Tab Preservation:** Pagination keeps `tab=orders&search=...` query string parameters intact across pages.

---

## 4. UI Design

Located in `resources/views/lost-wax/print-orders/plans.blade.php` (Tab 2: Dokumen Perintah Cetak):

- **Grid:** 3-column responsive filter form:
  1. `No Perintah Cetak` (`print_order_number`) — placeholder: `PC-202608...`
  2. `Kode Produksi / Nama Item` (`search`) — placeholder: `Contoh: 758 atau SS304 ELBOW`
  3. Action buttons: `[ Cari ]` (Amber button) and `[ Reset ]` (Slate button)
- **Reset Button:** Links to `route('lost-wax.print-orders.plans', ['tab' => 'orders'])`.

---

## 5. Example Search Result ("758")

When a user searches `758`:
- Print Orders containing items with code `268KS758`, `268CB758`, `267AB758`, etc., are returned.
- Non-matching print orders are filtered out.
- Empty search results display the standard clean empty state: *"Tidak ada dokumen Perintah Cetak ditemukan."*

---

## 6. Files Changed & Added

1. `app/Http/Controllers/LostWax/PrintOrderController.php` — Added `search` handling on `$printOrdersQuery` via `whereHas('lines', ...)`.
2. `resources/views/lost-wax/print-orders/plans.blade.php` — Added input field for `Kode Produksi / Nama Item` in the Tab 2 filter form.
3. `tests/Feature/LostWax/PrintOrderSearchTest.php` — Added 13 automated test cases validating partial matching, case insensitivity, multi-item matching, pagination, reset, and N+1 prevention.
4. `docs/architecture/PHASE_3_6_STEP_1_SMART_PRINT_ORDER_SEARCH.md` — This architectural report.

---

## 7. Test Results

### Test Suite Execution:
```
php artisan test --filter=PrintOrderSearchTest
   PASS  Tests\Feature\LostWax\PrintOrderSearchTest
  ✓ search by exact production code
  ✓ search by partial production code
  ✓ search 758 finds 268ks758
  ✓ search 758 finds multiple matching production codes
  ✓ search by partial item name
  ✓ search case insensitive
  ✓ print order appears if at least one item matches
  ✓ search unmatched shows normal empty state
  ✓ search works with pagination
  ✓ reset clears filters
  ✓ existing print order number search still works
  ✓ tab orders is retained
  ✓ no n plus one query

Tests: 13 passed (40 assertions)
```

### Full Test Suite:
```
php artisan test
Tests: 581 passed (3063 assertions)
```

### Code Formatting:
```
vendor/bin/pint --test
PASS: 204 files
```

---

## 8. Final Gate

**[PASS — SMART PRINT ORDER SEARCH IMPLEMENTED]**
