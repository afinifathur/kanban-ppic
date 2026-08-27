<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\ProductionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OutcomeWorkbenchUxTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);
        $adminRole->syncPermissions([$accessPlanning, $accessExecution]);

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
    }

    public function test_workbench_page_renders_header_filters_and_table_with_21_items(): void
    {
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260826-0004',
            'scheduled_date' => '2026-08-26',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        // Create 21 items under this print order
        for ($i = 1; $i <= 21; $i++) {
            $code = '268L'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $plan = ProductionPlan::create([
                'code' => $code,
                'customer' => 'PT PERONI KARYA UTAMA',
                'item_code' => '4.101105K.A0'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'item_name' => "SS304 ELBOW 90 F/F BSP {$i}/4\"",
                'aisi' => '304',
                'size' => "{$i}/4\"",
                'weight' => 0.5 * $i,
                'po_number' => 'PO-TEST',
                'qty_planned' => 200,
                'qty_remaining' => 200,
                'line_number' => $i,
                'status' => 'planning',
            ]);

            $order->lines()->create([
                'production_plan_id' => $plan->id,
                'qty_ordered' => 200,
                'code' => $plan->code,
                'customer' => $plan->customer,
                'item_name' => $plan->item_name,
                'size' => $plan->size,
                'aisi' => $plan->aisi,
                'standard_tree_capacity' => 20,
            ]);
        }

        $response = $this->actingAs($this->user)->get(route('lost-wax.outcomes.edit', $order));

        $response->assertOk();

        // 1. Header assertions
        $response->assertSee('PC-20260826-0004');
        $response->assertSee('26-08-2026');
        $response->assertSee('21 Item');
        $response->assertSee('4,200 pcs'); // 21 * 200 = 4,200
        $response->assertSee('Total Good');
        $response->assertSee('Total Defect');
        $response->assertSee('Outstanding');
        $response->assertSee('STATUS: ISSUED');

        // 2. Filter input assertions
        $response->assertSee('filterCode');
        $response->assertSee('filterProduct');
        $response->assertSee('filterStatus');
        $response->assertSee('btnResetFilter');

        // 3. Table assertions (Sample codes and Catat Hasil buttons)
        $response->assertSee('workbenchTable');
        $response->assertSee('268L001');
        $response->assertSee('268L021');
        $response->assertSee('SS304 ELBOW 90 F/F BSP 1/4"');
        $response->assertSee('Catat Hasil');
        $response->assertSee('BELUM MULAI');

        // 4. Modal assertions
        $response->assertSee('modalCatatHasil');
        $response->assertSee('modalInputGood');
        $response->assertSee('modalInputDefect');
        $response->assertSee('modalInputDate');
        $response->assertSee('btnModalSaveDraft');
        $response->assertSee('btnModalFinalize');
    }

    public function test_modal_workflow_end_to_end_finalization_updates_row_and_returns_to_edit_page(): void
    {
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260826-0004',
            'scheduled_date' => '2026-08-26',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        $plan = ProductionPlan::create([
            'code' => '268L651',
            'customer' => 'PT PERONI KARYA UTAMA',
            'item_code' => '4.101105K.A0080',
            'item_name' => 'SS304 ELBOW 90 F/F BSP 3/4"',
            'aisi' => '304',
            'size' => '3/4"',
            'weight' => 1.25,
            'po_number' => 'PO-TEST',
            'qty_planned' => 203,
            'qty_remaining' => 203,
            'line_number' => 1,
            'status' => 'planning',
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 203,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'standard_tree_capacity' => 20,
        ]);

        // 1. Initial State: Outstanding 203, Good 0, Defect 0
        $response = $this->actingAs($this->user)->get(route('lost-wax.outcomes.edit', $order));
        $response->assertOk();
        $response->assertSee('203');
        $response->assertSee('BELUM MULAI');

        // 2. Finalize execution for line (Good: 200, Defect: 3)
        $storeResponse = $this->actingAs($this->user)->postJson(
            route('lost-wax.outcomes.lines.execution.store', $line),
            [
                'execution_date' => '2026-08-26',
                'qty_good' => 200,
                'qty_defect' => 3,
                'status' => 'FINALIZED',
                'notes' => 'Catatan hasil uji coba workbench',
            ]
        );

        $storeResponse->assertOk();
        $storeResponse->assertJson(['success' => true]);

        // 3. Verify refreshed workbench page displays updated data
        $line->refresh();
        $this->assertEquals(200, $line->qty_executed_good);
        $this->assertEquals(3, $line->qty_executed_defect);
        $this->assertEquals(0, $line->qty_outstanding);
        $this->assertEquals('COMPLETED', $line->execution_status);

        $refreshedResponse = $this->actingAs($this->user)->get(route('lost-wax.outcomes.edit', $order));
        $refreshedResponse->assertOk();
        $refreshedResponse->assertSee('200');
        $refreshedResponse->assertSee('3');
        $refreshedResponse->assertSee('SELESAI');
    }
}
