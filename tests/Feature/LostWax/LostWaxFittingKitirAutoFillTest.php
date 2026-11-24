<?php

namespace Tests\Feature\LostWax;

use App\Models\FittingItem;
use App\Models\FittingStageExecution;
use App\Models\FittingTransfer;
use App\Models\LostWaxCastingResult;
use App\Models\LostWaxCastingResultTree;
use App\Models\LostWaxKitir;
use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxTree;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWax\LostWaxKitirResolverService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LostWaxFittingKitirAutoFillTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $ppicUser;

    protected static int $seq = 9000;

    protected function setUp(): void
    {
        parent::setUp();

        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->givePermissionTo([$permPlanning, $permExecution]);

        $ppicRole = Role::firstOrCreate(['name' => 'ppic', 'guard_name' => 'web']);
        $ppicRole->givePermissionTo([$permPlanning, $permExecution]);

        $this->adminUser = User::factory()->create(['email' => 'admin_autofill@peroniks.com']);
        $this->adminUser->assignRole('admin');

        $this->ppicUser = User::factory()->create(['email' => 'ppic_autofill@peroniks.com']);
        $this->ppicUser->assignRole('ppic');

        $this->bypassRoleGateFix();
    }

    protected function bypassRoleGateFix(): void
    {
        $gate = Gate::getFacadeRoot();
        $ref = new \ReflectionClass($gate);
        $prop = $ref->getProperty('beforeCallbacks');
        $prop->setAccessible(true);
        $prop->setValue($gate, [
            function ($user, $ability) {
                if (method_exists($user, 'hasPermissionTo')) {
                    try {
                        return $user->hasPermissionTo($ability) ? true : null;
                    } catch (\Throwable $e) {
                        return null;
                    }
                }

                return null;
            },
        ]);
    }

    /**
     * Create a complete casting result, print order, trees, and issued kitir.
     */
    protected function createKitirFixture(int $qty = 100, ?string $code = null): array
    {
        $n = self::$seq++;
        $prodCode = $code ?? "CODE-{$n}";
        $castDate = Carbon::today()->format('Y-m-d');

        $plan = ProductionPlan::create([
            'code' => $prodCode,
            'title' => 'Rencana Fitting Test',
            'item_code' => "ITM-{$n}",
            'item_name' => 'Elbow 90 2" SS304',
            'aisi' => '304',
            'size' => '2"',
            'weight' => 0.50,
            'po_number' => "PO-{$n}",
            'po_quantity' => $qty,
            'qty_planned' => $qty,
            'qty_remaining' => $qty,
            'line_number' => 1,
            'customer' => 'PT Peroni Fitting Test',
            'product_scope' => 'FITTING_STAINLESS',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'status' => 'active',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => "LW-PO-{$n}",
            'scheduled_date' => $castDate,
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $line = LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $order->id,
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'aisi' => '304',
            'size' => $plan->size,
            'qty_ordered' => $qty,
        ]);

        $castingResult = LostWaxCastingResult::create([
            'heat_number' => "H-AUTO-{$n}",
            'cast_date' => $castDate,
            'furnace' => '1',
            'shift' => '1',
            'operator_name' => 'Tester',
            'total_trees' => 1,
            'total_pcs' => $qty,
            'status' => 'COMPLETED',
            'recorded_by' => $this->adminUser->id,
        ]);

        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => sprintf('26%08d', $n),
            'tree_number' => 1,
            'daily_sequence' => 1,
            'production_date' => $castDate,
            'family_code' => '1',
            'require_layer_7' => false,
            'quantity' => $qty,
            'current_stage' => 'cor',
            'status' => 'active',
            'created_by' => $this->adminUser->id,
        ]);

        LostWaxCastingResultTree::create([
            'lost_wax_casting_result_id' => $castingResult->id,
            'lost_wax_tree_id' => $tree->id,
            'active_tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'production_code' => $prodCode,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'quantity_pcs' => $qty,
            'gross_pcs' => $qty,
            'defect_pcs' => 0,
            'tree_number' => 1,
            'gross_weight' => 50.0,
            'defect_qty' => 0,
        ]);

        $resolver = app(LostWaxKitirResolverService::class);
        $kitir = $resolver->issueOrGetKitir($castingResult, $prodCode, $this->adminUser);

        return [$castingResult, $kitir, $plan];
    }

    /**
     * Create transferred FittingItem linked to the LostWaxKitir.
     */
    protected function attachFittingItem(LostWaxKitir $kitir, ProductionPlan $plan, int $qty = 100): FittingItem
    {
        $n = self::$seq++;

        $transfer = FittingTransfer::create([
            'transfer_number' => "TRF-{$n}",
            'transfer_date' => Carbon::today()->format('Y-m-d'),
            'transferred_by' => $this->adminUser->id,
            'total_kitirs' => 1,
            'total_pcs' => $qty,
            'total_weight_kg' => 50.0,
            'status' => FittingTransfer::STATUS_ACTIVE,
        ]);

        return FittingItem::create([
            'fitting_transfer_id' => $transfer->id,
            'lost_wax_kitir_id' => $kitir->id,
            'active_kitir_id' => $kitir->id,
            'lost_wax_casting_result_id' => $kitir->lost_wax_casting_result_id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $kitir->traveler_number,
            'heat_number' => $kitir->castingResult->heat_number,
            'cast_date' => $kitir->castingResult->cast_date,
            'production_code' => $kitir->production_code,
            'item_code' => $plan->item_code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'line_number' => 1,
            'tree_count' => 1,
            'qty_transferred' => $qty,
            'unit_weight_kg' => 0.50,
            'total_weight_kg' => 50.0,
            'current_stage' => FittingItem::STAGE_NETTO_PF,
            'status' => FittingItem::STATUS_ACTIVE,
        ]);
    }

    /**
     * Create stage execution record on the FittingItem.
     */
    protected function createExecution(
        FittingItem $item,
        string $stage,
        string $checkpointCode,
        int $inputQty,
        int $defectQty,
        int $goodQty,
        Carbon $physDoneAt,
        string $status = FittingStageExecution::STATUS_WAITING_DEFECT
    ): FittingStageExecution {
        return FittingStageExecution::create([
            'fitting_item_id' => $item->id,
            'stage' => $stage,
            'checkpoint_code' => $checkpointCode,
            'input_qty' => $inputQty,
            'defect_qty' => $defectQty,
            'good_qty' => $goodQty,
            'status' => $status,
            'operator_id' => $this->adminUser->id,
            'executed_at' => $physDoneAt,
            'physical_done_at' => $physDoneAt,
        ]);
    }

    /**
     * 1. Kitir belum memiliki activeFittingItem:
     * Enam baris tetap tampil, seluruh kolom operasional kosong.
     */
    public function test_01_kitir_without_active_fitting_item_renders_six_blank_operational_rows(): void
    {
        [$castingResult, $kitir, $plan] = $this->createKitirFixture(100, 'KTR-BLANK-01');

        $this->assertNull($kitir->activeFittingItem);

        $response = $this->actingAs($this->adminUser)->get(
            route('lost-wax.casting-results.kitir.print', ['castingResult' => $castingResult, 'code' => $kitir->production_code])
        );

        $response->assertOk();
        $processRows = $response->viewData('kitirItems')[0]['processRows'];

        $this->assertCount(6, $processRows);
        foreach ($processRows as $row) {
            $this->assertNull($row['hasil'], "Stage {$row['label']} hasil must be null");
            $this->assertNull($row['rusak'], "Stage {$row['label']} rusak must be null");
            $this->assertNull($row['tanggal'], "Stage {$row['label']} tanggal must be null");
            $this->assertNull($row['operator'], "Stage {$row['label']} operator must be null");
        }
    }

    /**
     * 2. Traveler baru ditransfer tetapi belum diproses:
     * Enam baris operasional tetap kosong.
     */
    public function test_02_traveler_newly_transferred_without_executions_renders_blank_rows(): void
    {
        [$castingResult, $kitir, $plan] = $this->createKitirFixture(120, 'KTR-TRF-02');
        $fittingItem = $this->attachFittingItem($kitir, $plan, 120);

        $this->assertNotNull($kitir->fresh()->activeFittingItem);
        $this->assertEquals(0, $fittingItem->stageExecutions()->count());

        $response = $this->actingAs($this->adminUser)->get(
            route('lost-wax.casting-results.kitir.print', ['castingResult' => $castingResult, 'code' => $kitir->production_code])
        );

        $response->assertOk();
        $processRows = $response->viewData('kitirItems')[0]['processRows'];

        $this->assertCount(6, $processRows);
        foreach ($processRows as $row) {
            $this->assertNull($row['hasil']);
            $this->assertNull($row['rusak']);
            $this->assertNull($row['tanggal']);
            $this->assertNull($row['operator']);
        }
    }

    /**
     * 3. Traveler baru menyelesaikan NETTO PF:
     * Baris NETTO terisi, lima baris lainnya kosong.
     */
    public function test_03_traveler_completed_netto_pf_auto_fills_netto_row_only(): void
    {
        [$castingResult, $kitir, $plan] = $this->createKitirFixture(80, 'KTR-NETTO-03');
        $fittingItem = $this->attachFittingItem($kitir, $plan, 80);

        $doneDate = Carbon::parse('2026-11-20 10:30:00');
        $this->createExecution(
            $fittingItem,
            FittingItem::STAGE_NETTO_PF,
            FittingStageExecution::CHECKPOINT_NETTO,
            80,
            0,
            80,
            $doneDate,
            FittingStageExecution::STATUS_NO_DEFECT
        );

        $response = $this->actingAs($this->adminUser)->get(
            route('lost-wax.casting-results.kitir.print', ['castingResult' => $castingResult, 'code' => $kitir->production_code])
        );

        $response->assertOk();
        $processRows = $response->viewData('kitirItems')[0]['processRows'];

        // Row 1: NETTO filled
        $this->assertEquals(1, $processRows[0]['no']);
        $this->assertEquals('NETTO', $processRows[0]['label']);
        $this->assertEquals(80, $processRows[0]['hasil']);
        $this->assertEquals(0, $processRows[0]['rusak']);
        $this->assertEquals('20/11/2026', $processRows[0]['tanggal']);
        $this->assertEquals('SPV NETTO', $processRows[0]['operator']);

        // Rows 2-6: blank
        for ($i = 1; $i < 6; $i++) {
            $this->assertNull($processRows[$i]['hasil'], "Row {$i} hasil must be null");
            $this->assertNull($processRows[$i]['rusak'], "Row {$i} rusak must be null");
            $this->assertNull($processRows[$i]['tanggal'], "Row {$i} tanggal must be null");
            $this->assertNull($processRows[$i]['operator'], "Row {$i} operator must be null");
        }

        // HTML rendering assertions
        $html = $response->getContent();
        $this->assertStringContainsString('>NETTO</td>', $html);
        $this->assertStringContainsString('>80</td>', $html);
        $this->assertStringContainsString('>0</td>', $html);
        $this->assertStringContainsString('>20/11/2026</td>', $html);
        $this->assertStringContainsString('>SPV NETTO</td>', $html);
    }

    /**
     * 4. Traveler menyelesaikan beberapa tahap dengan defect di tahap hulu:
     * Kuantitas input efektif di tahap hilir berkurang sesuai resolver resmi.
     */
    public function test_04_traveler_completed_multiple_stages_with_defect_propagates_quantities(): void
    {
        [$castingResult, $kitir, $plan] = $this->createKitirFixture(100, 'KTR-PROP-04');
        $fittingItem = $this->attachFittingItem($kitir, $plan, 100);

        // Stage 1: Netto PF (input 100, defect 5 -> good 95)
        $this->createExecution(
            $fittingItem,
            FittingItem::STAGE_NETTO_PF,
            FittingStageExecution::CHECKPOINT_NETTO,
            100,
            5,
            95,
            Carbon::parse('2026-11-20 09:00:00'),
            FittingStageExecution::STATUS_CONFIRMED
        );

        // Stage 2: Kimia (input efektif 95, defect 0 -> good 95)
        $this->createExecution(
            $fittingItem,
            FittingItem::STAGE_KIMIA,
            FittingStageExecution::CHECKPOINT_KIMIA,
            95,
            0,
            95,
            Carbon::parse('2026-11-21 11:15:00'),
            FittingStageExecution::STATUS_NO_DEFECT
        );

        // Stage 3: Marking (input efektif 95, defect 2 -> good 93)
        $this->createExecution(
            $fittingItem,
            FittingItem::STAGE_MARKING,
            FittingStageExecution::CHECKPOINT_MARKING,
            95,
            2,
            93,
            Carbon::parse('2026-11-22 14:45:00'),
            FittingStageExecution::STATUS_CONFIRMED
        );

        $response = $this->actingAs($this->adminUser)->get(
            route('lost-wax.casting-results.kitir.print', ['castingResult' => $castingResult, 'code' => $kitir->production_code])
        );

        $response->assertOk();
        $processRows = $response->viewData('kitirItems')[0]['processRows'];

        // Row 1: NETTO
        $this->assertEquals(100, $processRows[0]['hasil']);
        $this->assertEquals(5, $processRows[0]['rusak']);
        $this->assertEquals('20/11/2026', $processRows[0]['tanggal']);
        $this->assertEquals('SPV NETTO', $processRows[0]['operator']);

        // Row 2: KIMIA (effective input = 100 - 5 = 95)
        $this->assertEquals(95, $processRows[1]['hasil']);
        $this->assertEquals(0, $processRows[1]['rusak']);
        $this->assertEquals('21/11/2026', $processRows[1]['tanggal']);
        $this->assertEquals('SPV KIMIA', $processRows[1]['operator']);

        // Row 3: MARKING (effective input = 100 - 5 = 95)
        $this->assertEquals(95, $processRows[2]['hasil']);
        $this->assertEquals(2, $processRows[2]['rusak']);
        $this->assertEquals('22/11/2026', $processRows[2]['tanggal']);
        $this->assertEquals('SPV MARKING', $processRows[2]['operator']);

        // Rows 4-6: unexecuted -> blank
        $this->assertNull($processRows[3]['hasil']);
        $this->assertNull($processRows[4]['hasil']);
        $this->assertNull($processRows[5]['hasil']);
    }

    /**
     * 5. Traveler menyelesaikan GUDANG JADI PF:
     * Seluruh 6 baris terisi, baris Gudang Jadi menggunakan resolveEffectiveGoodQty.
     */
    public function test_05_traveler_completed_gudang_jadi_pf_renders_all_six_rows_with_effective_good_qty(): void
    {
        [$castingResult, $kitir, $plan] = $this->createKitirFixture(100, 'KTR-ALL-05');
        $fittingItem = $this->attachFittingItem($kitir, $plan, 100);

        // Stages with defects: Netto -4, Kimia -0, Marking -2, CNC -3, QC -1, Gudang Jadi -0
        // Net good entering Gudang Jadi = 100 - 4 - 2 - 3 - 1 = 90 PCS
        $stages = [
            [FittingItem::STAGE_NETTO_PF, FittingStageExecution::CHECKPOINT_NETTO, 100, 4, 96, '2026-11-20 08:00:00'],
            [FittingItem::STAGE_KIMIA, FittingStageExecution::CHECKPOINT_KIMIA, 96, 0, 96, '2026-11-21 09:00:00'],
            [FittingItem::STAGE_MARKING, FittingStageExecution::CHECKPOINT_MARKING, 96, 2, 94, '2026-11-22 10:00:00'],
            [FittingItem::STAGE_BUBUT_CNC_PF, FittingStageExecution::CHECKPOINT_CNC, 94, 3, 91, '2026-11-23 11:00:00'],
            [FittingItem::STAGE_QC_PF, FittingStageExecution::CHECKPOINT_QC, 91, 1, 90, '2026-11-24 13:00:00'],
            [FittingItem::STAGE_GUDANG_JADI_PF, FittingStageExecution::CHECKPOINT_GUDANG_JADI, 90, 0, 90, '2026-11-24 16:00:00'],
        ];

        foreach ($stages as [$stg, $chk, $in, $def, $good, $date]) {
            $this->createExecution($fittingItem, $stg, $chk, $in, $def, $good, Carbon::parse($date), FittingStageExecution::STATUS_CONFIRMED);
        }

        $response = $this->actingAs($this->adminUser)->get(
            route('lost-wax.casting-results.kitir.print', ['castingResult' => $castingResult, 'code' => $kitir->production_code])
        );

        $response->assertOk();
        $processRows = $response->viewData('kitirItems')[0]['processRows'];

        $this->assertCount(6, $processRows);

        $this->assertEquals(100, $processRows[0]['hasil']); // Netto input
        $this->assertEquals(4, $processRows[0]['rusak']);

        $this->assertEquals(96, $processRows[1]['hasil']); // Kimia input
        $this->assertEquals(0, $processRows[1]['rusak']);

        $this->assertEquals(96, $processRows[2]['hasil']); // Marking input
        $this->assertEquals(2, $processRows[2]['rusak']);

        $this->assertEquals(94, $processRows[3]['hasil']); // CNC input
        $this->assertEquals(3, $processRows[3]['rusak']);

        $this->assertEquals(91, $processRows[4]['hasil']); // QC input
        $this->assertEquals(1, $processRows[4]['rusak']);

        // Stage 6 Gudang Jadi: displays final net good accepted = 90
        $this->assertEquals(90, $processRows[5]['hasil']);
        $this->assertEquals(0, $processRows[5]['rusak']);
        $this->assertEquals('24/11/2026', $processRows[5]['tanggal']);
        $this->assertEquals('SPV GUDANG', $processRows[5]['operator']);

        // HTML rendering assertions for all 6 stages
        $html = $response->getContent();
        $this->assertStringContainsString('>NETTO</td>', $html);
        $this->assertStringContainsString('>100</td>', $html);
        $this->assertStringContainsString('>KIMIA</td>', $html);
        $this->assertStringContainsString('>96</td>', $html);
        $this->assertStringContainsString('>MARKING</td>', $html);
        $this->assertStringContainsString('>BUBUT CNC</td>', $html);
        $this->assertStringContainsString('>94</td>', $html);
        $this->assertStringContainsString('>QC</td>', $html);
        $this->assertStringContainsString('>91</td>', $html);
        $this->assertStringContainsString('>GUDANG JADI</td>', $html);
        $this->assertStringContainsString('>90</td>', $html);
        $this->assertStringContainsString('>SPV GUDANG</td>', $html);
    }

    /**
     * 6. Auto-Nihil:
     * defect_qty = 0 ditampilkan sebagai 0, tanggal tetap physical_done_at, status aman.
     */
    public function test_06_auto_nihil_execution_renders_defect_zero_and_original_physical_date(): void
    {
        [$castingResult, $kitir, $plan] = $this->createKitirFixture(50, 'KTR-NIHIL-06');
        $fittingItem = $this->attachFittingItem($kitir, $plan, 50);

        $physDate = Carbon::parse('2026-11-18 14:00:00');
        $autoNihilDate = Carbon::parse('2026-11-23 15:00:00');

        $exec = FittingStageExecution::create([
            'fitting_item_id' => $fittingItem->id,
            'stage' => FittingItem::STAGE_NETTO_PF,
            'checkpoint_code' => FittingStageExecution::CHECKPOINT_NETTO,
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => FittingStageExecution::STATUS_NO_DEFECT,
            'operator_id' => $this->adminUser->id,
            'executed_at' => $physDate,
            'physical_done_at' => $physDate,
            'is_auto_nihil' => true,
            'auto_nihil_at' => $autoNihilDate,
            'notes' => 'Penetapan Nihil Otomatis Sistem',
        ]);

        $response = $this->actingAs($this->adminUser)->get(
            route('lost-wax.casting-results.kitir.print', ['castingResult' => $castingResult, 'code' => $kitir->production_code])
        );

        $response->assertOk();
        $processRows = $response->viewData('kitirItems')[0]['processRows'];

        $this->assertEquals(50, $processRows[0]['hasil']);
        $this->assertEquals(0, $processRows[0]['rusak']);
        $this->assertEquals('18/11/2026', $processRows[0]['tanggal']); // Physical done date, not auto_nihil date
        $this->assertEquals('SPV NETTO', $processRows[0]['operator']);

        // Verify status and fields in DB were not mutated by print
        $exec->refresh();
        $this->assertTrue($exec->is_auto_nihil);
        $this->assertEquals(FittingStageExecution::STATUS_NO_DEFECT, $exec->status);
        $this->assertEquals(0, $exec->defect_qty);
    }

    /**
     * 7. Regression:
     * Batch printing (printAll) works and print audit counters increment correctly.
     */
    public function test_07_regression_batch_print_all_increments_counter_and_maintains_data(): void
    {
        [$castingResult, $kitir, $plan] = $this->createKitirFixture(60, 'KTR-BATCH-07');
        $fittingItem = $this->attachFittingItem($kitir, $plan, 60);

        $this->createExecution(
            $fittingItem,
            FittingItem::STAGE_NETTO_PF,
            FittingStageExecution::CHECKPOINT_NETTO,
            60,
            0,
            60,
            Carbon::parse('2026-11-20 10:00:00'),
            FittingStageExecution::STATUS_NO_DEFECT
        );

        $initialPrintCount = $kitir->print_count;

        $response = $this->actingAs($this->adminUser)->get(
            route('lost-wax.casting-results.kitir.all', $castingResult)
        );

        $response->assertOk();
        $kitirItems = $response->viewData('kitirItems');
        $this->assertCount(1, $kitirItems);
        $this->assertEquals(60, $kitirItems[0]['processRows'][0]['hasil']);

        // Audit print count incremented
        $kitir->refresh();
        $this->assertEquals($initialPrintCount + 1, $kitir->print_count);
        $this->assertEquals($this->adminUser->id, $kitir->last_printed_by);
        $this->assertNotNull($kitir->printed_at);
    }

    /**
     * 8. Read-only assertion:
     * Ensure database production domain records (FittingItem & FittingStageExecution)
     * are completely identical before and after kitir print.
     */
    public function test_08_read_only_assertion_ensures_no_production_domain_mutation(): void
    {
        [$castingResult, $kitir, $plan] = $this->createKitirFixture(150, 'KTR-RO-08');
        $fittingItem = $this->attachFittingItem($kitir, $plan, 150);

        $exec = $this->createExecution(
            $fittingItem,
            FittingItem::STAGE_NETTO_PF,
            FittingStageExecution::CHECKPOINT_NETTO,
            150,
            3,
            147,
            Carbon::parse('2026-11-22 08:30:00'),
            FittingStageExecution::STATUS_CONFIRMED
        );

        // Snapshot database state before print
        $itemBefore = $fittingItem->fresh()->toArray();
        $execBefore = $exec->fresh()->toArray();

        // Perform print request
        $response = $this->actingAs($this->adminUser)->get(
            route('lost-wax.casting-results.kitir.print', ['castingResult' => $castingResult, 'code' => $kitir->production_code])
        );

        $response->assertOk();

        // Snapshot database state after print
        $itemAfter = $fittingItem->fresh()->toArray();
        $execAfter = $exec->fresh()->toArray();

        // Exact match assertions (no mutation to production records)
        $this->assertEquals($itemBefore, $itemAfter);
        $this->assertEquals($execBefore, $execAfter);
    }
}
