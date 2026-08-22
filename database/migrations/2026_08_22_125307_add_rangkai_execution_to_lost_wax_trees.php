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
        if (Schema::hasTable('lost_wax_trees')) {
            Schema::table('lost_wax_trees', function (Blueprint $table) {
                if (! Schema::hasColumn('lost_wax_trees', 'rangkai_execution_id')) {
                    // Gunakan nama kustom fk_lw_tree_re agar nama constraint pendek
                    $table->foreignId('rangkai_execution_id')
                        ->nullable()
                        ->after('lost_wax_print_order_line_id')
                        ->constrained('lost_wax_rangkai_executions', 'id', 'fk_lw_tree_re')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('lost_wax_trees', 'require_layer_7')) {
                    $table->boolean('require_layer_7')->default(false)->after('rangkai_execution_id');
                }
                if (! Schema::hasColumn('lost_wax_trees', 'printed_count')) {
                    $table->integer('printed_count')->unsigned()->default(0)->after('require_layer_7');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('lost_wax_trees')) {
            Schema::table('lost_wax_trees', function (Blueprint $table) {
                if (Schema::hasColumn('lost_wax_trees', 'rangkai_execution_id')) {
                    $table->dropForeign('fk_lw_tree_re');
                    $table->dropColumn('rangkai_execution_id');
                }
                $columns = ['require_layer_7', 'printed_count'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('lost_wax_trees', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
