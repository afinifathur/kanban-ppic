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
        Schema::create('production_defects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_item_id')->constrained('production_items')->cascadeOnDelete();
            $table->foreignId('defect_type_id')->constrained('defect_types')->cascadeOnDelete();
            $table->integer('qty');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_defects');
    }
};
