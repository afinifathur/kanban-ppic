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
            $table->foreignId('plan_id')->after('id')->nullable()->constrained('production_plans')->onDelete('set null');
            $table->string('item_code')->nullable()->after('plan_id');
            $table->string('aisi')->nullable()->after('item_name');
            $table->string('size')->nullable()->after('aisi');
            $table->decimal('bruto_weight', 10, 2)->nullable()->after('weight_kg');
            $table->decimal('netto_weight', 10, 2)->nullable()->after('bruto_weight');
            $table->decimal('finish_weight', 10, 2)->nullable()->after('netto_weight');
            $table->string('po_number')->nullable()->after('finish_weight');
            $table->string('code')->nullable()->after('plan_id');
            $table->string('heat_number')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
            $table->dropColumn(['plan_id', 'item_code', 'aisi', 'size', 'bruto_weight', 'netto_weight', 'finish_weight', 'po_number', 'code']);
            $table->string('heat_number')->nullable(false)->change();
        });
    }
};
