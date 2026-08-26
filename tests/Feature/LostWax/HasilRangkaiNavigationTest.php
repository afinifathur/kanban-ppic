<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxRangkaiWorkOrder;
use App\Models\ProductionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HasilRangkaiNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $this->user = User::factory()->create();
        $this->user->assignRole($adminRole);
    }

    protected function createProductionPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => 'CODE-NAV-01',
            'customer' => 'CUST-NAV',
            'item_code' => '4.101105K.A0020',
            'item_name' => 'SS304 FLANGE 1"',
            'aisi' => '304',
            'size' => '1"',
            'weight' => 1.5,
            'po_number' => 'PO-NAV-01',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'status' => 'planning',
        ], $attributes));
    }

    protected function createPrintOrderWithLine(ProductionPlan $plan, int $goodQty = 50)
    {
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-NAV-0001',
            'scheduled_date' => now()->format('Y-m-d'),
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        return $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'qty_actual_good' => $goodQty,
        ]);
    }

    /**
     * Test 1: Dedicated Work Orders route exists and renders successfully.
     */
    public function test_dedicated_work_orders_route_exists_and_renders(): void
    {
        $response = $this->actingAs($this->user)->get(route('lost-wax.assemblies.work-orders.index'));

        $response->assertOk();
        $response->assertSee('Hasil Rangkai');
        $response->assertSee('Hasil rangkaian yang sudah dibuat dan sedang dikelola');
    }

    /**
     * Test 2: The Hasil Rangkai page renders existing Work Order data.
     */
    public function test_hasil_rangkai_page_renders_work_order_data(): void
    {
        $plan = $this->createProductionPlan();
        $line = $this->createPrintOrderWithLine($plan, 50);

        $service = app(\App\Services\RangkaiExecutionService::class);
        $wo = $service->createWorkOrder($line, [
            'qty_trees_planned' => 2,
            'tree_capacity' => 20,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->get(route('lost-wax.assemblies.work-orders.index'));

        $response->assertOk();
        $response->assertSee($wo->rangkai_order_number);
        $response->assertSee($line->item_name);
        $response->assertSee('Detail &amp; Eksekusi', false);
        $response->assertSee('Print A5');
    }

    /**
     * Test 3: Assemblies/Rangkai page renders the Available workflow.
     */
    public function test_assemblies_rangkai_page_renders_available_workflow(): void
    {
        $plan = $this->createProductionPlan(['code' => 'CODE-AVAIL']);
        $this->createPrintOrderWithLine($plan, 50);

        $response = $this->actingAs($this->user)->get(route('lost-wax.assemblies.index'));

        $response->assertOk();
        $response->assertSee('Rangkai');
        $response->assertSee('Hasil Cetak Siap Rangkai');
        $response->assertSee('CODE-AVAIL');
        $response->assertSee('Buat WO Rangkai');
    }

    /**
     * Test 4 & 5: Sidebar contains both "Rangkai" and "Hasil Rangkai", pointing to dedicated routes.
     */
    public function test_sidebar_contains_rangkai_and_hasil_rangkai_links(): void
    {
        $response = $this->actingAs($this->user)->get(route('lost-wax.assemblies.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('title="Rangkai"', $html);
        $this->assertStringContainsString('title="Hasil Rangkai"', $html);
        $this->assertStringContainsString(route('lost-wax.assemblies.index'), $html);
        $this->assertStringContainsString(route('lost-wax.assemblies.work-orders.index'), $html);
    }

    /**
     * Test 6: When visiting Work Orders route, "Hasil Rangkai" is active and "Rangkai" is not active.
     */
    public function test_hasil_rangkai_active_sidebar_state(): void
    {
        $response = $this->actingAs($this->user)->get(route('lost-wax.assemblies.work-orders.index'));

        $response->assertOk();
        $html = $response->getContent();

        // Extract sidebar links
        $this->assertMatchesRegularExpression('/href="[^"]*\/lost-wax\/assemblies\/work-orders"[^>]*class="[^"]*text-white font-medium border-l-2 border-amber-400/', $html);
    }

    /**
     * Test 7: When visiting Assemblies/Available, "Rangkai" is active and "Hasil Rangkai" is not active.
     */
    public function test_rangkai_active_sidebar_state(): void
    {
        $response = $this->actingAs($this->user)->get(route('lost-wax.assemblies.index'));

        $response->assertOk();
        $html = $response->getContent();

        // Rangkai is active
        $this->assertMatchesRegularExpression('/href="[^"]*\/lost-wax\/assemblies"[^>]*class="[^"]*text-white font-medium border-l-2 border-amber-400/', $html);
    }

    /**
     * Test 8: Backward compatibility redirect from ?tab=work-orders to dedicated route.
     */
    public function test_backward_compatibility_redirect(): void
    {
        $response = $this->actingAs($this->user)->get('/lost-wax/assemblies?tab=work-orders&search=Flange');

        $response->assertRedirect(route('lost-wax.assemblies.work-orders.index', ['search' => 'Flange']));
    }

    /**
     * Test 9: Existing Work Order actions continue to work seamlessly.
     */
    public function test_existing_work_order_actions_continue_to_work(): void
    {
        $plan = $this->createProductionPlan();
        $line = $this->createPrintOrderWithLine($plan, 50);

        $service = app(\App\Services\RangkaiExecutionService::class);
        $wo = $service->createWorkOrder($line, [
            'qty_trees_planned' => 2,
            'tree_capacity' => 20,
            'created_by' => $this->user->id,
        ]);

        // Show WO detail
        $showResponse = $this->actingAs($this->user)->get(route('lost-wax.assemblies.work-orders.show', $wo));
        $showResponse->assertOk();
        $showResponse->assertSee($wo->rangkai_order_number);

        // Print WO
        $printResponse = $this->actingAs($this->user)->get(route('lost-wax.assemblies.work-orders.print', $wo));
        $printResponse->assertOk();
        $printResponse->assertSee($wo->rangkai_order_number);
    }

    /**
     * Test 10: Work Order detail page renders confirmation modal and submit guard.
     */
    public function test_work_order_detail_renders_confirmation_modal_and_submit_guard(): void
    {
        $plan = $this->createProductionPlan();
        $line = $this->createPrintOrderWithLine($plan, 50);

        $service = app(\App\Services\RangkaiExecutionService::class);
        $wo = $service->createWorkOrder($line, [
            'qty_trees_planned' => 2,
            'tree_capacity' => 20,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->get(route('lost-wax.assemblies.work-orders.show', $wo));
        $response->assertOk();

        // Check for submit button
        $response->assertSee('Simpan Eksekusi &amp; Terbitkan Traveler', false);

        // Check for confirmation modal elements
        $response->assertSee('confirmTravelerModal');
        $response->assertSee('Konfirmasi Terbitkan Traveler');
        $response->assertSee('Apakah data eksekusi sudah benar?');
        $response->assertSee('Setelah Traveler diterbitkan, data akan diproses sebagai hasil eksekusi dan tidak boleh diterbitkan secara tidak sengaja.');
        $response->assertSee('cancelConfirmBtn');
        $response->assertSee('proceedConfirmBtn');
        $response->assertSee('Ya, Terbitkan Traveler');
        $response->assertSee('Batal');

        // Check for submit event listener and confirmedSubmit flag
        $response->assertSee('confirmedSubmit');
        $response->assertSee('openConfirmationModal');
    }
}
