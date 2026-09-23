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
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'assigned_stage')) {
                    $table->string('assigned_stage', 30)->nullable()->after('product_scope');
                }
            });

            // Ensure index on assigned_stage exists safely
            try {
                Schema::table('users', function (Blueprint $table) {
                    $table->index('assigned_stage', 'idx_users_assigned_stage');
                });
            } catch (\Throwable $e) {
                // Index may already exist
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users')) {
            try {
                Schema::table('users', function (Blueprint $table) {
                    $table->dropIndex('idx_users_assigned_stage');
                });
            } catch (\Throwable $e) {
                // Ignore if index doesn't exist
            }
        }
    }
};
