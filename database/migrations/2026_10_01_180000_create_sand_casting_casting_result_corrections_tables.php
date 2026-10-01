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
        if (Schema::hasTable('sand_casting_casting_result_lines')) {
            Schema::table('sand_casting_casting_result_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('sand_casting_casting_result_lines', 'needs_reprint')) {
                    $table->boolean('needs_reprint')->default(false)->after('print_count');
                }
            });
        }

        if (! Schema::hasTable('sand_casting_casting_result_corrections')) {
            Schema::create('sand_casting_casting_result_corrections', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('sand_casting_casting_result_id');
                $table->foreign('sand_casting_casting_result_id', 'fk_sc_corr_result_id')
                    ->references('id')
                    ->on('sand_casting_casting_results')
                    ->cascadeOnDelete();

                $table->unsignedBigInteger('sand_casting_casting_result_line_id')->nullable();
                $table->foreign('sand_casting_casting_result_line_id', 'fk_sc_corr_line_id')
                    ->references('id')
                    ->on('sand_casting_casting_result_lines')
                    ->cascadeOnDelete();

                $table->string('target_type', 20)->default('HEADER'); // 'HEADER' or 'LINE'
                $table->string('field_name', 50);
                $table->text('old_value')->nullable();
                $table->text('new_value')->nullable();
                $table->text('reason')->nullable();

                $table->foreignId('corrected_by')->constrained('users', 'id', 'fk_sc_corr_user');
                $table->timestamp('corrected_at');
                $table->timestamps();

                $table->index('sand_casting_casting_result_id', 'idx_sc_corr_res_id');
                $table->index('sand_casting_casting_result_line_id', 'idx_sc_corr_line_id');
                $table->index('corrected_at', 'idx_sc_corr_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sand_casting_casting_result_corrections');

        if (Schema::hasTable('sand_casting_casting_result_lines')) {
            Schema::table('sand_casting_casting_result_lines', function (Blueprint $table) {
                if (Schema::hasColumn('sand_casting_casting_result_lines', 'needs_reprint')) {
                    $table->dropColumn('needs_reprint');
                }
            });
        }
    }
};
