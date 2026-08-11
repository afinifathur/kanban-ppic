<?php

namespace App\Console\Commands;

use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrder;
use Illuminate\Console\Command;

class LostWaxSeedDemoClean extends Command
{
    protected $signature = 'lost-wax:seed-demo-clean';

    protected $description = 'Hapus semua data demo Lost Wax (LWDEMO*). Hanya untuk development.';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Tidak dapat dijalankan di production.');

            return self::FAILURE;
        }

        $woIds = LostWaxWorkOrder::where('et_code', 'like', 'LWDEMO%')->pluck('id');

        if ($woIds->isEmpty()) {
            $this->info('Tidak ada data demo LWDEMO* yang perlu dihapus.');

            return self::SUCCESS;
        }

        $treeIds = LostWaxTree::whereIn('work_order_id', $woIds)->pluck('id');

        LostWaxScanEvent::whereIn('tree_id', $treeIds)->delete();
        $this->info('Scan events dihapus.');

        LostWaxTree::whereIn('work_order_id', $woIds)->delete();
        $this->info('Trees dihapus.');

        LostWaxWorkOrder::whereIn('id', $woIds)->delete();
        $this->info(count($woIds).' Work Orders dihapus.');

        return self::SUCCESS;
    }
}
