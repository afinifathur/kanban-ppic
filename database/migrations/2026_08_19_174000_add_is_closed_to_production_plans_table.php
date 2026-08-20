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
        if (Schema::hasTable('production_plans')) {
            Schema::table('production_plans', function (Blueprint $table) {
                if (! Schema::hasColumn('production_plans', 'is_closed')) {
                    $table->boolean('is_closed')->default(false)->after('status');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('production_plans')) {
            Schema::table('production_plans', function (Blueprint $table) {
                if (Schema::hasColumn('production_plans', 'is_closed')) {
                    $table->dropColumn('is_closed');
                }
            });
        }
    }
};
