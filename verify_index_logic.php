<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ProductionItem;
use App\Models\ProductionHistory;
use App\Models\ProductionPlan;
use Illuminate\Support\Facades\DB;

// Reset
DB::statement('SET FOREIGN_KEY_CHECKS=0;');
ProductionHistory::truncate();
ProductionItem::truncate();
DB::statement('SET FOREIGN_KEY_CHECKS=1;');

echo "1. Simulating COR Input for 2026-02-01...\n";
$item = ProductionItem::create([
    'item_code' => 'TEST-01',
    'item_name' => 'Test Item',
    'qty_pcs' => 100,
    'weight_kg' => 50,
    'current_dept' => 'netto',
    'production_date' => '2026-02-01',
    'dept_entry_at' => now(),
]);

ProductionHistory::create([
    'item_id' => $item->id,
    'from_dept' => 'cor',
    'to_dept' => 'netto',
    'qty_pcs' => 100,
    'weight_kg' => 50,
    'moved_at' => now(),
]);

echo "Checking Index for 'cor':\n";
$stats = ProductionHistory::where('from_dept', 'cor')
    ->join('production_items', 'production_histories.item_id', '=', 'production_items.id')
    ->selectRaw('COALESCE(production_items.production_date, DATE(production_histories.moved_at)) as date, COUNT(*) as items_count, SUM(production_histories.qty_pcs) as total_pcs')
    ->groupBy('date')
    ->get();

foreach ($stats as $s) {
    echo "Date: {$s->date}, Items: {$s->items_count}, Pcs: {$s->total_pcs}\n";
}

echo "\n2. Simulating NETTO Move to BUBUT OD...\n";
// Item in netto has 100 pcs. Move 40 forward.
$nextItem = $item->replicate();
$nextItem->current_dept = 'bubut_od';
$nextItem->qty_pcs = 40;
$nextItem->production_date = '2026-02-13';
$nextItem->save();

$item->decrement('qty_pcs', 40);

ProductionHistory::create([
    'item_id' => $nextItem->id,
    'from_dept' => 'netto',
    'to_dept' => 'bubut_od',
    'qty_pcs' => 40,
    'weight_kg' => 20,
    'moved_at' => now(),
]);

echo "Checking Index for 'netto':\n";
$statsNetto = ProductionHistory::where('from_dept', 'netto')
    ->join('production_items', 'production_histories.item_id', '=', 'production_items.id')
    ->selectRaw('COALESCE(production_items.production_date, DATE(production_histories.moved_at)) as date, COUNT(*) as items_count, SUM(production_histories.qty_pcs) as total_pcs')
    ->groupBy('date')
    ->get();

foreach ($statsNetto as $s) {
    echo "Date: {$s->date}, Items: {$s->items_count}, Pcs: {$s->total_pcs}\n";
}

echo "\nChecking Index for 'cor' again (Should still have 100 pcs):\n";
$statsCorAgain = ProductionHistory::where('from_dept', 'cor')
    ->join('production_items', 'production_histories.item_id', '=', 'production_items.id')
    ->selectRaw('COALESCE(production_items.production_date, DATE(production_histories.moved_at)) as date, COUNT(*) as items_count, SUM(production_histories.qty_pcs) as total_pcs')
    ->groupBy('date')
    ->get();

foreach ($statsCorAgain as $s) {
    echo "Date: {$s->date}, Items: {$s->items_count}, Pcs: {$s->total_pcs}\n";
}
