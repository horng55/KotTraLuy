<?php

namespace App\Observers;

use App\Models\Business;
use App\Services\InventoryAlertService;
use Illuminate\Database\Eloquent\Model;

class InventoryNotificationObserver
{
    public function saved(Model $model): void
    {
        if ($model->wasRecentlyCreated || $model->wasChanged(['stock_quantity', 'minimum_stock', 'is_active', 'quantity_remaining', 'expiry_date'])) {
            $business = Business::find($model->business_id);
            if ($business) {
                app(InventoryAlertService::class)->refresh($business);
            }
        }
    }
}
