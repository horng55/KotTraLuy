<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class TelegramMiniAppDataValidator
{
    public function validate(string $initData): array
    {
        $botToken = (string) config('services.telegram.bot_token');

        if ($botToken === '') {
            throw ValidationException::withMessages([
                'init_data' => 'Telegram Mini App authentication is unavailable.',
            ]);
        }

        parse_str($initData, $data);
        $receivedHash = (string) ($data['hash'] ?? '');
        unset($data['hash']);

        if ($receivedHash === '' || ! isset($data['auth_date'], $data['user'])) {
            throw ValidationException::withMessages([
                'init_data' => 'Telegram Mini App data is incomplete.',
            ]);
        }

        ksort($data, SORT_STRING);
        $dataCheckString = collect($data)
            ->map(fn ($value, $key) => $key.'='.$value)
            ->implode("\n");

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (! hash_equals($calculatedHash, $receivedHash)) {
            throw ValidationException::withMessages([
                'init_data' => 'Telegram Mini App authentication failed.',
            ]);
        }

        $authDate = filter_var($data['auth_date'], FILTER_VALIDATE_INT);
        $ttl = (int) config('services.telegram.mini_app_init_data_ttl', 600);

        if ($authDate === false || $authDate > now()->timestamp + 30 || now()->timestamp - $authDate > $ttl) {
            throw ValidationException::withMessages([
                'init_data' => 'Telegram Mini App authentication expired. Reopen the app from Telegram.',
            ]);
        }

        $user = json_decode((string) $data['user'], true);

        if (! is_array($user) || ! isset($user['id']) || ! is_numeric($user['id'])) {
            throw ValidationException::withMessages([
                'init_data' => 'Telegram user information is invalid.',
            ]);
        }

        $data['user'] = $user;

        return $data;
    }
}
