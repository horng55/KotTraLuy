<?php

namespace App\Jobs;

use App\Models\TelegramNotificationDelivery;
use App\Services\TelegramInventoryNotifier;
use App\Services\{TelegramPermanentFailureException, TelegramRateLimitException};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SendTelegramInventoryNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 45;

    public function __construct(public int $deliveryId) {}

    public function backoff(): array { return [60, 300, 900, 1800]; }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('telegram-delivery:'.$this->deliveryId))->releaseAfter(60)->expireAfter(75)];
    }

    public function handle(TelegramInventoryNotifier $notifier): void
    {
        $delivery = TelegramNotificationDelivery::with(['notification', 'user.business'])->find($this->deliveryId);
        if (!$delivery || $delivery->status === 'sent') return;
        if ($delivery->status === 'pending' && $delivery->next_attempt_at && $delivery->next_attempt_at->isFuture()) {
            $this->release(max(1, (int) ceil(now()->diffInSeconds($delivery->next_attempt_at))));
            return;
        }
        $user = $delivery->user;
        if ($user && !$user->useWorkspace((int) $delivery->business_id)) {
            $delivery->update(['status' => 'skipped', 'last_error' => 'Workspace membership is no longer authorized.']);
            return;
        }
        $notification = $delivery->notification;
        if (!$user || !$notification || (int) $delivery->business_id !== (int) $user->business_id || (int) $notification->business_id !== (int) $delivery->business_id || !$user->telegram_chat_id || $user->status !== 'active' || ($user->role === 'staff' && !$user->telegram_enabled)) {
            $delivery->update(['status' => 'skipped', 'last_error' => 'Recipient is no longer authorized or connected.']);
            return;
        }
        $preferences = $notifier->preferences($user->business);
        $inventoryEvent = in_array($notification->type, TelegramInventoryNotifier::INVENTORY_TYPES, true);
        if ($inventoryEvent && !empty($notification->data['resolved_at'])) {
            $delivery->update(['status' => 'skipped', 'last_error' => 'Inventory alert has been resolved.', 'next_attempt_at' => null]);
            return;
        }
        $salesEvent = str_starts_with($notification->type, 'sale.') || $notification->type === 'payment.received';
        if (($inventoryEvent && !$user->canDo('inventory.read'))
            || (str_starts_with($notification->type, 'expense.') && !$user->canDo('expenses.manage'))
            || ($salesEvent && !$user->canDo('sales.read.all') && !\App\Models\Sale::where('business_id', $user->business_id)->where('user_id', $user->id)->whereKey($notification->data['entity_id'] ?? 0)->exists())) {
            $delivery->update(['status' => 'skipped', 'last_error' => 'Recipient no longer has permission to view this notification.']);
            return;
        }
        if ((int) $notification->notifiable_id !== (int) $user->id || $notification->notifiable_type !== $user->getMorphClass() || ($notification->owner_only && $user->accessRole() === 'staff')) {
            $delivery->update(['status' => 'skipped', 'last_error' => 'Notification is not visible to this recipient.']);
            return;
        }
        $preferenceKey = str_replace('inventory.', '', $notification->type);
        if (in_array($notification->type, TelegramInventoryNotifier::INVENTORY_TYPES, true) && (!$preferences['telegram_enabled'] || empty($preferences[$preferenceKey]) || ($preferences['recipients'] && !in_array((int) $user->id, array_map('intval', $preferences['recipients']), true)))) {
            $delivery->update(['status' => 'skipped', 'last_error' => 'Telegram inventory notifications are disabled.']);
            return;
        }
        $delivery->increment('attempts');
        $delivery->forceFill(['last_attempt_at' => now(), 'error_category' => null])->save();
        $delivery->refresh();
        $params = $notification->data['params'] ?? [];
        $product = $notification->data['entity_type'] === 'product' ? \App\Models\Product::where('business_id', $delivery->business_id)->find($notification->data['entity_id'] ?? 0) : null;
        $params['type'] = $notification->type;
        $params['product_id'] = $product?->id;
        $params['workspace'] = $user->business?->name;
        $params['batch_code'] = $params['batch_code'] ?? $product?->batches()->find($params['batch_id'] ?? 0)?->batch_code;
        try {
            $result = $notifier->send($user->telegram_chat_id, $params, $product, $user->preferred_language ?: 'en');
            $delivery->update(['status' => 'sent', 'sent_at' => now(), 'next_attempt_at' => null, 'telegram_message_id' => $result['message_id'], 'last_error' => null, 'error_category' => null, 'telegram_response' => $result['response'] ?? null]);
        } catch (TelegramRateLimitException $failure) {
            $delivery->update(['status' => 'pending', 'next_attempt_at' => now()->addSeconds($failure->retryAfter), 'last_error' => $failure->getMessage(), 'error_category' => 'rate_limited']);
            $this->release($failure->retryAfter);
        } catch (TelegramPermanentFailureException $failure) {
            $delivery->update(['status' => 'failed', 'last_error' => $failure->getMessage(), 'error_category' => 'telegram_rejected', 'next_attempt_at' => null]);
            Log::notice('Telegram inventory notification permanently rejected.', ['delivery_id' => $delivery->id, 'error' => $failure->getMessage()]);
        } catch (\Throwable $failure) {
            $delay = $this->backoff()[min(max($this->attempts() - 1, 0), count($this->backoff()) - 1)];
            $delivery->update(['status' => 'pending', 'last_error' => $failure->getMessage(), 'error_category' => 'transient', 'next_attempt_at' => now()->addSeconds($delay)]);
            Log::warning('Telegram inventory notification failed.', ['delivery_id' => $delivery->id, 'error' => $failure->getMessage()]);
            throw $failure;
        }
    }

    public function failed(\Throwable $failure): void
    {
        TelegramNotificationDelivery::whereKey($this->deliveryId)->where('status', '!=', 'sent')->update(['status' => 'failed', 'next_attempt_at' => null, 'last_error' => $failure->getMessage()]);
    }
}
