<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$item = App\Models\ProductionItem::where('code', 'UN09')->first();
if ($item) {
    echo "ID: {$item->id}\n";
    echo "Code: {$item->code}\n";
    echo "Heat: {$item->heat_number}\n";
    echo "Item Name: {$item->item_name}\n";
    echo "Weight (KG): {$item->weight_kg}\n";
    echo "Finish Weight: {$item->finish_weight}\n";
    echo "Current Dept: {$item->current_dept}\n";
} else {
    echo "Item not found.\n";
}
