<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lost_wax_moulding_families')) {
            return;
        }

        Schema::create('lost_wax_moulding_families', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_reference_id')->constrained('lost_wax_item_references')->cascadeOnDelete();
            $table->string('family_code', 100)->unique();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['item_reference_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_wax_moulding_families');
    }
};
