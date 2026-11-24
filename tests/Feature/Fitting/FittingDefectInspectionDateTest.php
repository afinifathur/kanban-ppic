<?php

namespace Tests\Feature\Fitting;

use App\Models\FittingItem;
use App\Models\FittingStageExecution;
use App\Models\LostWaxCastingResult;
use App\Models\LostWaxCastingResultTree;
use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxTree;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\Fitting\FittingDefectService;
use App\Services\Fitting\FittingQuantityResolverService;
use App\Services\Fitting\FittingStageExecutionService;
use App\Services\Fitting\FittingTransferService;
use App\Services\LostWax\LostWaxKitirResolverService;
use Carbon\Carbon;
use Database\Seeders\AuditorUserSeeder;
use Database\Seeders\FittingSpvUserSeeder;
use Database\Seeders\QcFittingUserSeeder;
use Database\Seeders\SalesVisibilityUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FittingDefectInspectionDateTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 8500;

    private User $adminPpicPf;

    private User $spvNettoPf;

    private LostWaxKitirResolverService $kitirResolver;

    private FittingTransferService $transferService;

    private FittingStageExecutionService $executionService;

    private FittingDefectService $defectService;

    private FittingQuantityResolverService $quantityResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setEnvVar('AUDITOR_INITIAL_PASSWORD', 'AudPass123!');
        $this->setEnvVar('SALES_INITIAL_PASSWORD', 'SalesPass123!');
        $this->setEnvVar('FITTING_SPV_INITIAL_PASSWORD', 'FitPass123!');

        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'])->syncPermissions([$permPlanning, $permExecution]);
        Role::firstOrCreate(['name' => 'ppic', 'guard_name' => 'web'])->syncPermissions([$permPlanning, $permExecution]);

        (new QcFittingUserSeeder)->run();
        (new AuditorUserSeeder)->run();
        (new SalesVisibilityUserSeeder)->run();

        $this->adminPpicPf = User::create([
            'name' => 'Admin PPIC PF',
            'email' => 'adminppicpf@peroniks.com',
            'password' => Hash::make('password'),
        ]);
        $this->adminPpicPf->assignRole('admin');

        (new FittingSpvUserSeeder)->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->spvNettoPf = User::where('email', 'spvnettopf@peroniks.com')->firstOrFail();

        $this->kitirResolver = app(LostWaxKitirResolverService::class);
        $this->transferService = app(FittingTransferService::class);
        $this->executionService = app(FittingStageExecutionService::class);
        $this->defectService = app(FittingDefectService::class);
        $this->quantityResolver = app(FittingQuantityResolverService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach (['AUDITOR_INITIAL_PASSWORD', 'SALES_INITIAL_PASSWORD', 'FITTING_SPV_INITIAL_PASSWORD'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    private function setEnvVar(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function createTransferredItem(int $qty = 112, string $date = '2026-11-23'): FittingItem
    {
        $n = self::$seq++;
        $code = "CODE-DEF-{$n}";

        $plan = ProductionPlan::create([
            'code' => $code,
            'customer' => 'PT FITTING TEST',
            'item_code' => "4.101.{$n}",
            'item_name' => 'SS304 ELBOW 90 2"',
            'aisi' => '304',
            'size' => '2"',
            'weight' => 0.8,
            'po_number' => "PO-{$n}",
            'po_quantity' => $qty,
            'qty_planned' => $qty,
            'qty_remaining' => $qty,
            'line_number' => 1,
            'scheduled_date' => $date,
            'status' => 'planning',
            'product_scope' => 'FITTING_STAINLESS',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => "LW-PO-DEF-{$n}",
            'scheduled_date' => $date,
            'status' => 'ISSUED',
            'created_by' => $this->adminPpicPf->id,
        ]);

        $line = LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $order->id,
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'aisi' => $plan->aisi,
            'size' => $plan->size,
            'qty_ordered' => $qty,
        ]);

        $castingResult = LostWaxCastingResult::create([
            'heat_number' => "H-DEF-{$n}",
            'cast_date' => $date,
            'furnace' => '1',
            'shift' => '1',
            'operator_name' => 'Op Defect',
            'total_trees' => 1,
            'total_pcs' => $qty,
            'status' => 'COMPLETED',
            'recorded_by' => $this->adminPpicPf->id,
        ]);

        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => sprintf('26%08d', $n),
            'tree_number' => 1,
            'daily_sequence' => $n,
            'production_date' => $date,
            'family_code' => '1',
            'require_layer_7' => false,
            'quantity' => $qty,
            'current_stage' => 'cor',
            'status' => 'completed',
        ]);

        LostWaxCastingResultTree::create([
            'lost_wax_casting_result_id' => $castingResult->id,
            'lost_wax_tree_id' => $tree->id,
            'active_tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'production_code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'quantity_pcs' => $qty,
            'gross_pcs' => $qty,
            'defect_pcs' => 0,
        ]);

        $kitir = $this->kitirResolver->issueOrGetKitir($castingResult, $code, $this->adminPpicPf);
        $transfer = $this->transferService->transferKitirs([$kitir->id], $this->adminPpicPf, null, null, $date, [$kitir->id => 1]);

        return $transfer->items->first()->fresh();
    }

    /**
     * Skenario 1: Scan 23 November, buka form 23 November -> default 23 November.
     */
    public function test_01_scan_on_23_nov_opened_on_23_nov_defaults_to_23_nov(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-23 10:15:00', 'Asia/Jakarta'));

        $item = $this->createTransferredItem(112, '2026-11-23');
        $exec = $this->executionService->scanStage($item->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);

        $this->assertEquals('2026-11-23', $exec->resolveDefaultInspectionDate());

        $response = $this->actingAs($this->adminPpicPf)->get(route('fitting.defects.index', [
            'stage' => 'netto_pf',
            'mode' => 'unrecorded',
        ]));
        $response->assertOk();

        // Check that openRecordModal receives '2026-11-23' as defaultDate
        $response->assertSee('"2026-11-23"', false);
    }

    /**
     * Skenario 2: Scan 23 November, buka form 24 November -> tetap default 23 November.
     */
    public function test_02_scan_on_23_nov_opened_on_24_nov_still_defaults_to_23_nov(): void
    {
        // Scan occurs on 23 Nov
        Carbon::setTestNow(Carbon::parse('2026-11-23 14:00:00', 'Asia/Jakarta'));
        $item = $this->createTransferredItem(112, '2026-11-23');
        $exec = $this->executionService->scanStage($item->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);

        // Next day: 24 Nov
        Carbon::setTestNow(Carbon::parse('2026-11-24 09:30:00', 'Asia/Jakarta'));

        $this->assertEquals('2026-11-23', $exec->resolveDefaultInspectionDate());

        $response = $this->actingAs($this->adminPpicPf)->get(route('fitting.defects.index', [
            'stage' => 'netto_pf',
            'mode' => 'unrecorded',
        ]));
        $response->assertOk();

        // The default date in the modal call is still 2026-11-23
        $this->assertStringContainsString('2026-11-23', $response->getContent());
    }

    /**
     * Skenario 3: Admin tidak mengubah tanggal -> tersimpan 23 November.
     */
    public function test_03_admin_leaves_default_date_unchanged_saves_scan_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-23 11:00:00', 'Asia/Jakarta'));
        $item = $this->createTransferredItem(112, '2026-11-23');
        $exec = $this->executionService->scanStage($item->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);

        // Submitting with default date (or omitting it so backend uses default)
        $response = $this->actingAs($this->adminPpicPf)->post(route('fitting.defects.record', $exec->id), [
            'defect_qty' => 2,
            'inspection_date' => '2026-11-23',
            'stage' => 'netto_pf',
        ]);
        $response->assertRedirect();

        $fresh = $exec->fresh();
        $this->assertEquals('2026-11-23', $fresh->inspection_date->format('Y-m-d'));
        $this->assertEquals(2, $fresh->defect_qty);
    }

    /**
     * Skenario 4: Admin mengubah menjadi 24 November -> tersimpan 24 November.
     */
    public function test_04_admin_changes_date_to_24_nov_saves_24_nov(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-23 11:00:00', 'Asia/Jakarta'));
        $item = $this->createTransferredItem(112, '2026-11-23');
        $exec = $this->executionService->scanStage($item->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);

        // Admin inspects and decides date on 24 Nov
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'Asia/Jakarta'));

        $response = $this->actingAs($this->adminPpicPf)->post(route('fitting.defects.record', $exec->id), [
            'defect_qty' => 2,
            'inspection_date' => '2026-11-24',
            'stage' => 'netto_pf',
        ]);
        $response->assertRedirect();

        $fresh = $exec->fresh();
        $this->assertEquals('2026-11-24', $fresh->inspection_date->format('Y-m-d'));
        $this->assertEquals(2, $fresh->defect_qty);
    }

    /**
     * Skenario 5: Validation error -> tanggal pilihan admin tidak hilang.
     */
    public function test_05_validation_error_preserves_admin_selected_date_and_old_inputs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00', 'Asia/Jakarta'));
        $item = $this->createTransferredItem(112, '2026-11-23');
        $exec = $this->executionService->scanStage($item->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);

        // Submitting future date or invalid defect qty triggers validation error
        $response = $this->actingAs($this->adminPpicPf)->from(route('fitting.defects.index', ['stage' => 'netto_pf', 'mode' => 'unrecorded']))
            ->post(route('fitting.defects.record', $exec->id), [
                'execution_id' => $exec->id,
                'defect_qty' => -5, // invalid!
                'inspection_date' => '2026-11-24',
                'stage' => 'netto_pf',
            ]);

        $response->assertRedirect(route('fitting.defects.index', ['stage' => 'netto_pf', 'mode' => 'unrecorded']));
        $response->assertSessionHasErrors('defect_qty');

        // Check that session has old input
        $this->assertEquals('2026-11-24', session('_old_input.inspection_date'));
        $this->assertEquals($exec->id, session('_old_input.execution_id'));
    }

    /**
     * Skenario 6: Defect 0 PCS tetap bisa disimpan (NO_DEFECT).
     */
    public function test_06_defect_zero_pcs_saves_as_no_defect(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-23 15:00:00', 'Asia/Jakarta'));
        $item = $this->createTransferredItem(112, '2026-11-23');
        $exec = $this->executionService->scanStage($item->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);

        $response = $this->actingAs($this->adminPpicPf)->post(route('fitting.defects.record', $exec->id), [
            'defect_qty' => 0,
            'inspection_date' => '2026-11-23',
            'stage' => 'netto_pf',
        ]);
        $response->assertRedirect();

        $fresh = $exec->fresh();
        $this->assertEquals(FittingStageExecution::STATUS_NO_DEFECT, $fresh->status);
        $this->assertEquals(0, $fresh->defect_qty);
        $this->assertEquals(112, $fresh->good_qty);
        $this->assertEquals('2026-11-23', $fresh->inspection_date->format('Y-m-d'));
    }

    /**
     * Skenario 7: Defect 2 PCS berhasil disimpan (WAITING_QC).
     */
    public function test_07_defect_two_pcs_saves_as_waiting_qc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-23 15:00:00', 'Asia/Jakarta'));
        $item = $this->createTransferredItem(112, '2026-11-23');
        $exec = $this->executionService->scanStage($item->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);

        $response = $this->actingAs($this->adminPpicPf)->post(route('fitting.defects.record', $exec->id), [
            'defect_qty' => 2,
            'inspection_date' => '2026-11-23',
            'stage' => 'netto_pf',
            'notes' => 'Ditemukan 2 pcs retak halus.',
        ]);
        $response->assertRedirect();

        $fresh = $exec->fresh();
        $this->assertEquals(FittingStageExecution::STATUS_WAITING_QC, $fresh->status);
        $this->assertEquals(2, $fresh->defect_qty);
        $this->assertEquals(110, $fresh->good_qty);
        $this->assertEquals('2026-11-23', $fresh->inspection_date->format('Y-m-d'));
        $this->assertEquals('Ditemukan 2 pcs retak halus.', $fresh->notes);
    }

    /**
     * Skenario 8: Defect melebihi Maks Defect tetap ditolak.
     */
    public function test_08_defect_exceeding_max_allowed_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-23 15:00:00', 'Asia/Jakarta'));
        $item = $this->createTransferredItem(112, '2026-11-23');
        $exec = $this->executionService->scanStage($item->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);

        // Effective input is 112 PCS; attempting 115 PCS must be rejected
        $rejected = false;
        try {
            $this->defectService->recordDefectQty($exec->id, 115, $this->adminPpicPf);
        } catch (InvalidArgumentException $e) {
            $rejected = true;
            $this->assertStringContainsString('melebihi', $e->getMessage());
        }

        $this->assertTrue($rejected, 'Defect exceeding max allowed must throw InvalidArgumentException.');
    }

    /**
     * Skenario 9: Auto-Nihil Defect tidak berubah.
     */
    public function test_09_auto_nihil_defect_remains_unchanged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Jakarta'));
        $item = $this->createTransferredItem(112, '2026-11-10');
        $exec = $this->executionService->scanStage($item->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);

        // Advance 6 days (> 5 calendar days threshold)
        Carbon::setTestNow(Carbon::parse('2026-11-16 11:00:00', 'Asia/Jakarta'));

        $nihilExec = $this->defectService->timeoutToAutoNihil($exec);
        $this->assertNotNull($nihilExec);

        $fresh = $exec->fresh();
        $this->assertEquals(FittingStageExecution::STATUS_NO_DEFECT, $fresh->status);
        $this->assertTrue((bool) $fresh->is_auto_nihil);
        $this->assertNotNull($fresh->auto_nihil_at);
        $this->assertEquals(0, $fresh->defect_qty);
    }

    /**
     * Skenario 10: Data defect lama tetap terbaca normal.
     */
    public function test_10_legacy_defect_data_reads_normally(): void
    {
        $item = $this->createTransferredItem(112, '2026-11-01');

        // Existing execution with inspection_date set
        $execWithDate = FittingStageExecution::create([
            'fitting_item_id' => $item->id,
            'stage' => FittingItem::STAGE_NETTO_PF,
            'checkpoint_code' => FittingStageExecution::CHECKPOINT_NETTO,
            'input_qty' => 112,
            'defect_qty' => 3,
            'good_qty' => 109,
            'status' => FittingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->spvNettoPf->id,
            'executed_at' => Carbon::parse('2026-11-01 08:00:00'),
            'physical_done_at' => Carbon::parse('2026-11-01 08:00:00'),
            'inspection_date' => '2026-11-02',
        ]);
        $this->assertEquals('2026-11-02', $execWithDate->resolveEffectiveDefectDate());

        // Existing execution without inspection_date falls back to physical_done_at
        $execWithoutDate = FittingStageExecution::create([
            'fitting_item_id' => $item->id,
            'stage' => FittingItem::STAGE_KIMIA,
            'checkpoint_code' => FittingStageExecution::CHECKPOINT_KIMIA,
            'input_qty' => 109,
            'defect_qty' => 1,
            'good_qty' => 108,
            'status' => FittingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->spvNettoPf->id,
            'executed_at' => Carbon::parse('2026-11-03 14:00:00'),
            'physical_done_at' => Carbon::parse('2026-11-03 14:00:00'),
            'inspection_date' => null,
        ]);
        $this->assertEquals('2026-11-03', $execWithoutDate->resolveEffectiveDefectDate());
    }
}
