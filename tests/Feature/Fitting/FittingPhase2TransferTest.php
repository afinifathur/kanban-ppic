<?php

namespace Tests\Feature\Fitting;

use App\Models\FittingItem;
use App\Models\FittingKitirDecision;
use App\Models\FittingStageExecution;
use App\Models\FittingTransfer;
use App\Models\LostWaxCastingResult;
use App\Models\LostWaxCastingResultTree;
use App\Models\LostWaxKitir;
use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeAllocation;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\Fitting\FittingKitirDecisionService;
use App\Services\Fitting\FittingTransferService;
use App\Services\LostWax\LostWaxCastingResultService;
use App\Services\LostWax\LostWaxKitirResolverService;
use Carbon\Carbon;
use Database\Seeders\AuditorUserSeeder;
use Database\Seeders\FittingSpvUserSeeder;
use Database\Seeders\QcFittingUserSeeder;
use Database\Seeders\SalesVisibilityUserSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FittingPhase2TransferTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 5000;

    private User $systemAdmin;

    private User $ppicFitting;

    private User $adminPpicPf;

    private User $adminQcFitting;

    private User $auditor;

    private User $sales;

    private User $spvNettoPf;

    private LostWaxKitirResolverService $kitirResolver;

    private FittingTransferService $transferService;

    private FittingKitirDecisionService $decisionService;

    private LostWaxCastingResultService $castingResultService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setEnvVar('AUDITOR_INITIAL_PASSWORD', 'AudPass123!');
        $this->setEnvVar('SALES_INITIAL_PASSWORD', 'SalesPass123!');
        $this->setEnvVar('FITTING_SPV_INITIAL_PASSWORD', 'FitPass123!');

        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'])->syncPermissions([$permPlanning, $permExecution]);
        Role::firstOrCreate(['name' => 'ppic', 'guard_name' => 'web'])->syncPermissions([$permPlanning, $permExecution]);

        (new QcFittingUserSeeder)->run();
        (new AuditorUserSeeder)->run();
        (new SalesVisibilityUserSeeder)->run();

        $this->systemAdmin = User::create([
            'name' => 'MR System Admin',
            'email' => 'mr@peroniks.com',
            'password' => Hash::make('password'),
        ]);
        $this->systemAdmin->assignRole('admin');

        $this->ppicFitting = User::create([
            'name' => 'PPIC Fitting',
            'email' => 'ppicfitting@peroniks.com',
            'password' => Hash::make('password'),
            'product_scope' => 'FITTING_STAINLESS',
        ]);
        $this->ppicFitting->assignRole('ppic');

        $this->adminPpicPf = User::create([
            'name' => 'Admin PPIC PF',
            'email' => 'adminppicpf@peroniks.com',
            'password' => Hash::make('password'),
        ]);
        $this->adminPpicPf->assignRole('admin');

        (new FittingSpvUserSeeder)->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->systemAdmin->refresh();
        $this->ppicFitting->refresh();
        $this->adminPpicPf->refresh();
        $this->adminQcFitting = User::where('email', 'adminqcfitting@peroniks.com')->firstOrFail();
        $this->auditor = User::where('email', 'auditor@peroniks.com')->firstOrFail();
        $this->sales = User::where('email', 'sales@peroniks.com')->firstOrFail();
        $this->spvNettoPf = User::where('email', 'spvnettopf@peroniks.com')->firstOrFail();

        $this->kitirResolver = app(LostWaxKitirResolverService::class);
        $this->transferService = app(FittingTransferService::class);
        $this->decisionService = app(FittingKitirDecisionService::class);
        $this->castingResultService = app(LostWaxCastingResultService::class);
    }

    protected function tearDown(): void
    {
        foreach (['AUDITOR_INITIAL_PASSWORD', 'SALES_INITIAL_PASSWORD', 'FITTING_SPV_INITIAL_PASSWORD'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    private function setEnvVar(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    /**
     * Helper: Create a ProductionPlan + PrintOrderLine.
     */
    private function createPlanWithPrintLine(array $overrides = []): array
    {
        $n = self::$seq++;
        $code = $overrides['code'] ?? "PF-CODE-{$n}";

        $plan = ProductionPlan::create([
            'code' => $code,
            'title' => 'Rencana Fitting',
            'item_code' => $overrides['item_code'] ?? "ITM-{$n}",
            'item_name' => $overrides['item_name'] ?? 'Elbow 90 2" SS304',
            'aisi' => $overrides['aisi'] ?? '304',
            'size' => $overrides['size'] ?? '2"',
            'weight' => array_key_exists('weight', $overrides) ? $overrides['weight'] : 0.50,
            'po_number' => $overrides['po_number'] ?? "PO-{$n}",
            'po_quantity' => $overrides['po_quantity'] ?? 1000,
            'qty_planned' => $overrides['qty_planned'] ?? 1000,
            'qty_remaining' => $overrides['qty_remaining'] ?? 1000,
            'line_number' => array_key_exists('line_number', $overrides) ? $overrides['line_number'] : 2,
            'customer' => $overrides['customer'] ?? 'PT FITTING TEST',
            'product_scope' => 'FITTING_STAINLESS',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'status' => 'active',
        ]);

        $printOrder = LostWaxPrintOrder::create([
            'print_order_number' => "LW-PO-{$n}",
            'scheduled_date' => Carbon::today()->format('Y-m-d'),
            'status' => 'ISSUED',
            'created_by' => $this->systemAdmin->id,
        ]);

        $line = LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $printOrder->id,
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'aisi' => $plan->aisi,
            'size' => $plan->size,
            'qty_ordered' => $plan->qty_planned,
        ]);

        return [$plan, $line];
    }

    /**
     * Helper: Create a COMPLETED LostWaxCastingResult with tree specs linked to real PrintOrderLines / Allocations.
     *
     * @param  array<int, array{
     *     qty: int,
     *     gross?: int,
     *     print_line?: LostWaxPrintOrderLine,
     *     allocations?: array<int, array{print_line: LostWaxPrintOrderLine, allocated_qty: int}>
     * }>  $treeSpecs
     */
    private function createCastingResultWithPlans(array $treeSpecs, string $status = 'COMPLETED'): LostWaxCastingResult
    {
        $n = self::$seq++;
        $totalPcs = array_sum(array_column($treeSpecs, 'qty'));

        $result = LostWaxCastingResult::create([
            'heat_number' => "H-PF-{$n}",
            'cast_date' => Carbon::today()->format('Y-m-d'),
            'furnace' => '1',
            'shift' => '1',
            'operator_name' => 'Op Cor',
            'total_trees' => count($treeSpecs),
            'total_pcs' => $totalPcs,
            'status' => $status,
            'recorded_by' => $this->systemAdmin->id,
        ]);

        foreach ($treeSpecs as $idx => $spec) {
            $tNum = self::$seq++;
            $barcode = sprintf('26%08d', $tNum);

            $printLine = $spec['print_line'] ?? null;
            $allocSpecs = $spec['allocations'] ?? [];

            $physTree = LostWaxTree::create([
                'lost_wax_print_order_line_id' => $printLine?->id ?? ($allocSpecs[0]['print_line']->id ?? null),
                'barcode' => $barcode,
                'tree_number' => $idx + 1,
                'daily_sequence' => $tNum,
                'production_date' => Carbon::today()->format('Y-m-d'),
                'family_code' => '1',
                'require_layer_7' => false,
                'quantity' => $spec['gross'] ?? $spec['qty'],
                'current_stage' => 'cor',
                'status' => 'active',
            ]);

            if (! empty($allocSpecs)) {
                $codes = [];
                $custs = [];
                $items = [];
                foreach ($allocSpecs as $as) {
                    LostWaxTreeAllocation::create([
                        'lost_wax_tree_id' => $physTree->id,
                        'lost_wax_print_order_line_id' => $as['print_line']->id,
                        'allocated_qty' => $as['allocated_qty'],
                    ]);
                    $p = $as['print_line']->productionPlan;
                    $codes[] = $p?->code ?? $as['print_line']->code;
                    $custs[] = $p?->customer ?? $as['print_line']->customer;
                    $items[] = $p?->item_name ?? $as['print_line']->item_name;
                }
                $codeStr = implode(', ', array_unique($codes));
                $custStr = implode(', ', array_unique($custs));
                $itemStr = implode(', ', array_unique($items));
            } else {
                $p = $printLine?->productionPlan;
                $codeStr = $p?->code ?? $printLine?->code ?? "CODE-{$tNum}";
                $custStr = $p?->customer ?? $printLine?->customer ?? 'PT TEST';
                $itemStr = $p?->item_name ?? $printLine?->item_name ?? 'Elbow SS';
            }

            $gross = $spec['gross'] ?? $spec['qty'];
            LostWaxCastingResultTree::create([
                'lost_wax_casting_result_id' => $result->id,
                'lost_wax_tree_id' => $physTree->id,
                'active_tree_id' => $status === 'COMPLETED' ? $physTree->id : null,
                'barcode' => $barcode,
                'production_code' => $codeStr,
                'customer' => $custStr,
                'item_name' => $itemStr,
                'quantity_pcs' => $spec['qty'],
                'gross_pcs' => $gross,
                'defect_pcs' => max(0, $gross - $spec['qty']),
            ]);
        }

        return $result->fresh(['trees.tree']);
    }

    // =========================================================================
    // BAGIAN 1: KITIR ISSUANCE TANPA WAJIB CETAK (Scenarios 1 - 6)
    // =========================================================================

    public function test_01_issue_without_print_sets_print_count_zero_is_idempotent_and_preserves_legacy_print(): void
    {
        [, $line1] = $this->createPlanWithPrintLine(['code' => 'ISS-001', 'line_number' => 1, 'weight' => 0.45]);
        [, $line2] = $this->createPlanWithPrintLine(['code' => 'ISS-002', 'line_number' => 2, 'weight' => 0.55]);
        [, $line3] = $this->createPlanWithPrintLine(['code' => 'ISS-003', 'line_number' => 3, 'weight' => 0.65]);

        $result = $this->createCastingResultWithPlans([
            ['qty' => 20, 'print_line' => $line1],
            ['qty' => 30, 'print_line' => $line2],
            ['qty' => 40, 'print_line' => $line3],
        ]);

        // Show page BEFORE issuance shows "Belum Diterbitkan", "Terbitkan Kitir", and "Terbitkan Semua Kitir"
        $showBefore = $this->actingAs($this->ppicFitting)->get(route('lost-wax.casting-results.show', $result));
        $showBefore->assertOk();
        $showBefore->assertSee('Belum Diterbitkan');
        $showBefore->assertSee('Terbitkan Kitir');
        $showBefore->assertSee('Terbitkan Semua Kitir');
        $showBefore->assertSee('Cetak Semua Kitir');

        // 1 & 2. Issue single Kitir without printing -> print_count === 0 is valid
        $respIssue1 = $this->actingAs($this->ppicFitting)->post(
            route('lost-wax.casting-results.kitir.issue', ['castingResult' => $result, 'code' => 'ISS-001'])
        );
        $respIssue1->assertRedirect(route('lost-wax.casting-results.show', $result));

        $kitir1 = LostWaxKitir::where('lost_wax_casting_result_id', $result->id)
            ->where('production_code', 'ISS-001')
            ->firstOrFail();
        $this->assertNotNull($kitir1->issued_at);
        $this->assertEquals(0, $kitir1->print_count);
        $this->assertNull($kitir1->printed_at);
        $this->assertFalse($kitir1->is_printed);

        // Show page reflects "Sudah Diterbitkan" + "Belum Dicetak" for ISS-001
        $showMid = $this->actingAs($this->ppicFitting)->get(route('lost-wax.casting-results.show', $result));
        $showMid->assertOk();
        $showMid->assertSee('Sudah Diterbitkan');
        $showMid->assertSee('Belum Dicetak');
        $showMid->assertSee('Terbitkan Semua Kitir (2)');

        // 3. Re-issuing same Kitir is idempotent (same PFKTR, count stays 1, print_count stays 0)
        $this->actingAs($this->ppicFitting)->post(
            route('lost-wax.casting-results.kitir.issue', ['castingResult' => $result, 'code' => 'ISS-001'])
        )->assertRedirect();

        $this->assertEquals(1, LostWaxKitir::where('lost_wax_casting_result_id', $result->id)->count());
        $this->assertEquals($kitir1->traveler_number, $kitir1->fresh()->traveler_number);
        $this->assertEquals(0, $kitir1->fresh()->print_count);

        // 4. Issue All when 1 of 3 is already issued -> safely issues remaining 2 without duplicating 1st
        $this->actingAs($this->ppicFitting)->post(
            route('lost-wax.casting-results.kitir.issue-all', $result)
        )->assertRedirect(route('lost-wax.casting-results.show', $result));

        $this->assertEquals(3, LostWaxKitir::where('lost_wax_casting_result_id', $result->id)->count());
        $this->assertEquals(0, LostWaxKitir::where('lost_wax_casting_result_id', $result->id)->sum('print_count'));

        // Once all issued, "Terbitkan Semua Kitir" button no longer appears
        $showAfterAll = $this->actingAs($this->ppicFitting)->get(route('lost-wax.casting-results.show', $result));
        $showAfterAll->assertOk();
        $showAfterAll->assertDontSee('Terbitkan Semua Kitir');
        $showAfterAll->assertDontSee('Terbitkan Kitir');

        // 5 & 6. Print & reprint do NOT create a new Kitir and increment print_count cleanly
        $this->actingAs($this->ppicFitting)->get(
            route('lost-wax.casting-results.kitir.print', ['castingResult' => $result, 'code' => 'ISS-001'])
        )->assertOk();
        $this->assertEquals(1, $kitir1->fresh()->print_count);
        $this->assertEquals(3, LostWaxKitir::where('lost_wax_casting_result_id', $result->id)->count());

        $this->actingAs($this->ppicFitting)->get(
            route('lost-wax.casting-results.kitir.print', ['castingResult' => $result, 'code' => 'ISS-001'])
        )->assertOk();
        $this->assertEquals(2, $kitir1->fresh()->print_count);
        $this->assertEquals($kitir1->traveler_number, $kitir1->fresh()->traveler_number);

        // Re-issuing an already-printed Kitir preserves print_count = 2
        $this->actingAs($this->ppicFitting)->post(
            route('lost-wax.casting-results.kitir.issue', ['castingResult' => $result, 'code' => 'ISS-001'])
        )->assertRedirect();
        $this->assertEquals(2, $kitir1->fresh()->print_count);
    }

    // =========================================================================
    // BAGIAN 2: TRANSFER KITIR, PARTIAL TRANSFER, CONCURRENCY, LINE & WEIGHT (7 - 16)
    // =========================================================================

    public function test_02_partial_transfer_enters_netto_pf_without_mutating_lost_wax_and_rejects_unissued(): void
    {
        // PO = 1.000 PCS, 3 Kitirs issued (each 100 PCS) -> partial transfer 2 Kitirs immediately!
        [$plan, $line] = $this->createPlanWithPrintLine([
            'code' => 'PO-PARTIAL-01',
            'po_quantity' => 1000,
            'qty_planned' => 1000,
            'line_number' => 2,
            'weight' => 0.45,
        ]);

        $res1 = $this->createCastingResultWithPlans([['qty' => 100, 'print_line' => $line]]);
        $res2 = $this->createCastingResultWithPlans([['qty' => 100, 'print_line' => $line]]);
        $res3 = $this->createCastingResultWithPlans([['qty' => 100, 'print_line' => $line]]);

        $k1 = $this->kitirResolver->issueOrGetKitir($res1, 'PO-PARTIAL-01', $this->ppicFitting);
        $k2 = $this->kitirResolver->issueOrGetKitir($res2, 'PO-PARTIAL-01', $this->ppicFitting);
        $k3 = $this->kitirResolver->issueOrGetKitir($res3, 'PO-PARTIAL-01', $this->ppicFitting);

        // Unprinted kitirs (print_count = 0) MUST be transferable!
        $this->assertEquals(0, $k1->print_count);
        $this->assertEquals(0, $k2->print_count);

        // Snapshot Lost Wax state before transfer
        $k1Before = $k1->fresh()->toArray();
        $res1Before = $res1->fresh()->toArray();

        // Transfer only 2 of 3 Kitirs (Partial Transfer) with explicit PPIC-selected Fitting Line = 2
        $transfer = $this->transferService->transferKitirs(
            [$k1->id, $k2->id],
            $this->ppicFitting,
            'Batch pagi',
            'idem-partial-1',
            null,
            [$k1->id => 2, $k2->id => 2]
        );

        $this->assertStringStartsWith('PFTRF-', $transfer->transfer_number);
        $this->assertEquals(2, $transfer->total_kitirs);
        $this->assertEquals(200, $transfer->total_pcs);
        $this->assertEquals('90.00', (string) $transfer->total_weight_kg);
        $this->assertEquals(FittingTransfer::STATUS_ACTIVE, $transfer->status);

        // Both items enter netto_pf immediately with accurate snapshots
        $items = FittingItem::where('fitting_transfer_id', $transfer->id)->orderBy('lost_wax_kitir_id')->get();
        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertEquals(FittingItem::STAGE_NETTO_PF, $item->current_stage);
            $this->assertEquals(FittingItem::STATUS_ACTIVE, $item->status);
            $this->assertEquals($item->lost_wax_kitir_id, $item->active_kitir_id);
            $this->assertEquals(2, $item->line_number);
            $this->assertEquals(100, $item->qty_transferred);
            $this->assertEquals('0.4500', (string) $item->unit_weight_kg);
            $this->assertEquals('45.00', (string) $item->total_weight_kg);
            $this->assertFalse($item->is_multi_allocation);
            $this->assertEquals($plan->id, $item->production_plan_id);
        }

        // Lost Wax records were NOT mutated at all; PO was NOT closed
        $this->assertEquals($k1Before, $k1->fresh()->toArray());
        $this->assertEquals($res1Before, $res1->fresh()->toArray());
        $this->assertFalse((bool) $plan->fresh()->is_closed);
        $this->assertEquals(1000, (int) $plan->fresh()->po_quantity);

        // 3rd Kitir remains in active available transfer pool
        $workspace = $this->transferService->getWorkspaceData(['tab' => 'available']);
        $availableIds = $workspace['available_rows']->pluck('kitir.id')->all();
        $this->assertEquals([$k3->id], $availableIds);

        // 8. Unissued / non-existent Kitir is rejected
        $unissuedKitir = LostWaxKitir::create([
            'lost_wax_casting_result_id' => $res3->id,
            'traveler_number' => 'PFKTR-20261118-9991',
            'production_code' => 'UNISSUED-TMP',
            'tree_count' => 1,
            'quantity_pcs' => 10,
            'print_count' => 0,
            'issued_at' => null,
        ]);

        $unissuedRejected = false;
        try {
            $this->transferService->transferKitirs([$unissuedKitir->id], $this->ppicFitting, null, null, null, [$unissuedKitir->id => 1]);
        } catch (InvalidArgumentException $e) {
            $unissuedRejected = str_contains($e->getMessage(), 'belum berstatus diterbitkan');
        }
        $this->assertTrue($unissuedRejected, 'Kitir with issued_at = null must be rejected.');

        $missingRejected = false;
        try {
            $this->transferService->transferKitirs([999999], $this->ppicFitting, null, null, null, [999999 => 1]);
        } catch (InvalidArgumentException $e) {
            $missingRejected = true;
        }
        $this->assertTrue($missingRejected, 'Non-existent Kitir ID must be rejected.');
    }

    public function test_03_anti_double_transfer_idempotency_and_atomic_rollback_on_partial_failure(): void
    {
        [, $line1] = $this->createPlanWithPrintLine(['code' => 'DUP-01', 'line_number' => 1, 'weight' => 0.40]);
        [, $line2] = $this->createPlanWithPrintLine(['code' => 'DUP-02', 'line_number' => 4, 'weight' => 0.60]);

        $res = $this->createCastingResultWithPlans([
            ['qty' => 25, 'print_line' => $line1],
            ['qty' => 35, 'print_line' => $line2],
        ]);
        $k1 = $this->kitirResolver->issueOrGetKitir($res, 'DUP-01', $this->ppicFitting);
        $k2 = $this->kitirResolver->issueOrGetKitir($res, 'DUP-02', $this->ppicFitting);

        // First transfer of k1 succeeds
        $t1 = $this->transferService->transferKitirs([$k1->id], $this->adminPpicPf, null, 'idem-key-abc', null, [$k1->id => 1]);
        $this->assertEquals(1, FittingItem::count());

        // 11. Idempotency replay returns same transfer without creating duplicates
        $tReplay = $this->transferService->transferKitirs([$k1->id], $this->adminPpicPf, null, 'idem-key-abc', null, [$k1->id => 1]);
        $this->assertEquals($t1->id, $tReplay->id);
        $this->assertEquals(1, FittingTransfer::count());
        $this->assertEquals(1, FittingItem::count());

        // 10. Direct second transfer of k1 (without same idempotency key) MUST be rejected
        $doubleRejected = false;
        try {
            $this->transferService->transferKitirs([$k1->id], $this->ppicFitting, null, null, null, [$k1->id => 1]);
        } catch (InvalidArgumentException $e) {
            $doubleRejected = str_contains($e->getMessage(), 'sudah ditransfer');
        }
        $this->assertTrue($doubleRejected, 'Re-transfer of already transferred Kitir must fail.');

        // 12. Atomic rollback: batch [k2 (valid), k1 (already transferred)] MUST NOT persist k2!
        $batchFailed = false;
        try {
            $this->transferService->transferKitirs([$k2->id, $k1->id], $this->ppicFitting, null, null, null, [$k2->id => 4, $k1->id => 1]);
        } catch (InvalidArgumentException $e) {
            $batchFailed = true;
        }
        $this->assertTrue($batchFailed);
        $this->assertEquals(1, FittingTransfer::count(), 'Failed batch must roll back header.');
        $this->assertEquals(1, FittingItem::count(), 'Failed batch must roll back all items (no partial save).');
        $this->assertNull(FittingItem::where('lost_wax_kitir_id', $k2->id)->first());
    }

    public function test_04_line_number_rules_and_multi_allocation_weight_snapshots(): void
    {
        // 13. Valid lines 1..4
        [$planL1, $l1] = $this->createPlanWithPrintLine(['code' => 'LINE-1', 'line_number' => 1, 'weight' => 0.50, 'item_name' => 'Elbow 10" DN250']);
        [$planL4, $l4] = $this->createPlanWithPrintLine(['code' => 'LINE-4', 'line_number' => 4, 'weight' => 0.25, 'item_name' => 'Elbow 1/2" DN15']);
        // 14. Zero (0) & out-of-range (5) -> Unassigned (NEVER infer from size!)
        [, $lZero] = $this->createPlanWithPrintLine(['code' => 'LINE-ZERO', 'line_number' => 0, 'weight' => 0.80, 'item_name' => 'Elbow 2" DN50']);
        [, $lOut5] = $this->createPlanWithPrintLine(['code' => 'LINE-5', 'line_number' => 5, 'weight' => 0.90, 'item_name' => 'Tee 4" DN100']);
        // Also test PrintOrderLine with null production_plan_id (truly null line_number)
        $poNoPlan = LostWaxPrintOrder::create([
            'print_order_number' => 'LW-PO-NOPLAN-'.(self::$seq++),
            'scheduled_date' => Carbon::today()->format('Y-m-d'),
            'status' => 'ISSUED',
            'created_by' => $this->systemAdmin->id,
        ]);
        $lNoPlan = LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $poNoPlan->id,
            'production_plan_id' => null,
            'code' => 'LINE-NULL',
            'customer' => 'PT NOPLAN',
            'item_name' => 'Elbow 2" DN50',
            'qty_ordered' => 15,
        ]);
        // 15 & 16. Multi-allocation different lines (Line 1 + Line 3) & same line (Line 2 + Line 2)
        [$planMA1, $lMA1] = $this->createPlanWithPrintLine(['code' => 'MA-A', 'line_number' => 1, 'weight' => 0.50]);
        [$planMA2, $lMA2] = $this->createPlanWithPrintLine(['code' => 'MA-B', 'line_number' => 3, 'weight' => 0.30]);
        [$planSame1, $lSame1] = $this->createPlanWithPrintLine(['code' => 'SAME-A', 'line_number' => 2, 'weight' => 0.40]);
        [$planSame2, $lSame2] = $this->createPlanWithPrintLine(['code' => 'SAME-B', 'line_number' => 2, 'weight' => 0.60]);

        $res = $this->createCastingResultWithPlans([
            ['qty' => 10, 'print_line' => $l1],
            ['qty' => 20, 'print_line' => $l4],
            ['qty' => 15, 'print_line' => $lZero],
            ['qty' => 12, 'print_line' => $lOut5],
            ['qty' => 5, 'print_line' => $lNoPlan],
            // Multi-allocation diff line: 12 pcs @0.50kg (6.00) + 8 pcs @0.30kg (2.40) = 20 pcs / 8.40 kg (avg 0.4200)
            [
                'qty' => 20,
                'allocations' => [
                    ['print_line' => $lMA1, 'allocated_qty' => 12],
                    ['print_line' => $lMA2, 'allocated_qty' => 8],
                ],
            ],
            // Multi-allocation same line 2: 10 pcs @0.40kg (4.00) + 10 pcs @0.60kg (6.00) = 20 pcs / 10.00 kg (avg 0.5000)
            [
                'qty' => 20,
                'allocations' => [
                    ['print_line' => $lSame1, 'allocated_qty' => 10],
                    ['print_line' => $lSame2, 'allocated_qty' => 10],
                ],
            ],
        ]);

        $kitirs = $this->kitirResolver->issueAllKitirs($res, $this->ppicFitting);
        $this->assertCount(7, $kitirs);

        // Verify Suggested Line (Line Rencana) resolution BEFORE transfer:
        // - Consistent valid 1..4 -> CONSISTENT with suggested_line_number
        // - Zero, Null, Out-of-range (5) -> UNASSIGNED (default empty)
        // - Multi-allocation differing lines (1 vs 3) -> INCONSISTENT (default empty)
        // - Multi-allocation same line (2 & 2) -> CONSISTENT (Line 2)
        $snapshotResolver = app(\App\Services\Fitting\FittingKitirSnapshotResolverService::class);
        $snapsByCode = $kitirs->mapWithKeys(fn ($k) => [$k->production_code => $snapshotResolver->resolve($k)]);

        $this->assertEquals('CONSISTENT', $snapsByCode['LINE-1']['suggested_line_status']);
        $this->assertEquals(1, $snapsByCode['LINE-1']['suggested_line_number']);
        $this->assertEquals('CONSISTENT', $snapsByCode['LINE-4']['suggested_line_status']);
        $this->assertEquals(4, $snapsByCode['LINE-4']['suggested_line_number']);
        $this->assertEquals('UNASSIGNED', $snapsByCode['LINE-ZERO']['suggested_line_status']);
        $this->assertNull($snapsByCode['LINE-ZERO']['suggested_line_number']);
        $this->assertEquals('UNASSIGNED', $snapsByCode['LINE-NULL']['suggested_line_status']);
        $this->assertNull($snapsByCode['LINE-NULL']['suggested_line_number']);
        $this->assertEquals('UNASSIGNED', $snapsByCode['LINE-5']['suggested_line_status']);
        $this->assertNull($snapsByCode['LINE-5']['suggested_line_number']);
        $this->assertEquals('INCONSISTENT', $snapsByCode['MA-A, MA-B']['suggested_line_status']);
        $this->assertNull($snapsByCode['MA-A, MA-B']['suggested_line_number']);
        $this->assertEquals('Tidak Konsisten', $snapsByCode['MA-A, MA-B']['suggested_line_label']);
        $this->assertEquals('CONSISTENT', $snapsByCode['SAME-A, SAME-B']['suggested_line_status']);
        $this->assertEquals(2, $snapsByCode['SAME-A, SAME-B']['suggested_line_number']);

        // PPIC assigns explicit Final Operational Line (1..4) for every Kitir at transfer time
        $ppicLineChoices = [];
        foreach ($kitirs as $k) {
            $ppicLineChoices[$k->id] = match ($k->production_code) {
                'LINE-1' => 1,
                'LINE-4' => 4,
                'LINE-ZERO' => 1,
                'LINE-NULL' => 2,
                'LINE-5' => 3,
                'MA-A, MA-B' => 3, // PPIC resolves multi-allocation conflict to Line 3
                'SAME-A, SAME-B' => 2,
                default => 1,
            };
        }

        $transfer = $this->transferService->transferKitirs($kitirs->pluck('id')->all(), $this->ppicFitting, null, null, null, $ppicLineChoices);
        $itemsByCode = FittingItem::with('allocations')
            ->where('fitting_transfer_id', $transfer->id)
            ->get()
            ->keyBy('production_code');

        // Line 1 & Line 4 preserved from PPIC selection
        $itemL1 = $itemsByCode->get('LINE-1');
        $this->assertEquals(1, $itemL1->line_number);
        $this->assertEquals($planL1->id, $itemL1->production_plan_id);
        $this->assertEquals('0.5000', (string) $itemL1->unit_weight_kg);
        $this->assertEquals('5.00', (string) $itemL1->total_weight_kg);

        $itemL4 = $itemsByCode->get('LINE-4');
        $this->assertEquals(4, $itemL4->line_number);
        $this->assertEquals($planL4->id, $itemL4->production_plan_id);
        $this->assertEquals('0.2500', (string) $itemL4->unit_weight_kg);
        $this->assertEquals('5.00', (string) $itemL4->total_weight_kg);

        // Zero, Null Plan & Line 5 received explicit PPIC lines at transfer (without mutating production_plans!)
        $itemZero = $itemsByCode->get('LINE-ZERO');
        $this->assertEquals(1, $itemZero->line_number);
        $this->assertTrue($itemZero->is_line_assigned);
        $this->assertEquals('12.00', (string) $itemZero->total_weight_kg);

        $itemNull = $itemsByCode->get('LINE-NULL');
        $this->assertEquals(2, $itemNull->line_number);
        $this->assertTrue($itemNull->is_line_assigned);
        $this->assertEquals('0.00', (string) $itemNull->total_weight_kg);

        $itemOut5 = $itemsByCode->get('LINE-5');
        $this->assertEquals(3, $itemOut5->line_number);
        $this->assertTrue($itemOut5->is_line_assigned);
        $this->assertEquals('10.80', (string) $itemOut5->total_weight_kg);

        // Multi-allocation: traveler AND ALL allocations aligned with PPIC's final choice (Line 3)!
        $itemDiffMA = $itemsByCode->get('MA-A, MA-B');
        $this->assertNotNull($itemDiffMA);
        $this->assertTrue($itemDiffMA->is_multi_allocation);
        $this->assertNull($itemDiffMA->production_plan_id);
        $this->assertEquals(3, $itemDiffMA->line_number);
        $this->assertEquals(20, $itemDiffMA->qty_transferred);
        $this->assertEquals('8.40', (string) $itemDiffMA->total_weight_kg);
        $this->assertEquals('0.4200', (string) $itemDiffMA->unit_weight_kg);
        $this->assertCount(2, $itemDiffMA->allocations);

        $allocA = $itemDiffMA->allocations->firstWhere('production_plan_id', $planMA1->id);
        $allocB = $itemDiffMA->allocations->firstWhere('production_plan_id', $planMA2->id);
        $this->assertEquals(3, $allocA->line_number);
        $this->assertEquals(12, $allocA->allocated_qty_transferred);
        $this->assertEquals('0.5000', (string) $allocA->unit_weight_kg);
        $this->assertEquals('6.00', (string) $allocA->allocated_weight_kg);
        $this->assertEquals(3, $allocB->line_number);
        // Source production_plans.line_number MUST remain untouched (1 & 3)!
        $this->assertEquals(1, $planMA1->fresh()->line_number);
        $this->assertEquals(3, $planMA2->fresh()->line_number);

        $this->assertEquals(3, $allocB->line_number);
        $this->assertEquals(8, $allocB->allocated_qty_transferred);
        $this->assertEquals('0.3000', (string) $allocB->unit_weight_kg);
        $this->assertEquals('2.40', (string) $allocB->allocated_weight_kg);

        // Multi-allocation same line 2 -> retains Line 2 on physical item!
        $itemSameMA = $itemsByCode->get('SAME-A, SAME-B');
        $this->assertNotNull($itemSameMA);
        $this->assertTrue($itemSameMA->is_multi_allocation);
        $this->assertEquals(2, $itemSameMA->line_number);
        $this->assertEquals('10.00', (string) $itemSameMA->total_weight_kg);
        $this->assertEquals('0.5000', (string) $itemSameMA->unit_weight_kg);
        $this->assertEquals([$planSame1->id, $planSame2->id], $itemSameMA->allocations->pluck('production_plan_id')->sort()->values()->all());

        // Total transfer weight equals exact sum: 5.00 + 5.00 + 12.00 + 0.00 + 10.80 + 8.40 + 10.00 = 51.20
        $this->assertEquals('51.20', (string) $transfer->total_weight_kg);
        $this->assertEquals(102, $transfer->total_pcs);
    }

    // =========================================================================
    // BAGIAN 3: NOT_CONTINUED, DECISION HISTORY & UNDO (Scenarios 17 - 22)
    // =========================================================================

    public function test_05_not_continued_nullable_reason_undo_preserves_history_and_prevents_inconsistent_state(): void
    {
        [, $line1] = $this->createPlanWithPrintLine(['code' => 'NC-001', 'line_number' => 1, 'weight' => 0.50]);
        [, $line2] = $this->createPlanWithPrintLine(['code' => 'NC-002', 'line_number' => 2, 'weight' => 0.50]);

        $res = $this->createCastingResultWithPlans([
            ['qty' => 20, 'print_line' => $line1],
            ['qty' => 30, 'print_line' => $line2],
        ]);
        $k1 = $this->kitirResolver->issueOrGetKitir($res, 'NC-001', $this->ppicFitting);
        $k2 = $this->kitirResolver->issueOrGetKitir($res, 'NC-002', $this->ppicFitting);

        // 17 & 18. Mark NOT_CONTINUED with NULL reason (allowed!) — no hard delete
        $dec1 = $this->decisionService->markNotContinued($k1, $this->ppicFitting, null);
        $this->assertEquals(FittingKitirDecision::DECISION_NOT_CONTINUED, $dec1->decision);
        $this->assertEquals(FittingKitirDecision::STATUS_ACTIVE, $dec1->status);
        $this->assertEquals($k1->id, $dec1->active_kitir_id);
        $this->assertNull($dec1->reason);
        $this->assertEquals($this->ppicFitting->id, $dec1->decided_by);
        $this->assertNotNull($dec1->decided_at);
        $this->assertNotNull(LostWaxKitir::find($k1->id), 'LostWaxKitir must NEVER be hard deleted.');

        // Duplicate active NOT_CONTINUED on same Kitir rejected
        $dupDecRejected = false;
        try {
            $this->decisionService->markNotContinued($k1, $this->adminPpicPf, 'Double click');
        } catch (InvalidArgumentException $e) {
            $dupDecRejected = str_contains($e->getMessage(), 'NOT_CONTINUED');
        }
        $this->assertTrue($dupDecRejected);

        // 19. Kitir disappears from active transfer pool and cannot be transferred while NOT_CONTINUED
        $pool = $this->transferService->getWorkspaceData(['tab' => 'available']);
        $this->assertEquals([$k2->id], $pool['available_rows']->pluck('kitir.id')->all());

        $transferWhileNcFailed = false;
        try {
            $this->transferService->transferKitirs([$k1->id], $this->ppicFitting, null, null, null, [$k1->id => 1]);
        } catch (InvalidArgumentException $e) {
            $transferWhileNcFailed = str_contains($e->getMessage(), 'NOT_CONTINUED');
        }
        $this->assertTrue($transferWhileNcFailed);

        // Decision History tab displays k1 and undo button
        $decPage = $this->actingAs($this->ppicFitting)->get(route('fitting.transfers.index', ['tab' => 'decisions']));
        $decPage->assertOk();
        $decPage->assertSee($k1->traveler_number);
        $decPage->assertSee('Kembalikan ke Daftar Transfer');

        // 20 & 21. Undo (Reopen) succeeds, keeps historical row intact, returns k1 to available pool
        $reopened = $this->decisionService->reopenDecision($dec1, $this->adminPpicPf, 'Customer lanjut proses');
        $this->assertEquals(FittingKitirDecision::STATUS_REOPENED, $reopened->status);
        $this->assertNull($reopened->active_kitir_id);
        $this->assertEquals($this->adminPpicPf->id, $reopened->reopened_by);
        $this->assertNotNull($reopened->reopened_at);
        $this->assertEquals('Customer lanjut proses', $reopened->reopen_reason);

        // History row still exists in DB (count = 1)
        $this->assertEquals(1, FittingKitirDecision::where('lost_wax_kitir_id', $k1->id)->count());

        // k1 is back in active transfer pool and CAN now be transferred!
        $poolAfterUndo = $this->transferService->getWorkspaceData(['tab' => 'available']);
        $this->assertEqualsCanonicalizing([$k1->id, $k2->id], $poolAfterUndo['available_rows']->pluck('kitir.id')->all());

        $transfer = $this->transferService->transferKitirs([$k1->id], $this->ppicFitting, null, null, null, [$k1->id => 1]);
        $this->assertEquals(1, $transfer->total_kitirs);

        // 22. Consistency: cannot reopen already-reopened decision & cannot mark transferred kitir NOT_CONTINUED
        $reopenAgainFailed = false;
        try {
            $this->decisionService->reopenDecision($reopened, $this->ppicFitting);
        } catch (InvalidArgumentException $e) {
            $reopenAgainFailed = true;
        }
        $this->assertTrue($reopenAgainFailed);

        $ncAfterTransferFailed = false;
        try {
            $this->decisionService->markNotContinued($k1, $this->ppicFitting, 'Terlambat');
        } catch (InvalidArgumentException $e) {
            $ncAfterTransferFailed = str_contains($e->getMessage(), 'sudah ditransfer');
        }
        $this->assertTrue($ncAfterTransferFailed);

        // Historical decision record STILL preserved after k1 was transferred
        $this->assertEquals(1, FittingKitirDecision::where('lost_wax_kitir_id', $k1->id)->count());
    }

    // =========================================================================
    // BAGIAN 4: LOST WAX CANCEL CASTING RESULT PROTECTION (Scenarios 23 - 25)
    // =========================================================================

    public function test_06_lost_wax_cancel_guard_protects_active_and_completed_travelers_but_allows_untransferred_not_continued_and_cancelled(): void
    {
        // 23A. Casting Result with NO Kitir -> cancel allowed (legacy behavior)
        [, $lNoKitir] = $this->createPlanWithPrintLine(['code' => 'CXL-NONE']);
        $resNoKitir = $this->createCastingResultWithPlans([['qty' => 10, 'print_line' => $lNoKitir]]);
        $cancelledNoKitir = $this->castingResultService->cancelCastingResult($resNoKitir, $this->systemAdmin, 'Salah input heat');
        $this->assertEquals('CANCELLED', $cancelledNoKitir->status);

        // 23B. Casting Result with issued Kitir marked NOT_CONTINUED (never transferred) -> cancel allowed
        [, $lNc] = $this->createPlanWithPrintLine(['code' => 'CXL-NC']);
        $resNc = $this->createCastingResultWithPlans([['qty' => 15, 'print_line' => $lNc]]);
        $kNc = $this->kitirResolver->issueOrGetKitir($resNc, 'CXL-NC', $this->ppicFitting);
        $decNc = $this->decisionService->markNotContinued($kNc, $this->ppicFitting, 'Dijual raw');

        $cancelledNc = $this->castingResultService->cancelCastingResult($resNc, $this->systemAdmin, 'Void heat raw');
        $this->assertEquals('CANCELLED', $cancelledNc->status);

        // Once parent Heat is CANCELLED, reopening NOT_CONTINUED must be rejected
        $reopenCancelledHeatFailed = false;
        try {
            $this->decisionService->reopenDecision($decNc->fresh(), $this->ppicFitting);
        } catch (InvalidArgumentException $e) {
            $reopenCancelledHeatFailed = str_contains($e->getMessage(), 'CANCELLED');
        }
        $this->assertTrue($reopenCancelledHeatFailed);

        // 24A. Casting Result with ACTIVE Fitting traveler (netto_pf) -> cancel REJECTED with clear message
        [, $lActive] = $this->createPlanWithPrintLine(['code' => 'CXL-ACT']);
        $resActive = $this->createCastingResultWithPlans([['qty' => 20, 'print_line' => $lActive]]);
        $kActive = $this->kitirResolver->issueOrGetKitir($resActive, 'CXL-ACT', $this->ppicFitting);
        $transfer = $this->transferService->transferKitirs([$kActive->id], $this->ppicFitting, null, null, null, [$kActive->id => 1]);
        $activeItem = $transfer->items->first();

        $blockedActiveMsg = null;
        try {
            $this->castingResultService->cancelCastingResult($resActive, $this->systemAdmin, 'Coba batal paksa');
        } catch (InvalidArgumentException $e) {
            $blockedActiveMsg = $e->getMessage();
        }
        $this->assertNotNull($blockedActiveMsg);
        $this->assertStringContainsString($kActive->traveler_number, $blockedActiveMsg);
        $this->assertEquals('COMPLETED', $resActive->fresh()->status);

        // 24B. Casting Result with COMPLETED Fitting traveler -> cancel STILL REJECTED!
        [, $lDone] = $this->createPlanWithPrintLine(['code' => 'CXL-DONE']);
        $resDone = $this->createCastingResultWithPlans([['qty' => 25, 'print_line' => $lDone]]);
        $kDone = $this->kitirResolver->issueOrGetKitir($resDone, 'CXL-DONE', $this->ppicFitting);
        $doneTransfer = $this->transferService->transferKitirs([$kDone->id], $this->ppicFitting, null, null, null, [$kDone->id => 1]);
        $doneItem = $doneTransfer->items->first();
        $doneItem->update(['current_stage' => FittingItem::STAGE_COMPLETED]);

        $blockedCompleted = false;
        try {
            $this->castingResultService->cancelCastingResult($resDone, $this->systemAdmin, 'Coba batal completed');
        } catch (InvalidArgumentException $e) {
            $blockedCompleted = str_contains($e->getMessage(), $kDone->traveler_number);
        }
        $this->assertTrue($blockedCompleted, 'COMPLETED Fitting traveler must permanently protect source Casting Result.');

        // Also: if an active item already has a physical scan, cancelTransferItem must be rejected
        FittingStageExecution::create([
            'fitting_item_id' => $doneItem->id,
            'stage' => FittingItem::STAGE_NETTO_PF,
            'checkpoint_code' => FittingStageExecution::CHECKPOINT_NETTO,
            'input_qty' => 25,
            'defect_qty' => 0,
            'good_qty' => 25,
            'status' => FittingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->spvNettoPf->id,
            'executed_at' => now(),
            'physical_done_at' => now(),
        ]);

        $cancelScannedFailed = false;
        try {
            $this->transferService->cancelTransferItem($doneItem, $this->ppicFitting, 'Sudah discan');
        } catch (InvalidArgumentException $e) {
            $cancelScannedFailed = str_contains($e->getMessage(), 'riwayat scan');
        }
        $this->assertTrue($cancelScannedFailed);

        // 25. Cancel transfer BEFORE any scan -> releases active_kitir_id & current_stage -> Lost Wax Heat CAN now be cancelled!
        $cancelledItem = $this->transferService->cancelTransferItem($activeItem, $this->ppicFitting, 'Salah pilih kitir');
        $this->assertEquals(FittingItem::STATUS_CANCELLED, $cancelledItem->status);
        $this->assertNull($cancelledItem->active_kitir_id);
        $this->assertNull($cancelledItem->current_stage);
        $this->assertEquals(FittingTransfer::STATUS_CANCELLED, $transfer->fresh()->status);

        $cancelledHeatAfterRelease = $this->castingResultService->cancelCastingResult($resActive, $this->systemAdmin, 'Batal setelah transfer dibatalkan');
        $this->assertEquals('CANCELLED', $cancelledHeatAfterRelease->status);

        // Cancelled Heat's Kitir cannot be transferred again
        $transferCancelledHeatFailed = false;
        try {
            $this->transferService->transferKitirs([$kActive->id], $this->ppicFitting, null, null, null, [$kActive->id => 1]);
        } catch (InvalidArgumentException $e) {
            $transferCancelledHeatFailed = str_contains($e->getMessage(), 'CANCELLED');
        }
        $this->assertTrue($transferCancelledHeatFailed);
    }

    // =========================================================================
    // BAGIAN 5: HTTP FLOW, SIDEBAR & AUTHORIZATION ISOLATION
    // =========================================================================

    public function test_07_http_transfer_decision_endpoints_sidebar_and_authorization(): void
    {
        [, $line] = $this->createPlanWithPrintLine(['code' => 'HTTP-001', 'line_number' => 3, 'weight' => 0.75]);
        $res = $this->createCastingResultWithPlans([['qty' => 40, 'print_line' => $line]]);
        $kitir = $this->kitirResolver->issueOrGetKitir($res, 'HTTP-001', $this->ppicFitting);

        // Authorized accounts (systemAdmin, ppicFitting, adminPpicPf) can open /fitting/transfers and see sidebar menu
        foreach ([$this->systemAdmin, $this->ppicFitting, $this->adminPpicPf] as $authorized) {
            $resp = $this->actingAs($authorized)->get(route('fitting.transfers.index'));
            $resp->assertOk();
            $resp->assertSee('Fitting Production');
            $resp->assertSee('Transfer Kitir');
            $resp->assertSee($kitir->traveler_number);
            // Live menus by role (Phase 2-5); NO future placeholder menus in sidebar
            $resp->assertSee('Kanban Floor Fitting');
            $resp->assertSee('Production Status Fitting');
            $resp->assertDontSee('Fitting Dashboard');
            $resp->assertDontSee('Laporan Produksi Fitting');

            if ($authorized->is($this->systemAdmin)) {
                $resp->assertSee('Pencatatan Kerusakan Fitting');
                $resp->assertSee('Verifikasi Defect QC Fitting');
            } elseif ($authorized->is($this->adminPpicPf)) {
                $resp->assertSee('Pencatatan Kerusakan Fitting');
                $resp->assertDontSee('Verifikasi Defect QC Fitting');
            } else {
                $resp->assertDontSee('Pencatatan Kerusakan Fitting');
                $resp->assertDontSee('Verifikasi Defect QC Fitting');
            }
        }

        // Unauthorized accounts strictly receive 403 on GET & POST /fitting/transfers*
        foreach ([$this->auditor, $this->sales, $this->spvNettoPf, $this->adminQcFitting] as $unauthorized) {
            $this->actingAs($unauthorized)->get(route('fitting.transfers.index'))->assertForbidden();
            $this->actingAs($unauthorized)->post(route('fitting.transfers.store'), ['kitir_ids' => [$kitir->id]])->assertForbidden();
            $this->actingAs($unauthorized)->post(route('fitting.transfers.kitirs.not-continued', $kitir), ['reason' => 'No'])->assertForbidden();

            $svcDenied = false;
            try {
                $this->transferService->transferKitirs([$kitir->id], $unauthorized);
            } catch (AuthorizationException $e) {
                $svcDenied = true;
            }
            $this->assertTrue($svcDenied, "Service must reject unauthorized user {$unauthorized->email}");
        }

        // Full HTTP lifecycle: NOT_CONTINUED -> Undo -> Transfer -> Cancel Transfer -> Re-transfer
        $this->actingAs($this->ppicFitting)
            ->post(route('fitting.transfers.kitirs.not-continued', $kitir), ['reason' => 'Cek stok dulu'])
            ->assertRedirect(route('fitting.transfers.index', ['tab' => 'decisions']));

        $decision = FittingKitirDecision::where('lost_wax_kitir_id', $kitir->id)->firstOrFail();

        $this->actingAs($this->adminPpicPf)
            ->post(route('fitting.transfers.decisions.reopen', $decision), ['reopen_reason' => 'Jadi diproses'])
            ->assertRedirect(route('fitting.transfers.index', ['tab' => 'available']));

        $this->actingAs($this->ppicFitting)
            ->post(route('fitting.transfers.store'), [
                'kitir_ids' => [$kitir->id],
                'kitir_lines' => [$kitir->id => 3],
                'notes' => 'Transfer via HTTP',
                'idempotency_key' => 'http-idem-001',
            ])
            ->assertRedirect(route('fitting.transfers.index', ['tab' => 'transferred']));

        $item = FittingItem::where('active_kitir_id', $kitir->id)->firstOrFail();
        $this->assertEquals(3, $item->line_number);
        $this->assertEquals('30.00', (string) $item->total_weight_kg);

        $this->actingAs($this->ppicFitting)
            ->post(route('fitting.transfers.items.cancel', $item), ['cancel_reason' => 'Revisi urutan'])
            ->assertRedirect(route('fitting.transfers.index', ['tab' => 'transferred']));

        $this->assertEquals(FittingItem::STATUS_CANCELLED, $item->fresh()->status);

        // Sidebar order check: Sand Casting -> Lost Wax -> Fitting Production
        $pageHtml = $this->actingAs($this->systemAdmin)->get(route('fitting.transfers.index'))->getContent();
        $posSandCasting = strpos($pageHtml, '<!-- 3. SAND CASTING');
        $posLostWax = strpos($pageHtml, '<!-- 4. LOST WAX');
        $posFitting = strpos($pageHtml, '<!-- 4B. FITTING PRODUCTION');
        $this->assertNotFalse($posSandCasting);
        $this->assertNotFalse($posLostWax);
        $this->assertNotFalse($posFitting);
        $this->assertLessThan($posLostWax, $posSandCasting);
        $this->assertLessThan($posFitting, $posLostWax);
    }

    public function test_08_transfer_number_generator_retries_collision_and_prorates_precast_defect_multi_allocation(): void
    {
        // 1. Generator retry on concurrent transfer_number collision
        $attempts = 0;
        $date = '2026-11-18';
        $resultNum = \App\Services\Fitting\FittingTransferNumberGenerator::executeWithRetry(function (string $num) use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw new \Illuminate\Database\QueryException(
                    'sqlite',
                    'insert into fitting_transfers',
                    [],
                    new \PDOException('UNIQUE constraint failed: fitting_transfers.transfer_number (uk_fit_transfers_number)')
                );
            }

            return $num;
        }, $date);

        $this->assertEquals(2, $attempts);
        $this->assertEquals('PFTRF-20261118-0001', $resultNum);

        // 2. Pro-rata weight conservation when a multi-allocation tree has pre-cast defects (gross 20, defect 4 -> usable 16)
        [$planA, $lineA] = $this->createPlanWithPrintLine(['code' => 'PRORATA-A', 'line_number' => 1, 'weight' => 0.50]);
        [$planB, $lineB] = $this->createPlanWithPrintLine(['code' => 'PRORATA-B', 'line_number' => 2, 'weight' => 0.25]);

        $res = $this->createCastingResultWithPlans([
            [
                'qty' => 16,
                'gross' => 20,
                'allocations' => [
                    ['print_line' => $lineA, 'allocated_qty' => 15], // 75% of 16 = 12 pcs @ 0.50 = 6.00 kg
                    ['print_line' => $lineB, 'allocated_qty' => 5],  // 25% of 16 = 4 pcs  @ 0.25 = 1.00 kg
                ],
            ],
        ]);

        $kitir = $this->kitirResolver->issueOrGetKitir($res, 'PRORATA-A, PRORATA-B', $this->ppicFitting);
        $transfer = $this->transferService->transferKitirs([$kitir->id], $this->ppicFitting, null, null, null, [$kitir->id => 4]);
        $item = $transfer->items->first();

        $this->assertEquals(16, $item->qty_transferred);
        $this->assertEquals(16, (int) $item->allocations->sum('allocated_qty_transferred'));
        $this->assertEquals('7.00', (string) $item->total_weight_kg);
        $this->assertEquals('0.4375', (string) $item->unit_weight_kg);
        $this->assertEquals(4, $item->line_number); // Final Operational Line from PPIC

        $allocA = $item->allocations->firstWhere('production_plan_id', $planA->id);
        $allocB = $item->allocations->firstWhere('production_plan_id', $planB->id);
        $this->assertEquals(12, $allocA->allocated_qty_transferred);
        $this->assertEquals('6.00', (string) $allocA->allocated_weight_kg);
        $this->assertEquals(4, $allocA->line_number);

        $this->assertEquals(4, $allocB->allocated_qty_transferred);
        $this->assertEquals('1.00', (string) $allocB->allocated_weight_kg);
        $this->assertEquals(4, $allocB->line_number);

        // Source production_plans.line_number MUST remain untouched (1 & 2)
        $this->assertEquals(1, $planA->fresh()->line_number);
        $this->assertEquals(2, $planB->fresh()->line_number);
    }

    public function test_09_cancel_transfer_before_scan_allows_retransfer_and_completed_blocks_retransfer(): void
    {
        // 1. Cancel transfer before scan -> Kitir returns to pool -> Re-transfer with a NEW Line succeeds while cancelled row keeps old Line
        [, $line1] = $this->createPlanWithPrintLine(['code' => 'RETRF-001', 'line_number' => 2, 'weight' => 0.50]);
        $res1 = $this->createCastingResultWithPlans([['qty' => 30, 'print_line' => $line1]]);
        $k1 = $this->kitirResolver->issueOrGetKitir($res1, 'RETRF-001', $this->ppicFitting);

        $t1 = $this->transferService->transferKitirs([$k1->id], $this->ppicFitting, 'Transfer pertama', null, null, [$k1->id => 2]);
        $item1 = $t1->items->first();
        $this->assertEquals($k1->id, $item1->active_kitir_id);
        $this->assertEquals(2, $item1->line_number);

        // Cancel before scan
        $cancelled = $this->transferService->cancelTransferItem($item1, $this->ppicFitting, 'Revisi prioritas');
        $this->assertEquals(FittingItem::STATUS_CANCELLED, $cancelled->status);
        $this->assertNull($cancelled->active_kitir_id);
        $this->assertNull($cancelled->current_stage);
        $this->assertEquals(2, $cancelled->line_number); // Old snapshot preserved

        // Kitir is back in active available pool
        $pool = $this->transferService->getWorkspaceData(['tab' => 'available']);
        $this->assertContains($k1->id, $pool['available_rows']->pluck('kitir.id')->all());

        // Re-transfer via FittingTransferService with NEW Line 4 succeeds cleanly!
        $t2 = $this->transferService->transferKitirs([$k1->id], $this->adminPpicPf, 'Transfer ulang', null, null, [$k1->id => 4]);
        $this->assertNotEquals($t1->id, $t2->id);
        $item2 = $t2->items->first();

        $this->assertEquals(FittingItem::STATUS_ACTIVE, $item2->status);
        $this->assertEquals($k1->id, $item2->active_kitir_id);
        $this->assertEquals(FittingItem::STAGE_NETTO_PF, $item2->current_stage);
        $this->assertEquals(4, $item2->line_number);
        $this->assertEquals(4, $item2->allocations->first()->line_number);
        $this->assertEquals(2, $item1->fresh()->line_number); // Cancelled row untouched

        // Exactly 1 active traveler for k1, 2 total rows (1 CANCELLED audit + 1 ACTIVE)
        $this->assertEquals(1, FittingItem::where('lost_wax_kitir_id', $k1->id)->where('status', FittingItem::STATUS_ACTIVE)->count());
        $this->assertEquals(1, FittingItem::where('active_kitir_id', $k1->id)->count());
        $this->assertEquals(2, FittingItem::where('lost_wax_kitir_id', $k1->id)->count());

        // 2. COMPLETED item MUST reject re-transfer, keep active_kitir_id intact, and create zero new travelers
        [, $line2] = $this->createPlanWithPrintLine(['code' => 'DONE-001', 'line_number' => 3, 'weight' => 0.40]);
        $res2 = $this->createCastingResultWithPlans([['qty' => 20, 'print_line' => $line2]]);
        $k2 = $this->kitirResolver->issueOrGetKitir($res2, 'DONE-001', $this->ppicFitting);

        $tDone = $this->transferService->transferKitirs([$k2->id], $this->ppicFitting, null, null, null, [$k2->id => 3]);
        $doneItem = $tDone->items->first();
        $doneItem->update(['current_stage' => FittingItem::STAGE_COMPLETED]);

        $transfersBefore = FittingTransfer::count();
        $itemsBefore = FittingItem::count();

        $completedRetransferRejected = false;
        try {
            $this->transferService->transferKitirs([$k2->id], $this->ppicFitting, null, null, null, [$k2->id => 3]);
        } catch (InvalidArgumentException $e) {
            $completedRetransferRejected = str_contains($e->getMessage(), 'sudah ditransfer');
        }

        $this->assertTrue($completedRetransferRejected, 'COMPLETED item must reject re-transfer.');
        $this->assertEquals($k2->id, $doneItem->fresh()->active_kitir_id);
        $this->assertEquals(FittingItem::STATUS_ACTIVE, $doneItem->fresh()->status);
        $this->assertEquals(FittingItem::STAGE_COMPLETED, $doneItem->fresh()->current_stage);
        $this->assertEquals($transfersBefore, FittingTransfer::count());
        $this->assertEquals($itemsBefore, FittingItem::count());
    }

    public function test_10_generator_executes_inside_transaction_and_multialoc_prorata_clamp_and_partial_weight(): void
    {
        // 1. FittingTransferNumberGenerator::executeWithRetry() MUST open DB::transaction() BEFORE generateNext()
        $txLevelObserved = 0;
        $generated = \App\Services\Fitting\FittingTransferNumberGenerator::executeWithRetry(function (string $num) use (&$txLevelObserved) {
            $txLevelObserved = \Illuminate\Support\Facades\DB::transactionLevel();

            return $num;
        }, '2026-11-18');

        $this->assertGreaterThan(0, $txLevelObserved, 'executeWithRetry must run generateNext and callback inside an active DB transaction.');
        $this->assertMatchesRegularExpression('/^PFTRF-20261118-\d{4}$/', $generated);

        // 2. Pro-rata clamping across 3 equal allocations with detailQty = 2 (gross 3, defect 1 -> 1 + 1 + 0 = 2, never overshoot/negative)
        [, $lA] = $this->createPlanWithPrintLine(['code' => 'CLAMP-A', 'line_number' => 1, 'weight' => 0.50]);
        [, $lB] = $this->createPlanWithPrintLine(['code' => 'CLAMP-B', 'line_number' => 1, 'weight' => 0.50]);
        [, $lC] = $this->createPlanWithPrintLine(['code' => 'CLAMP-C', 'line_number' => 1, 'weight' => 0.50]);

        $resClamp = $this->createCastingResultWithPlans([
            [
                'qty' => 2,
                'gross' => 3,
                'allocations' => [
                    ['print_line' => $lA, 'allocated_qty' => 1],
                    ['print_line' => $lB, 'allocated_qty' => 1],
                    ['print_line' => $lC, 'allocated_qty' => 1],
                ],
            ],
        ]);

        $kClamp = $this->kitirResolver->issueOrGetKitir($resClamp, 'CLAMP-A, CLAMP-B, CLAMP-C', $this->ppicFitting);
        $snapClamp = app(\App\Services\Fitting\FittingKitirSnapshotResolverService::class)->resolve($kClamp);

        $allocQtys = array_column($snapClamp['allocations'], 'allocated_qty_transferred');
        $this->assertEquals(2, array_sum($allocQtys));
        foreach ($allocQtys as $q) {
            $this->assertGreaterThanOrEqual(0, $q);
        }

        // Also test 4 allocations where naive rounding could exceed detailQty (gross 4x1, detailQty = 3 -> sum MUST equal 3)
        [, $lD] = $this->createPlanWithPrintLine(['code' => 'CLAMP-D', 'line_number' => 2, 'weight' => 0.50]);
        $resClamp4 = $this->createCastingResultWithPlans([
            [
                'qty' => 3,
                'gross' => 4,
                'allocations' => [
                    ['print_line' => $lA, 'allocated_qty' => 1],
                    ['print_line' => $lB, 'allocated_qty' => 1],
                    ['print_line' => $lC, 'allocated_qty' => 1],
                    ['print_line' => $lD, 'allocated_qty' => 1],
                ],
            ],
        ]);
        $kClamp4 = $this->kitirResolver->issueOrGetKitir($resClamp4, 'CLAMP-A, CLAMP-B, CLAMP-C, CLAMP-D', $this->ppicFitting);
        $snapClamp4 = app(\App\Services\Fitting\FittingKitirSnapshotResolverService::class)->resolve($kClamp4);
        $allocQtys4 = array_column($snapClamp4['allocations'], 'allocated_qty_transferred');
        $this->assertEquals(3, array_sum($allocQtys4));
        foreach ($allocQtys4 as $q) {
            $this->assertGreaterThanOrEqual(0, $q);
        }

        // 3. Partial missing weight in multi-allocation: flag incomplete, preserve quantities, DO NOT fabricate misleading weighted average!
        [$planWtOk, $lWtOk] = $this->createPlanWithPrintLine(['code' => 'WT-OK', 'line_number' => 1, 'weight' => 0.50]);
        [$planWtNo, $lWtNo] = $this->createPlanWithPrintLine(['code' => 'NO-WT-B', 'line_number' => 1, 'weight' => 0]);

        $resPartialWt = $this->createCastingResultWithPlans([
            [
                'qty' => 20,
                'allocations' => [
                    ['print_line' => $lWtOk, 'allocated_qty' => 10],
                    ['print_line' => $lWtNo, 'allocated_qty' => 10],
                ],
            ],
        ]);

        $kPartialWt = $this->kitirResolver->issueOrGetKitir($resPartialWt, 'WT-OK, NO-WT-B', $this->ppicFitting);
        $snapPartialWt = app(\App\Services\Fitting\FittingKitirSnapshotResolverService::class)->resolve($kPartialWt);

        $this->assertEquals(\App\Services\Fitting\FittingKitirSnapshotResolverService::WEIGHT_STATUS_PARTIAL, $snapPartialWt['weight_status']);
        $this->assertTrue($snapPartialWt['is_weight_incomplete']);
        $this->assertFalse($snapPartialWt['has_complete_weight']);
        $this->assertEquals(['NO-WT-B'], $snapPartialWt['missing_weight_codes']);
        $this->assertEquals(20, $snapPartialWt['qty_transferred']);
        // No misleading weighted average (would falsely be 0.2500 across 20 pcs)
        $this->assertEquals(0.0, $snapPartialWt['unit_weight_kg']);
        // Known weight sum preserved (10 * 0.50 = 5.00 kg)
        $this->assertEquals(5.0, $snapPartialWt['total_weight_kg']);

        // Available UI displays "Tidak lengkap" badge instead of misleading unit weight
        $page = $this->actingAs($this->ppicFitting)->get(route('fitting.transfers.index', ['tab' => 'available']));
        $page->assertOk();
        $page->assertSee('Tidak lengkap');
        $page->assertSee('Sebagian');

        // Transferring preserves exact quantities per allocation and 0.0000 unit_weight_kg on traveler
        $trf = $this->transferService->transferKitirs([$kPartialWt->id], $this->ppicFitting, null, null, null, [$kPartialWt->id => 1]);
        $trfItem = $trf->items->first();
        $this->assertEquals(20, $trfItem->qty_transferred);
        $this->assertEquals('0.0000', (string) $trfItem->unit_weight_kg);
        $this->assertEquals('5.00', (string) $trfItem->total_weight_kg);
        $this->assertEquals(10, $trfItem->allocations->firstWhere('production_plan_id', $planWtOk->id)->allocated_qty_transferred);
        $this->assertEquals(10, $trfItem->allocations->firstWhere('production_plan_id', $planWtNo->id)->allocated_qty_transferred);
    }

    public function test_11_line_assignment_batch_fail_all_old_input_override_tamper_and_immutability(): void
    {
        // 1. Prepare 10 Kitirs: 9 with Line Rencana = 3, 1 with Line Rencana = 0 (unassigned)
        $kitirs = [];
        for ($i = 1; $i <= 10; $i++) {
            [, $pl] = $this->createPlanWithPrintLine([
                'code' => sprintf('BATCH10-%02d', $i),
                'line_number' => $i <= 9 ? 3 : 0,
                'weight' => 0.40,
            ]);
            $res = $this->createCastingResultWithPlans([['qty' => 10, 'print_line' => $pl]]);
            $kitirs[] = $this->kitirResolver->issueOrGetKitir($res, sprintf('BATCH10-%02d', $i), $this->ppicFitting);
        }

        $kitirIds = array_map(fn ($k) => $k->id, $kitirs);
        $kitirLines = [];
        foreach ($kitirs as $idx => $k) {
            if ($idx < 9) {
                $kitirLines[$k->id] = 3;
            }
            // 10th Kitir left blank intentionally!
        }

        // Batch HTTP POST with 9 valid + 1 blank MUST fail ALL atomically & retain old inputs!
        $respFail = $this->actingAs($this->ppicFitting)
            ->from(route('fitting.transfers.index', ['tab' => 'available']))
            ->post(route('fitting.transfers.store'), [
                'kitir_ids' => $kitirIds,
                'kitir_lines' => $kitirLines,
                'notes' => 'Batch 10 test',
            ]);

        $respFail->assertRedirect(route('fitting.transfers.index', ['tab' => 'available']));
        $respFail->assertSessionHasErrors('transfer_error');
        $this->assertStringContainsString($kitirs[9]->traveler_number, (string) session('errors')->first('transfer_error'));
        $this->assertEquals($kitirIds, session('_old_input.kitir_ids'));
        $this->assertEquals($kitirLines, session('_old_input.kitir_lines'));
        $this->assertEquals(0, FittingTransfer::count());
        $this->assertEquals(0, FittingItem::count());

        // 2. Direct tampered Line values (0, 5, 'abc', '') MUST be rejected by service!
        foreach ([0, 5, 'abc', '', -1, null] as $badLine) {
            $rejected = false;
            try {
                $this->transferService->transferKitirs([$kitirs[0]->id], $this->ppicFitting, null, null, null, [$kitirs[0]->id => $badLine]);
            } catch (InvalidArgumentException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'Tampered Line value must be rejected: '.var_export($badLine, true));
        }

        // 3. PPIC overrides Line Rencana (3 -> 1): fitting_items & allocations get Line 1, production_plans stays Line 3!
        [$planOverride, $plOverride] = $this->createPlanWithPrintLine([
            'code' => 'OVR-LINE',
            'item_name' => 'Elbow 90 1"',
            'line_number' => 3,
            'weight' => 0.35,
        ]);
        $resOvr = $this->createCastingResultWithPlans([['qty' => 25, 'print_line' => $plOverride]]);
        $kOvr = $this->kitirResolver->issueOrGetKitir($resOvr, 'OVR-LINE', $this->ppicFitting);

        // Available UI hides Line Rencana, Status Kitir, and Terbit metadata, shows merged Kitir/Heat + 2-decimal unit weight + Line Fitting, and hides Line filter on available tab
        $availPage = $this->actingAs($this->ppicFitting)->get(route('fitting.transfers.index', ['tab' => 'available']));
        $availPage->assertOk();
        $availPage->assertDontSee('Line Rencana');
        $availPage->assertDontSee('Status Kitir');
        $availPage->assertDontSee('Terbit:');
        $availPage->assertSee('Kitir / Heat');
        $availPage->assertSee('Line Fitting');
        $availPage->assertSee('Line 1 — Fitting biasa');
        $availPage->assertSee('0,35 kg');
        $availPage->assertDontSee('0,3500 kg');
        $availPage->assertDontSee('name="line"', false);

        // Search & Date filters work on available tab while preserving compact KPI navigation links
        $filteredPage = $this->actingAs($this->ppicFitting)->get(route('fitting.transfers.index', [
            'tab' => 'available',
            'search' => 'OVR-LINE',
            'date_from' => now()->subDay()->format('Y-m-d'),
            'date_to' => now()->addDay()->format('Y-m-d'),
        ]));
        $filteredPage->assertOk();
        $filteredPage->assertSee($kOvr->traveler_number);
        $filteredPage->assertSee('Heat:');
        $filteredPage->assertSee('Pohon');

        // Decisions tab loads cleanly with compact KPI header
        $decisionsPage = $this->actingAs($this->ppicFitting)->get(route('fitting.transfers.index', ['tab' => 'decisions']));
        $decisionsPage->assertOk();
        $decisionsPage->assertSee('Decision History');

        $trfOvr = $this->transferService->transferKitirs([$kOvr->id], $this->ppicFitting, null, null, null, [$kOvr->id => 1]);
        $itemOvr = $trfOvr->items->first();
        $this->assertEquals(1, $itemOvr->line_number);
        $this->assertEquals(1, $itemOvr->allocations->first()->line_number);
        $this->assertEquals(3, $planOverride->fresh()->line_number, 'Transfer MUST NOT mutate production_plans.line_number.');

        // 4. Editing production_plans.line_number AFTER transfer MUST NOT affect transferred FittingItem!
        $planOverride->update(['line_number' => 4]);
        $this->assertEquals(1, $itemOvr->fresh()->line_number);
        $this->assertEquals(1, $itemOvr->allocations()->first()->line_number);

        // Transferred tab DOES expose Line filter & shows operational Line 1
        $trfPage = $this->actingAs($this->ppicFitting)->get(route('fitting.transfers.index', ['tab' => 'transferred', 'line' => 1]));
        $trfPage->assertOk();
        $trfPage->assertSee('name="line"', false);
        $trfPage->assertSee($itemOvr->traveler_number);
    }
}
