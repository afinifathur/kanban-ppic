<?php

namespace App\Console\Commands;

use App\Models\LostWaxItemReference;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrder;
use App\Models\LostWaxWorkOrderPlan;
use App\Models\LostWaxWorkOrderWip;
use App\Services\ScanService;
use App\Services\TreeGenerationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class LostWaxSeedDemo extends Command
{
    protected $signature = 'lost-wax:seed-demo
                            {--fresh : Delete existing LWDEMO* records before seeding}
                            {--yes : Skip confirmation prompt}';

    protected $description = 'Seed realistic Lost Wax UAT demo data (Work Orders, Trees, scan events). Requires --demo flag or explicit --yes.';

    public function handle(
        ScanService $scanService,
        TreeGenerationService $treeService,
    ): int {
        if (! $this->option('fresh') && ! $this->option('yes')) {
            $this->warn('Perintah ini akan membuat data demo Lost Wax.');
            $this->warn('Gunakan --fresh untuk menghapus data LWDEMO yang sudah ada sebelum seed ulang.');
            $this->warn('Gunakan --yes untuk skip prompt.');
            if (! $this->confirm('Lanjutkan?', false)) {
                return self::FAILURE;
            }
        }

        if ($this->option('fresh')) {
            $this->call('lost-wax:seed-demo-clean');
        }

        $existingCount = LostWaxWorkOrder::where('et_code', 'like', 'LWDEMO%')->count();
        if ($existingCount > 0 && ! $this->option('fresh')) {
            $this->info("Data demo sudah ada ({$existingCount} Work Order LWDEMO*). Gunakan --fresh untuk buat ulang.");

            return self::SUCCESS;
        }

        $this->info('Memulai seeding data demo Lost Wax...');

        $operatorId = $this->getOperatorId();

        $skus = $this->loadSkus();

        if (count($skus) < 3) {
            $this->error('Minimal 3 SKU aktif diperlukan. Periksa koneksi MasterDataKPI.');

            return self::FAILURE;
        }

        $families = config('lost_wax.families', []);
        $familyCodes = array_keys($families);
        if (count($familyCodes) < 2) {
            $this->error('Minimal 2 family code diperlukan di config/lost_wax.php.');

            return self::FAILURE;
        }

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 6, 0, 0));

        $workOrders = $this->createWorkOrders($skus, $familyCodes);
        $this->info(count($workOrders).' Work Order dibuat.');

        $planMap = $this->createPlans($workOrders);
        $this->info(count($planMap).' Plan/Wave dibuat.');

        $this->createWipEntries($workOrders, $planMap);
        $this->info('WIP (assembly) dibuat untuk semua Work Order.');

        $allTrees = $this->generateAllTrees($workOrders, $planMap, $treeService);
        $this->info(count($allTrees).' Tree dibuat melalui TreeGenerationService.');

        $this->applyScanStates($allTrees, $workOrders, $scanService, $operatorId);
        $this->info('Scan events dan state pohon diaplikasikan.');

        $this->createAnomaly($allTrees, $scanService, $operatorId);
        $this->info('Anomali scan dibuat.');

        Carbon::setTestNow(null);

        $this->newLine();
        $this->info('=== DEMO DATA SUMMARY ===');
        $this->info('Work Orders : '.count($workOrders));
        $this->info('Plans       : '.count($planMap));
        $this->info('Trees       : '.count($allTrees));
        $this->info('Scan Events : '.LostWaxScanEvent::count());
        $this->info('Successes   : '.LostWaxScanEvent::where('result', 'success')->count());
        $this->info('Rejected    : '.LostWaxScanEvent::where('result', 'rejected')->count());
        $this->info('');
        $this->info('Login: adminppicpf@peroniks.com / password');
        $this->info('Buka /lost-wax/scan untuk test scan manual.');
        $this->info('Buka /lost-wax/trees untuk lihat daftar tree.');
        $this->info('Buka /lost-wax/work-orders untuk lihat Work Order.');
        $this->info('');
        $this->info('Tree stage distribution:');

        foreach (LostWaxTree::selectRaw('current_stage, count(*) as cnt')->groupBy('current_stage')->pluck('cnt', 'current_stage') as $stage => $count) {
            $label = $stage ? config('lost_wax.stages.'.$stage, $stage) : 'SEBELUM_SCAN';
            $this->line("  {$label}: {$count}");
        }

        return self::SUCCESS;
    }

    private function getOperatorId(): int
    {
        return \App\Models\User::where('email', 'adminppicpf@peroniks.com')->value('id')
            ?? \App\Models\User::first()?->id
            ?? 1;
    }

    private function loadSkus(): array
    {
        $repo = app(\App\Contracts\ItemMasterRepository::class);
        $active = $repo->allActive();

        if ($active->isNotEmpty()) {
            return $active->shuffle()->take(10)->values()->toArray();
        }

        return [
            ['code' => '4.101105K.A0015', 'name' => 'SS304 CASTED PLANE FLANGE JIS 5K 1/2"', 'aisi' => 'SCS 13 A', 'standard' => 'JIS', 'unit_weight' => '0.300', 'status' => 'active'],
            ['code' => '4.101105K.A0080', 'name' => 'SS304 CASTED PLANE FLANGE JIS 5K 3"', 'aisi' => 'SCS 13 A', 'standard' => 'JIS', 'unit_weight' => '1.980', 'status' => 'active'],
            ['code' => '4.104210K.A0015', 'name' => 'SS316 CASTED RAISED FLANGE JIS 10K 1/2"', 'aisi' => 'SCS 14 A', 'standard' => 'JIS', 'unit_weight' => '0.450', 'status' => 'active'],
            ['code' => '4.1042UNIPN16.D0032', 'name' => 'SS316 CASTED RAISED FLANGE UNI 2278/29 PN 16 DN 32', 'aisi' => '1.4408', 'standard' => 'UNI', 'unit_weight' => '1.200', 'status' => 'active'],
            ['code' => '4.1111300LB.A0025', 'name' => 'SS304 CASTED WNRF FLANGE ANSI 300LBS SCH40 1"', 'aisi' => 'CF8', 'standard' => 'ANSI', 'unit_weight' => '0.800', 'status' => 'active'],
            ['code' => '4.1062ENPN16.D0080', 'name' => 'SS316 CASTED BLIND RAISED FLANGE EN1092-1 PN 16 3" DN 80', 'aisi' => '1.4408', 'standard' => 'EN', 'unit_weight' => '2.500', 'status' => 'active'],
            ['code' => '4.1041150LB.A0400', 'name' => 'SS304 CASTED RAISED FLANGE ANSI 150LBS 16"', 'aisi' => 'CF8', 'standard' => 'ANSI', 'unit_weight' => '5.000', 'status' => 'active'],
            ['code' => '4.1062ENPN16.D0150', 'name' => 'SS316 CASTED BLIND RAISED FLANGE EN1092-1 PN 16 6" DN 150', 'aisi' => '1.4408', 'standard' => 'EN', 'unit_weight' => '4.000', 'status' => 'active'],
            ['code' => '4.1071UNIPN16.D0300', 'name' => 'SS304 CASTED LOOSE FLANGE UNI 6090 PN 16 DN 300', 'aisi' => '1.4308', 'standard' => 'UNI', 'unit_weight' => '3.500', 'status' => 'active'],
            ['code' => '4.1041ENPN16IL.D0250', 'name' => 'SS304 CASTED RAISED FLANGE PN 16 DN 250', 'aisi' => '1.4308', 'standard' => 'EN', 'unit_weight' => '3.000', 'status' => 'active'],
        ];
    }

    private function createWorkOrders(array $skus, array $familyCodes): array
    {
        $workOrders = [];

        $demoSpecs = [
            ['et_code' => 'LWDEMO001', 'family' => '3', 'po_qty' => 100, 'stock' => 50, 'net' => 50, 'plan' => 50, 'wip' => 45, 'layer7' => false],
            ['et_code' => 'LWDEMO002', 'family' => '3', 'po_qty' => 100, 'stock' => 30, 'net' => 70, 'plan' => 60, 'wip' => 44, 'layer7' => false],
            ['et_code' => 'LWDEMO003', 'family' => '4', 'po_qty' => 100, 'stock' => 50, 'net' => 50, 'plan' => 45, 'wip' => 45, 'layer7' => false],
            ['et_code' => 'LWDEMO004', 'family' => '1', 'po_qty' => 80, 'stock' => 20, 'net' => 60, 'plan' => 60, 'wip' => 60, 'layer7' => false],
            ['et_code' => 'LWDEMO005', 'family' => '2', 'po_qty' => 80, 'stock' => 30, 'net' => 50, 'plan' => 48, 'wip' => 48, 'layer7' => false],
            ['et_code' => 'LWDEMO006', 'family' => '1', 'po_qty' => 60, 'stock' => 20, 'net' => 40, 'plan' => 40, 'wip' => 40, 'layer7' => false],
            ['et_code' => 'LWDEMO007', 'family' => '3', 'po_qty' => 60, 'stock' => 10, 'net' => 50, 'plan' => 45, 'wip' => 45, 'layer7' => true],
            ['et_code' => 'LWDEMO008', 'family' => '4', 'po_qty' => 50, 'stock' => 20, 'net' => 30, 'plan' => 30, 'wip' => 30, 'layer7' => false],
            ['et_code' => 'LWDEMO009', 'family' => '2', 'po_qty' => 50, 'stock' => 10, 'net' => 40, 'plan' => 33, 'wip' => 33, 'layer7' => false],
            ['et_code' => 'LWDEMO010', 'family' => '6', 'po_qty' => 50, 'stock' => 20, 'net' => 30, 'plan' => 30, 'wip' => 30, 'layer7' => false],
        ];

        foreach ($demoSpecs as $i => $spec) {
            $sku = $skus[$i % count($skus)];

            $reference = LostWaxItemReference::updateOrCreate(
                [
                    'master_source' => 'masterdata_kpi',
                    'master_item_key' => $sku['code'],
                ],
                [
                    'item_code_snapshot' => $sku['code'],
                    'item_name_snapshot' => $sku['name'] ?? null,
                    'aisi_snapshot' => $sku['aisi'] ?? null,
                    'standard_snapshot' => $sku['standard'] ?? null,
                    'unit_weight_snapshot' => $sku['unit_weight'] ?? null,
                    'status_snapshot' => $sku['status'] ?? 'active',
                    'last_synced_at' => now(),
                ]
            );

            $etParts = $this->parseEtParts($spec['et_code']);

            $wo = LostWaxWorkOrder::create([
                'item_reference_id' => $reference->id,
                'et_code' => $spec['et_code'],
                'et_prefix' => $etParts['prefix'],
                'et_period' => $etParts['period'],
                'et_sequence' => $etParts['sequence'],
                'po_number' => 'PO-DEMO-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'customer_name' => 'Demo Customer '.($i + 1),
                'po_quantity' => $spec['po_qty'],
                'stock_quantity' => $spec['stock'],
                'net_requirement_quantity' => $spec['net'],
                'status' => 'active',
                'notes' => 'UAT Demo WO #'.($i + 1),
                'family_code' => $spec['family'],
                'require_layer_7' => $spec['layer7'],
            ]);

            $workOrders[$spec['et_code']] = ['wo' => $wo, 'spec' => $spec];
        }

        return $workOrders;
    }

    private function createPlans(array &$workOrders): array
    {
        $planMap = [];

        foreach ($workOrders as $etcode => &$data) {
            $spec = $data['spec'];
            $wo = $data['wo'];

            $plan = LostWaxWorkOrderPlan::create([
                'work_order_id' => $wo->id,
                'wave_number' => 1,
                'plan_type' => 'initial',
                'planned_quantity' => $spec['plan'],
                'status' => 'planned',
            ]);

            $planMap[$etcode] = $plan;
            $data['plan'] = $plan;
        }

        return $planMap;
    }

    private function createWipEntries(array &$workOrders, array $planMap): void
    {
        foreach ($workOrders as $etcode => &$data) {
            $wo = $data['wo'];
            $spec = $data['spec'];
            $plan = $planMap[$etcode];

            LostWaxWorkOrderWip::create([
                'work_order_id' => $wo->id,
                'work_order_plan_id' => $plan->id,
                'stage' => 'assembly',
                'quantity' => $spec['wip'],
                'status' => 'recorded',
                'produced_at' => now(),
            ]);
        }
    }

    private function generateAllTrees(array &$workOrders, array $planMap, TreeGenerationService $treeService): array
    {
        $allTrees = [];

        $treeConfig = [
            'LWDEMO001' => ['default_qty' => 15, 'manual' => array_fill(0, 3, 15)],
            'LWDEMO002' => ['default_qty' => 15, 'manual' => array_merge(array_fill(0, 2, 15), [14])],
            'LWDEMO003' => ['default_qty' => 15, 'manual' => array_fill(0, 3, 15)],
            'LWDEMO004' => ['default_qty' => 15, 'manual' => array_fill(0, 4, 15)],
            'LWDEMO005' => ['default_qty' => 12, 'manual' => array_fill(0, 4, 12)],
            'LWDEMO006' => ['default_qty' => 10, 'manual' => array_fill(0, 4, 10)],
            'LWDEMO007' => ['default_qty' => 15, 'manual' => array_fill(0, 3, 15)],
            'LWDEMO008' => ['default_qty' => 10, 'manual' => array_fill(0, 3, 10)],
            'LWDEMO009' => ['default_qty' => 11, 'manual' => array_fill(0, 3, 11)],
            'LWDEMO010' => ['default_qty' => 10, 'manual' => array_fill(0, 3, 10)],
        ];

        foreach ($treeConfig as $etcode => $config) {
            $plan = $planMap[$etcode];
            $data = $workOrders[$etcode];
            $familyCode = $data['spec']['family'];

            $trees = $treeService->generate(
                $plan,
                $config['default_qty'],
                $config['manual'],
                $familyCode
            );

            $data['trees'] = $trees;
            $allTrees = array_merge($allTrees, $trees);
        }

        return $allTrees;
    }

    private function applyScanStates(array $allTrees, array $workOrders, ScanService $scanService, int $operatorId): void
    {
        $treesByWo = [];
        foreach ($allTrees as $tree) {
            $treesByWo[$tree->work_order_id][] = $tree;
        }

        $stageSteps = [
            'LWDEMO001' => [4, 4, false],        // layer_4 (2 trees kept fresh)
            'LWDEMO002' => [1, 1, false],         // layer_1 (28 kept fresh)
            'LWDEMO003' => [6, 5, false],         // layer_6, some layer_5
            'LWDEMO004' => [0, 0, false],         // ALL fresh (never scanned)
            'LWDEMO005' => [3, 3, false],         // layer_3
            'LWDEMO006' => [6, 3, true],          // oven for main (6 layers + oven), layer_3 for alt
            'LWDEMO007' => [7, 3, false],         // layer_7 for main (require_layer_7), layer_3 for alt
            'LWDEMO008' => [2, 2, false],         // layer_2
            'LWDEMO009' => [5, 5, false],         // layer_5
            'LWDEMO010' => [0, 0, false],         // ALL fresh
        ];

        foreach ($stageSteps as $etcode => [$mainStages, $altStages, $finalizeOven]) {
            if (! isset($workOrders[$etcode])) {
                continue;
            }

            $data = $workOrders[$etcode];
            $wo = $data['wo'];
            $requireLayer7 = (bool) $data['spec']['layer7'];

            $trees = LostWaxTree::where('work_order_id', $wo->id)
                ->orderBy('id')
                ->get();

            $total = $trees->count();
            if ($total === 0) {
                continue;
            }

            $mainCount = (int) ceil($total * 0.7);
            $altCount = $total - $mainCount;

            // Ensure at least one tree gets main stages and alt stages if possible
            $mainCount = max(1, min($total, $mainCount));
            $altCount = $total - $mainCount;

            $index = 0;

            // Main group
            $agingProfiles = $this->buildAgingProfiles($mainStages);

            for ($i = 0; $i < $mainCount && $index < $total; $i++, $index++) {
                $tree = $trees[$index];
                $this->scanTreeToStage($tree, $mainStages, $requireLayer7, $scanService, $operatorId, $agingProfiles, $finalizeOven);
            }

            // Alt group (different stage count)
            $altAgingProfiles = $this->buildAgingProfiles($altStages);

            for ($i = 0; $i < $altCount && $index < $total; $i++, $index++) {
                $tree = $trees[$index];
                $this->scanTreeToStage($tree, $altStages, $requireLayer7, $scanService, $operatorId, $altAgingProfiles, false);
            }
        }
    }

    private function buildAgingProfiles(int $stageCount): array
    {
        $profiles = [];

        $agingIntervals = [
            'too_fast' => 150,   // 2.5 hours
            'normal' => 320,     // ~5.3 hours
            'too_long' => 480,   // 8 hours
        ];

        for ($i = 0; $i < $stageCount; $i++) {
            $agingType = match ($i % 3) {
                0 => 'normal',
                1 => 'too_fast',
                2 => 'too_long',
                default => 'normal',
            };
            $profiles[] = ['minutes' => $agingIntervals[$agingType], 'type' => $agingType];
        }

        return $profiles;
    }

    private function scanTreeToStage(
        LostWaxTree $tree,
        int $targetStageCount,
        bool $requireLayer7,
        ScanService $scanService,
        int $operatorId,
        array $agingProfiles,
        bool $finalizeOven = false,
    ): void {
        if ($targetStageCount <= 0) {
            return;
        }

        $tree->load('workOrder');

        $baseTime = Carbon::create(2026, 8, 11, 8, 0, 0);

        for ($i = 0; $i < $targetStageCount; $i++) {
            $profile = $agingProfiles[$i] ?? ['minutes' => 300, 'type' => 'normal'];
            $advanceMinutes = $profile['minutes'];

            $scanTime = $baseTime->copy()->addMinutes(array_sum(array_column(array_slice($agingProfiles, 0, $i), 'minutes')));

            Carbon::setTestNow($scanTime);

            $nextStage = $tree->nextStage();

            if (! $nextStage) {
                break;
            }

            $tree->update(['last_scan_at' => $scanTime->copy()->subMinutes($advanceMinutes)]);

            $result = $scanService->process($tree->barcode, $operatorId);

            if (! $result['success']) {
                break;
            }

            $tree = $result['tree'];
        }

        Carbon::setTestNow(Carbon::create(2026, 8, 12, 8, 0, 0));

        if ($finalizeOven) {
            $tree->refresh();

            if (in_array($tree->current_stage, ['layer_6', 'layer_7'])) {
                Carbon::setTestNow(Carbon::create(2026, 8, 12, 9, 0, 0));
                $scanService->processOvenScan($tree->barcode, $operatorId);
            }
        }
    }

    private function createAnomaly(array $allTrees, ScanService $scanService, int $operatorId): void
    {
        $tree = LostWaxTree::whereNull('current_stage')
            ->orderBy('id')
            ->first();

        if (! $tree) {
            $tree = LostWaxTree::where('current_stage', 'layer_1')
                ->orderBy('id')
                ->first();
        }

        if (! $tree) {
            return;
        }

        $tree->load('workOrder');

        if (! $tree->current_stage) {
            Carbon::setTestNow(Carbon::create(2026, 8, 11, 9, 0, 0));
            $result = $scanService->process($tree->barcode, $operatorId);

            if ($result['success']) {
                $tree = $result['tree'];
                $tree->load('workOrder');
            }
        }

        if ($tree->current_stage === 'layer_1') {
            Carbon::setTestNow(Carbon::create(2026, 8, 11, 9, 30, 0));
            $scanService->rejectSkippedScan(
                $tree,
                $operatorId,
                'layer_3',
                'Tree saat ini berada di Lapisan 1. Lapisan berikutnya harus Lapisan 2.'
            );
        }
    }

    private function parseEtParts(string $etcode): array
    {
        if (preg_match('/^(?<prefix>[A-Z]+)(?<period>\d{2,4})?-?(?<sequence>\d+)$/', $etcode, $matches)) {
            return [
                'prefix' => $matches['prefix'] ?? 'LWDEMO',
                'period' => $matches['period'] ?? null,
                'sequence' => isset($matches['sequence']) ? (int) $matches['sequence'] : null,
            ];
        }

        return ['prefix' => 'LWDEMO', 'period' => null, 'sequence' => null];
    }
}
