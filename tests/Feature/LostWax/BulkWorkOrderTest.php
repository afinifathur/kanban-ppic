<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxItemReference;
use App\Models\LostWaxWorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\ArrayItemMasterRepository;
use Tests\TestCase;

class BulkWorkOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            \App\Contracts\ItemMasterRepository::class,
            new ArrayItemMasterRepository([
                [
                    'code' => 'LY026',
                    'name' => 'Hex Nipple SUS304',
                    'aisi' => 'SUS304',
                    'standard' => 'STD',
                    'unit_weight' => '0.125',
                    'status' => 'active',
                ],
                [
                    'code' => 'LY027',
                    'name' => 'Flange X',
                    'aisi' => 'SUS316',
                    'standard' => 'STD',
                    'unit_weight' => '0.235',
                    'status' => 'active',
                ],
                [
                    'code' => 'LY028',
                    'name' => 'Elbow 90',
                    'aisi' => 'SUS304',
                    'standard' => 'STD',
                    'unit_weight' => '0.150',
                    'status' => 'active',
                ],
            ])
        );
    }

    public function test_bulk_submission_with_multiple_valid_rows(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('lost-wax.work-orders.bulk.store'), [
                'rows' => [
                    [
                        'et_code' => 'ET232',
                        'item_code' => 'LY026',
                        'po_number' => 'PO-001',
                        'customer_name' => 'PT A',
                        'po_quantity' => 1000,
                        'stock_quantity' => 500,
                        'net_requirement_quantity' => 500,
                        'family_code' => '1',
                        'status' => 'draft',
                        'notes' => '',
                        'due_date' => '',
                    ],
                    [
                        'et_code' => 'ET233',
                        'item_code' => 'LY027',
                        'po_number' => 'PO-002',
                        'customer_name' => 'PT B',
                        'po_quantity' => 200,
                        'stock_quantity' => 0,
                        'net_requirement_quantity' => 200,
                        'family_code' => '2',
                        'status' => 'planned',
                        'notes' => 'Urgent',
                        'due_date' => '2026-12-31',
                    ],
                ],
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertDatabaseCount('lost_wax_work_orders', 2);
        $this->assertDatabaseHas('lost_wax_work_orders', ['et_code' => 'ET232']);
        $this->assertDatabaseHas('lost_wax_work_orders', ['et_code' => 'ET233']);
        $this->assertDatabaseHas('lost_wax_item_references', ['master_item_key' => 'LY026']);
        $this->assertDatabaseHas('lost_wax_item_references', ['master_item_key' => 'LY027']);
    }

    public function test_duplicate_et_in_same_batch_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('lost-wax.work-orders.bulk.store'), [
                'rows' => [
                    [
                        'et_code' => 'ET232',
                        'item_code' => 'LY026',
                        'po_number' => 'PO-001',
                        'po_quantity' => 100,
                        'stock_quantity' => 0,
                        'net_requirement_quantity' => 100,
                        'status' => 'draft',
                    ],
                    [
                        'et_code' => 'ET232',
                        'item_code' => 'LY027',
                        'po_number' => 'PO-002',
                        'po_quantity' => 200,
                        'stock_quantity' => 0,
                        'net_requirement_quantity' => 200,
                        'status' => 'draft',
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        $response->assertJsonPath('errors.0', function ($error) {
            return str_contains($error, 'duplikat');
        });

        $this->assertDatabaseCount('lost_wax_work_orders', 0);
    }

    public function test_duplicate_et_already_in_database_is_rejected(): void
    {
        $reference = LostWaxItemReference::create([
            'master_source' => 'masterdata_kpi',
            'master_item_key' => 'LY026',
            'item_code_snapshot' => 'LY026',
            'item_name_snapshot' => 'Hex Nipple SUS304',
        ]);

        LostWaxWorkOrder::create([
            'item_reference_id' => $reference->id,
            'et_code' => 'ET232',
            'et_prefix' => 'ET',
            'et_period' => null,
            'et_sequence' => 232,
            'po_number' => 'PO-001',
            'po_quantity' => 100,
            'stock_quantity' => 0,
            'net_requirement_quantity' => 100,
            'status' => 'draft',
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('lost-wax.work-orders.bulk.store'), [
                'rows' => [
                    [
                        'et_code' => 'ET232',
                        'item_code' => 'LY026',
                        'po_number' => 'PO-002',
                        'po_quantity' => 200,
                        'stock_quantity' => 0,
                        'net_requirement_quantity' => 200,
                        'status' => 'draft',
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        $response->assertJsonPath('errors.0', function ($error) {
            return str_contains($error, 'sudah ada');
        });

        $this->assertDatabaseCount('lost_wax_work_orders', 1);
    }

    public function test_invalid_sku_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('lost-wax.work-orders.bulk.store'), [
                'rows' => [
                    [
                        'et_code' => 'ET232',
                        'item_code' => 'UNKNOWN',
                        'po_number' => 'PO-001',
                        'po_quantity' => 100,
                        'stock_quantity' => 0,
                        'net_requirement_quantity' => 100,
                        'status' => 'draft',
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        $response->assertJsonPath('errors.0', function ($error) {
            return str_contains($error, 'tidak ditemukan');
        });

        $this->assertDatabaseCount('lost_wax_work_orders', 0);
    }

    public function test_invalid_quantity_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('lost-wax.work-orders.bulk.store'), [
                'rows' => [
                    [
                        'et_code' => 'ET232',
                        'item_code' => 'LY026',
                        'po_number' => 'PO-001',
                        'po_quantity' => 'abc',
                        'stock_quantity' => 0,
                        'net_requirement_quantity' => 100,
                        'status' => 'draft',
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);

        $this->assertDatabaseCount('lost_wax_work_orders', 0);
    }

    public function test_missing_required_field_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('lost-wax.work-orders.bulk.store'), [
                'rows' => [
                    [
                        'et_code' => '',
                        'item_code' => 'LY026',
                        'po_number' => 'PO-001',
                        'po_quantity' => 100,
                        'stock_quantity' => 0,
                        'net_requirement_quantity' => 100,
                        'status' => 'draft',
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        $response->assertJsonPath('errors.0', function ($error) {
            return str_contains($error, 'ET Code wajib');
        });

        $this->assertDatabaseCount('lost_wax_work_orders', 0);
    }

    public function test_atomic_rollback_when_one_row_fails(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('lost-wax.work-orders.bulk.store'), [
                'rows' => [
                    [
                        'et_code' => 'ET232',
                        'item_code' => 'LY026',
                        'po_number' => 'PO-001',
                        'po_quantity' => 100,
                        'stock_quantity' => 0,
                        'net_requirement_quantity' => 100,
                        'status' => 'draft',
                    ],
                    [
                        'et_code' => 'ET233',
                        'item_code' => 'UNKNOWN',
                        'po_number' => 'PO-002',
                        'po_quantity' => 200,
                        'stock_quantity' => 0,
                        'net_requirement_quantity' => 200,
                        'status' => 'draft',
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);

        $this->assertDatabaseCount('lost_wax_work_orders', 0);
    }

    public function test_bulk_master_data_kpi_validation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('lost-wax.work-orders.bulk.store'), [
                'rows' => [
                    [
                        'et_code' => 'ET232',
                        'item_code' => 'LY026',
                        'po_number' => 'PO-001',
                        'po_quantity' => 100,
                        'stock_quantity' => 0,
                        'net_requirement_quantity' => 100,
                        'status' => 'draft',
                    ],
                ],
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('lost_wax_item_references', [
            'master_item_key' => 'LY026',
            'item_code_snapshot' => 'LY026',
            'item_name_snapshot' => 'Hex Nipple SUS304',
            'aisi_snapshot' => 'SUS304',
        ]);
    }

    public function test_existing_single_work_order_creation_still_works(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('lost-wax.work-orders.store'), [
            'et_code' => 'ET26-0232',
            'item_code' => 'LY026',
            'po_number' => 'PO-001',
            'customer_name' => 'PT Contoh',
            'po_quantity' => 1000,
            'stock_quantity' => 500,
            'net_requirement_quantity' => 500,
            'status' => 'planned',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('lost_wax_work_orders', [
            'et_code' => 'ET26-0232',
            'po_number' => 'PO-001',
            'po_quantity' => 1000,
        ]);
    }

    public function test_bulk_page_loads(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('lost-wax.work-orders.bulk.create'))
            ->assertOk()
            ->assertSee('Bulk Input')
            ->assertSee('Handsontable');
    }

    public function test_bulk_submission_with_empty_rows_array(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('lost-wax.work-orders.bulk.store'), [
                'rows' => [],
            ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
    }

    public function test_bulk_negative_quantity_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('lost-wax.work-orders.bulk.store'), [
                'rows' => [
                    [
                        'et_code' => 'ET232',
                        'item_code' => 'LY026',
                        'po_number' => 'PO-001',
                        'po_quantity' => -10,
                        'stock_quantity' => 0,
                        'net_requirement_quantity' => 100,
                        'status' => 'draft',
                    ],
                ],
            ]);

        $response->assertStatus(422);

        $this->assertDatabaseCount('lost_wax_work_orders', 0);
    }

    public function test_bulk_invalid_status_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('lost-wax.work-orders.bulk.store'), [
                'rows' => [
                    [
                        'et_code' => 'ET232',
                        'item_code' => 'LY026',
                        'po_number' => 'PO-001',
                        'po_quantity' => 100,
                        'stock_quantity' => 0,
                        'net_requirement_quantity' => 100,
                        'status' => 'invalid_status',
                    ],
                ],
            ]);

        $response->assertStatus(422);

        $this->assertDatabaseCount('lost_wax_work_orders', 0);
    }

    public function test_bulk_invalid_family_code_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('lost-wax.work-orders.bulk.store'), [
                'rows' => [
                    [
                        'et_code' => 'ET232',
                        'item_code' => 'LY026',
                        'po_number' => 'PO-001',
                        'po_quantity' => 100,
                        'stock_quantity' => 0,
                        'net_requirement_quantity' => 100,
                        'status' => 'draft',
                        'family_code' => '99',
                    ],
                ],
            ]);

        $response->assertStatus(422);

        $this->assertDatabaseCount('lost_wax_work_orders', 0);
    }

    public function test_bulk_multiple_valid_with_different_families(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('lost-wax.work-orders.bulk.store'), [
                'rows' => [
                    [
                        'et_code' => 'AB001',
                        'item_code' => 'LY028',
                        'po_number' => 'PO-003',
                        'po_quantity' => 500,
                        'stock_quantity' => 0,
                        'net_requirement_quantity' => 500,
                        'family_code' => '1',
                        'status' => 'active',
                    ],
                    [
                        'et_code' => 'L001',
                        'item_code' => 'LY026',
                        'po_number' => 'PO-004',
                        'po_quantity' => 300,
                        'stock_quantity' => 100,
                        'net_requirement_quantity' => 200,
                        'family_code' => '2',
                        'status' => 'draft',
                    ],
                    [
                        'et_code' => 'UN001',
                        'item_code' => 'LY027',
                        'po_number' => 'PO-005',
                        'po_quantity' => 100,
                        'stock_quantity' => 0,
                        'net_requirement_quantity' => 100,
                        'family_code' => '3',
                        'status' => 'planned',
                    ],
                ],
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertDatabaseCount('lost_wax_work_orders', 3);
        $this->assertDatabaseHas('lost_wax_work_orders', ['et_code' => 'AB001', 'family_code' => '1']);
        $this->assertDatabaseHas('lost_wax_work_orders', ['et_code' => 'L001', 'family_code' => '2']);
        $this->assertDatabaseHas('lost_wax_work_orders', ['et_code' => 'UN001', 'family_code' => '3']);
    }
}
