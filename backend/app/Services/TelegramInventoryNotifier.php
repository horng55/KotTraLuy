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
            'preferred_language' => null,
            'recipients' => [],
        ], (array) ($business->notification_preferences ?? []));
        $preferences['recipients'] = array_values(array_filter((array) ($preferences['recipients'] ?? []), 'is_numeric'));
        $preferences['expires_today'] = $preferences['expiring_soon'];
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
        return $this->send($user->telegram_chat_id, $payload, null, $this->preferences($business)['preferred_language'] ?: $user->preferred_language ?: 'en');
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
        if (($payload['type'] ?? '') === 'test') return "<b>KotTraLuy</b>\n".($km ? 'ការជូនដំណឹងសាកល្បង Telegram បានផ្ញើដោយជោគជ័យ។' : 'Telegram test notification delivered successfully.');
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $type = $payload['type'] ?? 'inventory.low_stock';
        $severity = match ($type) {
            'inventory.out_of_stock' => $km ? '🔴 អស់ពីស្តុក' : '🔴 OUT OF STOCK',
            'inventory.expired' => $km ? '🔴 ផុតកំណត់' : '🔴 EXPIRED',
            'inventory.expiring_soon', 'inventory.expires_today' => $km ? '🟠 ជិតផុតកំណត់' : '🟠 EXPIRING SOON',
            default => $km ? '🟡 ស្តុកជិតអស់' : '🟡 LOW STOCK',
        };
        $lines = ['<b>⚠️ KotTraLuy '.($km ? 'ការជូនដំណឹងស្តុក' : 'Inventory Alert').'</b>', '', '<b>'.$severity.'</b>', '', '📦 '.($km ? 'ផលិតផល' : 'Product').': <b>'.$escape($payload['product'] ?? '').'</b>'];
        $expiration = in_array($type, ['inventory.expired', 'inventory.expires_today', 'inventory.expiring_soon'], true);
        if ($expiration) {
            if (!empty($payload['batch_code'])) $lines[] = '🏷 '.($km ? 'បាច់' : 'Batch').': '.$escape($payload['batch_code']);
            if (!empty($payload['expiry_date'])) {
                $formatter = app(ExpirationCountdown::class);
                $date = $formatter->timestamp($payload['expiry_date'])->locale($km ? 'km' : 'en')->translatedFormat('d M Y');
                $lines[] = '📅 '.($km ? 'កាលបរិច្ឆេទផុតកំណត់' : 'Expiration').': '.$escape($date);
                $lines[] = '⏳ '.($km ? 'រយៈពេលនៅសល់' : 'Time remaining').': <b>'.$escape($formatter->format($payload['expiry_date'], $language)).'</b>';
            }
        }
        $lines[] = '📊 '.($km ? 'ចំនួនស្តុកនៅសល់' : 'Stock remaining').': '.$escape(($payload['quantity'] ?? 0).' '.($payload['unit'] ?? ''));
        $lines[] = '🏪 '.($km ? 'កន្លែងធ្វើការ' : 'Workspace').': '.$escape($payload['workspace'] ?? '');
        $lines[] = '';
        $lines[] = $km ? 'សូមពិនិត្យស្តុករបស់អ្នក។' : ($expiration ? 'Please review your inventory before selling this product.' : 'Please review your inventory and replenish stock.');
        return implode("\n", $lines);
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
