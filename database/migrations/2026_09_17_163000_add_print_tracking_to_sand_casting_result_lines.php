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
                if (! Schema::hasColumn('sand_casting_casting_result_lines', 'printed_at')) {
                    $table->timestamp('printed_at')->nullable()->after('notes');
                }
                if (! Schema::hasColumn('sand_casting_casting_result_lines', 'print_count')) {
                    $table->unsignedInteger('print_count')->default(0)->after('printed_at');
                }
                if (! Schema::hasColumn('sand_casting_casting_result_lines', 'last_printed_by')) {
                    $table->foreignId('last_printed_by')
                        ->nullable()
                        ->after('print_count')
                        ->constrained('users')
                        ->nullOnDelete();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('sand_casting_casting_result_lines')) {
            Schema::table('sand_casting_casting_result_lines', function (Blueprint $table) {
                if (Schema::hasColumn('sand_casting_casting_result_lines', 'last_printed_by')) {
                    $table->dropConstrainedForeignId('last_printed_by');
                }
                if (Schema::hasColumn('sand_casting_casting_result_lines', 'print_count')) {
                    $table->dropColumn('print_count');
                }
                if (Schema::hasColumn('sand_casting_casting_result_lines', 'printed_at')) {
                    $table->dropColumn('printed_at');
                }
            });
        }
    }
};
