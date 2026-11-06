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

    public function test_store_line_with_lost_wax_plan_is_rejected_with_forbidden_403(): void
    {
        $scPlan = ProductionPlan::create([
            'code' => 'SC-DRAFT-01',
            'title' => 'Rencana SC Draft',
            'item_code' => '4.101',
            'item_name' => 'FLANGE BESI 1"',
            'po_number' => 'PO-DRAFT-01',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'customer' => 'PT LOKAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $lwPlan = ProductionPlan::create([
            'code' => 'LW-INJECTION',
            'title' => 'Rencana LW Malicious',
            'item_code' => '4.102',
            'item_name' => 'FLANGE BESI 2" LW',
            'po_number' => 'PO-LW-01',
            'qty_planned' => 50,
            'qty_remaining' => 50,
            'line_number' => 1,
            'customer' => 'PT LOKAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0099',
            'scheduled_date' => '2026-09-14',
            'status' => 'DRAFT',
            'created_by' => $this->ppicUser->id,
        ]);

        // Initial valid line
        $order->lines()->create([
            'production_plan_id' => $scPlan->id,
            'qty_ordered' => 20,
            'code' => $scPlan->code,
            'item_name' => $scPlan->item_name,
        ]);

        // Attempt to dynamically add a LOST_WAX plan line to the draft PCOR
        $response = $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.lines.store', $order), [
                'production_plan_id' => $lwPlan->id,
                'qty_ordered' => 15,
            ]);

        $response->assertStatus(403);
        $this->assertEquals(1, $order->lines()->count(), 'Draft order must still only have the initial valid line');
    }

    public function test_mixed_domain_post_store_fails_with_403_and_rolls_back_atomically(): void
    {
        $scPlan = ProductionPlan::create([
            'code' => 'SC-VALID',
            'title' => 'Rencana SC Valid',
            'item_code' => '4.101',
            'item_name' => 'FLANGE BESI 1"',
            'po_number' => 'PO-001',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'customer' => 'PT LOKAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $lwPlan = ProductionPlan::create([
            'code' => 'LW-INVALID',
            'title' => 'Rencana LW Invalid',
            'item_code' => '4.102',
            'item_name' => 'FLANGE BESI 2" LW',
            'po_number' => 'PO-002',
            'qty_planned' => 50,
            'qty_remaining' => 50,
            'line_number' => 1,
            'customer' => 'PT LOKAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'status' => 'planning',
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.store'), [
                'casting_order_number' => 'PCOR-20260914-0088',
                'scheduled_date' => '2026-09-14',
                'items' => [
                    [
                        'production_plan_id' => $scPlan->id,
                        'qty_ordered' => 30,
                    ],
                    [
                        'production_plan_id' => $lwPlan->id,
                        'qty_ordered' => 20,
                    ],
                ],
            ]);

        $response->assertStatus(403);
        $this->assertEquals(0, SandCastingCastingOrder::where('casting_order_number', 'PCOR-20260914-0088')->count());
        $this->assertEquals(0, SandCastingCastingOrder::count(), 'Atomic rollback: no casting order created');
        $this->assertEquals(0, $scPlan->fresh()->qty_casting_scheduled, 'Quota of valid plan must not be locked');
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
        $scPlan->refresh();
        $this->assertEquals(40, $scPlan->qty_casting_scheduled, 'COMPLETED order must still lock scheduled quota');
        $this->assertEquals(60, $scPlan->qty_remaining_casting_scheduled);

        // Transition COMPLETED -> CANCELLED
        $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.update-status', $order), [
                'status' => 'CANCELLED',
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertEquals('CANCELLED', $order->status);

        // Released: since CANCELLED is not in ['DRAFT', 'ISSUED', 'PARTIALLY_COMPLETED', 'COMPLETED'], the 40 pcs is available again
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

    public function test_plans_view_displays_selection_summary_bar_net_weight_and_line_columns(): void
    {
        ProductionPlan::create([
            'code' => 'SC-WEIGHT-01',
            'title' => 'Rencana SC Berat',
            'item_code' => '4.201',
            'item_name' => 'FLANGE BESI JIS 10K 4"',
            'po_number' => 'PO-W01',
            'qty_planned' => 10,
            'qty_remaining' => 10,
            'weight' => 2.50,
            'line_number' => 2,
            'customer' => 'PT BERAT',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.plans', ['tab' => 'plans']));

        $response->assertOk();
        $response->assertSee('selection-summary', false);
        $response->assertSee('summary-count', false);
        $response->assertSee('summary-qty', false);
        $response->assertSee('summary-weight', false);
        $response->assertSee('Berat Bersih');
        $response->assertSee('Line 2');
        $response->assertSee('2.50 kg');
        $response->assertSee('data-qty="10"', false);
        $response->assertSee('data-weight="2.5"', false);
        $response->assertSee('sessionStorage.setItem(selectionStorageKey', false);
    }

    public function test_create_update_and_store_line_persist_line_number_on_production_plan(): void
    {
        $scPlan1 = ProductionPlan::create([
            'code' => 'SC-LINE-01',
            'title' => 'Rencana Line 1',
            'item_code' => '4.301',
            'item_name' => 'FLANGE LINE 1',
            'po_number' => 'PO-L01',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'weight' => 3.00,
            'line_number' => 1,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $scPlan2 = ProductionPlan::create([
            'code' => 'SC-LINE-02',
            'title' => 'Rencana Line 2',
            'item_code' => '4.302',
            'item_name' => 'FLANGE LINE 2',
            'po_number' => 'PO-L02',
            'qty_planned' => 50,
            'qty_remaining' => 50,
            'weight' => 4.00,
            'line_number' => 1,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        // 1. Store PCOR with line_number = 3
        $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.store'), [
                'casting_order_number' => 'PCOR-20260914-0301',
                'scheduled_date' => '2026-09-14',
                'items' => [
                    [
                        'production_plan_id' => $scPlan1->id,
                        'qty_ordered' => 40,
                        'line_number' => 3,
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertEquals(3, $scPlan1->fresh()->line_number);

        $order = SandCastingCastingOrder::where('casting_order_number', 'PCOR-20260914-0301')->firstOrFail();
        $line1 = $order->lines->first();

        // 2. Update PCOR with line_number = 4
        $this->actingAs($this->ppicUser)
            ->put(route('sand-casting.casting-orders.update', $order), [
                'casting_order_number' => 'PCOR-20260914-0301',
                'scheduled_date' => '2026-09-14',
                'items' => [
                    [
                        'id' => $line1->id,
                        'qty_ordered' => 45,
                        'line_number' => 4,
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertEquals(4, $scPlan1->fresh()->line_number);

        // 3. Add second line via storeLine with line_number = 2
        $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.lines.store', $order), [
                'production_plan_id' => $scPlan2->id,
                'qty_ordered' => 25,
                'line_number' => 2,
            ])
            ->assertRedirect();

        $this->assertEquals(2, $scPlan2->fresh()->line_number);
    }

    public function test_issued_order_can_be_reverted_to_draft_only_when_no_outcomes_exist(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $scPlan = ProductionPlan::create([
            'code' => 'SC-REV-01',
            'title' => 'Rencana Revisi',
            'item_code' => '4.401',
            'item_name' => 'FLANGE REVISI',
            'po_number' => 'PO-R01',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0401',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $scPlan->id,
            'qty_ordered' => 50,
            'code' => $scPlan->code,
            'item_name' => $scPlan->item_name,
        ]);

        // 1. No outcomes yet -> ISSUED -> DRAFT succeeds
        $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.update-status', $order), [
                'status' => 'DRAFT',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertEquals('DRAFT', $order->fresh()->status);

        // Issue again
        $order->refresh();
        $order->update(['status' => 'ISSUED']);

        // 2. Record casting outcome
        $service = new \App\Services\SandCasting\SandCastingCastingResultService;
        $service->recordResult([
            'heat_number' => 'A214092688',
            'cast_date' => '2026-09-14',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 20,
                'qty_reject' => 0,
            ],
        ], $this->ppicUser->id);

        $this->assertEquals('PARTIALLY_COMPLETED', $order->fresh()->status);

        // 3. Attempting to revert to DRAFT now fails
        $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.update-status', $order), [
                'status' => 'DRAFT',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEquals('PARTIALLY_COMPLETED', $order->fresh()->status);
    }

    public function test_automatic_pcor_status_sync_and_outstanding_calculation_with_rejects(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $scPlan = ProductionPlan::create([
            'code' => 'SC-SYNC-01',
            'title' => 'Rencana Sync',
            'item_code' => '4.501',
            'item_name' => 'FLANGE SYNC 4"',
            'po_number' => 'PO-S01',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'weight' => 5.00,
            'line_number' => 2,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0501',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $scPlan->id,
            'qty_ordered' => 100,
            'code' => $scPlan->code,
            'item_name' => $scPlan->item_name,
        ]);

        $service = new \App\Services\SandCasting\SandCastingCastingResultService;

        // Heat 1: Good 80, Reject 5 -> Outstanding must be 20 (Reject does not reduce Good target!)
        $heat1 = $service->recordResult([
            'heat_number' => 'HEAT-SYNC-01',
            'cast_date' => '2026-09-14',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 80,
                'qty_reject' => 5,
            ],
        ], $this->ppicUser->id);

        $order->refresh();
        $line->refresh();
        $scPlan->refresh();

        $this->assertEquals('PARTIALLY_COMPLETED', $order->status);
        $this->assertEquals(80, $line->qty_cast_good);
        $this->assertEquals(5, $line->qty_cast_reject);
        $this->assertEquals(20, $line->qty_remaining_to_cast);
        $this->assertEquals(80.0, $line->progress_percent);
        $this->assertEquals('IN_PROGRESS', $line->execution_status);
        $this->assertEquals(80, $order->qty_cast_good);
        $this->assertEquals(5, $order->qty_cast_reject);
        $this->assertEquals(20, $order->qty_outstanding);
        $this->assertEquals(80.0, $order->progress_percent);
        // Under Model A: When order is PARTIALLY_COMPLETED, scheduled committed is still qty_ordered (100), keeping uncast outstanding locked
        $this->assertEquals(100, $scPlan->qty_casting_scheduled);
        $this->assertEquals(0, $scPlan->qty_remaining_casting_scheduled);

        // Heat 2: Good 20, Reject 2 -> Total Good 100, Total Reject 7, Outstanding 0 -> Auto COMPLETED
        $heat2 = $service->recordResult([
            'heat_number' => 'HEAT-SYNC-02',
            'cast_date' => '2026-09-15',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 20,
                'qty_reject' => 2,
            ],
        ], $this->ppicUser->id);

        $order->refresh();
        $line->refresh();
        $scPlan->refresh();

        $this->assertEquals('COMPLETED', $order->status);
        $this->assertEquals(100, $line->qty_cast_good);
        $this->assertEquals(7, $line->qty_cast_reject);
        $this->assertEquals(0, $line->qty_remaining_to_cast);
        $this->assertEquals(100.0, $line->progress_percent);
        $this->assertEquals('COMPLETED', $line->execution_status);
        // Quota must remain locked in COMPLETED
        $this->assertEquals(100, $scPlan->qty_casting_scheduled);
        $this->assertEquals(0, $scPlan->qty_remaining_casting_scheduled);

        // Recording a new Heat 3 against a COMPLETED PCOR must be rejected
        try {
            $service->recordResult([
                'heat_number' => 'HEAT-SYNC-03',
                'cast_date' => '2026-09-16',
            ], [
                [
                    'sand_casting_casting_order_line_id' => $line->id,
                    'qty_good' => 10,
                    'qty_reject' => 0,
                ],
            ], $this->ppicUser->id);
            $this->fail('Expected InvalidArgumentException when allocating new Heat to COMPLETED PCOR.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('sudah berstatus COMPLETED', $e->getMessage());
        }

        // Correcting Heat 2 down from 20 to 10 Good -> Auto reverts PCOR to PARTIALLY_COMPLETED
        $heat2Line = $heat2->lines->first();
        $service->correctResult(
            $heat2,
            [
                'heat_number' => 'HEAT-SYNC-02',
                'cast_date' => '2026-09-15',
                'reason' => 'Koreksi salah hitung good',
            ],
            [
                [
                    'id' => $heat2Line->id,
                    'sand_casting_casting_order_line_id' => $line->id,
                    'qty_good' => 10,
                    'qty_reject' => 2,
                ],
            ],
            $this->ppicUser->id
        );

        $order->refresh();
        $line->refresh();

        $this->assertEquals('PARTIALLY_COMPLETED', $order->status);
        $this->assertEquals(90, $line->qty_cast_good);
        $this->assertEquals(10, $line->qty_remaining_to_cast);
        $this->assertEquals(90.0, $line->progress_percent);
    }

    public function test_master_demand_plan_cannot_be_deleted_or_domain_switched_when_pcor_exists(): void
    {
        $scPlan = ProductionPlan::create([
            'code' => 'SC-GUARD-01',
            'title' => 'Rencana Guard',
            'item_code' => '4.601',
            'item_name' => 'FLANGE GUARD',
            'po_number' => 'PO-G01',
            'qty_planned' => 50,
            'qty_remaining' => 50,
            'line_number' => 1,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0601',
            'scheduled_date' => '2026-09-14',
            'status' => 'DRAFT',
            'created_by' => $this->ppicUser->id,
        ]);

        $order->lines()->create([
            'production_plan_id' => $scPlan->id,
            'qty_ordered' => 20,
            'code' => $scPlan->code,
            'item_name' => $scPlan->item_name,
        ]);

        // 1. Attempt to delete plan in Master Demand -> blocked
        $this->actingAs($this->ppicUser)
            ->delete(route('plan.destroy', $scPlan->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('production_plans', ['id' => $scPlan->id]);

        // 2. Attempt to switch domain to LOST_WAX -> blocked
        $this->actingAs($this->ppicUser)
            ->put(route('plan.update', $scPlan->id), [
                'code' => $scPlan->code,
                'title' => $scPlan->title,
                'item_code' => $scPlan->item_code,
                'item_name' => $scPlan->item_name,
                'po_number' => $scPlan->po_number,
                'qty_planned' => 50,
                'line_number' => 1,
                'status' => 'planning',
                'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEquals(ProductionPlan::DOMAIN_SAND_CASTING, $scPlan->fresh()->production_domain);
    }

    public function test_composition_calculator_customer_classification_and_create_handoff(): void
    {
        \App\Models\Customer::create([
            'name' => 'PT LOKAL MAKMUR',
            'category' => \App\Models\Customer::CATEGORY_LOCAL,
        ]);
        \App\Models\Customer::create([
            'name' => 'OVERSEAS TRADING CORP',
            'category' => \App\Models\Customer::CATEGORY_EXPORT,
        ]);

        // Verify exact classification rules (never guess from name)
        $map = \App\Models\Customer::getCategoryMap();
        $this->assertEquals('LOCAL', \App\Models\Customer::resolveCategory('STOK NON PO', $map));
        $this->assertEquals('EXPORT', \App\Models\Customer::resolveCategory('STOK EXP', $map));
        $this->assertEquals('LOCAL', \App\Models\Customer::resolveCategory('PT LOKAL MAKMUR', $map));
        $this->assertEquals('EXPORT', \App\Models\Customer::resolveCategory('OVERSEAS TRADING CORP', $map));
        $this->assertEquals('UNCLASSIFIED', \App\Models\Customer::resolveCategory('PT BELUM TERDAFTAR EXPORT', $map));
        $this->assertEquals('UNCLASSIFIED', \App\Models\Customer::resolveCategory(null, $map));

        $planLocal = ProductionPlan::create([
            'code' => 'SC-CALC-01',
            'title' => 'Rencana Stok Non PO',
            'item_code' => '4.701',
            'item_name' => 'SS304 FLANGE JIS 10K 2"',
            'po_number' => 'PO-C01',
            'qty_planned' => 20,
            'qty_remaining' => 20,
            'weight' => 3.50,
            'line_number' => 1,
            'customer' => 'STOK NON PO',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $planExport = ProductionPlan::create([
            'code' => 'SC-CALC-02',
            'title' => 'Rencana Stok Exp',
            'item_code' => '4.702',
            'item_name' => 'SS316L Y STRAINER JIS 10K 2 1/2"',
            'po_number' => 'PO-C02',
            'qty_planned' => 15,
            'qty_remaining' => 15,
            'weight' => 8.00,
            'line_number' => 2,
            'customer' => 'STOK EXP',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $planUnclassified = ProductionPlan::create([
            'code' => 'SC-CALC-03',
            'title' => 'Rencana Unclassified',
            'item_code' => '4.703',
            'item_name' => 'WCB GLOBE VALVE 3"',
            'po_number' => 'PO-C03',
            'qty_planned' => 10,
            'qty_remaining' => 10,
            'weight' => 12.00,
            'line_number' => 3,
            'customer' => 'CUSTOMER BARU',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $plansResponse = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.plans', ['tab' => 'plans']));

        $plansResponse->assertOk()
            ->assertSee('composition-calculator-panel', false)
            ->assertSee('calc-cust-local-pcs', false)
            ->assertSee('calc-cust-export-pcs', false)
            ->assertSee('calc-cust-unclassified-pcs', false)
            ->assertSee('calc-line-1-pcs', false)
            ->assertSee('calc-line-2-pcs', false)
            ->assertSee('calc-line-3-pcs', false)
            ->assertSee('calc-line-4-pcs', false)
            ->assertSee('data-category="LOCAL"', false)
            ->assertSee('data-category="EXPORT"', false)
            ->assertSee('data-category="UNCLASSIFIED"', false)
            ->assertSee('plan-line-select', false)
            ->assertSee('plan-qty-input', false);

        // Verify Create form receives custom selected_qty and selected_line tuned in calculator
        $createResponse = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.create', [
                'plan_ids' => [$planLocal->id, $planExport->id],
                'selected_qty' => [
                    $planLocal->id => 12,
                    $planExport->id => 8,
                ],
                'selected_line' => [
                    $planLocal->id => 4,
                    $planExport->id => 3,
                ],
            ]));

        $createResponse->assertOk()
            ->assertSee('value="12"', false)
            ->assertSee('value="8"', false)
            ->assertSee('<option value="4" selected>Line 4</option>', false)
            ->assertSee('<option value="3" selected>Line 3</option>', false);
    }

    public function test_pcor_monitoring_multi_date_filter_and_overcast_progress_aggregation(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $planA = ProductionPlan::create([
            'code' => 'SC-MON-01',
            'title' => 'Rencana Mon 1',
            'item_code' => '4.801',
            'item_name' => 'FLANGE MON 1',
            'po_number' => 'PO-M01',
            'qty_planned' => 50,
            'qty_remaining' => 50,
            'weight' => 4.00,
            'line_number' => 1,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $planB = ProductionPlan::create([
            'code' => 'SC-MON-02',
            'title' => 'Rencana Mon 2',
            'item_code' => '4.802',
            'item_name' => 'FLANGE MON 2',
            'po_number' => 'PO-M02',
            'qty_planned' => 50,
            'qty_remaining' => 50,
            'weight' => 5.00,
            'line_number' => 2,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        // Order 1 on 2026-10-18: Single-line 17 pcs ordered, 54 pcs cast good (over-cast 54 / 17 pcs -> 100%)
        $orderSingle = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261018-0001',
            'scheduled_date' => '2026-10-18',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);
        $lineSingle = $orderSingle->lines()->create([
            'production_plan_id' => $planA->id,
            'qty_ordered' => 17,
            'code' => $planA->code,
            'item_name' => $planA->item_name,
        ]);

        // Order 2 on 2026-10-19: Multi-line totaling 17 pcs (Line 1: 10 pcs, Line 2: 7 pcs)
        // Line 1 is over-cast to 54 pcs, Line 2 is 0 pcs -> Total displays 54 / 17 pcs, Ostd: 7, Progress: 58.8% (NOT 100%)
        $orderMulti = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261019-0002',
            'scheduled_date' => '2026-10-19',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);
        $lineMulti1 = $orderMulti->lines()->create([
            'production_plan_id' => $planA->id,
            'qty_ordered' => 10,
            'code' => $planA->code,
            'item_name' => $planA->item_name,
        ]);
        $lineMulti2 = $orderMulti->lines()->create([
            'production_plan_id' => $planB->id,
            'qty_ordered' => 7,
            'code' => $planB->code,
            'item_name' => $planB->item_name,
        ]);

        $service = new \App\Services\SandCasting\SandCastingCastingResultService;
        $res1 = \App\Models\SandCastingCastingResult::create([
            'heat_number' => 'HEAT-MON-01',
            'cast_date' => '2026-10-18',
            'recorded_by' => $this->ppicUser->id,
        ]);
        $res1->lines()->create([
            'sand_casting_casting_order_line_id' => $lineSingle->id,
            'production_plan_id' => $lineSingle->production_plan_id,
            'traveler_number' => 'KTR-TEST-MON-01',
            'qty_good' => 54,
            'qty_reject' => 0,
            'current_stage' => 'netto',
        ]);
        $service->syncCastingOrderStatus($orderSingle);

        $res2 = \App\Models\SandCastingCastingResult::create([
            'heat_number' => 'HEAT-MON-02',
            'cast_date' => '2026-10-19',
            'recorded_by' => $this->ppicUser->id,
        ]);
        $res2->lines()->create([
            'sand_casting_casting_order_line_id' => $lineMulti1->id,
            'production_plan_id' => $lineMulti1->production_plan_id,
            'traveler_number' => 'KTR-TEST-MON-02',
            'qty_good' => 54,
            'qty_reject' => 0,
            'current_stage' => 'netto',
        ]);
        $service->syncCastingOrderStatus($orderMulti);

        $orderSingle->refresh();
        $orderMulti->refresh();

        $this->assertEquals('COMPLETED', $orderSingle->status);
        $this->assertEquals(54, $orderSingle->qty_cast_good);
        $this->assertEquals(17, $orderSingle->qty_ordered);
        $this->assertEquals(0, $orderSingle->qty_outstanding);
        $this->assertEquals(100.0, $orderSingle->progress_percent);

        $this->assertEquals('PARTIALLY_COMPLETED', $orderMulti->status);
        $this->assertEquals(54, $orderMulti->qty_cast_good);
        $this->assertEquals(17, $orderMulti->qty_ordered);
        $this->assertEquals(7, $orderMulti->qty_outstanding);
        $this->assertEquals(58.8, $orderMulti->progress_percent);

        // 1. Without date filter, both dates appear in monitoring
        $allOrdersResp = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.plans', ['tab' => 'orders']));

        $allOrdersResp->assertOk()
            ->assertSee('PCOR-20261018-0001')
            ->assertSee('PCOR-20261019-0002')
            ->assertSee('54 / 17 pcs')
            ->assertSee('+37')
            ->assertSee('58.8%');

        // 2. With scheduled_date filter, only matching date appears
        $filteredResp = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.plans', [
                'tab' => 'orders',
                'scheduled_date' => '2026-10-18',
            ]));

        $filteredResp->assertOk()
            ->assertSee('PCOR-20261018-0001')
            ->assertDontSee('PCOR-20261019-0002');
    }

    public function test_print_pcor_layout_with_few_and_many_items(): void
    {
        $orderFew = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261102-0010',
            'scheduled_date' => '2026-11-02',
            'status' => 'ISSUED',
            'notes' => 'Prioritas pagi',
            'created_by' => $this->ppicUser->id,
        ]);

        for ($i = 1; $i <= 4; $i++) {
            $plan = ProductionPlan::create([
                'code' => "SC-PRN-F{$i}",
                'title' => "Rencana Print {$i}",
                'item_code' => "4.90{$i}",
                'item_name' => "SS316L Y STRAINER JIS 10K 2 1/2\" SERI {$i}",
                'po_number' => "PO-PF{$i}",
                'qty_planned' => 20,
                'qty_remaining' => 20,
                'weight' => 5.50,
                'line_number' => ($i % 4) + 1,
                'size' => '2 1/2"',
                'aisi' => 'SS316L',
                'customer' => 'STOK EXP',
                'product_scope' => 'FLANGE_BESI',
                'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
                'status' => 'planning',
            ]);

            $orderFew->lines()->create([
                'production_plan_id' => $plan->id,
                'line_number' => $plan->line_number,
                'qty_ordered' => 10,
                'code' => $plan->code,
                'item_name' => $plan->item_name,
                'size' => $plan->size,
                'aisi' => $plan->aisi,
                'customer' => $plan->customer,
                'notes' => "Catatan {$i}",
            ]);
        }

        $respFew = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.print', $orderFew));

        $respFew->assertOk()
            ->assertSee('size: A4 portrait', false)
            ->assertSee('table-layout: fixed', false)
            ->assertSee('Kode Customer')
            ->assertSee('Customer')
            ->assertSee('Nama Produk')
            ->assertSee('Ukuran')
            ->assertSee('AISI')
            ->assertSee('Qty Perintah')
            ->assertSee('Good')
            ->assertSee('Reject')
            ->assertSee('Keterangan')
            ->assertSee('SS316L Y STRAINER JIS 10K 2 1/2" SERI 1')
            ->assertSee('40 pcs')
            ->assertSee('220.00 kg')
            ->assertSee('Dibuat Oleh (PPIC)')
            ->assertSee('Disetujui (Kabag Cor Pasir)')
            ->assertSee('Diterima (SPV Cor)');

        // Test with 25 items (multi-page scenario)
        $orderMany = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261102-0025',
            'scheduled_date' => '2026-11-02',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        for ($i = 1; $i <= 25; $i++) {
            $plan = ProductionPlan::create([
                'code' => sprintf('SC-PRN-M%02d', $i),
                'title' => "Rencana Banyak {$i}",
                'item_code' => sprintf('4.95%02d', $i),
                'item_name' => "WCB GLOBE VALVE ANSI 150 4\" ITEM {$i}",
                'po_number' => sprintf('PO-PM%02d', $i),
                'qty_planned' => 10,
                'qty_remaining' => 10,
                'weight' => 2.00,
                'line_number' => 1,
                'size' => '4"',
                'aisi' => 'WCB',
                'customer' => 'STOK NON PO',
                'product_scope' => 'FLANGE_BESI',
                'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
                'status' => 'planning',
            ]);

            $orderMany->lines()->create([
                'production_plan_id' => $plan->id,
                'line_number' => 1,
                'qty_ordered' => 4,
                'code' => $plan->code,
                'item_name' => $plan->item_name,
                'size' => $plan->size,
                'aisi' => $plan->aisi,
                'customer' => $plan->customer,
            ]);
        }

        $respMany = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.print', $orderMany));

        $respMany->assertOk()
            ->assertSee('SC-PRN-M01')
            ->assertSee('SC-PRN-M25')
            ->assertSee('100 pcs')
            ->assertSee('200.00 kg')
            ->assertSee('display: table-header-group', false);
    }

    public function test_sticky_compact_calculator_autocollapse_and_three_page_selection_flow(): void
    {
        // Create 45 plans so pagination spans 3 pages (20 per page)
        $createdPlans = [];
        for ($i = 1; $i <= 45; $i++) {
            $createdPlans[$i] = ProductionPlan::create([
                'code' => sprintf('SC-PG-%02d', $i),
                'title' => "Rencana Halaman {$i}",
                'item_code' => sprintf('4.60%02d', $i),
                'item_name' => "FLANGE MULTI PAGE {$i}",
                'po_number' => sprintf('PO-PG-%02d', $i),
                'qty_planned' => 500,
                'qty_remaining' => 500,
                'weight' => 10.00,
                'line_number' => 1,
                'customer' => ($i % 2 === 0) ? 'STOK EXP' : 'STOK NON PO',
                'product_scope' => 'FLANGE_BESI',
                'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
                'status' => 'planning',
            ]);
        }

        // Verify Page 1, Page 2, and Page 3 render sticky compact calculator with default collapsed drawer
        $page1Resp = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.plans', ['tab' => 'plans', 'plans_page' => 1]));

        $page1Resp->assertOk()
            ->assertSee('id="composition-calculator-panel"', false)
            ->assertSee('position: sticky;', false)
            ->assertSee('id="toggle-composition-btn" aria-expanded="false"', false)
            ->assertSee('id="composition-details-drawer" class="hidden', false)
            ->assertSee('Rincian Komposisi')
            ->assertSee('id="quota-warning-banner"', false);

        // Ensure there is only ONE selection-summary-bar (no duplicate sticky header)
        $this->assertEquals(1, substr_count($page1Resp->getContent(), 'id="selection-summary-bar"'));

        // Select 1 item from Page 1 (120 pcs * 10 kg = 1,200 kg, Line 1),
        // 1 item from Page 2 (50 pcs * 10 kg = 500 kg, Line 2),
        // 1 item from Page 3 (280 pcs * 10 kg = 2,800 kg, Line 3) -> Total = 450 pcs, 4,500.00 kg
        $p1Plan = $createdPlans[45]; // newest on page 1
        $p2Plan = $createdPlans[25]; // on page 2
        $p3Plan = $createdPlans[1];  // oldest on page 3

        $createResp = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.create', [
                'plan_ids' => [$p1Plan->id, $p2Plan->id, $p3Plan->id],
                'selected_qty' => [
                    $p1Plan->id => 120,
                    $p2Plan->id => 50,
                    $p3Plan->id => 280,
                ],
                'selected_line' => [
                    $p1Plan->id => 1,
                    $p2Plan->id => 2,
                    $p3Plan->id => 3,
                ],
            ]));

        $createResp->assertOk()
            ->assertSee($p1Plan->code)
            ->assertSee($p2Plan->code)
            ->assertSee($p3Plan->code)
            ->assertSee('1,200.00 kg')
            ->assertSee('500.00 kg')
            ->assertSee('2,800.00 kg')
            ->assertDontSee('onsubmit="sessionStorage.removeItem', false);

        // Simulate failed store (duplicate casting_order_number) -> must NOT flash clear_pcor_selection
        SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-DUP-0001',
            'scheduled_date' => '2026-11-02',
            'status' => 'DRAFT',
            'created_by' => $this->ppicUser->id,
        ]);

        $failStoreResp = $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.store'), [
                'casting_order_number' => 'PCOR-DUP-0001',
                'scheduled_date' => '2026-11-02',
                'items' => [
                    ['production_plan_id' => $p1Plan->id, 'qty_ordered' => 120, 'line_number' => 1],
                    ['production_plan_id' => $p2Plan->id, 'qty_ordered' => 50, 'line_number' => 2],
                    ['production_plan_id' => $p3Plan->id, 'qty_ordered' => 280, 'line_number' => 3],
                ],
            ]);

        $failStoreResp->assertSessionHasErrors('casting_order_number');
        $failStoreResp->assertSessionMissing('clear_pcor_selection');

        // Simulate successful store -> flashes clear_pcor_selection and persists all 3 lines with their custom Qty & Line
        $successStoreResp = $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.store'), [
                'casting_order_number' => 'PCOR-20261102-0099',
                'scheduled_date' => '2026-11-02',
                'items' => [
                    ['production_plan_id' => $p1Plan->id, 'qty_ordered' => 120, 'line_number' => 1],
                    ['production_plan_id' => $p2Plan->id, 'qty_ordered' => 50, 'line_number' => 2],
                    ['production_plan_id' => $p3Plan->id, 'qty_ordered' => 280, 'line_number' => 3],
                ],
            ]);

        $successStoreResp->assertRedirect();
        $successStoreResp->assertSessionHas('clear_pcor_selection', true);

        $createdOrder = SandCastingCastingOrder::where('casting_order_number', 'PCOR-20261102-0099')->firstOrFail();
        $this->assertEquals(3, $createdOrder->lines()->count());
        $this->assertEquals(450, $createdOrder->qty_ordered);
        $this->assertEquals(2, $p2Plan->fresh()->line_number);
        $this->assertEquals(3, $p3Plan->fresh()->line_number);
    }

    public function test_pcor_finished_items_excluded_from_new_plans_and_outstanding_carryover(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $service = app(\App\Services\SandCasting\SandCastingCastingResultService::class);

        // 4 items matching the prompt scenario
        $plan1 = ProductionPlan::create([
            'code' => '2610S850',
            'title' => 'Flange 2610S850',
            'item_code' => '2610S850',
            'item_name' => 'SS304 SORF ANSI 300LBS 2"',
            'po_number' => 'PO-SC-01',
            'qty_planned' => 300,
            'qty_remaining' => 300,
            'line_number' => 1,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);
        $plan2 = ProductionPlan::create([
            'code' => '269L183',
            'title' => 'Flange 269L183',
            'item_code' => '269L183',
            'item_name' => 'Flange 269L183',
            'po_number' => 'PO-SC-02',
            'qty_planned' => 22,
            'qty_remaining' => 22,
            'line_number' => 2,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);
        $plan3 = ProductionPlan::create([
            'code' => '2610L005',
            'title' => 'Flange 2610L005',
            'item_code' => '2610L005',
            'item_name' => 'Flange 2610L005',
            'po_number' => 'PO-SC-03',
            'qty_planned' => 16,
            'qty_remaining' => 16,
            'line_number' => 3,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);
        $plan4 = ProductionPlan::create([
            'code' => '2610L146',
            'title' => 'Flange 2610L146',
            'item_code' => '2610L146',
            'item_name' => 'Flange 2610L146',
            'po_number' => 'PO-SC-04',
            'qty_planned' => 22,
            'qty_remaining' => 22,
            'line_number' => 4,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        // Create PCOR-20261031-0002 for 360 pcs total
        $createResp = $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.store'), [
                'casting_order_number' => 'PCOR-20261031-0002',
                'scheduled_date' => '2026-10-31',
                'items' => [
                    ['production_plan_id' => $plan1->id, 'qty_ordered' => 300, 'line_number' => 1],
                    ['production_plan_id' => $plan2->id, 'qty_ordered' => 22, 'line_number' => 2],
                    ['production_plan_id' => $plan3->id, 'qty_ordered' => 16, 'line_number' => 3],
                    ['production_plan_id' => $plan4->id, 'qty_ordered' => 22, 'line_number' => 4],
                ],
            ]);
        $createResp->assertRedirect();

        $pcor = SandCastingCastingOrder::where('casting_order_number', 'PCOR-20261031-0002')->with('lines')->firstOrFail();
        $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.update-status', $pcor), [
                'status' => 'ISSUED',
            ])->assertRedirect();
        $line1 = $pcor->lines->firstWhere('production_plan_id', $plan1->id);
        $line2 = $pcor->lines->firstWhere('production_plan_id', $plan2->id);
        $line3 = $pcor->lines->firstWhere('production_plan_id', $plan3->id);
        $line4 = $pcor->lines->firstWhere('production_plan_id', $plan4->id);

        // Record Heat 1:
        // Item 1: 60 Good, 0 Defect
        // Item 2: 22 Good, 0 Defect (reaches quota)
        // Item 3: 16 Good, 0 Defect (reaches quota)
        // Item 4: 22 Good, 0 Defect (reaches quota)
        $service->recordResult([
            'heat_number' => 'HEAT-20261031-01',
            'cast_date' => '2026-10-31',
        ], [
            ['sand_casting_casting_order_line_id' => $line1->id, 'qty_good' => 60, 'qty_reject' => 0],
            ['sand_casting_casting_order_line_id' => $line2->id, 'qty_good' => 22, 'qty_reject' => 0],
            ['sand_casting_casting_order_line_id' => $line3->id, 'qty_good' => 16, 'qty_reject' => 0],
            ['sand_casting_casting_order_line_id' => $line4->id, 'qty_good' => 22, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        // Record Heat 2:
        // Item 1: 40 Good, 0 Defect -> Total 100 Good, Outstanding 200
        $service->recordResult([
            'heat_number' => 'HEAT-20261031-02',
            'cast_date' => '2026-10-31',
        ], [
            ['sand_casting_casting_order_line_id' => $line1->id, 'qty_good' => 40, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $pcor->refresh();
        $line1->refresh();
        $line2->refresh();
        $line3->refresh();
        $line4->refresh();

        // 1. Verify PCOR status and lines status
        $this->assertEquals('PARTIALLY_COMPLETED', $pcor->status);
        $this->assertEquals(100, $line1->qty_cast_good);
        $this->assertEquals(200, $line1->qty_remaining_to_cast);
        $this->assertEquals('IN_PROGRESS', $line1->execution_status);

        $this->assertEquals(22, $line2->qty_cast_good);
        $this->assertEquals(0, $line2->qty_remaining_to_cast);
        $this->assertEquals('COMPLETED', $line2->execution_status);

        $this->assertEquals(16, $line3->qty_cast_good);
        $this->assertEquals(0, $line3->qty_remaining_to_cast);
        $this->assertEquals('COMPLETED', $line3->execution_status);

        $this->assertEquals(22, $line4->qty_cast_good);
        $this->assertEquals(0, $line4->qty_remaining_to_cast);
        $this->assertEquals('COMPLETED', $line4->execution_status);

        // 2. Detail PCOR (show) must preserve all 4 original items and historical quantities
        $showResp = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.show', $pcor->id));
        $showResp->assertOk()
            ->assertSee('2610S850')
            ->assertSee('269L183')
            ->assertSee('2610L005')
            ->assertSee('2610L146');

        // Print PCOR must only show items with Outstanding > 0 (2610S850 with 200 PCS) and hide finished items
        $printResp = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.print', $pcor->id));
        $printResp->assertOk()
            ->assertSee('size: A4 portrait', false)
            ->assertSee('Qty Perintah (Sisa)')
            ->assertSee('2610S850')
            ->assertSee('200 pcs')
            ->assertDontSee('269L183')
            ->assertDontSee('2610L005')
            ->assertDontSee('2610L146');

        // 3. Condition B under Model A: Rencana Cor list (/sand-casting/casting-orders/plans?tab=plans)
        // Must exclude all items because plan1's 300 PCS is fully locked by the PARTIALLY_COMPLETED PCOR
        $plansResp = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.plans', ['tab' => 'plans']));
        $plansResp->assertOk();

        $activePlans = $plansResp->viewData('plans');
        $planIdsInView = $activePlans->pluck('id')->toArray();

        // Model A: plan1 remains locked until PCOR completes; it does NOT release uncast quota to plans view
        $this->assertNotContains($plan1->id, $planIdsInView);
        $this->assertNotContains($plan2->id, $planIdsInView);
        $this->assertNotContains($plan3->id, $planIdsInView);
        $this->assertNotContains($plan4->id, $planIdsInView);

        $plan1->refresh();
        $this->assertEquals(0, $plan1->qty_remaining_casting_scheduled);

        // 4. Attempting to create new PCOR for plan1 must fail because its quota is locked by PCOR-20261031-0002
        $failStore = $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.store'), [
                'casting_order_number' => 'PCOR-20261101-0001',
                'scheduled_date' => '2026-11-01',
                'items' => [
                    ['production_plan_id' => $plan1->id, 'qty_ordered' => 200, 'line_number' => 1],
                ],
            ]);
        $failStore->assertStatus(422);

        // 5. Subsequent casting results continue on the SAME PCOR until completed
        $service->recordResult([
            'heat_number' => 'HEAT-20261031-03',
            'cast_date' => '2026-11-01',
        ], [
            ['sand_casting_casting_order_line_id' => $line1->id, 'qty_good' => 200, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $pcor->refresh();
        $line1->refresh();
        $this->assertEquals('COMPLETED', $pcor->status);
        $this->assertEquals(300, $line1->qty_cast_good);
        $this->assertEquals(0, $line1->qty_remaining_to_cast);
    }

    public function test_print_pcor_only_shows_outstanding_and_handles_all_finished_state(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $service = app(\App\Services\SandCasting\SandCastingCastingResultService::class);

        $plan1 = ProductionPlan::create([
            'code' => '2610S850',
            'title' => 'Flange 2610S850',
            'item_code' => '2610S850',
            'item_name' => 'SS304 SORF ANSI 300LBS 2"',
            'po_number' => 'PO-PR-01',
            'qty_planned' => 300,
            'qty_remaining' => 300,
            'weight' => 2.50,
            'line_number' => 1,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);
        $plan2 = ProductionPlan::create([
            'code' => '269L183',
            'title' => 'Flange 269L183',
            'item_code' => '269L183',
            'item_name' => 'Flange 269L183',
            'po_number' => 'PO-PR-02',
            'qty_planned' => 22,
            'qty_remaining' => 22,
            'weight' => 1.00,
            'line_number' => 2,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);
        $plan3 = ProductionPlan::create([
            'code' => '2610L005',
            'title' => 'Flange 2610L005',
            'item_code' => '2610L005',
            'item_name' => 'Flange 2610L005',
            'po_number' => 'PO-PR-03',
            'qty_planned' => 16,
            'qty_remaining' => 16,
            'weight' => 1.00,
            'line_number' => 3,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);
        $plan4 = ProductionPlan::create([
            'code' => '2610L146',
            'title' => 'Flange 2610L146',
            'item_code' => '2610L146',
            'item_name' => 'Flange 2610L146',
            'po_number' => 'PO-PR-04',
            'qty_planned' => 22,
            'qty_remaining' => 22,
            'weight' => 1.00,
            'line_number' => 4,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261031-0002',
            'scheduled_date' => '2026-10-31',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $l1 = $order->lines()->create(['production_plan_id' => $plan1->id, 'line_number' => 1, 'qty_ordered' => 300, 'code' => $plan1->code, 'item_name' => $plan1->item_name]);
        $l2 = $order->lines()->create(['production_plan_id' => $plan2->id, 'line_number' => 2, 'qty_ordered' => 22, 'code' => $plan2->code, 'item_name' => $plan2->item_name]);
        $l3 = $order->lines()->create(['production_plan_id' => $plan3->id, 'line_number' => 3, 'qty_ordered' => 16, 'code' => $plan3->code, 'item_name' => $plan3->item_name]);
        $l4 = $order->lines()->create(['production_plan_id' => $plan4->id, 'line_number' => 4, 'qty_ordered' => 22, 'code' => $plan4->code, 'item_name' => $plan4->item_name]);

        // Case 1: All items uncast -> all 4 items appear with full ordered quantities (360 pcs total)
        $initialPrint = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.print', $order));
        $initialPrint->assertOk()
            ->assertSee('size: A4 portrait', false)
            ->assertSee('Qty Perintah (Sisa)')
            ->assertSee('2610S850')
            ->assertSee('269L183')
            ->assertSee('2610L005')
            ->assertSee('2610L146')
            ->assertSee('360 pcs');

        // Record Heat 1 & Heat 2:
        // l1: 60 + 40 = 100 Good, 5 Reject -> Outstanding 200
        // l2: 44 Good (exceeds target 22) -> Outstanding 0
        // l3: 40 Good (exceeds target 16) -> Outstanding 0
        // l4: 22 Good (reaches target 22) -> Outstanding 0
        $service->recordResult([
            'heat_number' => 'HEAT-PR-01',
            'cast_date' => '2026-10-31',
        ], [
            ['sand_casting_casting_order_line_id' => $l1->id, 'qty_good' => 60, 'qty_reject' => 3],
            ['sand_casting_casting_order_line_id' => $l2->id, 'qty_good' => 22, 'qty_reject' => 0],
            ['sand_casting_casting_order_line_id' => $l3->id, 'qty_good' => 16, 'qty_reject' => 0],
            ['sand_casting_casting_order_line_id' => $l4->id, 'qty_good' => 22, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $service->recordResult([
            'heat_number' => 'HEAT-PR-02',
            'cast_date' => '2026-10-31',
        ], [
            ['sand_casting_casting_order_line_id' => $l1->id, 'qty_good' => 40, 'qty_reject' => 2],
        ], $this->ppicUser->id);

        // Cases 2, 3, 4, 5, 8: Partial Good shows remaining 200 PCS & actual Good/Reject info; finished/overcast items hidden
        $partialPrint = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.print', $order));
        $partialPrint->assertOk()
            ->assertSee('size: A4 portrait', false)
            ->assertSee('2610S850')
            ->assertSee('200')
            ->assertSee('200 pcs')
            ->assertSee('500.00 kg') // 200 pcs * 2.50 kg
            ->assertSee('Aktual G:100 R:5 / Awal:300')
            ->assertDontSee('269L183')
            ->assertDontSee('2610L005')
            ->assertDontSee('2610L146');

        // Case 7: Print does not mutate historical PCOR lines or Heat Number results
        $this->assertEquals(300, $l1->fresh()->qty_ordered);
        $this->assertEquals(22, $l2->fresh()->qty_ordered);
        $this->assertEquals(16, $l3->fresh()->qty_ordered);
        $this->assertEquals(22, $l4->fresh()->qty_ordered);
        $this->assertEquals(100, $l1->fresh()->qty_cast_good);
        $this->assertEquals(5, $l1->fresh()->qty_cast_reject);

        // Record Heat 3 to finish the remaining 200 pcs of l1
        $service->recordResult([
            'heat_number' => 'HEAT-PR-03',
            'cast_date' => '2026-10-31',
        ], [
            ['sand_casting_casting_order_line_id' => $l1->id, 'qty_good' => 200, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        // Case 6: All items finished (Outstanding = 0) -> show empty message and do not render empty table
        $completedPrint = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.print', $order));
        $completedPrint->assertOk()
            ->assertSee('size: A4 portrait', false)
            ->assertSee('Tidak ada outstanding pengecoran pada PCOR ini')
            ->assertDontSee('<table class="pcor-print-table', false)
            ->assertDontSee('2610S850');
    }

    public function test_print_pcor_document_info_and_balance_summary_tables(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::create(2026, 11, 2, 18, 4, 0));

        $service = app(\App\Services\SandCasting\SandCastingCastingResultService::class);

        // Master customers: PT LOKAL JAYA => LOCAL, GLOBAL EXPORT INC => EXPORT
        // PT BELUM TERDAFTAR => not in master customers => UNCLASSIFIED
        \App\Models\Customer::create(['name' => 'PT LOKAL JAYA', 'category' => 'LOCAL', 'is_active' => true]);
        \App\Models\Customer::create(['name' => 'GLOBAL EXPORT INC', 'category' => 'EXPORT', 'is_active' => true]);

        $creator = User::factory()->create([
            'name' => 'PPIC Flange',
            'email' => 'ppic_creator@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $creator->assignRole('ppic');

        // Plan 1: LOCAL, Line 1, Ordered 100, Good 40 -> Outstanding 60 PCS * 2.50 kg = 150.00 kg
        $planLocal = ProductionPlan::create([
            'code' => 'BAL-LOC-01',
            'title' => 'Item Local',
            'item_code' => '4.701',
            'item_name' => 'FLANGE LOCAL 2"',
            'po_number' => 'PO-BAL-1',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'weight' => 2.50,
            'line_number' => 1,
            'customer' => 'PT LOKAL JAYA',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        // Plan 2: EXPORT, Line 2, Ordered 50, Good 0 -> Outstanding 50 PCS * 3.20 kg = 160.00 kg
        $planExport = ProductionPlan::create([
            'code' => 'BAL-EXP-02',
            'title' => 'Item Export',
            'item_code' => '4.702',
            'item_name' => 'FLANGE EXPORT 3"',
            'po_number' => 'PO-BAL-2',
            'qty_planned' => 50,
            'qty_remaining' => 50,
            'weight' => 3.20,
            'line_number' => 2,
            'customer' => 'GLOBAL EXPORT INC',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        // Plan 3: UNCLASSIFIED (even though name contains "EXPORT", not in master customer -> must not guess!), Line 4, Ordered 30, Good 10 -> Outstanding 20 PCS * 1.50 kg = 30.00 kg
        $planUnclassified = ProductionPlan::create([
            'code' => 'BAL-UNC-03',
            'title' => 'Item Unclassified',
            'item_code' => '4.703',
            'item_name' => 'FLANGE UNCLASSIFIED 4"',
            'po_number' => 'PO-BAL-3',
            'qty_planned' => 30,
            'qty_remaining' => 30,
            'weight' => 1.50,
            'line_number' => 4,
            'customer' => 'PT EXPORT TAPI BELUM MASTER',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        // Plan 4: Finished item (Ordered 25, Good 25 -> Outstanding 0), Line 3 -> must NOT be counted in print balance
        $planFinished = ProductionPlan::create([
            'code' => 'BAL-FIN-04',
            'title' => 'Item Selesai',
            'item_code' => '4.704',
            'item_name' => 'FLANGE SELESAI 1"',
            'po_number' => 'PO-BAL-4',
            'qty_planned' => 25,
            'qty_remaining' => 25,
            'weight' => 4.00,
            'line_number' => 3,
            'customer' => 'PT LOKAL JAYA',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261031-0099',
            'scheduled_date' => '2026-10-31',
            'status' => 'ISSUED',
            'notes' => 'Catatan khusus pengecoran shift pagi',
            'created_by' => $creator->id,
        ]);

        $lLocal = $order->lines()->create(['production_plan_id' => $planLocal->id, 'qty_ordered' => 100, 'code' => $planLocal->code, 'customer' => $planLocal->customer, 'item_name' => $planLocal->item_name]);
        $lExport = $order->lines()->create(['production_plan_id' => $planExport->id, 'qty_ordered' => 50, 'code' => $planExport->code, 'customer' => $planExport->customer, 'item_name' => $planExport->item_name]);
        $lUnclass = $order->lines()->create(['production_plan_id' => $planUnclassified->id, 'qty_ordered' => 30, 'code' => $planUnclassified->code, 'customer' => $planUnclassified->customer, 'item_name' => $planUnclassified->item_name]);
        $lFinished = $order->lines()->create(['production_plan_id' => $planFinished->id, 'qty_ordered' => 25, 'code' => $planFinished->code, 'customer' => $planFinished->customer, 'item_name' => $planFinished->item_name]);

        // Record Heat 1: Local Good 40, Unclassified Good 10, Finished Good 25
        $service->recordResult([
            'heat_number' => 'HEAT-BAL-01',
            'cast_date' => '2026-10-31',
        ], [
            ['sand_casting_casting_order_line_id' => $lLocal->id, 'qty_good' => 40, 'qty_reject' => 0],
            ['sand_casting_casting_order_line_id' => $lUnclass->id, 'qty_good' => 10, 'qty_reject' => 0],
            ['sand_casting_casting_order_line_id' => $lFinished->id, 'qty_good' => 25, 'qty_reject' => 0],
        ], $creator->id);

        $resp = $this->actingAs($creator)
            ->get(route('sand-casting.casting-orders.print', $order));

        $resp->assertOk();

        // 1. Document info in header (DD/MM/YYYY, creator, DD/MM/YYYY HH:mm, notes)
        $resp->assertSee('Tgl Rencana Cor:')
            ->assertSee('31/10/2026')
            ->assertSee('Dibuat Oleh:')
            ->assertSee('PPIC Flange')
            ->assertSee('Tgl Cetak Dokumen:')
            ->assertSee('02/11/2026 18:04')
            ->assertSee('Catatan:')
            ->assertSee('Catatan khusus pengecoran shift pagi');

        // 2 & 3 & 4. Verify balance summary data from viewData
        $balance = $resp->viewData('balanceSummary');
        $this->assertNotNull($balance);

        // Category breakdown (strictly from Outstanding: Local 60 pcs / 150 kg, Export 50 pcs / 160 kg, Unclassified 20 pcs / 30 kg)
        $this->assertEquals(60, $balance['categories']['LOCAL']['pcs']);
        $this->assertEquals(150.00, $balance['categories']['LOCAL']['kg']);
        $this->assertEquals(50, $balance['categories']['EXPORT']['pcs']);
        $this->assertEquals(160.00, $balance['categories']['EXPORT']['kg']);
        $this->assertEquals(20, $balance['categories']['UNCLASSIFIED']['pcs']);
        $this->assertEquals(30.00, $balance['categories']['UNCLASSIFIED']['kg']);
        $this->assertEquals(130, $balance['category_total_pcs']);
        $this->assertEquals(340.00, $balance['category_total_kg']);

        // Line breakdown (Line 1: 60 pcs / 150 kg, Line 2: 50 pcs / 160 kg, Line 3: 0 pcs / 0 kg, Line 4: 20 pcs / 30 kg)
        $this->assertEquals(60, $balance['lines'][1]['pcs']);
        $this->assertEquals(150.00, $balance['lines'][1]['kg']);
        $this->assertEquals(50, $balance['lines'][2]['pcs']);
        $this->assertEquals(160.00, $balance['lines'][2]['kg']);
        $this->assertEquals(0, $balance['lines'][3]['pcs']);
        $this->assertEquals(0.00, $balance['lines'][3]['kg']);
        $this->assertEquals(20, $balance['lines'][4]['pcs']);
        $this->assertEquals(30.00, $balance['lines'][4]['kg']);
        $this->assertEquals(130, $balance['line_total_pcs']);
        $this->assertEquals(340.00, $balance['line_total_kg']);

        $resp->assertSee('LOCAL')
            ->assertSee('EXPORT')
            ->assertSee('UNCLASSIFIED')
            ->assertSee('TOTAL KATEGORI')
            ->assertSee('LINE 1')
            ->assertSee('LINE 2')
            ->assertSee('LINE 3')
            ->assertSee('LINE 4')
            ->assertSee('TOTAL LINE')
            ->assertSee('150.00')
            ->assertSee('160.00')
            ->assertSee('30.00')
            ->assertSee('340.00')
            ->assertDontSee('STATUS: BALANCE')
            ->assertDontSee('STATUS: TIDAK BALANCE');

        // Verify DOM order: Header -> Info -> Table -> Rekap Kategori -> Rekap Line -> Signature
        $html = $resp->getContent();
        $posHeader = strpos($html, 'SURAT PERINTAH COR PASIR');
        $posInfo = strpos($html, 'Tgl Rencana Cor:');
        $posTable = strpos($html, '<table class="pcor-print-table');
        $posRekapKat = strpos($html, 'TOTAL KATEGORI');
        $posRekapLine = strpos($html, 'TOTAL LINE');
        $posSignature = strpos($html, 'Disetujui (Kabag Cor Pasir)');

        $this->assertTrue($posHeader < $posInfo);
        $this->assertTrue($posInfo < $posTable);
        $this->assertTrue($posTable < $posRekapKat);
        $this->assertTrue($posRekapKat < $posRekapLine);
        $this->assertTrue($posRekapLine < $posSignature);

        // 5. Complete all remaining items -> PCOR with no Outstanding shows empty message AND consistent 0 balance tables
        $service->recordResult([
            'heat_number' => 'HEAT-BAL-02',
            'cast_date' => '2026-10-31',
        ], [
            ['sand_casting_casting_order_line_id' => $lLocal->id, 'qty_good' => 60, 'qty_reject' => 0],
            ['sand_casting_casting_order_line_id' => $lExport->id, 'qty_good' => 50, 'qty_reject' => 0],
            ['sand_casting_casting_order_line_id' => $lUnclass->id, 'qty_good' => 20, 'qty_reject' => 0],
        ], $creator->id);

        $zeroResp = $this->actingAs($creator)
            ->get(route('sand-casting.casting-orders.print', $order));
        $zeroResp->assertOk()
            ->assertSee('Tidak ada outstanding pengecoran pada PCOR ini')
            ->assertSee('TOTAL KATEGORI')
            ->assertSee('TOTAL LINE')
            ->assertDontSee('STATUS: BALANCE')
            ->assertDontSee('STATUS: TIDAK BALANCE');

        $zeroBalance = $zeroResp->viewData('balanceSummary');
        $this->assertEquals(0, $zeroBalance['category_total_pcs']);
        $this->assertEquals(0.0, $zeroBalance['category_total_kg']);
        $this->assertEquals(0, $zeroBalance['line_total_pcs']);
        $this->assertEquals(0.0, $zeroBalance['line_total_kg']);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_master_customer_crud_validation_delete_protection_and_pcor_integration(): void
    {
        // 1. Open Master Customer page
        $indexResp = $this->actingAs($this->ppicUser)
            ->get(route('settings.customers.index'));
        $indexResp->assertOk()
            ->assertSee('Master Customer');

        // 2. Register new customer (LOCAL)
        $storeResp = $this->actingAs($this->ppicUser)
            ->post(route('settings.customers.store'), [
                'name' => '  PT NUSANTARA LOGAM  ',
                'category' => 'LOCAL',
                'is_active' => 1,
            ]);
        $storeResp->assertRedirect(route('settings.customers.index'));

        $customerLocal = \App\Models\Customer::where('name', 'PT NUSANTARA LOGAM')->first();
        $this->assertNotNull($customerLocal);
        $this->assertEquals('LOCAL', $customerLocal->category);
        $this->assertTrue($customerLocal->is_active);

        // 3. Reject duplicate customer name (case-insensitive)
        $dupResp = $this->actingAs($this->ppicUser)
            ->post(route('settings.customers.store'), [
                'name' => 'pt nusantara logam',
                'category' => 'EXPORT',
            ]);
        $dupResp->assertSessionHasErrors('name');

        // 4. Reject missing category when registering new customer (UNCLASSIFIED is not a valid choice for new registration)
        $noCatResp = $this->actingAs($this->ppicUser)
            ->post(route('settings.customers.store'), [
                'name' => 'PT TANPA KATEGORI',
                'category' => '',
            ]);
        $noCatResp->assertSessionHasErrors('category');

        // 5. Edit existing customer (including legacy customer without category)
        $legacyCustomer = \App\Models\Customer::create([
            'name' => 'PT LEGACY CUSTOMER',
            'category' => null,
            'is_active' => true,
        ]);

        // Inline category update (only category sent)
        $inlineUpdateResp = $this->actingAs($this->ppicUser)
            ->put(route('settings.customers.update', $legacyCustomer), [
                'category' => 'EXPORT',
            ]);
        $inlineUpdateResp->assertRedirect(route('settings.customers.index'));
        $this->assertEquals('EXPORT', $legacyCustomer->fresh()->category);

        // Full edit (name, category, is_active)
        $fullUpdateResp = $this->actingAs($this->ppicUser)
            ->put(route('settings.customers.update', $legacyCustomer), [
                'name' => 'PT GLOBAL EXPORT MANDIRI',
                'category' => 'EXPORT',
                'is_active' => 0,
            ]);
        $fullUpdateResp->assertRedirect(route('settings.customers.index'));
        $legacyCustomer->refresh();
        $this->assertEquals('PT GLOBAL EXPORT MANDIRI', $legacyCustomer->name);
        $this->assertEquals('EXPORT', $legacyCustomer->category);
        $this->assertFalse($legacyCustomer->is_active);

        // 6. Search and filter on Master Customer page
        session()->forget('success');
        $searchResp = $this->actingAs($this->ppicUser)
            ->get(route('settings.customers.index', ['search' => 'NUSANTARA', 'category' => 'LOCAL', 'status' => 'active']));
        $searchResp->assertOk()
            ->assertSee('PT NUSANTARA LOGAM')
            ->assertDontSee('PT GLOBAL EXPORT MANDIRI');
        $this->assertCount(1, $searchResp->viewData('customers'));

        // 7. Delete protection when customer is used in ProductionPlan
        $plan = ProductionPlan::create([
            'code' => 'CUST-INT-01',
            'title' => 'Rencana Nusantara',
            'item_code' => '4.991',
            'item_name' => 'FLANGE NUSANTARA 4"',
            'po_number' => 'PO-NUS-01',
            'qty_planned' => 40,
            'qty_remaining' => 40,
            'weight' => 3.00,
            'line_number' => 2,
            'customer' => 'PT NUSANTARA LOGAM',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $deleteUsedResp = $this->actingAs($this->ppicUser)
            ->delete(route('settings.customers.destroy', $customerLocal));
        $deleteUsedResp->assertRedirect(route('settings.customers.index'))
            ->assertSessionHas('error');
        $this->assertNotNull(\App\Models\Customer::find($customerLocal->id));

        // Unused customer can be deleted
        $unusedCustomer = \App\Models\Customer::create([
            'name' => 'PT BELUM PAKAI',
            'category' => 'LOCAL',
            'is_active' => true,
        ]);
        $deleteUnusedResp = $this->actingAs($this->ppicUser)
            ->delete(route('settings.customers.destroy', $unusedCustomer));
        $deleteUnusedResp->assertRedirect(route('settings.customers.index'))
            ->assertSessionHas('success');
        $this->assertNull(\App\Models\Customer::find($unusedCustomer->id));

        // 8. Verify integration with Composition Calculator & Print PCOR
        // Change PT NUSANTARA LOGAM to EXPORT and verify calculator & print PCOR reflect EXPORT
        $this->actingAs($this->ppicUser)
            ->put(route('settings.customers.update', $customerLocal), [
                'name' => 'PT NUSANTARA LOGAM',
                'category' => 'EXPORT',
                'is_active' => 1,
            ]);

        $plansPageResp = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.plans', ['tab' => 'plans']));
        $plansPageResp->assertOk()
            ->assertSee('data-category="EXPORT"', false);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261102-0777',
            'scheduled_date' => '2026-11-02',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);
        $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 40,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
        ]);

        $printResp = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.print', $order));
        $printResp->assertOk();
        $balance = $printResp->viewData('balanceSummary');
        $this->assertEquals(40, $balance['categories']['EXPORT']['pcs']);
        $this->assertEquals(120.00, $balance['categories']['EXPORT']['kg']);
    }
}
