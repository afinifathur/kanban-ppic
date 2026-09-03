<?php

namespace App\Services;

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
     */
    public function recordDefect(
        int|LostWaxTree $tree,
        string $stage,
        int $defectQty,
        string $defectReason,
        ?string $notes = null,
        ?DateTimeInterface $occurredAt = null,
        ?int $userId = null
    ): LostWaxTreeDefect {
        $treeId = $tree instanceof LostWaxTree ? $tree->id : $tree;

        if (! in_array($stage, self::VALID_STAGES, true)) {
            throw new InvalidArgumentException("Tahapan (stage) '{$stage}' tidak valid.");
        }

        if ($defectQty <= 0) {
            throw new InvalidArgumentException('Kuantitas defect harus lebih besar dari 0.');
        }

        return DB::transaction(function () use ($treeId, $stage, $defectQty, $defectReason, $notes, $occurredAt, $userId) {
            $treeModel = LostWaxTree::lockForUpdate()->findOrFail($treeId);

            if ($treeModel->status === 'cancelled') {
                throw new InvalidArgumentException("Tree dengan barcode {$treeModel->barcode} sudah dibatalkan (cancelled).");
            }

            $currentTotalDefect = (int) $treeModel->defects()->sum('defect_qty');
            $remainingPhysical = $treeModel->quantity - $currentTotalDefect;

            if ($defectQty > $remainingPhysical) {
                throw new InvalidArgumentException("Kuantitas defect baru ({$defectQty} pcs) melebihi sisa fisik pohon yang tersedia ({$remainingPhysical} pcs dari total {$treeModel->quantity} pcs).");
            }

            return $treeModel->defects()->create([
                'stage' => $stage,
                'defect_qty' => $defectQty,
                'defect_reason' => $defectReason,
                'notes' => $notes,
                'recorded_by' => $userId ?? auth()->id() ?? 1,
                'occurred_at' => $occurredAt ?? Carbon::now(),
            ]);
        });
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
        $qFinalUsable = 0;
        $qWipGross = 0;
        $qWipDefect = 0;

        foreach ($activeTrees as $tree) {
            $treeGross = $tree->quantity;
            $qActiveTreesGross += $treeGross;

            $treeDefects = $tree->defects;
            $totalTreeDefect = (int) $treeDefects->sum('defect_qty');
            $qTreeDefect += $totalTreeDefect;

            $assemblyDef = (int) $treeDefects->where('stage', 'assembly')->sum('defect_qty');
            $layerDef = (int) $treeDefects->filter(fn ($d) => str_starts_with($d->stage, 'layer_'))->sum('defect_qty');
            $ovenDef = (int) $treeDefects->where('stage', 'oven')->sum('defect_qty');

            $qAssemblyDefect += $assemblyDef;
            $qLayerDefect += $layerDef;
            $qOvenDefect += $ovenDef;

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
            'q_total_defect' => $qPrintDefect + $qTreeDefect,
            'q_excess_closed' => $qExcessClosed,
            'q_usable' => $qUsable,
            'status' => $status,
            'deficit_vs_plan' => $deficitVsPlan,
            'deficit_vs_po' => $deficitVsPo,
        ];
    }
}
