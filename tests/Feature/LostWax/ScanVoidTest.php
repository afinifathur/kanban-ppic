<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxScanEvent;
use App\Models\LostWaxScanEventVoid;
use App\Models\LostWaxTree;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ScanVoidTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup roles required by authorization policies
        Role::firstOrCreate(['name' => 'ppic']);
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'operator']);
    }

    protected function createProductionPlan($attributes = [])
    {
        return \App\Models\ProductionPlan::create(array_merge([
            'code' => 'TEST61',
            'customer' => 'TEST CUSTOMER',
            'item_code' => '4.101105K.A0020',
            'item_name' => 'SS316 BLIND 2"',
            'aisi' => '316',
            'size' => '2"',
            'weight' => 0.75,
            'po_number' => 'PO-TEST-01',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'status' => 'planning',
        ], $attributes));
    }

    protected function createTree($attributes = [])
    {
        return LostWaxTree::create(array_merge([
            'barcode' => '4220826001',
            'tree_number' => 1,
            'quantity' => 20,
            'status' => 'generated',
            'require_layer_7' => false,
            'production_date' => '2026-08-22',
            'family_code' => '4',
            'daily_sequence' => 1,
        ], $attributes));
    }

    // =========================================================================
    // A. LAYER 7 ACCESSOR TESTS
    // =========================================================================

    public function test_accessor_new_tree_with_layer_7_true(): void
    {
        $tree = $this->createTree([
            'require_layer_7' => true,
            'work_order_id' => null,
        ]);

        $this->assertTrue($tree->require_layer_7);
    }

    public function test_accessor_new_tree_with_layer_7_false(): void
    {
        $tree = $this->createTree([
            'require_layer_7' => false,
            'work_order_id' => null,
        ]);

        $this->assertFalse($tree->require_layer_7);
    }

    public function test_accessor_legacy_tree_fallback_to_work_order(): void
    {
        // Setup legacy WorkOrder with require_layer_7 = true
        $ref = \App\Models\LostWaxItemReference::create([
            'item_code_snapshot' => '123',
            'item_name_snapshot' => 'TEST',
            'master_item_key' => 'TEST-KEY',
        ]);
        $wo = \App\Models\LostWaxWorkOrder::create([
            'et_code' => 'ET-TEST',
            'qty_planned' => 100,
            'require_layer_7' => true,
            'status' => 'active',
            'item_reference_id' => $ref->id,
            'po_number' => 'PO-TEST-123',
            'po_quantity' => 100,
            'net_requirement_quantity' => 100,
        ]);

        $tree = $this->createTree([
            'require_layer_7' => false, // false database value, must fallback to work order
            'work_order_id' => $wo->id,
        ]);

        $this->assertTrue($tree->require_layer_7);
    }

    public function test_database_value_is_authoritative_and_not_overridden(): void
    {
        // Setup legacy WorkOrder with require_layer_7 = false
        $ref = \App\Models\LostWaxItemReference::create([
            'item_code_snapshot' => '123',
            'item_name_snapshot' => 'TEST',
            'master_item_key' => 'TEST-KEY',
        ]);
        $wo = \App\Models\LostWaxWorkOrder::create([
            'et_code' => 'ET-TEST',
            'qty_planned' => 100,
            'require_layer_7' => false,
            'status' => 'active',
            'item_reference_id' => $ref->id,
            'po_number' => 'PO-TEST-123',
            'po_quantity' => 100,
            'net_requirement_quantity' => 100,
        ]);

        // Tree has require_layer_7 = true in database
        $tree = $this->createTree([
            'require_layer_7' => true,
            'work_order_id' => $wo->id,
        ]);

        // Accessor must prioritize direct DB column value (true) over WorkOrder value (false)
        $this->assertTrue($tree->require_layer_7);
    }

    // =========================================================================
    // B. AUTHORIZATION TESTS
    // =========================================================================

    public function test_ppic_can_void_scan(): void
    {
        $ppic = User::factory()->create();
        $ppic->assignRole('ppic');

        $tree = $this->createTree();
        $event = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'lapisan_1',
            'result' => 'success',
            'scanned_at' => now(),
            'operator_id' => $ppic->id,
        ]);

        $response = $this->actingAs($ppic)
            ->post(route('lost-wax.scan-events.void', $event), [
                'void_reason' => 'Kesalahan scan operator',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('lost_wax_scan_event_voids', [
            'scan_event_id' => $event->id,
            'voided_by' => $ppic->id,
            'void_reason' => 'Kesalahan scan operator',
        ]);
    }

    public function test_admin_can_void_scan(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $tree = $this->createTree();
        $event = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'lapisan_1',
            'result' => 'success',
            'scanned_at' => now(),
            'operator_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->post(route('lost-wax.scan-events.void', $event), [
                'void_reason' => 'Test admin void',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('lost_wax_scan_event_voids', [
            'scan_event_id' => $event->id,
            'void_reason' => 'Test admin void',
        ]);
    }

    public function test_operator_cannot_void_scan(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operator');

        $tree = $this->createTree();
        $event = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'lapisan_1',
            'result' => 'success',
            'scanned_at' => now(),
            'operator_id' => $operator->id,
        ]);

        $response = $this->actingAs($operator)
            ->post(route('lost-wax.scan-events.void', $event), [
                'void_reason' => 'Operator pembatalan',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('lost_wax_scan_event_voids', [
            'scan_event_id' => $event->id,
        ]);
    }

    // =========================================================================
    // C. VALIDATION TESTS
    // =========================================================================

    public function test_empty_reason_is_rejected(): void
    {
        $ppic = User::factory()->create();
        $ppic->assignRole('ppic');

        $tree = $this->createTree();
        $event = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'lapisan_1',
            'result' => 'success',
            'scanned_at' => now(),
            'operator_id' => $ppic->id,
        ]);

        $response = $this->actingAs($ppic)
            ->post(route('lost-wax.scan-events.void', $event), [
                'void_reason' => '',
            ]);

        $response->assertSessionHasErrors('void_reason');
    }

    public function test_whitespace_only_reason_is_rejected(): void
    {
        $ppic = User::factory()->create();
        $ppic->assignRole('ppic');

        $tree = $this->createTree();
        $event = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'lapisan_1',
            'result' => 'success',
            'scanned_at' => now(),
            'operator_id' => $ppic->id,
        ]);

        // Test exception thrown at service level for whitespace
        $service = app(\App\Services\ScanVoidService::class);
        $this->expectException(\InvalidArgumentException::class);
        $service->void($event, '   ', $ppic->id);
    }

    // =========================================================================
    // D. LATEST-EVENT RULE TESTS
    // =========================================================================

    public function test_latest_event_can_be_voided(): void
    {
        $ppic = User::factory()->create();
        $ppic->assignRole('ppic');

        $tree = $this->createTree();
        $event1 = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'lapisan_1',
            'result' => 'success',
            'scanned_at' => now()->subMinutes(10),
            'operator_id' => $ppic->id,
        ]);

        $event2 = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'lapisan_2',
            'result' => 'success',
            'scanned_at' => now(),
            'operator_id' => $ppic->id,
        ]);

        $service = app(\App\Services\ScanVoidService::class);
        $void = $service->void($event2, 'Alasan valid', $ppic->id);

        $this->assertInstanceOf(LostWaxScanEventVoid::class, $void);
    }

    public function test_older_event_void_is_rejected(): void
    {
        $ppic = User::factory()->create();
        $ppic->assignRole('ppic');

        $tree = $this->createTree();
        $event1 = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'lapisan_1',
            'result' => 'success',
            'scanned_at' => now()->subMinutes(10),
            'operator_id' => $ppic->id,
        ]);

        $event2 = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'lapisan_2',
            'result' => 'success',
            'scanned_at' => now(),
            'operator_id' => $ppic->id,
        ]);

        $service = app(\App\Services\ScanVoidService::class);
        $this->expectException(\InvalidArgumentException::class);
        $service->void($event1, 'Alasan void event lampau', $ppic->id);
    }

    public function test_already_voided_event_cannot_be_voided_twice(): void
    {
        $ppic = User::factory()->create();
        $ppic->assignRole('ppic');

        $tree = $this->createTree();
        $event = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'lapisan_1',
            'result' => 'success',
            'scanned_at' => now(),
            'operator_id' => $ppic->id,
        ]);

        $service = app(\App\Services\ScanVoidService::class);
        $service->void($event, 'First void', $ppic->id);

        $this->expectException(\InvalidArgumentException::class);
        $service->void($event, 'Second void', $ppic->id);
    }

    // =========================================================================
    // E. STATE RECONSTRUCTION TESTS
    // =========================================================================

    public function test_state_reconstruction_from_l5_to_l4(): void
    {
        $ppic = User::factory()->create();
        $ppic->assignRole('ppic');

        $tree = $this->createTree();

        $l4 = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'lapisan_4',
            'result' => 'success',
            'scanned_at' => now()->subMinutes(5),
            'operator_id' => $ppic->id,
        ]);

        $l5 = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'lapisan_5',
            'result' => 'success',
            'scanned_at' => now(),
            'operator_id' => $ppic->id,
        ]);

        $tree->update([
            'current_stage' => 'lapisan_5',
            'last_scan_at' => $l5->scanned_at,
        ]);

        $service = app(\App\Services\ScanVoidService::class);
        $service->void($l5, 'Revert L5 to L4', $ppic->id);

        $tree->refresh();
        $this->assertEquals('lapisan_4', $tree->current_stage);
        $this->assertEquals($l4->scanned_at->timestamp, $tree->last_scan_at->timestamp);
    }

    public function test_state_reconstruction_to_null_when_all_voided(): void
    {
        $ppic = User::factory()->create();
        $ppic->assignRole('ppic');

        $tree = $this->createTree();

        $event = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'lapisan_1',
            'result' => 'success',
            'scanned_at' => now(),
            'operator_id' => $ppic->id,
        ]);

        $tree->update([
            'current_stage' => 'lapisan_1',
            'last_scan_at' => $event->scanned_at,
        ]);

        $service = app(\App\Services\ScanVoidService::class);
        $service->void($event, 'Revert L1 to Null', $ppic->id);

        $tree->refresh();
        $this->assertNull($tree->current_stage);
        $this->assertNull($tree->last_scan_at);
    }

    public function test_void_oven_event_restores_previous_layer(): void
    {
        $ppic = User::factory()->create();
        $ppic->assignRole('ppic');

        $tree = $this->createTree();

        $l6 = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'lapisan_6',
            'result' => 'success',
            'scanned_at' => now()->subMinutes(15),
            'operator_id' => $ppic->id,
        ]);

        $oven = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'oven',
            'result' => 'success',
            'scanned_at' => now(),
            'operator_id' => $ppic->id,
        ]);

        $tree->update([
            'current_stage' => 'oven',
            'last_scan_at' => $oven->scanned_at,
        ]);

        $service = app(\App\Services\ScanVoidService::class);
        $service->void($oven, 'Cancel oven scan', $ppic->id);

        $tree->refresh();
        $this->assertEquals('lapisan_6', $tree->current_stage);
        $this->assertEquals($l6->scanned_at->timestamp, $tree->last_scan_at->timestamp);
    }

    public function test_void_prior_layer_rejected_when_oven_active(): void
    {
        $ppic = User::factory()->create();
        $ppic->assignRole('ppic');

        $tree = $this->createTree();

        $l6 = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'lapisan_6',
            'result' => 'success',
            'scanned_at' => now()->subMinutes(15),
            'operator_id' => $ppic->id,
        ]);

        $oven = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'oven',
            'result' => 'success',
            'scanned_at' => now(),
            'operator_id' => $ppic->id,
        ]);

        $tree->update([
            'current_stage' => 'oven',
            'last_scan_at' => $oven->scanned_at,
        ]);

        // Attempt to void L6 instead of Oven (Oven is currently the latest)
        $service = app(\App\Services\ScanVoidService::class);
        $this->expectException(\InvalidArgumentException::class);
        $service->void($l6, 'Try to void L6 while oven active', $ppic->id);
    }

    // =========================================================================
    // F. AUDIT AND UI TESTS
    // =========================================================================

    public function test_original_scan_event_and_void_record_persistence(): void
    {
        $ppic = User::factory()->create();
        $ppic->assignRole('ppic');

        $tree = $this->createTree();
        $event = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'lapisan_1',
            'result' => 'success',
            'scanned_at' => now(),
            'operator_id' => $ppic->id,
        ]);

        $service = app(\App\Services\ScanVoidService::class);
        $service->void($event, 'Audit trace testing', $ppic->id);

        // Original ScanEvent must STILL exist in the database (non-destructive)
        $this->assertDatabaseHas('lost_wax_scan_events', [
            'id' => $event->id,
        ]);

        // Void ledger record exists
        $this->assertDatabaseHas('lost_wax_scan_event_voids', [
            'scan_event_id' => $event->id,
            'void_reason' => 'Audit trace testing',
        ]);
    }
}
