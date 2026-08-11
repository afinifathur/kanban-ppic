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
        Schema::table('production_items', function (Blueprint $table) {
            $table->decimal('bubut_weight', 10, 2)->nullable()->after('netto_weight');
            $table->date('production_date')->nullable()->after('dept_entry_at');
            $table->integer('scrap_qty')->default(0)->after('qty_pcs');
        });
    }

    public function down(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->dropColumn(['bubut_weight', 'production_date', 'scrap_qty']);
        });
    }
};
