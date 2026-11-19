<?php

namespace Tests\Feature\Fitting;

use App\Models\DefectType;
use App\Models\FittingItem;
use App\Models\FittingStageExecution;
use App\Models\FittingStageExecutionDefectLog;
use App\Models\LostWaxCastingResult;
use App\Models\LostWaxCastingResultTree;
use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxTree;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\Fitting\FittingDefectService;
use App\Services\Fitting\FittingQuantityResolverService;
use App\Services\Fitting\FittingStageExecutionService;
use App\Services\Fitting\FittingTransferService;
use App\Services\LostWax\LostWaxKitirResolverService;
use Carbon\Carbon;
use Database\Seeders\AuditorUserSeeder;
use Database\Seeders\FittingSpvUserSeeder;
use Database\Seeders\QcFittingUserSeeder;
use Database\Seeders\SalesVisibilityUserSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FittingPhase4DefectQcTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 9000;

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

    private FittingDefectService $defectService;

    private FittingQuantityResolverService $quantityResolver;

    private DefectType $defectPorosity;

    private DefectType $defectRetak;

    private DefectType $defectInactive;

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
        $this->defectService = app(FittingDefectService::class);
        $this->quantityResolver = app(FittingQuantityResolverService::class);

        $this->defectPorosity = DefectType::create([
            'department' => FittingItem::STAGE_NETTO_PF,
            'name' => 'Porosity PF',
            'is_active' => true,
        ]);
        $this->defectRetak = DefectType::create([
            'department' => FittingItem::STAGE_NETTO_PF,
            'name' => 'Retak PF',
            'is_active' => true,
        ]);
        $this->defectInactive = DefectType::create([
            'department' => FittingItem::STAGE_NETTO_PF,
            'name' => 'Legacy Inactive PF',
            'is_active' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

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

    private function createTransferredItem(array $overrides = []): FittingItem
    {
        $n = self::$seq++;
        $code = $overrides['code'] ?? "PF4-{$n}";
        $castDate = $overrides['cast_date'] ?? Carbon::today()->format('Y-m-d');
        $transferDate = $overrides['transfer_date'] ?? Carbon::today()->format('Y-m-d');
        $qty = $overrides['qty'] ?? 100;

        $plan = ProductionPlan::create([
            'code' => $code,
            'title' => 'Rencana Fitting Fase 4',
            'item_code' => "ITM-{$n}",
            'item_name' => $overrides['item_name'] ?? 'Elbow 90 2" SS304',
            'aisi' => '304',
            'size' => '2"',
            'weight' => 0.50,
            'po_number' => "PO-{$n}",
            'po_quantity' => 500,
            'qty_planned' => 500,
            'qty_remaining' => 500,
            'line_number' => $overrides['line_number'] ?? 1,
            'customer' => $overrides['customer'] ?? 'PT Fitting Nusantara',
            'product_scope' => 'FITTING_STAINLESS',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'status' => 'active',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => "LW-PO4-{$n}",
            'scheduled_date' => $castDate,
            'status' => 'ISSUED',
            'created_by' => $this->systemAdmin->id,
        ]);

        $line = LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $order->id,
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'aisi' => '304',
            'size' => $plan->size,
            'qty_ordered' => 500,
        ]);

        $castingResult = LostWaxCastingResult::create([
            'heat_number' => $overrides['heat_number'] ?? "H4-{$n}",
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
            'lost_wax_casting_result_id' => $castingResult->id,
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

        $kitir = $this->kitirResolver->issueOrGetKitir($castingResult, $code, $this->ppicFitting);
        $transferLine = (int) ($overrides['line_number'] ?? 1);
        if ($transferLine < 1 || $transferLine > 4) {
            $transferLine = 1;
        }
        $transfer = $this->transferService->transferKitirs([$kitir->id], $this->ppicFitting, null, null, $transferDate, [$kitir->id => $transferLine]);

        return $transfer->items->first()->fresh();
    }

    private function scanUpToStage(FittingItem $item, string $lastStage): array
    {
        $spvMap = [
            FittingItem::STAGE_NETTO_PF => $this->spvNettoPf,
            FittingItem::STAGE_KIMIA => $this->spvKimiaPf,
            FittingItem::STAGE_MARKING => $this->spvMarkingPf,
            FittingItem::STAGE_BUBUT_CNC_PF => $this->spvCncPf,
            FittingItem::STAGE_QC_PF => $this->spvQcPf,
            FittingItem::STAGE_GUDANG_JADI_PF => $this->spvGdPf,
        ];

        $executions = [];
        foreach (FittingItem::STAGES as $stage) {
            $executions[$stage] = $this->executionService->scanStage($item->traveler_number, $stage, $spvMap[$stage]);
            if ($stage === $lastStage) {
                break;
            }
        }

        return $executions;
    }

    // =========================================================================
    // TEST 1: Valid Defect Recording, Boundary Validation, Zero Good Qty & Idempotency
    // =========================================================================

    public function test_01_valid_defect_recording_boundaries_zero_good_qty_and_idempotency(): void
    {
        $item = $this->createTransferredItem(['qty' => 50]);
        $nettoExec = $this->executionService->scanStage($item->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);

        // Negative defect rejected
        $negRejected = false;
        try {
            $this->defectService->recordDefectQty($nettoExec->id, -1, $this->adminPpicPf);
        } catch (InvalidArgumentException) {
            $negRejected = true;
        }
        $this->assertTrue($negRejected, 'Negative defect must be rejected.');

        // Defect > effective input rejected
        $overRejected = false;
        try {
            $this->defectService->recordDefectQty($nettoExec->id, 51, $this->adminPpicPf);
        } catch (InvalidArgumentException $e) {
            $overRejected = str_contains($e->getMessage(), 'melebihi');
        }
        $this->assertTrue($overRejected, 'Defect > effective input must be rejected.');

        // Valid defect recording with idempotency key
        $recorded = $this->defectService->recordDefectQty(
            executionOrId: $nettoExec->id,
            defectQty: 12,
            user: $this->adminPpicPf,
            notes: 'Cacat awal netto',
            inspectionDate: Carbon::today()->toDateString(),
            idempotencyKey: 'idem-rec-01'
        );

        $this->assertSame(FittingStageExecution::STATUS_WAITING_QC, $recorded->status);
        $this->assertSame(12, (int) $recorded->defect_qty);
        $this->assertSame(38, (int) $recorded->good_qty);
        $this->assertSame($this->adminPpicPf->id, (int) $recorded->defect_entered_by);
        $this->assertNotNull($recorded->defect_entered_at);
        $this->assertCount(1, $recorded->defectLogs);
        $this->assertSame(12, (int) $recorded->defectLogs->first()->added_qty);

        // Idempotent replay with same key & same qty returns cleanly without duplicate log
        $replayed = $this->defectService->recordDefectQty(
            executionOrId: $nettoExec->id,
            defectQty: 12,
            user: $this->adminPpicPf,
            idempotencyKey: 'idem-rec-01'
        );
        $this->assertSame(12, (int) $replayed->defect_qty);
        $this->assertSame(1, FittingStageExecutionDefectLog::where('fitting_stage_execution_id', $nettoExec->id)->count());

        // Same key with different qty rejected
        $mismatchRejected = false;
        try {
            $this->defectService->recordDefectQty($nettoExec->id, 15, $this->adminPpicPf, idempotencyKey: 'idem-rec-01');
        } catch (InvalidArgumentException) {
            $mismatchRejected = true;
        }
        $this->assertTrue($mismatchRejected);

        // Edge case: Defect equals full effective input -> good_qty = 0
        $itemZero = $this->createTransferredItem(['qty' => 20]);
        $execZero = $this->executionService->scanStage($itemZero->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);
        $recordedAllDefect = $this->defectService->recordDefectQty($execZero->id, 20, $this->adminPpicPf, 'Semua rusak');

        $this->assertSame(20, (int) $recordedAllDefect->defect_qty);
        $this->assertSame(0, (int) $recordedAllDefect->good_qty);
        $this->assertSame(0, $this->quantityResolver->resolveEffectiveGoodQty($itemZero->fresh('stageExecutions'), FittingItem::STAGE_NETTO_PF));
        $this->assertSame(0, $this->quantityResolver->resolveEffectiveInputQty($itemZero->fresh('stageExecutions'), FittingItem::STAGE_KIMIA));

        // Phase 4.1: Manual defect = 0 -> NO_DEFECT (does NOT enter QC queue!)
        $itemNihil = $this->createTransferredItem(['qty' => 30]);
        $execNihil = $this->executionService->scanStage($itemNihil->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);
        $recordedNihil = $this->defectService->recordDefectQty($execNihil->id, 0, $this->adminPpicPf, 'Tidak ada defect');

        $this->assertSame(FittingStageExecution::STATUS_NO_DEFECT, $recordedNihil->status);
        $this->assertSame(0, (int) $recordedNihil->defect_qty);
        $this->assertSame(30, (int) $recordedNihil->good_qty);
        $this->assertFalse((bool) $recordedNihil->is_auto_nihil);
        $this->assertNull($recordedNihil->auto_nihil_at);

        // Must NOT appear in QC queue & QC cannot verify/saveDraft on 0 defect
        $this->actingAs($this->adminQcFitting)
            ->get(route('fitting.qc-defects.index', ['tab' => 'waiting']))
            ->assertOk()
            ->assertDontSee($itemNihil->traveler_number);
    }

    // =========================================================================
    // TEST 2: Defect Adjustment (+/-), NO_DEFECT Transition, Direct CONFIRMED Block & Two-Party QC Reopen
    // =========================================================================

    public function test_02_defect_adjustment_audit_trail_direct_confirmed_block_and_two_party_qc_reopen(): void
    {
        $item = $this->createTransferredItem(['qty' => 60]);
        $exec = $this->executionService->scanStage($item->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);

        // Initial record 10 -> WAITING_QC (and save a partial QC draft)
        $this->defectService->recordDefectQty($exec->id, 10, $this->adminPpicPf, 'Awal');
        $this->defectService->saveQcBreakdownDraft(
            $exec->id,
            [['defect_type_id' => $this->defectPorosity->id, 'qty' => 10]],
            $this->adminQcFitting
        );

        // Negative adjustment without notes rejected
        $missingNoteRejected = false;
        try {
            $this->defectService->adjustDefectQty($exec->id, -2, $this->adminPpicPf, null);
        } catch (InvalidArgumentException) {
            $missingNoteRejected = true;
        }
        $this->assertTrue($missingNoteRejected, 'Negative adjustment without notes must be rejected.');

        // Negative adjustment pushing below 0 rejected
        $belowZeroRejected = false;
        try {
            $this->defectService->adjustDefectQty($exec->id, -11, $this->adminPpicPf, 'Terlalu banyak');
        } catch (InvalidArgumentException) {
            $belowZeroRejected = true;
        }
        $this->assertTrue($belowZeroRejected);

        // Zero adjustment on WAITING_QC (not REOPENED_FOR_CORRECTION) rejected
        $zeroOnWaitingRejected = false;
        try {
            $this->defectService->adjustDefectQty($exec->id, 0, $this->adminPpicPf, 'Tetap');
        } catch (InvalidArgumentException) {
            $zeroOnWaitingRejected = true;
        }
        $this->assertTrue($zeroOnWaitingRejected);

        // Positive adjustment (+5) on WAITING_QC -> 15
        $adjustedUp = $this->defectService->adjustDefectQty(
            executionOrId: $exec->id,
            adjustmentQty: 5,
            user: $this->adminPpicPf,
            notes: 'Tambahan defect susulan',
            idempotencyKey: 'adj-up-01'
        );
        $this->assertSame(15, (int) $adjustedUp->defect_qty);
        $this->assertSame(45, (int) $adjustedUp->good_qty);
        $this->assertSame(FittingStageExecution::STATUS_WAITING_QC, $adjustedUp->status);

        // Negative adjustment (-7 -> 8) clears inconsistent draft (10 > 8)
        $adjustedDown = $this->defectService->adjustDefectQty(
            executionOrId: $exec->id,
            adjustmentQty: -7,
            user: $this->adminPpicPf,
            notes: 'Salah hitung fisik sebelumnya',
            idempotencyKey: 'adj-down-01'
        );
        $this->assertSame(8, (int) $adjustedDown->defect_qty);
        $this->assertSame(52, (int) $adjustedDown->good_qty);
        $this->assertCount(0, $adjustedDown->defects, 'Stale QC breakdown exceeding new defect_qty must be cleared.');

        // Negative adjustment all the way to 0 (-8 -> 0) -> NO_DEFECT (exits QC queue!)
        $adjustedZero = $this->defectService->adjustDefectQty(
            executionOrId: $exec->id,
            adjustmentQty: -8,
            user: $this->adminPpicPf,
            notes: 'Ternyata tidak ada defect',
            idempotencyKey: 'adj-zero-01'
        );
        $this->assertSame(0, (int) $adjustedZero->defect_qty);
        $this->assertSame(60, (int) $adjustedZero->good_qty);
        $this->assertSame(FittingStageExecution::STATUS_NO_DEFECT, $adjustedZero->status);

        // Adjusting back up (+5) from NO_DEFECT -> WAITING_QC (re-enters QC queue!)
        $adjustedBackUp = $this->defectService->adjustDefectQty(
            executionOrId: $exec->id,
            adjustmentQty: 5,
            user: $this->adminPpicPf,
            notes: 'Ditemukan 5 defect baru',
            idempotencyKey: 'adj-reenter-01'
        );
        $this->assertSame(5, (int) $adjustedBackUp->defect_qty);
        $this->assertSame(FittingStageExecution::STATUS_WAITING_QC, $adjustedBackUp->status);

        // QC verifies 5 -> CONFIRMED
        $confirmed = $this->defectService->verifyQcBreakdown(
            $exec->id,
            [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 3],
                ['defect_type_id' => $this->defectRetak->id, 'qty' => 2],
            ],
            $this->adminQcFitting,
            'Breakdown awal terverifikasi'
        );
        $this->assertSame(FittingStageExecution::STATUS_CONFIRMED, $confirmed->status);

        // Phase 4.1: Admin PPIC CANNOT directly adjust a CONFIRMED execution!
        $directEditConfirmedBlocked = false;
        try {
            $this->defectService->adjustDefectQty($exec->id, 1, $this->adminPpicPf, 'Coba edit langsung');
        } catch (InvalidArgumentException $e) {
            $directEditConfirmedBlocked = str_contains($e->getMessage(), 'CONFIRMED') || str_contains($e->getMessage(), 'Admin QC');
        }
        $this->assertTrue($directEditConfirmedBlocked, 'Admin PPIC must NOT directly edit CONFIRMED execution.');

        // Two-Party QC Reopen Path B: 5 -> CONFIRMED -> QC REOPEN (mandatory reason, lossless snapshot, qty stays 5) -> PPIC adjust -2 to 3 -> WAITING_QC -> QC re-verify 3
        $reopenMissingReason = false;
        try {
            $this->defectService->reopenConfirmedForCorrection($exec->id, $this->adminQcFitting, '   ');
        } catch (InvalidArgumentException) {
            $reopenMissingReason = true;
        }
        $this->assertTrue($reopenMissingReason, 'QC Reopen must require a non-empty reason.');

        $reopened1 = $this->defectService->reopenConfirmedForCorrection($exec->id, $this->adminQcFitting, 'Salah klasifikasi defect di lapangan');
        $this->assertSame(FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION, $reopened1->status);
        $this->assertSame(5, (int) $reopened1->defect_qty, 'QC Reopen must NEVER modify PPIC defect_qty.');
        $this->assertSame(55, (int) $reopened1->good_qty);
        $this->assertNull($reopened1->qc_verified_at);
        $this->assertNull($reopened1->qc_verified_by);
        $this->assertCount(2, $reopened1->defects, 'Previous breakdown preserved as draft reference until PPIC reduces/zeroes qty or QC replaces on verify.');

        // Verify lossless qc_snapshot_json archived in defect log
        $reopenLog1 = FittingStageExecutionDefectLog::where('fitting_stage_execution_id', $exec->id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($reopenLog1->qc_snapshot_json);
        $this->assertSame($this->adminQcFitting->id, $reopenLog1->qc_snapshot_json['previous_qc_verified_by']);
        $this->assertCount(2, $reopenLog1->qc_snapshot_json['previous_defects']);
        $this->assertSame(5, array_sum(array_column($reopenLog1->qc_snapshot_json['previous_defects'], 'qty')));
        $this->assertStringContainsString('[QC REOPEN]', (string) $reopenLog1->notes);

        // PPIC adjusts -2 -> 3 (returns to WAITING_QC) -> QC re-verifies 3 -> CONFIRMED
        $ppicCorrected3 = $this->defectService->adjustDefectQty($exec->id, -2, $this->adminPpicPf, 'Revisi jadi 3 sesuai temuan QC');
        $this->assertSame(3, (int) $ppicCorrected3->defect_qty);
        $this->assertSame(FittingStageExecution::STATUS_WAITING_QC, $ppicCorrected3->status);

        $reconfirmed3 = $this->defectService->verifyQcBreakdown(
            $exec->id,
            [['defect_type_id' => $this->defectPorosity->id, 'qty' => 3]],
            $this->adminQcFitting
        );
        $this->assertSame(FittingStageExecution::STATUS_CONFIRMED, $reconfirmed3->status);

        // Two-Party QC Reopen Path C (Clarification #2 — Unchanged Quantity): 5 -> CONFIRMED -> QC REOPEN -> tetap 5 -> WAITING_QC -> QC re-verify 5
        $itemKeep = $this->createTransferredItem(['qty' => 40]);
        $execKeep = $this->executionService->scanStage($itemKeep->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);
        $this->defectService->recordDefectQty($execKeep->id, 5, $this->adminPpicPf, 'Awal 5');
        $this->defectService->verifyQcBreakdown(
            $execKeep->id,
            [['defect_type_id' => $this->defectPorosity->id, 'qty' => 5]],
            $this->adminQcFitting
        );
        $this->defectService->reopenConfirmedForCorrection($execKeep->id, $this->adminQcFitting, 'Perlu ubah jenis cacat tanpa ubah qty');

        // Unchanged confirmation WITHOUT notes MUST be rejected
        $unchangedNoNotes = false;
        try {
            $this->defectService->adjustDefectQty($execKeep->id, 0, $this->adminPpicPf, '');
        } catch (InvalidArgumentException) {
            $unchangedNoNotes = true;
        }
        $this->assertTrue($unchangedNoNotes);

        // PPIC confirms unchanged (delta = 0) with mandatory reason -> WAITING_QC, qty stays 5!
        $keptSame = $this->defectService->adjustDefectQty(
            $execKeep->id,
            0,
            $this->adminPpicPf,
            'Jumlah fisik tetap 5 PCS, hanya revisi kategori QC'
        );
        $this->assertSame(5, (int) $keptSame->defect_qty);
        $this->assertSame(35, (int) $keptSame->good_qty);
        $this->assertSame(FittingStageExecution::STATUS_WAITING_QC, $keptSame->status);
        $keepLog = FittingStageExecutionDefectLog::where('fitting_stage_execution_id', $execKeep->id)->orderByDesc('id')->first();
        $this->assertSame(0, (int) $keepLog->added_qty);
        $this->assertSame(5, (int) $keepLog->previous_total);
        $this->assertSame(5, (int) $keepLog->new_total);
        $this->assertStringContainsString('[KONFIRMASI TETAP]', (string) $keepLog->notes);

        // QC re-verifies 5 with corrected breakdown -> CONFIRMED!
        $reconfirmed5 = $this->defectService->verifyQcBreakdown(
            $execKeep->id,
            [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 2],
                ['defect_type_id' => $this->defectRetak->id, 'qty' => 3],
            ],
            $this->adminQcFitting
        );
        $this->assertSame(FittingStageExecution::STATUS_CONFIRMED, $reconfirmed5->status);

        // Two-Party QC Reopen Path A (Decrease to 0): 5 -> CONFIRMED -> QC REOPEN -> PPIC adjust -5 -> 0 -> NO_DEFECT (exits QC queue!)
        $this->defectService->reopenConfirmedForCorrection($execKeep->id, $this->adminQcFitting, 'Setelah cek ulang ternyata 0 defect');
        $correctedToZero = $this->defectService->adjustDefectQty($execKeep->id, -5, $this->adminPpicPf, 'Koreksi penuh ke 0');
        $this->assertSame(0, (int) $correctedToZero->defect_qty);
        $this->assertSame(40, (int) $correctedToZero->good_qty);
        $this->assertSame(FittingStageExecution::STATUS_NO_DEFECT, $correctedToZero->status);
    }

    // =========================================================================
    // TEST 3: Dynamic Effective Good Quantity Propagation Across Stages
    // =========================================================================

    public function test_03_effective_quantity_propagation_without_double_counting_or_history_mutation(): void
    {
        $item = $this->createTransferredItem(['qty' => 100]);

        // Stage 1: Scan netto_pf -> record defect 10 (effective good = 90)
        $nettoExec = $this->executionService->scanStage($item->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);
        $this->assertSame(100, (int) $nettoExec->input_qty);
        $this->defectService->recordDefectQty($nettoExec->id, 10, $this->adminPpicPf);

        // Stage 2: Scan kimia -> automatically receives effective input 90
        $kimiaExec = $this->executionService->scanStage($item->traveler_number, FittingItem::STAGE_KIMIA, $this->spvKimiaPf);
        $this->assertSame(90, (int) $kimiaExec->input_qty);
        $this->assertSame(90, (int) $kimiaExec->good_qty);
        $this->defectService->recordDefectQty($kimiaExec->id, 5, $this->adminPpicPf);

        // Stage 3: Scan marking -> automatically receives effective input 85
        $markingExec = $this->executionService->scanStage($item->traveler_number, FittingItem::STAGE_MARKING, $this->spvMarkingPf);
        $this->assertSame(85, (int) $markingExec->input_qty);
        $this->defectService->recordDefectQty($markingExec->id, 3, $this->adminPpicPf);

        // Now retroactively adjust netto_pf defect +5 (total netto defect = 15)
        $this->defectService->adjustDefectQty($nettoExec->id, 5, $this->adminPpicPf, 'Defect susulan netto');

        // Historical scan snapshots remain intact (Phase 3 history NOT deleted or mutated)
        $this->assertSame(90, (int) $kimiaExec->fresh()->input_qty);
        $this->assertSame(85, (int) $markingExec->fresh()->input_qty);

        // Dynamic single-source-of-truth propagation (no double-counting!):
        // Transfer = 100 | Netto defect = 15 (good=85) | Kimia defect = 5 (in=85, good=80) | Marking defect = 3 (in=80, good=77)
        $freshItem = $item->fresh('stageExecutions');
        $trace = $this->quantityResolver->buildStageQuantityTrace($freshItem);

        $this->assertSame(100, $trace[FittingItem::STAGE_NETTO_PF]['effective_input_qty']);
        $this->assertSame(85, $trace[FittingItem::STAGE_NETTO_PF]['effective_good_qty']);
        $this->assertSame(85, $trace[FittingItem::STAGE_KIMIA]['effective_input_qty']);
        $this->assertSame(80, $trace[FittingItem::STAGE_KIMIA]['effective_good_qty']);
        $this->assertSame(80, $trace[FittingItem::STAGE_MARKING]['effective_input_qty']);
        $this->assertSame(77, $trace[FittingItem::STAGE_MARKING]['effective_good_qty']);
        $this->assertSame(77, $trace[FittingItem::STAGE_BUBUT_CNC_PF]['effective_input_qty']);

        // Mass-balance guard: cannot push netto_pf defect to 95 when kimia+marking already have 8 defects (95+8=103 > 100)
        $massBalanceBlocked = false;
        try {
            $this->defectService->adjustDefectQty($nettoExec->id, 80, $this->adminPpicPf, 'Overflow');
        } catch (InvalidArgumentException $e) {
            $massBalanceBlocked = str_contains($e->getMessage(), 'kapasitas');
        }
        $this->assertTrue($massBalanceBlocked, 'Mass-balance guard must prevent total defects across stages exceeding qty_transferred.');
    }

    // =========================================================================
    // TEST 4: QC Breakdown Draft, Verification, Inactive Rejection & Return for Correction
    // =========================================================================

    public function test_04_qc_draft_breakdown_verify_confirm_and_return_for_correction(): void
    {
        $item = $this->createTransferredItem(['qty' => 80]);
        $exec = $this->executionService->scanStage($item->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);
        $this->defectService->recordDefectQty($exec->id, 10, $this->adminPpicPf, 'Temuan 10 pcs');

        // Inactive defect type rejected
        $inactiveRejected = false;
        try {
            $this->defectService->saveQcBreakdownDraft(
                $exec->id,
                [['defect_type_id' => $this->defectInactive->id, 'qty' => 5]],
                $this->adminQcFitting
            );
        } catch (InvalidArgumentException $e) {
            $inactiveRejected = str_contains($e->getMessage(), 'nonaktif');
        }
        $this->assertTrue($inactiveRejected);

        // Draft > defect_qty rejected
        $overDraftRejected = false;
        try {
            $this->defectService->saveQcBreakdownDraft(
                $exec->id,
                [['defect_type_id' => $this->defectPorosity->id, 'qty' => 11]],
                $this->adminQcFitting
            );
        } catch (InvalidArgumentException) {
            $overDraftRejected = true;
        }
        $this->assertTrue($overDraftRejected);

        // Save valid partial draft (6/10) -> stays WAITING_QC
        $drafted = $this->defectService->saveQcBreakdownDraft(
            $exec->id,
            [['defect_type_id' => $this->defectPorosity->id, 'qty' => 6, 'notes' => 'Cek awal']],
            $this->adminQcFitting,
            'Draft sebagian'
        );
        $this->assertSame(FittingStageExecution::STATUS_WAITING_QC, $drafted->status);
        $this->assertSame(6, (int) $drafted->defects->sum('qty'));

        // Verify with mismatched sum (6 != 10) rejected
        $mismatchVerifyRejected = false;
        try {
            $this->defectService->verifyQcBreakdown(
                $exec->id,
                [['defect_type_id' => $this->defectPorosity->id, 'qty' => 6]],
                $this->adminQcFitting
            );
        } catch (InvalidArgumentException) {
            $mismatchVerifyRejected = true;
        }
        $this->assertTrue($mismatchVerifyRejected);

        // QC returns for correction -> WAITING_DEFECT, draft cleared, permanent [QC RETURN] audit log
        $returned = $this->defectService->returnForCorrection($exec->id, $this->adminQcFitting, 'Fisik cacat hanya 8 PCS');
        $this->assertSame(FittingStageExecution::STATUS_WAITING_DEFECT, $returned->status);
        $this->assertCount(0, $returned->defects);
        $this->assertStringContainsString('[QC RETURN]', (string) $returned->notes);
        $this->assertTrue(
            $returned->defectLogs->contains(fn ($l) => str_contains((string) $l->notes, '[QC RETURN] Fisik cacat hanya 8 PCS'))
        );

        // Admin PPIC re-records corrected total (8 PCS) -> WAITING_QC
        $reRecorded = $this->defectService->recordDefectQty($exec->id, 8, $this->adminPpicPf, 'Koreksi sesuai fisik QC');
        $this->assertSame(8, (int) $reRecorded->defect_qty);
        $this->assertSame(72, (int) $reRecorded->good_qty);
        $this->assertSame(FittingStageExecution::STATUS_WAITING_QC, $reRecorded->status);

        // Admin QC verifies exact breakdown (5 Porosity + 3 Retak = 8) -> CONFIRMED
        $confirmed = $this->defectService->verifyQcBreakdown(
            $exec->id,
            [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 5],
                ['defect_type_id' => $this->defectRetak->id, 'qty' => 3],
            ],
            $this->adminQcFitting,
            'Verifikasi selesai'
        );
        $this->assertSame(FittingStageExecution::STATUS_CONFIRMED, $confirmed->status);
        $this->assertSame($this->adminQcFitting->id, (int) $confirmed->qc_verified_by);
        $this->assertNotNull($confirmed->qc_verified_at);
        $this->assertCount(2, $confirmed->defects);
        $this->assertSame(8, (int) $confirmed->defects->sum('qty'));

        // Phase 4.1: Zero-defect goes directly to NO_DEFECT & is REJECTED by QC verify / saveDraft!
        $itemZero = $this->createTransferredItem(['qty' => 30]);
        $execZero = $this->executionService->scanStage($itemZero->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);
        $recordedZero = $this->defectService->recordDefectQty($execZero->id, 0, $this->adminPpicPf);
        $this->assertSame(FittingStageExecution::STATUS_NO_DEFECT, $recordedZero->status);
        $this->assertSame(0, (int) $recordedZero->defect_qty);

        $zeroVerifyBlocked = false;
        try {
            $this->defectService->verifyQcBreakdown($execZero->id, [], $this->adminQcFitting);
        } catch (InvalidArgumentException) {
            $zeroVerifyBlocked = true;
        }
        $this->assertTrue($zeroVerifyBlocked, 'QC verify must reject defect_qty = 0 / NO_DEFECT.');

        $zeroDraftBlocked = false;
        try {
            $this->defectService->saveQcBreakdownDraft($execZero->id, [], $this->adminQcFitting);
        } catch (InvalidArgumentException) {
            $zeroDraftBlocked = true;
        }
        $this->assertTrue($zeroDraftBlocked, 'QC saveDraft must reject defect_qty = 0 / NO_DEFECT.');
    }

    // =========================================================================
    // TEST 5: Auto-Nihil 5 Calendar Days Scheduler, NO_DEFECT, Provenance & Idempotency
    // =========================================================================

    public function test_05_auto_nihil_5_calendar_days_dry_run_execution_and_idempotency(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));

        // Item 1: aged 6 days -> ELIGIBLE for Auto-Nihil
        $itemAged = $this->createTransferredItem(['qty' => 40]);
        $execAged = $this->executionService->scanStage($itemAged->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);
        $execAged->forceFill(['physical_done_at' => Carbon::now()->subDays(6)])->save();

        // Item 2: aged 3 days -> NOT eligible yet
        $itemRecent = $this->createTransferredItem(['qty' => 35]);
        $execRecent = $this->executionService->scanStage($itemRecent->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);
        $execRecent->forceFill(['physical_done_at' => Carbon::now()->subDays(3)])->save();

        // Item 3: aged 7 days but already CONFIRMED -> MUST NOT be touched
        $itemConfirmed = $this->createTransferredItem(['qty' => 25]);
        $execConfirmed = $this->executionService->scanStage($itemConfirmed->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);
        $execConfirmed->forceFill(['physical_done_at' => Carbon::now()->subDays(7)])->save();
        $this->defectService->recordDefectQty($execConfirmed->id, 4, $this->adminPpicPf);
        $this->defectService->verifyQcBreakdown(
            $execConfirmed->id,
            [['defect_type_id' => $this->defectPorosity->id, 'qty' => 4]],
            $this->adminQcFitting
        );

        // 1. Dry-run mutates nothing
        $exitDry = Artisan::call('fitting:timeout-unrecorded-defects', ['--dry-run' => true]);
        $this->assertSame(0, $exitDry);
        $this->assertSame(FittingStageExecution::STATUS_WAITING_DEFECT, $execAged->fresh()->status);
        $this->assertFalse((bool) $execAged->fresh()->is_auto_nihil);

        // 2. Live execution processes ONLY $execAged -> NO_DEFECT (never enters QC queue!)
        $exitLive = Artisan::call('fitting:timeout-unrecorded-defects');
        $this->assertSame(0, $exitLive);

        $execAged->refresh();
        $this->assertSame(FittingStageExecution::STATUS_NO_DEFECT, $execAged->status);
        $this->assertSame(0, (int) $execAged->defect_qty);
        $this->assertSame(40, (int) $execAged->good_qty);
        $this->assertTrue((bool) $execAged->is_auto_nihil);
        $this->assertNotNull($execAged->auto_nihil_at);
        $originalAutoNihilAt = $execAged->auto_nihil_at->toDateTimeString();
        $this->assertNull($execAged->defect_entered_by);

        // Does NOT appear in QC queue
        $this->actingAs($this->adminQcFitting)
            ->get(route('fitting.qc-defects.index', ['tab' => 'waiting']))
            ->assertOk()
            ->assertDontSee($itemAged->traveler_number);

        // System audit log recorded with is_system_action = true, user_id = null
        $autoLogs = FittingStageExecutionDefectLog::where('fitting_stage_execution_id', $execAged->id)->get();
        $this->assertCount(1, $autoLogs);
        $this->assertTrue((bool) $autoLogs->first()->is_system_action);
        $this->assertNull($autoLogs->first()->user_id);

        // Traveler current_stage & status untouched
        $this->assertSame(FittingItem::STAGE_KIMIA, $itemAged->fresh()->current_stage);
        $this->assertSame(FittingItem::STATUS_ACTIVE, $itemAged->fresh()->status);

        // Recent & Confirmed executions completely untouched
        $this->assertSame(FittingStageExecution::STATUS_WAITING_DEFECT, $execRecent->fresh()->status);
        $this->assertFalse((bool) $execRecent->fresh()->is_auto_nihil);
        $this->assertSame(FittingStageExecution::STATUS_CONFIRMED, $execConfirmed->fresh()->status);
        $this->assertSame(4, (int) $execConfirmed->fresh()->defect_qty);

        // 3. Re-running command + calling service directly is 100% idempotent (ZERO duplicate logs!)
        $exitRepeat = Artisan::call('fitting:timeout-unrecorded-defects');
        $this->assertSame(0, $exitRepeat);
        $this->defectService->timeoutToAutoNihil($execAged->id);
        $this->assertSame(1, FittingStageExecutionDefectLog::where('fitting_stage_execution_id', $execAged->id)->count());

        // 4. Provenance check: PPIC later adds +3 defect after Auto-Nihil -> WAITING_QC, is_auto_nihil = false, auto_nihil_at PRESERVED!
        $afterOverride = $this->defectService->adjustDefectQty($execAged->id, 3, $this->adminPpicPf, 'Temuan defect susulan pasca Auto-Nihil');
        $this->assertSame(FittingStageExecution::STATUS_WAITING_QC, $afterOverride->status);
        $this->assertSame(3, (int) $afterOverride->defect_qty);
        $this->assertSame(37, (int) $afterOverride->good_qty);
        $this->assertFalse((bool) $afterOverride->is_auto_nihil);
        $this->assertNotNull($afterOverride->auto_nihil_at, 'auto_nihil_at provenance MUST NEVER be nulled!');
        $this->assertSame($originalAutoNihilAt, $afterOverride->auto_nihil_at->toDateTimeString());

        $logsAfter = FittingStageExecutionDefectLog::where('fitting_stage_execution_id', $execAged->id)->orderBy('id')->get();
        $this->assertCount(2, $logsAfter);
        $this->assertTrue((bool) $logsAfter[0]->is_system_action);
        $this->assertFalse((bool) $logsAfter[1]->is_system_action);
        $this->assertSame(3, (int) $logsAfter[1]->added_qty);

        // Re-running command after override must NOT touch it
        Artisan::call('fitting:timeout-unrecorded-defects');
        $this->assertSame(FittingStageExecution::STATUS_WAITING_QC, $execAged->fresh()->status);
        $this->assertSame(2, FittingStageExecutionDefectLog::where('fitting_stage_execution_id', $execAged->id)->count());
    }

    // =========================================================================
    // TEST 6: Final Quality Lock (Gudang Jadi PF) Locks Stages 1..5, Allows Stage 6, Preserves History
    // =========================================================================

    public function test_06_final_quality_lock_locks_stages_1_to_5_allows_stage_6_and_preserves_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));

        $item = $this->createTransferredItem(['qty' => 70]);
        $execs = $this->scanUpToStage($item, FittingItem::STAGE_QC_PF);

        // Record defect on kimia BEFORE Gudang Jadi PF scan -> allowed
        $this->defectService->recordDefectQty($execs[FittingItem::STAGE_KIMIA]->id, 3, $this->adminPpicPf);
        // Backdate netto_pf to test Auto-Nihil lock guard as well
        $execs[FittingItem::STAGE_NETTO_PF]->forceFill(['physical_done_at' => Carbon::now()->subDays(10)])->save();

        // Record & confirm defect on marking BEFORE Gudang Jadi PF scan to test QC Reopen Lock!
        $this->defectService->recordDefectQty($execs[FittingItem::STAGE_MARKING]->id, 1, $this->adminPpicPf);
        $this->defectService->verifyQcBreakdown(
            $execs[FittingItem::STAGE_MARKING]->id,
            [['defect_type_id' => $this->defectPorosity->id, 'qty' => 1]],
            $this->adminQcFitting
        );

        // Scan final stage gudang_jadi_pf -> triggers Final Quality Lock for stages 1..5!
        $gdExec = $this->executionService->scanStage($item->traveler_number, FittingItem::STAGE_GUDANG_JADI_PF, $this->spvGdPf);
        $this->assertSame(FittingItem::STAGE_COMPLETED, $item->fresh()->current_stage);
        $this->assertSame(66, (int) $gdExec->input_qty);

        // Stages 1..5 must ALL report locked and block every mutation
        $lockedStages = [
            FittingItem::STAGE_NETTO_PF,
            FittingItem::STAGE_KIMIA,
            FittingItem::STAGE_MARKING,
            FittingItem::STAGE_BUBUT_CNC_PF,
            FittingItem::STAGE_QC_PF,
        ];
        foreach ($lockedStages as $st) {
            $this->assertTrue($this->defectService->isStageQualityLocked($execs[$st], $item->fresh('stageExecutions')), "Stage {$st} must be locked.");
        }
        $this->assertFalse($this->defectService->isStageQualityLocked($gdExec, $item->fresh('stageExecutions')), 'Stage 6 (gudang_jadi_pf) must NOT be locked.');

        // 1. Block recordDefectQty on locked stage (netto_pf)
        $blockedRecord = false;
        try {
            $this->defectService->recordDefectQty($execs[FittingItem::STAGE_NETTO_PF]->id, 2, $this->adminPpicPf);
        } catch (InvalidArgumentException $e) {
            $blockedRecord = str_contains($e->getMessage(), 'Final Quality Lock');
        }
        $this->assertTrue($blockedRecord);

        // 2. Block adjustDefectQty on locked stage (kimia)
        $blockedAdjust = false;
        try {
            $this->defectService->adjustDefectQty($execs[FittingItem::STAGE_KIMIA]->id, 1, $this->adminPpicPf, 'Coba ubah');
        } catch (InvalidArgumentException $e) {
            $blockedAdjust = str_contains($e->getMessage(), 'Final Quality Lock');
        }
        $this->assertTrue($blockedAdjust);

        // 3. Block QC saveDraft, verify & return on locked stage (kimia)
        $blockedDraft = $blockedVerify = $blockedReturn = false;
        try {
            $this->defectService->saveQcBreakdownDraft($execs[FittingItem::STAGE_KIMIA]->id, [['defect_type_id' => $this->defectPorosity->id, 'qty' => 3]], $this->adminQcFitting);
        } catch (InvalidArgumentException $e) {
            $blockedDraft = str_contains($e->getMessage(), 'Final Quality Lock');
        }
        try {
            $this->defectService->verifyQcBreakdown($execs[FittingItem::STAGE_KIMIA]->id, [['defect_type_id' => $this->defectPorosity->id, 'qty' => 3]], $this->adminQcFitting);
        } catch (InvalidArgumentException $e) {
            $blockedVerify = str_contains($e->getMessage(), 'Final Quality Lock');
        }
        try {
            $this->defectService->returnForCorrection($execs[FittingItem::STAGE_KIMIA]->id, $this->adminQcFitting, 'Return locked');
        } catch (InvalidArgumentException $e) {
            $blockedReturn = str_contains($e->getMessage(), 'Final Quality Lock');
        }
        $blockedReopen = false;
        try {
            $this->defectService->reopenConfirmedForCorrection($execs[FittingItem::STAGE_MARKING]->id, $this->adminQcFitting, 'Reopen locked');
        } catch (InvalidArgumentException $e) {
            $blockedReopen = str_contains($e->getMessage(), 'Final Quality Lock');
        }
        $this->assertTrue($blockedDraft && $blockedVerify && $blockedReturn && $blockedReopen);

        // 4. Block Auto-Nihil on locked stage (netto_pf aged 10 days)
        $blockedAutoNihil = false;
        try {
            $this->defectService->timeoutToAutoNihil($execs[FittingItem::STAGE_NETTO_PF]->id);
        } catch (InvalidArgumentException $e) {
            $blockedAutoNihil = str_contains($e->getMessage(), 'Final Quality Lock');
        }
        $this->assertTrue($blockedAutoNihil);

        // CLI Auto-Nihil command skips locked stage cleanly without modifying it
        $exit = Artisan::call('fitting:timeout-unrecorded-defects');
        $this->assertSame(0, $exit);
        $this->assertSame(FittingStageExecution::STATUS_WAITING_DEFECT, $execs[FittingItem::STAGE_NETTO_PF]->fresh()->status);

        // 5. Stage 6 (gudang_jadi_pf) remains fully operable with audit trail (including QC Reopen!)
        $gdRecorded = $this->defectService->recordDefectQty($gdExec->id, 2, $this->adminPpicPf, 'Temuan gudang jadi');
        $this->assertSame(2, (int) $gdRecorded->defect_qty);
        $this->assertSame(64, (int) $gdRecorded->good_qty);

        $gdVerified = $this->defectService->verifyQcBreakdown(
            $gdExec->id,
            [['defect_type_id' => $this->defectRetak->id, 'qty' => 2]],
            $this->adminQcFitting,
            'Verifikasi gudang jadi'
        );
        $this->assertSame(FittingStageExecution::STATUS_CONFIRMED, $gdVerified->status);
        $this->assertSame(64, $this->quantityResolver->resolveEffectiveGoodQty($item->fresh('stageExecutions'), FittingItem::STAGE_GUDANG_JADI_PF));

        // Stage 6 QC Reopen IS allowed (not locked)
        $gdReopened = $this->defectService->reopenConfirmedForCorrection($gdExec->id, $this->adminQcFitting, 'Revisi tahap gudang jadi');
        $this->assertSame(FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION, $gdReopened->status);

        // 6. All 6 Phase 3 scan history records remain completely intact & readable
        $this->assertSame(6, FittingStageExecution::where('fitting_item_id', $item->id)->count());
    }

    // =========================================================================
    // TEST 7: HTTP Flow, RBAC Matrix & PreventQcMutation Surgical Isolation
    // =========================================================================

    public function test_07_http_endpoints_rbac_and_prevent_qc_mutation_isolation(): void
    {
        $item = $this->createTransferredItem(['qty' => 50]);
        $exec = $this->executionService->scanStage($item->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);

        // 1. Authorized GET views & sidebar visibility
        // Initial view without stage requires explicit stage selection
        $respPpicInitial = $this->actingAs($this->adminPpicPf)->get(route('fitting.defects.index'));
        $respPpicInitial->assertOk();
        $respPpicInitial->assertSee('Pilih Tahap Produksi Terlebih Dahulu');
        $respPpicInitial->assertDontSee($item->traveler_number);
        $respPpicInitial->assertDontSee('Semua Tahap');

        // GET view with explicit stage displays travelers
        $respPpic = $this->actingAs($this->adminPpicPf)->get(route('fitting.defects.index', ['stage' => FittingItem::STAGE_NETTO_PF]));
        $respPpic->assertOk();
        $respPpic->assertSee($item->traveler_number);
        $respPpic->assertSee('Pencatatan Kerusakan Fitting');
        $respPpic->assertDontSee('Semua Tahap');
        $respPpic->assertDontSee('Verifikasi Defect QC Fitting');

        $respQc = $this->actingAs($this->adminQcFitting)->get(route('fitting.qc-defects.index'));
        $respQc->assertOk();
        $respQc->assertSee('Verifikasi Defect QC Fitting');
        $respQc->assertDontSee('Pencatatan Kerusakan Fitting');

        // 2. Unauthorized users strictly get 403 on Defect Recording endpoints
        foreach ([$this->adminQcFitting, $this->ppicFitting, $this->spvNettoPf, $this->spvGdPf, $this->auditor, $this->sales] as $unauth) {
            $this->actingAs($unauth)->get(route('fitting.defects.index'))->assertForbidden();
            $this->actingAs($unauth)->postJson(route('fitting.defects.record', $exec), ['defect_qty' => 1])->assertForbidden();
            $this->actingAs($unauth)->postJson(route('fitting.defects.adjust', $exec), ['adjustment_qty' => 1])->assertForbidden();
        }

        // 3. Unauthorized users strictly get 403 on QC Verification endpoints
        foreach ([$this->adminPpicPf, $this->ppicFitting, $this->spvNettoPf, $this->spvGdPf, $this->auditor, $this->sales] as $unauth) {
            $this->actingAs($unauth)->get(route('fitting.qc-defects.index'))->assertForbidden();
            $this->actingAs($unauth)->postJson(route('fitting.qc-defects.save-draft', $exec), [
                'defects' => [['defect_type_id' => $this->defectPorosity->id, 'qty' => 1]],
            ])->assertForbidden();
            $this->actingAs($unauth)->postJson(route('fitting.qc-defects.verify', $exec), ['defects' => []])->assertForbidden();
            $this->actingAs($unauth)->postJson(route('fitting.qc-defects.return', $exec), ['reason' => 'No'])->assertForbidden();
            $this->actingAs($unauth)->postJson(route('fitting.qc-defects.reopen', $exec), ['reason' => 'No'])->assertForbidden();
        }

        // 4. Service-layer RBAC check
        $svcDenied = false;
        try {
            $this->defectService->recordDefectQty($exec->id, 1, $this->ppicFitting);
        } catch (AuthorizationException) {
            $svcDenied = true;
        }
        $this->assertTrue($svcDenied);

        // 5. Full HTTP happy path: Admin PPIC records -> Admin QC saves draft -> returns -> Admin PPIC records -> Admin QC verifies
        $this->actingAs($this->adminPpicPf)
            ->postJson(route('fitting.defects.record', $exec), [
                'defect_qty' => 6,
                'notes' => 'HTTP record',
                'idempotency_key' => 'http-rec-1',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', FittingStageExecution::STATUS_WAITING_QC)
            ->assertJsonPath('data.defect_qty', 6)
            ->assertJsonPath('data.effective_good_qty', 44);

        // Admin QC Fitting passes PreventQcMutation ONLY for these 3 Fitting QC routes!
        $this->actingAs($this->adminQcFitting)
            ->postJson(route('fitting.qc-defects.save-draft', $exec), [
                'defects' => [['defect_type_id' => $this->defectPorosity->id, 'qty' => 4]],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.draft_qty', 4);

        $this->actingAs($this->adminQcFitting)
            ->postJson(route('fitting.qc-defects.return', $exec), [
                'reason' => 'Jumlah fisik sebenarnya 5 PCS',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', FittingStageExecution::STATUS_WAITING_DEFECT);

        $this->actingAs($this->adminPpicPf)
            ->postJson(route('fitting.defects.record', $exec), [
                'defect_qty' => 5,
                'notes' => 'Koreksi jadi 5 PCS',
            ])
            ->assertOk()
            ->assertJsonPath('data.defect_qty', 5);

        $this->actingAs($this->adminQcFitting)
            ->postJson(route('fitting.qc-defects.verify', $exec), [
                'defects' => [
                    ['defect_type_id' => $this->defectPorosity->id, 'qty' => 3],
                    ['defect_type_id' => $this->defectRetak->id, 'qty' => 2],
                ],
                'notes' => 'Terverifikasi HTTP',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', FittingStageExecution::STATUS_CONFIRMED);

        // Phase 4.1: Direct HTTP adjust on CONFIRMED -> 422!
        $this->actingAs($this->adminPpicPf)
            ->postJson(route('fitting.defects.adjust', $exec), [
                'adjustment_qty' => 1,
                'notes' => 'Coba bypass CONFIRMED',
            ])
            ->assertStatus(422);

        // Admin QC passes PreventQcMutation for the 4th allowlisted route (fitting.qc-defects.reopen)!
        $this->actingAs($this->adminQcFitting)
            ->postJson(route('fitting.qc-defects.reopen', $exec), [
                'reason' => 'Buka ulang untuk ubah kategori tanpa ubah qty',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION)
            ->assertJsonPath('data.defect_qty', 5);

        // Admin PPIC confirms unchanged (adjustment_qty = 0) via HTTP -> WAITING_QC!
        $this->actingAs($this->adminPpicPf)
            ->postJson(route('fitting.defects.adjust', $exec), [
                'adjustment_qty' => 0,
                'notes' => 'Qty tetap 5 PCS sesuai fisik',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', FittingStageExecution::STATUS_WAITING_QC)
            ->assertJsonPath('data.defect_qty', 5);

        // Admin QC re-verifies 5 PCS -> CONFIRMED!
        $this->actingAs($this->adminQcFitting)
            ->postJson(route('fitting.qc-defects.verify', $exec), [
                'defects' => [
                    ['defect_type_id' => $this->defectPorosity->id, 'qty' => 4],
                    ['defect_type_id' => $this->defectRetak->id, 'qty' => 1],
                ],
                'notes' => 'Re-verifikasi HTTP selesai',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', FittingStageExecution::STATUS_CONFIRMED);

        // 6. PreventQcMutation MUST STILL block admin_qc_fitting on all non-QC mutations across ALL modules!
        $this->actingAs($this->adminQcFitting)
            ->postJson(route('fitting.transfers.store'), ['kitir_ids' => [1]])
            ->assertForbidden();
        $this->actingAs($this->adminQcFitting)
            ->postJson(route('fitting.scan.execute', ['stage' => 'netto_pf']), ['traveler_number' => $item->traveler_number])
            ->assertForbidden();
        $this->actingAs($this->adminQcFitting)
            ->postJson(route('fitting.kanban.reorder', ['stage' => 'netto_pf']), ['line_bucket' => '1', 'from_pos' => 1, 'to_pos' => 1])
            ->assertForbidden();
        $this->actingAs($this->adminQcFitting)
            ->postJson(route('settings.defect-types.store'), ['department' => 'netto_pf', 'name' => 'Hack'])
            ->assertForbidden();
        $this->actingAs($this->adminQcFitting)
            ->postJson(route('plan.store'), ['code' => 'HACK-01'])
            ->assertForbidden();
        $this->actingAs($this->adminQcFitting)
            ->postJson(route('sand-casting.casting-orders.store'), [])
            ->assertForbidden();
    }

    // =========================================================================
    // TEST 8: Compact UI View Rendering & HTTP Defect Reduction Flow (5 -> 3 -> 0)
    // =========================================================================

    public function test_08_defect_reduction_and_compact_ui_rendering(): void
    {
        $item = $this->createTransferredItem(['qty' => 50]);
        $exec = $this->executionService->scanStage($item->traveler_number, FittingItem::STAGE_NETTO_PF, $this->spvNettoPf);

        // 1. Verify Initial View without stage: Shows stage selection, no travelers, no "Semua Tahap"
        $initialRes = $this->actingAs($this->adminPpicPf)->get(route('fitting.defects.index'));
        $initialRes->assertOk();
        $initialRes->assertSee('Pilih Tahap Produksi Terlebih Dahulu');
        $initialRes->assertSee('Pilih tahap produksi terlebih dahulu untuk menampilkan daftar PFKTR. Pencatatan defect wajib dilakukan dalam konteks tahap yang eksplisit.');
        $initialRes->assertDontSee('Semua Tahap');
        $initialRes->assertDontSee($item->traveler_number);

        // Verify all 6 stages are presented with links
        foreach (FittingItem::STAGES as $st) {
            $label = \App\Services\Fitting\FittingStageAuthorizationService::STAGE_LABELS[$st];
            $initialRes->assertSee($label);
            $initialRes->assertSee("stage={$st}");
        }

        // 2. Verify invalid stage does NOT fallback to all data; stays on stage selector with error message
        $invalidRes = $this->actingAs($this->adminPpicPf)->get(route('fitting.defects.index', ['stage' => 'invalid_stage_xyz']));
        $invalidRes->assertOk();
        $invalidRes->assertSee('Pilih Tahap Produksi Terlebih Dahulu');
        $invalidRes->assertSee("Tahap operasional Fitting 'invalid_stage_xyz' tidak valid");
        $invalidRes->assertDontSee($item->traveler_number);

        $allRes = $this->actingAs($this->adminPpicPf)->get(route('fitting.defects.index', ['stage' => 'all']));
        $allRes->assertOk();
        $allRes->assertSee('Pilih Tahap Produksi Terlebih Dahulu');
        $allRes->assertDontSee($item->traveler_number);

        // 3. Verify Selected Stage view: Compact 5 KPI cards, table, modals, preserved parameters
        $stageRes = $this->actingAs($this->adminPpicPf)->get(route('fitting.defects.index', ['stage' => FittingItem::STAGE_NETTO_PF]));
        $stageRes->assertOk();
        $stageRes->assertSee('grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2', false);
        $stageRes->assertSee('Belum Dicatat');
        $stageRes->assertSee('Nihil (0 PCS)');
        $stageRes->assertSee('Menunggu QC');
        $stageRes->assertSee('Reopen QC');
        $stageRes->assertSee('Terverifikasi QC');
        $stageRes->assertSee($item->traveler_number);
        $stageRes->assertDontSee('Semua Tahap');
        $stageRes->assertSee('id="recordModal"', false);
        $stageRes->assertSee('id="adjustModal"', false);
        $stageRes->assertSee('id="logsModal"', false);
        $stageRes->assertSee('name="stage" value="netto_pf"', false);

        // 4. Verify travelers strictly isolated by stage (e.g. stage=kimia does not show netto_pf traveler)
        $diffStageRes = $this->actingAs($this->adminPpicPf)->get(route('fitting.defects.index', ['stage' => FittingItem::STAGE_KIMIA]));
        $diffStageRes->assertOk();
        $diffStageRes->assertDontSee($item->traveler_number);

        // 5. Verify search carries stage parameter
        $searchRes = $this->actingAs($this->adminPpicPf)->get(route('fitting.defects.index', [
            'stage' => FittingItem::STAGE_NETTO_PF,
            'search' => $item->traveler_number,
        ]));
        $searchRes->assertOk();
        $searchRes->assertSee($item->traveler_number);
        $searchRes->assertSee('name="stage" value="netto_pf"', false);

        // 6. HTTP Defect Reduction Flow (5 PCS -> 3 PCS -> 0 PCS)
        // Step A: Initial defect = 5 PCS
        $recordRes = $this->actingAs($this->adminPpicPf)
            ->postJson(route('fitting.defects.record', $exec), [
                'defect_qty' => 5,
                'notes' => 'Catatan awal 5 PCS defect',
            ]);
        $recordRes->assertOk();
        $recordRes->assertJsonPath('data.defect_qty', 5);
        $recordRes->assertJsonPath('data.good_qty', 45);
        $recordRes->assertJsonPath('data.status', FittingStageExecution::STATUS_WAITING_QC);

        // Step B: Defect reduction without note is rejected (HTTP 422)
        $noNoteRes = $this->actingAs($this->adminPpicPf)
            ->postJson(route('fitting.defects.adjust', $exec), [
                'adjustment_qty' => -2,
                'notes' => '',
            ]);
        $noNoteRes->assertStatus(422);

        // Step C: Defect reduction with note (5 -> 3, delta -2)
        $reduceRes = $this->actingAs($this->adminPpicPf)
            ->postJson(route('fitting.defects.adjust', $exec), [
                'adjustment_qty' => -2,
                'notes' => 'Koreksi fisik lapangan berkurang 2 PCS',
            ]);
        $reduceRes->assertOk();
        $reduceRes->assertJsonPath('data.defect_qty', 3);
        $reduceRes->assertJsonPath('data.good_qty', 47);
        $reduceRes->assertJsonPath('data.status', FittingStageExecution::STATUS_WAITING_QC);

        // Verify audit log for delta -2
        $latestLog = FittingStageExecutionDefectLog::where('fitting_stage_execution_id', $exec->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($latestLog);
        $this->assertSame(-2, (int) $latestLog->added_qty);
        $this->assertSame(5, (int) $latestLog->previous_total);
        $this->assertSame(3, (int) $latestLog->new_total);
        $this->assertSame($this->adminPpicPf->id, (int) $latestLog->user_id);
        $this->assertSame('Koreksi fisik lapangan berkurang 2 PCS', $latestLog->notes);

        // Step D: Defect reduction to 0 (3 -> 0, delta -3) -> transitions to NO_DEFECT
        $reduceZeroRes = $this->actingAs($this->adminPpicPf)
            ->postJson(route('fitting.defects.adjust', $exec), [
                'adjustment_qty' => -3,
                'notes' => 'Setelah dicek ulang seluruhnya dapat digunakan',
            ]);
        $reduceZeroRes->assertOk();
        $reduceZeroRes->assertJsonPath('data.defect_qty', 0);
        $reduceZeroRes->assertJsonPath('data.good_qty', 50);
        $reduceZeroRes->assertJsonPath('data.status', FittingStageExecution::STATUS_NO_DEFECT);

        // Step E: Negative reduction below 0 rejected (0 + -1 = -1 < 0)
        $belowZeroRes = $this->actingAs($this->adminPpicPf)
            ->postJson(route('fitting.defects.adjust', $exec), [
                'adjustment_qty' => -1,
                'notes' => 'Coba negatif di bawah 0',
            ]);
        $belowZeroRes->assertStatus(422);
    }
}
