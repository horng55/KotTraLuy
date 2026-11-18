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
        $user->setAttribute('access_role', $user->accessRole());
        $user->setAttribute('permissions', $user->permissionList());

        return response()->json(app(\App\Services\WorkspaceDataVisibility::class)->filter([
            'token' => $token,
            'user' => $user->load('business')->toArray(),
        ], $user));
    }

    public function createCode(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'owner' && ! $user->telegram_enabled) {
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
        $user = $request->user()->fresh();
        $user->workspaceContext = $request->user()->workspaceContext;
        $chatId = $user->telegram_chat_id;

        return response()->json([
            'connected' => filled($chatId),
            'user_id' => $user->id,
            'workspace_id' => $user->business_id,
            'workspace_name' => $user->business?->name,
            'bot_username' => ltrim((string) config('services.telegram.bot_username', 'KotTraLuyBot'), '@'),
            'test_eligible' => $this->testAvailable() && filled($chatId) && $user->status === 'active' && ($user->role === 'owner' || $user->telegram_enabled),
            'telegram_chat' => $chatId === null ? null : $this->maskedChatId((string) $chatId),
        ])->header('Cache-Control', 'no-store, private');
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
        $preferences = $notifier->preferences($request->user()->business);
        return response()->json([...$preferences, 'preferred_language' => $preferences['preferred_language'] ?: $request->user()->preferred_language ?: 'en', 'test_available' => $this->testAvailable()]);
    }

    public function updateNotificationSettings(Request $request, TelegramInventoryNotifier $notifier)
    {
        abort_unless($request->user()->canDo('telegram.settings') && in_array($request->user()->accessRole(), ['owner', 'manager'], true), 403);
        $data = $request->validate([
            'telegram_enabled' => ['required', 'boolean'],
            'low_stock' => ['required', 'boolean'],
            'out_of_stock' => ['required', 'boolean'],
            'expiring_soon' => ['required', 'boolean'],
            'expired' => ['required', 'boolean'],
            'reminder_frequency_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'recipients' => ['nullable', 'array', 'max:100'],
            'recipients.*' => ['integer', 'distinct', function ($attribute, $value, $fail) use ($request) {
                $recipient = User::find($value);
                if (!$recipient || !$recipient->useWorkspace((int) $request->user()->business_id)) $fail('The recipient must be an active member of this workspace.');
            }],
            'preferred_language' => ['sometimes', 'in:en,km'],
        ]);
        $language = $data['preferred_language'] ?? $request->user()->preferred_language ?? 'en';
        $data['preferred_language'] = $language;
        $request->user()->forceFill(['preferred_language' => $language])->save();
        $request->user()->business->forceFill(['notification_preferences' => $data])->save();
        return response()->json([...$notifier->preferences($request->user()->business->fresh()), 'preferred_language' => $language, 'test_available' => $this->testAvailable()]);
    }

    public function sendTestNotification(Request $request, TelegramInventoryNotifier $notifier)
    {
        abort_unless($this->testAvailable(), 404, 'Telegram delivery is not configured.');
        $user = $request->user();
        abort_unless($user->status === 'active' && $user->telegram_chat_id && ($user->role === 'owner' || $user->telegram_enabled), 422, 'Connect Telegram before sending a test notification.');
        // Persist the actual API outcome, not merely a queued-job acknowledgement.
        $notification = \App\Models\WorkspaceNotification::create([
            'id' => (string) Str::uuid(), 'type' => 'telegram.test', 'business_id' => $user->business_id,
            'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
            'event_key' => 'telegram.test:'.Str::uuid(),
            'data' => ['key' => 'telegram.test', 'category' => 'system', 'params' => [], 'action_page' => 'telegram'],
            'read_at' => now(),
        ]);
        $delivery = \App\Models\TelegramNotificationDelivery::create([
            'notification_id' => $notification->id, 'user_id' => $user->id, 'business_id' => $user->business_id,
            'status' => 'pending', 'attempts' => 1, 'last_attempt_at' => now(),
        ]);
        try {
            $result = $notifier->sendTest($user);
            $delivery->update(['status' => 'sent', 'sent_at' => now(), 'telegram_message_id' => $result['message_id'], 'telegram_response' => $result['response'] ?? null]);
        } catch (\Throwable $failure) {
            $category = $failure instanceof \App\Services\TelegramRateLimitException ? 'rate_limited'
                : ($failure instanceof \App\Services\TelegramPermanentFailureException ? 'telegram_rejected' : 'transient');
            $message = match ($category) {
                'rate_limited' => 'Telegram rate limit reached. Please try again later.',
                'telegram_rejected' => 'Telegram rejected this chat. Open @KotTraLuyBot, unblock it and reconnect your account.',
                default => 'Telegram could not be reached or rejected delivery. Please try again later.',
            };
            $delivery->update(['status' => 'failed', 'error_category' => $category, 'last_error' => $message]);
            return response()->json(['accepted' => false, 'delivery_id' => $delivery->id, 'status' => 'failed', 'message' => $message], 502);
        }
        return response()->json(['accepted' => true, 'status' => 'sent', 'delivery_id' => $delivery->id, 'telegram_message_id' => $result['message_id']]);
    }

    private function testAvailable(): bool
    {
        return filled(config('services.telegram.bot_token'));
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

