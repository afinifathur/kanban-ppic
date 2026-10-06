<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use App\Services\SandCasting\SandCastingProductionFloorQueryService;
use App\Services\SandCasting\SandCastingStageExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CncSingleCheckpointExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $spvNetto;

    protected User $spvBubutOd;

    protected User $spvBubutCnc;

    protected User $spvBor;

    protected SandCastingStageExecutionService $executionService;

    protected SandCastingProductionFloorQueryService $queryService;

    protected function setUp(): void
    {
        parent::setUp();

        $perm = Permission::firstOrCreate(['name' => 'access_execution']);
        $roleSpv = Role::firstOrCreate(['name' => 'spv']);
        $roleSpv->givePermissionTo($perm);

        $roleAdmin = Role::firstOrCreate(['name' => 'admin']);
        $roleAdmin->givePermissionTo($perm);

        $this->adminUser = User::factory()->create(['name' => 'Admin Test', 'email' => 'admin@peroniks.com']);
        $this->adminUser->assignRole('admin');

        $this->spvNetto = User::factory()->create(['name' => 'SPV Netto', 'email' => 'spvnetto@peroniks.com', 'assigned_stage' => 'netto']);
        $this->spvNetto->assignRole('spv');

        $this->spvBubutOd = User::factory()->create(['name' => 'SPV Bubut OD', 'email' => 'spvubutod@peroniks.com', 'assigned_stage' => 'bubut_od']);
        $this->spvBubutOd->assignRole('spv');

        $this->spvBubutCnc = User::factory()->create(['name' => 'SPV Bubut CNC', 'email' => 'spvubutcnc@peroniks.com', 'assigned_stage' => 'bubut_cnc']);
        $this->spvBubutCnc->assignRole('spv');

        $this->spvBor = User::factory()->create(['name' => 'SPV Bor', 'email' => 'spvbor@peroniks.com', 'assigned_stage' => 'bor']);
        $this->spvBor->assignRole('spv');

        $this->executionService = new SandCastingStageExecutionService;
        $this->queryService = new SandCastingProductionFloorQueryService;
    }

    protected function createKtrLine(array $attributes = []): SandCastingCastingResultLine
    {
        $plan = ProductionPlan::create([
            'code' => '268ET'.rand(100, 999),
            'title' => 'Rencana SC '.rand(100, 999),
            'item_code' => '4.'.rand(100, 999),
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-'.rand(100, 999),
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => 'PT SINAR METAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-'.rand(1000, 9999),
            'scheduled_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'notes' => 'Test order',
            'created_by' => $this->adminUser->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'aisi' => 'FC250',
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 250.00,
            'status' => 'issued',
        ]);

        $castingResult = SandCastingCastingResult::create([
            'heat_number' => 'A214092'.rand(100, 999),
            'cast_date' => now()->toDateString(),
            'furnace' => 'F1',
            'shift' => '1',
            'operator_name' => 'Budi Cor',
            'recorded_by' => $this->adminUser->id,
            'total_qty_good' => 100,
            'total_qty_reject' => 0,
        ]);

        $travelerNumber = 'KTR-'.now()->format('Ymd').'-'.sprintf('%04d', rand(1, 9999));

        return $castingResult->lines()->create(array_merge([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $travelerNumber,
            'qty_good' => 100,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 250.00,
            'current_stage' => 'bubut_cnc',
            'queue_position' => null,
            'is_urgent' => false,
        ], $attributes));
    }

    /**
     * TEST 1: CNC execution baru.
     * Given: KTR berada di bubut_cnc dan belum ada CNC execution.
     * When: CNC_MACHINING diselesaikan.
     * Then: execution CNC berhasil dibuat, hanya 1 execution CNC, physical_done_at terisi, current_stage = bor.
     */
    public function test_1_cnc_new_execution_creates_single_record_and_advances_to_bor(): void
    {
        $line = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);

        $this->executionService->markPhysicalDone($line->traveler_number, 'netto', (int) $this->spvNetto->id);
        $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_od', (int) $this->spvBubutOd->id);

        $line->refresh();
        $this->assertSame('bubut_cnc', $line->current_stage);

        // Check active checkpoint is CNC_MACHINING
        $lookup = $this->queryService->findByTraveler($line->traveler_number);
        $this->assertSame('CNC_MACHINING', $lookup['active_checkpoint']);

        // Execute CNC physical completion
        $cncExec = $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_cnc', (int) $this->spvBubutCnc->id);

        $this->assertInstanceOf(SandCastingStageExecution::class, $cncExec);
        $this->assertSame('CNC_MACHINING', $cncExec->checkpoint_code);
        $this->assertSame(50, $cncExec->input_qty);
        $this->assertNotNull($cncExec->physical_done_at);
        $this->assertSame(SandCastingStageExecution::STATUS_WAITING_DEFECT, $cncExec->status);

        // Verify line is immediately advanced to 'bor'
        $line->refresh();
        $this->assertSame('bor', $line->current_stage);

        // Verify only 1 CNC execution exists in DB
        $cncExecutions = $line->stageExecutions()->where('stage', 'bubut_cnc')->get();
        $this->assertCount(1, $cncExecutions);
        $this->assertSame('CNC_MACHINING', $cncExecutions->first()->checkpoint_code);
    }

    /**
     * TEST 2: Duplicate CNC scan.
     * Given: CNC_MACHINING sudah selesai.
     * When: request CNC kedua dilakukan.
     * Then: ditolak, tidak membuat execution kedua, stage tetap bor.
     */
    public function test_2_duplicate_cnc_scan_rejected(): void
    {
        $line = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);

        $this->executionService->markPhysicalDone($line->traveler_number, 'netto', (int) $this->spvNetto->id);
        $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_od', (int) $this->spvBubutOd->id);

        // First CNC execution
        $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_cnc', (int) $this->spvBubutCnc->id);

        // Second CNC attempt must be rejected
        $this->expectException(InvalidArgumentException::class);
        $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_cnc', (int) $this->spvBubutCnc->id);
    }

    /**
     * TEST 3: BOR readiness.
     * Given: CNC physical completion sudah selesai.
     * Then: KTR muncul sebagai READY untuk BOR sesuai query/service existing.
     */
    public function test_3_bor_readiness_after_cnc_physical_completion(): void
    {
        $line = $this->createKtrLine(['qty_good' => 75, 'current_stage' => 'netto']);

        $this->executionService->markPhysicalDone($line->traveler_number, 'netto', (int) $this->spvNetto->id);
        $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_od', (int) $this->spvBubutOd->id);
        $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_cnc', (int) $this->spvBubutCnc->id);

        $line->refresh();
        $this->assertSame('bor', $line->current_stage);

        // Query lookup check
        $lookup = $this->queryService->findByTraveler($line->traveler_number);
        $this->assertSame('bor', $lookup['current_stage']);
        $this->assertSame('BOR_DRILLING', $lookup['active_checkpoint']);
        $this->assertSame(75, $lookup['current_input_qty']);
        $this->assertSame('READY', $lookup['operational_status']);

        // Kanban query check
        $borKanban = $this->queryService->getStageKanbanData('bor');
        $this->assertCount(1, $borKanban['ready']);
        $this->assertSame($line->traveler_number, $borKanban['ready'][0]['traveler_number']);
        $this->assertSame('BOR_DRILLING', $borKanban['ready'][0]['active_checkpoint']);
        $this->assertSame(75, $borKanban['ready'][0]['input_qty']);
    }

    /**
     * TEST 4: Defect pending does not block BOR physical flow.
     * Given: CNC selesai secara fisik tetapi defect belum dicatat / QC belum diverifikasi.
     * Then: KTR tetap boleh berada di BOR dan BOR physical completion dapat diproses.
     */
    public function test_4_defect_pending_does_not_block_bor_physical_flow(): void
    {
        $line = $this->createKtrLine(['qty_good' => 40, 'current_stage' => 'netto']);

        $this->executionService->markPhysicalDone($line->traveler_number, 'netto', (int) $this->spvNetto->id);
        $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_od', (int) $this->spvBubutOd->id);
        $cncExec = $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_cnc', (int) $this->spvBubutCnc->id);

        // CNC execution is WAITING_DEFECT (defect pending!)
        $this->assertSame(SandCastingStageExecution::STATUS_WAITING_DEFECT, $cncExec->status);

        // SPV Bor executes physical completion without waiting for CNC defect/QC!
        $borExec = $this->executionService->markPhysicalDone($line->traveler_number, 'bor', (int) $this->spvBor->id);

        $this->assertInstanceOf(SandCastingStageExecution::class, $borExec);
        $this->assertSame('BOR_DRILLING', $borExec->checkpoint_code);
        $this->assertSame(40, $borExec->input_qty);
        $this->assertSame('qc', $line->fresh()->current_stage);
    }

    /**
     * TEST 5: Historical compatibility.
     * Given: historical execution mempunyai checkpoint QC_POST_CNC atau QC_PRE_BOR.
     * Then: existing historical record masih dapat dibaca dan downstream BOR execution tetap berjalan.
     */
    public function test_5_historical_compatibility_with_multi_checkpoint_records(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'bubut_cnc']);

        // Seed historical multi-checkpoints directly
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 0,
            'good_qty' => 100,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->spvNetto->id,
            'physical_done_at' => now()->subHours(5),
            'executed_at' => now()->subHours(5),
        ]);

        $line->stageExecutions()->create([
            'stage' => 'bubut_od',
            'checkpoint_code' => 'OD_TURNING',
            'input_qty' => 100,
            'defect_qty' => 0,
            'good_qty' => 100,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->spvBubutOd->id,
            'physical_done_at' => now()->subHours(4),
            'executed_at' => now()->subHours(4),
        ]);

        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 100,
            'defect_qty' => 2,
            'good_qty' => 98,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->spvBubutCnc->id,
            'physical_done_at' => now()->subHours(3),
            'executed_at' => now()->subHours(3),
        ]);

        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'QC_PRE_BOR',
            'input_qty' => 98,
            'defect_qty' => 3,
            'good_qty' => 95,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->spvBubutCnc->id,
            'physical_done_at' => now()->subHours(2),
            'executed_at' => now()->subHours(2),
        ]);

        // Manually set stage to bor as historical data
        $line->current_stage = 'bor';
        $line->save();

        // Query service can read historical line and resolves input qty correctly
        $lookup = $this->queryService->findByTraveler($line->traveler_number);
        $this->assertNotNull($lookup);
        $this->assertSame('bor', $lookup['current_stage']);
        $this->assertSame('BOR_DRILLING', $lookup['active_checkpoint']);

        // Bor execution succeeds using fallback to latest CNC checkpoint
        $borExec = $this->executionService->markPhysicalDone($line->traveler_number, 'bor', (int) $this->spvBor->id);
        $this->assertSame('BOR_DRILLING', $borExec->checkpoint_code);
        $this->assertSame('qc', $line->fresh()->current_stage);
    }

    /**
     * TEST 6: Full CNC -> BOR flow.
     * Simulates COR -> NETTO -> OD -> CNC -> BOR.
     * Verifies that CNC only requires ONE physical completion before advancing to BOR.
     */
    public function test_6_full_pipeline_single_cnc_physical_completion(): void
    {
        $line = $this->createKtrLine(['qty_good' => 60, 'current_stage' => 'netto']);

        // 1. NETTO
        $this->executionService->markPhysicalDone($line->traveler_number, 'netto', (int) $this->spvNetto->id);
        $this->assertSame('bubut_od', $line->fresh()->current_stage);

        // 2. BUBUT OD
        $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_od', (int) $this->spvBubutOd->id);
        $this->assertSame('bubut_cnc', $line->fresh()->current_stage);

        // 3. BUBUT CNC (Single scan & completion!)
        $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_cnc', (int) $this->spvBubutCnc->id);
        $this->assertSame('bor', $line->fresh()->current_stage);

        // 4. BOR
        $this->executionService->markPhysicalDone($line->traveler_number, 'bor', (int) $this->spvBor->id);
        $this->assertSame('qc', $line->fresh()->current_stage);

        // Verify stage execution count
        $executions = $line->fresh()->stageExecutions()->orderBy('id', 'asc')->pluck('checkpoint_code')->all();
        $this->assertSame(['NETTO_CUT', 'OD_TURNING', 'CNC_MACHINING', 'BOR_DRILLING'], $executions);
    }
}
