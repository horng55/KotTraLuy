<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Command\Command;

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
