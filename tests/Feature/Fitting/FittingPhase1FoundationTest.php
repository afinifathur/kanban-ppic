<?php

namespace Tests\Feature\Fitting;

use App\Models\DefectType;
use App\Models\FittingItem;
use App\Models\FittingItemAllocation;
use App\Models\FittingKitirDecision;
use App\Models\FittingStageExecution;
use App\Models\FittingStageExecutionDefect;
use App\Models\FittingStageExecutionDefectLog;
use App\Models\FittingTransfer;
use App\Models\FittingWipSnapshot;
use App\Models\LostWaxCastingResult;
use App\Models\LostWaxKitir;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\Fitting\FittingStageAuthorizationService;
use App\Services\SandCasting\SandCastingStageAuthorizationService;
use Database\Seeders\AuditorUserSeeder;
use Database\Seeders\FittingSpvUserSeeder;
use Database\Seeders\QcFittingUserSeeder;
use Database\Seeders\SalesVisibilityUserSeeder;
use Database\Seeders\SandCastingSpvUserSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FittingPhase1FoundationTest extends TestCase
{
    use RefreshDatabase;

    private FittingStageAuthorizationService $fittingAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fittingAuth = new FittingStageAuthorizationService;
    }

    protected function tearDown(): void
    {
        foreach (['FITTING_SPV_INITIAL_PASSWORD', 'AUDITOR_INITIAL_PASSWORD', 'SALES_INITIAL_PASSWORD'] as $envKey) {
            putenv("{$envKey}=");
            unset($_ENV[$envKey], $_SERVER[$envKey]);
        }
        parent::tearDown();
    }

    private function setEnvVar(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function createLostWaxKitirFixture(User $user, string $traveler = 'PFKTR-20261118-0001'): array
    {
        $result = LostWaxCastingResult::create([
            'heat_number' => 'H-PF-001',
            'cast_date' => '2026-11-18',
            'total_trees' => 2,
            'total_pcs' => 20,
            'status' => 'COMPLETED',
            'recorded_by' => $user->id,
        ]);

        $kitir = LostWaxKitir::create([
            'lost_wax_casting_result_id' => $result->id,
            'traveler_number' => $traveler,
            'production_code' => 'PF-CODE-01',
            'customer' => 'CUST-A',
            'item_name' => 'ELBOW 90 SS304',
            'tree_count' => 2,
            'quantity_pcs' => 20,
            'print_count' => 0,
            'issued_at' => now(),
            'issued_by' => $user->id,
        ]);

        return [$result, $kitir];
    }

    /**
     * 1. All 8 Fitting tables exist with required columns.
     */
    public function test_01_all_fitting_tables_and_key_columns_exist(): void
    {
        $expectedTables = [
            'fitting_transfers' => ['transfer_number', 'transfer_date', 'transferred_by', 'total_kitirs', 'total_pcs', 'total_weight_kg', 'status', 'idempotency_key'],
            'fitting_items' => ['fitting_transfer_id', 'lost_wax_kitir_id', 'active_kitir_id', 'lost_wax_casting_result_id', 'production_plan_id', 'traveler_number', 'heat_number', 'production_code', 'line_number', 'qty_transferred', 'unit_weight_kg', 'total_weight_kg', 'is_multi_allocation', 'current_stage', 'status'],
            'fitting_item_allocations' => ['fitting_item_id', 'production_plan_id', 'production_code', 'line_number', 'allocated_qty_transferred', 'unit_weight_kg', 'allocated_weight_kg'],
            'fitting_kitir_decisions' => ['lost_wax_kitir_id', 'active_kitir_id', 'lost_wax_casting_result_id', 'decision', 'reason', 'decided_by', 'decided_at', 'status', 'reopened_by', 'reopened_at'],
            'fitting_stage_executions' => ['fitting_item_id', 'stage', 'checkpoint_code', 'input_qty', 'defect_qty', 'good_qty', 'status', 'operator_id', 'physical_done_at', 'is_auto_nihil'],
            'fitting_stage_execution_defects' => ['fitting_stage_execution_id', 'defect_type_id', 'qty', 'notes'],
            'fitting_stage_execution_defect_logs' => ['fitting_stage_execution_id', 'added_qty', 'previous_total', 'new_total', 'user_id', 'is_system_action', 'idempotency_key'],
            'fitting_wip_snapshots' => ['snapshot_date', 'scheduled_cutoff_at', 'captured_at', 'stage', 'product_scope', 'qty_pcs', 'weight_kg', 'ktr_count', 'details'],
        ];

        foreach ($expectedTables as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "Table {$table} should exist.");
            foreach ($columns as $col) {
                $this->assertTrue(Schema::hasColumn($table, $col), "Column {$table}.{$col} should exist.");
            }
        }
    }

    /**
     * 2. Models, relationships, multi-allocation unassigned line, line normalization, decimal casts, and negative defect delta logs work cleanly.
     */
    public function test_02_models_relationships_casts_line_normalization_and_multi_allocation(): void
    {
        // Line normalization contract: strictly 1..4, anything else -> null (Unassigned)
        $this->assertSame(1, FittingItem::normalizeLineNumber(1));
        $this->assertSame(4, FittingItem::normalizeLineNumber('4'));
        $this->assertNull(FittingItem::normalizeLineNumber(0));
        $this->assertNull(FittingItem::normalizeLineNumber(5));
        $this->assertNull(FittingItem::normalizeLineNumber(-1));
        $this->assertNull(FittingItem::normalizeLineNumber(null));
        $this->assertNull(FittingItem::normalizeLineNumber(''));
        $this->assertNull(FittingItem::normalizeLineNumber('LINE-1'));

        $user = User::factory()->create();
        [$result, $kitir] = $this->createLostWaxKitirFixture($user);

        $planA = ProductionPlan::create([
            'code' => 'PF-CODE-A',
            'title' => 'Plan A',
            'customer' => 'CUST-A',
            'po_number' => 'PO-01',
            'line_number' => 1,
            'item_code' => 'ITM-A',
            'item_name' => 'ELBOW 1 INCH',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'weight' => 0.50,
            'product_scope' => 'FITTING_STAINLESS',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
        ]);

        $planB = ProductionPlan::create([
            'code' => 'PF-CODE-B',
            'title' => 'Plan B',
            'customer' => 'CUST-B',
            'po_number' => 'PO-02',
            'line_number' => 3,
            'item_code' => 'ITM-B',
            'item_name' => 'TEE 2 INCH',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'weight' => 0.30,
            'product_scope' => 'FITTING_STAINLESS',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
        ]);

        $transfer = FittingTransfer::create([
            'transfer_number' => 'PFTRF-20261118-0001',
            'transfer_date' => '2026-11-18',
            'transferred_by' => $user->id,
            'total_kitirs' => 1,
            'total_pcs' => 20,
            'total_weight_kg' => 8.40,
            'status' => FittingTransfer::STATUS_ACTIVE,
            'idempotency_key' => 'idem-trf-001',
        ]);

        $item = FittingItem::create([
            'fitting_transfer_id' => $transfer->id,
            'lost_wax_kitir_id' => $kitir->id,
            'active_kitir_id' => $kitir->id,
            'lost_wax_casting_result_id' => $result->id,
            'production_plan_id' => null,
            'traveler_number' => $kitir->traveler_number,
            'heat_number' => $result->heat_number,
            'cast_date' => $result->cast_date,
            'production_code' => 'PF-CODE-A, PF-CODE-B',
            'line_number' => null,
            'tree_count' => 2,
            'qty_transferred' => 20,
            'unit_weight_kg' => 0.4200,
            'total_weight_kg' => 8.40,
            'is_multi_allocation' => true,
            'current_stage' => FittingItem::STAGE_NETTO_PF,
            'status' => FittingItem::STATUS_ACTIVE,
        ]);

        FittingItemAllocation::create([
            'fitting_item_id' => $item->id,
            'production_plan_id' => $planA->id,
            'production_code' => 'PF-CODE-A',
            'line_number' => 1,
            'allocated_qty_transferred' => 12,
            'unit_weight_kg' => 0.5000,
            'allocated_weight_kg' => 6.00,
        ]);

        FittingItemAllocation::create([
            'fitting_item_id' => $item->id,
            'production_plan_id' => $planB->id,
            'production_code' => 'PF-CODE-B',
            'line_number' => 3,
            'allocated_qty_transferred' => 8,
            'unit_weight_kg' => 0.3000,
            'allocated_weight_kg' => 2.40,
        ]);

        $defectType = DefectType::create(['department' => 'kimia', 'name' => 'Noda Asam']);

        $exec = FittingStageExecution::create([
            'fitting_item_id' => $item->id,
            'stage' => FittingItem::STAGE_KIMIA,
            'checkpoint_code' => FittingStageExecution::CHECKPOINT_KIMIA,
            'input_qty' => 20,
            'defect_qty' => 2,
            'good_qty' => 18,
            'status' => FittingStageExecution::STATUS_WAITING_QC,
            'operator_id' => $user->id,
            'executed_at' => now(),
            'physical_done_at' => now(),
        ]);

        FittingStageExecutionDefect::create([
            'fitting_stage_execution_id' => $exec->id,
            'defect_type_id' => $defectType->id,
            'qty' => 2,
        ]);

        FittingStageExecutionDefectLog::create([
            'fitting_stage_execution_id' => $exec->id,
            'added_qty' => 3,
            'previous_total' => 0,
            'new_total' => 3,
            'user_id' => $user->id,
            'is_system_action' => false,
            'idempotency_key' => 'idem-def-01',
        ]);

        $negLog = FittingStageExecutionDefectLog::create([
            'fitting_stage_execution_id' => $exec->id,
            'added_qty' => -1,
            'previous_total' => 3,
            'new_total' => 2,
            'user_id' => null,
            'is_system_action' => true,
            'notes' => 'Koreksi negatif',
            'idempotency_key' => 'idem-def-02',
        ]);

        $snapshot = FittingWipSnapshot::create([
            'snapshot_date' => '2026-11-18',
            'scheduled_cutoff_at' => '2026-11-18 07:00:00',
            'captured_at' => '2026-11-18 07:00:05',
            'stage' => FittingItem::STAGE_NETTO_PF,
            'product_scope' => 'FITTING_STAINLESS',
            'qty_pcs' => 20,
            'weight_kg' => 8.4000,
            'ktr_count' => 1,
            'details' => ['unassigned_pcs' => 20],
        ]);

        $item->refresh();
        $this->assertFalse($item->is_line_assigned);
        $this->assertTrue($item->is_multi_allocation);
        $this->assertTrue($item->hasPhysicalExecution());
        $this->assertEquals('0.4200', $item->unit_weight_kg);
        $this->assertEquals('8.40', $item->total_weight_kg);
        $this->assertEquals(2, $item->allocations()->count());
        $this->assertEquals([1, 3], $item->allocations()->orderBy('line_number')->pluck('line_number')->all());
        $this->assertEquals($kitir->id, $item->lostWaxKitir->id);
        $this->assertEquals($result->id, $item->lostWaxCastingResult->id);
        $this->assertEquals($transfer->id, $item->transfer->id);
        $this->assertEquals(-1, $negLog->fresh()->added_qty);
        $this->assertTrue($negLog->fresh()->is_system_action);
        $this->assertEquals(['unassigned_pcs' => 20], $snapshot->fresh()->details);
    }

    /**
     * 3. Unique constraints (active_kitir_id, idempotency_key, checkpoint, wip snapshot) AND ON DELETE RESTRICT are enforced at DB level.
     */
    public function test_03_unique_constraints_idempotency_and_on_delete_restrict_are_enforced(): void
    {
        $user = User::factory()->create();
        [$result, $kitir] = $this->createLostWaxKitirFixture($user);

        $transfer = FittingTransfer::create([
            'transfer_number' => 'PFTRF-20261118-0010',
            'transfer_date' => '2026-11-18',
            'transferred_by' => $user->id,
            'idempotency_key' => 'trf-idem-unique-1',
        ]);

        // Duplicate transfer idempotency_key must fail
        $dupTransferIdemFailed = false;
        try {
            FittingTransfer::create([
                'transfer_number' => 'PFTRF-20261118-0011',
                'transfer_date' => '2026-11-18',
                'transferred_by' => $user->id,
                'idempotency_key' => 'trf-idem-unique-1',
            ]);
        } catch (QueryException $e) {
            $dupTransferIdemFailed = true;
        }
        $this->assertTrue($dupTransferIdemFailed, 'Duplicate fitting_transfers.idempotency_key must fail.');

        // Active transfer #1
        $item1 = FittingItem::create([
            'fitting_transfer_id' => $transfer->id,
            'lost_wax_kitir_id' => $kitir->id,
            'active_kitir_id' => $kitir->id,
            'lost_wax_casting_result_id' => $result->id,
            'traveler_number' => $kitir->traveler_number,
            'heat_number' => $result->heat_number,
            'production_code' => $kitir->production_code,
            'qty_transferred' => 20,
            'status' => FittingItem::STATUS_ACTIVE,
        ]);

        // ON DELETE RESTRICT: deleting LostWaxKitir or LostWaxCastingResult referenced by FittingItem MUST fail
        $deleteKitirFailed = false;
        try {
            $kitir->delete();
        } catch (QueryException $e) {
            $deleteKitirFailed = true;
        }
        $this->assertTrue($deleteKitirFailed, 'ON DELETE RESTRICT must reject deleting LostWaxKitir referenced by FittingItem.');

        $deleteResultFailed = false;
        try {
            $result->delete();
        } catch (QueryException $e) {
            $deleteResultFailed = true;
        }
        $this->assertTrue($deleteResultFailed, 'ON DELETE RESTRICT must reject deleting LostWaxCastingResult referenced by FittingItem.');

        // Duplicate active transfer of the same Kitir MUST fail
        $doubleTransferFailed = false;
        try {
            FittingItem::create([
                'fitting_transfer_id' => $transfer->id,
                'lost_wax_kitir_id' => $kitir->id,
                'active_kitir_id' => $kitir->id,
                'lost_wax_casting_result_id' => $result->id,
                'traveler_number' => $kitir->traveler_number,
                'heat_number' => $result->heat_number,
                'production_code' => $kitir->production_code,
                'qty_transferred' => 20,
                'status' => FittingItem::STATUS_ACTIVE,
            ]);
        } catch (QueryException $e) {
            $doubleTransferFailed = true;
        }
        $this->assertTrue($doubleTransferFailed, 'Duplicate active_kitir_id must fail unique constraint.');

        // Cancel transfer cleanly (clears active_kitir_id AND current_stage) -> re-transfer permitted
        $item1->update([
            'status' => FittingItem::STATUS_CANCELLED,
            'active_kitir_id' => null,
            'current_stage' => null,
            'cancelled_by' => $user->id,
            'cancelled_at' => now(),
            'cancel_reason' => 'Salah pilih',
        ]);
        $this->assertNull($item1->fresh()->current_stage);

        $item2 = FittingItem::create([
            'fitting_transfer_id' => $transfer->id,
            'lost_wax_kitir_id' => $kitir->id,
            'active_kitir_id' => $kitir->id,
            'lost_wax_casting_result_id' => $result->id,
            'traveler_number' => $kitir->traveler_number,
            'heat_number' => $result->heat_number,
            'production_code' => $kitir->production_code,
            'qty_transferred' => 20,
            'status' => FittingItem::STATUS_ACTIVE,
        ]);
        $this->assertNotEquals($item1->id, $item2->id);

        // NOT_CONTINUED decision unique constraint & nullable reason
        $decision = FittingKitirDecision::create([
            'lost_wax_kitir_id' => $kitir->id,
            'active_kitir_id' => $kitir->id,
            'lost_wax_casting_result_id' => $result->id,
            'decision' => FittingKitirDecision::DECISION_NOT_CONTINUED,
            'reason' => null,
            'decided_by' => $user->id,
            'decided_at' => now(),
            'status' => FittingKitirDecision::STATUS_ACTIVE,
        ]);
        $this->assertNull($decision->reason);

        $dupDecisionFailed = false;
        try {
            FittingKitirDecision::create([
                'lost_wax_kitir_id' => $kitir->id,
                'active_kitir_id' => $kitir->id,
                'lost_wax_casting_result_id' => $result->id,
                'decision' => FittingKitirDecision::DECISION_NOT_CONTINUED,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'status' => FittingKitirDecision::STATUS_ACTIVE,
            ]);
        } catch (QueryException $e) {
            $dupDecisionFailed = true;
        }
        $this->assertTrue($dupDecisionFailed, 'Duplicate active NOT_CONTINUED decision must fail.');

        // Duplicate checkpoint & duplicate defect log idempotency_key
        $exec = FittingStageExecution::create([
            'fitting_item_id' => $item2->id,
            'stage' => FittingItem::STAGE_NETTO_PF,
            'checkpoint_code' => FittingStageExecution::CHECKPOINT_NETTO,
            'input_qty' => 20,
            'good_qty' => 20,
            'status' => FittingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $user->id,
            'executed_at' => now(),
        ]);

        $dupExecFailed = false;
        try {
            FittingStageExecution::create([
                'fitting_item_id' => $item2->id,
                'stage' => FittingItem::STAGE_NETTO_PF,
                'checkpoint_code' => FittingStageExecution::CHECKPOINT_NETTO,
                'input_qty' => 20,
                'good_qty' => 20,
                'status' => FittingStageExecution::STATUS_WAITING_DEFECT,
                'operator_id' => $user->id,
                'executed_at' => now(),
            ]);
        } catch (QueryException $e) {
            $dupExecFailed = true;
        }
        $this->assertTrue($dupExecFailed, 'Duplicate (fitting_item_id, checkpoint_code) must fail.');

        FittingStageExecutionDefectLog::create([
            'fitting_stage_execution_id' => $exec->id,
            'added_qty' => 1,
            'previous_total' => 0,
            'new_total' => 1,
            'user_id' => $user->id,
            'idempotency_key' => 'def-log-idem-1',
        ]);

        $dupDefLogIdemFailed = false;
        try {
            FittingStageExecutionDefectLog::create([
                'fitting_stage_execution_id' => $exec->id,
                'added_qty' => 1,
                'previous_total' => 1,
                'new_total' => 2,
                'user_id' => $user->id,
                'idempotency_key' => 'def-log-idem-1',
            ]);
        } catch (QueryException $e) {
            $dupDefLogIdemFailed = true;
        }
        $this->assertTrue($dupDefLogIdemFailed, 'Duplicate defect log idempotency_key must fail.');

        // Duplicate WIP snapshot (date, stage, product_scope) must fail
        FittingWipSnapshot::create([
            'snapshot_date' => '2026-11-18',
            'scheduled_cutoff_at' => '2026-11-18 07:00:00',
            'captured_at' => '2026-11-18 07:00:05',
            'stage' => FittingItem::STAGE_KIMIA,
            'product_scope' => 'FITTING_STAINLESS',
        ]);

        $dupWipFailed = false;
        try {
            FittingWipSnapshot::create([
                'snapshot_date' => '2026-11-18',
                'scheduled_cutoff_at' => '2026-11-18 07:00:00',
                'captured_at' => '2026-11-18 07:00:10',
                'stage' => FittingItem::STAGE_KIMIA,
                'product_scope' => 'FITTING_STAINLESS',
            ]);
        } catch (QueryException $e) {
            $dupWipFailed = true;
        }
        $this->assertTrue($dupWipFailed, 'Duplicate fitting_wip_snapshots unique key must fail.');
    }

    /**
     * 4. Master Defect Fitting supports 6 Fitting departments, prevents case-insensitive trimmed duplicates on store & update,
     *    and preserves Sand Casting departments.
     */
    public function test_04_master_defect_supports_six_fitting_departments_and_blocks_duplicates(): void
    {
        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->givePermissionTo($permPlanning);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $scDefect = DefectType::create(['department' => 'netto', 'name' => 'Porosity']);

        $response = $this->actingAs($admin)->get(route('settings.defect-types.index'));
        $response->assertOk();
        $response->assertSee('Porosity');
        $response->assertSee('Fitting Production (6 Stage)');
        foreach (['netto_pf', 'kimia', 'marking', 'bubut_cnc_pf', 'qc_pf', 'gudang_jadi_pf'] as $dept) {
            $response->assertSee($dept);
        }

        // Same name allowed on DIFFERENT department (netto vs netto_pf)
        $this->actingAs($admin)->post(route('settings.defect-types.store'), [
            'department' => 'netto_pf',
            'name' => '  Porosity  ',
        ])->assertRedirect(route('settings.defect-types.index'));

        $this->assertDatabaseHas('defect_types', ['department' => 'netto_pf', 'name' => 'Porosity']);

        // Case-insensitive + whitespace duplicate on SAME department MUST be rejected on store
        $this->actingAs($admin)->post(route('settings.defect-types.store'), [
            'department' => 'netto_pf',
            'name' => '  pOrOsItY ',
        ])->assertSessionHasErrors('name');

        $this->assertEquals(1, DefectType::where('department', 'netto_pf')->count());

        // Duplicate on update within same department MUST be rejected
        $secondFittingDefect = DefectType::create(['department' => 'netto_pf', 'name' => 'Retak']);
        $this->actingAs($admin)->put(route('settings.defect-types.update', $secondFittingDefect), [
            'name' => '  POROSITY ',
        ])->assertSessionHasErrors('name');
        $this->assertEquals('Retak', $secondFittingDefect->fresh()->name);

        // Updating self with case change is allowed
        $this->actingAs($admin)->put(route('settings.defect-types.update', $secondFittingDefect), [
            'name' => '  Retak Halus ',
        ])->assertRedirect(route('settings.defect-types.index'));
        $this->assertEquals('Retak Halus', $secondFittingDefect->fresh()->name);

        // Unknown department rejected
        $this->actingAs($admin)->post(route('settings.defect-types.store'), [
            'department' => 'unknown_dept',
            'name' => 'Invalid',
        ])->assertSessionHasErrors('department');

        // Sand Casting defect untouched
        $this->assertEquals('Porosity', $scDefect->fresh()->name);
    }

    /**
     * 5. FittingSpvUserSeeder validates FITTING_SPV_INITIAL_PASSWORD BEFORE mutating DB (zero partial state),
     *    provisions 6 SPV accounts idempotently, preserves existing passwords, and restricts access_all_fitting_kanban to spvgdpf.
     */
    public function test_05_fitting_spv_seeder_atomic_env_guard_idempotency_and_password_preservation(): void
    {
        // A. Missing env var MUST fail BEFORE creating any Fitting permission or role (L2)
        putenv('FITTING_SPV_INITIAL_PASSWORD=');
        unset($_ENV['FITTING_SPV_INITIAL_PASSWORD'], $_SERVER['FITTING_SPV_INITIAL_PASSWORD']);

        $thrown = false;
        try {
            (new FittingSpvUserSeeder)->run();
        } catch (\RuntimeException $e) {
            $thrown = str_contains($e->getMessage(), 'FITTING_SPV_INITIAL_PASSWORD');
        }
        $this->assertTrue($thrown, 'Seeder must refuse to create accounts without FITTING_SPV_INITIAL_PASSWORD.');
        $this->assertNull(Role::where('name', FittingStageAuthorizationService::ROLE_SPV_FITTING)->first(), 'Zero partial role should be created on missing env.');
        $this->assertEquals(0, Permission::whereIn('name', FittingStageAuthorizationService::ALL_PERMISSIONS)->count(), 'Zero partial permissions should be created on missing env.');

        // B. Seed existing roles & accounts
        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);
        $permAud = Permission::firstOrCreate(['name' => 'access_auditor_visibility', 'guard_name' => 'web']);
        $permSales = Permission::firstOrCreate(['name' => 'access_production_visibility', 'guard_name' => 'web']);

        Role::firstOrCreate(['name' => 'ppic', 'guard_name' => 'web'])->syncPermissions([$permPlanning, $permExecution]);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'])->syncPermissions([$permPlanning, $permExecution]);
        Role::firstOrCreate(['name' => 'auditor', 'guard_name' => 'web'])->syncPermissions([$permAud]);
        Role::firstOrCreate(['name' => 'sales', 'guard_name' => 'web'])->syncPermissions([$permSales]);

        $this->seed(QcFittingUserSeeder::class);
        $this->seed(SandCastingSpvUserSeeder::class);

        $ppicFitting = User::create([
            'name' => 'PPIC Fitting',
            'email' => 'ppicfitting@peroniks.com',
            'password' => Hash::make('keep_ppic_pass'),
            'product_scope' => 'FITTING_STAINLESS',
        ]);
        $ppicFitting->assignRole('ppic');

        $adminPpicPf = User::create([
            'name' => 'Admin PPIC',
            'email' => 'adminppicpf@peroniks.com',
            'password' => Hash::make('keep_adminppic_pass'),
            'product_scope' => null,
        ]);
        $adminPpicPf->assignRole('admin');

        $existingQcHash = User::where('email', 'adminqcfitting@peroniks.com')->firstOrFail()->password;
        $existingPpicHash = $ppicFitting->password;
        $existingAdminPpicHash = $adminPpicPf->password;

        // C. Seed with secure initial password
        $this->setEnvVar('FITTING_SPV_INITIAL_PASSWORD', 'SecureFitPass2026!');
        (new FittingSpvUserSeeder)->run();

        $expectedMap = [
            'spvnettopf@peroniks.com' => 'netto_pf',
            'spvkimiapf@peroniks.com' => 'kimia',
            'spvmarkingpf@peroniks.com' => 'marking',
            'spvcncpf@peroniks.com' => 'bubut_cnc_pf',
            'spvqcpf@peroniks.com' => 'qc_pf',
            'spvgdpf@peroniks.com' => 'gudang_jadi_pf',
        ];

        foreach ($expectedMap as $email => $stage) {
            $u = User::where('email', $email)->first();
            $this->assertNotNull($u);
            $this->assertEquals($stage, $u->assigned_stage);
            $this->assertEquals('FITTING_STAINLESS', $u->product_scope);
            $this->assertTrue($u->hasRole('spv_fitting'));
            $this->assertFalse($u->hasRole('spv'));
            $this->assertTrue($u->hasPermissionTo(FittingStageAuthorizationService::PERMISSION_EXECUTION));
            $this->assertTrue(Hash::check('SecureFitPass2026!', $u->password));

            if ($email === 'spvgdpf@peroniks.com') {
                $this->assertTrue($u->hasPermissionTo(FittingStageAuthorizationService::PERMISSION_ALL_KANBAN));
            } else {
                $this->assertFalse($u->hasPermissionTo(FittingStageAuthorizationService::PERMISSION_ALL_KANBAN));
            }
        }

        // D. Re-run seeder WITHOUT env var -> idempotent & preserves custom passwords
        $spvNettoPf = User::where('email', 'spvnettopf@peroniks.com')->firstOrFail();
        $customSpvHash = Hash::make('CustomSpvPass999!');
        $spvNettoPf->update(['password' => $customSpvHash]);

        putenv('FITTING_SPV_INITIAL_PASSWORD=');
        unset($_ENV['FITTING_SPV_INITIAL_PASSWORD'], $_SERVER['FITTING_SPV_INITIAL_PASSWORD']);

        (new FittingSpvUserSeeder)->run();
        (new FittingSpvUserSeeder)->run();

        $this->assertEquals(1, User::where('email', 'spvnettopf@peroniks.com')->count());
        $this->assertEquals($customSpvHash, $spvNettoPf->fresh()->password);
        $this->assertEquals($existingPpicHash, $ppicFitting->fresh()->password);
        $this->assertEquals($existingAdminPpicHash, $adminPpicPf->fresh()->password);
        $this->assertEquals($existingQcHash, User::where('email', 'adminqcfitting@peroniks.com')->firstOrFail()->password);
    }

    /**
     * 6. H1: Reverse seeder order (FittingSpvUserSeeder FIRST, then QcFittingUserSeeder / AuditorUserSeeder / SalesVisibilityUserSeeder)
     *    MUST preserve all Fitting permissions without wiping them out.
     */
    public function test_06_reverse_seeder_execution_order_preserves_fitting_permissions(): void
    {
        $this->setEnvVar('AUDITOR_INITIAL_PASSWORD', 'AudPass123!');
        $this->setEnvVar('SALES_INITIAL_PASSWORD', 'SalesPass123!');
        $this->setEnvVar('FITTING_SPV_INITIAL_PASSWORD', 'FitPass123!');

        // Initial seed of existing accounts
        (new QcFittingUserSeeder)->run();
        (new AuditorUserSeeder)->run();
        (new SalesVisibilityUserSeeder)->run();

        // Run FittingSpvUserSeeder
        (new FittingSpvUserSeeder)->run();

        // Now re-run legacy seeders AFTER FittingSpvUserSeeder!
        (new QcFittingUserSeeder)->run();
        (new AuditorUserSeeder)->run();
        (new SalesVisibilityUserSeeder)->run();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Verify Fitting permissions survived syncPermissions() in all 3 seeders!
        $qcRole = Role::findByName('admin_qc_fitting', 'web');
        $this->assertTrue($qcRole->hasPermissionTo('access_planning'));
        $this->assertTrue($qcRole->hasPermissionTo('access_execution'));
        $this->assertTrue($qcRole->hasPermissionTo(FittingStageAuthorizationService::PERMISSION_VERIFY_QC));
        $this->assertTrue($qcRole->hasPermissionTo(FittingStageAuthorizationService::PERMISSION_ALL_KANBAN));
        $this->assertTrue($qcRole->hasPermissionTo(FittingStageAuthorizationService::PERMISSION_VISIBILITY));

        $auditorRole = Role::findByName('auditor', 'web');
        $this->assertTrue($auditorRole->hasPermissionTo('access_auditor_visibility'));
        $this->assertTrue($auditorRole->hasPermissionTo(FittingStageAuthorizationService::PERMISSION_VISIBILITY));
        $this->assertFalse($auditorRole->hasPermissionTo('access_planning'));
        $this->assertFalse($auditorRole->hasPermissionTo('access_execution'));

        $salesRole = Role::findByName('sales', 'web');
        $this->assertTrue($salesRole->hasPermissionTo('access_production_visibility'));
        $this->assertTrue($salesRole->hasPermissionTo(FittingStageAuthorizationService::PERMISSION_VISIBILITY));
        $this->assertFalse($salesRole->hasPermissionTo('access_planning'));
        $this->assertFalse($salesRole->hasPermissionTo('access_execution'));
    }

    /**
     * 7. H2: adminppicpf@peroniks.com (matching real local DB role 'admin') receives 'admin_ppic_fitting',
     *    has ZERO Super Admin bypass in Fitting (cannot reorder Kanban, scan SPV stage, or verify QC),
     *    while keeping its existing 'admin' access for Lost Wax / Sand Casting, and pure system 'admin' retains full access.
     */
    public function test_07_adminppicpf_has_no_fitting_super_admin_bypass_while_system_admin_retains_full_access(): void
    {
        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'])->syncPermissions([$permPlanning, $permExecution]);

        // Pure system administrator (e.g. mr@peroniks.com)
        $systemAdmin = User::create([
            'name' => 'MR Peroniks',
            'email' => 'mr@peroniks.com',
            'password' => Hash::make('password'),
        ]);
        $systemAdmin->assignRole('admin');

        // Exact mirror of local DB ID:3 (adminppicpf@peroniks.com has existing role 'admin')
        $adminPpicPf = User::create([
            'name' => 'Admin PPIC',
            'email' => 'adminppicpf@peroniks.com',
            'password' => Hash::make('password'),
        ]);
        $adminPpicPf->assignRole('admin');

        $this->setEnvVar('FITTING_SPV_INITIAL_PASSWORD', 'FitSecret123!');
        (new FittingSpvUserSeeder)->run();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $adminPpicPf->refresh();
        $systemAdmin->refresh();

        // Existing roles & permissions for Sand Casting / Lost Wax / Planning are intact!
        $this->assertTrue($adminPpicPf->hasRole('admin'));
        $this->assertTrue($adminPpicPf->hasRole(FittingStageAuthorizationService::ROLE_ADMIN_PPIC_FITTING));
        $this->assertTrue($adminPpicPf->hasPermissionTo('access_planning'));
        $this->assertTrue($adminPpicPf->hasPermissionTo('access_execution'));

        // Strictly NOT a Fitting System Admin
        $this->assertFalse(FittingStageAuthorizationService::isFittingSystemAdmin($adminPpicPf));
        $this->assertTrue(FittingStageAuthorizationService::isFittingSystemAdmin($systemAdmin));

        // Allowed in Fitting: Full Kanban visibility, Transfer Kitir, Defect Recording
        $this->assertTrue(FittingStageAuthorizationService::hasFullKanbanVisibility($adminPpicPf));
        $this->assertTrue($this->fittingAuth->canTransferKitir($adminPpicPf));
        $this->assertTrue($this->fittingAuth->canRecordDefect($adminPpicPf));
        $this->assertTrue($adminPpicPf->hasPermissionTo(FittingStageAuthorizationService::PERMISSION_TRANSFER));
        $this->assertTrue($adminPpicPf->hasPermissionTo(FittingStageAuthorizationService::PERMISSION_RECORD_DEFECT));

        // Strictly DENIED in Fitting (even via Spatie hasPermissionTo!): Reorder Kanban, SPV Scan, QC Verify
        $this->assertFalse($this->fittingAuth->canReorderKanban($adminPpicPf));
        $this->assertFalse($this->fittingAuth->canExecuteStage($adminPpicPf, 'netto_pf'));
        $this->assertFalse($this->fittingAuth->canVerifyQc($adminPpicPf));
        $this->assertFalse($adminPpicPf->hasPermissionTo(FittingStageAuthorizationService::PERMISSION_MANAGE_KANBAN));
        $this->assertFalse($adminPpicPf->hasPermissionTo(FittingStageAuthorizationService::PERMISSION_EXECUTION));
        $this->assertFalse($adminPpicPf->hasPermissionTo(FittingStageAuthorizationService::PERMISSION_VERIFY_QC));

        // Pure system admin retains full access across ALL Fitting capabilities
        $this->assertTrue($this->fittingAuth->canTransferKitir($systemAdmin));
        $this->assertTrue($this->fittingAuth->canReorderKanban($systemAdmin));
        $this->assertTrue($this->fittingAuth->canRecordDefect($systemAdmin));
        $this->assertTrue($this->fittingAuth->canVerifyQc($systemAdmin));
        $this->assertTrue($this->fittingAuth->canExecuteStage($systemAdmin, 'netto_pf'));
        $this->assertTrue(FittingStageAuthorizationService::canViewDashboard($systemAdmin));
    }

    /**
     * 8. M2, M3 & M5: Strict Spatie-based RBAC, authorize*() exceptions, assigned_stage null/invalid,
     *    SPV denied from Dashboard, Sand Casting isolation, AND immediate revocation proof (ZERO hardcoded email bypass).
     */
    public function test_08_strict_spatie_authorization_exceptions_and_revocation_without_email_bypass(): void
    {
        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'])->syncPermissions([$permPlanning, $permExecution]);
        Role::firstOrCreate(['name' => 'ppic', 'guard_name' => 'web'])->syncPermissions([$permPlanning, $permExecution]);

        $this->setEnvVar('AUDITOR_INITIAL_PASSWORD', 'AudPass123!');
        $this->setEnvVar('SALES_INITIAL_PASSWORD', 'SalesPass123!');
        $this->setEnvVar('FITTING_SPV_INITIAL_PASSWORD', 'FitSecret123!');

        (new QcFittingUserSeeder)->run();
        (new AuditorUserSeeder)->run();
        (new SalesVisibilityUserSeeder)->run();
        (new SandCastingSpvUserSeeder)->run();

        $ppicFitting = User::factory()->create(['email' => 'ppicfitting@peroniks.com', 'product_scope' => 'FITTING_STAINLESS']);
        $ppicFitting->assignRole('ppic');

        (new FittingSpvUserSeeder)->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $adminQcFitting = User::where('email', 'adminqcfitting@peroniks.com')->firstOrFail();
        $auditor = User::where('email', 'auditor@peroniks.com')->firstOrFail();
        $sales = User::where('email', 'sales@peroniks.com')->firstOrFail();
        $spvNettoPf = User::where('email', 'spvnettopf@peroniks.com')->firstOrFail();
        $spvGdPf = User::where('email', 'spvgdpf@peroniks.com')->firstOrFail();
        $spvNettoFl = User::where('email', 'spvnettofl@peroniks.com')->firstOrFail();
        $spvGdFl = User::where('email', 'spvgdfl@peroniks.com')->firstOrFail();

        // A. Invalid stage throws InvalidArgumentException directly
        $invalidStageThrown = false;
        try {
            $this->fittingAuth->authorizeStageExecution($spvNettoPf, 'invalid_stage');
        } catch (InvalidArgumentException $e) {
            $invalidStageThrown = true;
        }
        $this->assertTrue($invalidStageThrown, 'authorizeStageExecution must throw InvalidArgumentException for invalid stage.');

        // B. SPV Fitting with assigned_stage = null throws AuthorizationException
        $spvNoStage = User::factory()->create(['assigned_stage' => null, 'product_scope' => 'FITTING_STAINLESS']);
        $spvNoStage->assignRole('spv_fitting');

        $nullStageThrown = false;
        try {
            $this->fittingAuth->authorizeStageExecution($spvNoStage, 'netto_pf');
        } catch (AuthorizationException $e) {
            $nullStageThrown = str_contains($e->getMessage(), 'assigned_stage');
        }
        $this->assertTrue($nullStageThrown, 'SPV Fitting with null assigned_stage must throw AuthorizationException.');

        // C. SPV Fitting with Sand Casting stage ('netto') throws AuthorizationException
        $spvBadStage = User::factory()->create(['assigned_stage' => 'netto', 'product_scope' => 'FITTING_STAINLESS']);
        $spvBadStage->assignRole('spv_fitting');

        $badStageThrown = false;
        try {
            $this->fittingAuth->authorizeStageExecution($spvBadStage, 'netto_pf');
        } catch (AuthorizationException $e) {
            $badStageThrown = str_contains($e->getMessage(), 'tidak valid');
        }
        $this->assertTrue($badStageThrown, 'SPV Fitting with invalid assigned_stage must throw AuthorizationException.');

        // D. M2: ALL spv_fitting (both single-stage & spvgdpf) DENIED from Dashboard & Reports
        $this->assertFalse(FittingStageAuthorizationService::canViewDashboard($spvNettoPf));
        $this->assertFalse(FittingStageAuthorizationService::canViewDashboard($spvGdPf));
        $this->assertFalse(FittingStageAuthorizationService::canViewReports($spvNettoPf));
        $this->assertFalse(FittingStageAuthorizationService::canViewReports($spvGdPf));
        $this->assertFalse(FittingStageAuthorizationService::canViewProductionStatus($spvNettoPf));
        $this->assertFalse(FittingStageAuthorizationService::canViewProductionStatus($spvGdPf));

        // E. spvgdpf views ALL 6 Fitting Kanbans, executes ONLY gudang_jadi_pf
        $this->assertTrue(FittingStageAuthorizationService::hasFullKanbanVisibility($spvGdPf));
        foreach (FittingStageAuthorizationService::VALID_STAGES as $stage) {
            $this->assertTrue($this->fittingAuth->canViewStageKanban($spvGdPf, $stage));
        }
        $this->assertTrue($this->fittingAuth->canExecuteStage($spvGdPf, 'gudang_jadi_pf'));
        $this->assertFalse($this->fittingAuth->canExecuteStage($spvGdPf, 'netto_pf'));

        // F. Auditor & Sales strictly read-only
        $this->assertTrue(FittingStageAuthorizationService::canViewDashboard($auditor));
        $this->assertTrue(FittingStageAuthorizationService::canViewProductionStatus($auditor));
        $this->assertTrue(FittingStageAuthorizationService::canViewReports($auditor));
        $this->assertTrue(FittingStageAuthorizationService::canViewProductionStatus($sales));
        $this->assertFalse(FittingStageAuthorizationService::canViewDashboard($sales));
        $this->assertFalse(FittingStageAuthorizationService::canViewReports($sales));

        foreach ([$auditor, $sales] as $roUser) {
            $this->expectAuthorizationException(fn () => $this->fittingAuth->authorizeTransferKitir($roUser));
            $this->expectAuthorizationException(fn () => $this->fittingAuth->authorizeReorderKanban($roUser));
            $this->expectAuthorizationException(fn () => $this->fittingAuth->authorizeRecordDefect($roUser));
            $this->expectAuthorizationException(fn () => $this->fittingAuth->authorizeVerifyQc($roUser));
            $this->expectAuthorizationException(fn () => $this->fittingAuth->authorizeStageExecution($roUser, 'netto_pf'));
        }

        // G. Sand Casting SPVs isolated from Fitting & Fitting SPVs isolated from Sand Casting
        $this->assertFalse(FittingStageAuthorizationService::hasFullKanbanVisibility($spvNettoFl));
        $this->assertFalse(FittingStageAuthorizationService::hasFullKanbanVisibility($spvGdFl));
        $this->expectAuthorizationException(fn () => $this->fittingAuth->authorizeKanbanView($spvNettoFl, 'netto_pf'));
        $this->expectAuthorizationException(fn () => $this->fittingAuth->authorizeKanbanView($spvGdFl, 'gudang_jadi_pf'));

        $scAuth = new SandCastingStageAuthorizationService;
        $this->assertFalse($scAuth->canAccessStage($spvNettoPf, 'netto'));
        $this->assertFalse($scAuth->canViewStageKanban($spvGdPf, 'netto'));

        // H. M3: TRUE PERMISSION REVOCATION (proves ZERO hardcoded email bypass!)
        // 1. Revoke manage_fitting_kanban from ppicfitting@peroniks.com -> immediately DENIED!
        $this->assertTrue($this->fittingAuth->canReorderKanban($ppicFitting));
        $ppicFitting->revokePermissionTo(FittingStageAuthorizationService::PERMISSION_MANAGE_KANBAN);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($this->fittingAuth->canReorderKanban($ppicFitting->fresh()));
        $this->expectAuthorizationException(fn () => $this->fittingAuth->authorizeReorderKanban($ppicFitting->fresh()));

        // 2. Revoke access_fitting_execution from spv_fitting role -> spvnettopf immediately DENIED!
        $this->assertTrue($this->fittingAuth->canExecuteStage($spvNettoPf, 'netto_pf'));
        Role::findByName('spv_fitting', 'web')->revokePermissionTo(FittingStageAuthorizationService::PERMISSION_EXECUTION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($this->fittingAuth->canExecuteStage($spvNettoPf->fresh(), 'netto_pf'));
        $this->expectAuthorizationException(fn () => $this->fittingAuth->authorizeStageExecution($spvNettoPf->fresh(), 'netto_pf'));

        // 3. Revoke access_all_fitting_kanban from spvgdpf@peroniks.com -> immediately DENIED full Kanban!
        $spvGdPf->revokePermissionTo(FittingStageAuthorizationService::PERMISSION_ALL_KANBAN);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse(FittingStageAuthorizationService::hasFullKanbanVisibility($spvGdPf->fresh()));

        // 4. Revoke verify_fitting_qc from admin_qc_fitting role -> adminqcfitting immediately DENIED!
        $this->assertTrue($this->fittingAuth->canVerifyQc($adminQcFitting));
        Role::findByName('admin_qc_fitting', 'web')->revokePermissionTo(FittingStageAuthorizationService::PERMISSION_VERIFY_QC);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($this->fittingAuth->canVerifyQc($adminQcFitting->fresh()));
        $this->expectAuthorizationException(fn () => $this->fittingAuth->authorizeVerifyQc($adminQcFitting->fresh()));
    }

    /**
     * 9. M1 & M5: User operational identity & sidebar rendering for SPV Fitting, SPV Gudang Jadi PF,
     *    SPV Flange, Auditor, Sales, and Admin (no empty Fitting Production header/dropdown when routes do not exist).
     */
    public function test_09_user_identity_and_sidebar_rendering_hide_empty_fitting_menus_in_phase_1(): void
    {
        $this->setEnvVar('AUDITOR_INITIAL_PASSWORD', 'AudPass123!');
        $this->setEnvVar('SALES_INITIAL_PASSWORD', 'SalesPass123!');
        $this->setEnvVar('FITTING_SPV_INITIAL_PASSWORD', 'FitSecret123!');

        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'])->syncPermissions([$permPlanning, $permExecution]);

        (new SandCastingSpvUserSeeder)->run();
        (new AuditorUserSeeder)->run();
        (new SalesVisibilityUserSeeder)->run();
        (new FittingSpvUserSeeder)->run();

        $admin = User::factory()->create(['email' => 'mr@peroniks.com']);
        $admin->assignRole('admin');

        $spvNettoPf = User::where('email', 'spvnettopf@peroniks.com')->firstOrFail();
        $spvGdPf = User::where('email', 'spvgdpf@peroniks.com')->firstOrFail();
        $spvNettoFl = User::where('email', 'spvnettofl@peroniks.com')->firstOrFail();
        $auditor = User::where('email', 'auditor@peroniks.com')->firstOrFail();
        $sales = User::where('email', 'sales@peroniks.com')->firstOrFail();

        // Identities
        $this->assertEquals('SPV NETTO PF', $spvNettoPf->getOperationalTitle());
        $this->assertEquals('FITTING', $spvNettoPf->getOperationalSubtitle());
        $this->assertEquals('SPV GUDANG JADI PF', $spvGdPf->getOperationalTitle());
        $this->assertEquals('FITTING', $spvGdPf->getOperationalSubtitle());
        $this->assertEquals('SPV NETTO', $spvNettoFl->getOperationalTitle());
        $this->assertEquals('FLANGE', $spvNettoFl->getOperationalSubtitle());

        // Single-stage SPV Fitting sidebar: Operasional Fitting + clickable Kanban Floor & Scanner links for assigned stage
        $respNettoPf = $this->actingAs($spvNettoPf)->get('/dashboard');
        $respNettoPf->assertOk();
        $respNettoPf->assertSee('Operasional Fitting');
        $respNettoPf->assertSee(route('fitting.kanban.show', 'netto-pf'));
        $respNettoPf->assertSee(route('fitting.scan.stage', 'netto-pf'));
        $respNettoPf->assertDontSee(route('sand-casting.scan.stage', 'netto'));

        // SPV Gudang Jadi PF sidebar: Operasional Fitting + all-stage Kanban link & GUDANG JADI PF Scanner link
        $respGdPf = $this->actingAs($spvGdPf)->get('/dashboard');
        $respGdPf->assertOk();
        $respGdPf->assertSee('Operasional Fitting');
        $respGdPf->assertSee(route('fitting.kanban.index'));
        $respGdPf->assertSee(route('fitting.scan.stage', 'gudang-jadi-pf'));

        // SPV Flange sidebar: UNCHANGED
        $respFlange = $this->actingAs($spvNettoFl)->get('/sand-casting/kanban/stage/netto');
        $respFlange->assertOk();
        $respFlange->assertSee('FLANGE');
        $respFlange->assertSee(route('sand-casting.scan.stage', 'netto'));
        $respFlange->assertDontSee('Operasional Fitting');

        // Phase 3: Auditor & Sales see Fitting Production -> Kanban Floor (read-only) with NO Phase 4-6 placeholders;
        // System Admin sees Fitting Production dropdown with Transfer Kitir & Kanban Floor only (no Phase 4-6 placeholders).
        $respAuditor = $this->actingAs($auditor)->get('/sand-casting/dashboard');
        $respAuditor->assertOk();
        $respAuditor->assertSee('Fitting Production');
        $respAuditor->assertSee(route('fitting.kanban.index'));
        $respAuditor->assertDontSee('Dashboard Fitting');
        $respAuditor->assertDontSee('Transfer Kitir');

        $respSales = $this->actingAs($sales)->get('/sand-casting/production-status');
        $respSales->assertOk();
        $respSales->assertSee('Fitting Production');
        $respSales->assertSee(route('fitting.kanban.index'));
        $respSales->assertDontSee('Transfer Kitir');

        $respAdmin = $this->actingAs($admin)->get('/dashboard');
        $respAdmin->assertOk();
        $respAdmin->assertSee('id="fittingMenu"', false);
        $respAdmin->assertSee('Transfer Kitir');
        $respAdmin->assertSee('Kanban Floor');
        $respAdmin->assertDontSee('Fitting Dashboard');
    }

    private function expectAuthorizationException(callable $callback): void
    {
        $thrown = false;
        try {
            $callback();
        } catch (AuthorizationException $e) {
            $thrown = true;
        }
        $this->assertTrue($thrown, 'Expected AuthorizationException was not thrown.');
    }
}
