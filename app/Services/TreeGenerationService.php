<?php

namespace App\Services;

use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrder;
use App\Models\LostWaxWorkOrderPlan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TreeGenerationService
{
    public function calculateProposedTrees(int $totalQty, int $defaultQtyPerTree): array
    {
        if ($totalQty <= 0 || $defaultQtyPerTree <= 0) {
            return [];
        }

        $fullTrees = intdiv($totalQty, $defaultQtyPerTree);
        $remainder = $totalQty % $defaultQtyPerTree;

        $quantities = [];

        for ($i = 0; $i < $fullTrees; $i++) {
            $quantities[] = $defaultQtyPerTree;
        }

        if ($remainder > 0) {
            $quantities[] = $remainder;
        }

        return $quantities;
    }

    public function generate(
        LostWaxWorkOrderPlan $plan,
        int $defaultQtyPerTree,
        ?array $manualQuantities = null,
        ?string $familyCode = null
    ): array {
        $workOrder = LostWaxWorkOrder::with(['trees', 'wipEntries'])->findOrFail($plan->work_order_id);
        $familyCode = $familyCode ?? $workOrder->family_code;

        if (empty($familyCode)) {
            throw new \InvalidArgumentException('Kode family harus diisi. Silakan isi family code pada Work Order terlebih dahulu.');
        }

        if (! array_key_exists($familyCode, config('lost_wax.families', []))) {
            throw new \InvalidArgumentException("Kode family \"{$familyCode}\" tidak dikenali.");
        }

        $treeQtyOnPlan = (int) $workOrder->trees()
            ->where('work_order_plan_id', $plan->id)
            ->sum('quantity');

        $availableQty = $workOrder->assembly_output_quantity - $this->getTotalAllocatedToTrees($workOrder);

        if ($availableQty <= 0) {
            throw new \InvalidArgumentException('Tidak ada quantity tersedia untuk tree. Semua assembly output sudah dialokasikan.');
        }

        $quantities = $manualQuantities ?? $this->calculateProposedTrees($availableQty, $defaultQtyPerTree);

        $totalQty = array_sum($quantities);

        if ($totalQty > $availableQty) {
            throw new \InvalidArgumentException("Total quantity tree ({$totalQty}) melebihi quantity tersedia ({$availableQty}).");
        }

        if ($totalQty <= 0) {
            throw new \InvalidArgumentException('Total quantity tree harus lebih dari 0.');
        }

        $productionDate = Carbon::now(config('app.timezone'));

        $currentTreeCount = $this->getTreeCountForPlan($plan);

        $trees = [];

        $maxRetries = 5;

        for ($retry = 0; $retry < $maxRetries; $retry++) {
            $startingSeq = (int) (LostWaxTree::where('family_code', $familyCode)
                ->whereDate('production_date', $productionDate->format('Y-m-d'))
                ->max('daily_sequence') ?? 0);

            try {
                DB::transaction(function () use ($workOrder, $plan, $quantities, $familyCode, $productionDate, &$trees, &$currentTreeCount, $startingSeq) {
                    $maxSeq = $startingSeq;

                    foreach ($quantities as $quantity) {
                        $maxSeq++;
                        $currentTreeCount++;

                        $barcode = $familyCode
                            .$productionDate->format('dmy')
                            .str_pad((string) $maxSeq, 3, '0', STR_PAD_LEFT);

                        $tree = LostWaxTree::create([
                            'work_order_id' => $workOrder->id,
                            'work_order_plan_id' => $plan->id,
                            'barcode' => $barcode,
                            'tree_number' => $currentTreeCount,
                            'quantity' => $quantity,
                            'status' => 'generated',
                            'production_date' => $productionDate->format('Y-m-d'),
                            'family_code' => $familyCode,
                            'daily_sequence' => $maxSeq,
                        ]);

                        $trees[] = $tree;
                    }
                });

                break;
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($retry === $maxRetries - 1) {
                    throw $e;
                }

                $trees = [];
                $currentTreeCount = $this->getTreeCountForPlan($plan);
            }
        }

        return $trees;
    }

    public function adjustQuantity(LostWaxTree $tree, int $newQuantity): void
    {
        if ($newQuantity < 1) {
            throw new \InvalidArgumentException('Quantity tree minimal 1 pcs.');
        }

        if (! $tree->is_correctable) {
            throw new \InvalidArgumentException('Tree dengan status ini tidak dapat dikoreksi.');
        }

        $workOrder = $tree->workOrder()->with('trees')->firstOrFail();

        $totalAllocated = (int) $workOrder->trees->sum('quantity');
        $currentQty = $tree->quantity;
        $newTotal = $totalAllocated - $currentQty + $newQuantity;

        $availableQty = $workOrder->assembly_output_quantity;

        if ($newTotal > $availableQty) {
            throw new \InvalidArgumentException("Total quantity tree ({$newTotal}) akan melebihi assembly output ({$availableQty}).");
        }

        $tree->update(['quantity' => $newQuantity]);
    }

    public function getTotalAllocatedToTrees(LostWaxWorkOrder $workOrder): int
    {
        return (int) $workOrder->trees()->sum('quantity');
    }

    public function getTreeCountForPlan(LostWaxWorkOrderPlan $plan): int
    {
        return (int) LostWaxTree::where('work_order_plan_id', $plan->id)->count();
    }

    public function getRemainingQuantity(LostWaxWorkOrder $workOrder): int
    {
        return max(0, $workOrder->assembly_output_quantity - $this->getTotalAllocatedToTrees($workOrder));
    }
}
