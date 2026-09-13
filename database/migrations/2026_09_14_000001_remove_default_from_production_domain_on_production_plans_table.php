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
        if (Schema::hasColumn('production_plans', 'production_domain')) {
            Schema::table('production_plans', function (Blueprint $table) {
                $table->string('production_domain', 50)->nullable()->default(null)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('production_plans', 'production_domain')) {
            Schema::table('production_plans', function (Blueprint $table) {
                $table->string('production_domain', 50)->default('LOST_WAX')->nullable(false)->change();
            });
        }
    }
};
