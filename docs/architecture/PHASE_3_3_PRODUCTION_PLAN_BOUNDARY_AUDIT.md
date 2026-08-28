# PHASE 3.3 PRODUCTION PLAN BOUNDARY & CROSS-MODULE LINEAGE AUDIT
**Root Cause Analysis of Cross-Module Contamination Between Lost Wax and Cor Subsystems**

---

### 1. Executive Summary
```
====================================================================================================
AUDIT RESULT & CLASSIFICATION:
[CATEGORY B & C] — QUERY/RELATION BUG + ARCHITECTURAL COUPLING CONFIRMED
====================================================================================================
```
1. **Root Cause Definitif**: Modul input Cor (`/input/cor` via `InputController::store`) dan pembuatan rencana lama (`/plan/create` via `PlanController::store`) mengaitkan data produksi Cor (`ProductionItem`) ke `ProductionPlan` menggunakan **`item_code`**, sama sekali **mengabaikan `code` (Production Code)**.
2. **Dampak Cross-Contamination Nyata**:
   - Rencana produksi Lost Wax (misal `268ETB748`, `268ETB785`, `268ETB788`, `BA61`, `UN55`, `UN66`) mengalami pencemaran data di mana `qty_remaining` dikurangi oleh hasil cor dari Production Code yang sama sekali berbeda (misal `CR02`, `ETB524`, `ETB526`, `UN39`, `S631`).
   - Rencana Lost Wax secara keliru menampilkan angka "Hasil Cor" dan berstatus `completed` padahal belum pernah dicor.
3. **Penyebab Blokir Hapus (*Delete Protection*)**:
   - Pesan *"Tidak bisa menghapus rencana yang sudah memiliki data produksi"* pada `PlanController::destroy` dipicu oleh `$plan->items()->exists()`. Relasi `items()` ini menangkap record `ProductionItem` yang salah cantol akibat greedy matching `item_code`. Rencana Lost Wax yang bersih dari SPK cetak menjadi tidak bisa dihapus karena tersandera data Cor fiktif.
4. **Tindakan yang Dibutuhkan**: Memutus relasi dan auto-linking berbasis `item_code`, mengisolasi `ProductionPlan` khusus Lost Wax berdasarkan `code` (Production Code) sebagai aggregate root murni, serta memisahkan alur input PPIC Cor konvensional.

---

### 2. Current Architecture (As-Is)
Saat ini terdapat dua subsistem yang berbagi tabel `production_plans` dengan asumsi boundary yang saling bertolak belakang:

```
                               ┌──────────────────────────┐
                               │   production_plans       │
                               │   (Shared / Single Table)│
                               └────────────┬─────────────┘
                                            │
                    ┌───────────────────────┴───────────────────────┐
                    │ Relasi via item_code                          │ Relasi via production_plan_id
                    ▼ (Greedy FIFO Match)                           ▼ (Strict ID/Code Traceability)
        ┌────────────────────────┐                      ┌────────────────────────┐
        │    MODUL COR (LAMA)    │                      │   MODUL LOST WAX       │
        ├────────────────────────┤                      ├────────────────────────┤
        │ • /plan/create         │                      │ • /lost-wax/print-orders│
        │ • /kanban/rencana_cor  │                      │ • SPK (PrintOrder)     │
        │ • /input/cor           │                      │ • Wax Trees / Oven     │
        │ • production_items     │                      │ • Quality / Recovery   │
        └────────────────────────┘                      └────────────────────────┘
                    │                                               │
                    ▼                                               ▼
         Item Code Boundary                                Production Code Boundary
    (Mengabaikan Production Code)                      (1 Plan = 1 Production Code)
```

---

### 3. `/plan/create` Data Flow & Field Mapping

#### A. Data Ingestion Lifecycle
- **Route**: `GET /plan/create` & `POST /plan` (`PlanController::create` / `store`).
- **UI Component**: Handsontable Grid (`resources/views/plan/create.blade.php`).
- **Target Table**: `production_plans`.

#### B. Field Mapping Table (UI vs Database)
| Field UI | Database Column | Table | Source | Wajib / Opsional | Keterangan Bisnis |
|---|---|---|---|---|---|
| **Code** | `code` | `production_plans` | User Input (Excel Paste) | *Nullable* di Controller | **Production Code** (Kunci identitas Lost Wax) |
| **Item Code** | `item_code` | `production_plans` | User Input (Excel Paste) | **Required** | Kode Master Barang (Bukan kode batch) |
| **Item Name** | `item_name` | `production_plans` | User Input (Excel Paste) | **Required** | Deskripsi Produk |
| **AISI** | `aisi` | `production_plans` | User Input (Excel Paste) | *Nullable* | Material Grade (304, 316, dll.) |
| **Size** | `size` | `production_plans` | User Input (Excel Paste) | *Nullable* | Ukuran (misal `DN 40`, `1/2"`) |
| **Weight** | `weight` | `production_plans` | User Input (Excel Paste) | *Nullable* | Berat satuan produk (Kg) |
| **P.O. Number** | `po_number` | `production_plans` | User Input (Excel Paste) | **Required** | Nomor Purchase Order Customer |
| **Qty Plan** | `qty_planned` | `production_plans` | User Input (Excel Paste) | **Required** | Target Kuantitas Produksi Internal |
| **Line** | `line_number` | `production_plans` | User Input (1–4) | **Required** | Jalur/Line Kanban Cor |
| **Customer** | `customer` | `production_plans` | Dropdown / Input | *Nullable* | Nama Customer Pemesan |
| *Tidak Ada di UI* | `po_quantity` | `production_plans` | *Migration Phase 1* | *Nullable (Default NULL)* | **PO Quantity** (Komitmen resmi PO customer) |
| *Internal* | `qty_remaining` | `production_plans` | Default = `qty_planned` | **Required** | Sisa kuantitas antrean cor lama |
| *Internal* | `product_scope` | `production_plans` | Auth / Auto-detect | *Nullable* | Scope bisnis (`FLANGE_STAINLESS`, dll.) |

> [!IMPORTANT]
> **PO Number vs PO Quantity pada `/plan/create`**:
> - `po_number` sudah tersimpan di kolom `production_plans.po_number`.
> - `po_quantity` sudah ada di schema database, namun **belum disediakan kolom inputnya** pada grid `/plan/create`. Saat ini `po_quantity` bernilai `NULL` saat plan dibuat dari halaman ini.

---

### 4. `/kanban/rencana_cor` Data Flow
- **Route**: `GET /kanban/rencana_cor` (`KanbanController::index('rencana_cor')`).
- **Query Filter**:
  ```php
  $items = \App\Models\ProductionPlan::where('qty_remaining', '>', 0)
      ->orderBy('line_number')
      ->orderBy('created_at')
      ->get();
  ```
- **Karakteristik**:
  - Kolom Kanban memuat rencana produksi yang `qty_remaining > 0`.
  - Jika `qty_remaining <= 0`, item otomatis hilang dari board Kanban Cor.

---

### 5. Hasil Cor Query Trace (Root Cause Discovery)

#### A. Bagaimana "Hasil Cor" Dihitung di View?
Pada `resources/views/plan/list.blade.php` (Line 86):
```blade
<td class="border border-gray-200 px-3 py-2 text-center font-bold text-green-600">
    {{ number_format($plan->qty_planned - $plan->qty_remaining) }}
</td>
```
Angka **Hasil Cor** murni didasarkan pada nilai kalkulasi: $\mathbf{\text{Hasil Cor}} = \mathbf{\text{qty\_planned}} - \mathbf{\text{qty\_remaining}}$.

#### B. Mengapa `qty_remaining` Berkurang Secara Salah?
Terdapat **dua lokasi kode** yang melakukan auto-decrement dan foreign-key link secara salah:

1. **Pada `InputController::store` saat Dept Cor Input (`app/Http/Controllers/InputController.php:295-306`)**:
   ```php
   // CURRENT FLAWED LOGIC:
   $plan = \App\Models\ProductionPlan::where('item_code', $item['item_code'])
       ->where('line_number', $lineNumber)
       ->where('qty_remaining', '>', 0)
       ->orderBy('created_at', 'asc')
       ->first();

   $planId = null;
   if ($plan) {
       $planId = $plan->id;
       $plan->decrement('qty_remaining', $item['qty_pcs']);
       $plan->update(['status' => ($plan->qty_remaining <= 0 ? 'completed' : 'active')]);
   }

   $newItem = \App\Models\ProductionItem::create([
       'plan_id' => $planId,
       'code' => $item['code'] ?? null,
       ...
   ]);
   ```

2. **Pada `PlanController::store` saat Plan Baru Disimpan (`app/Http/Controllers/PlanController.php:164-189`)**:
   ```php
   // Auto-detect and link any existing unassigned "Cor" items
   $query = \App\Models\ProductionItem::whereNull('plan_id')
       ->where('item_code', $itemCode);

   if ($lineNumber !== null) {
       $query->where('line_number', $lineNumber);
   }

   $unassignedItems = $query->orderBy('created_at', 'asc')->get();

   foreach ($unassignedItems as $item) {
       if ($createdPlan->qty_remaining <= 0) break;

       $item->update([
           'plan_id' => $createdPlan->id,
           'po_number' => $item->po_number ?? $createdPlan->po_number,
           'customer' => $item->customer ?? $createdPlan->customer,
       ]);

       $createdPlan->decrement('qty_remaining', $item->qty_pcs);
   }
   ```

---

### 6. Production Code vs Item Code Semantic Flaw

```
+-----------------------------------------------------------------------------------+
| SEMANTIC MISMATCH:                                                                |
| - Item Code       = Jenis Cetakan / Spesifikasi Produk (Bisa diproduksi berkali-kali) |
| - Production Code = Batch / Lot Perintah Kerja Tunggal (1 Production Plan)        |
+-----------------------------------------------------------------------------------+
```
Karena query hanya mencocokkan `WHERE item_code = ?`, maka batch Cor apa pun yang memiliki `item_code` sama akan langsung memotong kuantitas rencana mana pun yang dibuat lebih awal, meskipun Production Code fisiknya sama sekali berbeda.

---

### 7. Bukti Konkret Cross-Contamination (Database Audit)

Berikut adalah bukti nyata dari database lokal yang disinkronkan dari production:

| Plan ID | Plan Production Code | Item Code | Customer | Qty Planned | Qty Remaining | Tampilan "Hasil Cor" | Item Cor yang Tercantol (`ProductionItem`) | Production Code Fisik Cor | Status Kontaminasi |
|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---|:---:|:---:|
| **353** | `268ETB748` | `4.1011UNIPN06.D0065` | E02 | 400 | -80 | **480 pcs** | Item ID: 128, 129, 403, 404 | **`CR02`** | ❌ **TERKONTAMINASI** |
| **354** | `268ETB785` | `4.1031SPC.MM4065` | E02 | 440 | 35 | **405 pcs** | Item ID: 537, 539, 540, 581, 647, 648, 682 | **`ETB524`** | ❌ **TERKONTAMINASI** |
| **357** | `268ETB788` | `4.1032SPC.MM6065` | E02 | 125 | -35 | **160 pcs** | Item ID: 282, 545, 650 | **`ETB526`** | ❌ **TERKONTAMINASI** |
| **224** | `BA61` | `4.1062150LB.A0050` | - | 11 | -13 | **24 pcs** | Item ID: 59 | **`UN39`** | ❌ **TERKONTAMINASI** |
| **229** | `UN55` | `4.1091150LB.A0025` | - | 352 | 315 | **37 pcs** | Item ID: 294, 562 | **`S631`** | ❌ **TERKONTAMINASI** |
| **231** | `UN66` | `4.1092150LB.A0020` | - | 22 | -34 | **56 pcs** | Item ID: 138, 868 | **`S620`** | ❌ **TERKONTAMINASI** |
| **234** | `UN83` | `4.1092150LB.A0025` | - | 110 | -18 | **128 pcs** | Item ID: 359, 361, 592 | **`S621`** | ❌ **TERKONTAMINASI** |

> [!CAUTION]
> **Kesimpulan Bukti**:
> Production Plan `268ETB748` milik customer `E02` sama sekali belum pernah dicor dengan kode tersebut, namun di tabel tertera Hasil Cor = 480 pcs karena ditarik dari hasil cor item `CR02`.

---

### 8. Delete Protection Root Cause Analysis

#### A. Kode Aktual Pemicu Penolakan Delete
Pada `app/Http/Controllers/PlanController.php:264-267`:
```php
public function destroy(ProductionPlan $plan)
{
    ...
    // Check if there are items associated with this plan
    if ($plan->items()->exists()) {
        return back()->with('error', 'Tidak bisa menghapus rencana yang sudah memiliki data produksi.');
    }

    $plan->delete();
    return back()->with('success', 'Rencana berhasil dihapus.');
}
```

#### B. Mengapa Rencana Lost Wax Tidak Bisa Dihapus?
- Relasi `$plan->items()` merujuk ke: `hasMany(ProductionItem::class, 'plan_id')`.
- Karena auto-link greedy `item_code` pada `InputController::store` atau `PlanController::store`, baris `ProductionItem` milik batch cor lain dipasangi `plan_id = $plan->id`.
- Saat PPIC hendak menghapus rencana Lost Wax yang belum dicetak (atau dibatalkan), `$plan->items()->exists()` mengembalikan `true`.
- **Hasil**: Delete ditolak secara keliru karena sistem mengira rencana memiliki data produksi, padahal data produksi tersebut milik Production Code lain yang salah terhubung.

---

### 9. Lost Wax vs Cor Dependency Map

```
CURRENT SHARED DEPENDENCIES:
-------------------------------------------------------------------------------------
Komponen               Modul Cor (Lama)                 Modul Lost Wax (Baru)
-------------------------------------------------------------------------------------
Model                  ProductionPlan                   ProductionPlan (Aggregate Root)
Tabel                  production_plans                 production_plans
Kolom Kuantitas        qty_planned, qty_remaining       qty_planned, po_quantity, q_usable
Identitas Boundary     item_code (Salah)                code (Production Code)
Relasi Produksi        items() -> ProductionItem        printOrderLines() -> SPK / Trees
View Entry             /plan/create (Handsontable)      /lost-wax/print-orders/plans
-------------------------------------------------------------------------------------
```

---

### 10. Current Schema Analysis & Gaps

1. **`production_plans` Table**:
   - Kolom `code`: Ada (`string`, nullable). Pada Lost Wax, kolom ini adalah **Production Code** utama.
   - Kolom `po_number`: Ada (`string`, nullable).
   - Kolom `po_quantity`: Ada (`integer`, nullable, ditambahkan di Phase 1).
   - Kolom `qty_planned`: Ada (`integer`).
   - Kolom `qty_remaining`: Ada (`integer`), digunakan oleh Cor lama.
   - Kolom `is_closed`, `closure_reason`: Ada (Phase 3.2).
2. **Gap pada `/plan/create`**:
   - `code` belum divalidasi sebagai field wajib unik per scope/batch.
   - `po_quantity` belum ada di form UI Handsontable.
   - Auto-linking ke `ProductionItem` berbasis `item_code` masih aktif dan mencemari database.

---

### 11. Target Architecture (To-Be)

```
                               ┌────────────────────────────────┐
                               │         ProductionPlan         │
                               │  (Clean Lost Wax Root Entity)  │
                               ├────────────────────────────────┤
                               │ • code (Production Code - PK)  │
                               │ • customer                     │
                               │ • item_code & item_name        │
                               │ • aisi, size, weight           │
                               │ • po_number                    │
                               │ • po_quantity (Customer Commit)│
                               │ • qty_planned (Internal Target)│
                               └───────────────┬────────────────┘
                                               │
                                               │ hasMany(printOrderLines)
                                               ▼
                               ┌────────────────────────────────┐
                               │     LostWaxPrintOrderLine      │
                               └───────────────┬────────────────┘
                                               │
                                               ▼
                               ┌────────────────────────────────┐
                               │   PrintExecution / Trees /     │
                               │   Defects / Usable Calculation │
                               └────────────────────────────────┘
```
- **Modul Cor**: Diberikan entitas/jalur terisolasi tanpa merusak integritas `ProductionPlan` Lost Wax.
- **Kemandirian Lost Wax**: `ProductionPlan` Lost Wax tidak lagi memiliki dependensi ke `ProductionItem.plan_id`.

---

### 12. Required Detachment Plan

1. **Putus Auto-Linking Berbasis `item_code`**:
   - Hapus blok auto-detection `ProductionItem::whereNull('plan_id')->where('item_code', ...)` dari `PlanController::store`.
   - Hapus blok `ProductionPlan::where('item_code', ...)->decrement('qty_remaining')` dari `InputController::store` untuk mencegah pemotongan kuantitas antar-kode.
2. **Perbaiki Boundary Matching Cor**:
   - Jika Modul Cor tetap ingin mengaitkan `ProductionItem` ke `ProductionPlan`, pencarian **wajib menggunakan `where('code', $item['code'])`** (bukan `item_code`).
3. **Restrukturisasi Delete Rule pada `PlanController::destroy`**:
   - Bedakan:
     - **Kasus 1 (Aman Dihapus)**: Tidak memiliki SPK Lost Wax (`printOrderLines()->doesntExist()`) DAN tidak memiliki item Cor yang sah (`items()->where('code', $plan->code)->doesntExist()`).
     - **Kasus 2 (Ditolak)**: Memiliki data eksekusi Lost Wax aktif (Print Orders / Trees) atau data Cor sah dengan kode produksi yang sama.
     - **Kasus 3 (Data Nyasar)**: Data Cor dengan `item.code != plan.code` diabaikan/dibersihkan dari relasi blokir.

---

### 13. Data Integrity & Migration Risks

| Risiko | Dampak | Mitigasi yang Direkomendasikan |
|---|---|---|
| **Pembersihan `plan_id` Salah Cantol** | Data historis Cor kehilangan foreign key ke plan salah | Null-kan `plan_id` pada `production_items` di mana `production_items.code != production_plans.code`. Data fisik Cor tetap 100% aman di `production_items` & `production_histories`. |
| **Koreksi `qty_remaining` Plan** | Nilai `qty_remaining` rencana Lost Wax negatif / salah | Rekalkulasi `qty_remaining` berdasarkan pemakaian riil kode yang sah saja. |
| **Downtime / Breaking Change** | Gangguan pada input operator Cor | Perubahan backend query bersifat backward-compatible dan tidak mengubah schema tabel fisik. |

---

### 14. Recommended Implementation Sequence

```
Langkah 1: Nonaktifkan Greedy Item Code Auto-Linking (InputController & PlanController)
       ↓
Langkah 2: Perbaiki Rule Proteksi Delete (Hanya blokir jika ada SPK Lost Wax / Cor ber-Kode Sama)
       ↓
Langkah 3: Tambahkan Kolom PO Quantity pada UI /plan/create
       ↓
Langkah 4: Cleanup Data Salah Cantol (Unlink production_items.plan_id yang beda kode)
       ↓
Langkah 5: Isolasi Penuh Halaman /plan/create Khusus Lost Wax Planning
```

---

### 15. Findings Severity Classification

- **CRITICAL**: **Cross-Contamination Hasil Cor via `item_code`**. Data rencana produksi Lost Wax terdistorsi oleh aktivitas Cor batch lain.
- **HIGH**: **False Delete Blocking**. Rencana Lost Wax terkunci tidak bisa dihapus akibat relasi parasit dari `ProductionItem` lain.
- **MEDIUM**: **Missing `po_quantity` on `/plan/create`**. User belum dapat memasukkan target PO resmi langsung dari grid Handsontable.
- **LOW**: Judul form masih tertulis *"Rencana Cor (Input PPIC)"* padahal digunakan bersama untuk Lost Wax.

---

```
====================================================================================================
FINAL VERDICT: [CATEGORY B & C CONFIRMED — READY FOR ARCHITECTURAL DETACHMENT PLAN]
====================================================================================================
```
*Audit membuktikan secara tak terbantahkan bahwa pencemaran Hasil Cor terjadi karena boundary query menggunakan `item_code` alih-alih `code` (Production Code). Masalah ini murni pada query matching legacy dan foreign-key linkage, sedangkan arsitektur Lost Wax (Phase 1 s.d. 3.2) sudah menggunakan boundary Production Code yang benar.*
