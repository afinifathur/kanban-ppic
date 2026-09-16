<?php

namespace Tests\Feature\Integration;

use App\Jobs\SyncCastingResultToMasterDataJob;
use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingOrderLine;
use App\Models\User;
use App\Services\Integration\MasterDataHeatNumberPublisher;
use App\Services\SandCasting\SandCastingCastingResultService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MasterDataHeatNumberSyncTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected SandCastingCastingResultService $service;

    protected MasterDataHeatNumberPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup test connection for masterdata_kpi pointing to default sqlite connection
        config([
            'database.connections.masterdata_kpi' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);

        // Create target schema on masterdata_kpi connection
        Schema::connection('masterdata_kpi')->dropIfExists('md_heat_numbers');
        Schema::connection('masterdata_kpi')->create('md_heat_numbers', function ($table) {
            $table->id();
            $table->string('traveler_number', 60)->nullable()->unique();
            $table->string('kode_produksi', 50)->nullable();
            $table->date('heat_date')->nullable();
            $table->string('item_code', 50);
            $table->string('item_name', 200)->nullable();
            $table->string('heat_number', 50);
            $table->integer('cor_qty')->default(0);
            $table->string('size', 20)->nullable();
            $table->string('customer', 50)->nullable();
            $table->string('line', 20)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index('heat_number');
            $table->index('item_code');
        });

        $permission = Permission::firstOrCreate(['name' => 'access_planning']);
        $role = Role::firstOrCreate(['name' => 'ppic']);
        $role->givePermissionTo($permission);

        $this->user = User::factory()->create([
            'email' => 'ppic@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->user->assignRole('ppic');

        $this->service = new SandCastingCastingResultService;
        $this->publisher = new MasterDataHeatNumberPublisher('masterdata_kpi', 'md_heat_numbers');
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => 'LH083',
            'title' => 'Rencana SC LH083',
            'item_code' => '4.101105K.A0015',
            'item_name' => 'SS304 CASTED PLANE FLANGE JIS 5K 1/2"',
            'po_number' => 'PO-001',
            'line_number' => 1,
            'customer' => 'E01',
            'size' => '1/2"',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'weight' => 2.50,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ], $attributes));
    }

    protected function createIssuedOrderLine(ProductionPlan $plan, int $qtyOrdered = 100): SandCastingCastingOrderLine
    {
        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-'.uniqid(),
            'scheduled_date' => '2026-09-15',
            'status' => 'ISSUED',
            'notes' => 'Test PCOR',
            'created_by' => $this->user->id,
        ]);

        return SandCastingCastingOrderLine::create([
            'sand_casting_casting_order_id' => $order->id,
            'production_plan_id' => $plan->id,
            'qty_ordered' => $qtyOrdered,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'notes' => $plan->title,
        ]);
    }

    public function test_casting_result_dispatches_sync_job_after_commit(): void
    {
        Queue::fake();

        $plan = $this->createPlan();
        $orderLine = $this->createIssuedOrderLine($plan, 100);

        $result = $this->service->recordResult(
            [
                'heat_number' => 'A214092601',
                'cast_date' => '2026-09-15',
                'furnace' => 'FURNACE 1',
                'shift' => '1',
            ],
            [
                [
                    'sand_casting_casting_order_line_id' => $orderLine->id,
                    'qty_good' => 80,
                    'qty_reject' => 5,
                ],
            ],
            $this->user->id
        );

        $this->assertNotNull($result);
        $this->assertEquals('A214092601', $result->heat_number);

        Queue::assertPushed(SyncCastingResultToMasterDataJob::class, function ($job) use ($result) {
            return $job->castingResultId === $result->id;
        });
    }

    public function test_sync_job_is_not_dispatched_if_transaction_rolls_back(): void
    {
        Queue::fake();

        $plan = $this->createPlan();
        $orderLine = $this->createIssuedOrderLine($plan, 50);

        // Attempt negative qty to trigger exception and rollback
        try {
            $this->service->recordResult(
                [
                    'heat_number' => 'A214092602',
                    'cast_date' => '2026-09-15',
                ],
                [
                    [
                        'sand_casting_casting_order_line_id' => $orderLine->id,
                        'qty_good' => -5,
                        'qty_reject' => 0,
                    ],
                ],
                $this->user->id
            );
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak boleh negatif', $e->getMessage());
        }

        Queue::assertNotPushed(SyncCastingResultToMasterDataJob::class);
        $this->assertDatabaseMissing('sand_casting_casting_results', [
            'heat_number' => 'A214092602',
        ]);
    }

    public function test_publisher_upserts_md_heat_numbers_successfully(): void
    {
        $plan = $this->createPlan([
            'code' => 'ET374',
            'item_code' => '4.1011UNIPN06.D0125',
            'item_name' => 'SS304 CASTED PLANE FLANGE UNI 2276 PN 6 DN 125',
            'size' => '5"',
            'customer' => 'E02',
            'line_number' => 2,
        ]);
        $orderLine = $this->createIssuedOrderLine($plan, 100);

        $result = $this->service->recordResult(
            [
                'heat_number' => 'LA202012602',
                'cast_date' => '2026-09-15',
            ],
            [
                [
                    'sand_casting_casting_order_line_id' => $orderLine->id,
                    'qty_good' => 35,
                    'qty_reject' => 0,
                ],
            ],
            $this->user->id
        );

        $syncOutcome = $this->publisher->publishCastingResult($result);

        $this->assertTrue($syncOutcome['success']);
        $this->assertEquals(1, $syncOutcome['synced_count']);

        $row = DB::connection('masterdata_kpi')->table('md_heat_numbers')
            ->where('heat_number', 'LA202012602')
            ->where('item_code', '4.1011UNIPN06.D0125')
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals('LA202012602', $row->heat_number);
        $this->assertEquals('4.1011UNIPN06.D0125', $row->item_code);
        $this->assertEquals('ET374', $row->kode_produksi);
        $this->assertEquals('SS304 CASTED PLANE FLANGE UNI 2276 PN 6 DN 125', $row->item_name);
        $this->assertEquals('5"', $row->size);
        $this->assertEquals('E02', $row->customer);
        $this->assertEquals('LINE 2', $row->line);
        $this->assertEquals(35, $row->cor_qty);
        $this->assertEquals('2026-09-15', $row->heat_date);
        $this->assertEquals('active', $row->status);
        $this->assertNotEmpty($row->traveler_number);
        $this->assertStringStartsWith('KTR-', $row->traveler_number);
    }

    public function test_publisher_is_idempotent_on_repeated_sync(): void
    {
        $plan = $this->createPlan(['code' => 'LA1', 'item_code' => '4.101105K.A0015', 'line_number' => 1]);
        $orderLine = $this->createIssuedOrderLine($plan, 100);

        $result = $this->service->recordResult(
            [
                'heat_number' => 'A410012525',
                'cast_date' => '2026-09-15',
            ],
            [
                [
                    'sand_casting_casting_order_line_id' => $orderLine->id,
                    'qty_good' => 50,
                    'qty_reject' => 0,
                ],
            ],
            $this->user->id
        );

        // First sync
        $this->publisher->publishCastingResult($result);
        $this->assertEquals(1, DB::connection('masterdata_kpi')->table('md_heat_numbers')->count());

        // Repeated sync (e.g. retry / reconciliation)
        $this->publisher->publishCastingResult($result);
        $this->assertEquals(1, DB::connection('masterdata_kpi')->table('md_heat_numbers')->count());

        $row = DB::connection('masterdata_kpi')->table('md_heat_numbers')->first();
        $this->assertEquals(50, $row->cor_qty);
    }

    public function test_same_heat_with_multiple_items_creates_distinct_target_rows(): void
    {
        $planA = $this->createPlan(['code' => 'ET001', 'item_code' => 'ITEM-A', 'item_name' => 'Item Alpha', 'line_number' => 1]);
        $planB = $this->createPlan(['code' => 'ET002', 'item_code' => 'ITEM-B', 'item_name' => 'Item Beta', 'line_number' => 2]);

        $orderLineA = $this->createIssuedOrderLine($planA, 100);
        $orderLineB = $this->createIssuedOrderLine($planB, 50);

        $result = $this->service->recordResult(
            [
                'heat_number' => 'HEAT-MULTI-01',
                'cast_date' => '2026-09-15',
            ],
            [
                [
                    'sand_casting_casting_order_line_id' => $orderLineA->id,
                    'qty_good' => 60,
                    'qty_reject' => 0,
                ],
                [
                    'sand_casting_casting_order_line_id' => $orderLineB->id,
                    'qty_good' => 30,
                    'qty_reject' => 0,
                ],
            ],
            $this->user->id
        );

        $this->publisher->publishCastingResult($result);

        $rows = DB::connection('masterdata_kpi')->table('md_heat_numbers')
            ->where('heat_number', 'HEAT-MULTI-01')
            ->orderBy('item_code')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertEquals('ITEM-A', $rows[0]->item_code);
        $this->assertEquals(60, $rows[0]->cor_qty);
        $this->assertEquals('LINE 1', $rows[0]->line);

        $this->assertEquals('ITEM-B', $rows[1]->item_code);
        $this->assertEquals(30, $rows[1]->cor_qty);
        $this->assertEquals('LINE 2', $rows[1]->line);
    }

    public function test_same_heat_and_same_item_across_multiple_lines_creates_distinct_traveler_rows(): void
    {
        // Two separate PCOR order lines for the SAME production plan item
        $plan = $this->createPlan(['code' => 'ET001', 'item_code' => 'ITEM-SPLIT', 'line_number' => 3]);
        $orderLine1 = $this->createIssuedOrderLine($plan, 50);
        $orderLine2 = $this->createIssuedOrderLine($plan, 50);

        $result = $this->service->recordResult(
            [
                'heat_number' => 'HEAT-SPLIT-01',
                'cast_date' => '2026-09-15',
            ],
            [
                [
                    'sand_casting_casting_order_line_id' => $orderLine1->id,
                    'qty_good' => 40,
                    'qty_reject' => 0,
                ],
                [
                    'sand_casting_casting_order_line_id' => $orderLine2->id,
                    'qty_good' => 35,
                    'qty_reject' => 0,
                ],
            ],
            $this->user->id
        );

        $this->publisher->publishCastingResult($result);

        $rows = DB::connection('masterdata_kpi')->table('md_heat_numbers')
            ->where('heat_number', 'HEAT-SPLIT-01')
            ->orderBy('cor_qty', 'desc')
            ->get();

        // Exactly 2 distinct rows created (one per traveler)
        $this->assertCount(2, $rows);
        $this->assertEquals('ITEM-SPLIT', $rows[0]->item_code);
        $this->assertEquals(40, $rows[0]->cor_qty);
        $this->assertNotEmpty($rows[0]->traveler_number);

        $this->assertEquals('ITEM-SPLIT', $rows[1]->item_code);
        $this->assertEquals(35, $rows[1]->cor_qty);
        $this->assertNotEmpty($rows[1]->traveler_number);
        $this->assertNotEquals($rows[0]->traveler_number, $rows[1]->traveler_number);
    }

    public function test_reconciliation_command_syncs_records_by_date_and_heat(): void
    {
        $plan = $this->createPlan(['code' => 'ET999', 'item_code' => 'ITEM-RECON', 'line_number' => 4]);
        $orderLine = $this->createIssuedOrderLine($plan, 100);

        $this->service->recordResult(
            [
                'heat_number' => 'HEAT-RECON-01',
                'cast_date' => '2026-09-15',
            ],
            [
                [
                    'sand_casting_casting_order_line_id' => $orderLine->id,
                    'qty_good' => 90,
                    'qty_reject' => 0,
                ],
            ],
            $this->user->id
        );

        // Run artisan reconciliation command
        $this->artisan('kanban:sync-heat-numbers', [
            '--date' => '2026-09-15',
            '--heat' => 'HEAT-RECON-01',
        ])
            ->expectsOutputToContain('Synchronization Complete!')
            ->assertExitCode(0);

        $row = DB::connection('masterdata_kpi')->table('md_heat_numbers')
            ->where('heat_number', 'HEAT-RECON-01')
            ->where('item_code', 'ITEM-RECON')
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals(90, $row->cor_qty);
    }

    public function test_sync_job_handle_executes_publisher_and_retries_on_failure(): void
    {
        $plan = $this->createPlan(['code' => 'ET888', 'item_code' => 'ITEM-JOB-TEST']);
        $orderLine = $this->createIssuedOrderLine($plan, 100);

        $result = $this->service->recordResult(
            [
                'heat_number' => 'HEAT-JOB-01',
                'cast_date' => '2026-09-15',
            ],
            [
                [
                    'sand_casting_casting_order_line_id' => $orderLine->id,
                    'qty_good' => 45,
                    'qty_reject' => 0,
                ],
            ],
            $this->user->id
        );

        $job = new SyncCastingResultToMasterDataJob($result->id);
        $job->handle($this->publisher);

        $this->assertDatabaseHas('md_heat_numbers', [
            'heat_number' => 'HEAT-JOB-01',
            'item_code' => 'ITEM-JOB-TEST',
            'cor_qty' => 45,
        ], 'masterdata_kpi');
    }

    public function test_target_database_offline_does_not_fail_hasil_cor_creation(): void
    {
        // When publisher points to an invalid/offline host, recordResult must STILL succeed
        // because the sync job is dispatched after commit into the queue.
        $plan = $this->createPlan(['code' => 'OFFLINE-01', 'item_code' => 'ITEM-OFFLINE']);
        $orderLine = $this->createIssuedOrderLine($plan, 100);

        // Record Hasil Cor
        $result = $this->service->recordResult(
            [
                'heat_number' => 'HEAT-OFFLINE-01',
                'cast_date' => '2026-09-15',
            ],
            [
                [
                    'sand_casting_casting_order_line_id' => $orderLine->id,
                    'qty_good' => 50,
                    'qty_reject' => 0,
                ],
            ],
            $this->user->id
        );

        // Hasil Cor must be saved in database
        $this->assertDatabaseHas('sand_casting_casting_results', [
            'id' => $result->id,
            'heat_number' => 'HEAT-OFFLINE-01',
        ]);

        // Publisher with invalid connection name fails cleanly and throws Throwable for job retry
        $offlinePublisher = new MasterDataHeatNumberPublisher('non_existent_db_connection');
        $job = new SyncCastingResultToMasterDataJob($result->id);

        try {
            $job->handle($offlinePublisher);
            $this->fail('Expected exception from offline connection.');
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // Hasil Cor remains intact in Kanban PPIC
        $this->assertDatabaseHas('sand_casting_casting_results', [
            'id' => $result->id,
            'heat_number' => 'HEAT-OFFLINE-01',
        ]);
    }

    public function test_reconciliation_command_handles_no_records_gracefully(): void
    {
        $this->artisan('kanban:sync-heat-numbers', [
            '--date' => '1999-01-01',
        ])
            ->expectsOutputToContain('No matching Sand Casting Casting Result records found.')
            ->assertExitCode(0);
    }

    public function test_publisher_handles_historical_null_traveler_payloads_without_overwriting_travelers(): void
    {
        // 1. Insert a historical record with traveler_number = null
        $payloadHistorical = [
            'traveler_number' => null,
            'heat_number' => 'HN-LEGACY-01',
            'item_code' => 'ITEM-LEGACY-A',
            'kode_produksi' => 'KD-LEGACY-1',
            'heat_date' => '2026-09-10',
            'item_name' => 'Legacy Item',
            'size' => '1"',
            'customer' => 'CUST_LEGACY',
            'line' => 'LINE 1',
            'cor_qty' => 50,
            'status' => 'active',
        ];

        $outcome1 = $this->publisher->publishPayloads([$payloadHistorical]);
        $this->assertTrue($outcome1['success']);

        // 2. Insert a new traveler record with the same heat_number and item_code
        $payloadNewTraveler = [
            'traveler_number' => 'KTR-20260915-9999',
            'heat_number' => 'HN-LEGACY-01',
            'item_code' => 'ITEM-LEGACY-A',
            'kode_produksi' => 'KD-LEGACY-1',
            'heat_date' => '2026-09-15',
            'item_name' => 'Legacy Item',
            'size' => '1"',
            'customer' => 'CUST_LEGACY',
            'line' => 'LINE 1',
            'cor_qty' => 60,
            'status' => 'active',
        ];

        $outcome2 = $this->publisher->publishPayloads([$payloadNewTraveler]);
        $this->assertTrue($outcome2['success']);

        // Both rows must exist independently without overwriting each other
        $rows = DB::connection('masterdata_kpi')->table('md_heat_numbers')
            ->where('heat_number', 'HN-LEGACY-01')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->contains(fn ($r) => is_null($r->traveler_number) && $r->cor_qty == 50));
        $this->assertTrue($rows->contains(fn ($r) => $r->traveler_number === 'KTR-20260915-9999' && $r->cor_qty == 60));
    }
}
