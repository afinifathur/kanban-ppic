<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxCastingResult;
use App\Models\LostWaxCastingResultTree;
use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeAllocation;
use App\Models\LostWaxTreeDefect;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWax\LostWaxCastingResultService;
use App\Services\LostWax\ReadyForCastingService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LostWaxCastingResultTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $ppicUser;

    protected User $spvUser;

    protected User $qcFittingUser;

    protected User $unauthorizedUser;

    protected LostWaxCastingResultService $castingResultService;

    protected ReadyForCastingService $readyForCastingService;

    protected static int $seq = 8000;

    protected function setUp(): void
    {
        parent::setUp();

        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);
        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning']);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->syncPermissions([$accessExecution, $accessPlanning]);

        $ppicRole = Role::firstOrCreate(['name' => 'ppic']);
        $ppicRole->syncPermissions([$accessPlanning, $accessExecution]);

        $spvRole = Role::firstOrCreate(['name' => 'spv']);
        $spvRole->syncPermissions([$accessExecution]);

        $qcRole = Role::firstOrCreate(['name' => 'admin_qc_fitting']);
        $qcRole->syncPermissions([$accessExecution, $accessPlanning]);

        $unauthorizedRole = Role::firstOrCreate(['name' => 'sales']);

        $this->adminUser = User::factory()->create(['email' => 'admin_cor@peroniks.com']);
        $this->adminUser->assignRole('admin');

        $this->ppicUser = User::factory()->create(['email' => 'ppic_cor@peroniks.com']);
        $this->ppicUser->assignRole('ppic');

        $this->spvUser = User::factory()->create(['email' => 'spv_cor@peroniks.com']);
        $this->spvUser->assignRole('spv');

        $this->qcFittingUser = User::factory()->create(['email' => 'qcfitting_cor@peroniks.com']);
        $this->qcFittingUser->assignRole('admin_qc_fitting');

        $this->unauthorizedUser = User::factory()->create(['email' => 'sales_cor@peroniks.com']);
        $this->unauthorizedUser->assignRole('sales');

        $this->castingResultService = app(LostWaxCastingResultService::class);
        $this->readyForCastingService = app(ReadyForCastingService::class);
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        $seq = self::$seq++;

        return ProductionPlan::create(array_merge([
            'code' => 'CODE-'.$seq,
            'customer' => 'CUST-'.$seq,
            'item_code' => 'ITEM-'.$seq,
            'item_name' => 'Flange Casting '.$seq,
            'aisi' => 'CF8M',
            'size' => '2 Inch',
            'weight' => 2.50,
            'po_number' => 'PO-'.$seq,
            'po_quantity' => 200,
            'qty_planned' => 200,
            'qty_remaining' => 200,
            'line_number' => 1,
            'status' => 'planning',
        ], $attributes));
    }

    protected function createPrintOrderLine(ProductionPlan $plan, array $attributes = []): LostWaxPrintOrderLine
    {
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PO-ORD-'.uniqid(),
            'scheduled_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        return LostWaxPrintOrderLine::create(array_merge([
            'lost_wax_print_order_id' => $order->id,
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'qty_ordered' => 50,
            'execution_status' => 'COMPLETED',
        ], $attributes));
    }

    protected function createTree(array $attributes = []): LostWaxTree
    {
        $currentSeq = self::$seq++;

        return LostWaxTree::create(array_merge([
            'barcode' => 'P'.now()->format('ymd').str_pad((string) $currentSeq, 4, '0', STR_PAD_LEFT),
            'tree_number' => $currentSeq,
            'quantity' => 48,
            'status' => 'generated',
            'current_stage' => 'siap_cor',
            'require_layer_7' => false,
            'production_date' => now()->toDateString(),
            'family_code' => '1',
            'daily_sequence' => $currentSeq,
        ], $attributes));
    }

    protected function createScanEvent(LostWaxTree $tree, string $stage, Carbon $scannedAt, string $result = 'success'): LostWaxScanEvent
    {
        return LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'operator_id' => $this->adminUser->id,
            'stage' => $stage,
            'result' => $result,
            'error_reason' => null,
            'scanned_at' => $scannedAt,
        ]);
    }

    /**
     * 1. Halaman checklist hanya menampilkan WIP valid.
     */
    public function test_01_checklist_displays_only_valid_ready_for_casting_wip(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        // Valid Tree
        $validTree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'current_stage' => 'siap_cor']);
        $this->createScanEvent($validTree, 'siap_cor', now()->subHours(2));

        // Invalid: Stage still in oven
        $ovenTree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'current_stage' => 'oven']);
        $this->createScanEvent($ovenTree, 'oven', now()->subHours(5));

        // Invalid: Status cancelled
        $cancelledTree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'current_stage' => 'siap_cor', 'status' => 'cancelled']);
        $this->createScanEvent($cancelledTree, 'siap_cor', now()->subHours(1));

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.casting-results.create'));
        $response->assertStatus(200);
        $response->assertSee($validTree->barcode);
        $response->assertDontSee($ovenTree->barcode);
        $response->assertDontSee($cancelledTree->barcode);
    }

    /**
     * 2. Heat Number wajib diisi.
     */
    public function test_02_heat_number_is_required(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id]);
        $this->createScanEvent($tree, 'siap_cor', now());

        $response = $this->actingAs($this->adminUser)->post(route('lost-wax.casting-results.store'), [
            'heat_number' => '',
            'cast_date' => now()->toDateString(),
            'tree_ids' => [$tree->id],
        ]);

        $response->assertSessionHasErrors(['heat_number']);
        $this->assertDatabaseCount('lost_wax_casting_results', 0);
    }

    /**
     * 3. Satu Heat dapat menerima banyak Traveler Card.
     */
    public function test_03_one_heat_number_can_accept_multiple_traveler_cards(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        $tree1 = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 48]);
        $this->createScanEvent($tree1, 'siap_cor', now()->subMinutes(10));

        $tree2 = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 50]);
        $this->createScanEvent($tree2, 'siap_cor', now()->subMinutes(5));

        $response = $this->actingAs($this->adminUser)->post(route('lost-wax.casting-results.store'), [
            'heat_number' => 'A411112601',
            'cast_date' => now()->toDateString(),
            'furnace' => 'Tungku 1',
            'shift' => '1',
            'operator_name' => 'Budi Operator',
            'tree_ids' => [$tree1->id, $tree2->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('lost_wax_casting_results', [
            'heat_number' => 'A411112601',
            'total_trees' => 2,
            'total_pcs' => 98,
            'status' => 'COMPLETED',
        ]);

        $this->assertDatabaseCount('lost_wax_casting_result_trees', 2);
    }

    /**
     * 4. Satu Traveler Card tidak dapat dikonsumsi dua kali.
     */
    public function test_04_one_traveler_card_cannot_be_consumed_twice(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 48]);
        $this->createScanEvent($tree, 'siap_cor', now());

        // First Heat A
        $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-A',
            'tree_ids' => [$tree->id],
        ], $this->adminUser);

        // Second Heat B attempt
        $this->expectException(\InvalidArgumentException::class);
        $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-B',
            'tree_ids' => [$tree->id],
        ], $this->adminUser);
    }

    /**
     * 5. Database unique constraint blocks concurrent active consumption.
     */
    public function test_05_database_unique_constraint_blocks_concurrent_active_consumption(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 48]);

        $castingResult = LostWaxCastingResult::create([
            'heat_number' => 'HEAT-CONC-1',
            'cast_date' => now()->toDateString(),
            'total_trees' => 1,
            'total_pcs' => 48,
            'status' => 'COMPLETED',
            'recorded_by' => $this->adminUser->id,
        ]);

        // First detail insert succeeds
        LostWaxCastingResultTree::create([
            'lost_wax_casting_result_id' => $castingResult->id,
            'lost_wax_tree_id' => $tree->id,
            'active_tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'production_code' => 'TEST',
            'quantity_pcs' => 48,
        ]);

        // Second detail insert with same active_tree_id must trigger DB unique exception
        $this->expectException(QueryException::class);

        LostWaxCastingResultTree::create([
            'lost_wax_casting_result_id' => $castingResult->id,
            'lost_wax_tree_id' => $tree->id,
            'active_tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'production_code' => 'TEST',
            'quantity_pcs' => 48,
        ]);
    }

    /**
     * 6. Qty snapshot menggunakan usable_quantity.
     */
    public function test_06_quantity_snapshot_uses_usable_quantity_accurately(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 48]);
        $this->createScanEvent($tree, 'siap_cor', now());

        // Pre-casting defect of 6 pcs -> Usable = 42 pcs
        LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $tree->id,
            'defect_qty' => 6,
            'defect_reason' => 'Patah / Cuil',
            'stage' => 'oven',
            'recorded_by' => $this->adminUser->id,
        ]);

        $res = $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-DEF',
            'tree_ids' => [$tree->id],
        ], $this->adminUser);

        $detail = $res->trees()->first();
        $this->assertEquals(42, $detail->quantity_pcs);
        $this->assertEquals(48, $detail->gross_pcs);
        $this->assertEquals(6, $detail->defect_pcs);
        $this->assertEquals(42, $res->total_pcs);
    }

    /**
     * 7. Total PCS dihitung server-side.
     */
    public function test_07_total_pcs_is_calculated_strictly_server_side(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        $tree1 = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 30]);
        $this->createScanEvent($tree1, 'siap_cor', now());

        $tree2 = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 25]);
        $this->createScanEvent($tree2, 'siap_cor', now());

        $res = $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-SUM',
            'tree_ids' => [$tree1->id, $tree2->id],
        ], $this->adminUser);

        $this->assertEquals(55, $res->total_pcs);
    }

    /**
     * 8. Multi-alokasi tidak menggandakan Traveler Card atau PCS.
     */
    public function test_08_multi_allocation_tree_does_not_duplicate_tree_or_pcs(): void
    {
        $planA = $this->createPlan(['code' => 'CODE-A']);
        $lineA = $this->createPrintOrderLine($planA);

        $planB = $this->createPlan(['code' => 'CODE-B']);
        $lineB = $this->createPrintOrderLine($planB);

        $tree = $this->createTree(['quantity' => 50]);
        $this->createScanEvent($tree, 'siap_cor', now());

        LostWaxTreeAllocation::create([
            'lost_wax_tree_id' => $tree->id,
            'lost_wax_print_order_line_id' => $lineA->id,
            'allocated_qty' => 30,
        ]);

        LostWaxTreeAllocation::create([
            'lost_wax_tree_id' => $tree->id,
            'lost_wax_print_order_line_id' => $lineB->id,
            'allocated_qty' => 20,
        ]);

        $res = $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-MULTI',
            'tree_ids' => [$tree->id],
        ], $this->adminUser);

        $this->assertEquals(1, $res->total_trees);
        $this->assertEquals(50, $res->total_pcs);
        $this->assertCount(1, $res->trees);
    }

    /**
     * 9. Penyimpanan header-detail atomik.
     */
    public function test_09_header_and_details_storage_is_atomic(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 50]);
        $this->createScanEvent($tree, 'siap_cor', now());

        $res = $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-ATOMIC',
            'tree_ids' => [$tree->id],
        ], $this->adminUser);

        $this->assertNotNull($res->id);
        $this->assertDatabaseHas('lost_wax_casting_results', ['id' => $res->id]);
        $this->assertDatabaseHas('lost_wax_casting_result_trees', ['lost_wax_casting_result_id' => $res->id, 'lost_wax_tree_id' => $tree->id]);
        $this->assertEquals('cor', $tree->fresh()->current_stage);
    }

    /**
     * 10. Satu detail gagal menyebabkan rollback.
     */
    public function test_10_failure_in_one_tree_rolls_back_entire_transaction(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        $validTree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 50]);
        $this->createScanEvent($validTree, 'siap_cor', now());

        $invalidTree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'current_stage' => 'oven']); // invalid stage

        try {
            $this->castingResultService->storeCastingResult([
                'heat_number' => 'HEAT-FAIL',
                'tree_ids' => [$validTree->id, $invalidTree->id],
            ], $this->adminUser);
            $this->fail('Harusnya melempar InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            // Expected
        }

        $this->assertDatabaseCount('lost_wax_casting_results', 0);
        $this->assertDatabaseCount('lost_wax_casting_result_trees', 0);
        $this->assertEquals('siap_cor', $validTree->fresh()->current_stage);
    }

    /**
     * 11. Setelah transaksi tersimpan, pohon keluar dari Barang Siap Cor.
     */
    public function test_11_saved_trees_are_immediately_removed_from_ready_for_casting_wip(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 50]);
        $this->createScanEvent($tree, 'siap_cor', now());

        // Queue before
        $queueBefore = $this->readyForCastingService->getQueueData();
        $this->assertEquals(1, $queueBefore['summary']['total_trees']);

        // Save
        $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-WIP',
            'tree_ids' => [$tree->id],
        ], $this->adminUser);

        // Queue after
        $queueAfter = $this->readyForCastingService->getQueueData();
        $this->assertEquals(0, $queueAfter['summary']['total_trees']);
        $this->assertEquals(0, $queueAfter['summary']['total_usable_pcs']);
    }

    /**
     * 12. Pohon yang tidak dipilih tetap berada di antrean.
     */
    public function test_12_unselected_trees_remain_in_ready_for_casting_wip(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        $treeSelected = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 50]);
        $this->createScanEvent($treeSelected, 'siap_cor', now()->subMinutes(10));

        $treeUnselected = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 30]);
        $this->createScanEvent($treeUnselected, 'siap_cor', now()->subMinutes(5));

        $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-PARTIAL',
            'tree_ids' => [$treeSelected->id],
        ], $this->adminUser);

        $queue = $this->readyForCastingService->getQueueData();
        $this->assertEquals(1, $queue['summary']['total_trees']);
        $this->assertEquals(30, $queue['summary']['total_usable_pcs']);
        $this->assertEquals($treeUnselected->id, $queue['items']->first()['tree_id']);
    }

    /**
     * 13. Transaksi CANCELLED mengembalikan pohon ke Siap Cor.
     */
    public function test_13_cancelled_transaction_returns_trees_to_siap_cor_wip(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 50]);
        $this->createScanEvent($tree, 'siap_cor', now());

        $res = $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-CANCEL',
            'tree_ids' => [$tree->id],
        ], $this->adminUser);

        $this->assertEquals('cor', $tree->fresh()->current_stage);

        // Cancel
        $this->castingResultService->cancelCastingResult($res, $this->adminUser, 'Salah input nomor heat');

        $this->assertEquals('siap_cor', $tree->fresh()->current_stage);
        $queue = $this->readyForCastingService->getQueueData();
        $this->assertEquals(1, $queue['summary']['total_trees']);
    }

    /**
     * 14. Detail transaksi lama tetap tersimpan setelah void.
     */
    public function test_14_old_transaction_detail_history_is_preserved_after_void(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 50]);
        $this->createScanEvent($tree, 'siap_cor', now());

        $res = $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-VOID-HIST',
            'tree_ids' => [$tree->id],
        ], $this->adminUser);

        $this->castingResultService->cancelCastingResult($res, $this->adminUser, 'Uji coba void');

        $this->assertDatabaseHas('lost_wax_casting_results', [
            'id' => $res->id,
            'status' => 'CANCELLED',
            'void_reason' => 'Uji coba void',
        ]);

        $this->assertDatabaseHas('lost_wax_casting_result_trees', [
            'lost_wax_casting_result_id' => $res->id,
            'lost_wax_tree_id' => $tree->id,
            'active_tree_id' => null, // Freed
            'barcode' => $tree->barcode,
        ]);
    }

    /**
     * 15. Pohon dari transaksi CANCELLED dapat dipakai ke Heat lain.
     */
    public function test_15_tree_from_cancelled_transaction_can_be_reused_in_new_heat(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 50]);
        $this->createScanEvent($tree, 'siap_cor', now());

        // Heat 1
        $res1 = $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-1',
            'tree_ids' => [$tree->id],
        ], $this->adminUser);

        // Cancel Heat 1
        $this->castingResultService->cancelCastingResult($res1, $this->adminUser, 'Batal Heat 1');

        // Heat 2 with same tree
        $res2 = $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-2',
            'tree_ids' => [$tree->id],
        ], $this->adminUser);

        $this->assertEquals('COMPLETED', $res2->status);
        $this->assertEquals('cor', $tree->fresh()->current_stage);
        $this->assertDatabaseCount('lost_wax_casting_result_trees', 2);
    }

    /**
     * 16. Dua transaksi aktif tidak dapat mengonsumsi pohon yang sama.
     */
    public function test_16_two_active_transactions_cannot_consume_same_tree(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 50]);
        $this->createScanEvent($tree, 'siap_cor', now());

        $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-A-ACTIVE',
            'tree_ids' => [$tree->id],
        ], $this->adminUser);

        $this->expectException(\InvalidArgumentException::class);
        $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-B-ACTIVE',
            'tree_ids' => [$tree->id],
        ], $this->adminUser);
    }

    /**
     * 17. Dokumen cetak menggunakan snapshot transaksi.
     */
    public function test_17_print_document_uses_stored_snapshots(): void
    {
        $plan = $this->createPlan(['code' => 'SNAPSHOT-CODE']);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 48]);
        $this->createScanEvent($tree, 'siap_cor', now());

        $res = $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-SNAP',
            'tree_ids' => [$tree->id],
        ], $this->adminUser);

        // Mutate original plan code after casting result creation
        $plan->update(['code' => 'ALTERED-CODE']);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.casting-results.print', $res));
        $response->assertStatus(200);
        $response->assertSee('SNAPSHOT-CODE');
        $response->assertDontSee('ALTERED-CODE');
    }

    /**
     * 18. Cetak ulang tidak mengubah status WIP.
     */
    public function test_18_reprinting_document_does_not_alter_wip(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 48]);
        $this->createScanEvent($tree, 'siap_cor', now());

        $res = $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-PRINT',
            'tree_ids' => [$tree->id],
        ], $this->adminUser);

        $this->actingAs($this->adminUser)->get(route('lost-wax.casting-results.print', $res))->assertStatus(200);
        $this->actingAs($this->adminUser)->get(route('lost-wax.casting-results.print', $res))->assertStatus(200);

        $this->assertEquals('cor', $tree->fresh()->current_stage);
        $this->assertEquals('COMPLETED', $res->fresh()->status);
    }

    /**
     * 19. Role tanpa permission tidak dapat mengakses fitur.
     */
    public function test_19_role_permission_and_access_control(): void
    {
        $gate = Gate::getFacadeRoot();
        $ref = new \ReflectionClass($gate);
        $prop = $ref->getProperty('beforeCallbacks');
        $prop->setAccessible(true);
        $prop->setValue($gate, [
            function ($user, $ability) {
                if (method_exists($user, 'hasPermissionTo')) {
                    try {
                        return $user->hasPermissionTo($ability) ? true : null;
                    } catch (\Throwable $e) {
                        return null;
                    }
                }

                return null;
            },
        ]);

        // Unauthorized (sales) gets 403
        $this->actingAs($this->unauthorizedUser)->get(route('lost-wax.casting-results.index'))->assertStatus(403);
        $this->actingAs($this->unauthorizedUser)->get(route('lost-wax.casting-results.create'))->assertStatus(403);

        // Authorized roles get 200
        $this->actingAs($this->adminUser)->get(route('lost-wax.casting-results.index'))->assertStatus(200);
        $this->actingAs($this->ppicUser)->get(route('lost-wax.casting-results.index'))->assertStatus(200);
        $this->actingAs($this->spvUser)->get(route('lost-wax.casting-results.index'))->assertStatus(200);
        $this->actingAs($this->qcFittingUser)->get(route('lost-wax.casting-results.index'))->assertStatus(200);
    }

    /**
     * 20. Transaksi CANCELLED ditampilkan dengan status yang jelas.
     */
    public function test_20_cancelled_transaction_displays_cancelled_status_clearly(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 48]);
        $this->createScanEvent($tree, 'siap_cor', now());

        $res = $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-CANCEL-UI',
            'tree_ids' => [$tree->id],
        ], $this->adminUser);

        $this->castingResultService->cancelCastingResult($res, $this->adminUser, 'Alasan Pembatalan Uji');

        $showResponse = $this->actingAs($this->adminUser)->get(route('lost-wax.casting-results.show', $res));
        $showResponse->assertStatus(200);
        $showResponse->assertSee('TRANSAKSI DIBATALKAN');
        $showResponse->assertSee('Alasan Pembatalan Uji');

        $printResponse = $this->actingAs($this->adminUser)->get(route('lost-wax.casting-results.print', $res));
        $printResponse->assertStatus(200);
        $printResponse->assertSee('DOKUMEN INI TELAH DIBATALKAN (VOID)');
    }
}
