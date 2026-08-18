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
        if (! Schema::hasTable('lost_wax_print_orders')) {
            Schema::create('lost_wax_print_orders', function (Blueprint $table) {
                $table->id();
                $table->string('print_order_number')->unique();
                $table->date('scheduled_date');
                $table->enum('status', ['DRAFT', 'ISSUED', 'CANCELLED'])->default('DRAFT');
                $table->foreignId('created_by')->constrained('users');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('lost_wax_print_order_lines')) {
            Schema::create('lost_wax_print_order_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('lost_wax_print_order_id')->constrained('lost_wax_print_orders')->cascadeOnDelete();
                $table->foreignId('production_plan_id')->nullable()->constrained('production_plans')->nullOnDelete();
                $table->integer('qty_ordered');
                // Snapshot columns for historical integrity
                $table->string('code')->nullable();
                $table->string('customer')->nullable();
                $table->string('item_name');
                $table->string('size')->nullable();
                $table->string('aisi')->nullable();
                $table->timestamps();

                $table->index(['lost_wax_print_order_id', 'production_plan_id'], 'idx_lw_po_lines_po_plan');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lost_wax_print_order_lines');
        Schema::dropIfExists('lost_wax_print_orders');
    }
};
