<?php

namespace Tests\Feature\Fitting;

use App\Models\DefectType;
use App\Models\FittingItem;
use App\Models\FittingStageExecution;
use App\Models\LostWaxCastingResult;
use App\Models\LostWaxCastingResultTree;
use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxTree;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\Fitting\FittingDefectService;
use App\Services\Fitting\FittingStageAuthorizationService;
use App\Services\Fitting\FittingStageExecutionService;
use App\Services\Fitting\FittingTransferService;
use App\Services\LostWax\LostWaxKitirResolverService;
use Carbon\Carbon;
use Database\Seeders\FittingDefectTypesSeeder;
use Database\Seeders\FittingSpvUserSeeder;
use Database\Seeders\QcFittingUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FittingQcDefectVerificationModuleTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 8000;

    private User $systemAdmin;

    private User $adminQcFitting;

    private User $ppicFitting;

    private User $unauthorizedUser;

    private FittingDefectService $defectService;

    private FittingStageExecutionService $executionService;

    private FittingTransferService $transferService;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('AUDITOR_INITIAL_PASSWORD=AudPass123!');
        $_ENV['AUDITOR_INITIAL_PASSWORD'] = 'AudPass123!';
        putenv('SALES_INITIAL_PASSWORD=SalesPass123!');
        $_ENV['SALES_INITIAL_PASSWORD'] = 'SalesPass123!';
        putenv('FITTING_SPV_INITIAL_PASSWORD=FitPass123!');
        $_ENV['FITTING_SPV_INITIAL_PASSWORD'] = 'FitPass123!';

        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'])->syncPermissions([$permPlanning, $permExecution]);
        Role::firstOrCreate(['name' => 'ppic', 'guard_name' => 'web'])->syncPermissions([$permPlanning, $permExecution]);

        // Seed Universal Fitting Defect Types & QC User
        (new FittingDefectTypesSeeder)->run();
        (new QcFittingUserSeeder)->run();
        (new FittingSpvUserSeeder)->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->systemAdmin = User::create([
            'name' => 'MR System Admin',
            'email' => 'mr@peroniks.com',
            'password' => Hash::make('password'),
        ]);
        $this->systemAdmin->assignRole('admin');

        $this->adminQcFitting = User::where('email', 'adminqcfitting@peroniks.com')->firstOrFail();

        $this->ppicFitting = User::create([
            'name' => 'PPIC Fitting',
            'email' => 'ppicfitting@peroniks.com',
            'password' => Hash::make('password'),
            'product_scope' => 'FITTING_STAINLESS',
        ]);
        $this->ppicFitting->assignRole('ppic');
        $this->ppicFitting->givePermissionTo([
            FittingStageAuthorizationService::PERMISSION_RECORD_DEFECT,
            FittingStageAuthorizationService::PERMISSION_ALL_KANBAN,
            FittingStageAuthorizationService::PERMISSION_VISIBILITY,
            FittingStageAuthorizationService::PERMISSION_TRANSFER,
            FittingStageAuthorizationService::PERMISSION_MANAGE_KANBAN,
        ]);

        $this->unauthorizedUser = User::create([
            'name' => 'Guest Operator',
            'email' => 'guest@peroniks.com',
            'password' => Hash::make('password'),
        ]);

        // Seed Sand Casting Defect Types to verify cross-domain rejection
        DefectType::create(['department' => 'cor', 'name' => 'RUSAK COR SC', 'is_active' => true]);
        DefectType::create(['department' => 'netto', 'name' => 'KERIPUT SC', 'is_active' => true]);
        DefectType::create(['department' => 'bubut_cnc', 'name' => 'RUSAK DRAT SC', 'is_active' => true]);

        $this->defectService = app(FittingDefectService::class);
        $this->executionService = app(FittingStageExecutionService::class);
        $this->transferService = app(FittingTransferService::class);
    }

    private function createReadyFittingItem(int $qty = 50): FittingItem
    {
        $n = self::$seq++;
        $code = "PF4-QC-{$n}";
        $castDate = Carbon::today()->format('Y-m-d');
        $transferDate = Carbon::today()->format('Y-m-d');

        $plan = ProductionPlan::create([
            'code' => $code,
            'title' => 'Rencana Fitting QC Test',
            'item_code' => "ITM-QC-{$n}",
            'item_name' => "TEE 2 INCH 304 QC {$n}",
            'aisi' => '304',
            'size' => '2"',
            'weight' => 0.50,
            'po_number' => "PO-QC-{$n}",
            'po_quantity' => 500,
            'qty_planned' => 500,
            'qty_remaining' => 500,
            'line_number' => 1,
            'customer' => "PT Customer QC {$n}",
            'product_scope' => 'FITTING_STAINLESS',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'status' => 'active',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => "LW-PO-QC-{$n}",
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
            'heat_number' => "H-QC-{$n}",
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

        $kitir = app(LostWaxKitirResolverService::class)->issueOrGetKitir($castingResult, $code, $this->ppicFitting);
        $transfer = $this->transferService->transferKitirs([$kitir->id], $this->ppicFitting, null, null, $transferDate, [$kitir->id => 1]);

        return $transfer->items->first()->fresh();
    }

    private function scanStage(FittingItem $item, string $stage = FittingItem::STAGE_NETTO_PF, ?User $user = null): FittingStageExecution
    {
        return $this->executionService->scanStage(
            $item->traveler_number,
            $stage,
            $user ?? $this->systemAdmin
        );
    }

    // ==========================================
    // A. Master Defect Tests
    // ==========================================

    public function test_01_exactly_14_universal_master_defects_exist_for_fitting(): void
    {
        $fittingDefects = DefectType::where('department', FittingDefectTypesSeeder::CANONICAL_DEPARTMENT)->get();

        $this->assertCount(14, $fittingDefects);
        $expectedNames = FittingDefectTypesSeeder::UNIVERSAL_DEFECT_NAMES;
        sort($expectedNames);

        $actualNames = $fittingDefects->pluck('name')->sort()->values()->all();
        $this->assertSame($expectedNames, $actualNames);

        foreach ($fittingDefects as $dt) {
            $this->assertTrue((bool) $dt->is_active);
        }
    }

    public function test_02_seeder_is_idempotent_and_does_not_duplicate_records(): void
    {
        $initialCount = DefectType::where('department', FittingDefectTypesSeeder::CANONICAL_DEPARTMENT)->count();
        $this->assertSame(14, $initialCount);

        // Run seeder second time
        (new FittingDefectTypesSeeder)->run();

        $afterCount = DefectType::where('department', FittingDefectTypesSeeder::CANONICAL_DEPARTMENT)->count();
        $this->assertSame(14, $afterCount);
    }

    public function test_03_all_six_stages_receive_all_14_choices_in_controller(): void
    {
        $this->actingAs($this->adminQcFitting);

        $response = $this->get(route('fitting.qc-defects.index'));
        $response->assertOk();

        $map = $response->viewData('defectTypesMap');
        $this->assertIsArray($map);

        foreach (FittingItem::STAGES as $stageKey) {
            $this->assertArrayHasKey($stageKey, $map);
            $this->assertCount(14, $map[$stageKey]);
            $names = array_column($map[$stageKey], 'name');
            $this->assertContains('KEROPOS', $names);
            $this->assertContains('BOCOR TES', $names);
            $this->assertContains('RUSAK SETTING', $names);
        }
    }

    public function test_04_sand_casting_master_defects_remain_unaltered(): void
    {
        $scCount = DefectType::whereIn('department', ['cor', 'netto', 'bubut_cnc'])->count();
        $this->assertSame(3, $scCount);

        (new FittingDefectTypesSeeder)->run();

        $scCountAfter = DefectType::whereIn('department', ['cor', 'netto', 'bubut_cnc'])->count();
        $this->assertSame(3, $scCountAfter);
    }

    public function test_05_historical_defects_display_even_if_master_defect_is_subsequently_deactivated(): void
    {
        $item = $this->createReadyFittingItem(40);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF);

        $this->defectService->recordDefectQty($exec, 5, $this->ppicFitting);
        $exec->refresh();

        $keropos = DefectType::where('department', 'fitting')->where('name', 'KEROPOS')->firstOrFail();

        // Save draft with KEROPOS
        $this->defectService->saveQcBreakdownDraft(
            executionOrId: $exec,
            defects: [['defect_type_id' => $keropos->id, 'qty' => 5]],
            user: $this->adminQcFitting
        );

        // Deactivate KEROPOS
        $keropos->update(['is_active' => false]);

        $this->actingAs($this->adminQcFitting);
        $response = $this->get(route('fitting.qc-defects.index'));
        $response->assertOk();

        // The historical record in table still resolves relation and shows KEROPOS
        $response->assertSee('KEROPOS');
        $response->assertSee('5 PCS');
    }

    // ==========================================
    // B. UI & JavaScript Tests
    // ==========================================

    public function test_06_verification_button_renders_without_quote_collision_syntax_errors(): void
    {
        $item = $this->createReadyFittingItem(30);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);
        $this->defectService->recordDefectQty($exec, 3, $this->ppicFitting);

        $this->actingAs($this->adminQcFitting);
        $response = $this->get(route('fitting.qc-defects.index'));
        $response->assertOk();

        $html = $response->getContent();

        // Must not contain broken onclick='openVerifyModal(...'
        $this->assertStringNotContainsString("onclick='openVerifyModal(", $html);
        $this->assertStringNotContainsString("onclick='openQcLogsModal(", $html);

        // Must contain data-attributes on button
        $this->assertStringContainsString('btn-open-verify-modal', $html);
        $this->assertStringContainsString("data-exec-id=\"{$exec->id}\"", $html);
        $this->assertStringContainsString('data-defect-qty="3"', $html);
        $this->assertStringContainsString('data-stage-key="netto_pf"', $html);
    }

    public function test_07_qc_logs_button_renders_safely_with_data_attributes(): void
    {
        $item = $this->createReadyFittingItem(25);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);
        $this->defectService->recordDefectQty($exec, 4, $this->ppicFitting);

        $this->actingAs($this->adminQcFitting);
        $response = $this->get(route('fitting.qc-defects.index'));
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('btn-open-logs-modal', $html);
        $this->assertStringContainsString('data-logs=', $html);
    }

    public function test_08_js_defect_types_map_is_valid_json_with_14_entries_per_stage(): void
    {
        $this->actingAs($this->adminQcFitting);
        $response = $this->get(route('fitting.qc-defects.index'));

        preg_match('/const DEFECT_TYPES_MAP = (.*?);/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'DEFECT_TYPES_MAP variable not found in rendered HTML.');

        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded);

        foreach (FittingItem::STAGES as $stage) {
            $this->assertCount(14, $decoded[$stage]);
        }
    }

    public function test_09_existing_draft_breakdown_is_passed_to_view_and_rendered_correctly(): void
    {
        $item = $this->createReadyFittingItem(35);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);
        $this->defectService->recordDefectQty($exec, 7, $this->ppicFitting);

        $keropos = DefectType::where('department', 'fitting')->where('name', 'KEROPOS')->firstOrFail();
        $retak = DefectType::where('department', 'fitting')->where('name', 'PUTUS')->firstOrFail();

        $this->defectService->saveQcBreakdownDraft(
            executionOrId: $exec,
            defects: [
                ['defect_type_id' => $keropos->id, 'qty' => 4],
                ['defect_type_id' => $retak->id, 'qty' => 3],
            ],
            user: $this->adminQcFitting
        );

        $this->actingAs($this->adminQcFitting);
        $response = $this->get(route('fitting.qc-defects.index'));
        $response->assertOk();

        $response->assertSee('KEROPOS:');
        $response->assertSee('4 PCS');
        $response->assertSee('PUTUS:');
        $response->assertSee('3 PCS');
        $response->assertSee('Draft: 7 / 7 PCS');
    }

    // ==========================================
    // C. Permission & RBAC Tests
    // ==========================================

    public function test_10_role_admin_qc_fitting_has_verify_fitting_qc_permission(): void
    {
        $role = Role::findByName('admin_qc_fitting', 'web');
        $this->assertTrue($role->hasPermissionTo(FittingStageAuthorizationService::PERMISSION_VERIFY_QC));
        $this->assertTrue($this->adminQcFitting->hasPermissionTo(FittingStageAuthorizationService::PERMISSION_VERIFY_QC));
    }

    public function test_11_authorized_qc_account_can_access_qc_defects_index(): void
    {
        $this->actingAs($this->adminQcFitting);
        $response = $this->get(route('fitting.qc-defects.index'));
        $response->assertOk();
    }

    public function test_12_unauthorized_user_receives_403_forbidden(): void
    {
        $this->actingAs($this->unauthorizedUser);
        $response = $this->get(route('fitting.qc-defects.index'));
        $response->assertForbidden();
    }

    // ==========================================
    // D. Queue & Zero-Defect Filter Tests
    // ==========================================

    public function test_13_manual_defect_0_transitions_to_no_defect_and_is_excluded_from_qc_queue(): void
    {
        $item = $this->createReadyFittingItem(20);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);

        $this->defectService->recordDefectQty($exec, 0, $this->ppicFitting);
        $exec->refresh();

        $this->assertSame(FittingStageExecution::STATUS_NO_DEFECT, $exec->status);
        $this->assertSame(0, (int) $exec->defect_qty);

        $this->actingAs($this->adminQcFitting);
        $response = $this->get(route('fitting.qc-defects.index'));
        $response->assertOk();

        $summary = $response->viewData('summary');
        $this->assertSame(0, $summary['waiting_total']);
        $response->assertDontSee($item->traveler_number);
    }

    public function test_14_auto_nihil_transitions_to_no_defect_and_is_excluded_from_qc_queue(): void
    {
        $item = $this->createReadyFittingItem(20);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);

        // Simulate 6 days past physical done
        $exec->update(['physical_done_at' => Carbon::now()->subDays(6)]);

        $this->defectService->timeoutToAutoNihil($exec, $this->systemAdmin->id);
        $exec->refresh();

        $this->assertSame(FittingStageExecution::STATUS_NO_DEFECT, $exec->status);
        $this->assertTrue((bool) $exec->is_auto_nihil);
        $this->assertSame(0, (int) $exec->defect_qty);

        $this->actingAs($this->adminQcFitting);
        $response = $this->get(route('fitting.qc-defects.index'));
        $response->assertOk();

        $summary = $response->viewData('summary');
        $this->assertSame(0, $summary['waiting_total']);
        $response->assertDontSee($item->traveler_number);
    }

    public function test_15_defect_greater_than_zero_appears_in_qc_queue(): void
    {
        $item = $this->createReadyFittingItem(20);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);

        $this->defectService->recordDefectQty($exec, 2, $this->ppicFitting);
        $exec->refresh();

        $this->assertSame(FittingStageExecution::STATUS_WAITING_QC, $exec->status);

        $this->actingAs($this->adminQcFitting);
        $response = $this->get(route('fitting.qc-defects.index'));
        $response->assertOk();

        $summary = $response->viewData('summary');
        $this->assertSame(1, $summary['waiting_total']);
        $response->assertSee($item->traveler_number);
    }

    public function test_16_confirmed_executions_do_not_appear_in_waiting_mode(): void
    {
        $item = $this->createReadyFittingItem(20);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);
        $this->defectService->recordDefectQty($exec, 2, $this->ppicFitting);

        $keropos = DefectType::where('department', 'fitting')->where('name', 'KEROPOS')->firstOrFail();

        $this->defectService->verifyQcBreakdown(
            executionOrId: $exec,
            defects: [['defect_type_id' => $keropos->id, 'qty' => 2]],
            user: $this->adminQcFitting
        );
        $exec->refresh();
        $this->assertSame(FittingStageExecution::STATUS_CONFIRMED, $exec->status);

        $this->actingAs($this->adminQcFitting);
        $responseWaiting = $this->get(route('fitting.qc-defects.index', ['mode' => 'waiting']));
        $responseWaiting->assertOk();
        $responseWaiting->assertDontSee($item->traveler_number);

        $responseConfirmed = $this->get(route('fitting.qc-defects.index', ['mode' => 'confirmed']));
        $responseConfirmed->assertOk();
        $responseConfirmed->assertSee($item->traveler_number);
    }

    public function test_17_summary_cards_match_exact_query_counts(): void
    {
        $item1 = $this->createReadyFittingItem(20);
        $exec1 = $this->scanStage($item1, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);
        $this->defectService->recordDefectQty($exec1, 2, $this->ppicFitting);

        $item2 = $this->createReadyFittingItem(20);
        $exec2 = $this->scanStage($item2, FittingItem::STAGE_NETTO_PF);
        $this->defectService->recordDefectQty($exec2, 4, $this->ppicFitting);

        $this->actingAs($this->adminQcFitting);
        $response = $this->get(route('fitting.qc-defects.index'));
        $response->assertOk();

        $summary = $response->viewData('summary');
        $this->assertSame(2, $summary['waiting_total']);
        $this->assertSame(0, $summary['confirmed_total']);
    }

    // ==========================================
    // E. Workflow & Breakdown Validation Tests
    // ==========================================

    public function test_18_partial_draft_saving_succeeds_and_retains_waiting_qc(): void
    {
        $item = $this->createReadyFittingItem(30);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);
        $this->defectService->recordDefectQty($exec, 10, $this->ppicFitting);

        $keropos = DefectType::where('department', 'fitting')->where('name', 'KEROPOS')->firstOrFail();

        $this->actingAs($this->adminQcFitting);
        $response = $this->post(route('fitting.qc-defects.save-draft', $exec), [
            'inspection_date' => Carbon::now()->toDateString(),
            'defects' => [
                ['defect_type_id' => $keropos->id, 'qty' => 4, 'notes' => 'Partial check'],
            ],
        ]);
        $response->assertRedirect();

        $exec->refresh();
        $this->assertSame(FittingStageExecution::STATUS_WAITING_QC, $exec->status);
        $this->assertSame(4, (int) $exec->defects()->sum('qty'));
    }

    public function test_19_draft_exceeding_defect_qty_is_rejected(): void
    {
        $item = $this->createReadyFittingItem(30);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);
        $this->defectService->recordDefectQty($exec, 5, $this->ppicFitting);

        $keropos = DefectType::where('department', 'fitting')->where('name', 'KEROPOS')->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('melebihi total defect');

        $this->defectService->saveQcBreakdownDraft(
            executionOrId: $exec,
            defects: [['defect_type_id' => $keropos->id, 'qty' => 6]],
            user: $this->adminQcFitting
        );
    }

    public function test_20_confirmation_with_sum_less_than_defect_qty_is_rejected(): void
    {
        $item = $this->createReadyFittingItem(30);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);
        $this->defectService->recordDefectQty($exec, 10, $this->ppicFitting);

        $keropos = DefectType::where('department', 'fitting')->where('name', 'KEROPOS')->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('harus sama persis');

        $this->defectService->verifyQcBreakdown(
            executionOrId: $exec,
            defects: [['defect_type_id' => $keropos->id, 'qty' => 8]],
            user: $this->adminQcFitting
        );
    }

    public function test_21_confirmation_with_sum_greater_than_defect_qty_is_rejected(): void
    {
        $item = $this->createReadyFittingItem(30);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);
        $this->defectService->recordDefectQty($exec, 5, $this->ppicFitting);

        $keropos = DefectType::where('department', 'fitting')->where('name', 'KEROPOS')->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('harus sama persis');

        $this->defectService->verifyQcBreakdown(
            executionOrId: $exec,
            defects: [['defect_type_id' => $keropos->id, 'qty' => 7]],
            user: $this->adminQcFitting
        );
    }

    public function test_22_confirmation_with_exact_sum_succeeds_and_locks_confirmed(): void
    {
        $item = $this->createReadyFittingItem(30);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);
        $this->defectService->recordDefectQty($exec, 5, $this->ppicFitting);

        $keropos = DefectType::where('department', 'fitting')->where('name', 'KEROPOS')->firstOrFail();
        $bocor = DefectType::where('department', 'fitting')->where('name', 'BOCOR TES')->firstOrFail();

        $confirmed = $this->defectService->verifyQcBreakdown(
            executionOrId: $exec,
            defects: [
                ['defect_type_id' => $keropos->id, 'qty' => 3],
                ['defect_type_id' => $bocor->id, 'qty' => 2],
            ],
            user: $this->adminQcFitting,
            notes: 'Verified OK',
            inspectionDate: Carbon::now()->toDateString()
        );

        $this->assertSame(FittingStageExecution::STATUS_CONFIRMED, $confirmed->status);
        $this->assertNotNull($confirmed->qc_verified_at);
        $this->assertSame($this->adminQcFitting->id, $confirmed->qc_verified_by);
        $this->assertSame(5, (int) $confirmed->defects()->sum('qty'));
    }

    public function test_23_return_for_correction_resets_to_waiting_defect_and_logs_reason(): void
    {
        $item = $this->createReadyFittingItem(30);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);
        $this->defectService->recordDefectQty($exec, 5, $this->ppicFitting);

        $returned = $this->defectService->returnForCorrection($exec, $this->adminQcFitting, 'Fisik rusak 6 bukan 5');

        $this->assertSame(FittingStageExecution::STATUS_WAITING_DEFECT, $returned->status);
        $this->assertSame(0, $returned->defects()->count());
        $this->assertStringContainsString('[QC RETURN]', $returned->notes);

        $latestLog = $returned->defectLogs()->reorder('id', 'desc')->first();
        $this->assertNotNull($latestLog);
        $this->assertStringContainsString('Fisik rusak 6 bukan 5', $latestLog->notes);
    }

    public function test_24_reopen_confirmed_verification_creates_permanent_json_snapshot(): void
    {
        $item = $this->createReadyFittingItem(30);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);
        $this->defectService->recordDefectQty($exec, 5, $this->ppicFitting);

        $keropos = DefectType::where('department', 'fitting')->where('name', 'KEROPOS')->firstOrFail();
        $this->defectService->verifyQcBreakdown(
            executionOrId: $exec,
            defects: [['defect_type_id' => $keropos->id, 'qty' => 5]],
            user: $this->adminQcFitting
        );

        $reopened = $this->defectService->reopenConfirmedForCorrection($exec, $this->adminQcFitting, 'Koreksi jumlah defect setelah perakitan');

        $this->assertSame(FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION, $reopened->status);

        $log = $reopened->defectLogs()->reorder('id', 'desc')->first();
        $this->assertNotNull($log);
        $this->assertNotNull($log->qc_snapshot_json);
        $this->assertSame('QC_REOPEN', $log->qc_snapshot_json['action']);
        $this->assertSame('Koreksi jumlah defect setelah perakitan', $log->qc_snapshot_json['reopen_reason']);
        $this->assertSame(5, $log->qc_snapshot_json['previous_defect_qty']);
        $this->assertSame('KEROPOS', $log->qc_snapshot_json['previous_defects'][0]['defect_type_name']);
    }

    // ==========================================
    // F. Data Integrity & Domain Isolation Tests
    // ==========================================

    public function test_25_draft_saving_never_mutates_defect_qty_or_good_qty(): void
    {
        $item = $this->createReadyFittingItem(30);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);
        $exec = $this->defectService->recordDefectQty($exec, 8, $this->ppicFitting);
        $initialDefect = (int) $exec->defect_qty;
        $initialGood = (int) $exec->good_qty;

        $keropos = DefectType::where('department', 'fitting')->where('name', 'KEROPOS')->firstOrFail();
        $this->defectService->saveQcBreakdownDraft(
            executionOrId: $exec,
            defects: [['defect_type_id' => $keropos->id, 'qty' => 3]],
            user: $this->adminQcFitting
        );
        $exec->refresh();

        $this->assertSame($initialDefect, (int) $exec->defect_qty);
        $this->assertSame($initialGood, (int) $exec->good_qty);
    }

    public function test_26_qc_confirmation_never_mutates_defect_qty_or_good_qty(): void
    {
        $item = $this->createReadyFittingItem(30);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);
        $exec = $this->defectService->recordDefectQty($exec, 8, $this->ppicFitting);
        $initialDefect = (int) $exec->defect_qty;
        $initialGood = (int) $exec->good_qty;

        $keropos = DefectType::where('department', 'fitting')->where('name', 'KEROPOS')->firstOrFail();
        $this->defectService->verifyQcBreakdown(
            executionOrId: $exec,
            defects: [['defect_type_id' => $keropos->id, 'qty' => 8]],
            user: $this->adminQcFitting
        );
        $exec->refresh();

        $this->assertSame($initialDefect, (int) $exec->defect_qty);
        $this->assertSame($initialGood, (int) $exec->good_qty);
    }

    public function test_27_sand_casting_defect_type_is_rejected_on_fitting_breakdown(): void
    {
        $item = $this->createReadyFittingItem(30);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);
        $this->defectService->recordDefectQty($exec, 5, $this->ppicFitting);

        $scDefect = DefectType::where('department', 'cor')->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bukan jenis kerusakan resmi Fitting');

        $this->defectService->saveQcBreakdownDraft(
            executionOrId: $exec,
            defects: [['defect_type_id' => $scDefect->id, 'qty' => 5]],
            user: $this->adminQcFitting
        );
    }

    public function test_28_auto_nihil_remains_strictly_idempotent_and_never_enters_qc(): void
    {
        $item = $this->createReadyFittingItem(20);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);
        $exec->update(['physical_done_at' => Carbon::now()->subDays(6)]);

        $firstRun = $this->defectService->timeoutToAutoNihil($exec, $this->systemAdmin->id);
        $this->assertSame(FittingStageExecution::STATUS_NO_DEFECT, $firstRun->status);

        $secondRun = $this->defectService->timeoutToAutoNihil($exec, $this->systemAdmin->id);
        $this->assertSame(FittingStageExecution::STATUS_NO_DEFECT, $secondRun->status);
        $this->assertSame($firstRun->auto_nihil_at->toIso8601String(), $secondRun->auto_nihil_at->toIso8601String());
    }

    // ==========================================
    // G. UAT Refinements: RBAC & Filter Isolation
    // ==========================================

    public function test_29_adminppicpf_and_ppic_role_are_strictly_forbidden_from_qc_defects(): void
    {
        $authService = app(FittingStageAuthorizationService::class);

        // 1. Role ppic user
        $this->assertFalse($authService->canVerifyQc($this->ppicFitting));
        $this->actingAs($this->ppicFitting);
        $this->get(route('fitting.qc-defects.index'))->assertForbidden();

        // 2. Admin PPIC PF email (even if role is admin)
        $adminPpic = User::create([
            'name' => 'Admin PPIC PF',
            'email' => 'adminppicpf@peroniks.com',
            'password' => Hash::make('password'),
        ]);
        $adminPpic->assignRole('admin');

        $this->assertFalse($authService->canVerifyQc($adminPpic));
        $this->actingAs($adminPpic);
        $this->get(route('fitting.qc-defects.index'))->assertForbidden();

        // 3. User with admin_ppic_fitting role
        $rolePpicPf = Role::firstOrCreate(['name' => 'admin_ppic_fitting', 'guard_name' => 'web']);
        $userPpicRole = User::create([
            'name' => 'Staff PPIC PF',
            'email' => 'staffppic@peroniks.com',
            'password' => Hash::make('password'),
        ]);
        $userPpicRole->assignRole($rolePpicPf);

        $this->assertFalse($authService->canVerifyQc($userPpicRole));
        $this->actingAs($userPpicRole);
        $this->get(route('fitting.qc-defects.index'))->assertForbidden();
    }

    public function test_30_ppic_user_cannot_submit_qc_draft_or_verification_actions(): void
    {
        $item = $this->createReadyFittingItem(30);
        $exec = $this->scanStage($item, FittingItem::STAGE_NETTO_PF, $this->systemAdmin);
        $this->defectService->recordDefectQty($exec, 5, $this->ppicFitting);

        $keropos = DefectType::where('department', 'fitting')->where('name', 'KEROPOS')->firstOrFail();

        // Acting as PPIC attempting saveDraft
        $this->actingAs($this->ppicFitting);
        $response = $this->post(route('fitting.qc-defects.save-draft', $exec), [
            'defects' => [['defect_type_id' => $keropos->id, 'qty' => 5]],
        ]);
        $response->assertForbidden();

        // Acting as PPIC attempting verify
        $response = $this->post(route('fitting.qc-defects.verify', $exec), [
            'defects' => [['defect_type_id' => $keropos->id, 'qty' => 5]],
        ]);
        $response->assertForbidden();

        // Acting as PPIC attempting return
        $response = $this->post(route('fitting.qc-defects.return', $exec), [
            'reason' => 'Test reason',
        ]);
        $response->assertForbidden();
    }

    public function test_31_qc_defects_index_defaults_to_netto_pf_and_removes_semua_tahap(): void
    {
        $this->actingAs($this->adminQcFitting);

        $response = $this->get(route('fitting.qc-defects.index'));
        $response->assertOk();

        // Defaults to netto_pf
        $this->assertSame(FittingItem::STAGE_NETTO_PF, $response->viewData('selectedStage'));

        // "Semua Tahap" must NOT exist in the rendered HTML
        $response->assertDontSee('Semua Tahap');

        // All 6 specific stages must be rendered
        $response->assertSee('NETTO PF');
        $response->assertSee('KIMIA');
        $response->assertSee('MARKING');
        $response->assertSee('BUBUT CNC PF');
        $response->assertSee('QC PF');
        $response->assertSee('GUDANG JADI PF');
    }

    public function test_32_master_defect_dropdown_contains_all_14_fitting_defect_types(): void
    {
        $this->actingAs($this->adminQcFitting);

        $response = $this->get(route('fitting.qc-defects.index'));
        $response->assertOk();

        $defectTypesMap = $response->viewData('defectTypesMap');
        $this->assertIsArray($defectTypesMap);
        $this->assertArrayHasKey(FittingItem::STAGE_NETTO_PF, $defectTypesMap);
        $this->assertCount(14, $defectTypesMap[FittingItem::STAGE_NETTO_PF]);

        $expectedNames = [
            'KEROPOS', 'PUTUS', 'RUSAK POTONG', 'RUSAK VIEW',
            'RANGKAIAN BOCOR', 'BUNGA COR', 'TIDAK SAMBUNG', 'RUSAK DRAT',
            'RUSAK SETTING', 'RUSAK BUBUT', 'BARANG MIRING', 'DRAT BLONG',
            'OVAL', 'BOCOR TES',
        ];

        foreach ($expectedNames as $name) {
            $response->assertSee($name);
        }
    }
}
