<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([\App\Models\Sale::class, \App\Models\SalePayment::class, \App\Models\Expense::class, \App\Models\InventoryAlert::class, \App\Models\User::class, \App\Models\PreparationPack::class, \App\Models\Business::class] as $model) {
            $model::observe(\App\Observers\BusinessNotificationObserver::class);
        }
        foreach ([\App\Models\Product::class, \App\Models\StockBatch::class] as $model) {
            $model::observe(\App\Observers\InventoryNotificationObserver::class);
        }
    }
}
