<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ProductionPlan;
use App\Models\ProductionItem;

echo "--- PRODUCTION PLANS FOR UN09 ---\n";
$plans = ProductionPlan::where('item_code', 'UN09')->orWhere('code', 'UN09')->get();
foreach ($plans as $plan) {
    echo "ID: {$plan->id}, Item Code: {$plan->item_code}, Weight: {$plan->weight}, Qty Planned: {$plan->qty_planned}\n";
}

echo "\n--- PRODUCTION ITEMS FOR UN09 (NETTO) ---\n";
$items = ProductionItem::where('current_dept', 'netto')->where(function ($q) {
    $q->where('item_code', 'UN09')->orWhere('code', 'UN09');
})->orderBy('created_at', 'desc')->limit(10)->get();
foreach ($items as $item) {
    echo "ID: {$item->id}, Code: {$item->code}, Heat: {$item->heat_number}, Weight: {$item->weight_kg}, Finish Weight: {$item->finish_weight}\n";
}
