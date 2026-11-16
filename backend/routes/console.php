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

Artisan::command('telegram:diagnose', function () {
    $this->info('Loaded environment: '.app()->environmentFilePath());
    $this->info('Configured bot username: '.(string) config('services.telegram.bot_username'));
    $token = (string) config('services.telegram.bot_token');
    if ($token === '') {
        $this->error('TELEGRAM_BOT_TOKEN is not configured.');
        return Command::FAILURE;
    }

    $baseUrl = "https://api.telegram.org/bot{$token}";
    $failed = false;
    foreach (['getMe' => [], 'getWebhookInfo' => []] as $method => $payload) {
        try {
            $response = Http::timeout(15)->post($baseUrl.'/'.$method, $payload);
        } catch (\Throwable $exception) {
            $this->error($method.' failed: Telegram API is unreachable.');
            $failed = true;
            continue;
        }
        if (!$response->successful() || $response->json('ok') !== true) {
            $this->error($method.' failed (HTTP '.$response->status().', code '.($response->json('error_code') ?? 'unknown').'): '.str_replace($token, '[REDACTED]', (string) ($response->json('description') ?? 'Telegram rejected the request')));
            $failed = true;
            continue;
        }
        $this->info($method.' HTTP '.$response->status().' (ok=true)');
        $result = $response->json('result', []);
        if ($method === 'getMe') {
            $this->info('Bot reachable: @'.($result['username'] ?? 'unknown').' (id '.($result['id'] ?? 'unknown').')');
            if (strcasecmp(ltrim((string) config('services.telegram.bot_username'), '@'), (string) ($result['username'] ?? '')) !== 0) {
                $this->error('Bot identity does not match TELEGRAM_BOT_USERNAME; account linking URLs target a different bot.');
                return Command::FAILURE;
            }
        } else {
            $this->info('Webhook URL configured: '.(($result['url'] ?? '') !== '' ? 'yes' : 'no'));
            $expected = rtrim((string) config('app.url'), '/').'/api/telegram/webhook';
            $matches = ($result['url'] ?? '') === $expected;
            $this->info('Webhook matches APP_URL: '.($matches ? 'yes' : 'no'));
            $failed = $failed || !$matches;
            $this->info('Pending updates: '.(int) ($result['pending_update_count'] ?? 0));
            if (!empty($result['last_error_message'])) $this->warn('Last Telegram webhook error: '.$result['last_error_message']);
        }
    }

    return $failed ? Command::FAILURE : Command::SUCCESS;
})->purpose('Check Telegram bot credentials and webhook health without exposing the bot token');

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
