<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Command\Command;
use App\Models\Business;
use App\Services\InventoryAlertService;
use App\Services\TelegramInventoryNotifier;
use App\Jobs\SendTelegramInventoryNotification;
use App\Models\TelegramNotificationDelivery;
use App\Models\WorkspaceNotification;
use App\Models\User;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('telegram:configure', function () {
    $token = (string) config('services.telegram.bot_token');
    $secret = (string) config('services.telegram.webhook_secret');
    $miniAppUrl = rtrim((string) config('services.telegram.mini_app_url'), '/');
    $webhookUrl = rtrim((string) config('app.url'), '/').'/api/telegram/webhook';

    if ($token === '' || $secret === '') {
        $this->error('TELEGRAM_BOT_TOKEN and TELEGRAM_WEBHOOK_SECRET are required.');

        return Command::FAILURE;
    }

    if (! str_starts_with($miniAppUrl, 'https://') || ! str_starts_with($webhookUrl, 'https://')) {
        $this->error('APP_URL and TELEGRAM_MINI_APP_URL must use HTTPS.');

        return Command::FAILURE;
    }

    $baseUrl = "https://api.telegram.org/bot{$token}";
    $webhookResponse = Http::timeout(15)->post($baseUrl.'/setWebhook', [
        'url' => $webhookUrl,
        'secret_token' => $secret,
        'allowed_updates' => ['message', 'edited_message'],
    ]);
    $menuResponse = Http::timeout(15)->post($baseUrl.'/setChatMenuButton', [
        'menu_button' => [
            'type' => 'web_app',
            'text' => 'Open KotTraLuy',
            'web_app' => ['url' => $miniAppUrl],
        ],
    ]);

    if (! $webhookResponse->successful() || $webhookResponse->json('ok') !== true) {
        $this->error('Telegram rejected the webhook configuration: '.($webhookResponse->json('description') ?? 'Unknown error'));

        return Command::FAILURE;
    }

    if (! $menuResponse->successful() || $menuResponse->json('ok') !== true) {
        $this->error('Telegram rejected the Mini App menu button: '.($menuResponse->json('description') ?? 'Unknown error'));

        return Command::FAILURE;
    }

    $this->info("Webhook configured: {$webhookUrl}");
    $this->info("Mini App configured: {$miniAppUrl}");

    return Command::SUCCESS;
})->purpose('Configure the Telegram webhook and stable Mini App menu button');

Artisan::command('inventory:refresh-alerts', function (InventoryAlertService $alerts) {
    Business::where('status', 'active')->each(fn ($business) => $alerts->refresh($business));
    $this->info('Inventory alerts refreshed.');
})->purpose('Refresh expiry and stock alerts for every active business');

Artisan::command('telegram:remind-inventory', function (TelegramInventoryNotifier $notifier) {
    Business::where('status', 'active')->each(function ($business) use ($notifier) {
        $preferences = $notifier->preferences($business);
        if (!$preferences['telegram_enabled']) return;
        $cutoff = now()->subHours((int) $preferences['reminder_frequency_hours']);
        WorkspaceNotification::where('business_id', $business->id)
            ->whereIn('type', TelegramInventoryNotifier::INVENTORY_TYPES)
            ->whereNull('data->resolved_at')->with('notifiable')->get()->each(function ($notification) use ($business, $cutoff, $preferences) {
                $params = $notification->data['params'] ?? [];
                $recipients = User::where('business_id', $business->id)->where('status', 'active')
                    ->whereNotNull('telegram_chat_id')->when($preferences['recipients'], fn ($query) => $query->whereIn('id', $preferences['recipients']), fn ($query) => $query->where(fn ($allowed) => $allowed->where('role', 'owner')->orWhere('telegram_enabled', true)))->get();
                foreach ($recipients as $recipient) {
                    $delivery = TelegramNotificationDelivery::firstOrCreate(['notification_id' => $notification->id, 'user_id' => $recipient->id], ['business_id' => $business->id, 'status' => 'pending']);
                    if ($delivery->status !== 'sent' || !$delivery->sent_at || $delivery->sent_at->lte($cutoff)) {
                        $delivery->update(['status' => 'pending', 'next_attempt_at' => null]);
                        SendTelegramInventoryNotification::dispatch($delivery->id);
                    }
                }
            });
    });
    $this->info('Telegram inventory reminders queued.');
})->purpose('Queue due Telegram reminders for unresolved inventory alerts');
