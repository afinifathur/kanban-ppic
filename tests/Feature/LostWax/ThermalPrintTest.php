<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxTree;
use App\Models\PrintJob;
use App\Models\ProductionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThermalPrintTest extends TestCase
{
    use RefreshDatabase;

    private User $ppicFlange;

    private User $ppicFlangeBesi;

    private User $adminUser;

    private User $spvUser;

    private string $agentToken = 'peroniks_print_token_2026';

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup config for test consistency
        config(['lost_wax.print_agent_token' => $this->agentToken]);
        config(['lost_wax.printer_name' => 'TSC TE200']);

        // 2. Set up roles and permissions (Spatie)
        $accessPlanning = \Spatie\Permission\Models\Permission::findOrCreate('access_planning');
        $accessExecution = \Spatie\Permission\Models\Permission::findOrCreate('access_execution');

        $adminRole = \Spatie\Permission\Models\Role::findOrCreate('admin');
        $ppicRole = \Spatie\Permission\Models\Role::findOrCreate('ppic');
        $spvRole = \Spatie\Permission\Models\Role::findOrCreate('spv');

        $adminRole->givePermissionTo([$accessPlanning, $accessExecution]);
        $ppicRole->givePermissionTo([$accessPlanning, $accessExecution]);
        $spvRole->givePermissionTo([$accessExecution]);

        // 3. Create the test users
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

        $this->adminUser = User::factory()->create([
            'email' => 'admin@peroniks.com',
            'product_scope' => null,
        ]);
        $this->adminUser->assignRole('admin');

        $this->spvUser = User::factory()->create([
            'email' => 'spv@peroniks.com',
            'product_scope' => null,
        ]);
        $this->spvUser->assignRole('spv');
    }

    /**
     * Test print agent API endpoints security.
     */
    public function test_print_jobs_api_authentication(): void
    {
        // 1. Without Token -> 401 Unauthorized
        $response = $this->postJson('/api/print-jobs/claim', [
            'machine_id' => 'TEST-PC',
            'printer_name' => 'TSC TE200',
        ]);
        $response->assertStatus(401);

        // 2. With Invalid Token -> 401 Unauthorized
        $response = $this->withHeaders([
            'Authorization' => 'Bearer wrong_token',
        ])->postJson('/api/print-jobs/claim', [
            'machine_id' => 'TEST-PC',
            'printer_name' => 'TSC TE200',
        ]);
        $response->assertStatus(401);

        // 3. With Valid Token -> 204 No Content (No jobs in queue yet)
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->agentToken,
        ])->postJson('/api/print-jobs/claim', [
            'machine_id' => 'TEST-PC',
            'printer_name' => 'TSC TE200',
        ]);
        $response->assertStatus(204);
    }

    /**
     * Test print queue claiming and updating state.
     */
    public function test_print_agent_api_workflow(): void
    {
        // Create a pending job
        $job = PrintJob::create([
            'printer_name' => 'TSC TE200',
            'payload_tspl' => 'SIZE 50 mm, 90 mm\r\n',
            'payload_hash' => hash('sha256', 'SIZE 50 mm, 90 mm\r\n'),
            'copies' => 1,
            'status' => 'pending',
            'template_type' => 'TRAVELER_LABEL_90X50',
        ]);

        // 1. Claim job
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->agentToken,
        ])->postJson('/api/print-jobs/claim', [
            'machine_id' => 'TEST-PC',
            'printer_name' => 'TSC TE200',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'id' => $job->id,
            'status' => 'processing',
            'claimed_by_machine' => 'TEST-PC',
        ]);

        // 2. Mark completed
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->agentToken,
        ])->postJson("/api/print-jobs/{$job->id}/complete");

        $response->assertStatus(200);
        $this->assertEquals('printed', $job->fresh()->status);
        $this->assertNotNull($job->fresh()->printed_at);

        // 3. Mark failed
        $job->update(['status' => 'processing', 'claimed_at' => now()]);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->agentToken,
        ])->postJson("/api/print-jobs/{$job->id}/failed", [
            'error' => 'Paper jammed',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('failed', $job->fresh()->status);
        $this->assertEquals('Paper jammed', $job->fresh()->error_message);
        $this->assertNotNull($job->fresh()->failed_at);
    }

    /**
     * Test web print route authorization and scope enforcement.
     */
    public function test_web_print_thermal_permissions_and_rbac(): void
    {
        // 1. Setup production plans with different scopes
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

        // Create Print Orders
        $printOrder = LostWaxPrintOrder::create([
            'print_order_number' => 'PO-001',
            'scheduled_date' => now(),
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $lineStainless = LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $printOrder->id,
            'production_plan_id' => $planStainless->id,
            'qty_ordered' => 10,
            'code' => 'AB01',
            'customer' => 'Cust A',
            'item_name' => 'SS304 Flange',
        ]);

        $lineBesi = LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $printOrder->id,
            'production_plan_id' => $planBesi->id,
            'qty_ordered' => 10,
            'code' => 'AB02',
            'customer' => 'Cust B',
            'item_name' => 'Besi Flange',
        ]);

        // Create Trees
        $treeStainless = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $lineStainless->id,
            'barcode' => '260821001',
            'tree_number' => 1,
            'quantity' => 15,
            'status' => 'generated',
            'production_date' => now(),
            'family_code' => '3',
            'daily_sequence' => 1,
        ]);

        $treeBesi = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $lineBesi->id,
            'barcode' => '260821002',
            'tree_number' => 2,
            'quantity' => 15,
            'status' => 'generated',
            'production_date' => now(),
            'family_code' => '3',
            'daily_sequence' => 2,
        ]);

        // 2. Unauthenticated user is blocked (redirected)
        $response = $this->postJson(route('lost-wax.trees.print-thermal'), [
            'ids' => (string) $treeStainless->id,
        ]);
        $response->assertStatus(401);

        // 3. PPIC Stainless can print Stainless Tree
        $response = $this->actingAs($this->ppicFlange)->postJson(route('lost-wax.trees.print-thermal'), [
            'ids' => (string) $treeStainless->id,
        ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('print_jobs', [
            'template_type' => 'TRAVELER_LABEL_90X50',
            'printer_name' => 'TSC TE200',
        ]);

        // 4. PPIC Stainless is BLOCKED from printing Besi Tree (RBAC Scope check)
        $response = $this->actingAs($this->ppicFlange)->postJson(route('lost-wax.trees.print-thermal'), [
            'ids' => (string) $treeBesi->id,
        ]);
        $response->assertStatus(403);

        // 5. SPV User has all scopes -> Can print Besi Tree
        $response = $this->actingAs($this->spvUser)->postJson(route('lost-wax.trees.print-thermal'), [
            'ids' => (string) $treeBesi->id,
        ]);
        $response->assertStatus(200);
    }

    /**
     * Test TSPL payload correctness.
     */
    public function test_tspl_payload_correctness(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'ST-001',
            'customer' => 'PT. XYZ INDONESIA',
            'item_code' => 'LY026',
            'item_name' => 'SS304 Flange 3 Inch',
            'product_scope' => 'FLANGE_STAINLESS',
            'aisi' => '304',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'status' => 'planning',
            'line_number' => 1,
            'po_number' => 'PO-ST-01',
        ]);

        $printOrder = LostWaxPrintOrder::create([
            'print_order_number' => 'PO-001',
            'scheduled_date' => now(),
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $line = LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $printOrder->id,
            'production_plan_id' => $plan->id,
            'qty_ordered' => 10,
            'code' => 'AB01',
            'customer' => 'PT. XYZ INDONESIA',
            'item_name' => 'SS304 Flange 3 Inch',
        ]);

        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => '260821001',
            'tree_number' => 1,
            'quantity' => 15,
            'status' => 'generated',
            'production_date' => now(),
            'family_code' => '3',
            'daily_sequence' => 1,
        ]);

        $response = $this->actingAs($this->adminUser)->postJson(route('lost-wax.trees.print-thermal'), [
            'ids' => (string) $tree->id,
        ]);
        $response->assertStatus(200);

        $job = PrintJob::latest('id')->first();
        $this->assertNotNull($job);

        // Assert TSPL contents
        $tspl = $job->payload_tspl;
        $this->assertStringContainsString('SIZE 50 mm, 90 mm', $tspl);
        $this->assertStringContainsString('GAP 3 mm, 0', $tspl);
        $this->assertStringContainsString('BARCODE 60,410,"128",160,0,0,2,4,"260821001"', $tspl);
        $this->assertStringContainsString('CUST: AB01', $tspl);
        $this->assertStringContainsString('15 PCS', $tspl);
        $this->assertStringContainsString('PT. XYZ INDONESIA', $tspl);
    }

    /**
     * Test bulk printing creates 1 PrintJob per tree.
     */
    public function test_bulk_thermal_print_creates_correct_number_of_jobs(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'ST-001',
            'customer' => 'PT. XYZ INDONESIA',
            'item_code' => 'LY026',
            'item_name' => 'SS304 Flange 3 Inch',
            'product_scope' => 'FLANGE_STAINLESS',
            'aisi' => '304',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'status' => 'planning',
            'line_number' => 1,
            'po_number' => 'PO-ST-01',
        ]);

        $printOrder = LostWaxPrintOrder::create([
            'print_order_number' => 'PO-001',
            'scheduled_date' => now(),
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $line = LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $printOrder->id,
            'production_plan_id' => $plan->id,
            'qty_ordered' => 10,
            'code' => 'AB01',
            'customer' => 'PT. XYZ INDONESIA',
            'item_name' => 'SS304 Flange 3 Inch',
        ]);

        $tree1 = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => '260821001',
            'tree_number' => 1,
            'quantity' => 15,
            'status' => 'generated',
            'production_date' => now(),
            'family_code' => '3',
            'daily_sequence' => 1,
        ]);

        $tree2 = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => '260821002',
            'tree_number' => 2,
            'quantity' => 15,
            'status' => 'generated',
            'production_date' => now(),
            'family_code' => '3',
            'daily_sequence' => 2,
        ]);

        $this->assertEquals(0, PrintJob::count());

        $response = $this->actingAs($this->adminUser)->postJson(route('lost-wax.trees.print-thermal'), [
            'ids' => $tree1->id.','.$tree2->id,
        ]);
        $response->assertStatus(200);

        // Check that 2 separate jobs were created
        $this->assertEquals(2, PrintJob::count());
    }

    /**
     * Test epson traveler view is unmodified and loads properly.
     */
    public function test_epson_route_remains_intact(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'ST-001',
            'customer' => 'PT. XYZ INDONESIA',
            'item_code' => 'LY026',
            'item_name' => 'SS304 Flange 3 Inch',
            'product_scope' => 'FLANGE_STAINLESS',
            'aisi' => '304',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'status' => 'planning',
            'line_number' => 1,
            'po_number' => 'PO-ST-01',
        ]);

        $printOrder = LostWaxPrintOrder::create([
            'print_order_number' => 'PO-001',
            'scheduled_date' => now(),
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $line = LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $printOrder->id,
            'production_plan_id' => $plan->id,
            'qty_ordered' => 10,
            'code' => 'AB01',
            'customer' => 'PT. XYZ INDONESIA',
            'item_name' => 'SS304 Flange 3 Inch',
        ]);

        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => '260821001',
            'tree_number' => 1,
            'quantity' => 15,
            'status' => 'generated',
            'production_date' => now(),
            'family_code' => '3',
            'daily_sequence' => 1,
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.trees.traveler', $tree));
        $response->assertStatus(200);
        $response->assertSee('LOST WAX TRAVELER');
        $response->assertSee('Cetak Epson A4');
        $response->assertSee('Cetak Thermal 90×50');
    }
}
