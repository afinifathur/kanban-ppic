<?php

namespace Tests\Feature;

use App\Models\ProductionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductionPlanCodeUniquenessTest extends TestCase
{
    use RefreshDatabase;

    protected User $ppicUser;

    protected function setUp(): void
    {
        parent::setUp();

        $accessPlanning = Permission::findOrCreate('access_planning');
        $accessExecution = Permission::findOrCreate('access_execution');

        $ppicRole = Role::findOrCreate('ppic');
        $ppicRole->syncPermissions([$accessPlanning, $accessExecution]);

        $this->ppicUser = User::factory()->create([
            'product_scope' => 'FLANGE_STAINLESS',
        ]);
        $this->ppicUser->assignRole('ppic');
    }

    /**
     * TEST 1: New unique Production Code is accepted.
     */
    public function test_new_unique_production_code_is_accepted(): void
    {
        $payload = [
            'title' => 'Rencana Baru September',
            'date' => '2026-09-15',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'plans' => [
                [
                    'row_number' => 1,
                    'code' => '268IL900',
                    'item_code' => '4.101105K.A0015',
                    'item_name' => 'SS304 BLIND 1/2"',
                    'po_number' => 'PO-2026-001',
                    'qty_planned' => 50,
                    'line_number' => 1,
                    'customer' => 'PT Test',
                ],
            ],
        ];

        $response = $this->actingAs($this->ppicUser)->postJson(route('plan.store'), $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'processed' => 1,
        ]);

        $this->assertDatabaseHas('production_plans', [
            'code' => '268IL900',
            'qty_planned' => 50,
            'product_scope' => 'FLANGE_STAINLESS',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
        ]);
    }

    /**
     * TEST 2: Existing Production Code is rejected even if item_code and po_number differ.
     */
    public function test_existing_production_code_is_rejected_even_if_item_and_po_differ(): void
    {
        ProductionPlan::create([
            'code' => '268IL001',
            'title' => 'Plan Lama',
            'item_code' => 'ITEM-ORIGINAL-A',
            'item_name' => 'Original Item',
            'po_number' => 'PO-ORIGINAL-A',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'status' => 'planning',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'product_scope' => 'FLANGE_STAINLESS',
        ]);

        $payload = [
            'title' => 'Plan Baru',
            'date' => '2026-09-15',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'plans' => [
                [
                    'row_number' => 5,
                    'code' => '268IL001',
                    'item_code' => 'ITEM-DIFFERENT-B',
                    'item_name' => 'Different Item',
                    'po_number' => 'PO-DIFFERENT-B',
                    'qty_planned' => 20,
                    'line_number' => 1,
                ],
            ],
        ];

        $response = $this->actingAs($this->ppicUser)->postJson(route('plan.store'), $payload);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'rejected_count' => 1,
        ]);
        $response->assertJsonPath('errors.0.row', 5);
        $response->assertJsonPath('errors.0.code', '268IL001');
        $this->assertStringContainsString('sudah terdaftar di sistem', $response->json('errors.0.reason'));
    }

    /**
     * TEST 3: Existing Production Code is rejected even if production_domain differs.
     */
    public function test_existing_production_code_is_rejected_across_production_domains(): void
    {
        ProductionPlan::create([
            'code' => '268AB37',
            'title' => 'Lost Wax Batch',
            'item_code' => 'ITEM-LW',
            'item_name' => 'Lost Wax Item',
            'po_number' => 'PO-LW',
            'qty_planned' => 50,
            'qty_remaining' => 50,
            'line_number' => 1,
            'status' => 'planning',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'product_scope' => 'FLANGE_STAINLESS',
        ]);

        $payload = [
            'title' => 'Sand Casting Batch',
            'date' => '2026-09-15',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'plans' => [
                [
                    'row_number' => 1,
                    'code' => '268AB37',
                    'item_code' => 'ITEM-SC',
                    'item_name' => 'Sand Casting Item',
                    'po_number' => 'PO-SC',
                    'qty_planned' => 30,
                    'line_number' => 1,
                ],
            ],
        ];

        $response = $this->actingAs($this->ppicUser)->postJson(route('plan.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.code', '268AB37');
    }

    /**
     * TEST 4: Existing Production Code is rejected even if product_scope differs.
     */
    public function test_existing_production_code_is_rejected_across_product_scopes(): void
    {
        ProductionPlan::create([
            'code' => '268KS103',
            'title' => 'Flange Besi Plan',
            'item_code' => 'ITEM-BESI',
            'item_name' => 'CS Flange',
            'po_number' => 'PO-BESI',
            'qty_planned' => 80,
            'qty_remaining' => 80,
            'line_number' => 1,
            'status' => 'planning',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'product_scope' => 'FLANGE_BESI',
        ]);

        $payload = [
            'title' => 'Stainless Plan',
            'date' => '2026-09-15',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'plans' => [
                [
                    'row_number' => 2,
                    'code' => '268KS103',
                    'item_code' => 'ITEM-SS',
                    'item_name' => 'SS Flange',
                    'po_number' => 'PO-SS',
                    'qty_planned' => 40,
                    'line_number' => 1,
                ],
            ],
        ];

        $response = $this->actingAs($this->ppicUser)->postJson(route('plan.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.code', '268KS103');
    }

    /**
     * TEST 5: Duplicate Production Code within the same import batch is rejected with row-level diagnostic.
     */
    public function test_duplicate_production_code_within_same_batch_is_rejected_with_row_diagnostics(): void
    {
        $payload = [
            'title' => 'Batch With Internal Duplicate',
            'date' => '2026-09-15',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'plans' => [
                [
                    'row_number' => 1,
                    'code' => '268IL010',
                    'item_code' => 'ITEM-1',
                    'item_name' => 'Item 1',
                    'po_number' => 'PO-1',
                    'qty_planned' => 10,
                    'line_number' => 1,
                ],
                [
                    'row_number' => 2,
                    'code' => '268IL020',
                    'item_code' => 'ITEM-2',
                    'item_name' => 'Item 2',
                    'po_number' => 'PO-2',
                    'qty_planned' => 20,
                    'line_number' => 2,
                ],
                [
                    'row_number' => 3,
                    'code' => '268IL010', // duplicate of row 1
                    'item_code' => 'ITEM-3',
                    'item_name' => 'Item 3',
                    'po_number' => 'PO-3',
                    'qty_planned' => 30,
                    'line_number' => 3,
                ],
            ],
        ];

        $response = $this->actingAs($this->ppicUser)->postJson(route('plan.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.row', 3);
        $response->assertJsonPath('errors.0.code', '268IL010');
        $this->assertStringContainsString('duplikat di dalam batch ini (sama dengan Baris 1)', $response->json('errors.0.reason'));
    }

    /**
     * TEST 6: Case normalization (lowercase conflicts with uppercase and persists as uppercase).
     */
    public function test_case_normalization_conflicts_and_persists_as_uppercase(): void
    {
        // Part A: Lowercase conflicts with existing uppercase
        ProductionPlan::create([
            'code' => '268IL009',
            'title' => 'Existing Upper',
            'item_code' => 'ITEM-UPPER',
            'item_name' => 'Item Upper',
            'po_number' => 'PO-UPPER',
            'qty_planned' => 10,
            'qty_remaining' => 10,
            'line_number' => 1,
            'status' => 'planning',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'product_scope' => 'FLANGE_STAINLESS',
        ]);

        $payload = [
            'title' => 'Lower test',
            'date' => '2026-09-15',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'plans' => [
                [
                    'row_number' => 1,
                    'code' => '268il009', // lowercase
                    'item_code' => 'ITEM-LOWER',
                    'item_name' => 'Item Lower',
                    'po_number' => 'PO-LOWER',
                    'qty_planned' => 15,
                    'line_number' => 1,
                ],
            ],
        ];

        $resConflict = $this->actingAs($this->ppicUser)->postJson(route('plan.store'), $payload);
        $resConflict->assertStatus(422);
        $resConflict->assertJsonPath('errors.0.code', '268IL009');

        // Part B: Lowercase new code persists as uppercase in DB
        $payloadNew = [
            'title' => 'New Lower',
            'date' => '2026-09-15',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'plans' => [
                [
                    'row_number' => 1,
                    'code' => '268il099', // lowercase
                    'item_code' => 'ITEM-NEW',
                    'item_name' => 'Item New',
                    'po_number' => 'PO-NEW',
                    'qty_planned' => 25,
                    'line_number' => 1,
                ],
            ],
        ];

        $resSuccess = $this->actingAs($this->ppicUser)->postJson(route('plan.store'), $payloadNew);
        $resSuccess->assertStatus(200);

        $this->assertDatabaseHas('production_plans', [
            'code' => '268IL099', // saved in uppercase
        ]);
    }

    /**
     * TEST 7: Whitespace normalization.
     */
    public function test_whitespace_normalization(): void
    {
        $payload = [
            'title' => 'Whitespace test',
            'date' => '2026-09-15',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'plans' => [
                [
                    'row_number' => 1,
                    'code' => "  268IL555 \t ",
                    'item_code' => ' ITEM-TRIM ',
                    'item_name' => ' Item Trim ',
                    'po_number' => ' PO-TRIM ',
                    'qty_planned' => 45,
                    'line_number' => 1,
                ],
            ],
        ];

        $response = $this->actingAs($this->ppicUser)->postJson(route('plan.store'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('production_plans', [
            'code' => '268IL555',
            'item_code' => 'ITEM-TRIM',
            'item_name' => 'Item Trim',
            'po_number' => 'PO-TRIM',
        ]);
    }

    /**
     * TEST 8: Partially populated row is not silently discarded and receives clear validation reason.
     */
    public function test_partially_populated_row_is_flagged_with_validation_reason(): void
    {
        $payload = [
            'title' => 'Incomplete row test',
            'date' => '2026-09-15',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'plans' => [
                [
                    'row_number' => 4,
                    'code' => '268IL004',
                    'item_code' => '', // missing item_code
                    'item_name' => 'Item 4',
                    'po_number' => 'PO-4',
                    'qty_planned' => 10,
                    'line_number' => 1,
                ],
                [
                    'row_number' => 5,
                    'code' => '', // missing code
                    'item_code' => 'ITEM-5',
                    'item_name' => 'Item 5',
                    'po_number' => 'PO-5',
                    'qty_planned' => 0, // invalid qty
                    'line_number' => 2,
                ],
            ],
        ];

        $response = $this->actingAs($this->ppicUser)->postJson(route('plan.store'), $payload);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'rejected_count' => 3, // row 4 missing item_code, row 5 missing code & invalid qty
        ]);
    }

    /**
     * TEST 9: Batch containing invalid/duplicate rows does not silently partially commit (0 inserted).
     */
    public function test_batch_with_errors_does_not_partially_commit(): void
    {
        $initialCount = ProductionPlan::count();

        $payload = [
            'title' => 'Atomic Transaction Test',
            'date' => '2026-09-15',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'plans' => [
                [
                    'row_number' => 1,
                    'code' => '268IL701',
                    'item_code' => 'ITEM-VALID-1',
                    'item_name' => 'Item Valid 1',
                    'po_number' => 'PO-VALID-1',
                    'qty_planned' => 10,
                    'line_number' => 1,
                ],
                [
                    'row_number' => 2,
                    'code' => '268IL702',
                    'item_code' => '', // ERROR
                    'item_name' => 'Item Error',
                    'po_number' => 'PO-ERROR',
                    'qty_planned' => 10,
                    'line_number' => 2,
                ],
            ],
        ];

        $response = $this->actingAs($this->ppicUser)->postJson(route('plan.store'), $payload);

        $response->assertStatus(422);

        // Assert that row 1 was NOT inserted
        $this->assertEquals($initialCount, ProductionPlan::count());
        $this->assertDatabaseMissing('production_plans', [
            'code' => '268IL701',
        ]);
    }

    /**
     * TEST 10: Bulk import with realistic number of rows (e.g. 100 rows) succeeds.
     */
    public function test_bulk_import_with_many_rows_succeeds(): void
    {
        $plans = [];
        for ($i = 1; $i <= 100; $i++) {
            $plans[] = [
                'row_number' => $i,
                'code' => sprintf('268BULK%03d', $i),
                'item_code' => sprintf('ITEM-BULK-%03d', $i),
                'item_name' => sprintf('Item Bulk %03d', $i),
                'po_number' => 'PO-BULK-2026',
                'qty_planned' => 50,
                'line_number' => $i,
                'customer' => 'Customer Bulk',
            ];
        }

        $payload = [
            'title' => 'Bulk Import 100 Items',
            'date' => '2026-09-15',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'plans' => $plans,
        ];

        $response = $this->actingAs($this->ppicUser)->postJson(route('plan.store'), $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'processed' => 100,
        ]);

        $this->assertEquals(100, ProductionPlan::where('title', 'Bulk Import 100 Items')->count());
    }

    /**
     * TEST 11: Historical duplicate codes in database remain untouched.
     */
    public function test_historical_duplicate_codes_remain_untouched_and_reject_new_reuse(): void
    {
        // Seed 2 historical records with identical code 268AB37
        $rec1 = ProductionPlan::create([
            'code' => '268AB37',
            'title' => 'Historical LW',
            'item_code' => 'ITEM-HIST-1',
            'item_name' => 'Historical Item 1',
            'po_number' => 'PO-HIST-1',
            'qty_planned' => 10,
            'qty_remaining' => 10,
            'line_number' => 1,
            'status' => 'planning',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'product_scope' => 'FITTING_STAINLESS',
        ]);

        $rec2 = ProductionPlan::create([
            'code' => '268AB37',
            'title' => 'Historical SC',
            'item_code' => 'ITEM-HIST-2',
            'item_name' => 'Historical Item 2',
            'po_number' => 'PO-HIST-2',
            'qty_planned' => 20,
            'qty_remaining' => 20,
            'line_number' => 1,
            'status' => 'planning',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'product_scope' => 'FLANGE_STAINLESS',
        ]);

        // Attempting to import 268AB37 again must be rejected
        $payload = [
            'title' => 'New Import Attempting Duplicate',
            'date' => '2026-09-15',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'plans' => [
                [
                    'row_number' => 1,
                    'code' => '268AB37',
                    'item_code' => 'ITEM-NEW-3',
                    'item_name' => 'New Item 3',
                    'po_number' => 'PO-NEW-3',
                    'qty_planned' => 30,
                    'line_number' => 1,
                ],
            ],
        ];

        $response = $this->actingAs($this->ppicUser)->postJson(route('plan.store'), $payload);
        $response->assertStatus(422);
        $this->assertStringContainsString('268AB37', $response->json('errors.0.code'));

        // Verify historical records remain intact and unchanged
        $this->assertDatabaseHas('production_plans', ['id' => $rec1->id, 'code' => '268AB37', 'item_code' => 'ITEM-HIST-1']);
        $this->assertDatabaseHas('production_plans', ['id' => $rec2->id, 'code' => '268AB37', 'item_code' => 'ITEM-HIST-2']);
    }
}
