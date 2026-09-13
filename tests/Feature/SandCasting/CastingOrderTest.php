<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CastingOrderTest extends TestCase
{
    use RefreshDatabase;

    protected User $ppicUser;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions and roles
        $permission = Permission::firstOrCreate(['name' => 'access_planning']);
        $ppicRole = Role::firstOrCreate(['name' => 'ppic']);
        $ppicRole->givePermissionTo($permission);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->givePermissionTo($permission);

        $this->ppicUser = User::factory()->create([
            'email' => 'ppic_flange@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->ppicUser->assignRole('ppic');

        $this->adminUser = User::factory()->create([
            'email' => 'admin@peroniks.com',
            'product_scope' => null,
        ]);
        $this->adminUser->assignRole('admin');
    }

    public function test_plans_screen_only_lists_sand_casting_plans_and_filters_by_scope(): void
    {
        // Sand Casting plan with matching scope
        $scPlan = ProductionPlan::create([
            'code' => 'SC001',
            'title' => 'Rencana SC 1',
            'item_code' => '4.101',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-001',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'customer' => 'PT LOKAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        // Sand Casting plan with different scope
        $scPlanOtherScope = ProductionPlan::create([
            'code' => 'SC002',
            'title' => 'Rencana SC 2',
            'item_code' => '4.102',
            'item_name' => 'FITTING STAINLESS 2"',
            'po_number' => 'PO-002',
            'qty_planned' => 50,
            'qty_remaining' => 50,
            'line_number' => 1,
            'customer' => 'PT OTHER',
            'product_scope' => 'FITTING_STAINLESS',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        // Lost Wax plan with same scope
        $lwPlan = ProductionPlan::create([
            'code' => 'LW001',
            'title' => 'Rencana LW 1',
            'item_code' => '4.103',
            'item_name' => 'FLANGE BESI JIS 10K 2" LW',
            'po_number' => 'PO-003',
            'qty_planned' => 80,
            'qty_remaining' => 80,
            'line_number' => 1,
            'customer' => 'PT LOKAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'status' => 'planning',
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.plans', ['tab' => 'plans']));

        $response->assertOk();
        $response->assertSee('SC001');
        $response->assertDontSee('SC002'); // Different scope
        $response->assertDontSee('LW001'); // Lost Wax domain excluded
    }

    public function test_cannot_create_casting_order_with_lost_wax_plan_due_to_strict_domain_guard(): void
    {
        $lwPlan = ProductionPlan::create([
            'code' => 'LW-MALICIOUS',
            'title' => 'Rencana LW',
            'item_code' => '4.103',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-003',
            'qty_planned' => 80,
            'qty_remaining' => 80,
            'line_number' => 1,
            'customer' => 'PT LOKAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'status' => 'planning',
        ]);

        // Try to access create view
        $responseCreate = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.create', ['plan_ids' => [$lwPlan->id]]));
        $responseCreate->assertStatus(403);

        // Try to post store with Lost Wax plan
        $responseStore = $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.store'), [
                'casting_order_number' => 'PCOR-20260914-0001',
                'scheduled_date' => '2026-09-14',
                'items' => [
                    [
                        'production_plan_id' => $lwPlan->id,
                        'qty_ordered' => 20,
                    ],
                ],
            ]);
        $responseStore->assertStatus(403);
    }

    public function test_successful_creation_of_casting_order_and_proper_quantity_isolation(): void
    {
        $scPlan = ProductionPlan::create([
            'code' => 'SC010',
            'title' => 'Rencana SC 10',
            'item_code' => '4.101',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'aisi' => 'FC250',
            'size' => '2"',
            'po_number' => 'PO-010',
            'qty_planned' => 100,
            'qty_remaining' => 100, // Legacy physical remaining
            'line_number' => 1,
            'customer' => 'PT LOKAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $this->assertEquals(0, $scPlan->qty_casting_scheduled);
        $this->assertEquals(100, $scPlan->qty_remaining_casting_scheduled);

        $response = $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.store'), [
                'casting_order_number' => 'PCOR-20260914-0001',
                'scheduled_date' => '2026-09-14',
                'notes' => 'Penuangan batch 1',
                'items' => [
                    [
                        'production_plan_id' => $scPlan->id,
                        'qty_ordered' => 30,
                        'notes' => 'Line 1 note',
                    ],
                ],
            ]);

        $order = SandCastingCastingOrder::first();
        $this->assertNotNull($order);
        $this->assertEquals('PCOR-20260914-0001', $order->casting_order_number);
        $this->assertEquals('DRAFT', $order->status);
        $this->assertEquals('Penuangan batch 1', $order->notes);
        $this->assertEquals($this->ppicUser->id, $order->created_by);

        $line = $order->lines->first();
        $this->assertNotNull($line);
        $this->assertEquals($scPlan->id, $line->production_plan_id);
        $this->assertEquals(30, $line->qty_ordered);
        $this->assertEquals('SC010', $line->code);
        $this->assertEquals('PT LOKAL', $line->customer);
        $this->assertEquals('FLANGE BESI JIS 10K 2"', $line->item_name);
        $this->assertEquals('2"', $line->size);
        $this->assertEquals('FC250', $line->aisi);

        // Verify quantity semantics:
        // ProductionPlan.qty_remaining MUST NOT be mutated
        $scPlan->refresh();
        $this->assertEquals(100, $scPlan->qty_remaining, 'qty_remaining must remain untouched by Perintah Cor');
        $this->assertEquals(30, $scPlan->qty_casting_scheduled);
        $this->assertEquals(70, $scPlan->qty_remaining_casting_scheduled);

        $response->assertRedirect(route('sand-casting.casting-orders.show', $order));
    }

    public function test_cannot_order_more_than_available_capacity(): void
    {
        $scPlan = ProductionPlan::create([
            'code' => 'SC020',
            'title' => 'Rencana SC 20',
            'item_code' => '4.101',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-020',
            'qty_planned' => 50,
            'qty_remaining' => 50,
            'line_number' => 1,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.store'), [
                'casting_order_number' => 'PCOR-20260914-0002',
                'scheduled_date' => '2026-09-14',
                'items' => [
                    [
                        'production_plan_id' => $scPlan->id,
                        'qty_ordered' => 51, // Exceeds 50
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertEquals(0, SandCastingCastingOrder::count());
    }

    public function test_status_transitions_and_cancellation_releases_quantity(): void
    {
        $scPlan = ProductionPlan::create([
            'code' => 'SC030',
            'title' => 'Rencana SC 30',
            'item_code' => '4.101',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-030',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0003',
            'scheduled_date' => '2026-09-14',
            'status' => 'DRAFT',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $scPlan->id,
            'qty_ordered' => 40,
            'code' => $scPlan->code,
            'item_name' => $scPlan->item_name,
        ]);

        $scPlan->refresh();
        $this->assertEquals(60, $scPlan->qty_remaining_casting_scheduled);

        // Transition DRAFT -> ISSUED
        $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.update-status', $order), [
                'status' => 'ISSUED',
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertEquals('ISSUED', $order->status);
        $scPlan->refresh();
        $this->assertEquals(60, $scPlan->qty_remaining_casting_scheduled);

        // Transition ISSUED -> COMPLETED
        $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.update-status', $order), [
                'status' => 'COMPLETED',
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertEquals('COMPLETED', $order->status);

        // Transition COMPLETED -> CANCELLED
        $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.update-status', $order), [
                'status' => 'CANCELLED',
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertEquals('CANCELLED', $order->status);

        // Released: since CANCELLED is not in ['DRAFT', 'ISSUED'], the 40 pcs is available again
        $scPlan->refresh();
        $this->assertEquals(0, $scPlan->qty_casting_scheduled);
        $this->assertEquals(100, $scPlan->qty_remaining_casting_scheduled);
    }

    public function test_draft_deletion_and_line_removal_releases_capacity(): void
    {
        $scPlan1 = ProductionPlan::create([
            'code' => 'SC041',
            'title' => 'Rencana SC 41',
            'item_code' => '4.101',
            'item_name' => 'FLANGE BESI 1"',
            'po_number' => 'PO-041',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $scPlan2 = ProductionPlan::create([
            'code' => 'SC042',
            'title' => 'Rencana SC 42',
            'item_code' => '4.102',
            'item_name' => 'FLANGE BESI 2"',
            'po_number' => 'PO-042',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 2,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0004',
            'scheduled_date' => '2026-09-14',
            'status' => 'DRAFT',
            'created_by' => $this->ppicUser->id,
        ]);

        $line1 = $order->lines()->create([
            'production_plan_id' => $scPlan1->id,
            'qty_ordered' => 25,
            'code' => $scPlan1->code,
            'item_name' => $scPlan1->item_name,
        ]);

        $line2 = $order->lines()->create([
            'production_plan_id' => $scPlan2->id,
            'qty_ordered' => 35,
            'code' => $scPlan2->code,
            'item_name' => $scPlan2->item_name,
        ]);

        $this->assertEquals(75, $scPlan1->fresh()->qty_remaining_casting_scheduled);
        $this->assertEquals(65, $scPlan2->fresh()->qty_remaining_casting_scheduled);

        // Delete line 1
        $this->actingAs($this->ppicUser)
            ->delete(route('sand-casting.casting-orders.lines.destroy', [$order, $line1]))
            ->assertRedirect(route('sand-casting.casting-orders.edit', $order));

        $this->assertEquals(100, $scPlan1->fresh()->qty_remaining_casting_scheduled);
        $this->assertEquals(65, $scPlan2->fresh()->qty_remaining_casting_scheduled);

        // Delete last line (deletes the entire draft order)
        $this->actingAs($this->ppicUser)
            ->delete(route('sand-casting.casting-orders.lines.destroy', [$order, $line2]))
            ->assertRedirect(route('sand-casting.casting-orders.plans'));

        $this->assertDatabaseMissing('sand_casting_casting_orders', ['id' => $order->id]);
        $this->assertEquals(100, $scPlan2->fresh()->qty_remaining_casting_scheduled);
    }

    public function test_issued_order_cannot_be_deleted(): void
    {
        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0005',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->delete(route('sand-casting.casting-orders.destroy', $order));

        $response->assertRedirect(route('sand-casting.casting-orders.show', $order));
        $this->assertDatabaseHas('sand_casting_casting_orders', ['id' => $order->id]);
    }

    public function test_updating_draft_order_and_adding_new_lines(): void
    {
        $scPlan1 = ProductionPlan::create([
            'code' => 'SC051',
            'title' => 'Rencana SC 51',
            'item_code' => '4.101',
            'item_name' => 'FLANGE BESI 1"',
            'po_number' => 'PO-051',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $scPlan2 = ProductionPlan::create([
            'code' => 'SC052',
            'title' => 'Rencana SC 52',
            'item_code' => '4.102',
            'item_name' => 'FLANGE BESI 2"',
            'po_number' => 'PO-052',
            'qty_planned' => 50,
            'qty_remaining' => 50,
            'line_number' => 2,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0050',
            'scheduled_date' => '2026-09-14',
            'status' => 'DRAFT',
            'created_by' => $this->ppicUser->id,
        ]);

        $line1 = $order->lines()->create([
            'production_plan_id' => $scPlan1->id,
            'qty_ordered' => 20,
            'code' => $scPlan1->code,
            'item_name' => $scPlan1->item_name,
        ]);

        // Update line quantity
        $response = $this->actingAs($this->ppicUser)
            ->put(route('sand-casting.casting-orders.update', $order), [
                'scheduled_date' => '2026-09-15',
                'casting_order_number' => 'PCOR-20260914-0050',
                'notes' => 'Updated notes',
                'items' => [
                    [
                        'id' => $line1->id,
                        'qty_ordered' => 45,
                        'notes' => 'Line updated note',
                    ],
                ],
            ]);

        $response->assertRedirect(route('sand-casting.casting-orders.show', $order));
        $line1->refresh();
        $this->assertEquals(45, $line1->qty_ordered);
        $this->assertEquals('Line updated note', $line1->notes);
        $this->assertEquals(55, $scPlan1->fresh()->qty_remaining_casting_scheduled);

        // Add second line to DRAFT order via storeLine
        $responseAddLine = $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.lines.store', $order), [
                'production_plan_id' => $scPlan2->id,
                'qty_ordered' => 30,
                'notes' => 'Added second line',
            ]);

        $responseAddLine->assertRedirect(route('sand-casting.casting-orders.edit', $order));
        $this->assertEquals(2, $order->lines()->count());
        $this->assertEquals(20, $scPlan2->fresh()->qty_remaining_casting_scheduled);
    }

    public function test_print_and_show_views_render_successfully(): void
    {
        $scPlan = ProductionPlan::create([
            'code' => 'SC060',
            'title' => 'Rencana SC 60',
            'item_code' => '4.101',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-060',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0060',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $order->lines()->create([
            'production_plan_id' => $scPlan->id,
            'qty_ordered' => 50,
            'code' => $scPlan->code,
            'item_name' => $scPlan->item_name,
        ]);

        $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.show', $order))
            ->assertOk()
            ->assertSee('PCOR-20260914-0060')
            ->assertSee('FLANGE BESI JIS 10K 2"');

        $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.print', $order))
            ->assertOk()
            ->assertSee('SURAT PERINTAH COR PASIR (SAND CASTING)')
            ->assertSee('PCOR-20260914-0060');
    }
}
