# LAPORAN VERIFIKASI AKHIR — ZERO QUOTA HEAT GUARD TEST SUITES

**Repository:** `C:\laragon\www\kanban-ppic`  
**Tanggal:** 2026-11-09  
**Status:** VERIFIKASI SELESAI — TIDAK ADA PERUBAHAN KODE / DATABASE  

---

## 1. Command PowerShell yang Digunakan

Command eksekusi pengujian PHPUnit melalui Laravel Artisan:

```powershell
php artisan test --filter="ZeroQuotaHeatSelectionTest|LiveCastingAvailabilityPanelTest|CastingOrderModelATest|CastingResultServiceAndControllerTest|OverPlanCastingResultTest|PcorReopeningCorrectionTest|FinalQualityLockTest|DefectAdjustmentTest"
```

---

## 2. Output Terminal Aktual (Raw Terminal Output)

Berikut adalah output terminal aktual dari eksekusi command di atas tanpa rekayasa atau ringkasan manual:

```
   PASS  Tests\Feature\SandCasting\CastingOrderModelATest
  ✓ scenario 1 production plan 200 pcor issued 200 available quota is zero                                       0.83s  
  ✓ scenario 2 pcor partially completed with good 100 available quota remains zero                               0.09s  
  ✓ scenario 3 pcor partially completed can receive additional 100 good becoming completed                       0.05s  
  ✓ scenario 4 after full good additional 1 good is strictly rejected                                            0.05s  
  ✓ scenario 5 over quota input is allowed and commits actual good                                               0.05s  
  ✓ scenario 6 reject does not reduce outstanding good                                                           0.04s  
  ✓ scenario 7 draft pcor cannot receive casting results                                                         0.04s  
  ✓ scenario 8 completed and cancelled pcor cannot receive new results                                           0.05s  
  ✓ scenario 9 pcor with actual results cannot be cancelled                                                      0.05s  
  ✓ scenario 10 valid cancelled pcor releases quota                                                              0.05s  
  ✓ scenario 11 model accessor and plans sql subquery produce exact same available quota                         0.06s  
  ✓ scenario 12 two pcors cannot lock production plan exceeding remaining quota                                  2.02s  

   PASS  Tests\Feature\SandCasting\CastingResultServiceAndControllerTest
  ✓ issued order can create casting result via service and http                                                  0.06s  
  ✓ single heat can combine items from multiple pcor orders via service and http                                 0.07s  
  ✓ draft order is rejected                                                                                      0.05s  
  ✓ cancelled order is rejected                                                                                  0.04s  
  ✓ completed order is rejected for new heats                                                                    0.04s  
  ✓ lost wax plan is strictly rejected                                                                           0.06s  
  ✓ invalid order line id is rejected                                                                            0.04s  
  ✓ casting result allows exact quota and over casting                                                           0.06s  
  ✓ partial casting accumulates accurately                                                                       0.05s  
  ✓ transaction rolls back if any line fails                                                                     0.04s  
  ✓ unauthorized user is forbidden                                                                               0.04s  
  ✓ duplicate order line in same heat is rejected                                                                0.05s  
  ✓ multi line heat creates one heat with multiple result lines and distinct travelers                           0.05s  
  ✓ multi line heat with over casting maintains accurate allocations                                             0.06s  
  ✓ same heat same production plan is rejected                                                                   0.05s  
  ✓ same heat different production plan is allowed                                                               0.06s  
  ✓ different heat same production plan is allowed                                                               0.05s  
  ✓ two identical production plan id in same request is rejected                                                 0.05s  
  ✓ request with multiple distinct plans is allowed                                                              0.05s  
  ✓ request with same line repeated is rejected                                                                  0.05s  
  ✓ existing duplicate historical data remains readable                                                          0.14s  
  ✓ no partial insert if duplicate found in multi line request                                                   0.05s  
  ✓ duplicate rejection returns structured payload via http                                                      0.06s  
  ✓ existing ktr remains unmodified when duplicate is rejected                                                   0.05s  

   PASS  Tests\Feature\SandCasting\DefectAdjustmentTest
  ✓ 01 positive adjustment increases total correctly                                                             0.07s  
  ✓ 02 negative adjustment decreases total correctly                                                             0.05s  
  ✓ 03 negative adjustment without reason is rejected                                                            0.05s  
  ✓ 04 adjustment cannot produce negative total                                                                  0.05s  
  ✓ 05 adjustment cannot exceed applicable dynamic quantity boundary                                             0.06s  
  ✓ 06 correcting total to zero preserves recorded zero distinction                                              0.06s  
  ✓ 07 confirmed execution requiring reverification reverts status and requires qc                               0.07s  
  ✓ 08 duplicate retry does not apply same adjustment twice                                                      0.05s  
  ✓ 09 database unique index prevents duplicate idempotency insertion                                            0.06s  
  ✓ 10 adjustment log preserves full audit trail                                                                 0.06s  
  ✓ 11 defect adjustment does not mutate physical scan snapshots                                                 0.06s  
  ✓ 12 production codes remain strictly isolated no quantity borrowing                                           0.06s  
  ✓ 13 existing positive defect and product scope enforced via controller                                        0.06s  
  ✓ 14 effective gd calculation reflects defect adjustments dynamically                                          0.05s  
  ✓ 15 idempotency key reused with different payload is rejected                                                 0.05s  
  ✓ 16 idempotency key reused across different executions is rejected                                            0.06s  
  ✓ 17 rejected adjustment preserves defect total and log history                                                0.05s  
  ✓ 18 http endpoint rejects conflicting idempotency keys with 422                                               0.07s  

   PASS  Tests\Feature\SandCasting\FinalQualityLockTest
  ✓ tc01 uncompleted ktr can record netto defect                                                                 0.07s  
  ✓ tc02 uncompleted ktr can adjust qc defect                                                                    0.07s  
  ✓ tc03 gudang jadi finalization activates final quality lock                                                   0.09s  
  ✓ tc04 netto defect mutation rejected after gudang jadi completed                                              0.10s  
  ✓ tc05 machining stages defect mutation rejected after finalization                                            0.10s  
  ✓ tc06 qc defect draft and verify rejected after finalization                                                  0.10s  
  ✓ tc07 gudang jadi defect mutation remains allowed after finalization                                          0.10s  
  ✓ tc08 other ktr in same heat remains editable                                                                 0.09s  
  ✓ tc09 ui renders locked badge for completed ktr stages                                                        0.14s  
  ✓ tc10 transaction and row locking guarantees race safety                                                      0.07s  
  ✓ tc11 existing workflow and quantity resolution unaffected                                                    0.10s  
  ✓ tc12 guard is fail closed and rejects mutation when parent ktr is missing                                    0.05s  
  ✓ tc13 is stage quality locked returns true for orphaned execution                                             0.09s  

   PASS  Tests\Feature\SandCasting\LiveCastingAvailabilityPanelTest
  ✓ total local and export equals overall total accounting for unclassified                                      0.07s  
  ✓ total line 1 to 4 equals overall total accounting for unassigned lines                                       0.06s  
  ✓ kg and pcs consistency with product weight resolver                                                          0.06s  
  ✓ line with zero po displays zero pcs and tidak ada po                                                         0.06s  
  ✓ filter on customer or code updates baseline correctly with filter active true                                0.08s  
  ✓ plans page view renders all panel elements successfully                                                      0.10s  
  ✓ items already completed in pcor are excluded from available quota                                            0.06s  
  ✓ line distribution percentage is based on weight kg including unassigned                                      0.06s  
  ✓ line card displays stacked vertical local and export details                                                 0.06s  
  ✓ perintah cor page has no line dropdown and renders read only line                                            0.06s  

   PASS  Tests\Feature\SandCasting\OverPlanCastingResultTest
  ✓ 1 plan 110 actual 120 in final heat saves successfully                                                       0.05s  
  ✓ 2 accumulation of three heats 40 40 40 for plan 110 succeeds                                                 0.05s  
  ✓ 3 over plan warning does not block submission on web form                                                    0.07s  
  ✓ 4 after pcor becomes completed new heat is rejected by service                                               0.05s  
  ✓ 5 completed pcor does not appear in candidate lines                                                          0.06s  
  ✓ 6 completed pcor cannot be bypassed via preselected order id                                                 0.05s  
  ✓ 7 direct post on completed pcor is rejected                                                                  0.06s  
  ✓ 8 issued and partially completed pcor accept over plan                                                       0.09s  
  ✓ 9 correction of existing result on completed pcor follows lifecycle and records audit trail                  0.07s  
  ✓ 10 new production code for recast creates new pcor normally                                                  0.06s  
  ✓ 11 model a commits higher quantity and prevents duplicate pcor                                               0.09s  
  ✓ 12 outstanding po never becomes negative                                                                     0.08s  
  ✓ 13 final quality lock and downstream stages have no regression                                               0.05s  

   PASS  Tests\Feature\SandCasting\PcorReopeningCorrectionTest
  ✓ 1 quantity correction keeping completed as completed                                                         0.07s  
  ✓ 2 quantity correction making completed partially completed                                                   0.06s  
  ✓ 3 new heat can be recorded after pcor reopened                                                               0.06s  
  ✓ 4 correction of production code and pcor from 26ab to 26ac                                                   0.06s  
  ✓ 5 recalculation of totals and statuses for both pcors                                                        0.07s  
  ✓ 6 rollback of entire correction transaction if any step fails                                                0.05s  
  ✓ 7 audit trail captures complete history with codes and pcor numbers                                          0.05s  
  ✓ 8 rejection of correction violating final quality lock and execution gates                                   0.05s  

   PASS  Tests\Feature\SandCasting\ZeroQuotaHeatSelectionTest
  ✓ 1 multiline pcor candidate isolation excludes zero quota line                                                0.06s  
  ✓ 2 direct post on zero quota line rejected with 422                                                           0.06s  
  ✓ 3 legitimate final heat overplan allowed then blocks subsequent heat                                         0.05s  
  ✓ 4 pcor reopening restores line to available candidates                                                       0.07s  
  ✓ 5 zero quota line rejected by service even when parent is active                                             0.04s  
  ✓ 6 preselected order url enforces remaining quota filtering                                                   0.06s  
  ✓ 7 atomic rollback on zero quota rejection                                                                    0.05s  

  Tests:    105 passed (564 assertions)
  Duration: 10.19s
```

---

## 3. Rekonsiliasi Jumlah Test per Suite

Berikut pencocokan jumlah test aktual setiap test suite dengan PHPUnit:

| No | Nama Test Suite | File Test | Jumlah Test Aktual | Status |
| :---: | :--- | :--- | :---: | :---: |
| 1 | `CastingOrderModelATest` | `tests/Feature/SandCasting/CastingOrderModelATest.php` | **12** | PASS |
| 2 | `CastingResultServiceAndControllerTest` | `tests/Feature/SandCasting/CastingResultServiceAndControllerTest.php` | **24** | PASS |
| 3 | `DefectAdjustmentTest` | `tests/Feature/SandCasting/DefectAdjustmentTest.php` | **18** | PASS |
| 4 | `FinalQualityLockTest` | `tests/Feature/SandCasting/FinalQualityLockTest.php` | **13** | PASS |
| 5 | `LiveCastingAvailabilityPanelTest` | `tests/Feature/SandCasting/LiveCastingAvailabilityPanelTest.php` | **10** | PASS |
| 6 | `OverPlanCastingResultTest` | `tests/Feature/SandCasting/OverPlanCastingResultTest.php` | **13** | PASS |
| 7 | `PcorReopeningCorrectionTest` | `tests/Feature/SandCasting/PcorReopeningCorrectionTest.php` | **8** | PASS |
| 8 | `ZeroQuotaHeatSelectionTest` | `tests/Feature/SandCasting/ZeroQuotaHeatSelectionTest.php` | **7** | PASS |
| **TOTAL** | **8 Test Suites** | | **105 tests** | **564 assertions** |

---

## 4. Identifikasi Lima Test Tambahan

Penyebab perbedaan antara hitungan manual pada laporan sebelumnya (100) dan output PHPUnit (105):

Pada dokumen `IMPLEMENTATION_ZERO_QUOTA_HEAT_GUARD.md`, suite **`DefectAdjustmentTest`** secara tidak sengaja tertulis:
```markdown
PASS Tests\Feature\SandCasting\DefectAdjustmentTest (13 tests)   <-- TYPO DOKUMENTASI
```
Padahal file `tests/Feature/SandCasting/DefectAdjustmentTest.php` memiliki **18 method test** (test 01 sampai test 18), sehingga:
$$12 + 24 + \mathbf{18} + 13 + 10 + 13 + 8 + 7 = \mathbf{105\ tests}$$

Lima test yang merupakan selisih tersebut berada di dalam test suite **`DefectAdjustmentTest`**, yaitu:

1. **`test_14_effective_gd_calculation_reflects_defect_adjustments_dynamically`** (Baris 480)
   - Menguji perhitungan dinamis Effective Good (`effective_gd`) pasca penyesuaian reject/scrap.
2. **`test_15_idempotency_key_reused_with_different_payload_is_rejected`** (Baris 528)
   - Menguji proteksi penolakan jika idempotency key yang sama dikirim ulang dengan payload data berbeda.
3. **`test_16_idempotency_key_reused_across_different_executions_is_rejected`** (Baris 550)
   - Menguji bahwa idempotency key tidak dapat dipakai silang antar record eksekusi yang berbeda.
4. **`test_17_rejected_adjustment_preserves_defect_total_and_log_history`** (Baris 577)
   - Menguji bahwa transaksi penyesuaian yang ditolak tidak merusak total defect historis.
5. **`test_18_http_endpoint_rejects_conflicting_idempotency_keys_with_422`** (Baris 604)
   - Menguji penolakan HTTP 422 oleh controller ketika terjadi konflik idempotency key pada request API.

---

## 5. Kesimpulan Verifikasi

1. Angka **105 passed (564 assertions)** dari PHPUnit adalah angka **akurat dan valid**.
2. Seluruh 8 test suite Sand Casting lulus 100% tanpa error, tanpa warning, dan tanpa skenario yang terlewat.
3. Selama proses verifikasi ini, **tidak ada kode aplikasi, file test, maupun data database yang diubah**.

