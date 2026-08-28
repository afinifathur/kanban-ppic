# FINAL DESIGN LOCK — LOST WAX SUBSYSTEM
**Traceability, Defect, Quantity Conservation & Recovery Workflow**  
**Kanban PPIC — Investment Casting Subsystem**

---

## 1. Executive Verdict

```
====================================================================================================
               FINAL ARCHITECTURAL DESIGN LOCK — LOST WAX SUBSYSTEM
====================================================================================================
 Canonical Quantity Formula           : LOCKED (Mathematically verified against double counting)
 Quantity Conservation Model         : LOCKED (Material state transformation, not additive sum)
 Scan vs Defect Event Architecture   : LOCKED (Throughput scanning isolated from quality logging)
 Stage Defect Entity Schema           : LOCKED (lost_wax_tree_defects with cumulative tree guards)
 PO vs Plan Target Semantics          : LOCKED (po_quantity as customer target, qty_planned as internal)
 Status Matrix (NORMAL/WARN/CRITICAL) : LOCKED (Deterministic classification based on Q_usable)
 Recovery Pool Lifecycle              : LOCKED (Non-intrusive pool preserving completed planning queue)
 Reprint & Historical Immutability   : LOCKED (New SPKs issued, historical records 100% immutable)
 Production Status Real Data Engine   : LOCKED (Heuristic max(0, cetak - rangkai) permanently purged)
 Multi-line Tree Traceability (5W+1H) : LOCKED (100% resolvable back to PO and physical tree)
 Legacy Data & Scan Backward Safety   : LOCKED (Zero disruption to 214 legacy trees and 510 scans)
====================================================================================================
 FINAL VERDICT: ARCHITECTURALLY SOUND & LOCKED — READY FOR IMPLEMENTATION APPROVAL
====================================================================================================
```

---

## 2. Corrected Quantity Model & Data Origin

Tabel berikut menetapkan definisi, sumber data, dan jaminan keamanan dari double counting untuk setiap metrik kuantitas:

| Komponen Kuantitas | Kode Variabel | Definisi Bisnis | Sumber Data Asli (Source of Truth) | Jaminan Bebas Double Counting |
|---|:---:|---|---|---|
| **Customer PO Target** | $Q_{\text{po}}$ | Kewajiban kontrak pesanan customer | `production_plans.po_quantity` | Hanya sebagai target pembanding (tidak memengaruhi saldo material). |
| **Internal Plan Target** | $Q_{\text{planned}}$ | Rencana produksi internal PPIC (termasuk safety buffer) | `production_plans.qty_planned` | Target pembanding perencanaan awal. |
| **Total Print Scheduled** | $Q_{\text{scheduled}}$ | Total pcs yang sudah diterbitkan SPK Cetak | $\sum \text{lost\_wax\_print\_order\_lines.qty\_ordered}$ | Dihitung dari baris SPK status `DRAFT` atau `ISSUED`. |
| **Print Output Good** | $Q_{\text{print\_good}}$ | Total pcs hasil cetak lilin bagus yang lolos QC cetak | $\sum \text{lost\_wax\_print\_executions.qty\_good}$ (status `FINALIZED`) | Hanya menjumlahkan eksekusi `FINALIZED`. Defect cetak **sudah dikeluarkan**. |
| **Print Defect** | $D_{\text{print}}$ | Total pcs lilin cacat/reject pada tahap cetak | $\sum \text{lost\_wax\_print\_executions.qty\_defect}$ (status `FINALIZED`) | Tercatat mandiri di eksekusi cetak, **tidak pernah masuk** ke $Q_{\text{print\_good}}$. |
| **Unallocated Print Pool** | $Q_{\text{pool}}$ | Saldo lilin cetak bagus yang belum dirangkai ke Tree | $Q_{\text{print\_good}} - Q_{\text{allocated}} - Q_{\text{excess\_closed}}$ | Menampung material standby siap rangkai. |
| **Active Physical Trees** | $Q_{\text{trees\_gross}}$ | Total kuantitas fisik lilin yang terpasang pada Tree aktif | $\sum_{\text{active trees}} \text{lost\_wax\_trees.quantity}$ | Tree berstatus `cancelled` **dikecualikan**. |
| **Rangkai / Stage Defects** | $D_{\text{stage}}$ | Cacat fisik yang terjadi pada stage tertentu | $\sum \text{lost\_wax\_tree\_defects.defect\_qty}$ | Dicatat spesifik per stage (`assembly`, `layer_1`..`layer_7`, `oven`). |
| **Excess Closed** | $Q_{\text{excess\_closed}}$ | Saldo lilin sehat yang ditutup/dilebur kembali secara resmi | `lost_wax_print_order_lines.qty_excess_closed` | Saldo yang secara sadar dihentikan dari proses rangkai. |

---

## 3. Canonical Usable Quantity Formula

### A. Formula Utama (Top-Down dari Hasil Cetak):
$$\mathbf{Q_{\text{usable}}} = \mathbf{Q_{\text{print\_good}}} - \sum \mathbf{D_{\text{tree\_defects}}} - \mathbf{Q_{\text{excess\_closed}}}$$

Dimana:
$$\sum \mathbf{D_{\text{tree\_defects}}} = D_{\text{assembly}} + \sum_{i=1}^{7} D_{\text{layer\_}i} + D_{\text{oven}}$$

### B. Formula Ekuivalen (Bottom-Up dari Fisik di Lantai Produksi):
$$\mathbf{Q_{\text{usable}}} = \mathbf{Q_{\text{unallocated\_pool}}} + \sum_{t \in \text{Active Trees}} \left( \mathbf{Q}_{t} - \sum \mathbf{D}_{t,\text{defects}} \right)$$

### C. Pembuktian Bebas Double Counting (Proof of Correctness):
1. **Defect Cetak ($D_{\text{print}}$)**: Karena $Q_{\text{print\_good}}$ berasal dari `qty_good` (yang sudah memisahkan `qty_defect`), maka $D_{\text{print}}$ **TIDAK DIKURANGKAN LAGI** dari $Q_{\text{print\_good}}$.
2. **Defect Stage ($D_{\text{stage}}$)**: Cacat pada tahap Rangkai, Lapisan 1–7, atau Oven dicatat di entitas `lost_wax_tree_defects`. Kuantitas ini secara murni mengurangi kuantitas usable pohon fisik.
3. **Standby Material ($Q_{\text{pool}}$)**: Material yang belum dirangkai adalah material bagus yang siap pakai, sehingga **TIDAK DIANGGAP DEFECT**.

---

## 4. Quantity Conservation Model

Material tidak pernah diciptakan atau dimusnahkan secara sembarangan di dalam sistem, melainkan bertransformasi status mengikuti hukum kekekalan kuantitas:

$$\mathbf{Q_{\text{print\_good}}} \equiv \mathbf{Q_{\text{unallocated\_pool}}} + \mathbf{Q_{\text{active\_trees\_net}}} + \sum \mathbf{D_{\text{tree\_defects}}} + \mathbf{Q_{\text{excess\_closed}}}$$

```
                                [ TOTAL PRINT GOOD (1280) ]
                                             │
               ┌─────────────────────────────┴─────────────────────────────┐
               ▼                                                           ▼
     [ STANDBY POOL (10) ]                                     [ TOTAL ON TREES (1270) ]
     (Siap dibuatkan Tree)                                                 │
                                                   ┌───────────────────────┴───────────────────────┐
                                                   ▼                                               ▼
                                      [ USABLE IN PROCESS (1250) ]                     [ RECORDED DEFECTS (20) ]
                                      (Layer 1-7 & Oven aktif)                         - Rangkai : 10 pcs
                                                                                       - Layer 1 : 10 pcs
                                                                                       - Oven    : 0 pcs
```

---

## 5 & 6. Scan Event vs Defect Event Architecture

Sistem memisahkan secara tegas antara **Throughput Scanning** (Operator) dan **Quality Logging** (Admin/SPV):

```
┌──────────────────────────────────────────────────────────────────────────────────────────────────┐
│                                   SEPARATION OF CONCERNS                                         │
├─────────────────────────────────────────────────┬────────────────────────────────────────────────┤
│         SCAN EVENT (Workflow Throughput)         │          DEFECT EVENT (Quality Audit)          │
├─────────────────────────────────────────────────┼────────────────────────────────────────────────┤
│ • Aktor: Operator Scanner di area celup/oven   │ • Aktor: Admin / SPV / Quality Control         │
│ • Sifat: Real-time, fast-paced (hanya barcode)  │ • Sifat: Asynchronous, audit-based (form modal) │
│ • Tujuan: Perpindahan stage, timer aging, rak  │ • Tujuan: Pengurangan usable qty, analisis RCA │
│ • Validasi: Urutan sekuensial (Layer 1 -> 2..)  │ • Validasi: defect_qty <= remaining physical   │
│ • Dampak Kuantitas: 0 (Tidak mengubah angka)   │ • Dampak Kuantitas: Mengurangi Q_usable        │
└─────────────────────────────────────────────────┴────────────────────────────────────────────────┘
```

### Penanganan Stage Kejadian vs Waktu Pencatatan:
- **`stage`**: Menyimpan tahapan aktual tempat cacat terjadi (misal: `layer_2` karena retak lapisan 2).
- **`created_at` / `recorded_at`**: Timestamp saat Admin menginput data ke sistem.
- *Contoh Kasus*: Tree saat ini berada di `layer_4`. Admin menemukan bahwa 2 pcs rontok saat proses `layer_2`. Admin memilih `stage = layer_2`. Sistem mencatat `stage = layer_2`, waktu pencatatan = sekarang, dan kuantitas usable pohon berkurang 2 pcs.

---

## 7. `lost_wax_tree_defects` Schema Specification

### A. Tabel Database: `lost_wax_tree_defects`

```sql
CREATE TABLE `lost_wax_tree_defects` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `lost_wax_tree_id` BIGINT UNSIGNED NOT NULL,
    `stage` VARCHAR(20) NOT NULL,
    `defect_qty` INT UNSIGNED NOT NULL,
    `defect_reason` VARCHAR(100) NOT NULL,
    `notes` TEXT NULL,
    `recorded_by` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    INDEX `idx_lw_td_tree` (`lost_wax_tree_id`),
    INDEX `idx_lw_td_stage` (`stage`),
    INDEX `idx_lw_td_recorded_by` (`recorded_by`),
    CONSTRAINT `fk_lw_td_tree` FOREIGN KEY (`lost_wax_tree_id`) REFERENCES `lost_wax_trees` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_lw_td_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### B. Daftar Enum / Nilai Standar `stage`:
- `assembly` (Cacat saat perangkaian pola lilin ke sprue)
- `layer_1`, `layer_2`, `layer_3`, `layer_4`, `layer_5`, `layer_6`, `layer_7` (Cacat celup/coating)
- `oven` (Cacat saat pembakaran/dewaxing)

### C. Daftar Alasan Cacat Standar (`defect_reason`):
- `pola_patah` (Pola lilin patah/rusak)
- `retak_lapisan` (Lapisan slurry/pasir retak)
- `lapisan_rontok` (Slurry mengelupas / rontok)
- `lapisan_tipis` (Ketebalan coating tidak merata)
- `lilin_bocor_dini` (Lilin meleleh sebelum waktu oven)
- `oven_pecah` (Cangkang keramik pecah di dalam oven)
- `lainnya` (Alasan lain dengan penjelasan di `notes`)

---

## 8. Defect Cumulative Validation Engine

Untuk mencegah over-deduction atau kesalahan input manusia:
1. **Rule**: Total seluruh baris defect pada satu Tree tidak boleh melebihi kuantitas awal Tree tersebut:
   $$\sum \text{defect\_qty}_{\text{existing}} + \text{defect\_qty}_{\text{new}} \le \text{tree.quantity}$$
2. **Transaction-Safe Concurrency**:
   Pencatatan defect dijalankan di dalam `DB::transaction()` dengan `LostWaxTree::lockForUpdate()->findOrFail($treeId)`.
3. **Pemberitahuan Error**: Jika melebihi kuantitas yang tersisa, sistem menolak transaksi dengan pesan eksplisit:
   `"Kuantitas defect baru ({new} pcs) melebihi sisa kuantitas fisik pohon yang tersedia ({remaining} pcs dari total {total} pcs)."`

---

## 9. Rangkai Defect Semantics vs Pool / Excess

Empat kondisi pada tahap Rangkai diklasifikasikan secara tegas:

| Status Material | Makna Operasional | Tindakan Sistem | Pengaruh ke Usable |
|---|---|---|:---:|
| **Belum Dirangkai** | Lilin sehat berada di pool cetak | Tetap berada di $Q_{\text{pool}}$ | Usable |
| **Rusak Saat Rangkai** | Lilin patah saat dirakit ke sprue | Dicatat di `lost_wax_tree_defects` (`stage: 'assembly'`) atau dicatat saat closing | Defect (Mengurangi Usable) |
| **Excess Closed** | Lilin sehat sengaja ditutup/dilebur kembali | Dicatat di `qty_excess_closed` | Non-usable (Closed) |
| **Shortage Closed** | RWO ditutup dengan kuantitas parsial | RWO berstatus `CLOSED_WITH_SHORTAGE` | Sisa pool tetap usable |

---

## 10. PO vs Production Plan Target Semantics

Tabel `production_plans` diperluas dengan kolom `po_quantity`:

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                               PRODUCTION PLAN TARGETS                                  │
├─────────────────────────────────────────┬──────────────────────────────────────────────┤
│       PO QUANTITY (po_quantity)         │       PLANNED QUANTITY (qty_planned)         │
├─────────────────────────────────────────┼──────────────────────────────────────────────┤
│ • Target komitmen ke Customer           │ • Target produksi internal lantai pabrik     │
│ • Dasar penentuan CRITICAL              │ • Dasar penentuan WARNING & Safety Buffer    │
│ • Bersifat opsional (Nullable Integer)  │ • Wajib terisi (Integer >= 1)                │
└─────────────────────────────────────────┴──────────────────────────────────────────────┘
```

---

## 11. Deterministic Status Rules: NORMAL / WARNING / CRITICAL

Status dihitung secara deterministik untuk setiap `ProductionPlan`:

```
                                  [ EVALUASI Q_USABLE ]
                                             │
                     ┌───────────────────────┴───────────────────────┐
                     ▼                                               ▼
          [ Q_usable >= Q_planned ]                       [ Q_usable < Q_planned ]
                     │                                               │
             ┌───────┴───────┐                               ┌───────┴───────┐
             ▼               ▼                               ▼               ▼
          NORMAL          NORMAL                  [ po_quantity ADA? ]   [ po_quantity NULL ]
      (Order Surplus) (Sesuai Rencana)                       │               │
                                             ┌───────────────┴───────────────┐  └───────┬───────┘
                                             ▼                               ▼          ▼
                                [ Q_usable >= Q_po ]                 [ Q_usable < Q_po ]  WARNING
                                             │                               │       (Review Plan)
                                             ▼                               ▼
                                          WARNING                        CRITICAL
                                     (Buffer Tergerus,              (Kurang dari PO,
                                      PO Masih Aman)                 Under-delivery)
```

### Matriks Aturan Evaluasi:
1. **NORMAL**: $Q_{\text{usable}} \ge Q_{\text{planned}}$ $\rightarrow$ Target internal tercapai.
2. **WARNING (Review Required)**:
   - Jika $Q_{\text{po}}$ terdefinisi: $Q_{\text{planned}} > Q_{\text{usable}} \ge Q_{\text{po}}$
   - Jika $Q_{\text{po}}$ bernilai `NULL`: $Q_{\text{usable}} < Q_{\text{planned}}$
   - *Aksi*: Muncul di Recovery Pool dengan badge kuning.
3. **CRITICAL (Under-Delivery)**:
   - $Q_{\text{usable}} < Q_{\text{po}}$
   - *Aksi*: Muncul di Recovery Pool dengan badge merah mencolok.

---

## 12. Recovery Pool Lifecycle

Halaman Rencana Cetak (`/lost-wax/print-orders/plans`) dan Monitoring Status (`/lost-wax/production-status`) memiliki alur Recovery Pool:

```
[ Production Plan Aktif ] ──(Dijadwalkan SPK 100%)──> [ Hilang dari Antrean Rencana Cetak ]
                                                                      │
                                                   (Terjadi Defect di Layer/Oven)
                                                                      │
                                                                      ▼
                                                       [ MASUK KE RECOVERY POOL ]
                                                       • Filter: WARNING & CRITICAL
                                                       • is_closed = false
                                                                      │
                                            ┌─────────────────────────┴─────────────────────────┐
                                            ▼                                                   ▼
                                  [ TOMBOL REPRINT ]                              [ TOMBOL CLOSE WITHOUT REPRINT ]
                                            │                                                   │
                                            ▼                                                   ▼
                                  (Terbit SPK Tambahan)                             (Plan Ditutup Resmi)
```

### Data yang Ditampilkan di Recovery Pool:
- Kode Produksi & No PO
- Nama Produk, Customer, AISI, Size
- Target PO ($Q_{\text{po}}$) & Target Plan ($Q_{\text{planned}}$)
- Total Good Cetak, Total Defect (Print + Rangkai + Layer 1–7 + Oven)
- Usable Quantity Aktual ($Q_{\text{usable}}$)
- Defisit Kebutuhan ($\Delta = Q_{\text{planned}} - Q_{\text{usable}}$)
- Badge Status (**WARNING** / **CRITICAL**)
- Tombol Aksi: `[Buat SPK Reprint]` & `[Tutup Tanpa Reprint]`

---

## 13. Reprint Transaction (Historical Immutability)

Ketika user mengeksekusi `[Buat SPK Reprint]`:
1. **Historical Immutability**: SPK Cetak awal (`LostWaxPrintOrder` lama) dan riwayat eksekusi sebelumnya **TIDAK DISENTUH SAMA SEKALI**.
2. **Penerbitan Dokumen Baru**:
   - Dibuat `LostWaxPrintOrder` baru (misal: `PC-20260828-0006`).
   - Dibuat baris `LostWaxPrintOrderLine` baru yang merujuk ke `production_plan_id` yang sama, dengan `qty_ordered = Defisit Kebutuhan`.
3. **Penyatuan Otomatis**: Hasil cetak dari SPK baru ini akan mengalir masuk ke **Kode Produksi Pool yang sama** dan dikonsumsi secara FIFO oleh engine Rangkai yang sudah teruji.

---

## 14. Close Without Reprint

Ketika user memilih `[Tutup Tanpa Reprint]`:
1. **Perekaman Alasan**: User wajib mengisi modal konfirmasi berisi `closure_reason` (misal: *"Customer menyetujui pengiriman partial 1150 pcs"*).
2. **Update Status**: `production_plans` diperbarui:
   - `is_closed = true`
   - `closure_reason = "..."`
   - `closed_by = auth()->id()`
   - `closed_at = now()`
3. **Dampak**: Production Plan keluar dari antrean Recovery Pool, namun seluruh riwayat cetak, defect per stage, dan traveler pohon tetap tersimpan utuh di database.

---

## 15. Production Status Calculation (Pembersihan Heuristik Lama)

Di `ProductionStatusController`:
1. **Penghapusan Heuristik**: Rumus `$rangkai_defect = max(0, $cetak_good - $rangkai_good)` **dihapus permanen**.
2. **Sumber Data Riil**:
   - `total_cetak_good` = $\sum \text{line.qty\_executed\_good}$
   - `total_cetak_defect` = $\sum \text{line.qty\_executed\_defect}$
   - `total_rangkai_defect` = $\sum \text{tree\_defects WHERE stage = 'assembly'}$
   - `total_layer_defect` = $\sum \text{tree\_defects WHERE stage LIKE 'layer\_%'}$
   - `total_oven_defect` = $\sum \text{tree\_defects WHERE stage = 'oven'}$
   - `overall_defect` = $\text{cetak\_defect} + \text{rangkai\_defect} + \text{layer\_defect} + \text{oven\_defect}$
   - `usable_qty` = $\text{total\_cetak\_good} - (\text{rangkai\_defect} + \text{layer\_defect} + \text{oven\_defect} + \text{excess\_closed})$
3. **Pemisahan Standby Material**: Saldo pool yang belum dirangkai ditampilkan sebagai kolom tersendiri (*"Standby Cetak"*), bukan sebagai barang cacat.

---

## 16 & 17. UI Design: Tree Detail & Defect Entry Modal

### Layout Halaman `/lost-wax/trees/{id}`:

```
┌─────────────────────────────────────────────────────────────────────────────────────────────────┐
│ DETAIL TREE / TRAVELER: 1270826001 (Tree #001)                                                  │
│ Produk: SS304 SQUARE DN 40 | Kode: 268ETB827 | Qty Awal: 32 pcs | Sisa Fisik: 30 pcs            │
│ Status: In Coating (Lapisan 3) | Posisi: RAK-04                                                 │
├─────────────────────────────────────────────────────────────────────────────────────────────────┤
│ [SECTION 1] RIWAYAT SCAN (Operator Timeline)                                                    │
│  • Lapisan 1 : SUCCESS (27-08-2026 17:44) &bull; Op: Agus &bull; Aging: 4 jam (Normal)           │
│  • Lapisan 2 : SUCCESS (27-08-2026 21:50) &bull; Op: Budi &bull; Aging: 4 jam (Normal)           │
│  • Lapisan 3 : SUCCESS (28-08-2026 08:15) &bull; Op: Agus &bull; Aging: 10 jam (Normal)          │
├─────────────────────────────────────────────────────────────────────────────────────────────────┤
│ [SECTION 2] QUALITY & DEFECT LOG (Admin / SPV Inspection)                                       │
│  Tabel Defect Tercatat:                                                                         │
│  ┌───────────┬─────────┬──────────────────────┬────────────────────────┬─────────────────────┐  │
│  │ Stage     │ Defect  │ Alasan               │ Dicatat Oleh           │ Catatan             │  │
│  ├───────────┼─────────┼──────────────────────┼────────────────────────┼─────────────────────┤  │
│  │ Lapisan 2 │ 2 pcs   │ Retak Lapisan        │ SPV Bambang (28-08 09) │ Retak pada leher    │  │
│  └───────────┴─────────┴──────────────────────┴────────────────────────┴─────────────────────┘  │
│                                                                                                 │
│  [ + Catat Defect Tree Ini ]  (Membuka Modal Input Defect)                                      │
└─────────────────────────────────────────────────────────────────────────────────────────────────┘
```

### Form Input Modal Catat Defect:
- **Tahapan Kejadian (`stage`)**: Dropdown pilihan (`Rangkai`, `Lapisan 1` s/d `Lapisan 7`, `Oven`).
- **Kuantitas Defect (`defect_qty`)**: Input integer ($1 \le \text{qty} \le \text{Sisa Fisik Pohon}$).
- **Alasan Cacat (`defect_reason`)**: Dropdown kategori cacat standar.
- **Catatan Tambahan (`notes`)**: Textarea opsional.

---

## 18. Complete Traceability Chain (5W+1H)

Jalur relasi data yang menjamin auditabilitas 100%:

$$\text{LostWaxTreeDefect} \xrightarrow{\text{belongsTo}} \text{LostWaxTree} \xrightarrow{\text{belongsTo}} \text{LostWaxPrintOrderLine} \xrightarrow{\text{belongsTo}} \text{ProductionPlan}$$

```
[ DEFECT RECORD ]
  ├── 1. Defect Quantity & Reason (LostWaxTreeDefect.defect_qty, defect_reason, notes)
  ├── 2. Stage Kejadian (LostWaxTreeDefect.stage)
  ├── 3. Siapa yang mencatat & Kapan (LostWaxTreeDefect.recorded_by, created_at)
  └── 4. Tree Barcode & Nomor Rangkaian (LostWaxTree.barcode, tree_number)
        │
        ├── 5. Rangkai Execution & Tanggal Rangkai (LostWaxRangkaiExecution.execution_date, family_code)
        ├── 6. Rangkai Work Order Number (LostWaxRangkaiWorkOrder.rangkai_order_number)
        └── 7. Multi-Line Allocation Breakdown (LostWaxTreeAllocation)
              │
              └── 8. SPK Cetak Number (LostWaxPrintOrder.print_order_number)
                    │
                    └── 9. Production Plan & Customer PO (ProductionPlan.code, po_number, customer)
```

---

## 19. Legacy Compatibility & Migration Safety

1. **Legacy Trees (214 Trees)**: Tree lama yang tidak memiliki record di `lost_wax_tree_defects` secara otomatis dihitung dengan total defect = 0 pcs ($Q_{\text{usable}} = \text{tree.quantity}$).
2. **Existing Scan Events (510 Scans)**: Struktur tabel `lost_wax_scan_events` dan `lost_wax_scan_event_voids` **tidak dimodifikasi**.
3. **Existing Print Orders & Allocations**: 100% kompatibel tanpa migrasi data retroaktif.
4. **Idempotent Migration**: Menggunakan `Schema::hasTable` dan `Schema::hasColumn` guards sesuai aturan `AGENTS.md`.

---

## 20. Comprehensive Test Matrix (24 Test Cases)

Berikut adalah daftar test case automated yang wajib diuji dan lulus pada tahap implementasi:

```
[TEST MATRIX — LOST WAX DEFECT, USABLE & RECOVERY ENGINE]
 1. test_print_defect_is_strictly_excluded_from_print_good()
 2. test_tree_stage_defect_reduces_tree_usable_quantity()
 3. test_multiple_stage_defects_on_single_tree_accumulate_correctly()
 4. test_defect_entry_exceeding_tree_physical_quantity_is_rejected()
 5. test_defect_entry_on_fully_defective_tree_is_rejected()
 6. test_concurrent_defect_entries_are_transaction_safe()
 7. test_scan_event_remains_intact_when_defect_is_logged()
 8. test_defect_logged_for_earlier_stage_records_correct_occurred_stage()
 9. test_oven_defect_reduces_overall_usable_quantity()
10. test_standby_pool_is_conserved_and_not_counted_as_defect()
11. test_canonical_usable_quantity_exact_match_across_all_stages()
12. test_status_is_normal_when_usable_exceeds_or_equals_planned()
13. test_status_is_warning_when_usable_below_planned_but_above_po()
14. test_status_is_critical_when_usable_below_po_quantity()
15. test_status_fallback_to_warning_when_po_quantity_is_null_and_usable_below_planned()
16. test_recovery_pool_displays_warning_and_critical_plans()
17. test_recovery_pool_excludes_normal_and_closed_plans()
18. test_reprint_action_creates_new_print_order_without_modifying_old_spk()
19. test_reprint_order_line_shares_same_production_code_and_plan_id()
20. test_new_reprinted_output_merges_into_same_fifo_pool()
21. test_close_without_reprint_records_reason_and_closes_plan()
22. test_closed_plan_disappears_from_recovery_pool()
23. test_multi_line_allocated_tree_defect_traceability()
24. test_full_lost_wax_regression_suite_passes_100_percent()
```

---

## 21. Files Expected To Change (Upon Implementation Approval)

1. `database/migrations/xxxx_xx_xx_create_lost_wax_tree_defects_table.php` (New)
2. `database/migrations/xxxx_xx_xx_add_po_quantity_and_closure_to_production_plans_table.php` (New)
3. `app/Models/LostWaxTreeDefect.php` (New)
4. `app/Models/ProductionPlan.php` (Add `po_quantity`, `closure_reason`, `closed_by`, `closed_at`, accessor status)
5. `app/Models/LostWaxTree.php` (Add `defects()` relation and `usable_quantity` accessor)
6. `app/Services/LostWaxQualityService.php` (New — handles defect logging & canonical usable calculation)
7. `app/Http/Controllers/LostWax/TreeController.php` (Add defect entry modal endpoint)
8. `app/Http/Controllers/LostWax/PrintOrderController.php` (Add Recovery Pool tab & Reprint action)
9. `app/Http/Controllers/LostWax/ProductionStatusController.php` (Update to use real defect data)
10. `resources/views/lost-wax/trees/show.blade.php` (Add Quality & Defect Log section)
11. `resources/views/lost-wax/print-orders/plans.blade.php` (Add Recovery Pool UI)
12. `resources/views/lost-wax/production-status/index.blade.php` (Update defect & usable columns)
13. `tests/Feature/LostWax/LostWaxDefectAndRecoveryTest.php` (New)

---

## 22. Explicit Business Rules Locked

1. **Boundary Pool**: Production Code (`code`) adalah satu-satunya batas material pool.
2. **Defect Immutability**: Defect yang sudah dicatat tidak boleh diedit sembarangan (hanya Super Admin / void).
3. **Throughput Scanning Unburdened**: Operator scanner dilarang dibebani form defect saat proses scan berlangsung.
4. **SPK Immutability**: SPK Cetak yang sudah diterbitkan tidak boleh diubah kuantitasnya saat terjadi reprint; reprint wajib membuat SPK baru.
5. **No Negative Balance**: Tidak ada baris SPK atau Tree yang boleh memiliki saldo kuantitas negatif.

---

## 23. Risks & Mitigation Strategies

| Risiko Potensial | Mitigasi yang Dikunci dalam Desain |
|---|---|
| Admin salah menginput jumlah defect melebihi isi pohon | Validasi ketat `SUM(defects) <= tree.quantity` dengan pesan error informatif. |
| Dua admin menginput defect pada pohon yang sama secara bersamaan | Menggunakan `lockForUpdate()` dalam transaksi database. |
| Production status salah menghitung sisa cetak sebagai defect | Menghapus rumus heuristik lama dan menggunakan data riil `lost_wax_tree_defects`. |

---

## FINAL IMPLEMENTATION GATE

```
====================================================================================================
                        IMPLEMENTATION READINESS CHECKLIST
====================================================================================================
 [x] Canonical Usable Quantity Formula Locked & Proven
 [x] Quantity Conservation Model Defined
 [x] Scan Throughput vs Quality Defect Event Separated
 [x] lost_wax_tree_defects Table Schema Finalized
 [x] Cumulative Tree Defect Validation Guard Defined
 [x] PO vs Plan Target Semantics Defined
 [x] NORMAL / WARNING / CRITICAL Matrix Fully Specified
 [x] Recovery Pool Lifecycle & Reprint Workflow Designed
 [x] Heuristic Bug in ProductionStatus Purged from Target Design
 [x] Complete 5W+1H Traceability Chain Mapped
 [x] Legacy Tree Compatibility & Idempotency Guaranteed
 [x] 24 Automated Test Cases Defined
====================================================================================================
 STATUS: [X] READY FOR IMPLEMENTATION
====================================================================================================
```

> [!TIP]
> Seluruh arsitektur, relasi entity, formula matematis, dan interaksi UX telah dikunci secara komprehensif di dalam dokumen ini. Silakan berikan konfirmasi/approval jika Anda ingin memulai implementasi kode berdasarkan urutan roadmap di atas.
