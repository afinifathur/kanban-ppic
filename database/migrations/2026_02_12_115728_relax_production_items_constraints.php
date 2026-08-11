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
            $table->decimal('weight_kg', 10, 2)->nullable()->change();
            $table->tinyInteger('line_number')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->decimal('weight_kg', 10, 2)->nullable(false)->change();
            $table->tinyInteger('line_number')->nullable(false)->change();
        });
    }
};
