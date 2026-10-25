<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\TelegramMiniAppDataValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramLinkController
{
    public function authenticateMiniApp(Request $request, TelegramMiniAppDataValidator $validator)
    {
        $validated = $request->validate([
            'init_data' => ['required', 'string', 'max:8192'],
        ]);
        $telegramData = $validator->validate($validated['init_data']);
        $telegramUserId = (string) $telegramData['user']['id'];

        $user = User::where('telegram_chat_id', $telegramUserId)->first();

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
            'user' => $user,
        ]);
    }

    public function createCode(Request $request)
    {
        $user = $request->user();

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

