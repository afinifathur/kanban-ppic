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
                if (! Schema::hasColumn('lost_wax_trees', 'rack_id')) {
                    $table->foreignId('rack_id')
                        ->nullable()
                        ->after('rangkai_execution_id')
                        ->constrained('lost_wax_coating_racks', 'id', 'fk_lw_tree_coating_rack')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('lost_wax_trees', 'rack_assigned_at')) {
                    $table->timestamp('rack_assigned_at')->nullable()->after('rack_id');
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
                if (Schema::hasColumn('lost_wax_trees', 'rack_id')) {
                    $table->dropForeign('fk_lw_tree_coating_rack');
                    $table->dropColumn('rack_id');
                }
                if (Schema::hasColumn('lost_wax_trees', 'rack_assigned_at')) {
                    $table->dropColumn('rack_assigned_at');
                }
            });
        }
    }
};
