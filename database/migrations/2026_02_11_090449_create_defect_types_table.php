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
        Schema::create('defect_types', function (Blueprint $table) {
            $table->id();
            $table->string('department'); // e.g., 'netto', 'bubut_od'
            $table->string('name');       // e.g., 'Retak', 'Keropos'
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('department');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('defect_types');
    }
};
