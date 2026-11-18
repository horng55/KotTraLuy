<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Facades\DB;

class WorkspaceNotificationSetup
{
    /** Fill missing values only. An explicitly empty recipient list is preserved. */
    public function initialize(Business $business): bool
    {
        return DB::transaction(function () use ($business) {
            $business = Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $owners = $business->ownerUsers()->where('status', 'active')->orderBy('id')->get();
            $defaults = [
                'telegram_enabled' => false, 'low_stock' => true, 'out_of_stock' => true,
                'expiring_soon' => true, 'expired' => true, 'reminder_frequency_hours' => 24,
                'preferred_language' => $owners->first()?->preferred_language ?: 'en',
                'recipients' => $owners->pluck('id')->all(),
            ];
            $existing = (array) $business->notification_preferences;
            $preferences = array_replace($defaults, $existing);
            if ($preferences === $existing) return false;
            $business->forceFill(['notification_preferences' => $preferences])->save();
            return true;
        });
    }
}
