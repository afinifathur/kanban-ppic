<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('lost_wax_scan_events')) {
            Schema::table('lost_wax_scan_events', function (Blueprint $table) {
                if (! Schema::hasColumn('lost_wax_scan_events', 'source')) {
                    $table->string('source', 40)->default('physical_scan')->after('result')->index();
                }

                if (! Schema::hasColumn('lost_wax_scan_events', 'notes')) {
                    $table->string('notes', 255)->nullable()->after('anomaly_reason');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('lost_wax_scan_events')) {
            Schema::table('lost_wax_scan_events', function (Blueprint $table) {
                if (Schema::hasColumn('lost_wax_scan_events', 'notes')) {
                    $table->dropColumn('notes');
                }

                if (Schema::hasColumn('lost_wax_scan_events', 'source')) {
                    $table->dropColumn('source');
                }
            });
        }
    }
};
