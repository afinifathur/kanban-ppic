<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lost_wax_moulding_instances')) {
            return;
        }

        Schema::table('lost_wax_moulding_instances', function (Blueprint $table) {
            $table->unique(['moulding_family_id', 'instance_code'], 'lw_moulding_instance_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('lost_wax_moulding_instances')) {
            return;
        }

        Schema::table('lost_wax_moulding_instances', function (Blueprint $table) {
            $table->dropUnique('lw_moulding_instance_unique');
        });
    }
};
