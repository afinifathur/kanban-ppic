# PHASE 3.5 STEP 3 — QC FITTING READ-ONLY USER & CROSS-SCOPE RBAC IMPLEMENTATION

**Date:** 2026-08-29  
**Status:** COMPLETED — READY FOR PRODUCTION  
**Target User:** `adminqcfitting@peroniks.com`  
**Role:** `admin_qc_fitting`  
**Product Scope:** `null` (Cross-Scope Read-Only across all 3 PPIC product scopes)  

---

## 1. USER & ROLE DEFINITION

```text
User:       Admin QC Fitting
Email:      adminqcfitting@peroniks.com
Password:   password (Bcrypt hashed)
Role:       admin_qc_fitting
Scope:      CROSS-SCOPE READ-ONLY (FLANGE_STAINLESS, FITTING_STAINLESS, FLANGE_BESI)
```

The `admin_qc_fitting` role is a dedicated Quality Control role engineered specifically for cross-scope inspection, quality auditing, defect reporting, and production monitoring. It deliberately **does not** inherit write permissions from `admin` or `ppic`.

---

## 2. CROSS-SCOPE ACCESS MODEL

| Product Scope | Read Access | Write / Mutation Access |
| :--- | :---: | :---: |
| **`FLANGE_STAINLESS`** | **YES (ALLOWED)** | **NO (BLOCKED — 403)** |
| **`FITTING_STAINLESS`** | **YES (ALLOWED)** | **NO (BLOCKED — 403)** |
| **`FLANGE_BESI`** | **YES (ALLOWED)** | **NO (BLOCKED — 403)** |
| **Daily Defect Report** | **YES (ALLOWED)** | **NO (READ-ONLY)** |

Because `$user->product_scope` is `null` and the user does not possess the `ppic` role (which enforces singular scope filtering), query scopes in `PlanController`, `PrintOrderController`, `TreeController`, `AssemblyController`, `OutcomeController`, `ProductionStatusController`, and `LostWaxDefectReportService` dynamically return records across **all 3 product scopes**.

---

## 3. ALLOWED & FORBIDDEN ACTIONS MATRIX

### A. Allowed Actions (Read-Only & Auditing):
- ✅ View Production Plans (`/plan`) across all scopes
- ✅ View Production Status (`/lost-wax/production-status`)
- ✅ View Perintah Cetak & SPK Plans (`/lost-wax/print-orders/plans`)
- ✅ View Tree / Traveler list and individual Tree Detail (`/lost-wax/trees/{tree}`)
- ✅ View Barcode Traceability and Inline Scan History
- ✅ View Quality Control Daily Defect Report (`/lost-wax/quality/defects`)
- ✅ Filter by Date From / Date To, Stage, and Search keywords
- ✅ Switch between Mode Ringkas and Mode Detail with Drill-down
- ✅ Export Excel (`/lost-wax/quality/defects/export/excel`)
- ✅ Export PDF / Print (`/lost-wax/quality/defects/export/pdf`)
- ✅ View Master Foto Rangkai & Audit Index (`/settings/assembly-photos/index`)

### B. Forbidden Actions (HTTP 403 Enforced):
- ❌ `POST /plan` — Create Production Plan
- ❌ `PUT /plan/{plan}` — Update Production Plan
- ❌ `DELETE /plan/{plan}` — Delete Production Plan
- ❌ `POST /lost-wax/print-orders` — Create SPK
- ❌ `PUT /lost-wax/print-orders/{printOrder}` — Update SPK
- ❌ `DELETE /lost-wax/print-orders/{printOrder}` — Delete SPK
- ❌ `POST /lost-wax/print-orders/reprint` — Create Reprint SPK
- ❌ `POST /lost-wax/production-plans/{plan}/close-recovery` — Close Plan
- ❌ `PUT /lost-wax/production-plans/{plan}/update-po` — Update PO Quantity
- ❌ `POST /lost-wax/scan` — Process Coating Scan
- ❌ `POST /lost-wax/scan-oven` — Process Oven Scan
- ❌ `POST /lost-wax/scan-events/{event}/void` — Void Scan Event
- ❌ `POST /lost-wax/trees/{tree}/defects` — Record Defect
- ❌ `PATCH /lost-wax/trees/{tree}` — Correct Tree Quantity
- ❌ `POST /settings/assembly-photos` — Upload / Modify Assembly Photos
- ❌ Any non-safe HTTP method (`POST`, `PUT`, `PATCH`, `DELETE`) across the entire web application

---

## 4. RBAC & BACKEND ENFORCEMENT MECHANISM

### A. Route & Middleware Layer: `PreventQcMutation`
A dedicated middleware [`app/Http/Middleware/PreventQcMutation.php`](file:///c:/laragon/www/kanban-ppic/app/Http/Middleware/PreventQcMutation.php) was registered in the global `web` middleware pipeline in [`bootstrap/app.php`](file:///c:/laragon/www/kanban-ppic/bootstrap/app.php):

```php
if (auth()->check() && auth()->user()->hasRole('admin_qc_fitting')) {
    if (! in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
        abort(403, 'Akses ditolak. Akun QC bersifat Read-Only dan tidak memiliki hak mutasi data.');
    }
}
```

Any direct HTTP mutation request (`POST`, `PUT`, `PATCH`, `DELETE`) is immediately intercepted and aborted with **HTTP 403 Forbidden** before executing any controller or service logic.

### B. View Layer (Blade UI):
- Action buttons (Catat Defect, Koreksi Quantity, Tambah Rencana, Upload Foto) are conditionally hidden or rendered in read-only informational banners when authenticated as `admin_qc_fitting`.

---

## 5. IDEMPOTENT DATABASE SEEDER

Updated [`database/seeders/DatabaseSeeder.php`](file:///c:/laragon/www/kanban-ppic/database/seeders/DatabaseSeeder.php):
```php
$qcRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_qc_fitting']);
$qcRole->syncPermissions([$accessPlanning, $accessExecution]);

$this->seedUser('QC Fitting', 'adminqcfitting@peroniks.com', 'password', 'admin_qc_fitting', null);
```

- Idempotent: Executable repeatedly without duplicate user creations.
- Password: Securely hashed via `bcrypt()`.
- Existing roles and users (`admin`, `ppic`, `spv`) remain untouched.

---

## 6. AUTOMATED TEST SUITE

Created [`tests/Feature/LostWax/QcFittingCrossScopeRbacTest.php`](file:///c:/laragon/www/kanban-ppic/tests/Feature/LostWax/QcFittingCrossScopeRbacTest.php) with 21 feature test scenarios:

| # | Test Scenario | Result |
| :--- | :--- | :--- |
| 1 | `test_1_qc_user_can_login` | **PASS** |
| 2 | `test_2_qc_user_can_read_flange_stainless` | **PASS** |
| 3 | `test_3_qc_user_can_read_fitting_stainless` | **PASS** |
| 4 | `test_4_qc_user_can_read_flange_besi` | **PASS** |
| 5 | `test_5_qc_user_can_open_daily_defect_report` | **PASS** |
| 6 | `test_6_report_displays_cross_scope_data_from_all_three_scopes` | **PASS** |
| 7 | `test_7_date_range_filter_works` | **PASS** |
| 8 | `test_8_stage_filter_works` | **PASS** |
| 9 | `test_9_export_excel_works` | **PASS** |
| 10 | `test_10_export_pdf_works` | **PASS** |
| 11 | `test_11_user_cannot_create_production_plan` | **PASS (403)** |
| 12 | `test_12_user_cannot_mutate_spk` | **PASS (403)** |
| 13 | `test_13_user_cannot_scan` | **PASS (403)** |
| 14 | `test_14_user_cannot_void_scan` | **PASS (403)** |
| 15 | `test_15_user_cannot_input_defect` | **PASS (403)** |
| 16 | `test_16_user_cannot_update_po_quantity` | **PASS (403)** |
| 17 | `test_17_user_cannot_close_plan` | **PASS (403)** |
| 18 | `test_18_user_cannot_create_reprint` | **PASS (403)** |
| 19 | `test_19_user_cannot_modify_assembly_photo` | **PASS (403)** |
| 20 | `test_20_direct_http_mutations_yield_403` | **PASS (403)** |
| 21 | `test_21_existing_ppic_and_admin_permissions_unaltered` | **PASS** |

### Full Application Test Suite:
```text
   PASS  Tests\Feature\LostWax\QcFittingCrossScopeRbacTest
   553 passed (2,956 assertions) across all test suites
   Laravel Pint: 201 files checked, 0 style issues (PASS)
```

---

## 7. FILES CREATED & MODIFIED

1. `app/Http/Middleware/PreventQcMutation.php` (NEW) — Global read-only mutation blocker for QC.
2. `bootstrap/app.php` (MODIFIED) — Registered `PreventQcMutation` in `web` middleware stack.
3. `database/seeders/DatabaseSeeder.php` (MODIFIED) — Added `admin_qc_fitting` role and `adminqcfitting@peroniks.com` user.
4. `resources/views/layouts/app.blade.php` (MODIFIED) — Added sidebar navigation access for `admin_qc_fitting`.
5. `resources/views/lost-wax/trees/show.blade.php` (MODIFIED) — Hid write actions for QC.
6. `resources/views/settings/assembly-photos/index.blade.php` (MODIFIED) — Replaced photo upload form with read-only badge for QC.
7. `tests/Feature/LostWax/QcFittingCrossScopeRbacTest.php` (NEW) — 21 automated feature tests.
8. `docs/architecture/PHASE_3_5_STEP_3_QC_FITTING_RBAC_IMPLEMENTATION.md` (NEW) — Implementation documentation.

---

## 8. FINAL GATE VERDICT

```text
═════════════════════════════════════════════════════════════════
  PHASE 3.5 STEP 3
  QC CROSS-SCOPE READ-ONLY RBAC IMPLEMENTATION

  FINAL VERDICT:
  [PASS — QC CROSS-SCOPE READ-ONLY RBAC VERIFIED]
═════════════════════════════════════════════════════════════════
```
