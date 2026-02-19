<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ProductionPlan;
use Carbon\Carbon;

$count = ProductionPlan::whereDate('created_at', '2026-02-19')
    ->where('po_number', 'SE-2026-007')
    ->count();

echo "Found $count plans to update.\n";

if ($count > 0) {
    ProductionPlan::whereDate('created_at', '2026-02-19')
        ->where('po_number', 'SE-2026-007')
        ->update([
            'created_at' => Carbon::parse('2026-02-07 14:00:00'),
            'updated_at' => Carbon::parse('2026-02-07 14:00:00')
        ]);
    echo "Successfully updated $count plans to 2026-02-07.\n";
}
?>