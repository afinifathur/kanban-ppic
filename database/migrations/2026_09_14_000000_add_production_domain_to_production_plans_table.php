<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('production_plans', 'production_domain')) {
            Schema::table('production_plans', function (Blueprint $table) {
                $table->string('production_domain', 50)->default('LOST_WAX')->after('product_scope');
            });
        }

        // Migrate all existing records to LOST_WAX if null or empty
        DB::table('production_plans')
            ->whereNull('production_domain')
            ->orWhere('production_domain', '')
            ->update(['production_domain' => 'LOST_WAX']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('production_plans', 'production_domain')) {
            Schema::table('production_plans', function (Blueprint $table) {
                $table->dropColumn('production_domain');
            });
        }
    }
};
