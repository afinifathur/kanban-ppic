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
        if (! Schema::hasTable('sand_casting_casting_orders')) {
            Schema::create('sand_casting_casting_orders', function (Blueprint $table) {
                $table->id();
                $table->string('casting_order_number')->unique();
                $table->date('scheduled_date');
                $table->string('status', 30)->default('DRAFT'); // DRAFT, ISSUED, COMPLETED, CANCELLED
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->constrained('users');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sand_casting_casting_order_lines')) {
            Schema::create('sand_casting_casting_order_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sand_casting_casting_order_id');
                $table->foreign('sand_casting_casting_order_id', 'fk_sc_lines_order_id')
                    ->references('id')
                    ->on('sand_casting_casting_orders')
                    ->cascadeOnDelete();

                $table->unsignedBigInteger('production_plan_id')->nullable();
                $table->foreign('production_plan_id', 'fk_sc_lines_plan_id')
                    ->references('id')
                    ->on('production_plans')
                    ->nullOnDelete();

                $table->integer('qty_ordered');
                // Snapshot columns for historical integrity
                $table->string('code')->nullable();
                $table->string('customer')->nullable();
                $table->string('item_name');
                $table->string('size')->nullable();
                $table->string('aisi')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['sand_casting_casting_order_id', 'production_plan_id'], 'idx_sc_co_lines_co_plan');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sand_casting_casting_order_lines');
        Schema::dropIfExists('sand_casting_casting_orders');
    }
};
