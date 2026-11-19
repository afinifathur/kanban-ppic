<?php

namespace App\Services;

use App\Models\LostWaxCastingResultTree;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeDefect;
use App\Models\ProductionPlan;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LostWaxQualityService
{
    public const VALID_STAGES = [
        'assembly',
        'layer_1',
        'layer_2',
        'layer_3',
        'layer_4',
        'layer_5',
        'layer_6',
        'layer_7',
        'oven',
    ];

    /**
     * Record a defect for a specific tree.
     * Supports both pre-Siap Cor defects and post-Siap Cor defects (still under stage='oven',
     * distinguished by defect_context, scan_event_id, is_late_entry, and occurred_at vs created_at).
     */
    public function recordDefect(
        int|LostWaxTree $tree,
        string $stage,
        int $defectQty,
        string $defectReason,
        ?string $notes = null,
        ?DateTimeInterface $occurredAt = null,
        ?int $userId = null,
        ?string $defectContext = null,
        ?int $scanEventId = null,
        bool $confirmUnknownOccurredAt = false
    ): LostWaxTreeDefect {
        $treeId = $tree instanceof LostWaxTree ? $tree->id : $tree;

        if (! in_array($stage, self::VALID_STAGES, true)) {
            throw new InvalidArgumentException("Tahapan (stage) '{$stage}' tidak valid.");
        }

        if ($defectQty <= 0) {
            throw new InvalidArgumentException('Kuantitas defect harus lebih besar dari 0.');
        }

        $cleanNotes = $notes !== null ? trim($notes) : null;
        if ($cleanNotes === '') {
            $cleanNotes = null;
        }

        $nowWib = Carbon::now('Asia/Jakarta');
        $occurredAtWib = $occurredAt !== null
            ? Carbon::instance($occurredAt)->copy()->setTimezone('Asia/Jakarta')
            : null;

        if ($occurredAtWib !== null && $occurredAtWib->gt($nowWib->copy()->addSeconds(5))) {
            throw new InvalidArgumentException('Waktu kejadian fisik (occurred_at) tidak boleh berada di masa depan.');
        }

        return DB::transaction(function () use (
            $treeId,
            $stage,
            $defectQty,
            $defectReason,
            $cleanNotes,
            $occurredAtWib,
            $userId,
            $defectContext,
            $scanEventId,
            $confirmUnknownOccurredAt
        ) {
            $treeModel = LostWaxTree::lockForUpdate()->findOrFail($treeId);

            $this->assertTreeMutableForDefect($treeModel);

            $activeSiapCorEvent = $this->findActiveSiapCorEvent($treeModel->id);

            // Resolve defect_context when omitted by legacy callers
            $resolvedContext = $defectContext !== null && trim($defectContext) !== ''
                ? trim($defectContext)
                : (($treeModel->current_stage === 'siap_cor' && $stage === 'oven' && $activeSiapCorEvent !== null)
                    ? LostWaxTreeDefect::CONTEXT_POST_SIAP_COR
                    : LostWaxTreeDefect::CONTEXT_PRE_SIAP_COR);

            if (! in_array($resolvedContext, LostWaxTreeDefect::VALID_CONTEXTS, true)) {
                throw new InvalidArgumentException("Konteks defect '{$resolvedContext}' tidak valid.");
            }

            $resolvedScanEventId = null;
            $isLateEntry = false;

            if ($resolvedContext === LostWaxTreeDefect::CONTEXT_POST_SIAP_COR) {
                if ($stage !== 'oven') {
                    throw new InvalidArgumentException('Defect pasca-Siap Cor wajib dicatat pada tahapan Oven (tanggung jawab Departemen Oven).');
                }

                if ($treeModel->current_stage !== 'siap_cor' || $activeSiapCorEvent === null) {
                    throw new InvalidArgumentException('Defect pasca-Siap Cor hanya dapat dicatat pada Tree yang berstatus Siap Cor dan memiliki event Scan Siap Cor aktif.');
                }

                if ($scanEventId !== null && (int) $scanEventId !== (int) $activeSiapCorEvent->id) {
                    throw new InvalidArgumentException('Referensi scan_event_id tidak cocok dengan event Scan Siap Cor aktif milik Tree ini.');
                }

                $scanAtWib = $activeSiapCorEvent->scanned_at->copy()->setTimezone('Asia/Jakarta');
                if ($occurredAtWib !== null && $occurredAtWib->lt($scanAtWib)) {
                    throw new InvalidArgumentException(
                        'Waktu kejadian untuk defect Pasca-Siap Cor tidak boleh lebih awal dari waktu Scan Siap Cor ('.$scanAtWib->format('d-m-Y H:i:s').' WIB). Gunakan opsi Koreksi Terlambat Pra-Siap Cor jika kerusakan terjadi sebelum scan.'
                    );
                }

                $resolvedScanEventId = $activeSiapCorEvent->id;
            } else {
                // CONTEXT_PRE_SIAP_COR
                if ($scanEventId !== null) {
                    throw new InvalidArgumentException('Defect Pra-Siap Cor tidak boleh dikaitkan dengan scan_event_id Siap Cor.');
                }

                if ($treeModel->current_stage === 'siap_cor' && $activeSiapCorEvent !== null) {
                    // Late-recorded pre-Siap Cor correction after Scan Siap Cor already happened
                    $isLateEntry = true;

                    if ($cleanNotes === null || mb_strlen($cleanNotes) < 5) {
                        throw new InvalidArgumentException('Koreksi terlambat defect Pra-Siap Cor wajib menyertakan alasan/catatan minimal 5 karakter.');
                    }

                    $scanAtWib = $activeSiapCorEvent->scanned_at->copy()->setTimezone('Asia/Jakarta');
                    if ($occurredAtWib !== null) {
                        if ($occurredAtWib->gt($scanAtWib)) {
                            throw new InvalidArgumentException(
                                'Waktu kejadian untuk Koreksi Terlambat Pra-Siap Cor tidak boleh lebih baru dari waktu Scan Siap Cor ('.$scanAtWib->format('d-m-Y H:i:s').' WIB). Pilih konteks Pasca-Siap Cor.'
                            );
                        }
                    } elseif (! $confirmUnknownOccurredAt) {
                        throw new InvalidArgumentException('Untuk Koreksi Terlambat Pra-Siap Cor tanpa waktu kejadian pasti, wajib mencentang konfirmasi bahwa kejadian terjadi sebelum Scan Siap Cor.');
                    }
                }
            }

            $currentTotalDefect = (int) $treeModel->activeDefects()->sum('defect_qty');
            $remainingPhysical = (int) $treeModel->quantity - $currentTotalDefect;

            if ($defectQty > $remainingPhysical) {
                throw new InvalidArgumentException("Kuantitas defect baru ({$defectQty} pcs) melebihi sisa fisik pohon yang tersedia ({$remainingPhysical} pcs dari total {$treeModel->quantity} pcs).");
            }

            return $treeModel->defects()->create([
                'stage' => $stage,
                'defect_context' => $resolvedContext,
                'is_late_entry' => $isLateEntry,
                'scan_event_id' => $resolvedScanEventId,
                'defect_qty' => $defectQty,
                'defect_reason' => $defectReason,
                'notes' => $cleanNotes,
                'recorded_by' => $userId ?? auth()->id() ?? 1,
                'occurred_at' => $occurredAtWib, // NULL preserved when incident time is unknown
            ]);
        });
    }

    /**
     * Soft-void a mistakenly recorded defect with full audit trail (never deletes record).
     * Strictly blocked once Tree is linked to a non-CANCELLED Casting Result or Historical Cutoff.
     */
    public function voidDefect(
        int|LostWaxTree $tree,
        int|LostWaxTreeDefect $defect,
        string $reason,
        ?int $userId = null
    ): LostWaxTreeDefect {
        $treeId = $tree instanceof LostWaxTree ? $tree->id : $tree;
        $defectId = $defect instanceof LostWaxTreeDefect ? $defect->id : $defect;

        $cleanReason = trim($reason);
        if (mb_strlen($cleanReason) < 3) {
            throw new InvalidArgumentException('Alasan pembatalan (void) defect wajib diisi minimal 3 karakter.');
        }

        return DB::transaction(function () use ($treeId, $defectId, $cleanReason, $userId) {
            $treeModel = LostWaxTree::lockForUpdate()->findOrFail($treeId);

            $this->assertTreeMutableForDefect($treeModel);

            $defectModel = LostWaxTreeDefect::lockForUpdate()->findOrFail($defectId);

            if ((int) $defectModel->lost_wax_tree_id !== (int) $treeModel->id) {
                throw new InvalidArgumentException('Record defect tidak milik Tree ini.');
            }

            if ($defectModel->is_voided) {
                throw new InvalidArgumentException('Record defect ini sudah dibatalkan (VOIDED) sebelumnya.');
            }

            $defectModel->voided_at = Carbon::now('Asia/Jakarta');
            $defectModel->voided_by = $userId ?? auth()->id() ?? 1;
            $defectModel->void_reason = $cleanReason;
            $defectModel->save();

            return $defectModel->fresh(['recordedBy', 'voidedBy', 'scanEvent']);
        });
    }

    /**
     * Check if a tree is still eligible for defect recording / voiding.
     */
    public function isTreeMutableForDefect(LostWaxTree $treeModel): bool
    {
        try {
            $this->assertTreeMutableForDefect($treeModel);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Enforce defense-in-depth lifecycle guards protecting Casting Results & Historical Cutoffs.
     */
    protected function assertTreeMutableForDefect(LostWaxTree $treeModel): void
    {
        if ($treeModel->status === 'cancelled') {
            throw new InvalidArgumentException("Tree dengan barcode {$treeModel->barcode} sudah dibatalkan (cancelled).");
        }

        if ($treeModel->status === 'historical_cutoff' || $treeModel->activeHistoricalCutoffTree()->exists()) {
            throw new InvalidArgumentException("Tree dengan barcode {$treeModel->barcode} sudah ditutup melalui Historical Cutoff dan tidak dapat diubah defect-nya.");
        }

        if ($treeModel->current_stage === 'cor') {
            throw new InvalidArgumentException("Tree dengan barcode {$treeModel->barcode} sudah masuk tahap Cor (Casting Result) dan terkunci dari perubahan defect.");
        }

        $hasActiveCastingTree = LostWaxCastingResultTree::where('active_tree_id', $treeModel->id)->exists();
        if ($hasActiveCastingTree) {
            throw new InvalidArgumentException("Tree dengan barcode {$treeModel->barcode} sudah terikat pada transaksi Casting Result aktif dan tidak dapat diubah defect-nya.");
        }

        $hasNonCancelledCasting = LostWaxCastingResultTree::where('lost_wax_tree_id', $treeModel->id)
            ->whereHas('castingResult', function ($q) {
                $q->where('status', '!=', 'CANCELLED');
            })
            ->exists();

        if ($hasNonCancelledCasting) {
            throw new InvalidArgumentException("Tree dengan barcode {$treeModel->barcode} sudah dialokasikan ke transaksi Casting Result non-CANCELLED. Batalkan Casting Result terlebih dahulu sebelum mengubah defect.");
        }
    }

    /**
     * Resolve the single canonical active (non-voided, successful) Scan Siap Cor event for a Tree.
     */
    public function findActiveSiapCorEvent(int $treeId): ?LostWaxScanEvent
    {
        return LostWaxScanEvent::where('tree_id', $treeId)
            ->where('stage', 'siap_cor')
            ->where('result', 'success')
            ->whereDoesntHave('void')
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Calculate usable quantity for a single tree.
     */
    public function calculateTreeUsableQuantity(int|LostWaxTree $tree): int
    {
        $treeModel = $tree instanceof LostWaxTree
            ? $tree
            : LostWaxTree::with('defects')->findOrFail($tree);

        return $treeModel->usable_quantity;
    }

    /**
     * Calculate canonical usable quantity for an entire Production Plan.
     */
    public function calculateProductionPlanUsableQuantity(int|ProductionPlan $plan): int
    {
        $breakdown = $this->getProductionPlanQuantityBreakdown($plan);

        return $breakdown['q_usable'];
    }

    /**
     * Get a comprehensive quantity breakdown and status for a Production Plan.
     */
    public function getProductionPlanQuantityBreakdown(int|ProductionPlan $plan): array
    {
        $planModel = $plan instanceof ProductionPlan
            ? $plan
            : ProductionPlan::findOrFail($plan);

        // Load lines with executions and trees with defects
        $planModel->loadMissing([
            'printOrderLines.executions',
            'printOrderLines.printOrder',
            'printOrderLines.trees.defects',
            'printOrderLines.treeAllocations.tree.defects',
        ]);

        $lines = $planModel->printOrderLines->filter(function ($line) {
            return ! $line->printOrder || $line->printOrder->status !== 'CANCELLED';
        });

        $qScheduled = 0;
        $qPrintGood = 0;
        $qPrintDefect = 0;
        $qExcessClosed = 0;
        $allTrees = collect();

        foreach ($lines as $line) {
            $qScheduled += $line->qty_ordered;
            $qPrintGood += $line->qty_executed_good ?: ($line->qty_actual_good ?? 0);
            $qPrintDefect += $line->qty_executed_defect ?: ($line->qty_actual_defect ?? 0);
            $qExcessClosed += $line->qty_excess_closed ?? 0;

            // Collect direct trees
            foreach ($line->trees as $t) {
                if ($t->status !== 'cancelled') {
                    $allTrees->put($t->id, $t);
                }
            }

            // Collect trees allocated through multi-line allocations
            foreach ($line->treeAllocations as $alloc) {
                if ($alloc->tree && $alloc->tree->status !== 'cancelled') {
                    $allTrees->put($alloc->tree->id, $alloc->tree);
                }
            }
        }

        $activeTrees = $allTrees->values();

        $qActiveTreesGross = 0;
        $qTreeDefect = 0;
        $qAssemblyDefect = 0;
        $qLayerDefect = 0;
        $qOvenDefect = 0;
        $qOvenDefectPreSiapCor = 0;
        $qOvenDefectPostSiapCor = 0;
        $qFinalUsable = 0;
        $qWipGross = 0;
        $qWipDefect = 0;

        foreach ($activeTrees as $tree) {
            $treeGross = $tree->quantity;
            $qActiveTreesGross += $treeGross;

            $treeDefects = $tree->defects->whereNull('voided_at');
            $totalTreeDefect = (int) $treeDefects->sum('defect_qty');
            $qTreeDefect += $totalTreeDefect;

            $assemblyDef = (int) $treeDefects->where('stage', 'assembly')->sum('defect_qty');
            $layerDef = (int) $treeDefects->filter(fn ($d) => str_starts_with($d->stage, 'layer_'))->sum('defect_qty');
            $ovenDefects = $treeDefects->where('stage', 'oven');
            $ovenDef = (int) $ovenDefects->sum('defect_qty');
            $ovenPostDef = (int) $ovenDefects->where('defect_context', LostWaxTreeDefect::CONTEXT_POST_SIAP_COR)->sum('defect_qty');
            $ovenPreDef = $ovenDef - $ovenPostDef;

            $qAssemblyDefect += $assemblyDef;
            $qLayerDefect += $layerDef;
            $qOvenDefect += $ovenDef;
            $qOvenDefectPreSiapCor += $ovenPreDef;
            $qOvenDefectPostSiapCor += $ovenPostDef;

            $treeUsable = max(0, $treeGross - $totalTreeDefect);

            if ($tree->current_stage === 'oven') {
                $qFinalUsable += $treeUsable;
            } else {
                $qWipGross += $treeGross;
                $qWipDefect += $totalTreeDefect;
            }
        }

        $qWipNet = max(0, $qWipGross - $qWipDefect);

        // Standby pool = Print Good - Active Tree Gross - Excess Closed (clamped to min 0)
        $qStandby = max(0, $qPrintGood - $qActiveTreesGross - $qExcessClosed);

        // Canonical usable quantity = Print Good - Total Tree Defect - Excess Closed
        $qUsable = max(0, $qPrintGood - $qTreeDefect - $qExcessClosed);

        $status = $planModel->evaluateProductionStatus($qUsable);

        $deficitVsPlan = max(0, $planModel->qty_planned - $qUsable);
        $deficitVsPo = $planModel->po_quantity !== null
            ? max(0, $planModel->po_quantity - $qUsable)
            : null;

        return [
            'plan_id' => $planModel->id,
            'code' => $planModel->code,
            'po_number' => $planModel->po_number,
            'po_quantity' => $planModel->po_quantity,
            'qty_planned' => $planModel->qty_planned,
            'q_scheduled' => $qScheduled,
            'q_print_good' => $qPrintGood,
            'q_print_defect' => $qPrintDefect,
            'q_standby' => $qStandby,
            'q_active_trees_gross' => $qActiveTreesGross,
            'q_wip_gross' => $qWipGross,
            'q_wip_net' => $qWipNet,
            'q_final_usable' => $qFinalUsable,
            'q_tree_defect' => $qTreeDefect,
            'q_assembly_defect' => $qAssemblyDefect,
            'q_layer_defect' => $qLayerDefect,
            'q_oven_defect' => $qOvenDefect,
            'q_oven_defect_pre_siap_cor' => $qOvenDefectPreSiapCor,
            'q_oven_defect_post_siap_cor' => $qOvenDefectPostSiapCor,
            'q_total_defect' => $qPrintDefect + $qTreeDefect,
            'q_excess_closed' => $qExcessClosed,
            'q_usable' => $qUsable,
            'status' => $status,
            'deficit_vs_plan' => $deficitVsPlan,
            'deficit_vs_po' => $deficitVsPo,
        ];
    }
}
