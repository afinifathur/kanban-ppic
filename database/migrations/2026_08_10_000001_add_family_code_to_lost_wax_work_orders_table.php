<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lost_wax_work_orders') && ! Schema::hasColumn('lost_wax_work_orders', 'family_code')) {
            Schema::table('lost_wax_work_orders', function (Blueprint $table) {
                $table->string('family_code', 10)->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('lost_wax_work_orders') && Schema::hasColumn('lost_wax_work_orders', 'family_code')) {
            Schema::table('lost_wax_work_orders', function (Blueprint $table) {
                $table->dropColumn('family_code');
            });
        }
    }
};
