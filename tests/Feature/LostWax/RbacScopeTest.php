<?php

namespace Tests\Feature\LostWax;

use App\Models\ProductionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $ppicFlange;

    private User $ppicFlangeBesi;

    private User $ppicFitting;

    private User $adminUser;

    private User $spvUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up roles and permissions
        $accessPlanning = \Spatie\Permission\Models\Permission::findOrCreate('access_planning');
        $accessExecution = \Spatie\Permission\Models\Permission::findOrCreate('access_execution');

        $adminRole = \Spatie\Permission\Models\Role::findOrCreate('admin');
        $ppicRole = \Spatie\Permission\Models\Role::findOrCreate('ppic');
        $spvRole = \Spatie\Permission\Models\Role::findOrCreate('spv');

        $adminRole->givePermissionTo([$accessPlanning, $accessExecution]);
        $ppicRole->givePermissionTo([$accessPlanning, $accessExecution]);
        $spvRole->givePermissionTo([$accessExecution]);

        // Create the test users
        $this->ppicFlange = User::factory()->create([
            'email' => 'ppicflange@peroniks.com',
            'product_scope' => 'FLANGE_STAINLESS',
        ]);
        $this->ppicFlange->assignRole('ppic');

        $this->ppicFlangeBesi = User::factory()->create([
            'email' => 'ppicflangebesi@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->ppicFlangeBesi->assignRole('ppic');

        $this->ppicFitting = User::factory()->create([
            'email' => 'ppicfitting@peroniks.com',
            'product_scope' => 'FITTING_STAINLESS',
        ]);
        $this->ppicFitting->assignRole('ppic');

        $this->adminUser = User::factory()->create([
            'email' => 'adminfitting@peroniks.com',
            'product_scope' => null,
        ]);
        $this->adminUser->assignRole('admin');

        $this->spvUser = User::factory()->create([
            'email' => 'spvlapisan@peroniks.com',
            'product_scope' => null,
        ]);
        $this->spvUser->assignRole('spv');
    }

    /**
     * Test role-based menu access restriction for SPV.
     */
    public function test_spv_blocked_from_planning_but_allowed_on_execution(): void
    {
        // Blocked from Planning index
        $response = $this->actingAs($this->spvUser)
            ->get(route('plan.index'));
        $response->assertStatus(403);

        // Blocked from Print Orders plans list
        $response = $this->actingAs($this->spvUser)
            ->get(route('lost-wax.print-orders.plans'));
        $response->assertStatus(403);

        // Allowed on Scan
        $response = $this->actingAs($this->spvUser)
            ->get(route('lost-wax.scan.index'));
        $response->assertOk();

        // Allowed on Scan Oven
        $response = $this->actingAs($this->spvUser)
            ->get(route('lost-wax.scan-oven.index'));
        $response->assertOk();

        // Allowed on Dashboard
        $response = $this->actingAs($this->spvUser)
            ->get(route('lost-wax.dashboard'));
        $response->assertOk();
    }

    /**
     * Test PPIC product scoping filters data properly on lists.
     */
    public function test_ppic_scope_filtering_on_plans(): void
    {
        // Create plans of different scopes
        $planStainless = ProductionPlan::create([
            'code' => 'ST-001',
            'customer' => 'Cust A',
            'item_code' => 'LY026',
            'item_name' => 'SS304 Flange',
            'product_scope' => 'FLANGE_STAINLESS',
            'aisi' => '304',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'status' => 'planning',
            'line_number' => 1,
            'po_number' => 'PO-ST-01',
        ]);

        $planBesi = ProductionPlan::create([
            'code' => 'FE-001',
            'customer' => 'Cust B',
            'item_code' => 'LY027',
            'item_name' => 'Besi Flange',
            'product_scope' => 'FLANGE_BESI',
            'aisi' => 'FE',
            'qty_planned' => 200,
            'qty_remaining' => 200,
            'status' => 'planning',
            'line_number' => 2,
            'po_number' => 'PO-FE-01',
        ]);

        $date = now()->toDateString();

        // PPIC Flange (FLANGE_STAINLESS) should see ST-001 but not FE-001
        $response = $this->actingAs($this->ppicFlange)
            ->get(route('plan.index', ['date' => $date]));
        $response->assertOk();
        $response->assertSee('ST-001');
        $response->assertDontSee('FE-001');

        // PPIC Flange Besi (FLANGE_BESI) should see FE-001 but not ST-001
        $response = $this->actingAs($this->ppicFlangeBesi)
            ->get(route('plan.index', ['date' => $date]));
        $response->assertOk();
        $response->assertSee('FE-001');
        $response->assertDontSee('ST-001');

        // Admin should see both
        $response = $this->actingAs($this->adminUser)
            ->get(route('plan.index', ['date' => $date]));
        $response->assertOk();
        $response->assertSee('ST-001');
        $response->assertSee('FE-001');
    }

    /**
     * Test PPIC cannot tamper with other scopes' records.
     */
    public function test_ppic_blocked_from_other_scopes_plans(): void
    {
        $planBesi = ProductionPlan::create([
            'code' => 'FE-001',
            'customer' => 'Cust B',
            'item_code' => 'LY027',
            'item_name' => 'Besi Flange',
            'product_scope' => 'FLANGE_BESI',
            'aisi' => 'FE',
            'qty_planned' => 200,
            'qty_remaining' => 200,
            'status' => 'planning',
            'line_number' => 2,
            'po_number' => 'PO-FE-01',
        ]);

        // PPIC Flange attempts to edit/update FE-001
        $response = $this->actingAs($this->ppicFlange)
            ->get(route('plan.edit', $planBesi));
        $response->assertStatus(403);

        $response = $this->actingAs($this->ppicFlange)
            ->put(route('plan.update', $planBesi), [
                'code' => 'FE-001-MOD',
                'qty_planned' => 300,
            ]);
        $response->assertStatus(403);
    }

    /**
     * Test role-based planning close/bulk-close access restriction.
     */
    public function test_planning_close_authorization_and_product_scope_safety(): void
    {
        // 1. Spv user (who does not have access_planning permission) is blocked from closing plan
        $plan = ProductionPlan::create([
            'code' => 'SS-001',
            'customer' => 'Cust A',
            'item_code' => 'LY001',
            'item_name' => 'Stainless Flange',
            'product_scope' => 'FLANGE_STAINLESS',
            'aisi' => '304',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'status' => 'planning',
            'line_number' => 1,
            'po_number' => 'PO-SS-01',
        ]);

        $response = $this->actingAs($this->spvUser)
            ->post(route('lost-wax.print-orders.store'), [
                'action' => 'close_plan',
                'production_plan_id' => $plan->id,
            ]);
        $response->assertStatus(403);
        $this->assertFalse($plan->fresh()->is_closed);

        // 2. PPIC Flange cannot close a plan belonging to FLANGE_BESI scope
        $planBesi = ProductionPlan::create([
            'code' => 'FE-001',
            'customer' => 'Cust B',
            'item_code' => 'LY027',
            'item_name' => 'Besi Flange',
            'product_scope' => 'FLANGE_BESI',
            'aisi' => 'FE',
            'qty_planned' => 200,
            'qty_remaining' => 200,
            'status' => 'planning',
            'line_number' => 2,
            'po_number' => 'PO-FE-01',
        ]);

        $response = $this->actingAs($this->ppicFlange)
            ->post(route('lost-wax.print-orders.store'), [
                'action' => 'close_plan',
                'production_plan_id' => $planBesi->id,
            ]);
        $response->assertStatus(403);
        $this->assertFalse($planBesi->fresh()->is_closed);

        // 3. PPIC Flange cannot bulk close plans containing other scope's plan
        $response = $this->actingAs($this->ppicFlange)
            ->post(route('lost-wax.print-orders.store'), [
                'action' => 'bulk_close_plans',
                'plan_ids' => [$plan->id, $planBesi->id],
            ]);
        $response->assertStatus(403);
        $this->assertFalse($plan->fresh()->is_closed);
        $this->assertFalse($planBesi->fresh()->is_closed);

        // 4. PPIC Flange can successfully close plan of their own scope
        $response = $this->actingAs($this->ppicFlange)
            ->post(route('lost-wax.print-orders.store'), [
                'action' => 'close_plan',
                'production_plan_id' => $plan->id,
            ]);
        $response->assertRedirect();
        $this->assertTrue($plan->fresh()->is_closed);
    }
}
