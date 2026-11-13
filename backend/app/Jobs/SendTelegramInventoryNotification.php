<?php

namespace App\Jobs;

use App\Models\TelegramNotificationDelivery;
use App\Services\TelegramInventoryNotifier;
use App\Services\TelegramRateLimitException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendTelegramInventoryNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 45;

    public function __construct(public int $deliveryId) {}

    public function backoff(): array { return [60, 300, 900, 1800]; }

    public function handle(TelegramInventoryNotifier $notifier): void
    {
        $delivery = TelegramNotificationDelivery::with(['notification', 'user.business'])->find($this->deliveryId);
        if (!$delivery || $delivery->status === 'sent') return;
        $user = $delivery->user;
        $notification = $delivery->notification;
        if (!$user || !$notification || !$user->telegram_chat_id || $user->status !== 'active' || ($user->role === 'staff' && !$user->telegram_enabled)) {
            $delivery->update(['status' => 'skipped', 'last_error' => 'Recipient is no longer authorized or connected.']);
            return;
        }
        $preferences = $notifier->preferences($user->business);
        $preferenceKey = str_replace('inventory.', '', $notification->type);
        if (!$preferences['telegram_enabled'] || empty($preferences[$preferenceKey])) {
            $delivery->update(['status' => 'skipped', 'last_error' => 'Telegram inventory notifications are disabled.']);
            return;
        }
        $delivery->increment('attempts');
        $delivery->refresh();
        $params = $notification->data['params'] ?? [];
        $product = $notification->data['entity_type'] === 'product' ? \App\Models\Product::where('business_id', $delivery->business_id)->find($notification->data['entity_id'] ?? 0) : null;
        $params['type'] = $notification->type;
        $params['product_id'] = $product?->id;
        $params['workspace'] = $user->business?->name;
        $params['batch_code'] = $params['batch_code'] ?? $product?->batches()->find($params['batch_id'] ?? 0)?->batch_code;
        try {
            $result = $notifier->send($user->telegram_chat_id, $params, $product, $user->preferred_language ?: 'en');
            $delivery->update(['status' => 'sent', 'sent_at' => now(), 'telegram_message_id' => $result['message_id'], 'last_error' => null]);
        } catch (TelegramRateLimitException $failure) {
            $delivery->update(['status' => 'pending', 'next_attempt_at' => now()->addSeconds($failure->retryAfter), 'last_error' => $failure->getMessage()]);
            $this->release($failure->retryAfter);
        } catch (\Throwable $failure) {
            $delivery->update(['status' => 'pending', 'last_error' => $failure->getMessage(), 'next_attempt_at' => now()->addMinutes(5)]);
            Log::warning('Telegram inventory notification failed.', ['delivery_id' => $delivery->id, 'error' => $failure->getMessage()]);
            throw $failure;
        }
    }

    public function failed(\Throwable $failure): void
    {
        TelegramNotificationDelivery::whereKey($this->deliveryId)->update(['status' => 'failed', 'last_error' => $failure->getMessage()]);
    }
}
