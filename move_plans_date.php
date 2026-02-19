<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ProductionPlan;
use Carbon\Carbon;

$sourceDate = '2026-02-16';
$targetDate = '2026-02-04';

$plans = ProductionPlan::whereDate('created_at', $sourceDate)->get();
$count = $plans->count();

echo "Found $count plans on $sourceDate.\n";

if ($count > 0) {
    foreach ($plans as $plan) {
        $plan->update([
            'created_at' => Carbon::parse($targetDate . ' ' . $plan->created_at->format('H:i:s')),
            'updated_at' => Carbon::parse($targetDate . ' ' . $plan->updated_at->format('H:i:s'))
        ]);
    }
    echo "Successfully moved $count plans to $targetDate.\n";
}
?>