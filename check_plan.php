<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$plans = App\Models\ProductionPlan::where('item_code', 'UN09')->get();
foreach ($plans as $plan) {
    echo "Plan ID: {$plan->id}, Item: {$plan->item_code}, Weight: {$plan->weight}\n";
}
