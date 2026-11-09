# LAPORAN IMPLEMENTASI PERBAIKAN
## Restore Over-Plan Casting Result with Non-Blocking Warning
**Sistem:** Kanban PPIC — Sand Casting Module  
**Repository Lokal:** `C:\laragon\www\kanban-ppic`  
**Tanggal Implementasi:** 09 November 2026  
**Status Implementasi:** SELESAI (Lokal Laragon) — Menunggu Evaluasi & Persetujuan (Production untouched, no git push)  

---

## DAFTAR ISI
1. [Root Cause yang Diperbaiki](#1-root-cause-yang-diperbaiki)
2. [Daftar File yang Berubah](#2-daftar-file-yang-berubah)
3. [Perubahan Backend](#3-perubahan-backend)
4. [Perubahan Frontend dan Warning Visual](#4-perubahan-frontend-dan-warning-visual)
5. [Perubahan Candidate Lines dan Status PCOR](#5-perubahan-candidate-lines-dan-status-pcor)
6. [Bukti Bahwa Model A Tetap Berlaku](#6-bukti-bahwa-model-a-tetap-berlaku)
7. [Hasil Automated Testing](#7-hasil-automated-testing)
8. [Panduan Skenario UAT Manual](#8-panduan-skenario-uat-manual)

---

## 1. ROOT CAUSE YANG DIPERBAIKI

Berdasarkan dokumen audit [`AUDIT_OVERPLAN_CASTING_RESULT.md`](file:///c:/laragon/www/kanban-ppic/AUDIT_OVERPLAN_CASTING_RESULT.md), pembatasan hasil cor aktual tidak boleh melebihi target rencana cor (*Casting Plan / PCOR*) disebabkan oleh *over-restriction* pada saat implementasi Model A (commit `b9beebc`).

Pengembang saat itu secara keliru menggabungkan konsep:
1. **Planning Control (PPIC):** Mengunci kuota agar tidak diterbitkan PCOR ganda untuk kuota yang sama (Aturan Model A).
2. **Production Recording (Tanur/Foundry):** Mencatat kuantitas fisik leburan yang dituangkan ke cetakan pasir.

Akibatnya, backend melempar exception `422`, frontend HTML memasang atribut `max`, JavaScript memotong angka (*value clamping*) dan memunculkan popup penolakan, serta baris PCOR yang kuotanya tercapai dieliminasi dari daftar candidate lines. Hal ini bertentangan dengan realitas lantai pabrik cor di mana kelebihan penuangan (*over-casting*) sering terjadi dan seluruh fisik logam beku wajib memiliki identitas Kitir Produksi (KTR) untuk diproses ke Netto, Bubut, Bor, QC, dan Gudang Jadi.

---

## 2. DAFTAR FILE YANG BERUBAH

| No | File Path | Peran File | Ringkasan Perubahan |
| :---: | :--- | :--- | :--- |
| 1 | [`app/Services/SandCasting/SandCastingCastingResultService.php`](file:///c:/laragon/www/kanban-ppic/app/Services/SandCasting/SandCastingCastingResultService.php) | Backend Service | Menghapus pelemparan exception over-quota pada `recordResult()` & `correctResult()`; mengizinkan pencatatan hasil fisik pada PCOR berstatus `COMPLETED`. |
| 2 | [`app/Http/Controllers/SandCasting/CastingResultController.php`](file:///c:/laragon/www/kanban-ppic/app/Http/Controllers/SandCasting/CastingResultController.php) | Controller Hasil Cor | Memperbarui query candidate lines di `create()` agar line aktif tetap dapat dipilih meskipun sisa kuota awal sudah 0 atau order telah `COMPLETED` (hingga 30 hari). |
| 3 | [`resources/views/sand-casting/casting-results/create.blade.php`](file:///c:/laragon/www/kanban-ppic/resources/views/sand-casting/casting-results/create.blade.php) | Blade View & JS | Menghapus atribut `max`, menghapus alert pemblokir & clamping; mengembalikan notifikasi visual amber non-blocking (`+N pcs over kuota`). |
| 4 | [`app/Models/ProductionPlan.php`](file:///c:/laragon/www/kanban-ppic/app/Models/ProductionPlan.php) | Model Perencanaan | Menyelaraskan accessor `qty_casting_scheduled` agar mengunci `max(ordered, good)` secara konsisten di seluruh status aktif (`DRAFT`, `ISSUED`, `PARTIALLY_COMPLETED`, `COMPLETED`). |
| 5 | [`app/Http/Controllers/SandCasting/CastingOrderController.php`](file:///c:/laragon/www/kanban-ppic/app/Http/Controllers/SandCasting/CastingOrderController.php) | Controller PCOR | Menyelaraskan SQL subquery pada tab Rencana Cor (`plans()`) dengan formula `max(ordered, good)`. |
| 6 | [`tests/Feature/SandCasting/CastingResultServiceAndControllerTest.php`](file:///c:/laragon/www/kanban-ppic/tests/Feature/SandCasting/CastingResultServiceAndControllerTest.php) | Automated Test | Menyelaraskan ekspektasi pengujian agar exact quota dan over-casting bernilai valid (`PASS`). |
| 7 | [`tests/Feature/SandCasting/CastingOrderModelATest.php`](file:///c:/laragon/www/kanban-ppic/tests/Feature/SandCasting/CastingOrderModelATest.php) | Automated Test Model A | Menyelaraskan skenario 4, 5, dan 8 agar penguncian kuota PPIC tetap aman sementara fisik over-plan diizinkan. |
| 8 | [`tests/Feature/SandCasting/CastingOrderTest.php`](file:///c:/laragon/www/kanban-ppic/tests/Feature/SandCasting/CastingOrderTest.php) | Automated Test PCOR | Menyelaraskan penolakan alokasi pada order `CANCELLED` (tetap ditolak) vs order `COMPLETED` (diizinkan heat tambahan). |
| 9 | [`tests/Feature/SandCasting/OverPlanCastingResultTest.php`](file:///c:/laragon/www/kanban-ppic/tests/Feature/SandCasting/OverPlanCastingResultTest.php) | **File Baru (Test)** | 13 skenario automated test lengkap membuktikan seluruh aturan bisnis over-plan dan integritas Model A. |

---

## 3. PERUBAHAN BACKEND

### A. Relaksasi Hard-Block Over-Quota pada `recordResult()`
**File:** [`app/Services/SandCasting/SandCastingCastingResultService.php`](file:///c:/laragon/www/kanban-ppic/app/Services/SandCasting/SandCastingCastingResultService.php#L155-L170)

1. **Status PCOR Valid:**  
   PCOR berstatus `COMPLETED` kini diizinkan menerima alokasi Heat fisik tambahan:
   ```php
   if (! in_array($order->status, ['ISSUED', 'PARTIALLY_COMPLETED', 'COMPLETED'], true)) {
       throw new InvalidArgumentException("Perintah Cor {$order->casting_order_number} tidak dalam status yang valid untuk pencatatan Hasil Cor.");
   }
   ```
2. **Hard Exception Dihapus:**  
   Pengecekan berikut yang melempar exception telah dihapus:
   ```php
   // DIHAPUS:
   if ($qtyGood > $remainingQuota) {
       throw new InvalidArgumentException("Kuantitas Good melebihi sisa yang belum dicor...");
   }
   ```
3. **Validasi Kritis yang Tetap Dipertahankan (100% Aman):**
   - Nilai tidak boleh negatif (`$qtyGood < 0` atau `$qtyReject < 0` tetap melempar exception).
   - Kuantitas total tidak boleh nol (`$qtyGood === 0 && $qtyReject === 0` tetap ditolak).
   - Otorisasi Product Scope pengguna PPIC tetap ditegakkan (`Unauthorized: Item berada di luar product scope`).
   - Domain Sand Casting tetap diverifikasi (`bukan bagian dari domain Sand Casting`).
   - Dokumen `DRAFT` dan `CANCELLED` tetap dilarang menerima hasil cor.
   - Proteksi duplikasi KTR per kombinasi Heat Number dan Production Plan tetap bekerja.
   - Transaksi database dan row locking (`DB::transaction` dan `lockForUpdate()`) tetap aktif.

### B. Relaksasi Hard-Block pada `correctResult()`
**File:** [`app/Services/SandCasting/SandCastingCastingResultService.php`](file:///c:/laragon/www/kanban-ppic/app/Services/SandCasting/SandCastingCastingResultService.php#L515-L535)

- Pengecekan `if ($newQtyGood > $maxAllowed)` dihapus.
- Koreksi hasil cor yang menyebabkan total penuangan melampaui rencana tetap diperbolehkan, dengan pencatatan audit log lengkap di tabel `sand_casting_casting_result_corrections`.
- Post-gate execution locking (KTR yang sudah dieksekusi di Netto/QC tidak boleh diubah kuantitasnya) tetap terkunci rapat.

---

## 4. PERUBAHAN FRONTEND DAN WARNING VISUAL

**File:** [`resources/views/sand-casting/casting-results/create.blade.php`](file:///c:/laragon/www/kanban-ppic/resources/views/sand-casting/casting-results/create.blade.php)

1. **Penghapusan Atribut `max` HTML:**  
   Pada fungsi `selectCandidateLine()`, atribut `max` dihapus sehingga browser tidak lagi membatasi input:
   ```javascript
   goodInput.value = remainingQuota > 0 ? remainingQuota : 0;
   goodInput.removeAttribute('max');
   ```
2. **Penghapusan Alert Pemblokir pada Staging:**  
   Pada fungsi `addStagedLineToHeat()`, blokade `if (good > remainingQuota)` dihapus. Ketika item ditambahkan dan melebihi sisa rencana, muncul banner notifikasi visual kuning/amber:
   ```javascript
   if (good > remainingQuota) {
       const overQty = good - remainingQuota;
       document.getElementById('candidateContainer').innerHTML = `
           <div class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2 font-semibold my-2">
               <i class="fas fa-exclamation-triangle mr-1"></i> 
               Qty Good (${good} pcs) melebihi sisa kuota PCOR (${remainingQuota} pcs, +${overQty} pcs over kuota). Data tetap ditambahkan ke Heat dan dapat disimpan.
           </div>`;
   }
   ```
3. **Penghapusan Clamping Nilai & Restorasi Badge Dinamis:**  
   Pada fungsi `updateStagedItem()`, pemotongan nilai paksa (`val = stagedItems[index].remaining`) dihapus. Saat user mengetik angka lebih besar dari sisa kuota di tabel staging, muncul badge amber informatif yang ter-update secara real-time:
   ```javascript
   <div class="text-[10px] text-amber-700 font-semibold mt-1 text-center bg-amber-100/70 border border-amber-300 rounded px-1.5 py-0.5">
       <i class="fas fa-exclamation-triangle mr-0.5 text-amber-600"></i> +${overQty} pcs over kuota
   </div>
   ```
4. **Penghapusan Submit Interceptor:**  
   Loop JavaScript pada `submitHasilCorForm()` yang sebelumnya membatalkan `form.submit()` telah dihapus. User dapat menyimpan form tanpa hambatan.

---

## 5. PERUBAHAN CANDIDATE LINES DAN STATUS PCOR

**File:** [`app/Http/Controllers/SandCasting/CastingResultController.php`](file:///c:/laragon/www/kanban-ppic/app/Http/Controllers/SandCasting/CastingResultController.php#L89-L130)

Sebelumnya, candidate lines disaring dengan klausa ketat:
`whereIn('status', ['ISSUED', 'PARTIALLY_COMPLETED'])` dan `filter(fn($l) => $l->qty_remaining_to_cast > 0)`.  
Akibatnya, saat kuota 110 pcs tercapai (sisa 0), PCOR berubah menjadi `COMPLETED` dan seketika lenyap dari dropdown candidate lines.

### Solusi Minimal yang Diterapkan:
1. Query database diperluas untuk mencakup PCOR yang relevan:
   ```php
   ->whereHas('castingOrder', function ($q) {
       $q->whereIn('status', ['ISSUED', 'PARTIALLY_COMPLETED', 'COMPLETED']);
   })
   ```
2. Logika penyaringan koleksi `$availableLines`:
   ```php
   $availableLines = $linesQuery->get()->filter(function ($line) use ($preselectedOrderId) {
       // 1. Jika diakses dengan parameter preselected (?casting_order_id=...), selalu sertakan seluruh barisnya
       if ($preselectedOrderId) {
           return (int) $line->sand_casting_casting_order_id === (int) $preselectedOrderId;
       }

       // 2. Selalu sertakan jika baris masih memiliki sisa kuota yang belum dicor
       if ($line->qty_remaining_to_cast > 0) {
           return true;
       }

       // 3. Sertakan seluruh baris jika dokumen PCOR induknya masih aktif berjalan (ISSUED atau PARTIALLY_COMPLETED)
       $orderStatus = $line->castingOrder ? $line->castingOrder->status : null;
       if (in_array($orderStatus, ['ISSUED', 'PARTIALLY_COMPLETED'], true)) {
           return true;
       }

       // 4. Untuk PCOR berstatus COMPLETED, tetap dapat dipilih jika baru selesai dalam 30 hari terakhir
       if ($orderStatus === 'COMPLETED') {
           return $line->castingOrder && $line->castingOrder->updated_at && $line->castingOrder->updated_at->gte(now()->subDays(30));
       }

       return false;
   })->values();
   ```

**Keuntungan Solusi Ini:**
- Operator dapat mencatat penuangan susulan (*Heat 4*) untuk PCOR yang baru saja selesai pada hari yang sama.
- Dokumen PCOR lama yang sudah selesai berbulan-bulan lalu tidak membebani datalist pencarian umum, namun tetap dapat diakses jika dibuka langsung via URL detail PCOR.
- Lifecycle PCOR tetap mempertahankan status `COMPLETED` sebagai penanda bahwa target rencana awal telah terpenuhi secara administratif.

---

## 6. BUKTI BAHWA MODEL A TETAP BERLAKU

Model A menetapkan aturan: **PCOR yang diterbitkan mengunci kuota agar tidak dilepaskan kembali ke tab Rencana Cor dan tidak dapat diterbitkan ganda oleh PPIC.**

Keterikatan ini dibuktikan melalui 3 level arsitektur:

1. **Model Accessor `ProductionPlan::getQtyCastingScheduledAttribute`:**  
   [`app/Models/ProductionPlan.php:110-112`](file:///c:/laragon/www/kanban-ppic/app/Models/ProductionPlan.php#L110-L112)
   ```php
   if (in_array($status, ['DRAFT', 'ISSUED', 'PARTIALLY_COMPLETED', 'COMPLETED'], true)) {
       $totalCommitted += max($ordered, $good);
   }
   ```
   - Jika `ordered` = 110 dan `good` = 40 (parsial): Mengunci `max(110, 40) = 110` (Model A). Sisa 70 **tidak lepas** ke Rencana Cor.
   - Jika `ordered` = 110 dan `good` = 125 (over-plan): Mengunci `max(110, 125) = 125`.
2. **SQL Subquery di Tab Rencana Cor (`CastingOrderController::plans`):**  
   [`app/Http/Controllers/SandCasting/CastingOrderController.php:42-59`](file:///c:/laragon/www/kanban-ppic/app/Http/Controllers/SandCasting/CastingOrderController.php#L42-L59)  
   Menggunakan ekspresi kondisional SQL yang identik: `CASE WHEN SUM(qty_good) > qty_ordered THEN SUM(qty_good) ELSE qty_ordered END`. Menjamin tampilan kuota di web selalu sinkron dan tidak pernah membiarkan sisa rencana muncul kembali.
3. **Pembuktian Automated Test:**  
   Pada test `test_9_over_plan_commits_higher_quantity_so_no_quota_leaks_to_new_pcor` di [`OverPlanCastingResultTest.php`](file:///c:/laragon/www/kanban-ppic/tests/Feature/SandCasting/OverPlanCastingResultTest.php), ketika hasil cor 120 pcs dicatat pada rencana 110 pcs, kuota sisa rencana bernilai 0. Upaya PPIC menerbitkan PCOR baru untuk item tersebut **ditolak secara atomik** (`DRAFT` gagal naik ke `ISSUED`).

---

## 7. HASIL AUTOMATED TESTING

Seluruh rangkaian automated test dijalankan menggunakan database SQLite `:memory:` sesuai konvensi project.

### Ringkasan Eksekusi Test:
```
php artisan test \
    tests/Feature/SandCasting/CastingOrderModelATest.php \
    tests/Feature/SandCasting/CastingResultServiceAndControllerTest.php \
    tests/Feature/SandCasting/OverPlanCastingResultTest.php \
    tests/Feature/SandCasting/CastingOrderTest.php \
    tests/Feature/SandCasting/FinalQualityLockTest.php
```

### Output Hasil Eksekusi:
```text
   PASS  Tests\Feature\SandCasting\CastingOrderModelATest
  ✓ scenario 1 production plan 200 pcor issued 200 available quota is zero           0.86s
  ✓ scenario 2 pcor partially completed with good 100 available quota remains zero   0.10s
  ✓ scenario 3 pcor partially completed can receive additional 100 good              0.05s
  ✓ scenario 4 after full good additional good can be recorded (over-plan)           0.05s
  ✓ scenario 5 over quota input is allowed and commits actual good                   0.05s
  ✓ scenario 6 reject does not reduce outstanding good                               0.05s
  ✓ scenario 7 draft pcor cannot receive casting results                             0.05s
  ✓ scenario 8 completed and cancelled pcor status validation                        0.06s
  ✓ scenario 9 pcor with actual results cannot be cancelled                          0.07s
  ✓ scenario 10 valid cancelled pcor releases quota                                  0.06s
  ✓ scenario 11 model accessor and plans sql subquery produce exact same quota       0.08s
  ✓ scenario 12 two pcors cannot lock production plan exceeding remaining quota      2.17s

   PASS  Tests\Feature\SandCasting\CastingResultServiceAndControllerTest
  ✓ issued order can create casting result via service and http                      0.07s
  ✓ single heat can combine items from multiple pcor orders via service and http     0.06s
  ✓ draft order is rejected                                                          0.05s
  ✓ cancelled order is rejected                                                      0.05s
  ✓ completed order can receive additional outcomes if needed                        0.06s
  ✓ lost wax plan is strictly rejected                                               0.06s
  ✓ invalid order line id is rejected                                                0.05s
  ✓ casting result allows exact quota and over casting                               0.06s
  ✓ partial casting accumulates accurately                                           0.07s
  ✓ transaction rolls back if any line fails                                         0.05s
  ✓ unauthorized user is forbidden                                                   0.05s
  ✓ duplicate order line in same heat is rejected                                    0.05s
  ✓ multi line heat creates one heat with multiple result lines                      0.06s
  ✓ multi line heat with over casting maintains accurate allocations                 0.07s
  ✓ same heat same production plan is rejected                                       0.06s
  ✓ same heat different production plan is allowed                                   0.06s
  ✓ different heat same production plan is allowed                                   0.07s
  ✓ two identical production plan id in same request is rejected                     0.05s
  ✓ request with multiple distinct plans is allowed                                  0.06s
  ✓ request with same line repeated is rejected                                      0.05s
  ✓ existing duplicate historical data remains readable                              0.12s
  ✓ no partial insert if duplicate found in multi line request                       0.06s
  ✓ duplicate rejection returns structured payload via http                          0.08s
  ✓ existing ktr remains unmodified when duplicate is rejected                       0.10s

   PASS  Tests\Feature\SandCasting\OverPlanCastingResultTest
  ✓ 1 plan 110 actual 120 saves successfully                                         0.07s
  ✓ 2 plan 110 actual 110 saves successfully exact                                   0.05s
  ✓ 3 plan 110 actual 100 saves successfully partially completed                     0.04s
  ✓ 4 three heats of 40 pcs accumulate to 120 pcs successfully                       0.06s
  ✓ 5 after total reaches 110 user can record additional heat                        0.05s
  ✓ 6 correction resulting in over plan is allowed                                   0.06s
  ✓ 7 create view renders available lines and allows post with over plan             0.08s
  ✓ 8 model a retains locked quota when partially completed or completed             0.06s
  ✓ 9 over plan commits higher quantity so no quota leaks to new pcor                0.07s
  ✓ 10 outstanding po never becomes negative                                         0.05s
  ✓ 11 po quantity remains unchanged after over plan                                 0.05s
  ✓ 12 good reject and heat allocations remain consistent                            0.05s
  ✓ 13 ktr production items created accurately for downstream stages                 0.04s

   PASS  Tests\Feature\SandCasting\CastingOrderTest
  ... 24 tests passed ...

   PASS  Tests\Feature\SandCasting\FinalQualityLockTest
  ... 13 tests passed ...

----------------------------------------------------------------------
Tests:    86 passed (686 assertions)
Duration: 11.94s
Status:   100% GREEN (ZERO REGRESSION)
```

---

## 8. PANDUAN SKENARIO UAT MANUAL

Lakukan langkah-langkah berikut di browser pada lingkungan lokal Laragon:

### Skenario UAT 1: Multi-Heat Akumulasi Melebihi Rencana (Kasus Utama 40 + 40 + 40)
1. Login sebagai PPIC (`adminppicpf@peroniks.com` / `password`).
2. Buka menu **Perintah Cor > Rencana Cor** (`/sand-casting/casting-orders/plans`).
3. Pilih item dengan kuota rencana 110 pcs (misal target PO 100 pcs). Buat PCOR dan terbitkan menjadi **`ISSUED`** (misal `PCOR-20261109-0001`).
4. Buka menu **Hasil Cor > Input Hasil Cor** (`/sand-casting/casting-results/create`).
5. **Input Heat 1:**
   - Isi Heat Number: `HEAT-UAT-01`, Tanggal, Furnace.
   - Cari kode item di kotak pencarian, klik item. Sistem menampilkan *Sisa Kuota: 110 pcs*.
   - Masukkan Good: 40 pcs, Reject: 0 pcs. Klik **Tambahkan ke Heat**, lalu klik **Simpan**.
   - Verifikasi: Detail PCOR menunjukkan status **`PARTIALLY_COMPLETED`**, progres 36.4%.
6. **Input Heat 2:**
   - Buka kembali form Input Hasil Cor. Masukkan Heat Number: `HEAT-UAT-02`.
   - Cari kode item yang sama. Sistem menampilkan *Sisa Kuota: 70 pcs*.
   - Masukkan Good: 40 pcs, Reject: 0 pcs. Tambahkan ke Heat dan Simpan.
   - Verifikasi: Akumulasi Good menjadi 80 pcs, status tetap `PARTIALLY_COMPLETED`.
7. **Input Heat 3 (Over-Plan):**
   - Buka kembali form Input Hasil Cor. Masukkan Heat Number: `HEAT-UAT-03`.
   - Cari kode item yang sama. Sistem menampilkan *Sisa Kuota: 30 pcs*.
   - Masukkan Good: **40 pcs** (melebihi sisa 30 pcs).
   - **Periksa Tampilan:** Muncul banner kuning/amber:  
     `Qty Good (40 pcs) melebihi sisa kuota PCOR (30 pcs, +10 pcs over kuota). Data tetap ditambahkan ke Heat dan dapat disimpan.`
   - Di tabel staging, muncul badge: `+10 pcs over kuota`.
   - Klik **Simpan**.
   - **Hasil yang Diharapkan:** Form berhasil disimpan tanpa dicegat popup error. Halaman diarahkan ke detail Heat dengan total Good 40 pcs dan nomor KTR tercetak.
   - Buka detail PCOR: Total Good menjadi **120 pcs**, status berubah menjadi **`COMPLETED`**.

### Skenario UAT 2: Input Heat Susulan pada PCOR yang Sudah Selesai
1. Dengan PCOR pada Skenario 1 yang sudah berstatus `COMPLETED` (120/110 pcs), buka form Input Hasil Cor.
2. Masukkan Heat Number: `HEAT-UAT-04`.
3. Cari kode produk yang sama.
4. **Hasil yang Diharapkan:** Item tetap ditemukan pada hasil pencarian (menampilkan Sisa: 0 pcs).
5. Masukkan Good: 10 pcs. Tambahkan ke Heat dan Simpan.
6. **Hasil yang Diharapkan:** Heat 4 berhasil disimpan (total akumulasi Good menjadi 130 pcs).

### Skenario UAT 3: Verifikasi Integritas Kuota PPIC (Model A Anti-Duplikasi)
1. Kembali ke tab **Rencana Cor** (`/sand-casting/casting-orders/plans`).
2. Cari kode item yang sudah diover-cor (120 pcs) pada Skenario 1.
3. **Hasil yang Diharapkan:**
   - Kolom *Jadwal Cor* terisi 120 (mengunci kuantitas tertinggi).
   - Kolom *Sisa Kuota Cor* bernilai **0 pcs**.
   - Item tidak bisa dicentang untuk diterbitkan PCOR baru karena kuotanya sudah habis terkunci.
4. Buka menu **Laporan Status Produksi** (`/sand-casting/production-status`).
5. Periksa baris item tersebut: Kolom *Sisa PO* bernilai **0 pcs** (tidak pernah negatif).

---

> **Status Penyelesaian:**  
> Implementasi perbaikan dan automated test telah tuntas 100% pada repository lokal Laragon. Sesuai instruksi batasan, tidak ada tindakan deployment atau akses ke production server. Sistem berhenti di sini dan siap untuk dievaluasi oleh pengguna.

