<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BizTrackTelegramBotTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_sale_command_updates_stock(): void
    {
        Config::set('services.telegram.webhook_secret', 'test-secret');
        Config::set('services.telegram.bot_token', 'test-token');
        Http::fake();

        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'staff',
            'telegram_chat_id' => 123456,
            'telegram_enabled' => true,
        ]);
        Shift::factory()->create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'shift_type' => 'A',
            'status' => 'open',
        ]);

        $product = Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Milk',
            'sku' => 'MILK-01',
            'selling_price' => 3.50,
            'default_cost' => 1.20,
            'stock_quantity' => 10,
        ]);

        $response = $this->postJson('/api/telegram/webhook', [
            'update_id' => 987654321,
            'message' => [
                'chat' => [
                    'id' => 123456,
                    'type' => 'private',
                ],
                'text' => 'sale Milk 4 3.5',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('sales', ['business_id' => $business->id]);
        $this->assertEquals(6, $product->fresh()->stock_quantity);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 987654321,
            'message' => [
                'chat' => ['id' => 123456, 'type' => 'private'],
                'text' => 'sale Milk 4 3.5',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
        ])->assertOk()->assertJsonPath('duplicate', true);

        $this->assertDatabaseCount('sales', 1);
        $this->assertEquals(6, $product->fresh()->stock_quantity);
        Http::assertSentCount(1);
    }

    public function test_telegram_purchase_and_waste_commands_update_stock(): void
    {
        Config::set('services.telegram.webhook_secret', 'test-secret');
        Config::set('services.telegram.bot_token', 'test-token');
        Http::fake();

        $business = Business::factory()->create();
        User::factory()->create([
            'business_id' => $business->id,
            'telegram_chat_id' => 123456,
            'telegram_enabled' => true,
        ]);
        $product = Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Milk',
            'sku' => 'MILK-01',
            'stock_quantity' => 10,
        ]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 987654323,
            'message' => [
                'chat' => ['id' => 123456, 'type' => 'private'],
                'text' => 'purchase Milk 5 1.2',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
        ])->assertOk();

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 987654324,
            'message' => [
                'chat' => ['id' => 123456, 'type' => 'private'],
                'text' => 'waste Milk 2 expired',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
        ])->assertOk();

        $this->assertSame(13, $product->fresh()->stock_quantity);
        $this->assertDatabaseHas('purchases', ['business_id' => $business->id]);
        $this->assertDatabaseHas('waste_records', [
            'business_id' => $business->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'reason' => 'expired',
        ]);
        Http::assertSentCount(2);
    }

    public function test_user_can_link_telegram_with_a_one_time_code(): void
    {
        Config::set('services.telegram.webhook_secret', 'test-secret');
        Config::set('services.telegram.bot_token', 'test-token');
        Http::fake();

        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
        ]);

        $codeResponse = $this->actingAs($user, 'sanctum')
            ->postJson('/api/telegram/link-code')
            ->assertOk()
            ->assertJsonStructure(['code', 'expires_at', 'command', 'bot_url']);

        $code = $codeResponse->json('code');
        $this->assertSame(48, strlen($code));
        $this->assertSame('/link '.$code, $codeResponse->json('command'));
        $this->assertStringStartsWith('https://t.me/KotTraLuyBot?start=link_', $codeResponse->json('bot_url'));

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 123456789,
            'message' => [
                'chat' => ['id' => 7654321, 'type' => 'private'],
                'text' => '/link '.$code,
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
        ])->assertOk();

        $this->assertSame(7654321, $user->fresh()->telegram_chat_id);
        $this->assertDatabaseHas('telegram_link_codes', [
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $code),
        ]);
        $this->assertNotNull(
            DB::table('telegram_link_codes')
                ->where('token_hash', hash('sha256', $code))
                ->value('consumed_at')
        );
        $this->assertNotNull($user->fresh()->telegram_chat_id);
    }

    public function test_link_code_requires_authentication_and_expires_after_five_minutes(): void
    {
        $this->postJson('/api/telegram/link-code')->assertUnauthorized();

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->freezeTime(function () use ($user) {
            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/telegram/link-code')
                ->assertOk();

            $this->assertSame(
                now()->addMinutes(5)->toIso8601String(),
                $response->json('expires_at')
            );
        });
    }

    public function test_generating_a_new_code_invalidates_the_previous_code(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $firstCode = $this->actingAs($user, 'sanctum')
            ->postJson('/api/telegram/link-code')
            ->assertOk()
            ->json('code');

        $secondCode = $this->actingAs($user, 'sanctum')
            ->postJson('/api/telegram/link-code')
            ->assertOk()
            ->json('code');

        $this->assertNotSame($firstCode, $secondCode);
        $this->assertNotNull(DB::table('telegram_link_codes')
            ->where('token_hash', hash('sha256', $firstCode))
            ->value('consumed_at'));
        $this->assertNull(DB::table('telegram_link_codes')
            ->where('token_hash', hash('sha256', $secondCode))
            ->value('consumed_at'));
    }

    public function test_expired_incorrect_and_reused_tokens_cannot_link(): void
    {
        Config::set('services.telegram.webhook_secret', 'test-secret');
        Config::set('services.telegram.bot_token', 'test-token');
        Http::fake();

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $expiredCode = Str::random(48);
        $reusedCode = Str::random(48);

        DB::table('telegram_link_codes')->insert([
            [
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $expiredCode),
                'expires_at' => now()->subSecond(),
                'consumed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $reusedCode),
                'expires_at' => now()->addMinutes(5),
                'consumed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        foreach ([$expiredCode, Str::random(48), $reusedCode] as $index => $code) {
            $this->postJson('/api/telegram/webhook', [
                'update_id' => 200 + $index,
                'message' => [
                    'chat' => ['id' => 9000 + $index, 'type' => 'private'],
                    'text' => '/link '.$code,
                ],
            ], [
                'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
            ])->assertOk();
        }

        $this->assertNull($user->fresh()->telegram_chat_id);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => str_contains(
            $request['text'],
            'invalid or expired'
        ));
    }

    public function test_telegram_account_cannot_be_linked_to_multiple_users(): void
    {
        Config::set('services.telegram.webhook_secret', 'test-secret');
        Config::set('services.telegram.bot_token', 'test-token');
        Http::fake();

        $business = Business::factory()->create();
        User::factory()->create([
            'business_id' => $business->id,
            'telegram_chat_id' => 555000,
        ]);
        $secondUser = User::factory()->create(['business_id' => $business->id]);
        $code = $this->actingAs($secondUser, 'sanctum')
            ->postJson('/api/telegram/link-code')
            ->assertOk()
            ->json('code');

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 301,
            'message' => [
                'chat' => ['id' => 555000, 'type' => 'private'],
                'text' => '/link '.$code,
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
        ])->assertOk();

        $this->assertNull($secondUser->fresh()->telegram_chat_id);
        $this->assertNull(DB::table('telegram_link_codes')
            ->where('token_hash', hash('sha256', $code))
            ->value('consumed_at'));
        Http::assertSent(fn ($request) => str_contains(
            $request['text'],
            'already connected to another KotTraLuy account'
        ));
    }

    public function test_start_does_not_consume_a_link_token(): void
    {
        Config::set('services.telegram.webhook_secret', 'test-secret');
        Config::set('services.telegram.bot_token', 'test-token');
        Http::fake();

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $code = $this->actingAs($user, 'sanctum')
            ->postJson('/api/telegram/link-code')
            ->assertOk()
            ->json('code');

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 302,
            'message' => [
                'chat' => ['id' => 81234, 'type' => 'private'],
                'text' => '/start link_'.$code,
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
        ])->assertOk();

        $this->assertNull($user->fresh()->telegram_chat_id);
        $this->assertNull(DB::table('telegram_link_codes')
            ->where('token_hash', hash('sha256', $code))
            ->value('consumed_at'));
        Http::assertSent(fn ($request) => $request['text'] === "To finish connecting your KotTraLuy account, send this command:\n/link {$code}");
    }

    public function test_user_can_view_status_and_disconnect_only_their_own_telegram_account(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'telegram_chat_id' => 123456789,
        ]);
        $otherUser = User::factory()->create([
            'business_id' => $business->id,
            'telegram_chat_id' => 987654321,
        ]);
        DB::table('telegram_link_codes')->insert([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', Str::random(48)),
            'expires_at' => now()->addMinutes(5),
            'consumed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/telegram/status')
            ->assertOk()
            ->assertJson([
                'connected' => true,
                'telegram_chat' => '•••••6789',
            ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/telegram/link')
            ->assertOk()
            ->assertJson(['connected' => false]);

        $this->assertNull($user->fresh()->telegram_chat_id);
        $this->assertSame(987654321, $otherUser->fresh()->telegram_chat_id);
        $this->assertNotNull(DB::table('telegram_link_codes')
            ->where('user_id', $user->id)
            ->value('consumed_at'));
    }

    public function test_telegram_webhook_rejects_missing_or_invalid_secret(): void
    {
        Config::set('services.telegram.webhook_secret', 'test-secret');
        Config::set('services.telegram.bot_token', 'test-token');

        $payload = ['update_id' => 42];

        $this->postJson('/api/telegram/webhook', $payload)
            ->assertForbidden();

        $this->postJson('/api/telegram/webhook', $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret',
        ])->assertForbidden();

        Config::set('services.telegram.webhook_secret', null);
        $this->postJson('/api/telegram/webhook', $payload)
            ->assertStatus(503);
    }

    public function test_telegram_bot_ignores_group_chats(): void
    {
        Config::set('services.telegram.webhook_secret', 'test-secret');
        Config::set('services.telegram.bot_token', 'test-token');
        Http::fake();

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $code = $this->actingAs($user, 'sanctum')
            ->postJson('/api/telegram/link-code')
            ->assertOk()
            ->json('code');

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 987654322,
            'message' => [
                'chat' => ['id' => -123456, 'type' => 'group'],
                'text' => '/link '.$code,
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
        ])->assertOk();

        Http::assertNothingSent();
        $this->assertNull($user->fresh()->telegram_chat_id);
        $this->assertNull(DB::table('telegram_link_codes')
            ->where('token_hash', hash('sha256', $code))
            ->value('consumed_at'));
    }
}
