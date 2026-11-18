<?php

namespace Tests\Feature\Fitting;

use App\Models\FittingItem;
use App\Models\FittingStageExecution;
use App\Models\FittingTransfer;
use App\Models\LostWaxCastingResult;
use App\Models\LostWaxCastingResultTree;
use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxTree;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\Fitting\FittingKanbanQueryService;
use App\Services\Fitting\FittingStageExecutionService;
use App\Services\Fitting\FittingTransferService;
use App\Services\LostWax\LostWaxCastingResultService;
use App\Services\LostWax\LostWaxKitirResolverService;
use Carbon\Carbon;
use Database\Seeders\AuditorUserSeeder;
use Database\Seeders\FittingSpvUserSeeder;
use Database\Seeders\QcFittingUserSeeder;
use Database\Seeders\SalesVisibilityUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FittingPhase3ScannerKanbanTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 7000;

    private User $systemAdmin;

    private User $ppicFitting;

    private User $adminPpicPf;

    private User $adminQcFitting;

    private User $auditor;

    private User $sales;

    private User $spvNettoPf;

    private User $spvKimiaPf;

    private User $spvMarkingPf;

    private User $spvCncPf;

    private User $spvQcPf;

    private User $spvGdPf;

    private LostWaxKitirResolverService $kitirResolver;

    private FittingTransferService $transferService;

    private FittingStageExecutionService $executionService;

    private FittingKanbanQueryService $kanbanService;

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
        $this->spvKimiaPf = User::where('email', 'spvkimiapf@peroniks.com')->firstOrFail();
        $this->spvMarkingPf = User::where('email', 'spvmarkingpf@peroniks.com')->firstOrFail();
        $this->spvCncPf = User::where('email', 'spvcncpf@peroniks.com')->firstOrFail();
        $this->spvQcPf = User::where('email', 'spvqcpf@peroniks.com')->firstOrFail();
        $this->spvGdPf = User::where('email', 'spvgdpf@peroniks.com')->firstOrFail();

        $this->kitirResolver = app(LostWaxKitirResolverService::class);
        $this->transferService = app(FittingTransferService::class);
        $this->executionService = app(FittingStageExecutionService::class);
        $this->kanbanService = app(FittingKanbanQueryService::class);
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
     * Helper: create an issued & transferred FittingItem entering NETTO PF.
     */
    private function createTransferredItem(array $overrides = []): array
    {
        $n = self::$seq++;
        $code = $overrides['code'] ?? "PF3-{$n}";
        $castDate = $overrides['cast_date'] ?? Carbon::today()->format('Y-m-d');
        $transferDate = $overrides['transfer_date'] ?? Carbon::today()->format('Y-m-d');
        $qty = $overrides['qty'] ?? 25;

        $plan = ProductionPlan::create([
            'code' => $code,
            'title' => 'Rencana Fitting Fase 3',
            'item_code' => "ITM-{$n}",
            'item_name' => $overrides['item_name'] ?? 'Elbow 90 2" SS304',
            'aisi' => '304',
            'size' => $overrides['size'] ?? '2"',
            'weight' => array_key_exists('weight', $overrides) ? $overrides['weight'] : 0.50,
            'po_number' => "PO-{$n}",
            'po_quantity' => 500,
            'qty_planned' => 500,
            'qty_remaining' => 500,
            'line_number' => array_key_exists('line_number', $overrides) ? $overrides['line_number'] : 1,
            'customer' => $overrides['customer'] ?? 'PT FITTING ALPHA',
            'product_scope' => 'FITTING_STAINLESS',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'status' => 'active',
        ]);

        $printOrder = LostWaxPrintOrder::create([
            'print_order_number' => "LW-PO-{$n}",
            'scheduled_date' => $castDate,
            'status' => 'ISSUED',
            'created_by' => $this->systemAdmin->id,
        ]);

        $line = LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $printOrder->id,
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'aisi' => '304',
            'size' => $plan->size,
            'qty_ordered' => 500,
        ]);

        $result = LostWaxCastingResult::create([
            'heat_number' => $overrides['heat_number'] ?? "H-PF3-{$n}",
            'cast_date' => $castDate,
            'total_trees' => 1,
            'total_pcs' => $qty,
            'status' => 'COMPLETED',
            'recorded_by' => $this->systemAdmin->id,
        ]);

        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => sprintf('26%08d', $n),
            'tree_number' => 1,
            'daily_sequence' => $n,
            'production_date' => $castDate,
            'family_code' => '1',
            'require_layer_7' => false,
            'quantity' => $qty,
            'current_stage' => 'cor',
            'status' => 'active',
        ]);

        LostWaxCastingResultTree::create([
            'lost_wax_casting_result_id' => $result->id,
            'lost_wax_tree_id' => $tree->id,
            'active_tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'production_code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'quantity_pcs' => $qty,
            'gross_pcs' => $qty,
            'defect_pcs' => 0,
        ]);

        $kitir = $this->kitirResolver->issueOrGetKitir($result, $plan->code, $this->ppicFitting);
        $rawPlanLine = array_key_exists('line_number', $overrides) ? $overrides['line_number'] : 1;
        $transferLine = $overrides['transfer_line'] ?? ((is_int($rawPlanLine) && $rawPlanLine >= 1 && $rawPlanLine <= 4) ? $rawPlanLine : 1);
        $transfer = $this->transferService->transferKitirs([$kitir->id], $this->ppicFitting, null, null, $transferDate, [$kitir->id => $transferLine]);
        $item = $transfer->items->first();

        if (! empty($overrides['force_legacy_unassigned'])) {
            $item->update(['line_number' => null]);
            $item->refresh();
        }

        return [$result, $kitir, $transfer, $item];
    }

    // =========================================================================
    // 1. FULL 6-STAGE LINEAR LIFECYCLE, COMPLETED PROTECTION & PHASE 4 COMPAT
    // =========================================================================

    public function test_01_valid_pfktr_completes_all_six_stages_and_locks_completed_lifecycle(): void
    {
        [$result, $kitir, , $item] = $this->createTransferredItem(['line_number' => 2, 'qty' => 40, 'weight' => 0.50]);

        // Pre-set a manual queue_position in netto_pf to verify reset on transition
        $item->update(['queue_position' => 1]);
        $this->assertEquals(FittingItem::STAGE_NETTO_PF, $item->current_stage);

        $stagesAndSpvs = [
            ['stage' => 'netto_pf', 'slug' => 'netto-pf', 'chk' => 'PF_NETTO', 'spv' => $this->spvNettoPf, 'next' => 'kimia'],
            ['stage' => 'kimia', 'slug' => 'kimia', 'chk' => 'PF_KIMIA', 'spv' => $this->spvKimiaPf, 'next' => 'marking'],
            ['stage' => 'marking', 'slug' => 'marking', 'chk' => 'PF_MARKING', 'spv' => $this->spvMarkingPf, 'next' => 'bubut_cnc_pf'],
            ['stage' => 'bubut_cnc_pf', 'slug' => 'bubut-cnc-pf', 'chk' => 'PF_CNC', 'spv' => $this->spvCncPf, 'next' => 'qc_pf'],
            ['stage' => 'qc_pf', 'slug' => 'qc-pf', 'chk' => 'PF_QC', 'spv' => $this->spvQcPf, 'next' => 'gudang_jadi_pf'],
            ['stage' => 'gudang_jadi_pf', 'slug' => 'gudang-jadi-pf', 'chk' => 'PF_GUDANG_JADI', 'spv' => $this->spvGdPf, 'next' => 'completed'],
        ];

        foreach ($stagesAndSpvs as $step) {
            $resp = $this->actingAs($step['spv'])->postJson(
                route('fitting.scan.execute', $step['slug']),
                ['traveler_number' => $item->traveler_number, 'notes' => "Done {$step['stage']}"]
            );

            $resp->assertOk();
            $resp->assertJson([
                'success' => true,
                'data' => [
                    'traveler_number' => $item->traveler_number,
                    'stage' => $step['stage'],
                    'checkpoint_code' => $step['chk'],
                    'input_qty' => 40,
                    'defect_qty' => 0,
                    'good_qty' => 40,
                    'status' => FittingStageExecution::STATUS_WAITING_DEFECT,
                    'current_stage' => $step['next'],
                    'operator_id' => $step['spv']->id,
                ],
            ]);

            $item->refresh();
            $this->assertEquals($step['next'], $item->current_stage);
            $this->assertNull($item->queue_position, 'queue_position must reset to null upon stage advancement.');
            $this->assertEquals(FittingItem::STATUS_ACTIVE, $item->status);
            $this->assertEquals($kitir->id, $item->active_kitir_id);
        }

        // All 6 stage executions recorded cleanly (Phase 4 compatible)
        $this->assertEquals(6, FittingStageExecution::where('fitting_item_id', $item->id)->count());

        // Addendum #2: COMPLETED item cannot be scanned again, cannot be re-transferred, and blocks Lost Wax Heat cancel!
        $this->actingAs($this->spvGdPf)
            ->postJson(route('fitting.scan.execute', 'gudang-jadi-pf'), ['traveler_number' => $item->traveler_number])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $retransferBlocked = false;
        try {
            $this->transferService->transferKitirs([$kitir->id], $this->ppicFitting, null, null, null, [$kitir->id => 2]);
        } catch (InvalidArgumentException $e) {
            $retransferBlocked = str_contains($e->getMessage(), 'sudah ditransfer');
        }
        $this->assertTrue($retransferBlocked, 'COMPLETED traveler must block re-transfer via active_kitir_id.');

        $cancelHeatBlocked = false;
        try {
            $this->castingResultService->cancelCastingResult($result, $this->systemAdmin, 'Try cancel completed');
        } catch (InvalidArgumentException $e) {
            $cancelHeatBlocked = str_contains($e->getMessage(), $item->traveler_number);
        }
        $this->assertTrue($cancelHeatBlocked, 'COMPLETED traveler must block Lost Wax cancelCastingResult.');
    }

    // =========================================================================
    // 2. SCANNER REJECTIONS: UNKNOWN, CANCELLED, WRONG STAGE, SKIP, DOUBLE SCAN, HEAT FALLBACK
    // =========================================================================

    public function test_02_scanner_rejects_unknown_cancelled_skip_wrong_stage_double_scan_and_mass_heat_scan(): void
    {
        // Unknown PFKTR -> 422
        $this->actingAs($this->spvNettoPf)
            ->postJson(route('fitting.scan.execute', 'netto-pf'), ['traveler_number' => 'PFKTR-20261118-9999'])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        // CANCELLED traveler -> 422
        [, , , $cancelledItem] = $this->createTransferredItem(['line_number' => 1]);
        $this->transferService->cancelTransferItem($cancelledItem, $this->ppicFitting, 'Salah kirim');

        $this->actingAs($this->spvNettoPf)
            ->postJson(route('fitting.scan.execute', 'netto-pf'), ['traveler_number' => $cancelledItem->traveler_number])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertEquals(0, FittingStageExecution::where('fitting_item_id', $cancelledItem->id)->count());

        // Active traveler at netto_pf -> cannot skip to kimia or marking
        [, , , $item] = $this->createTransferredItem(['line_number' => 2, 'heat_number' => 'H-MULTI-99']);
        [, , , $itemSameHeat] = $this->createTransferredItem(['line_number' => 3, 'heat_number' => 'H-MULTI-99']);

        $this->actingAs($this->spvKimiaPf)
            ->postJson(route('fitting.scan.execute', 'kimia'), ['traveler_number' => $item->traveler_number])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->actingAs($this->spvMarkingPf)
            ->postJson(route('fitting.scan.execute', 'marking'), ['traveler_number' => $item->traveler_number])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        // Scan netto_pf succeeds -> moves to kimia
        $this->actingAs($this->spvNettoPf)
            ->postJson(route('fitting.scan.execute', 'netto-pf'), ['traveler_number' => $item->traveler_number])
            ->assertOk();

        // Double scan on netto_pf (already passed / rework) -> 422, count stays 1
        $this->actingAs($this->spvNettoPf)
            ->postJson(route('fitting.scan.execute', 'netto-pf'), ['traveler_number' => $item->traveler_number])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
        $this->assertEquals(1, FittingStageExecution::where('fitting_item_id', $item->id)->count());

        // Addendum #1: Heat Number fallback returns candidate list, NEVER mass-scans!
        $heatLookup = $this->actingAs($this->spvNettoPf)->getJson(
            route('fitting.scan.heat', ['heatNumber' => 'H-MULTI-99', 'stage' => 'netto_pf'])
        );
        $heatLookup->assertOk();
        $heatLookup->assertJson(['success' => true]);
        $candidates = $heatLookup->json('data');
        $this->assertCount(2, $candidates);
        $this->assertEqualsCanonicalizing(
            [$item->traveler_number, $itemSameHeat->traveler_number],
            array_column($candidates, 'traveler_number')
        );

        // Passing raw Heat Number or heat_number/traveler_numbers to execute endpoint MUST fail (422)
        $this->actingAs($this->spvNettoPf)
            ->postJson(route('fitting.scan.execute', 'netto-pf'), ['traveler_number' => 'H-MULTI-99'])
            ->assertStatus(422);

        $this->actingAs($this->spvNettoPf)
            ->postJson(route('fitting.scan.execute', 'netto-pf'), [
                'heat_number' => 'H-MULTI-99',
                'traveler_number' => $itemSameHeat->traveler_number,
            ])
            ->assertStatus(422);

        $this->actingAs($this->spvNettoPf)
            ->postJson(route('fitting.scan.execute', 'netto-pf'), [
                'traveler_numbers' => [$itemSameHeat->traveler_number],
            ])
            ->assertStatus(422);
    }

    // =========================================================================
    // 3. AUTHORIZATION: 6 SPVs, SPVGD VISIBILITY, PPIC/ADMINPPICPF/QC/AUDITOR/SALES
    // =========================================================================

    public function test_03_authorization_strictly_isolates_spvs_ppic_qc_auditor_and_sales(): void
    {
        [, , , $item] = $this->createTransferredItem(['line_number' => 1]);

        // SPV Netto PF: allowed netto-pf, 403 on kimia & other stages (both scan & kanban)
        $this->actingAs($this->spvNettoPf)->get(route('fitting.scan.stage', 'netto-pf'))->assertOk();
        $this->actingAs($this->spvNettoPf)->get(route('fitting.kanban.stage', 'netto-pf'))->assertOk();
        $this->actingAs($this->spvNettoPf)->get(route('fitting.scan.stage', 'kimia'))->assertForbidden();
        $this->actingAs($this->spvNettoPf)->get(route('fitting.kanban.stage', 'kimia'))->assertForbidden();
        $this->actingAs($this->spvNettoPf)
            ->postJson(route('fitting.scan.execute', 'kimia'), ['traveler_number' => $item->traveler_number])
            ->assertForbidden();
        $this->actingAs($this->spvNettoPf)
            ->postJson(route('fitting.kanban.reorder', 'netto-pf'), ['line_bucket' => '1', 'from_position' => 1, 'to_position' => 1])
            ->assertForbidden();

        // SPV Gudang Jadi PF: views ALL 6 Kanbans, executes ONLY gudang-jadi-pf, CANNOT reorder
        foreach (['netto-pf', 'kimia', 'marking', 'bubut-cnc-pf', 'qc-pf', 'gudang-jadi-pf'] as $slug) {
            $resp = $this->actingAs($this->spvGdPf)->get(route('fitting.kanban.stage', $slug));
            $resp->assertOk();
            $resp->assertDontSee('EDIT ANTRIAN');
            if ($slug === 'gudang-jadi-pf') {
                $resp->assertSee(route('fitting.scan.stage', 'gudang-jadi-pf'));
            } else {
                $resp->assertDontSee(route('fitting.scan.stage', $slug));
            }
        }
        $this->actingAs($this->spvGdPf)->get(route('fitting.scan.stage', 'gudang-jadi-pf'))->assertOk();
        $this->actingAs($this->spvGdPf)->get(route('fitting.scan.stage', 'netto-pf'))->assertForbidden();
        $this->actingAs($this->spvGdPf)
            ->postJson(route('fitting.scan.execute', 'netto-pf'), ['traveler_number' => $item->traveler_number])
            ->assertForbidden();
        $this->actingAs($this->spvGdPf)
            ->postJson(route('fitting.kanban.reorder', 'netto-pf'), ['line_bucket' => '1', 'from_position' => 1, 'to_position' => 1])
            ->assertForbidden();

        // PPIC Fitting, Admin PPIC PF, Admin QC Fitting, Auditor, Sales CANNOT access Scanner or pretend to be SPV!
        foreach ([$this->ppicFitting, $this->adminPpicPf, $this->adminQcFitting, $this->auditor, $this->sales] as $nonSpv) {
            $this->actingAs($nonSpv)->get(route('fitting.scan.index'))->assertForbidden();
            $this->actingAs($nonSpv)->get(route('fitting.scan.stage', 'netto-pf'))->assertForbidden();
            $this->actingAs($nonSpv)
                ->postJson(route('fitting.scan.execute', 'netto-pf'), ['traveler_number' => $item->traveler_number])
                ->assertForbidden();
            $this->actingAs($nonSpv)
                ->getJson(route('fitting.scan.pfktr', ['travelerNumber' => $item->traveler_number, 'stage' => 'netto_pf']))
                ->assertForbidden();
        }

        // Auditor, Sales, Admin PPIC PF, Admin QC Fitting CAN view Kanban (read-only) but CANNOT reorder!
        foreach ([$this->adminPpicPf, $this->adminQcFitting, $this->auditor, $this->sales] as $noReorderUser) {
            $kResp = $this->actingAs($noReorderUser)->get(route('fitting.kanban.index'));
            $kResp->assertOk();
            $kResp->assertDontSee('EDIT ANTRIAN');
            $kResp->assertDontSee(route('fitting.scan.stage', 'netto-pf'));

            $this->actingAs($noReorderUser)
                ->postJson(route('fitting.kanban.reorder', 'netto-pf'), ['line_bucket' => '1', 'from_position' => 1, 'to_position' => 1])
                ->assertForbidden();
        }

        // PPIC Fitting sees EDIT ANTRIAN on Kanban, NO SCAN button
        $ppicKanban = $this->actingAs($this->ppicFitting)->get(route('fitting.kanban.index'));
        $ppicKanban->assertOk();
        $ppicKanban->assertSee('EDIT ANTRIAN');
        $ppicKanban->assertDontSee(route('fitting.scan.stage', 'netto-pf'));
    }

    // =========================================================================
    // 4. KANBAN 5 BUCKETS (LINE 1..4 + UNASSIGNED), FIFO & QUEUE REORDER INTEGRITY
    // =========================================================================

    public function test_04_kanban_displays_unassigned_respects_fifo_and_reorder_preserves_transfer_history(): void
    {
        // Create 3 items on Line 1 with different cast/transfer dates + 2 items on Unassigned (line_number = 0 / 5, size 2")
        [, , $trf1, $itemOlder] = $this->createTransferredItem([
            'code' => 'FIFO-OLD',
            'line_number' => 1,
            'cast_date' => '2026-11-15',
            'transfer_date' => '2026-11-16',
        ]);
        [, , $trf2, $itemMid] = $this->createTransferredItem([
            'code' => 'FIFO-MID',
            'line_number' => 1,
            'cast_date' => '2026-11-16',
            'transfer_date' => '2026-11-16',
        ]);
        [, , $trf3, $itemNewer] = $this->createTransferredItem([
            'code' => 'FIFO-NEW',
            'line_number' => 1,
            'cast_date' => '2026-11-16',
            'transfer_date' => '2026-11-18',
        ]);
        [, , , $itemUnassigned1] = $this->createTransferredItem([
            'code' => 'UNASS-1',
            'line_number' => 5, // legacy unassigned row -> NEVER inferred from size 2"!
            'force_legacy_unassigned' => true,
            'size' => '2"',
            'item_name' => 'Elbow 2" DN50',
        ]);
        [, , , $itemUnassigned2] = $this->createTransferredItem([
            'code' => 'UNASS-2',
            'line_number' => 0,
            'force_legacy_unassigned' => true,
            'size' => '4"',
        ]);

        // Verify Kanban data structure: 5 buckets, Unassigned preserved, FIFO order on Line 1
        $data = $this->kanbanService->getStageKanbanData('netto_pf');
        $this->assertEquals(5, $data['summary']['total_cards']);
        $this->assertEquals(2, $data['summary']['unassigned_cards']);
        $this->assertEquals(
            [$itemOlder->id, $itemMid->id, $itemNewer->id],
            array_column($data['buckets']['1'], 'id')
        );
        $this->assertEquals(
            [$itemUnassigned1->id, $itemUnassigned2->id],
            array_column($data['buckets']['unassigned'], 'id')
        );
        $this->assertNull($itemUnassigned1->fresh()->line_number);
        $this->assertNull($itemUnassigned2->fresh()->line_number);

        // Snapshot FittingTransfer records before reorder
        $trf1Before = $trf1->fresh()->toArray();
        $trf2Before = $trf2->fresh()->toArray();
        $trf3Before = $trf3->fresh()->toArray();

        // PPIC reorders Line 1: move position 3 (FIFO-NEW) -> position 1
        $reorderResp = $this->actingAs($this->ppicFitting)->postJson(
            route('fitting.kanban.reorder', 'netto-pf'),
            ['line_bucket' => '1', 'from_position' => 3, 'to_position' => 1]
        );
        $reorderResp->assertOk()->assertJson(['success' => true]);

        $afterLine1 = $this->kanbanService->getStageKanbanData('netto_pf')['buckets']['1'];
        $this->assertEquals(
            [$itemNewer->id, $itemOlder->id, $itemMid->id],
            array_column($afterLine1, 'id')
        );

        // queue_position must be dense 1..3 with ZERO duplicates
        $positionsLine1 = FittingItem::whereIn('id', [$itemNewer->id, $itemOlder->id, $itemMid->id])
            ->orderBy('queue_position')
            ->pluck('queue_position')
            ->all();
        $this->assertEquals([1, 2, 3], $positionsLine1);

        // Also test reordering the Unassigned bucket (position 2 -> position 1)
        $this->actingAs($this->ppicFitting)->postJson(
            route('fitting.kanban.reorder', 'netto-pf'),
            ['line_bucket' => 'unassigned', 'from_position' => 2, 'to_position' => 1]
        )->assertOk();

        $afterUnassigned = $this->kanbanService->getStageKanbanData('netto_pf')['buckets']['unassigned'];
        $this->assertEquals([$itemUnassigned2->id, $itemUnassigned1->id], array_column($afterUnassigned, 'id'));
        $this->assertNull($itemUnassigned1->fresh()->line_number, 'Reorder must NOT assign a line number to Unassigned items.');
        $this->assertNull($itemUnassigned2->fresh()->line_number);

        // Transfer history MUST remain 100% untouched after reorder!
        $this->assertEquals($trf1Before, $trf1->fresh()->toArray());
        $this->assertEquals($trf2Before, $trf2->fresh()->toArray());
        $this->assertEquals($trf3Before, $trf3->fresh()->toArray());

        // Sidebar order & Kanban view rendering verification (including Line 1-4 classification labels)
        $html = $this->actingAs($this->ppicFitting)->get(route('fitting.kanban.stage', 'netto-pf'))->getContent();
        $this->assertStringContainsString('UNASSIGNED', $html);
        $this->assertStringContainsString('LINE 1 — ELBOW / TEE', $html);
        $this->assertStringContainsString('LINE 2 — UNION', $html);
        $this->assertStringContainsString('LINE 4 — BALL VALVE', $html);
        $this->assertStringContainsString($itemUnassigned1->traveler_number, $html);
        $this->assertStringContainsString('Transfer Kitir', $html);
        $this->assertStringContainsString('Kanban Floor', $html);
        $this->assertStringNotContainsString('Fitting Dashboard', $html);
        $this->assertStringNotContainsString('Pencatatan Kerusakan Fitting', $html);
    }

    public function test_05_scanner_shows_effective_qty_two_step_confirmation_and_blocks_zero_effective_input(): void
    {
        [, , , $item] = $this->createTransferredItem(['line_number' => 1, 'qty' => 30]);

        // 1. Scanner views render Qty Efektif Masuk, BATAL, and 2-step confirmation modal
        $scanHtml = $this->actingAs($this->spvKimiaPf)->get(route('fitting.scan.stage', 'kimia'))->getContent();
        $this->assertStringContainsString('confirmScanModal', $scanHtml);
        $this->assertStringContainsString('Qty Efektif Masuk', $scanHtml);
        $this->assertStringContainsString('BATAL', $scanHtml);

        $gdScanHtml = $this->actingAs($this->spvGdPf)->get(route('fitting.scan.stage', 'gudang-jadi-pf'))->getContent();
        $this->assertStringContainsString('Final Quality Lock', $gdScanHtml);

        // 2. Scan netto_pf -> record 10 defects -> lookup kimia shows effective_input_qty = 20, eligible = true
        $nettoExec = $this->executionService->scanStage($item->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);
        app(\App\Services\Fitting\FittingDefectService::class)->recordDefectQty($nettoExec->id, 10, $this->adminPpicPf, 'Defect parsial');

        $lookup20 = $this->actingAs($this->spvKimiaPf)->getJson(
            route('fitting.scan.pfktr', ['travelerNumber' => $item->traveler_number, 'stage' => 'kimia'])
        );
        $lookup20->assertOk()
            ->assertJsonPath('data.qty_transferred', 30)
            ->assertJsonPath('data.effective_input_qty', 20)
            ->assertJsonPath('data.upstream_defect_qty', 10)
            ->assertJsonPath('data.is_eligible_for_stage', true);

        // 3. Adjust defect +20 (total 30 = 100% defect) -> effective_input_qty = 0 -> lookup ineligible & scan BLOCKED (422)
        app(\App\Services\Fitting\FittingDefectService::class)->adjustDefectQty($nettoExec->id, 20, $this->adminPpicPf, 'Semua cacat');

        $lookup0 = $this->actingAs($this->spvKimiaPf)->getJson(
            route('fitting.scan.pfktr', ['travelerNumber' => $item->traveler_number, 'stage' => 'kimia'])
        );
        $lookup0->assertOk()
            ->assertJsonPath('data.effective_input_qty', 0)
            ->assertJsonPath('data.upstream_defect_qty', 30)
            ->assertJsonPath('data.is_eligible_for_stage', false);

        $blockedResp = $this->actingAs($this->spvKimiaPf)->postJson(
            route('fitting.scan.execute', 'kimia'),
            ['traveler_number' => $item->traveler_number]
        );
        $blockedResp->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertStringContainsString('0 PCS', (string) $blockedResp->json('message'));
        $this->assertEquals(0, FittingStageExecution::where('fitting_item_id', $item->id)->where('stage', FittingItem::STAGE_KIMIA)->count());
    }
}
