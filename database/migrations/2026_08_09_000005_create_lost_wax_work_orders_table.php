<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lost_wax_work_orders')) {
            return;
        }

        Schema::create('lost_wax_work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_reference_id')->constrained('lost_wax_item_references')->restrictOnDelete();
            $table->string('et_code', 50)->unique();
            $table->string('et_prefix', 20)->nullable();
            $table->string('et_period', 20)->nullable();
            $table->unsignedInteger('et_sequence')->nullable();
            $table->string('po_number', 100);
            $table->string('customer_name')->nullable();
            $table->unsignedInteger('po_quantity');
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedInteger('net_requirement_quantity');
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamps();

            $table->index(['item_reference_id', 'status']);
            $table->index(['po_number', 'customer_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_wax_work_orders');
    }
};
