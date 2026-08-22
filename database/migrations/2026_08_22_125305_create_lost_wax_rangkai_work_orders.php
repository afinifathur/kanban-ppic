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
        if (! Schema::hasTable('lost_wax_rangkai_work_orders')) {
            Schema::create('lost_wax_rangkai_work_orders', function (Blueprint $table) {
                $table->id();
                $table->string('rangkai_order_number', 50)->unique();

                // Gunakan nama kustom fk_lw_rwo_line agar nama constraint pendek
                $table->foreignId('lost_wax_print_order_line_id')
                    ->constrained('lost_wax_print_order_lines', 'id', 'fk_lw_rwo_line')
                    ->cascadeOnDelete();

                $table->integer('qty_trees_planned')->unsigned();
                $table->integer('tree_capacity')->unsigned()->default(20);
                $table->boolean('require_layer_7')->default(false);
                $table->string('status', 30)->default('OPEN');
                $table->text('notes')->nullable();
                $table->string('reference_image_path', 500)->nullable();
                $table->foreignId('created_by')->constrained('users');
                $table->timestamps();

                $table->index(['lost_wax_print_order_line_id', 'status'], 'idx_lw_rangkai_wo_line_status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lost_wax_rangkai_work_orders');
    }
};
