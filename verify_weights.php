<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ProductionItem;

echo "--- Verifying Weight Calculation ---\n";
// Create a temporary mock-like scenario or query existing data
$item = ProductionItem::where('qty_pcs', '>', 0)->first();
if ($item) {
    $qty = $item->qty_pcs;
    $unitWeight = $item->weight_kg;
    $totalWeight = $qty * $unitWeight;

    echo "Item Code: {$item->item_code}\n";
    echo "Qty: {$qty}\n";
    echo "Unit Weight: {$unitWeight} kg\n";
    echo "Calculated Total: {$totalWeight} kg\n";

    // Check if total matches user expectation (e.g. 60 * 3.09 = 185.4)
    if ($qty == 60 && abs($unitWeight - 3.09) < 0.01) {
        echo "MATCHES USER EXAMPLE: 60 * 3.09 = " . (60 * 3.09) . " kg\n";
    }
} else {
    echo "No items found to verify.\n";
}

echo "\n--- Last 5 Items Tonnage Check ---\n";
$items = ProductionItem::where('qty_pcs', '>', 0)->latest()->take(5)->get();
foreach ($items as $i) {
    echo "ID: {$i->id} | Qty: {$i->qty_pcs} | Unit: {$i->weight_kg} | Total: " . ($i->qty_pcs * $i->weight_kg) . " kg\n";
}
