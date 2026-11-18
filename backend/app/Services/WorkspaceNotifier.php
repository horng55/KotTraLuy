<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;
use App\Models\WorkspaceNotification;
use Illuminate\Support\Str;
use App\Jobs\SendTelegramInventoryNotification;
use App\Services\TelegramInventoryNotifier;

class WorkspaceNotifier
{
    public const TYPES = [
        'sale.created' => ['sales', 'records'],
        'sale.updated' => ['sales', 'records'],
        'sale.deleted' => ['sales', 'records'],
        'sale.unpaid' => ['sales', 'records'],
        'payment.received' => ['sales', 'records'],
        'inventory.low_stock' => ['inventory', 'alerts'],
        'inventory.out_of_stock' => ['inventory', 'alerts'],
        'inventory.expiring_soon' => ['inventory', 'inventory'],
        'inventory.expires_today' => ['inventory', 'inventory'],
        'inventory.expired' => ['inventory', 'inventory'],
        'expense.created' => ['expenses', 'records'],
        'team.created' => ['team', 'more'],
        'team.activated' => ['team', 'more'],
        'team.deactivated' => ['team', 'more'],
        'telegram.connected' => ['team', 'telegram'],
        'telegram.disconnected' => ['team', 'telegram'],
        'pack.created' => ['financing', 'packs'],
        'business.incomplete' => ['financing', 'business'],
        'security.password_changed' => ['system', 'more'],
        'security.password_reset' => ['system', 'more'],
    ];

    public function publish(int $businessId, string $type, string $eventKey, array $params, ?string $entityType = null, int|string|null $entityId = null, array $additionalRecipients = [], bool $owners = true): void
    {
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('Unsupported notification type.');
        }

        $inventoryEvent = in_array($type, TelegramInventoryNotifier::INVENTORY_TYPES, true);
        $sharedEvent = $owners && !str_starts_with($type, 'security.') && !str_starts_with($type, 'telegram.');
        $preferences = $inventoryEvent && ($business = Business::find($businessId))
            ? app(TelegramInventoryNotifier::class)->preferences($business) : [];
        $configuredRecipients = $preferences['recipients'] ?? [];
        $recipients = User::where(fn ($query) => $query->where('business_id', $businessId)->orWhereIn('id', \Illuminate\Support\Facades\DB::table('business_user')->where('business_id', $businessId)->where('status', 'active')->select('user_id')))->where(function ($query) use ($owners, $additionalRecipients, $sharedEvent) {
            if ($sharedEvent) {
                $query->where('status', 'active');
            } elseif ($owners) {
                $query->where(fn ($owner) => $owner->where('role', 'owner')->where('status', 'active'));
            } else {
                $query->whereRaw('1 = 0');
            }
            $query->orWhereIn('id', array_filter($additionalRecipients));
        })->where('status', 'active')->get();

        foreach ($recipients as $recipient) {
            if (!$recipient->useWorkspace($businessId)) continue;
            if ($inventoryEvent && !$recipient->canDo('inventory.read')) continue;
            if (str_starts_with($type, 'sale.') || $type === 'payment.received') {
                if (!$recipient->canDo('sales.read.all') && (!$entityId || !\App\Models\Sale::where('business_id', $businessId)->where('user_id', $recipient->id)->whereKey($entityId)->exists())) continue;
            }
            if (str_starts_with($type, 'expense.') && !$recipient->canDo('expenses.manage')) continue;
            $notification = WorkspaceNotification::firstOrCreate([
                'business_id' => $businessId,
                'notifiable_type' => $recipient->getMorphClass(),
                'notifiable_id' => $recipient->id,
                'event_key' => $eventKey,
            ], [
                'id' => (string) Str::uuid(),
                'type' => $type,
                'owner_only' => !$sharedEvent && !in_array((int) $recipient->id, array_map('intval', $additionalRecipients), true),
                'data' => [
                    'key' => $type,
                    'category' => self::TYPES[$type][0],
                    'params' => $params,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'action_page' => self::TYPES[$type][1],
                ],
            ]);
            $telegramRecipient = !$configuredRecipients || in_array((int) $recipient->id, array_map('intval', $configuredRecipients), true);
            $linkedRecipient = $recipient->telegram_chat_id && ($recipient->role === 'owner' || $recipient->telegram_enabled);
            $inventoryEligible = $inventoryEvent && $telegramRecipient && $linkedRecipient
                && !empty($preferences['telegram_enabled'])
                && !empty($preferences[str_replace('inventory.', '', $type)])
                && empty($notification->data['resolved_at']);
            if (($inventoryEvent ? $inventoryEligible : ($linkedRecipient && $notification->wasRecentlyCreated))) {
                $delivery = \App\Models\TelegramNotificationDelivery::firstOrCreate([
                    'notification_id' => $notification->id,
                    'user_id' => $recipient->id,
                ], ['business_id' => $businessId, 'status' => 'pending']);
                $queue = $delivery->wasRecentlyCreated;
                // Recover pre-connection / disabled skips after consent without
                // replaying sent, failed or already pending deliveries.
                if ($inventoryEvent && !$queue) {
                    $queue = (bool) \App\Models\TelegramNotificationDelivery::whereKey($delivery->id)
                        ->where('status', 'skipped')->whereNull('sent_at')
                        ->update(['status' => 'pending', 'last_error' => null, 'next_attempt_at' => null]);
                }
                if ($queue) SendTelegramInventoryNotification::dispatch($delivery->id)->afterCommit();
            }
        }
    }

    public function profileIncomplete(Business $business): void
    {
        $missing = collect(['name', 'activity', 'province'])->filter(fn ($field) => blank($business->$field))->values()->all();
        if ($missing) {
            $this->publish($business->id, 'business.incomplete', 'business.incomplete:'.$business->id.':'.hash('sha256', implode(',', $missing)), ['missing_fields' => $missing], 'business', $business->id);
        }
    }
}
