<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lost_wax_item_references')) {
            return;
        }

        Schema::create('lost_wax_item_references', function (Blueprint $table) {
            $table->id();
            $table->string('master_source')->default('masterdata_kpi');
            $table->string('master_item_key', 100)->unique();
            $table->string('item_code_snapshot', 100);
            $table->string('item_name_snapshot')->nullable();
            $table->string('aisi_snapshot')->nullable();
            $table->string('standard_snapshot')->nullable();
            $table->decimal('unit_weight_snapshot', 12, 3)->nullable();
            $table->string('status_snapshot', 50)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_wax_item_references');
    }
};
