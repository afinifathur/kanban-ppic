<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Items Count: " . App\Models\ProductionItem::count() . "\n";
echo "History Count: " . App\Models\ProductionHistory::count() . "\n";
echo "Defects Count: " . App\Models\ProductionDefect::count() . "\n";
