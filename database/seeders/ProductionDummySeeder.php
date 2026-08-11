<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ProductionDummySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $items = [
            'Flange JIS 10K', 'Flange ANSI 150', 'Flange JIS 5K', 'Flange ANSI 300',
        ];
        $sizes = ['2"', '3"', '4"', '5"', '6"', '8"', '10"', '12"', '14"', '16"', '18"', '24"'];

        for ($i = 0; $i < 50; $i++) {
            // Logic Qty based on Size
            $size = $sizes[array_rand($sizes)];
            $sizeVal = (int) filter_var($size, FILTER_SANITIZE_NUMBER_INT);
            $qty = 100;
            if ($sizeVal >= 5 && $sizeVal <= 12) {
                $qty = 50;
            }
            if ($sizeVal > 14) {
                $qty = 10;
            }

            // Heat Number Logic: T D YYMMDD NN
            $type = ['A', 'B'][rand(0, 1)]; // A=304, B=316
            $dapur = rand(1, 2);
            // Random date in last 45 days to show full aging spectrum
            $entryDate = now()->subDays(rand(0, 45));
            $dateStr = $entryDate->format('ymd');
            $pour = str_pad(rand(1, 30), 2, '0', STR_PAD_LEFT);
            $heatNo = "{$type}{$dapur}{$dateStr}{$pour}";

            $itemName = $items[array_rand($items)].' '.$size;
            $weight = $qty * ($sizeVal * 1.5); // Check logic: 2" * 1.5 = 3kg per pc? Approximation.

            // Dept Logic: Distribute items to visualize flow
            // Mostly in COR (Input), some in Netto, etc.
            // Or prioritize COR as per "input data dummy"?
            // User: "buatkan dulu ... data dummy ... input data dummy" mostly implies seeding the starting point.
            // But having them scattered is better for testing Aging/Kanban.
            // I'll put most in COR (Line 1-4) and some downstream.
            $dept = 'cor';
            $rand = rand(1, 100);
            if ($rand > 60) {
                $dept = 'netto';
            }
            if ($rand > 80) {
                $dept = 'bubut_od';
            }

            $lp = \App\Models\ProductionItem::create([
                'heat_number' => $heatNo,
                'item_name' => $itemName,
                'qty_pcs' => $qty,
                'weight_kg' => $weight,
                'current_dept' => $dept,
                'line_number' => rand(1, 4),
                'dept_entry_at' => $entryDate, // Aging base
                'created_at' => $entryDate,
            ]);

            // History
            \App\Models\ProductionHistory::create([
                'item_id' => $lp->id,
                'from_dept' => null,
                'to_dept' => 'cor',
                'line_number' => $lp->line_number,
                'qty_pcs' => $qty,
                'weight_kg' => $weight,
                'moved_at' => $entryDate,
            ]);
        }
    }
}
