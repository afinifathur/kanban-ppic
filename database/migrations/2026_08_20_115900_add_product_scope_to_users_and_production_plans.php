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
        if (! Schema::hasColumn('users', 'product_scope')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('product_scope')->nullable()->after('password');
            });
        }

        if (! Schema::hasColumn('production_plans', 'product_scope')) {
            Schema::table('production_plans', function (Blueprint $table) {
                $table->string('product_scope')->nullable()->after('customer');
            });
        }

        // Run mapping logic for legacy Production Plans
        $plans = \DB::table('production_plans')->get();
        foreach ($plans as $plan) {
            $scope = \App\Models\ProductionPlan::determineProductScopeFromItem($plan->item_name, $plan->aisi);
            if ($scope) {
                \DB::table('production_plans')
                    ->where('id', $plan->id)
                    ->update(['product_scope' => $scope]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'product_scope')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('product_scope');
            });
        }

        if (Schema::hasColumn('production_plans', 'product_scope')) {
            Schema::table('production_plans', function (Blueprint $table) {
                $table->dropColumn('product_scope');
            });
        }
    }
};
