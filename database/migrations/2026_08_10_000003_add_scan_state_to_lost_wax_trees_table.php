<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lost_wax_trees') && ! Schema::hasColumn('lost_wax_trees', 'current_stage')) {
            Schema::table('lost_wax_trees', function (Blueprint $table) {
                $table->string('current_stage', 20)->nullable()->after('status');
                $table->timestamp('last_scan_at')->nullable()->after('current_stage');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('lost_wax_trees') && Schema::hasColumn('lost_wax_trees', 'current_stage')) {
            Schema::table('lost_wax_trees', function (Blueprint $table) {
                $table->dropColumn(['current_stage', 'last_scan_at']);
            });
        }
    }
};
