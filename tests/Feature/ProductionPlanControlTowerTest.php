<?php

namespace Tests\Feature;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingOrderLine;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductionPlanControlTowerTest extends TestCase
{
    use RefreshDatabase;

    protected User $ppicStainless;

    protected User $ppicCarbon;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $accessPlanning = Permission::findOrCreate('access_planning');
        $accessExecution = Permission::findOrCreate('access_execution');

        $ppicRole = Role::findOrCreate('ppic');
        $ppicRole->syncPermissions([$accessPlanning, $accessExecution]);

        $adminRole = Role::findOrCreate('admin');
        $adminRole->syncPermissions([$accessPlanning, $accessExecution]);

        $this->ppicStainless = User::factory()->create([
            'email' => 'ppic_flange_ss@peroniks.com',
            'product_scope' => 'FLANGE_STAINLESS',
        ]);
        $this->ppicStainless->assignRole('ppic');

        $this->ppicCarbon = User::factory()->create([
            'email' => 'ppic_flange_cs@peroniks.com',
            'product_scope' => 'FLANGE_CARBON',
        ]);
        $this->ppicCarbon->assignRole('ppic');

        $this->adminUser = User::factory()->create([
            'email' => 'admin_user@peroniks.com',
            'product_scope' => null,
        ]);
        $this->adminUser->assignRole('admin');
    }

    private function createPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => 'CODE-'.uniqid(),
            'title' => 'Batch Test',
            'item_code' => 'ITEM-01',
            'item_name' => 'Item Flange Test',
            'po_number' => 'PO-'.uniqid(),
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'status' => 'planning',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'product_scope' => 'FLANGE_STAINLESS',
            'created_at' => '2026-09-15 08:00:00',
        ], $attributes));
    }

    private function createSandCastingResult(ProductionPlan $plan, int $qtyGood, int $qtyReject = 0): SandCastingCastingResultLine
    {
        $pcor = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-TEST-'.uniqid(),
            'scheduled_date' => now()->toDateString(),
            'status' => 'COMPLETED',
            'created_by' => $this->ppicStainless->id,
        ]);

        $pcorLine = SandCastingCastingOrderLine::create([
            'sand_casting_casting_order_id' => $pcor->id,
            'production_plan_id' => $plan->id,
            'item_name' => $plan->item_name,
            'qty_ordered' => $plan->qty_planned,
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'HEAT-'.uniqid(),
            'cast_date' => now()->toDateString(),
            'recorded_by' => $this->ppicStainless->id,
        ]);

        return SandCastingCastingResultLine::create([
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $pcorLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'TRV-'.uniqid(),
            'qty_good' => $qtyGood,
            'qty_reject' => $qtyReject,
        ]);
    }

    private function createLostWaxPrintExecution(ProductionPlan $plan, int $qtyExecutedGood, string $orderStatus = 'COMPLETED'): LostWaxPrintOrderLine
    {
        $spk = LostWaxPrintOrder::create([
            'print_order_number' => 'PO-'.uniqid(),
            'scheduled_date' => now()->toDateString(),
            'status' => $orderStatus,
            'created_by' => $this->ppicStainless->id,
        ]);

        return LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $spk->id,
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'qty_ordered' => $plan->qty_planned,
            'qty_executed_good' => $qtyExecutedGood,
            'qty_executed_defect' => 0,
        ]);
    }

    /** 1. Sand Casting actual 0 => NOT STARTED */
    public function test_sand_casting_actual_0_is_not_started(): void
    {
        $plan = $this->createPlan([
            'code' => 'SC001',
            'title' => 'Batch SC 1',
            'qty_planned' => 100,
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
        ]);

        $response = $this->actingAs($this->ppicStainless)->get(route('plan.index'));
        $response->assertStatus(200);
        $response->assertSee('Not Started');
        $response->assertSee('/ 100 pcs');
    }

    /** 2. Sand Casting partial => IN PROGRESS */
    public function test_sand_casting_partial_execution_is_in_progress(): void
    {
        $plan = $this->createPlan([
            'code' => 'SC002',
            'title' => 'Batch SC 2',
            'qty_planned' => 100,
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
        ]);

        $this->createSandCastingResult($plan, 45, 5); // 45 good, 5 reject (reject should NOT count towards target)

        $response = $this->actingAs($this->ppicStainless)->get(route('plan.index'));
        $response->assertStatus(200);
        $response->assertSee('In Progress');
        $response->assertSee('45');
        $response->assertSee('/ 100 pcs');
        $response->assertSee('45%');
        $response->assertSee('Sisa target:');
    }

    /** 3. Sand Casting exact target => COMPLETED */
    public function test_sand_casting_exact_target_is_completed(): void
    {
        $plan = $this->createPlan([
            'code' => 'SC003',
            'title' => 'Batch SC 3',
            'qty_planned' => 100,
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
        ]);

        $this->createSandCastingResult($plan, 100, 10);

        $response = $this->actingAs($this->ppicStainless)->get(route('plan.index'));
        $response->assertStatus(200);
        $response->assertSee('Completed');
        $response->assertSee('100');
        $response->assertSee('/ 100 pcs');
        $response->assertSee('100%');
    }

    /** 4. Sand Casting over target => COMPLETED */
    public function test_sand_casting_over_target_is_completed(): void
    {
        $plan = $this->createPlan([
            'code' => 'SC004',
            'title' => 'Batch SC 4',
            'qty_planned' => 100,
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
        ]);

        $this->createSandCastingResult($plan, 115, 0);

        $response = $this->actingAs($this->ppicStainless)->get(route('plan.index'));
        $response->assertStatus(200);
        $response->assertSee('Completed');
        $response->assertSee('115');
        $response->assertSee('/ 100 pcs');
        $response->assertSee('115%');
        $response->assertSee('+15 pcs Over Target');
    }

    /** 5. Lost Wax actual 0 => NOT STARTED */
    public function test_lost_wax_actual_0_is_not_started(): void
    {
        $plan = $this->createPlan([
            'code' => 'LW001',
            'title' => 'Batch LW 1',
            'qty_planned' => 50,
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
        ]);

        $response = $this->actingAs($this->ppicStainless)->get(route('plan.index'));
        $response->assertStatus(200);
        $response->assertSee('Not Started');
        $response->assertSee('/ 50 pcs');
        $response->assertSee('Good Hasil Cetak');
    }

    /** 6. Lost Wax partial => IN PROGRESS */
    public function test_lost_wax_partial_is_in_progress(): void
    {
        $plan = $this->createPlan([
            'code' => 'LW002',
            'title' => 'Batch LW 2',
            'qty_planned' => 50,
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
        ]);

        $this->createLostWaxPrintExecution($plan, 25);

        $response = $this->actingAs($this->ppicStainless)->get(route('plan.index'));
        $response->assertStatus(200);
        $response->assertSee('In Progress');
        $response->assertSee('25');
        $response->assertSee('/ 50 pcs');
        $response->assertSee('50%');
    }

    /** 7. Lost Wax exact target => COMPLETED */
    public function test_lost_wax_exact_target_is_completed(): void
    {
        $plan = $this->createPlan([
            'code' => 'LW003',
            'title' => 'Batch LW 3',
            'qty_planned' => 50,
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
        ]);

        $this->createLostWaxPrintExecution($plan, 50);

        $response = $this->actingAs($this->ppicStainless)->get(route('plan.index'));
        $response->assertStatus(200);
        $response->assertSee('Completed');
        $response->assertSee('50');
        $response->assertSee('/ 50 pcs');
        $response->assertSee('100%');
    }

    /** 8. Lost Wax cancelled orders excluded */
    public function test_lost_wax_cancelled_orders_excluded(): void
    {
        $plan = $this->createPlan([
            'code' => 'LW004',
            'title' => 'Batch LW 4',
            'qty_planned' => 50,
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
        ]);

        // Cancelled print order line
        $this->createLostWaxPrintExecution($plan, 50, 'CANCELLED');

        $response = $this->actingAs($this->ppicStainless)->get(route('plan.index'));
        $response->assertStatus(200);
        // Actual execution must be 0 because the print order was cancelled
        $response->assertSee('Not Started');
        $response->assertSee('/ 50 pcs');
    }

    /** 9. Two plans on same date remain separate cards */
    public function test_two_plans_on_same_date_remain_separate_cards(): void
    {
        $this->createPlan([
            'code' => 'CODE-A01',
            'title' => 'A06 pasir',
            'qty_planned' => 100,
            'created_at' => '2026-09-15 08:00:00',
        ]);

        $this->createPlan([
            'code' => 'CODE-E01',
            'title' => 'E01 september',
            'qty_planned' => 200,
            'created_at' => '2026-09-15 09:00:00',
        ]);

        $response = $this->actingAs($this->ppicStainless)->get(route('plan.index'));
        $response->assertStatus(200);
        $response->assertSee('A06 pasir');
        $response->assertSee('E01 september');
        // Both card totals must appear independently
        $response->assertSee('/ 100 pcs');
        $response->assertSee('/ 200 pcs');
    }

    /** 10. Card detail opens exact planning group */
    public function test_card_detail_opens_exact_planning_group(): void
    {
        $planA = $this->createPlan([
            'code' => 'CODE-GRP-A',
            'title' => 'Group A Title',
            'qty_planned' => 100,
            'created_at' => '2026-09-15 08:00:00',
        ]);

        $planB = $this->createPlan([
            'code' => 'CODE-GRP-B',
            'title' => 'Group B Title',
            'qty_planned' => 200,
            'created_at' => '2026-09-15 08:00:00',
        ]);

        // Request detail specifically for Group A
        $response = $this->actingAs($this->ppicStainless)->get(route('plan.index', [
            'date' => '2026-09-15',
            'title' => 'Group A Title',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'product_scope' => 'FLANGE_STAINLESS',
        ]));

        $response->assertStatus(200);
        $response->assertSee('Group A Title');
        $response->assertSee('CODE-GRP-A');
        $response->assertDontSee('CODE-GRP-B');
    }

    /** 11. Scope authorization remains intact */
    public function test_scope_authorization_remains_intact(): void
    {
        // Stainless plan
        $this->createPlan([
            'code' => 'CODE-SS',
            'title' => 'Stainless Batch',
            'product_scope' => 'FLANGE_STAINLESS',
            'created_at' => '2026-09-15 08:00:00',
        ]);

        // Carbon plan
        $this->createPlan([
            'code' => 'CODE-CS',
            'title' => 'Carbon Batch',
            'product_scope' => 'FLANGE_CARBON',
            'created_at' => '2026-09-15 08:00:00',
        ]);

        // PPIC Stainless should only see Stainless batch
        $responseSS = $this->actingAs($this->ppicStainless)->get(route('plan.index'));
        $responseSS->assertStatus(200);
        $responseSS->assertSee('Stainless Batch');
        $responseSS->assertDontSee('Carbon Batch');

        // PPIC Carbon should only see Carbon batch
        $responseCS = $this->actingAs($this->ppicCarbon)->get(route('plan.index'));
        $responseCS->assertStatus(200);
        $responseCS->assertSee('Carbon Batch');
        $responseCS->assertDontSee('Stainless Batch');

        // Admin should see both batches
        $responseAdmin = $this->actingAs($this->adminUser)->get(route('plan.index'));
        $responseAdmin->assertStatus(200);
        $responseAdmin->assertSee('Stainless Batch');
        $responseAdmin->assertSee('Carbon Batch');
    }

    /** 12. actual > planned displays over-target semantics */
    public function test_actual_over_planned_displays_over_target_semantics(): void
    {
        $plan = $this->createPlan([
            'code' => 'OVER01',
            'title' => 'Over Target Batch',
            'qty_planned' => 100,
            'created_at' => '2026-09-15 08:00:00',
        ]);

        $this->createSandCastingResult($plan, 120);

        $response = $this->actingAs($this->ppicStainless)->get(route('plan.index'));
        $response->assertStatus(200);
        $response->assertSee('120');
        $response->assertSee('/ 100 pcs');
        $response->assertSee('120%');
        $response->assertSee('+20 pcs Over Target');
        $response->assertDontSee('Remaining = -20');
        $response->assertDontSee('Sisa: -20');
    }

    /** 13. legacy qty_remaining MUST NOT be treated as actual execution */
    public function test_legacy_qty_remaining_is_not_treated_as_actual_execution(): void
    {
        // Legacy plan with qty_remaining = 0, but NO actual execution records in casting/print tables
        $this->createPlan([
            'code' => 'LEGACY01',
            'title' => 'Legacy Plan No Execution Table',
            'qty_planned' => 500,
            'qty_remaining' => 0, // In legacy logic, this would falsely look "completed"
            'created_at' => '2026-09-15 08:00:00',
        ]);

        $response = $this->actingAs($this->ppicStainless)->get(route('plan.index'));
        $response->assertStatus(200);
        // Actual verified execution is 0 -> Status MUST be Not Started (0 / 500 pcs)
        $response->assertSee('Not Started');
        $response->assertSee('/ 500 pcs');
        $response->assertSee('0%');
    }

    /** 14. Bulk query efficiency (No N+1) */
    public function test_plan_index_executes_bulk_queries_without_n_plus_one(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $plan = $this->createPlan([
                'code' => "BULK{$i}",
                'title' => "Bulk Batch {$i}",
                'qty_planned' => 100,
                'production_domain' => $i % 2 === 0 ? ProductionPlan::DOMAIN_SAND_CASTING : ProductionPlan::DOMAIN_LOST_WAX,
                'created_at' => '2026-09-15 08:00:00',
            ]);

            if ($i % 2 === 0) {
                $this->createSandCastingResult($plan, 50);
            } else {
                $this->createLostWaxPrintExecution($plan, 30);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($this->ppicStainless)->get(route('plan.index'));
        $response->assertStatus(200);

        $queries = DB::getQueryLog();
        // 10 plans across 10 groups: The execution queries must be aggregated via whereIn (2 queries), not 1 per plan.
        $this->assertLessThan(20, count($queries), 'Query count must remain small and constant regardless of number of plan cards.');
    }
}
