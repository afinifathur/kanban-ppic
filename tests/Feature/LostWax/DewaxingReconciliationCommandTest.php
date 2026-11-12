<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DewaxingReconciliationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'id' => 3,
            'name' => 'Admin PPIC',
            'email' => 'adminppicpf@peroniks.com',
        ]);
    }

    protected function createTree(array $attributes = []): LostWaxTree
    {
        static $seq = 100;
        $seq++;

        return LostWaxTree::create(array_merge([
            'barcode' => 'TREE-REC-'.$seq,
            'tree_number' => $seq,
            'family_code' => '1',
            'daily_sequence' => $seq,
            'quantity' => 20,
            'status' => 'generated',
            'current_stage' => 'oven',
            'production_date' => Carbon::now()->toDateString(),
        ], $attributes));
    }

    public function test_01_dry_run_mode_does_not_modify_database(): void
    {
        $cutoff = '2026-10-12 23:59:59';

        $tree1 = $this->createTree([
            'barcode' => 'TEST001',
            'last_scan_at' => Carbon::parse('2026-10-10 10:00:00'),
        ]);

        LostWaxScanEvent::create([
            'tree_id' => $tree1->id,
            'barcode' => $tree1->barcode,
            'stage' => 'oven',
            'scanned_at' => Carbon::parse('2026-10-10 10:00:00'),
            'operator_id' => $this->adminUser->id,
            'result' => 'success',
            'source' => 'physical_scan',
        ]);

        $this->artisan('lost-wax:reconcile-dewaxing', [
            '--dry-run' => true,
            '--cutoff' => $cutoff,
            '--user-id' => $this->adminUser->id,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('DRY-RUN');

        $tree1->refresh();
        $this->assertEquals('oven', $tree1->current_stage);
        $this->assertEquals(0, LostWaxScanEvent::where('stage', 'siap_cor')->count());
    }

    public function test_02_force_mode_reconciles_eligible_trees_with_audit_trail(): void
    {
        $cutoff = '2026-10-12 23:59:59';

        // Tree 1: Eligible (Oven scan on 2026-10-10 <= cutoff)
        $treeEligible = $this->createTree([
            'barcode' => 'TREE-ELIGIBLE-1',
            'quantity' => 25,
            'last_scan_at' => Carbon::parse('2026-10-10 10:00:00'),
        ]);

        $originalOvenEvent = LostWaxScanEvent::create([
            'tree_id' => $treeEligible->id,
            'barcode' => $treeEligible->barcode,
            'stage' => 'oven',
            'scanned_at' => Carbon::parse('2026-10-10 10:00:00'),
            'operator_id' => $this->adminUser->id,
            'result' => 'success',
            'source' => 'physical_scan',
        ]);

        // Tree 2: Remaining in monitoring (Oven scan on 2026-10-15 > cutoff)
        $treeRemaining = $this->createTree([
            'barcode' => 'TREE-REMAINING-2',
            'quantity' => 20,
            'last_scan_at' => Carbon::parse('2026-10-15 08:00:00'),
        ]);

        LostWaxScanEvent::create([
            'tree_id' => $treeRemaining->id,
            'barcode' => $treeRemaining->barcode,
            'stage' => 'oven',
            'scanned_at' => Carbon::parse('2026-10-15 08:00:00'),
            'operator_id' => $this->adminUser->id,
            'result' => 'success',
            'source' => 'physical_scan',
        ]);

        // Tree 3: Excluded (already has siap_cor event)
        $treeAlreadySiapCor = $this->createTree([
            'barcode' => 'TREE-ALREADY-SIAP-3',
            'quantity' => 15,
            'current_stage' => 'siap_cor',
            'last_scan_at' => Carbon::parse('2026-10-11 12:00:00'),
        ]);

        LostWaxScanEvent::create([
            'tree_id' => $treeAlreadySiapCor->id,
            'barcode' => $treeAlreadySiapCor->barcode,
            'stage' => 'oven',
            'scanned_at' => Carbon::parse('2026-10-10 09:00:00'),
            'operator_id' => $this->adminUser->id,
            'result' => 'success',
            'source' => 'physical_scan',
        ]);

        LostWaxScanEvent::create([
            'tree_id' => $treeAlreadySiapCor->id,
            'barcode' => $treeAlreadySiapCor->barcode,
            'stage' => 'siap_cor',
            'scanned_at' => Carbon::parse('2026-10-11 12:00:00'),
            'operator_id' => $this->adminUser->id,
            'result' => 'success',
            'source' => 'physical_scan',
        ]);

        // Run live execution
        $this->artisan('lost-wax:reconcile-dewaxing', [
            '--cutoff' => $cutoff,
            '--user-id' => $this->adminUser->id,
            '--force' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('REKONSILIASI HISTORIS SELESAI DENGAN SUKSES');

        // Verify eligible tree updated
        $treeEligible->refresh();
        $this->assertEquals('siap_cor', $treeEligible->current_stage);

        // Verify original oven scan intact
        $originalOvenEvent->refresh();
        $this->assertEquals('oven', $originalOvenEvent->stage);
        $this->assertEquals('physical_scan', $originalOvenEvent->source);
        $this->assertEquals('2026-10-10 10:00:00', $originalOvenEvent->scanned_at->format('Y-m-d H:i:s'));

        // Verify reconciliation event created
        $reconEvent = LostWaxScanEvent::where('tree_id', $treeEligible->id)
            ->where('stage', 'siap_cor')
            ->first();

        $this->assertNotNull($reconEvent);
        $this->assertEquals('historical_reconciliation', $reconEvent->source);
        $this->assertEquals('success', $reconEvent->result);
        $this->assertEquals($this->adminUser->id, $reconEvent->operator_id);
        $this->assertStringContainsString('12 Oktober 2026', $reconEvent->notes);
        $this->assertTrue($reconEvent->is_reconciliation);

        // Verify remaining tree still in oven
        $treeRemaining->refresh();
        $this->assertEquals('oven', $treeRemaining->current_stage);
        $this->assertFalse(LostWaxScanEvent::where('tree_id', $treeRemaining->id)->where('stage', 'siap_cor')->exists());

        // Verify already siap cor tree untouched (no duplicate event)
        $this->assertEquals(1, LostWaxScanEvent::where('tree_id', $treeAlreadySiapCor->id)->where('stage', 'siap_cor')->count());
    }

    public function test_03_reconciliation_command_is_strictly_idempotent(): void
    {
        $cutoff = '2026-10-12 23:59:59';

        $tree = $this->createTree([
            'barcode' => 'TREE-IDEMPOTENT',
            'quantity' => 10,
            'last_scan_at' => Carbon::parse('2026-10-05 10:00:00'),
        ]);

        LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'oven',
            'scanned_at' => Carbon::parse('2026-10-05 10:00:00'),
            'operator_id' => $this->adminUser->id,
            'result' => 'success',
            'source' => 'physical_scan',
        ]);

        // First run: reconciles 1 tree
        $this->artisan('lost-wax:reconcile-dewaxing', [
            '--cutoff' => $cutoff,
            '--user-id' => $this->adminUser->id,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertEquals(1, LostWaxScanEvent::where('stage', 'siap_cor')->count());

        // Second run: should find 0 candidates and not duplicate event
        $this->artisan('lost-wax:reconcile-dewaxing', [
            '--cutoff' => $cutoff,
            '--user-id' => $this->adminUser->id,
            '--force' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Tidak ada pohon kandidat yang memenuhi kriteria rekonsiliasi. Selesai (Idempotent).');

        $this->assertEquals(1, LostWaxScanEvent::where('stage', 'siap_cor')->count());
    }

    public function test_04_default_source_is_physical_scan(): void
    {
        $tree = $this->createTree();

        // Create scan event without specifying source
        $event = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'oven',
            'scanned_at' => now(),
            'operator_id' => $this->adminUser->id,
            'result' => 'success',
        ]);

        $event->refresh();
        $this->assertEquals('physical_scan', $event->source);
        $this->assertFalse($event->is_reconciliation);
    }
}
