<?php

namespace App\Providers;

use App\Contracts\ItemMasterRepository;
use App\Repositories\ArrayItemMasterRepository;
use App\Repositories\DatabaseItemMasterRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ItemMasterRepository::class, function () {
            if (config('lost_wax.masterdata.driver') === 'array') {
                return new ArrayItemMasterRepository(config('lost_wax.masterdata.fallback_items', []));
            }

            return new DatabaseItemMasterRepository;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
