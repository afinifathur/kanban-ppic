<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ProductionItem;
use App\Models\ProductionHistory;

echo "--- Checking February 11th Data ---\n";
// Look for items with Feb 11th production date
$items = ProductionItem::where('production_date', '2026-02-11')->get();
echo "Items count on Feb 11th: " . $items->count() . "\n";

foreach ($items as $item) {
    echo "Item ID: {$item->id} | Code: {$item->item_code} | Line: {$item->line_number} | Prod Date: {$item->production_date}\n";
    $hists = ProductionHistory::where('item_id', $item->id)->get();
    foreach ($hists as $h) {
        echo "  History ID: {$h->id} | To: {$h->to_dept} | Qty: {$h->qty_pcs} | Moved At: {$h->moved_at}\n";
    }
}

echo "\n--- All Recent History (Last 20) ---\n";
$recent = ProductionHistory::orderBy('id', 'desc')->take(20)->get();
foreach ($recent as $h) {
    $pDate = $h->item ? $h->item->production_date : 'N/A';
    echo "ID: {$h->id} | To: {$h->to_dept} | Qty: {$h->qty_pcs} | Line: {$h->line_number} | Moved: {$h->moved_at} | ProdDate: {$pDate}\n";
}
