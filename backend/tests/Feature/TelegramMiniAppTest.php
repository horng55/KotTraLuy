<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramMiniAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_user_can_authenticate_from_valid_telegram_init_data(): void
    {
        Config::set('services.telegram.bot_token', 'test-token');
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'telegram_chat_id' => 7654321,
        ]);

        $response = $this->postJson('/api/telegram/mini-app/auth', [
            'init_data' => $this->validInitData(['id' => 7654321, 'first_name' => 'Mini']),
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['token', 'user']);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'telegram-mini-app',
        ]);
    }

    public function test_mini_app_rejects_invalid_or_expired_init_data(): void
    {
        Config::set('services.telegram.bot_token', 'test-token');

        $this->postJson('/api/telegram/mini-app/auth', [
            'init_data' => 'auth_date='.now()->timestamp.'&user=%7B%22id%22%3A7654321%7D&hash=invalid',
        ])->assertUnprocessable();

        $this->postJson('/api/telegram/mini-app/auth', [
            'init_data' => $this->validInitData(
                ['id' => 7654321, 'first_name' => 'Mini'],
                now()->subMinutes(11)->timestamp
            ),
        ])->assertUnprocessable();
    }

    public function test_valid_but_unlinked_telegram_user_cannot_enter_mini_app(): void
    {
        Config::set('services.telegram.bot_token', 'test-token');

        $this->postJson('/api/telegram/mini-app/auth', [
            'init_data' => $this->validInitData(['id' => 999999, 'first_name' => 'Unknown']),
        ])->assertForbidden()
            ->assertJsonPath('code', 'telegram_not_linked');
    }

    public function test_configure_command_sets_stable_webhook_and_mini_app_urls(): void
    {
        Config::set('app.url', 'https://kottraluy.tech');
        Config::set('services.telegram.bot_token', 'test-token');
        Config::set('services.telegram.webhook_secret', 'test-secret');
        Config::set('services.telegram.mini_app_url', 'https://kottraluy.tech');
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->artisan('telegram:configure')->assertSuccessful();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === 'https://kottraluy.tech/api/telegram/webhook'
            && $request['secret_token'] === 'test-secret');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setChatMenuButton')
            && $request['menu_button']['web_app']['url'] === 'https://kottraluy.tech');
    }

    private function validInitData(array $telegramUser, ?int $authDate = null): string
    {
        $data = [
            'auth_date' => (string) ($authDate ?? now()->timestamp),
            'query_id' => 'AAHdF6IQAAAAAN0XohDhrOrc',
            'user' => json_encode($telegramUser, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
        ksort($data, SORT_STRING);
        $dataCheckString = collect($data)
            ->map(fn ($value, $key) => $key.'='.$value)
            ->implode("\n");
        $secretKey = hash_hmac('sha256', 'test-token', 'WebAppData', true);
        $data['hash'] = hash_hmac('sha256', $dataCheckString, $secretKey);

        return http_build_query($data, '', '&', PHP_QUERY_RFC3986);
    }
}
