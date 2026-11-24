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
        if (! Schema::hasTable('lost_wax_kitirs')) {
            Schema::create('lost_wax_kitirs', function (Blueprint $table) {
                $table->id();

                $table->foreignId('lost_wax_casting_result_id')
                    ->constrained('lost_wax_casting_results', 'id', 'fk_lw_kitirs_result')
                    ->cascadeOnDelete();

                // Traveler number format: PFKTR-YYYYMMDD-XXXX (globally unique)
                $table->string('traveler_number', 64)->unique('uk_lw_kitirs_traveler');

                // Group key (single Production Code or combined code from multi-allocation snapshot)
                $table->string('production_code', 255)->index('idx_lw_kitirs_prod_code');

                // Snapshot metadata for Kitir card
                $table->string('customer', 255)->nullable();
                $table->string('item_name', 255)->nullable();
                $table->unsignedInteger('tree_count')->default(0);
                $table->unsignedInteger('quantity_pcs')->default(0);

                // Audit & reprint tracking
                $table->unsignedInteger('print_count')->default(0);
                $table->timestamp('issued_at')->nullable();
                $table->timestamp('printed_at')->nullable();
                $table->foreignId('issued_by')->nullable()->constrained('users', 'id', 'fk_lw_kitirs_issued_by');
                $table->foreignId('last_printed_by')->nullable()->constrained('users', 'id', 'fk_lw_kitirs_printed_by');
                $table->text('notes')->nullable();

                $table->timestamps();

                // Concurrency-safe unique constraint: 1 Casting Result + 1 Production Code = EXACTLY 1 Kitir!
                $table->unique(['lost_wax_casting_result_id', 'production_code'], 'uk_lw_kitirs_result_code');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lost_wax_kitirs');
    }
};
