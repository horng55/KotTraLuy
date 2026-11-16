<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TelegramInventoryNotifier
{
    public const INVENTORY_TYPES = [
        'inventory.low_stock', 'inventory.out_of_stock',
        'inventory.expiring_soon', 'inventory.expires_today', 'inventory.expired',
    ];

    public function preferences(Business $business): array
    {
        $preferences = array_replace([
            'telegram_enabled' => false,
            'low_stock' => true,
            'out_of_stock' => true,
            'expiring_soon' => true,
            'expired' => true,
            'reminder_frequency_hours' => 24,
            'recipients' => [],
        ], (array) ($business->notification_preferences ?? []));
        $preferences['recipients'] = array_values(array_filter((array) ($preferences['recipients'] ?? []), 'is_numeric'));
        return $preferences;
    }

    public function send(string|int $chatId, array $payload, ?Product $product, string $language = 'en'): array
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '') throw new \RuntimeException('Telegram bot is not configured.');

        $message = $this->message($payload, $language);
        $keyboard = $this->keyboard($payload['product_id'] ?? $product?->id, $payload['batch_id'] ?? null, $language);
        $baseUrl = "https://api.telegram.org/bot{$token}";
        $imageKey = $product?->image_storage_key;

        if ($imageKey && Storage::disk('local')->exists($imageKey)) {
            $stream = fopen(Storage::disk('local')->path($imageKey), 'rb');
            try {
                $response = Http::timeout(20)->attach('photo', $stream, basename($imageKey))->post($baseUrl.'/sendPhoto', [
                    'chat_id' => $chatId, 'caption' => $message, 'parse_mode' => 'HTML', 'reply_markup' => json_encode($keyboard, JSON_THROW_ON_ERROR),
                ]);
            } finally { if (is_resource($stream)) fclose($stream); }
            if ($response->successful() && $response->json('ok') === true) return ['message_id' => (string) $response->json('result.message_id'), 'response' => $response->json()];
            if ($response->status() !== 400) $this->throwTelegramFailure($response);
            Log::notice('Telegram product image send failed; falling back to text.', ['status' => $response->status()]);
        }

        $response = Http::timeout(20)->post($baseUrl.'/sendMessage', [
            'chat_id' => $chatId, 'text' => $message, 'parse_mode' => 'HTML', 'reply_markup' => $keyboard,
        ]);
        if (!$response->successful() || $response->json('ok') !== true) $this->throwTelegramFailure($response);
        return ['message_id' => (string) $response->json('result.message_id'), 'response' => $response->json()];
    }

    public function sendTest(User $user): array
    {
        $business = $user->business;
        $payload = ['type' => 'test', 'product' => $business->name, 'quantity' => 0, 'unit' => '', 'workspace' => $business->name];
        return $this->send($user->telegram_chat_id, $payload, null, $user->preferred_language ?: 'en');
    }

    private function legacyMessage(array $payload, string $language): string
    {
        $km = $language === 'km';
        $type = (string) ($payload['type'] ?? 'inventory.low_stock');
        if ($type === 'test') return $km ? "<b>KotTraLuy</b>\nការជូនដំណឹង Telegram សាកល្បងបានជោគជ័យ។" : "<b>KotTraLuy</b>\nTelegram test notification delivered successfully.";
        $severity = match ($type) {
            'inventory.out_of_stock' => $km ? 'អស់ពីស្តុក' : 'OUT OF STOCK',
            'inventory.expired' => $km ? 'ផុតកំណត់' : 'EXPIRED',
            'inventory.expires_today' => $km ? 'ផុតកំណត់ថ្ងៃនេះ' : 'EXPIRES TODAY',
            default => $km ? 'ត្រូវការយកចិត្តទុកដាក់' : 'ATTENTION',
        };
        $name = htmlspecialchars((string) ($payload['product'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $workspace = htmlspecialchars((string) ($payload['workspace'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $quantity = (string) ($payload['quantity'] ?? 0).' '.htmlspecialchars((string) ($payload['unit'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $batch = !empty($payload['batch_code']) ? ($km ? "\nលេខបាច់៖ {$payload['batch_code']}" : "\nBatch: {$payload['batch_code']}") : '';
        $expiry = !empty($payload['expiry_date']) ? ($km ? "\nថ្ងៃផុតកំណត់៖ {$payload['expiry_date']}" : "\nExpiration: {$payload['expiry_date']}") : '';
        $labels = $km ? "\nចំនួននៅសល់៖ {$quantity}\nអាជីវកម្ម៖ {$workspace}" : "\nRemaining: {$quantity}\nWorkspace: {$workspace}";
        return "<b>{$severity}</b>\n<b>{$name}</b>{$batch}{$expiry}{$labels}";
    }

    private function message(array $payload, string $language): string
    {
        if (isset($payload['type']) && $payload['type'] !== 'test' && !in_array($payload['type'], self::INVENTORY_TYPES, true)) {
            $messages = json_decode(file_get_contents(resource_path('notifications/'.($language === 'km' ? 'km' : 'en').'.json')), true, 512, JSON_THROW_ON_ERROR);
            $key = 'notifications.'.$payload['type'];
            $title = $messages[$key.'.title'] ?? $messages['notifications.fallback.title'];
            $body = $messages[$key.'.message'] ?? $messages['notifications.fallback.message'];
            $body = preg_replace_callback('/\{([a-z_]+)\}/', function ($match) use ($payload) {
                $value = $payload[$match[1]] ?? '';
                if (is_array($value)) $value = implode(', ', $value);
                if ($match[1] === 'amount') $value = number_format((float) $value, 2).' '.($payload['currency'] ?? '');
                return (string) $value;
            }, $body);
            $escape = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return "<b>KotTraLuy</b>\n<b>".$escape($title)."</b>\n".$escape($body)."\n".$escape($payload['workspace'] ?? '');
        }
        $km = $language === 'km';
        $type = (string) ($payload['type'] ?? 'inventory.low_stock');
        if ($type === 'test') return $km ? "<b>KotTraLuy</b>\nការជូនដំណឹង Telegram សាកល្បងបានជោគជ័យ។" : "<b>KotTraLuy</b>\nTelegram test notification delivered successfully.";
        $severity = match ($type) {
            'inventory.out_of_stock' => $km ? 'អស់ពីស្តុក' : 'OUT OF STOCK',
            'inventory.expired' => $km ? 'ផុតកំណត់' : 'EXPIRED',
            'inventory.expires_today' => $km ? 'ផុតកំណត់ថ្ងៃនេះ' : 'EXPIRES TODAY',
            'inventory.expiring_soon' => $km ? 'ជិតផុតកំណត់' : 'EXPIRING SOON',
            default => $km ? 'ស្តុកជិតអស់' : 'LOW STOCK',
        };
        $name = htmlspecialchars((string) ($payload['product'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $workspace = htmlspecialchars((string) ($payload['workspace'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $quantity = (string) ($payload['quantity'] ?? 0).' '.htmlspecialchars((string) ($payload['unit'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $batchCode = htmlspecialchars((string) ($payload['batch_code'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $expiryDate = htmlspecialchars((string) ($payload['expiry_date'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $batch = $batchCode !== '' ? ($km ? "\nលេខបាច់៖ {$batchCode}" : "\nBatch: {$batchCode}") : '';
        $expiry = $expiryDate !== '' ? ($km ? "\nថ្ងៃផុតកំណត់៖ {$expiryDate}" : "\nExpiration: {$expiryDate}") : '';
        $days = array_key_exists('days_remaining', $payload) && $payload['days_remaining'] !== null
            ? ($km ? "\nចំនួនថ្ងៃនៅសល់៖ {$payload['days_remaining']}" : "\nDays remaining: {$payload['days_remaining']}") : '';
        $labels = $km ? "\nចំនួននៅសល់៖ {$quantity}\nអាជីវកម្ម៖ {$workspace}" : "\nRemaining: {$quantity}\nWorkspace: {$workspace}";
        $title = $km ? '⚠️ ការជូនដំណឹងស្តុក KotTraLuy' : '⚠️ KotTraLuy Inventory Alert';
        return "<b>{$title}</b>\n<b>{$severity}</b>\n<b>{$name}</b>{$batch}{$expiry}{$days}{$labels}";
    }

    private function keyboard(int|string|null $productId, int|string|null $batchId, string $language = 'en'): array
    {
        $url = rtrim((string) config('services.telegram.mini_app_url', config('app.url')), '/');
        $query = http_build_query(array_filter(['inventory_product' => $productId, 'inventory_batch' => $batchId]));
        return [
            'inline_keyboard' => [[
                ['text' => $language === 'km' ? 'បើក KotTraLuy' : 'Open KotTraLuy', 'web_app' => ['url' => $url.($query ? '?'.$query : '')]],
            ]],
        ];
    }

    private function throwTelegramFailure(Response $response): never
    {
        $retryAfter = (int) $response->json('parameters.retry_after', 0);
        if ($response->status() === 429 || $retryAfter > 0) {
            throw new TelegramRateLimitException($retryAfter ?: 60);
        }
        if (in_array($response->status(), [400, 403], true)) {
            throw new TelegramPermanentFailureException((string) $response->json('description', 'Telegram rejected the chat.'));
        }
        throw new \RuntimeException('Telegram rejected the notification: '.(string) $response->json('description', 'Unknown error'));
    }
}

class TelegramRateLimitException extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter) { parent::__construct('Telegram rate limit reached.'); }
}

class TelegramPermanentFailureException extends \RuntimeException
{
}
