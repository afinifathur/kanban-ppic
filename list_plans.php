<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$plans = App\Models\ProductionPlan::select('code', 'item_code', 'weight')->get();
foreach ($plans as $plan) {
    echo "Code: '{$plan->code}', Item Code: '{$plan->item_code}', Weight: {$plan->weight}\n";
}
