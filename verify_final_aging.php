<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ProductionItem;
use Carbon\Carbon;

echo "Final Verification of Aging Logic (Base: 2026-02-13)\n";
Carbon::setTestNow(Carbon::parse('2026-02-13 11:00:00'));

function check($item_data, $label)
{
    $item = new ProductionItem($item_data);
    echo "[$label] Production Date: " . ($item->production_date ? $item->production_date->format('Y-m-d') : 'NULL') .
        ", Entry: " . ($item->dept_entry_at ? $item->dept_entry_at->format('Y-m-d') : 'NULL') .
        " => Aging: " . $item->aging_days . "d, Color: " . $item->aging_color . "\n";
}

check(['production_date' => '2026-02-01', 'dept_entry_at' => '2026-02-13'], "Delayed Input Situation"); // User's example
check(['production_date' => '2026-02-13', 'dept_entry_at' => '2026-02-13'], "Live Input Situation");
check(['production_date' => null, 'dept_entry_at' => '2026-02-10'], "Fallback Verification");

Carbon::setTestNow(); // Reset tracking
