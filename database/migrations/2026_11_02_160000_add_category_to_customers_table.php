<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers') && ! Schema::hasColumn('customers', 'category')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('category', 20)->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'category')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('category');
            });
        }
    }
};
