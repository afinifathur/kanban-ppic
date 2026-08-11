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
        Schema::create('production_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable();
            $table->string('item_code');
            $table->string('item_name');
            $table->string('aisi')->nullable();
            $table->string('size')->nullable();
            $table->decimal('weight', 10, 2)->nullable();
            $table->string('po_number');
            $table->integer('qty_planned');
            $table->integer('qty_remaining');
            $table->integer('line_number');
            $table->string('customer')->nullable();
            $table->enum('status', ['planning', 'active', 'completed'])->default('planning');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_plans');
    }
};
