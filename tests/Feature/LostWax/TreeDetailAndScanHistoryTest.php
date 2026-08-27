<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\ProductionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TreeDetailAndScanHistoryTest extends TestCase
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

    public function test_detail_tree_renders_prominent_product_and_inline_scan_history(): void
    {
        $plan = ProductionPlan::create([
            'code' => '268ST007',
            'customer' => 'CUSL063',
            'item_code' => '4.101105K.A0080',
            'item_name' => 'CS Q235 PLANE FLANGE JIS 5K 2-1/2"',
            'aisi' => 'CS Q235',
            'size' => '2-1/2"',
            'weight' => 1.25,
            'po_number' => 'PO-TEST',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'status' => 'planning',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260826-0006',
            'scheduled_date' => '2026-08-26',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'standard_tree_capacity' => 16,
        ]);

        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => '6260826015',
            'tree_number' => 1,
            'quantity' => 16,
            'status' => 'generated',
            'production_date' => '2026-08-26',
            'family_code' => '6',
            'daily_sequence' => 15,
            'current_stage' => 'layer_2',
        ]);

        // Create scan events: 1 success layer_1, 1 success layer_2, 1 rejected
        LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'layer_1',
            'result' => 'success',
            'operator_id' => $this->user->id,
            'scanned_at' => now()->subMinutes(30),
            'aging_status' => 'normal',
        ]);

        LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'layer_2',
            'result' => 'success',
            'operator_id' => $this->user->id,
            'scanned_at' => now()->subMinutes(10),
            'aging_status' => 'normal',
        ]);

        LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'layer_3',
            'result' => 'rejected',
            'operator_id' => $this->user->id,
            'scanned_at' => now()->subMinutes(5),
            'anomaly_reason' => 'Tree belum mencapai waktu aging minimum.',
        ]);

        $response = $this->actingAs($this->user)->get(route('lost-wax.trees.show', $tree));

        $response->assertOk();

        // 1. Prominent product information & SKU
        $response->assertSee('CS Q235 PLANE FLANGE JIS 5K 2-1/2"');
        $response->assertSee('268ST007');
        $response->assertSee('CUSL063');
        $response->assertSee('2-1/2"');
        $response->assertSee('CS Q235');
        $response->assertSee('6260826015');

        // 2. Action buttons (Print Traveler & Kembali, NO Riwayat Scan button)
        $response->assertSee('Print Traveler');
        $response->assertSee('Kembali');
        $response->assertDontSee(route('lost-wax.trees.history', $tree));

        // 3. Inline Scan History Section & Timeline
        $response->assertSee('RIWAYAT SCAN');
        $response->assertSee('Tahapan Saat Ini');
        $response->assertSee('Berhasil');
        $response->assertSee('Ditolak');
        $response->assertSee('LAPISAN 1');
        $response->assertSee('LAPISAN 2');
        $response->assertSee('BERHASIL');
        $response->assertSee('DITOLAK');
        $response->assertSee('Tree belum mencapai waktu aging minimum.');
    }

    public function test_detail_tree_without_scans_renders_empty_state_cleanly(): void
    {
        $plan = ProductionPlan::create([
            'code' => '268ST008',
            'customer' => 'CUSL064',
            'item_code' => '4.101105K.A0081',
            'item_name' => 'SS304 TEE DN 50',
            'aisi' => '304',
            'size' => '2"',
            'weight' => 1.5,
            'po_number' => 'PO-TEST2',
            'qty_planned' => 50,
            'qty_remaining' => 50,
            'line_number' => 1,
            'status' => 'planning',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260826-0007',
            'scheduled_date' => '2026-08-26',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 50,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'standard_tree_capacity' => 10,
        ]);

        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => '6260826016',
            'tree_number' => 2,
            'quantity' => 10,
            'status' => 'generated',
            'production_date' => '2026-08-26',
            'family_code' => '6',
            'daily_sequence' => 16,
            'current_stage' => null,
        ]);

        $response = $this->actingAs($this->user)->get(route('lost-wax.trees.show', $tree));

        $response->assertOk();
        $response->assertSee('SS304 TEE DN 50');
        $response->assertSee('BELUM SCAN');
        $response->assertSee('Belum ada aktivitas scan untuk Traveler ini');
    }

    public function test_legacy_history_endpoint_handles_null_work_order_safely(): void
    {
        $plan = ProductionPlan::create([
            'code' => '268ST009',
            'customer' => 'CUSL065',
            'item_code' => '4.101105K.A0082',
            'item_name' => 'SS316 HEX NIPPLE 1"',
            'aisi' => '316',
            'size' => '1"',
            'weight' => 0.8,
            'po_number' => 'PO-TEST3',
            'qty_planned' => 30,
            'qty_remaining' => 30,
            'line_number' => 1,
            'status' => 'planning',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260826-0008',
            'scheduled_date' => '2026-08-26',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 30,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'standard_tree_capacity' => 15,
        ]);

        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => '6260826017',
            'tree_number' => 3,
            'quantity' => 15,
            'status' => 'generated',
            'production_date' => '2026-08-26',
            'family_code' => '6',
            'daily_sequence' => 17,
        ]);

        // Directly accessing /lost-wax/trees/{tree}/history must not throw 500 error
        $response = $this->actingAs($this->user)->get(route('lost-wax.trees.history', $tree));

        $response->assertOk();
        $response->assertSee('6260826017');
        $response->assertSee('SS316 HEX NIPPLE 1"');
    }
}
