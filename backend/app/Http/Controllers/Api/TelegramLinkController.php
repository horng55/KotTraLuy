<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\TelegramMiniAppDataValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\TelegramInventoryNotifier;

class TelegramLinkController
{
    public function authenticateMiniApp(Request $request, TelegramMiniAppDataValidator $validator)
    {
        $validated = $request->validate([
            'init_data' => ['required', 'string', 'max:8192'],
        ]);
        $telegramData = $validator->validate($validated['init_data']);
        $telegramUserId = (string) $telegramData['user']['id'];

        $user = User::where('telegram_chat_id', $telegramUserId)
            ->where('status', 'active')
            ->where('must_change_password', false)
            ->where(function ($query) {
                $query->where('role', 'owner')->orWhere('telegram_enabled', true);
            })
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'This Telegram account is not connected to KotTraLuy. Sign in on the website and connect Telegram first.',
                'code' => 'telegram_not_linked',
            ], 403);
        }

        $user->tokens()->where('name', 'telegram-mini-app')->delete();
        $token = $user->createToken('telegram-mini-app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->load('business'),
        ]);
    }

    public function createCode(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'staff' && ! $user->telegram_enabled) {
            return response()->json([
                'message' => 'Your owner has not enabled Telegram access for your account.',
                'code' => 'telegram_access_disabled',
            ], 403);
        }

        if ($user->telegram_chat_id !== null) {
            return response()->json([
                'message' => 'A Telegram chat is already linked to this account.',
            ], 422);
        }

        $code = Str::random(48);
        $expiresAt = now()->addMinutes(5);

        DB::transaction(function () use ($user, $code, $expiresAt) {
            DB::table('telegram_link_codes')
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            DB::table('telegram_link_codes')->insert([
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $code),
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'code' => $code,
            'expires_at' => $expiresAt->toIso8601String(),
            'command' => '/link '.$code,
            'bot_url' => $this->botUrl($code),
        ]);
    }

    public function status(Request $request)
    {
        $user = $request->user();
        $chatId = $user->telegram_chat_id;

        return response()->json([
            'connected' => $chatId !== null,
            'telegram_chat' => $chatId === null ? null : $this->maskedChatId((string) $chatId),
        ]);
    }

    public function disconnect(Request $request)
    {
        $disconnected = DB::transaction(function () use ($request) {
            $user = $request->user()->newQuery()
                ->whereKey($request->user()->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $wasConnected = $user->telegram_chat_id !== null;

            if ($wasConnected) {
                $user->forceFill(['telegram_chat_id' => null])->save();
            }

            DB::table('telegram_link_codes')
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->update([
                    'consumed_at' => now(),
                    'updated_at' => now(),
                ]);

            return $wasConnected;
        });

        return response()->json([
            'message' => $disconnected
                ? 'Telegram disconnected successfully.'
                : 'Telegram was not connected.',
            'connected' => false,
        ]);
    }

    public function notificationSettings(Request $request, TelegramInventoryNotifier $notifier)
    {
        return response()->json([...$notifier->preferences($request->user()->business), 'preferred_language' => $request->user()->preferred_language ?: 'en']);
    }

    public function updateNotificationSettings(Request $request, TelegramInventoryNotifier $notifier)
    {
        abort_unless($request->user()->role === 'owner', 403);
        $data = $request->validate([
            'telegram_enabled' => ['required', 'boolean'],
            'low_stock' => ['required', 'boolean'],
            'out_of_stock' => ['required', 'boolean'],
            'expiring_soon' => ['required', 'boolean'],
            'expired' => ['required', 'boolean'],
            'reminder_frequency_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'recipients' => ['nullable', 'array', 'max:100'],
            'recipients.*' => ['integer', 'distinct', \Illuminate\Validation\Rule::exists('users', 'id')->where(fn ($query) => $query->where('business_id', $request->user()->business_id)->where('status', 'active'))],
            'preferred_language' => ['sometimes', 'in:en,km'],
        ]);
        $language = $data['preferred_language'] ?? $request->user()->preferred_language ?? 'en';
        unset($data['preferred_language']);
        $request->user()->forceFill(['preferred_language' => $language])->save();
        $request->user()->business->forceFill(['notification_preferences' => $data])->save();
        return response()->json([...$notifier->preferences($request->user()->business->fresh()), 'preferred_language' => $language]);
    }

    public function sendTestNotification(Request $request, TelegramInventoryNotifier $notifier)
    {
        $user = $request->user();
        abort_unless($user->telegram_chat_id && ($user->role === 'owner' || $user->telegram_enabled), 422, 'Connect Telegram before sending a test notification.');
        $result = $notifier->sendTest($user);
        return response()->json(['accepted' => true, 'telegram_message_id' => $result['message_id']]);
    }

    private function botUrl(string $code): string
    {
        $username = ltrim((string) config('services.telegram.bot_username', 'KotTraLuyBot'), '@');

        return 'https://t.me/'.rawurlencode($username).'?start='.rawurlencode('link_'.$code);
    }

    private function maskedChatId(string $chatId): string
    {
        $visible = substr($chatId, -4);

        return str_repeat("\u{2022}", max(0, strlen($chatId) - strlen($visible))).$visible;
    }
}

