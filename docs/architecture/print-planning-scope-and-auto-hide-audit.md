# Audit: Print Planning Scope, Authorization & Auto-Hide
**File:** `docs/architecture/print-planning-scope-and-auto-hide-audit.md`  
**Date:** 2026-08-25  
**Status:** AUDIT COMPLETE — READY FOR BUILD (dengan gap terdokumentasi)

---

## 1. EXECUTIVE SUMMARY

Audit dilakukan terhadap halaman `/lost-wax/print-orders/plans` dan seluruh
backend-nya. Ditemukan **3 gap nyata** antara requirement bisnis dan implementasi:

| # | Gap | Severity |
|---|-----|----------|
| G-1 | `adminppicpf@peroniks.com` (role `admin`, `product_scope = null`) dapat melakukan action **"Tutup"**, **"Buka"**, **"Bulk Tutup"**, dan **"Buat Perintah Cetak"** tanpa batasan | 🔴 HIGH |
| G-2 | Auto-hide plan ketika `scheduled >= planned` **sudah bekerja** melalui query, tetapi plan yang fully-scheduled tidak secara otomatis berstatus `closed` — ini by design, namun perlu diklarifikasi | 🟡 INFO |
| G-3 | Tidak ada test yang memverifikasi bahwa **admin** (tanpa `product_scope`) diblokir dari aksi mutasi | 🟡 MEDIUM |

Tidak ditemukan: vulnerability query injection, data leak antar PPIC scope
(untuk akun ppic), atau integrity issue pada historical data.

---

## 2. CURRENT DATA SCOPING

### Mekanisme Scoping

Satu-satunya mekanisme scope adalah kolom `product_scope` pada tabel `users`
dan kolom `product_scope` pada tabel `production_plans`.

Controller memeriksa dua kondisi untuk mengaktifkan filter:

```php
// PrintOrderController.php, baris 17 & 40
$scope   = auth()->user()->product_scope;
$isPpic  = auth()->user()->hasRole('ppic');

if ($isPpic && $scope) {
    $plansQuery->where('product_scope', $scope);
}
```

**Guard kondisi:** `$isPpic && $scope`  
Artinya: filter HANYA aktif jika user memiliki role `ppic` **DAN**
`product_scope` tidak null.

### Data yang Dilihat per Akun

| Akun | Role | `product_scope` | Data Plans yang Dilihat |
|------|------|-----------------|-------------------------|
| `ppicflange@peroniks.com` | `ppic` | `FLANGE_STAINLESS` | Hanya `product_scope = 'FLANGE_STAINLESS'` |
| `ppicfitting@peroniks.com` | `ppic` | `FITTING_STAINLESS` | Hanya `product_scope = 'FITTING_STAINLESS'` |
| `ppicflangebesi@peroniks.com` | `ppic` | `FLANGE_BESI` | Hanya `product_scope = 'FLANGE_BESI'` |
| `adminppicpf@peroniks.com` | `admin` | `null` | **Semua plan dari semua scope** |

### Apakah Scoping Sudah Benar?

**✅ YA — untuk akun ppic.** Filter `product_scope` bekerja dengan benar,
dibuktikan oleh test `RbacScopeTest::test_ppic_scope_filtering_on_plans()`.

**✅ YA — untuk adminppicpf sebagai aggregator view.** Karena kondisi
`$isPpic && $scope` menjadi `false && null = false`, tidak ada filter
diterapkan, sehingga admin melihat semua data lintas scope.

---

## 3. ROOT CAUSE — Data Tutup Masih Muncul pada adminppicpf

**Ini bukan bug — ini by design.**

Ketika `ppicflange` men-close sebuah plan, plan tersebut di-set
`is_closed = true`. Query default (filter `status=active`) menyertakan:

```php
$plansQuery->where('is_closed', false);
```

Plan yang di-close oleh `ppicflange` **tidak muncul** di tampilan aktif.

**Penjelasan yang lebih mungkin dari lapangan:**

Ketika `ppicflange` menutup banyak plan dan halaman menampilkan "0 rencana",
itu berarti semua plan `FLANGE_STAINLESS` sudah di-close atau fully-scheduled.
Ketika `adminppicpf` login dan masih melihat plan, itu adalah plan dari scope
`FITTING_STAINLESS` atau `FLANGE_BESI` yang PPIC-nya belum menutup.

> [!NOTE]
> adminppicpf melihat plan dari scope lain yang belum ditutup oleh PPIC
> pemiliknya. Ini adalah perilaku aggregator yang benar. Tidak ada data
> visibility bug.

---

## 4. ROOT CAUSE — adminppicpf Mendapat Tombol "Tutup"

**Ini adalah GAP NYATA (G-1).**

### Layer UI — Blade

```blade
{{-- plans.blade.php, baris 135–147 --}}
@if(!$plan->is_closed)
    <button ... onclick="submitSingleAction('close_plan', ...)">
        Tutup
    </button>
@else
    <button ... onclick="submitSingleAction('open_plan', ...)">
        Buka
    </button>
@endif
```

**Tidak ada kondisi role di Blade.** Tombol "Tutup" dan "Buka" ditampilkan
kepada **semua** user yang memiliki akses ke halaman ini, termasuk `adminppicpf`.

### Layer Backend — Controller

```php
// PrintOrderController::store(), baris 161–169
if ($request->input('action') === 'close_plan') {
    $plan = \App\Models\ProductionPlan::findOrFail($planId);
    if ($isPpic && $scope && $plan->product_scope !== $scope) {
        abort(403, 'Unauthorized.');
    }
    $plan->update(['is_closed' => true]); // <-- eksekusi tanpa guard untuk admin
}
```

Guard backend: `$isPpic && $scope && plan_scope_mismatch`

Untuk `adminppicpf` (role `admin`, scope `null`):
- `$isPpic` = `false`
- `$scope` = `null`
- `false && null && ...` = **false** → guard TIDAK aktif
- **Mutation berlanjut tanpa halangan**

### Summary G-1

| Layer | adminppicpf bisa aksi "Tutup"? |
|-------|-------------------------------|
| UI (Blade) | ✅ Tombol muncul — tidak ada kondisi role |
| Backend (Controller store) | ✅ Guard tidak aktif — tidak ada 403 |
| **Akses langsung via HTTP** | ✅ Dapat dieksekusi langsung via POST |

---

## 5. ROOT CAUSE — adminppicpf Dapat Membuat Print Order

**Bagian dari GAP G-1.**

### Backend — `create()` method

```php
// PrintOrderController::create(), baris 123–133
$scope  = auth()->user()->product_scope;
$isPpic = auth()->user()->hasRole('ppic');

if ($isPpic && $scope) {            // <-- untuk admin: false && null = false
    $unauthorizedCount = ProductionPlan::whereIn('id', $planIds)
        ->where('product_scope', '!=', $scope)
        ->count();
    if ($unauthorizedCount > 0) {
        abort(403, 'Unauthorized.');
    }
}
// Tidak ada guard lain. Admin lanjut ke halaman create.
```

### Backend — `store()` method (simpan PO baru)

```php
// PrintOrderController::store(), baris 219–227
foreach ($request->items as $itemData) {
    $plan = ProductionPlan::findOrFail($itemData['production_plan_id']);
    if ($isPpic && $scope && $plan->product_scope !== $scope) { // false untuk admin
        abort(403, 'Unauthorized.');
    }
    // Admin melanjutkan, Print Order dibuat
}
```

### Kesimpulan

`adminppicpf` dapat:
1. Membuka halaman `create` Print Order untuk plan dari scope manapun
2. Menyimpan Print Order baru ke database untuk plan dari scope manapun
3. Melakukan ini langsung via HTTP POST tanpa UI

---

## 6. CURRENT PLAN COMPLETION LOGIC

### Definisi "Tutup" (is_closed)

`is_closed` adalah **flag manual** yang di-set oleh PPIC secara eksplisit.
**Bukan** flag otomatis yang berubah ketika `scheduled >= planned`.

Tidak ada trigger, observer, atau event yang mengubah `is_closed` secara otomatis.

### Kapan Plan Tidak Muncul di Active Pool

Active pool (`status=active`) menggunakan dua kondisi `AND`:

```php
// PrintOrderController::plans(), baris 62–71
$plansQuery->where('is_closed', false);

$subquery = DB::table('lost_wax_print_order_lines')
    ->join('lost_wax_print_orders', ...)
    ->whereColumn('lost_wax_print_order_lines.production_plan_id', 'production_plans.id')
    ->whereIn('lost_wax_print_orders.status', ['DRAFT', 'ISSUED'])
    ->selectRaw('COALESCE(SUM(lost_wax_print_order_lines.qty_ordered), 0)');

$plansQuery->whereRaw('qty_planned > (' . $subquery->toSql() . ')', $subquery->getBindings());
```

**Formula eksak:**

```sql
Active Pool = (is_closed = false)
          AND (qty_planned > SUM(qty_ordered FROM print_order_lines
                                 JOIN print_orders ON status IN ('DRAFT','ISSUED')))
```

---

## 7. CURRENT REMAINING TO SCHEDULE LOGIC

### Formula di Model `ProductionPlan`

```php
// ProductionPlan.php

// qty_scheduled: hanya PO DRAFT atau ISSUED
public function getQtyScheduledAttribute(): int
{
    return (int) $this->printOrderLines()
        ->whereHas('printOrder', function ($query) {
            $query->whereIn('status', ['DRAFT', 'ISSUED']);
        })
        ->sum('qty_ordered');
}

// qty_remaining_scheduled: selisih planned vs scheduled
public function getQtyRemainingScheduledAttribute(): int
{
    return $this->qty_planned - $this->qty_scheduled;
}

// qty_produced: actual good dari PO yang tidak CANCELLED
public function getQtyProducedAttribute(): int
{
    return (int) $this->printOrderLines()
        ->whereHas('printOrder', function ($query) {
            $query->where('status', '!=', 'CANCELLED');
        })
        ->sum('qty_executed_good');
}
```

**Poin penting:**
- `qty_scheduled` = berbasis `COMMAND` (qty_ordered), **bukan actual produced**
- `qty_remaining_scheduled` = `qty_planned - qty_scheduled`
- `CANCELLED` PO **dikecualikan** dari `qty_scheduled`
- Auto-hide berbasis **SCHEDULED**, bukan **ACTUAL** — sesuai requirement ✅

---

## 8. AUTO-HIDE ANALYSIS

### Apakah Auto-Hide Sudah Diimplementasi?

**✅ YA — sudah berjalan.**

Query active pool memfilter:
```
qty_planned > SUM(qty_ordered dari PO aktif)
```

Plan yang fully-scheduled (`remaining = 0`) **tidak muncul** di active pool
tanpa perlu manual close.

### Dua Konsep yang Berbeda

| Konsep | Mekanisme | Trigger | Efek |
|--------|-----------|---------|------|
| **Auto-hide** | Query filter | `qty_planned <= sum_scheduled` | Plan tidak muncul di tab `active` |
| **Manual close** | `is_closed = true` | PPIC klik tombol "Tutup" | Plan muncul di tab `closed`, tidak di `active` |

Keduanya adalah mekanisme terpisah dan tidak saling menggantikan.

### Plan Fully-Scheduled Tanpa Manual Close

- Status: `is_closed = false`
- `qty_remaining_scheduled = 0`
- **Tidak muncul di tab `active`** (auto-hide bekerja)
- **Tidak muncul di tab `closed`** (belum di-close manual)
- **Muncul di tab `all`** (tanpa filter)

Ini dapat menjadi sumber kebingungan jika PPIC mencari plan tersebut
di tab `closed` namun tidak menemukannya.

---

## 9. CANCELLED PRINT ORDER BEHAVIOR

### Implementasi

`CANCELLED` PO **dikecualikan** dari `qty_scheduled` di semua tempat:

```php
// Model accessor: hanya DRAFT/ISSUED
->whereIn('status', ['DRAFT', 'ISSUED'])

// Controller active pool subquery: hanya DRAFT/ISSUED
->whereIn('lost_wax_print_orders.status', ['DRAFT', 'ISSUED'])
```

### Edge Cases Verifikasi

| Case | Skenario | Aktual | Benar? |
|------|----------|--------|--------|
| CASE 1 | Planned=1000, Scheduled=900 → Remaining=100 | Muncul di active | ✅ |
| CASE 2 | Planned=1000, Scheduled=1000 → Remaining=0 | Tidak muncul di active | ✅ |
| CASE 3 | Planned=1000, Scheduled=1100 → Remaining=-20 | Tidak muncul (qty_planned tidak > sum) | ✅ |
| CASE 4 | Planned=1000, Scheduled=1000, Actual Good=950 | Tidak muncul (berbasis scheduled bukan actual) | ✅ |
| CASE 5 | PO CANCELLED 300 → Effective Scheduled=700, Remaining=300 | Kembali muncul di active | ✅ |
| CASE 6 | PO1=500+PO2=200+PO3=300=1000, Remaining=0 | Tidak muncul | ✅ |

Semua edge case sudah ditangani dengan benar di level query.

---

## 10. AUTHORIZATION / SECURITY ANALYSIS

### Route Middleware Stack

```
GET/POST /lost-wax/print-orders/*
└─ middleware: auth
   └─ middleware: permission:access_planning
      └─ PrintOrderController
```

`adminppicpf` memiliki permission `access_planning` via role `admin`:
```php
// DatabaseSeeder.php, baris 26
$adminRole->syncPermissions([$accessPlanning, $accessExecution]);
```

Sehingga `adminppicpf` lolos middleware dan masuk ke semua route planning.

### Authorization Matrix per Action

| Action | Route Middleware | Backend Guard | UI Guard | adminppicpf Dapat Akses? |
|--------|-----------------|---------------|----------|--------------------------|
| Lihat semua plans | `access_planning` | scope filter ppic only | — | ✅ Read-only semua scope |
| Close plan (single) | `access_planning` | `$isPpic && $scope` | Tidak ada | 🔴 **YES — GAP** |
| Open plan | `access_planning` | `$isPpic && $scope` | Tidak ada | 🔴 **YES — GAP** |
| Bulk close plans | `access_planning` | `$isPpic && $scope` | Tidak ada | 🔴 **YES — GAP** |
| Create PO (halaman) | `access_planning` | `$isPpic && $scope` | Tidak ada | 🔴 **YES — GAP** |
| Store PO (simpan baru) | `access_planning` | `$isPpic && $scope` | Tidak ada | 🔴 **YES — GAP** |
| Edit PO | `access_planning` | `authorizePrintOrder()` | — | ✅ Terlindungi jika PO lintas scope |
| Update PO | `access_planning` | `authorizePrintOrder()` | — | ✅ Terlindungi |
| Cancel/Status PO | `access_planning` | `authorizePrintOrder()` | — | ✅ Terlindungi |
| Delete PO | `access_planning` | `authorizePrintOrder()` | — | ✅ Terlindungi |

> [!WARNING]
> `authorizePrintOrder()` melindungi edit/update/cancel/delete PO dengan benar.
> Namun `close_plan`, `open_plan`, `bulk_close_plans`, dan pembuatan PO baru
> **tidak menggunakan `authorizePrintOrder()`** — sehingga admin tidak terlindungi.

### Direct HTTP Access Risk

Dengan mengirim POST request langsung ke `/lost-wax/print-orders`:
```
POST /lost-wax/print-orders
action=close_plan&production_plan_id=123
```

`adminppicpf` dapat menutup plan dari scope manapun tanpa melalui UI,
karena tidak ada server-side guard yang memblokir.

---

## 11. DATABASE IMPACT

### Auto-Hide — Zero DB Impact

Auto-hide adalah pure query filter. Tidak ada perubahan database:

```
production_plans          → tidak berubah
lost_wax_print_orders     → tidak berubah  
lost_wax_print_order_lines → tidak berubah
```

### Fix G-1 — Zero Migration Diperlukan

Fix untuk G-1 hanya memerlukan perubahan pada:
- `app/Http/Controllers/LostWax/PrintOrderController.php`
- `resources/views/lost-wax/print-orders/plans.blade.php`

**Tidak ada migration database yang diperlukan.**

---

## 12. REQUIRED BUILD CHANGES

### FIX-1 (WAJIB): Blokir admin dari mutasi plan

**File:** `PrintOrderController.php` — method `store()`

Tambahkan guard di awal `close_plan`, `open_plan`, dan `bulk_close_plans`:

```php
// Prinsip: hanya ppic dengan scope tertentu yang boleh mutasi
if (!($isPpic && $scope)) {
    abort(403, 'Hanya PPIC owner yang dapat menutup atau membuka rencana produksi.');
}
```

**File:** `PrintOrderController.php` — method `create()` dan `store()` (untuk PO baru)

Tambahkan guard yang memblokir user tanpa `product_scope`:

```php
if (!($isPpic && $scope)) {
    abort(403, 'Hanya PPIC owner yang dapat membuat Perintah Cetak.');
}
```

**File:** `plans.blade.php`

Tambahkan kondisi role untuk semua tombol mutasi:

```blade
@if(auth()->user()->hasRole('ppic') && auth()->user()->product_scope)
    {{-- Tombol Tutup / Buka --}}
    {{-- Tombol Bulk Tutup --}}
    {{-- Tombol Buat Perintah Cetak --}}
@endif
```

> [!CAUTION]
> UI guard saja **tidak cukup**. Backend guard wajib ditambahkan
> karena endpoint dapat diakses langsung via HTTP.

### FIX-2 (OPSIONAL): Informasi status plan fully-scheduled

Pertimbangkan menambahkan indikator visual di tab `all` untuk membedakan:
- Plan fully-scheduled (auto-hidden dari active, belum di-close manual)
- Plan yang sudah di-close manual

Tidak memerlukan perubahan database atau migration.

---

## 13. REQUIRED TEST CASES

### Test yang Sudah Ada

| Test | File | Status |
|------|------|--------|
| SPV diblokir dari planning | `RbacScopeTest` | ✅ Ada |
| PPIC scope filtering plans list | `RbacScopeTest` | ✅ Ada |
| PPIC tidak bisa close scope lain | `RbacScopeTest` | ✅ Ada |
| PPIC bisa close plan scopenya sendiri | `RbacScopeTest` | ✅ Ada |
| Cancelled PO dikecualikan dari scheduled | `PrintOrderTest` | ✅ Ada |
| Fully scheduled plan hilang dari active pool | `PrintOrderTest` | ✅ Ada |
| Over-scheduled plan hilang dari active pool | `PrintOrderTest` | ✅ Ada |
| Closed plan tidak muncul di active pool | `PrintOrderTest` | ✅ Ada |
| Multi-day PO / partial scheduling | `PrintOrderTest` | ✅ Ada |
| qty_planned tidak berubah karena overprint | `PrintOrderTest` | ✅ Ada |

### Test yang BELUM Ada — WAJIB Ditambahkan

| # | Test Case | Prioritas |
|---|-----------|-----------|
| T-1 | `admin` (`product_scope=null`) diblokir dari `close_plan` | 🔴 HIGH |
| T-2 | `admin` (`product_scope=null`) diblokir dari `open_plan` | 🔴 HIGH |
| T-3 | `admin` (`product_scope=null`) diblokir dari `bulk_close_plans` | 🔴 HIGH |
| T-4 | `admin` (`product_scope=null`) diblokir dari halaman `create` PO | 🔴 HIGH |
| T-5 | `admin` (`product_scope=null`) diblokir dari `store` PO baru | 🔴 HIGH |
| T-6 | `admin` masih dapat **melihat** semua plans (read-only aggregator) | 🟡 MEDIUM |
| T-7 | Plan fully-scheduled auto-hide tanpa manual close | 🟡 MEDIUM |
| T-8 | PO di-CANCEL → plan kembali muncul di active pool dengan sisa yang benar | 🟡 MEDIUM |

---

## 14. EDGE CASES

### EC-1: Plan Legacy dengan `product_scope = null`

Plan legacy tanpa `product_scope` tidak akan muncul di tampilan PPIC manapun
karena `WHERE product_scope = 'FLANGE_STAINLESS'` tidak akan match `null`.

Plan ini hanya terlihat oleh `admin`. Tidak ada bug, tetapi perlu awareness
jika ada data legacy di production.

### EC-2: `admin` dengan `product_scope` tidak null

Jika admin diberi `product_scope`, kondisi `$isPpic && $scope` tetap `false`
(karena role bukan `ppic`). Scope admin tidak pernah dipakai untuk filtering.

Potensi kebingungan jika admin melihat semua data meskipun punya scope.

### EC-3: Cancelled PO → Plan Kembali ke Active Pool

Ketika PO di-CANCEL:
1. `qty_scheduled` turun (CANCELLED dikecualikan)
2. `qty_remaining_scheduled` naik
3. Kondisi `qty_planned > sum_scheduled` menjadi true
4. Plan kembali muncul di active pool secara otomatis

Ini sudah benar dan diverifikasi dari kode.

---

## 15. RISK ASSESSMENT

| Risk | Probability | Impact | Severity |
|------|------------|--------|----------|
| adminppicpf menutup plan PPIC lain secara tidak sengaja | HIGH | HIGH — plan hilang dari active pool PPIC owner | 🔴 CRITICAL |
| adminppicpf membuat Print Order dari scope manapun | MEDIUM | HIGH — PO tanpa ownership jelas | 🔴 HIGH |
| Plan fully-scheduled tidak terlihat di tab closed (user confusion) | MEDIUM | LOW — data tetap ada, hanya UX | 🟡 MEDIUM |
| Plan legacy tanpa product_scope tidak terlihat oleh PPIC | LOW | MEDIUM | 🟡 MEDIUM |

---

## 16. STATUS TUTUP vs FULLY SCHEDULED

### Dua Konsep Berbeda — Terbukti dari Kode

**A. Manual Close (`is_closed = true`)**
- Trigger: PPIC klik tombol "Tutup" → POST action=close_plan
- Effect: Plan muncul di tab `closed`, tidak di tab `active`
- Reversible: YA (via tombol "Buka")
- Berkaitan dengan qty: TIDAK (tidak ada cek qty sebelum close)

**B. Auto-hide dari Active Pool**
- Trigger: `qty_planned <= SUM(qty_ordered dari PO DRAFT/ISSUED)`
- Effect: Plan tidak muncul di tab `active`
- Tidak mengubah `is_closed`
- Plan tetap ada di tab `all`

**C. Keduanya BUKAN hal yang sama**

Skenario:
- `is_closed = false` + `remaining = 0` → Auto-hidden dari active, TIDAK di tab closed
- `is_closed = true` + `remaining > 0` → Muncul di tab closed, meski masih ada sisa (manual close override)

---

## 17. FINAL VERDICT

```
STATUS: READY FOR BUILD
```

**Prasyarat sebelum deploy ke production:**

1. **G-1 WAJIB diperbaiki** — Backend guard pada `close_plan`, `open_plan`,
   `bulk_close_plans`, `create`, dan `store` harus memblokir user tanpa
   role `ppic` + `product_scope`. UI harus menyembunyikan tombol-tombol
   tersebut dari admin.

2. **Test T-1 s/d T-8 WAJIB ditambahkan** bersamaan dengan fix G-1.

3. **G-2 adalah by-design** — Auto-hide sudah bekerja dengan benar
   berbasis SCHEDULED qty. Tidak ada perubahan database diperlukan.

4. **Tidak ada migration diperlukan** untuk semua fix di atas.

5. **Historical data aman** — `qty_planned` tidak pernah diubah oleh
   auto-hide, manual close, atau open. Semua perubahan hanya pada
   `is_closed` flag.
