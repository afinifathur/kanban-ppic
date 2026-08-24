# Audit: Lost Wax Print Planning & Daily Print Order Workflow

**Tanggal Audit:** 2026-08-24  
**Status Verdict:** READY WITH MODIFICATIONS

---

## 1. Executive Verdict

> **READY WITH MODIFICATIONS**

Arsitektur data model dasar sudah **benar** dan sudah mendukung workflow PPIC aktual. Relasi `1 Plan → N Print Orders → N Executions` sudah terimplementasi di database dan model. Namun, terdapat **dua bug bisnis kritis** yang menyebabkan penolakan input yang valid dari lapangan:

1. **`PrintExecutionService::record()`** memaksakan `actual <= outstanding` (derived dari `command`), sehingga `Command=200, Actual=201` ditolak.
2. **`OutcomeController::adjustExecutionsToMatch()`** juga memaksakan `good+defect <= qty_ordered`.

Tidak ada masalah arsitektural besar — hanya validasi yang terlalu ketat di dua titik PHP.

---

## 2. Current Architecture

```
ProductionPlan (1)
    |-- hasMany -->
LostWaxPrintOrderLine (N)   <- baris per Print Order, bukan header
    |-- belongsTo -->
LostWaxPrintOrder (N)       <- header dokumen perintah cetak
    |-- hasMany -->
LostWaxPrintExecution (N)   <- baris pelaksanaan aktual
    |-- hasOne -->
LostWaxPrintExecutionCorrection
```

### Relasi 1 Plan -> N Print Orders (sudah benar):

```
ProductionPlan id=5 (qty_planned=330)
    |
    +-- PrintOrderLine id=11  -> PrintOrder PC-001  qty_ordered=100
    +-- PrintOrderLine id=12  -> PrintOrder PC-002  qty_ordered=100
    +-- PrintOrderLine id=13  -> PrintOrder PC-003  qty_ordered=130
```

`ProductionPlan` memiliki `hasMany(LostWaxPrintOrderLine)`. Setiap kali PPIC membuat Print Order baru dari plan yang sama, sebuah `LostWaxPrintOrderLine` baru dibuat dengan FK `production_plan_id`. **Tidak ada unique constraint pada `(production_plan_id)` di tabel `lost_wax_print_order_lines`**, sehingga arsitektur ini sudah benar untuk mendukung partial/daily scheduling.

---

## 3. Current Quantity Semantics

| Field | Lokasi | Definisi |
|---|---|---|
| `qty_planned` | `production_plans` | Target produksi dari PO/rencana **(IMMUTABLE)** |
| `qty_remaining` | `production_plans` | **Legacy field** — tidak dipakai oleh logika baru |
| `qty_scheduled` | accessor `ProductionPlan` | SUM `qty_ordered` dari semua PrintOrderLines dengan status DRAFT atau ISSUED |
| `qty_remaining_scheduled` | accessor `ProductionPlan` | `qty_planned - qty_scheduled` = **Belum Dijadwalkan** |
| `qty_ordered` | `lost_wax_print_order_lines` | Command Qty untuk satu Print Order spesifik |
| `qty_executed_good` | `lost_wax_print_order_lines` | Agregat FINALIZED good dari semua executions |
| `qty_executed_defect` | `lost_wax_print_order_lines` | Agregat FINALIZED defect dari semua executions |
| `qty_outstanding` | accessor `LostWaxPrintOrderLine` | `qty_ordered - qty_executed_good - qty_executed_defect` |
| `qty_good` | `lost_wax_print_executions` | Actual good untuk satu sesi eksekusi |
| `qty_defect` | `lost_wax_print_executions` | Actual defect untuk satu sesi eksekusi |

### Dua konsep yang HARUS dibedakan:

```
REMAINING TO SCHEDULE = qty_planned - SUM(qty_ordered of DRAFT/ISSUED lines)
                      -> sudah ada: qty_remaining_scheduled

REMAINING TO PRODUCE  = qty_planned - SUM(qty_executed_good across all lines of this plan)
                      -> BELUM ADA accessor ini (gap informatif)
```

---

## 4. Current Behavior vs Actual PPIC Workflow

| # | Requirement | Current System | PPIC Reality | Gap | Severity |
|---|---|---|---|---|---|
| 1 | 1 Plan -> N Print Orders | SUDAH DIDUKUNG | Tiap hari buat PO baru | Tidak ada gap | — |
| 2 | Planned Qty immutable | `qty_planned` tidak diubah saat buat PO | Planned harus tetap 330 | Tidak ada gap | — |
| 3 | Belum Dijadwalkan berkurang setelah PO dibuat | `qty_remaining_scheduled` dihitung dinamis | Ya, perlu tahu sisa | Tidak ada gap | — |
| 4 | Actual Qty boleh > Command Qty | **DITOLAK** di service & controller | Operator bisa cetak 201 dari command 200 | **GAP KRITIS** | HIGH |
| 5 | Historical Print Orders tetap ada | Tidak dihapus | PO lama harus tetap terlihat | Tidak ada gap | — |
| 6 | Tanggal tiap PO tersimpan | `scheduled_date` ada di table | Tiap hari beda tanggal | Tidak ada gap | — |
| 7 | PO punya nomor unik | `print_order_number` UNIQUE | Ya, nomor berbeda tiap hari | Tidak ada gap | — |
| 8 | Multiple POs tidak menyebabkan Plan closed otomatis | `is_closed` dikendalikan manual | Betul, tidak otomatis | Tidak ada gap | — |
| 9 | Remaining to Produce (berbeda dari Remaining to Schedule) | Tidak ada accessor | PPIC butuh tahu berapa sudah diproduksi aktual | Gap informatif | MEDIUM |

---

## 5. Multiple Daily Print Orders — Status

**Sistem sudah mendukung sepenuhnya.** Bukti:

### Migration — tidak ada unique constraint:
```php
// 2026_08_18_000001_create_lost_wax_print_orders_tables.php
$table->foreignId('production_plan_id')->nullable()->constrained(...)->nullOnDelete();
// Tidak ada ->unique() disini
```

### Model ProductionPlan:
```php
public function printOrderLines()
{
    return $this->hasMany(LostWaxPrintOrderLine::class, 'production_plan_id');
}
```

### Test yang sudah membuktikan (PrintOrderTest.php line 51-100):
```
Plan=200 -> PO1=120 -> remaining=80 -> PO2=80 -> remaining=0 [PASS]
```

**Skenario 330 -> 100 -> 100 -> 130 sudah bisa dilakukan tanpa modifikasi apapun.**

---

## 6. Remaining to Schedule — Status

**Sudah berfungsi dengan benar.** Logika di `ProductionPlan`:

```php
// qty_scheduled = SUM qty_ordered dari seluruh PO aktif (DRAFT + ISSUED)
public function getQtyScheduledAttribute(): int
{
    return (int) $this->printOrderLines()
        ->whereHas('printOrder', function ($query) {
            $query->whereIn('status', ['DRAFT', 'ISSUED']);
        })
        ->sum('qty_ordered');
}

// qty_remaining_scheduled = Belum Dijadwalkan
public function getQtyRemainingScheduledAttribute(): int
{
    return $this->qty_planned - $this->qty_scheduled;
}
```

Halaman `plans.blade.php` sudah menampilkan Planned, Terjadwal, dan Sisa dengan benar.
Halaman `create.blade.php` juga menampilkan **Belum Dijadwalkan (Sisa)** yang memperhitungkan seluruh PO sebelumnya.

> **Catatan:** Status `COMPLETED` dan `PARTIALLY_COMPLETED` tidak diikutkan dalam `qty_scheduled`. Ini perlu dikonfirmasi dengan PPIC — apakah PO yang sudah selesai masih harus "menahan" slot scheduling.

---

## 7. Actual > Command — Root Cause

Terdapat **tiga titik validasi** yang menolak `Actual > Command`:

### Titik 1: `PrintExecutionService::record()` (line 33-35)
```php
$currentOutstanding = $line->qty_outstanding;
// = qty_ordered - qty_executed_good - qty_executed_defect

$newTotal = $qtyGood + $qtyDefect;
if ($newTotal > $currentOutstanding) {
    throw new \InvalidArgumentException(
        "Total Hasil ({$qtyGood} pcs) + Rusak ({$qtyDefect} pcs) tidak boleh melebihi sisa outstanding..."
    );
}
```
Dengan Command=200 dan belum ada eksekusi sebelumnya: `outstanding = 200`. Input `good=201` -> `201 > 200` -> **ditolak**.

### Titik 2: `OutcomeController::adjustExecutionsToMatch()` (line 209-211)
```php
if ($targetGood + $targetDefect > $line->qty_ordered) {
    throw new \InvalidArgumentException(
        "Total Hasil + Rusak tidak boleh melebihi Qty Perintah ({$line->qty_ordered} pcs)..."
    );
}
```

### Titik 3: `PrintExecutionService::update()` (line 91-93)
```php
$currentOutstanding = max(0, $line->qty_ordered - $otherGood - $otherDefect);
if ($newTotal > $currentOutstanding) {
    throw new \InvalidArgumentException(...);
}
```

Tidak ada validasi di database level (tidak ada DB CHECK constraint).

---

## 8. Recommended Domain Model

```
PLANNING:
    qty_planned = target immutable dari PO/rencana

COMMAND (per Print Order Line):
    qty_ordered = apa yang PPIC perintahkan ke operator hari ini

EXECUTION (per Print Execution record):
    qty_good + qty_defect = apa yang operator benar-benar hasilkan
    BOLEH melebihi qty_ordered (overprint)

AGGREGATION (pada PrintOrderLine):
    qty_executed_good   = SUM FINALIZED executions.qty_good
    qty_executed_defect = SUM FINALIZED executions.qty_defect
    qty_outstanding     = qty_ordered - qty_executed_good - qty_executed_defect
                        -> boleh negatif (tanda overprint)
    execution_status    = COMPLETED jika outstanding <= 0

VARIANCE (dihitung in-memory, tidak perlu kolom baru):
    Variance = (qty_executed_good + qty_executed_defect) - qty_ordered
    Positif = overprint | Negatif = underprint

REMAINING TO SCHEDULE (ProductionPlan):
    = qty_planned - SUM(qty_ordered of DRAFT/ISSUED lines)

REMAINING TO PRODUCE (tambah accessor, tanpa migration):
    = qty_planned - SUM(qty_executed_good across all lines of this plan)
```

---

## 9. Required Changes

### MUST (Harus dilakukan)

| # | Perubahan | Lokasi | Baris |
|---|---|---|---|
| M1 | Hapus/relaksasi validasi `newTotal > currentOutstanding` | `PrintExecutionService::record()` | 33–35 |
| M2 | Hapus/relaksasi validasi `newTotal > currentOutstanding` | `PrintExecutionService::update()` | 91–93 |
| M3 | Hapus/relaksasi validasi `targetGood + targetDefect > qty_ordered` | `OutcomeController::adjustExecutionsToMatch()` | 209–211 |
| M4 | Ubah `outstanding === 0` menjadi `outstanding <= 0` untuk status COMPLETED | `PrintExecutionService::updateLineAggregates()` | 152 |
| M5 | Update/tambah tests untuk skenario overprint | `PrintOrderTest.php` + file baru | — |

### SHOULD (Direkomendasikan)

| # | Perubahan | Lokasi |
|---|---|---|
| S1 | Tambah accessor `qty_produced` (Remaining to Produce) | `ProductionPlan` model |
| S2 | Tampilkan overprint indicator di UI hasil cetak | `show.blade.php`, `outcomes/edit` |
| S3 | Tampilkan Remaining to Produce di halaman plans | `plans.blade.php` |

### OPTIONAL

| # | Perubahan |
|---|---|
| O1 | Konfirmasi dialog di UI jika actual > command |
| O2 | Batas toleransi overprint yang dapat dikonfigurasi |

---

## 10. Risk Analysis

| Area | Risiko | Severitas | Mitigasi |
|---|---|---|---|
| Existing Print Order data | PO lama tidak terpengaruh | NONE | Backward compatible |
| `qty_outstanding` negatif | UI mungkin tampilkan angka negatif | MEDIUM | Clamp di UI layer saja |
| `execution_status` COMPLETED | Logic `outstanding === 0` harus jadi `<= 0` | LOW | Satu baris perubahan |
| Production Status page | Tidak ada impact, membaca `current_stage` dari Trees | NONE | — |
| Rangkai / Trees | `qty_available_for_rangkai` = `qty_executed_good - allocated_trees` — overprint menambah qty tersedia (benar secara bisnis) | LOW | Verifikasi dengan PPIC |
| Traveler Gate | Tidak ada impact langsung | NONE | — |
| XLSX Export | Tidak ada impact | NONE | — |
| Defect handling | `good + defect` total boleh > command — semantik defect tidak berubah | LOW | Validasi logika tidak berubah |

---

## 11. Test Plan (untuk BUILD Phase)

Tests yang perlu **DITAMBAHKAN**:

```
TEST: actual_can_exceed_command_qty
    Command = 200
    Record execution: good=201, defect=0
    Expected: diterima tanpa exception
    Expected: qty_executed_good = 201
    Expected: execution_status = COMPLETED
    Expected: qty_outstanding <= 0

TEST: cumulative_overprint_across_executions
    Command = 200
    Execution 1: good=100
    Execution 2: good=105
    Total = 205 > 200
    Expected: diterima, execution_status = COMPLETED

TEST: overprint_does_not_affect_remaining_to_schedule
    Plan = 330, PO1 command=200, actual=201, PO2 command=130
    qty_remaining_scheduled = 330 - 200 - 130 = 0

TEST: multiple_daily_orders_with_overprint
    Plan = 330
    PO1=100, actual=101
    PO2=100, actual=100
    PO3=130, actual=130
    qty_remaining_scheduled = 0
    total_actual = 331

TEST: outstanding_completed_on_overprint
    Command = 200, actual = 201
    execution_status = COMPLETED

TEST: defect_plus_good_can_exceed_command
    Command = 200
    good = 195, defect = 10
    Total = 205 > 200
    Expected: diterima
```

---

## 12. Migration Requirement

> **Migration Required: NO**

Tidak ada perubahan schema database yang diperlukan:
- Validasi bermasalah ada di PHP layer saja (service + controller).
- Model attributes sudah cukup untuk mendukung overprint.
- Accessor `qty_remaining_to_produce` bisa ditambahkan tanpa migration.
- Tidak ada DB-level constraint yang perlu dihapus.

---

## 13. Data Backward Compatibility

**Sepenuhnya backward compatible:**

- Semua Print Order yang sudah ada tidak diubah.
- Data historis `qty_executed_good`, `qty_executed_defect` tetap valid karena semuanya sudah `actual <= command` (sesuai validasi lama).
- Setelah validasi dihapus, sistem hanya menerima input baru yang sebelumnya ditolak.
- Status PO yang sudah COMPLETED tidak akan berubah.
- Tidak ada data migration.

---

## 14. Edge Case Summary

| Case | Sebelum Fix | Setelah Fix |
|---|---|---|
| Planned=330, No PO | remaining=330 | Sama |
| Planned=330, PO1=100+PO2=100 | remaining=130 | Sama |
| Planned=330, PO1+PO2+PO3=330 | remaining=0 | Sama |
| Planned=330, Over-scheduled=340 | remaining=-10, tampil "(Lebih)" | Sama |
| Command=200, Actual=201 | **DITOLAK** | Diterima |
| Command=200, Actual=250 | **DITOLAK** | Diterima (tanpa batas atas) |
| Command=200, Actual=199 | Diterima | Sama |
| Command=200, Actual=0 | Diterima | Sama |
| PO1 actual=201, PO2 cmd=130 | PO2 scheduling correct | Sama |
| Multiple POs, different dates | Semua tanggal tersimpan | Sama |

---

---

## 15. Build Phase Implementation Summary

Implementasi telah berhasil diselesaikan dengan detail perubahan berikut:

### 1. Perubahan Validasi
- **`PrintExecutionService::record()`**: Menghapus validasi outstanding (`newTotal > currentOutstanding`). Menambahkan validasi preventif kuantitas negatif (`qtyGood < 0 || qtyDefect < 0`).
- **`PrintExecutionService::update()`**: Menghapus validasi outstanding yang sama. Menambahkan validasi kuantitas negatif.
- **`OutcomeController::adjustExecutionsToMatch()`**: Menghapus validasi overprint (`targetGood + targetDefect > line->qty_ordered`).
- **`outcomes/edit.blade.php` (JS)**: Menghapus warning validation client-side yang menonaktifkan tombol submit jika input melebihi sisa outstanding.

### 2. Kuantitas Semantik & Accessors Baru
- **`qty_produced`** pada `ProductionPlan`: Dihitung berdasarkan `SUM(qty_executed_good)` dari seluruh print order lines yang status dokumen parent-nya bukan `CANCELLED`. Mengikuti semantik produksi di mana hanya barang "Good" yang mengurangi sisa produksi actual target.
- **`qty_remaining_to_produce`** pada `ProductionPlan`: Dihitung berdasarkan `max(0, qty_planned - qty_produced)`.
- **`qty_outstanding`** pada `LostWaxPrintOrderLine`: Tetap menggunakan `max(0, qty_ordered - good - defect)` untuk display UI yang aman dari nilai negatif.
- **Status Completion**: Di dalam `PrintExecutionService::updateLineAggregates()`, outstanding murni dihitung secara internal (`qty_ordered - goodSum - defectSum`). Status order berubah menjadi `COMPLETED` apabila outstanding internal tersebut `<= 0` (yang berarti target command sudah terpenuhi atau overprint).

### 3. Downstream Safety
- **Rangkai / Tree Generation**: Terjamin aman karena `qty_available_for_rangkai` menggunakan `qty_executed_good - allocated_trees`. Ketika overprint terjadi, jumlah good yang tersedia bertambah, yang mana secara fisik dan bisnis memang boleh dirangkai.
- **Over-Scheduling Protection**: Tetap aman dan dipertahankan. Pembuatan Print Order baru melebihi Planned Qty akan memicu warning di UI dan dihitung sebagai negatif di `qty_remaining_scheduled`.

### 4. Database Migration
- **Status: NO MIGRATION REQUIRED**  
Tidak ada perubahan pada skema database master/historis.

---

*Laporan ini telah diperbarui untuk mencerminkan implementasi penuh build phase.*
