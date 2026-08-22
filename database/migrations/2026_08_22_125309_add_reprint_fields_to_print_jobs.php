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
        if (Schema::hasTable('print_jobs')) {
            Schema::table('print_jobs', function (Blueprint $table) {
                if (! Schema::hasColumn('print_jobs', 'tree_id')) {
                    // Gunakan nama kustom fk_pj_tree agar nama constraint pendek
                    $table->foreignId('tree_id')
                        ->nullable()
                        ->after('printer_name')
                        ->constrained('lost_wax_trees', 'id', 'fk_pj_tree')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('print_jobs', 'parent_print_job_id')) {
                    // Gunakan nama kustom fk_pj_parent agar nama constraint pendek
                    $table->foreignId('parent_print_job_id')
                        ->nullable()
                        ->after('tree_id')
                        ->constrained('print_jobs', 'id', 'fk_pj_parent')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('print_jobs', 'is_reprint')) {
                    $table->boolean('is_reprint')->default(false)->after('parent_print_job_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('print_jobs')) {
            Schema::table('print_jobs', function (Blueprint $table) {
                if (Schema::hasColumn('print_jobs', 'tree_id')) {
                    $table->dropForeign('fk_pj_tree');
                    $table->dropColumn('tree_id');
                }
                if (Schema::hasColumn('print_jobs', 'parent_print_job_id')) {
                    $table->dropForeign('fk_pj_parent');
                    $table->dropColumn('parent_print_job_id');
                }
                if (Schema::hasColumn('print_jobs', 'is_reprint')) {
                    $table->dropColumn('is_reprint');
                }
            });
        }
    }
};
