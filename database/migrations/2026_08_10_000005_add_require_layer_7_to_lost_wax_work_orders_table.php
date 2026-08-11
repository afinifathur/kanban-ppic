<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lost_wax_work_orders') && ! Schema::hasColumn('lost_wax_work_orders', 'require_layer_7')) {
            Schema::table('lost_wax_work_orders', function (Blueprint $table) {
                $table->boolean('require_layer_7')->default(false)->after('family_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('lost_wax_work_orders') && Schema::hasColumn('lost_wax_work_orders', 'require_layer_7')) {
            Schema::table('lost_wax_work_orders', function (Blueprint $table) {
                $table->dropColumn('require_layer_7');
            });
        }
    }
};
