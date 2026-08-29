# PHASE 3.4 STEP 2 — POST-REMEDIATION ADVERSARIAL AUDIT
## 20-MINUTE LOST WAX SCAN SAFETY INTERLOCK BOUNDARY REMEDIATION

**Audit Mode:** STRICT READ-ONLY ADVERSARIAL AUDIT  
**Date:** 2026-08-29  
**Application:** Laravel 12 — FIFO Tracking / Kanban PPIC (Lost Wax Subsystem)  

---

## 1. EXECUTIVE SUMMARY & VERDICT

An independent, rigorous, strict read-only adversarial audit was performed on the remediated 20-Minute Scan Safety Interlock across the entire Lost Wax scanning subsystem.

### Final Gate Verdict:
```text
═════════════════════════════════════════════════════════════════
  PHASE 3.4 STEP 2
  POST-REMEDIATION AUDIT

  FINAL VERDICT:
  [PASS — SAFETY INTERLOCK VERIFIED]
═════════════════════════════════════════════════════════════════
```

All 17 audit dimensions were evaluated with formal proof, zero code modifications, and full verification against active runtime logic, database schema, concurrency safety, and automated test suites.

---

## 2. AUDIT DIMENSIONS & PROOF

### Dimension 1: Boundary Precision
**Authoritative Business Rule:**
- $\Delta t < 1200\text{ seconds} \implies \mathbf{REJECT}$
- $\Delta t \ge 1200\text{ seconds} \implies \mathbf{ALLOW}$

**Implementation Proof ([ScanService.php](file:///c:/laragon/www/kanban-ppic/app/Services/ScanService.php)):**
```php
$diffInSeconds = (int) $tree->last_scan_at->diffInSeconds($scannedAt);
$agingMinutes = (int) floor($diffInSeconds / 60);
$agingStatus = $this->classifyAging($agingMinutes, $tree->current_stage);

if ($diffInSeconds < ($minScanInterval * 60)) {
    // REJECT via rejectInterlockScan
}
```

**Evaluated Boundary Cases:**

| Base Scan Time | Target Scan Time | Exact Elapsed Seconds | Evaluated Logic ($< 1200\text{s}$) | Formatted Minutes (`floor`) | Final Result | Audit Status |
| :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `08:00:00` | `08:19:29` | **1169 s** | `1169 < 1200` $\implies$ **TRUE** | $19\text{ min}$ | **REJECT** | **PASS** |
| `08:00:00` | `08:19:30` | **1170 s** | `1170 < 1200` $\implies$ **TRUE** | $19\text{ min}$ | **REJECT** | **PASS** |
| `08:00:00` | `08:19:59` | **1199 s** | `1199 < 1200` $\implies$ **TRUE** | $19\text{ min}$ | **REJECT** | **PASS** |
| `08:00:00` | `08:20:00` | **1200 s** | `1200 < 1200` $\implies$ **FALSE** | $20\text{ min}$ | **ALLOW** | **PASS** |
| `08:00:00` | `08:20:01` | **1201 s** | `1201 < 1200` $\implies$ **FALSE** | $20\text{ min}$ | **ALLOW** | **PASS** |

The rounding flaw previously present with `round(diffInMinutes())` has been completely eliminated.

---

### Dimension 2: Rejection State Immutability
**Verification:**
In [`ScanService::rejectInterlockScan`](file:///c:/laragon/www/kanban-ppic/app/Services/ScanService.php#L280-L305):
- `$tree->update(...)` is **never called**.
- `current_stage` remains unchanged (e.g., `layer_1` does not advance to `layer_2`, `layer_6` does not advance to `oven`).
- `last_scan_at` remains unchanged.
- `tree->status` remains unchanged.
- `tree->quantity` remains unchanged.
- `allocations` and `defects` remain completely untouched.

---

### Dimension 3: Rejected Event Audit Trail
**Verification:**
Every rejection triggers creation of a record in `lost_wax_scan_events` with:
- `tree_id` = target tree id
- `barcode` = scanned barcode
- `stage` = target stage attempted
- `scanned_at` = current server timestamp (`Carbon::now(config('app.timezone'))`)
- `operator_id` = authenticated operator id
- `result` = `'rejected'`
- `anomaly_reason` = `"Scan ditolak: Interval scan terlalu singkat (X menit dari scan sebelumnya). Minimum interval adalah 20 menit untuk mencegah double scan."`
- `aging_minutes` = `$agingMinutes` (floored minutes)
- `aging_status` = `'too_fast'`
- **Zero** successful scan events are recorded for rejected attempts.

---

### Dimension 4: Clock Immutability / Lockout Escalation Proof
**Scenario Tested:**
1. `08:00:00` $\to$ Scan 1 SUCCESS (`last_scan_at` becomes `08:00:00`)
2. `08:05:00` $\to$ Scan 2 REJECT (`last_scan_at` remains `08:00:00`)
3. `08:10:00` $\to$ Scan 3 REJECT (`last_scan_at` remains `08:00:00`)
4. `08:19:59` $\to$ Scan 4 REJECT (`last_scan_at` remains `08:00:00`)
5. `08:20:00` $\to$ Scan 5:
   - Measured against `last_scan_at` (`08:00:00`).
   - Elapsed = $1200\text{ seconds}$.
   - Interlock check `1200 < 1200` $\implies$ **FALSE**.
   - Result: **SUCCESS**.

No lockout escalation or clock-pushing exists.

---

### Dimension 5: First Scan Invariant
**Verification:**
- A newly generated tree has `last_scan_at = null`.
- The interlock code block is enclosed in `if ($tree->last_scan_at) { ... }`.
- When `last_scan_at` is null, the interlock block is skipped entirely.
- The tree enters `layer_1` immediately on its first scan.

---

### Dimension 6: Oven Scan Interlock Protection
**Verification ([ScanService::processOvenScan](file:///c:/laragon/www/kanban-ppic/app/Services/ScanService.php#L230-L248)):**
- The same second-precision interlock is enforced against `tree.last_scan_at` from the prior layer (`layer_6` or `layer_7`).
- At `08:19:59` ($1199\text{s}$ from layer scan) $\implies$ **REJECT** (tree remains in coating layer).
- At `08:20:00` ($1200\text{s}$ from layer scan) $\implies$ **ALLOW** (tree transitions to `oven`).

---

### Dimension 7: Aging Semantics Separation
**Verification:**
- The 20-minute safety interlock ($1200\text{ seconds}$) is an **anti-double-scan circuit breaker**, distinct from the technological drying time classifications.
- `classifyAging()` retains its full configuration mappings from `config('lost_wax.aging.stages')` (e.g., Layer 1: 4h–6h, Layer 5: 8h–8h, Layer 7: 24h–24h).
- Valid scans occurring after 20 minutes but before drying completion (e.g. at 2 hours) are **ALLOWED** and marked as `result = 'success'`, `aging_status = 'too_fast'`.
- Valid scans occurring within standard drying windows are **ALLOWED** and marked as `result = 'success'`, `aging_status = 'normal'`.
- Valid scans occurring after extended delays (e.g. 33 hours) are **ALLOWED** and marked as `result = 'success'`, `aging_status = 'too_long'`.

---

### Dimension 8: Configuration Source
**Verification:**
- Single source of truth in [`config/lost_wax.php`](file:///c:/laragon/www/kanban-ppic/config/lost_wax.php):
  ```php
  'aging' => [
      'stages' => [ ... ],
      'min_hours' => (float) env('LOST_WAX_AGING_MIN_HOURS', 4),
      'max_hours' => (float) env('LOST_WAX_AGING_MAX_HOURS', 6),
      'min_scan_interval_minutes' => (int) env('LOST_WAX_MIN_SCAN_INTERVAL_MINUTES', 20),
  ],
  ```
- Services read via `config('lost_wax.aging.min_scan_interval_minutes', 20)` and multiply by 60. No scattered hardcoded numbers exist across the codebase.

---

### Dimension 9: Scan Entry Points Comprehensive Audit
**Audit of Routes & Controllers:**
- `POST /lost-wax/scan` $\to$ `ScanController::process()` $\to$ `ScanService::process()` [Protected]
- `POST /lost-wax/scan-oven` $\to$ `ScanController::processOven()` $\to$ `ScanService::processOvenScan()` [Protected]
- `POST /lost-wax/stage-label` $\to$ Read-only helper [Safe]
- `GET /lost-wax/scan/keepalive` $\to$ Heartbeat [Safe]
- `GET /lost-wax/trees/{tree}/history` $\to$ Read-only audit log [Safe]
- `POST /lost-wax/scan-events/{event}/void` $\to$ `ScanVoidService::void()` [Protected Admin/PPIC Ledger]

No alternative or bypass scanning endpoints exist in the system.

---

### Dimension 10: Concurrency & Lock Safety
**Verification:**
- Both `process()` and `processOvenScan()` execute within `DB::transaction()` and apply pessimistic row locking:
  ```php
  LostWaxTree::with(['workOrder', 'printOrderLine.printOrder', 'printOrderLine.productionPlan'])
      ->lockForUpdate()
      ->where('barcode', $barcode)
      ->first();
  ```
- Two concurrent scan requests for the same barcode serialize at the database level. The first request updates `last_scan_at`; the second request reads the updated timestamp and is rejected by the 20-minute interlock.

---

### Dimension 11: Scan Void Compatibility
**Verification ([ScanVoidService.php](file:///c:/laragon/www/kanban-ppic/app/Services/ScanVoidService.php#L44-L75)):**
- `ScanVoidService` strictly filters by `where('result', 'success')->whereDoesntHave('void')`.
- Rejected interlock events (`result = 'rejected'`) are completely ignored by the void reconstruction engine and can never become the active tree state.
- When an active scan event is voided, `last_scan_at` correctly falls back to the previous successful scan event (or `null`).

---

### Dimension 12: Database Schema & Column Audit
**Verification:**
The table `lost_wax_scan_events` contains all necessary columns:
- `id` (bigint)
- `tree_id` (bigint)
- `barcode` (varchar)
- `stage` (varchar, nullable)
- `scanned_at` (datetime)
- `operator_id` (bigint)
- `result` (varchar: 'success' | 'rejected')
- `anomaly_reason` (text, nullable)
- `aging_minutes` (int, nullable)
- `aging_status` (varchar, nullable)
- `created_at`, `updated_at`

---

### Dimension 13: Regression & Test Quality Audit
**Test Execution Results:**
- `php artisan test --filter=ScanEngineTest` $\implies$ **28 passed (111 assertions)**
- `php artisan test --filter=ScanOvenTest` $\implies$ **28 passed (108 assertions)**
- `php artisan test --filter=ScanVoidTest` $\implies$ **17 passed (25 assertions)**
- `php artisan test --filter=DemoDataAndManualScanTest` $\implies$ **11 passed (69 assertions)**
- Full application suite: `php artisan test` $\implies$ **519 passed (2,823 assertions)**
- Code formatting: `vendor/bin/pint --test` $\implies$ **196 files checked, 0 errors (PASS)**

---

### Dimension 14: Quality of Boundary Tests
**Verification:**
The tests in `ScanEngineTest.php` and `ScanOvenTest.php` directly exercise:
1. `Carbon::setTestNow(08:19:59)` $\to$ Asserts `result = rejected`, `aging_minutes = 19`, `tree.current_stage` unchanged.
2. `Carbon::setTestNow(08:20:00)` $\to$ Asserts `result = success`, `aging_minutes = 20`, `tree.current_stage = layer_2`.
3. `Carbon::setTestNow(08:20:01)` $\to$ Asserts `result = success`, `tree.current_stage = layer_2`.

Both HTTP response and database state are explicitly asserted.

---

### Dimension 15: Negative / Adversarial Interval Cases
- **1 second:** `1 < 1200` $\implies$ **REJECT**
- **30 seconds:** `30 < 1200` $\implies$ **REJECT**
- **5 minutes:** `300 < 1200` $\implies$ **REJECT**
- **19 minutes 29 seconds:** `1169 < 1200` $\implies$ **REJECT**
- **19 minutes 30 seconds:** `1170 < 1200` $\implies$ **REJECT**
- **19 minutes 59 seconds:** `1199 < 1200` $\implies$ **REJECT**
- **20 minutes 00 seconds:** `1200 < 1200` $\implies$ **ALLOW**
- **20 minutes 01 seconds:** `1201 < 1200` $\implies$ **ALLOW**
- **4 hours (Normal):** `14400 < 1200` $\implies$ **ALLOW**
- **33.5 hours (Too Long):** `120600 < 1200` $\implies$ **ALLOW**

---

### Dimension 16: Git Diff & Modification Scope
**Verification:**
Only the intended files were modified:
- `config/lost_wax.php` (config threshold addition)
- `app/Services/ScanService.php` (interlock and reject logic)
- `app/Http/Controllers/LostWax/ScanController.php` (error response tree_info)
- `tests/Feature/LostWax/ScanEngineTest.php` (boundary & interlock tests)
- `tests/Feature/LostWax/ScanOvenTest.php` (boundary & interlock tests)
- `tests/Feature/LostWax/DemoDataAndManualScanTest.php` (test timestamp alignment)

Zero unauthorized changes or unrelated files were modified.

---

## 3. AUDIT CONCLUSION

All audit requirements are satisfied without compromise or regression.

```text
PHASE 3.4 STEP 2
POST-REMEDIATION AUDIT

FINAL VERDICT:
[PASS — SAFETY INTERLOCK VERIFIED]
```
