<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxItemReference;
use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrder;
use App\Models\LostWaxWorkOrderPlan;
use App\Models\LostWaxWorkOrderWip;
use App\Models\User;
use App\Services\TreeGenerationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TreeTest extends TestCase
{
    use RefreshDatabase;

    private TreeGenerationService $treeService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->treeService = new TreeGenerationService;
    }

    public function test_tree_belongs_to_correct_work_order_plan(): void
    {
        $workOrder = $this->createWorkOrderWithAssemblyOutput(450);
        $plan = $this->createPlan($workOrder);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan), [
                'default_qty' => 15,
                'quantities' => array_fill(0, 30, 15),
                'family_code' => '1',
            ])
            ->assertSessionHasNoErrors();

        $trees = LostWaxTree::where('work_order_id', $workOrder->id)->get();

        $this->assertCount(30, $trees);

        foreach ($trees as $tree) {
            $this->assertSame($workOrder->id, $tree->work_order_id);
            $this->assertSame($plan->id, $tree->work_order_plan_id);
        }
    }

    public function test_tree_quantity_calculation_with_exact_division(): void
    {
        $quantities = $this->treeService->calculateProposedTrees(450, 15);

        $this->assertCount(30, $quantities);
        $this->assertSame(450, array_sum($quantities));

        foreach ($quantities as $qty) {
            $this->assertSame(15, $qty);
        }
    }

    public function test_tree_quantity_calculation_with_remainder(): void
    {
        $quantities = $this->treeService->calculateProposedTrees(449, 15);

        $this->assertCount(30, $quantities);
        $this->assertSame(449, array_sum($quantities));

        $this->assertSame(15, $quantities[0]);
        $this->assertSame(14, $quantities[29]);
    }

    public function test_variable_final_tree_quantity_is_supported(): void
    {
        $workOrder = $this->createWorkOrderWithAssemblyOutput(449);
        $plan = $this->createPlan($workOrder);

        $user = User::factory()->create();

        $quantities = array_merge(
            array_fill(0, 29, 15),
            [14]
        );

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan), [
                'default_qty' => 15,
                'quantities' => $quantities,
                'family_code' => '1',
            ])
            ->assertSessionHasNoErrors();

        $trees = LostWaxTree::where('work_order_id', $workOrder->id)
            ->orderBy('tree_number')
            ->get();

        $this->assertCount(30, $trees);
        $this->assertSame(15, $trees[0]->quantity);
        $this->assertSame(14, $trees[29]->quantity);
        $this->assertSame(449, $trees->sum('quantity'));
    }

    public function test_cannot_allocate_more_than_available_wip(): void
    {
        $workOrder = $this->createWorkOrderWithAssemblyOutput(450);
        $plan = $this->createPlan($workOrder);

        $user = User::factory()->create();

        $quantities = array_merge(
            array_fill(0, 30, 15),
            [1]
        );

        $response = $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan), [
                'default_qty' => 15,
                'quantities' => $quantities,
                'family_code' => '1',
            ]);

        $response->assertSessionHas('error');

        $this->assertDatabaseCount('lost_wax_trees', 0);
    }

    public function test_barcode_format_is_correct(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11));

        $workOrder = $this->createWorkOrderWithAssemblyOutput(15);
        $plan = $this->createPlan($workOrder);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan), [
                'default_qty' => 15,
                'quantities' => [15],
                'family_code' => '1',
            ])
            ->assertSessionHasNoErrors();

        $tree = LostWaxTree::first();

        $this->assertSame('1110826001', $tree->barcode);

        $this->assertStringStartsWith('1', $tree->barcode);
        $this->assertStringContainsString('110826', $tree->barcode);
        $this->assertStringEndsWith('001', $tree->barcode);
        $this->assertSame(10, strlen($tree->barcode));

        Carbon::setTestNow(null);
    }

    public function test_barcode_uniqueness_across_trees(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11));

        $workOrder = $this->createWorkOrderWithAssemblyOutput(30);
        $plan = $this->createPlan($workOrder);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan), [
                'default_qty' => 15,
                'quantities' => [15, 15],
                'family_code' => '1',
            ])
            ->assertSessionHasNoErrors();

        $trees = LostWaxTree::all();

        $this->assertCount(2, $trees);

        $barcodes = $trees->pluck('barcode')->toArray();

        $this->assertSame(['1110826001', '1110826002'], $barcodes);
        $this->assertCount(2, array_unique($barcodes));

        Carbon::setTestNow(null);
    }

    public function test_sequence_continues_across_ets_within_same_family_and_date(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11));

        $user = User::factory()->create();

        $wo1 = $this->createWorkOrderWithAssemblyOutput(15);
        $plan1 = $this->createPlan($wo1);

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan1), [
                'default_qty' => 15,
                'quantities' => [15],
                'family_code' => '1',
            ])
            ->assertRedirect()
            ->assertSessionMissing('error');

        $wo2 = $this->createWorkOrderWithAssemblyOutput(15, 'ET233', 'LY026-2');
        $plan2 = $this->createPlan($wo2);

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan2), [
                'default_qty' => 15,
                'quantities' => [15],
                'family_code' => '1',
            ])
            ->assertRedirect()
            ->assertSessionMissing('error');

        $trees = LostWaxTree::orderBy('id')->get();

        $this->assertCount(2, $trees);
        $this->assertSame('1110826001', $trees[0]->barcode);
        $this->assertSame('1110826002', $trees[1]->barcode);
        $this->assertSame(1, $trees[0]->daily_sequence);
        $this->assertSame(2, $trees[1]->daily_sequence);

        Carbon::setTestNow(null);
    }

    public function test_different_family_starts_own_sequence(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11));

        $user = User::factory()->create();

        $wo1 = $this->createWorkOrderWithAssemblyOutput(15);
        $plan1 = $this->createPlan($wo1);

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan1), [
                'default_qty' => 15,
                'quantities' => [15],
                'family_code' => '1',
            ])
            ->assertSessionHasNoErrors();

        $wo2 = $this->createWorkOrderWithAssemblyOutput(15, 'ET233', 'LY026-2');
        $plan2 = $this->createPlan($wo2);

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan2), [
                'default_qty' => 15,
                'quantities' => [15],
                'family_code' => '2',
            ])
            ->assertSessionHasNoErrors();

        $family1Trees = LostWaxTree::where('family_code', '1')->get();
        $family2Trees = LostWaxTree::where('family_code', '2')->get();

        $this->assertCount(1, $family1Trees);
        $this->assertCount(1, $family2Trees);

        $this->assertSame(1, $family1Trees[0]->daily_sequence);
        $this->assertSame(1, $family2Trees[0]->daily_sequence);

        $this->assertStringStartsWith('1', $family1Trees[0]->barcode);
        $this->assertStringStartsWith('2', $family2Trees[0]->barcode);

        Carbon::setTestNow(null);
    }

    public function test_different_date_starts_own_sequence(): void
    {
        $user = User::factory()->create();

        Carbon::setTestNow(Carbon::create(2026, 8, 11));

        $wo1 = $this->createWorkOrderWithAssemblyOutput(15);
        $plan1 = $this->createPlan($wo1);

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan1), [
                'default_qty' => 15,
                'quantities' => [15],
                'family_code' => '1',
            ])
            ->assertSessionHasNoErrors();

        Carbon::setTestNow(Carbon::create(2026, 8, 12));

        $wo2 = $this->createWorkOrderWithAssemblyOutput(15, 'ET233', 'LY026-2');
        $plan2 = $this->createPlan($wo2);

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan2), [
                'default_qty' => 15,
                'quantities' => [15],
                'family_code' => '1',
            ])
            ->assertSessionHasNoErrors();

        $trees = LostWaxTree::orderBy('id')->get();

        $this->assertCount(2, $trees);
        $this->assertSame('2026-08-11', $trees[0]->production_date->format('Y-m-d'));
        $this->assertSame('2026-08-12', $trees[1]->production_date->format('Y-m-d'));
        $this->assertSame(1, $trees[0]->daily_sequence);
        $this->assertSame(1, $trees[1]->daily_sequence);

        Carbon::setTestNow(null);
    }

    public function test_human_readable_barcode_is_present(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11));

        $workOrder = $this->createWorkOrderWithAssemblyOutput(15);
        $plan = $this->createPlan($workOrder);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan), [
                'default_qty' => 15,
                'quantities' => [15],
                'family_code' => '1',
            ])
            ->assertSessionHasNoErrors();

        $tree = LostWaxTree::first();

        $this->assertNotNull($tree->human_barcode);
        $this->assertStringContainsString(' ', $tree->human_barcode);
        $this->assertStringContainsString('-', $tree->human_barcode);

        Carbon::setTestNow(null);
    }

    public function test_traveler_page_loads(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11));

        $workOrder = $this->createWorkOrderWithAssemblyOutput(15);
        $plan = $this->createPlan($workOrder);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan), [
                'default_qty' => 15,
                'quantities' => [15],
                'family_code' => '1',
            ])
            ->assertSessionHasNoErrors();

        $tree = LostWaxTree::first();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.trees.traveler', $tree));

        $response->assertOk();
        $response->assertSee('LOST WAX TRAVELER');
        $response->assertSee($tree->barcode);

        Carbon::setTestNow(null);
    }

    public function test_barcode_image_generation(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11));

        $workOrder = $this->createWorkOrderWithAssemblyOutput(15);
        $plan = $this->createPlan($workOrder);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan), [
                'default_qty' => 15,
                'quantities' => [15],
                'family_code' => '1',
            ])
            ->assertSessionHasNoErrors();

        $tree = LostWaxTree::first();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.trees.barcode', $tree));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');

        Carbon::setTestNow(null);
    }

    public function test_work_order_tree_summary_accessors(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11));

        $workOrder = $this->createWorkOrderWithAssemblyOutput(450);
        $plan = $this->createPlan($workOrder);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan), [
                'default_qty' => 15,
                'quantities' => array_fill(0, 30, 15),
                'family_code' => '1',
            ])
            ->assertSessionHasNoErrors();

        $workOrder->load('trees');

        $this->assertSame(450, $workOrder->tree_quantity);
        $this->assertSame(30, $workOrder->tree_count);
        $this->assertSame(0, $workOrder->remaining_unallocated_quantity);

        Carbon::setTestNow(null);
    }

    public function test_remaining_unallocated_quantity_works(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11));

        $workOrder = $this->createWorkOrderWithAssemblyOutput(450);
        $plan = $this->createPlan($workOrder);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan), [
                'default_qty' => 15,
                'quantities' => array_fill(0, 20, 15),
                'family_code' => '1',
            ])
            ->assertSessionHasNoErrors();

        $workOrder->load('trees');

        $this->assertSame(300, $workOrder->tree_quantity);
        $this->assertSame(150, $workOrder->remaining_unallocated_quantity);

        Carbon::setTestNow(null);
    }

    public function test_tree_quantity_correction_works(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11));

        $workOrder = $this->createWorkOrderWithAssemblyOutput(450);
        $plan = $this->createPlan($workOrder);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan), [
                'default_qty' => 15,
                'quantities' => [15],
                'family_code' => '1',
            ])
            ->assertSessionHasNoErrors();

        $tree = LostWaxTree::first();

        $this->actingAs($user)
            ->patch(route('lost-wax.trees.update', $tree), [
                'quantity' => 20,
            ])
            ->assertSessionHas('success');

        $this->assertSame(20, $tree->fresh()->quantity);
        $this->assertNotNull($tree->fresh()->updated_at);

        Carbon::setTestNow(null);
    }

    public function test_tree_quantity_correction_cannot_exceed_available(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11));

        $workOrder = $this->createWorkOrderWithAssemblyOutput(450);
        $plan = $this->createPlan($workOrder);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan), [
                'default_qty' => 15,
                'quantities' => array_fill(0, 30, 15),
                'family_code' => '1',
            ])
            ->assertSessionHasNoErrors();

        $tree = LostWaxTree::first();

        $this->actingAs($user)
            ->patch(route('lost-wax.trees.update', $tree), [
                'quantity' => 100,
            ])
            ->assertSessionHas('error');
    }

    public function test_tree_generation_requires_family_code(): void
    {
        $workOrder = $this->createWorkOrderWithAssemblyOutput(15, 'ET232', 'LY026');
        $plan = $this->createPlan($workOrder);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan), [
                'default_qty' => 15,
                'quantities' => [15],
                'family_code' => '',
            ]);

        $response->assertSessionHasErrors('family_code');
    }

    public function test_tree_generation_requires_valid_family_code(): void
    {
        $workOrder = $this->createWorkOrderWithAssemblyOutput(15);
        $plan = $this->createPlan($workOrder);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan), [
                'default_qty' => 15,
                'quantities' => [15],
                'family_code' => '99',
            ]);

        $response->assertSessionHas('error');
    }

    public function test_tree_generation_page_loads(): void
    {
        $workOrder = $this->createWorkOrderWithAssemblyOutput(450);
        $plan = $this->createPlan($workOrder);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.trees.generate', $plan));

        $response->assertOk();
        $response->assertSee('Generate Tree');
        $response->assertSee('450');
        $response->assertSee('30');
    }

    public function test_tree_index_page_loads(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.trees.index'));

        $response->assertOk();
        $response->assertSee('Tree / Traveler');
    }

    public function test_tree_show_page_loads(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11));

        $workOrder = $this->createWorkOrderWithAssemblyOutput(15);
        $plan = $this->createPlan($workOrder);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan), [
                'default_qty' => 15,
                'quantities' => [15],
                'family_code' => '1',
            ])
            ->assertSessionHasNoErrors();

        $tree = LostWaxTree::first();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.trees.show', $tree));

        $response->assertOk();
        $response->assertSee($tree->barcode);

        Carbon::setTestNow(null);
    }

    public function test_work_order_with_no_assembly_output_cannot_generate_trees(): void
    {
        $workOrder = $this->createWorkOrderWithAssemblyOutput(0);
        $plan = $this->createPlan($workOrder);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.trees.generate', $plan));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_tree_generation_respects_multiple_plans(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11));

        $workOrder = $this->createWorkOrderWithAssemblyOutput(450);
        $plan1 = $this->createPlan($workOrder, 1);
        $plan2 = $this->createPlan($workOrder, 2);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan1), [
                'default_qty' => 15,
                'quantities' => array_fill(0, 10, 15),
                'family_code' => '1',
            ])
            ->assertRedirect()
            ->assertSessionMissing('error');

        $workOrder->load('trees');

        $this->assertSame(150, $workOrder->tree_quantity);
        $this->assertSame(300, $workOrder->remaining_unallocated_quantity);

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan2), [
                'default_qty' => 15,
                'quantities' => array_fill(0, 20, 15),
                'family_code' => '1',
            ])
            ->assertRedirect()
            ->assertSessionMissing('error');

        $workOrder->load('trees');

        $this->assertSame(450, $workOrder->tree_quantity);
        $this->assertSame(0, $workOrder->remaining_unallocated_quantity);

        $this->assertSame(10, LostWaxTree::where('work_order_plan_id', $plan1->id)->count());
        $this->assertSame(20, LostWaxTree::where('work_order_plan_id', $plan2->id)->count());

        Carbon::setTestNow(null);
    }

    private function createWorkOrderWithAssemblyOutput(
        int $assemblyQty,
        string $etcode = 'ET232',
        string $itemKey = 'LY026'
    ): LostWaxWorkOrder {
        $reference = LostWaxItemReference::create([
            'master_source' => 'masterdata_kpi',
            'master_item_key' => $itemKey,
            'item_code_snapshot' => $itemKey,
            'item_name_snapshot' => 'Hex Nipple SUS304',
            'aisi_snapshot' => 'SUS304',
        ]);

        $workOrder = LostWaxWorkOrder::create([
            'item_reference_id' => $reference->id,
            'et_code' => $etcode,
            'po_number' => 'PO-001',
            'customer_name' => 'PT Contoh',
            'po_quantity' => 1000,
            'stock_quantity' => 500,
            'net_requirement_quantity' => 500,
            'status' => 'active',
            'family_code' => '1',
        ]);

        if ($assemblyQty > 0) {
            LostWaxWorkOrderWip::create([
                'work_order_id' => $workOrder->id,
                'stage' => 'assembly',
                'quantity' => $assemblyQty,
                'status' => 'recorded',
                'produced_at' => now(),
            ]);
        }

        return $workOrder;
    }

    private function createPlan(LostWaxWorkOrder $workOrder, int $waveNumber = 1): LostWaxWorkOrderPlan
    {
        return LostWaxWorkOrderPlan::create([
            'work_order_id' => $workOrder->id,
            'wave_number' => $waveNumber,
            'plan_type' => 'initial',
            'planned_quantity' => 705,
            'status' => 'planned',
        ]);
    }
}
