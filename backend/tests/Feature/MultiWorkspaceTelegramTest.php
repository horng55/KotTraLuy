<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramInventoryNotification;
use App\Models\{Business, Product, User, StockBatch, WorkspaceNotification, TelegramNotificationDelivery};
use App\Services\{InventoryAlertService, TelegramInventoryNotifier};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Config, Http, Queue};
use Tests\TestCase;

class MultiWorkspaceTelegramTest extends TestCase
{
    use RefreshDatabase;
    private int $telegramStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Config::set('services.telegram.bot_token', 'test-token');
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['ok' => $this->telegramStatus === 200, 'description' => 'Forbidden', 'result' => ['message_id' => 123]], $this->telegramStatus));
    }

    public function test_three_independent_registered_owners_link_opt_in_and_receive_only_their_inventory(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Config::set('services.telegram.webhook_secret', 'test-secret');
        $accounts = [];
        foreach (['A', 'B', 'C'] as $index => $letter) {
            $language = $letter === 'B' ? 'en' : 'km';
            $registration = $this->postJson('/api/register', [
                'name' => 'Owner '.$letter, 'email' => strtolower($letter).'@example.test',
                'password' => 'strong-test-password', 'business_name' => 'Workspace '.$letter, 'preferred_language' => $language,
            ])->assertCreated();
            $owner = User::findOrFail($registration->json('user.id'));
            $business = $owner->business;
            $this->assertSame(false, $business->notification_preferences['telegram_enabled']);
            $this->assertSame([$owner->id], $business->notification_preferences['recipients']);
            $this->assertSame($language, $business->notification_preferences['preferred_language']);
            $this->actingAs($owner, 'sanctum');
            $code = $this->postJson('/api/telegram/link-code')->assertOk()->json('code');
            $chatId = 10000 + $index;
            if ($index === 1) {
                $this->postJson('/api/telegram/webhook', [
                    'update_id' => 89999,
                    'message' => ['chat' => ['id' => 10000, 'type' => 'private'], 'text' => '/start link_'.$code],
                ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
                $this->assertNull($owner->fresh()->telegram_chat_id);
                $this->assertSame(10000, $accounts[0][0]->fresh()->telegram_chat_id);
            }
            $this->postJson('/api/telegram/webhook', [
                'update_id' => 90000 + $index,
                'message' => ['chat' => ['id' => $chatId, 'type' => 'private'], 'from' => ['id' => $chatId], 'text' => '/start link_'.$code],
            ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
            $this->assertSame($chatId, $owner->fresh()->telegram_chat_id);
            $this->actingAs($owner->fresh(), 'sanctum');
            $this->getJson('/api/telegram/status')->assertOk()->assertJsonPath('workspace_id', $business->id)->assertJsonPath('connected', true);
            $this->putJson('/api/telegram/notification-settings', [
                'telegram_enabled' => true, 'low_stock' => true, 'out_of_stock' => true, 'expiring_soon' => true,
                'expired' => true, 'reminder_frequency_hours' => 24, 'recipients' => [$owner->id], 'preferred_language' => $language,
            ])->assertOk();
            $test = $this->postJson('/api/telegram/test-notification')->assertOk()->assertJsonPath('status', 'sent');
            $this->assertDatabaseHas('telegram_notification_deliveries', ['id' => $test->json('delivery_id'), 'user_id' => $owner->id, 'business_id' => $business->id, 'status' => 'sent']);
            // Register the next account without retaining the previous identity.
            $this->app['auth']->forgetGuards();
            $accounts[] = [$owner, $business, $chatId];
        }
        $this->assertCount(3, array_unique(array_map(fn ($a) => $a[1]->id, $accounts)));
        Queue::fake();
        foreach ($accounts as [$owner, $business]) {
            Product::factory()->create(['business_id' => $business->id, 'name' => 'Shared low', 'stock_quantity' => 1]);
            Product::factory()->create(['business_id' => $business->id, 'name' => 'Shared empty', 'stock_quantity' => 0]);
            $expiring = Product::factory()->create(['business_id' => $business->id, 'name' => 'Shared expiry', 'stock_quantity' => 20]);
            StockBatch::create(['business_id' => $business->id, 'product_id' => $expiring->id, 'batch_code' => 'SAME-BATCH',
                'received_date' => now()->toDateString(), 'quantity_received' => 20, 'quantity_remaining' => 20,
                'unit_cost' => 1, 'expiry_date' => now()->addDay()->toDateString()]);
            $expired = Product::factory()->create(['business_id' => $business->id, 'name' => 'Shared expired', 'stock_quantity' => 20]);
            StockBatch::create(['business_id' => $business->id, 'product_id' => $expired->id, 'batch_code' => 'EXPIRED-BATCH',
                'received_date' => now()->subDays(5)->toDateString(), 'quantity_received' => 20, 'quantity_remaining' => 20,
                'unit_cost' => 1, 'expiry_date' => now()->subDay()->toDateString()]);
        }
        // Console execution has no authenticated browser/Mini App dependency.
        $this->app['auth']->forgetGuards();
        $this->artisan('inventory:refresh-alerts')->assertSuccessful();
        $jobs = Queue::pushed(SendTelegramInventoryNotification::class);
        $this->assertCount(15, $jobs);
        foreach ($jobs as $job) $job->handle(app(TelegramInventoryNotifier::class));
        foreach ($accounts as [$owner, $business, $chatId]) {
            $this->assertSame(5, TelegramNotificationDelivery::where('business_id', $business->id)->where('user_id', $owner->id)
                ->whereHas('notification', fn ($q) => $q->where('type', 'like', 'inventory.%'))->where('status', 'sent')->count());
            Http::assertSent(fn ($request) => $request['chat_id'] === $chatId && str_contains($request['text'] ?? '', $business->name));
            foreach ($accounts as [$otherOwner, $otherBusiness, $otherChat]) {
                if ($chatId !== $otherChat) Http::assertNotSent(fn ($request) => $request['chat_id'] === $chatId && str_contains($request['text'] ?? '', $otherBusiness->name));
            }
        }
        Queue::fake();
        $this->artisan('inventory:refresh-alerts')->assertSuccessful();
        $this->artisan('telegram:remind-inventory')->assertSuccessful();
        Queue::assertNotPushed(SendTelegramInventoryNotification::class);
    }

    public function test_backfill_fills_only_missing_settings_and_preserves_consent_and_recipients(): void
    {
        $business = Business::factory()->create();
        $owner = User::withoutEvents(fn () => User::factory()->create(['business_id' => $business->id, 'role' => 'owner', 'status' => 'active', 'preferred_language' => 'km']));
        $custom = Business::factory()->create(['notification_preferences' => ['telegram_enabled' => true, 'low_stock' => false, 'recipients' => [], 'reminder_frequency_hours' => 6]]);
        $this->artisan('notifications:initialize-workspaces', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($business->fresh()->notification_preferences);
        $this->artisan('notifications:initialize-workspaces')->assertSuccessful();
        $first = $business->fresh()->notification_preferences;
        $this->assertFalse($first['telegram_enabled']);
        $this->assertSame([$owner->id], $first['recipients']);
        $this->assertSame('km', $first['preferred_language']);
        $this->assertSame([], $custom->fresh()->notification_preferences['recipients']);
        $this->assertTrue($custom->fresh()->notification_preferences['telegram_enabled']);
        $this->assertFalse($custom->fresh()->notification_preferences['low_stock']);
        $this->assertSame(6, $custom->fresh()->notification_preferences['reminder_frequency_hours']);
        $this->artisan('notifications:initialize-workspaces')->assertSuccessful();
        $this->assertSame($first, $business->fresh()->notification_preferences);
    }

    public function test_test_notification_records_failure_and_never_reports_queued_as_delivered(): void
    {
        $business = Business::factory()->create();
        $owner = User::withoutEvents(fn () => User::factory()->create(['business_id' => $business->id, 'role' => 'owner', 'status' => 'active', 'telegram_chat_id' => 123]));
        $this->telegramStatus = 403;
        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/telegram/test-notification')
            ->assertStatus(502)->assertJsonPath('accepted', false)->assertJsonPath('status', 'failed');
        $this->assertDatabaseHas('telegram_notification_deliveries', ['id' => $response->json('delivery_id'), 'user_id' => $owner->id, 'business_id' => $owner->business_id, 'status' => 'failed', 'error_category' => 'telegram_rejected']);
    }

    public function test_workspace_refresh_failure_does_not_stop_the_other_workspaces(): void
    {
        $businesses = Business::factory()->count(3)->create();
        $seen = [];
        $service = \Mockery::mock(InventoryAlertService::class);
        $service->shouldReceive('refresh')->times(3)->andReturnUsing(function ($business) use (&$seen, $businesses) {
            $seen[] = $business->id;
            if ($business->id === $businesses[0]->id) throw new \RuntimeException('Fixture failure');
        });
        $this->app->instance(InventoryAlertService::class, $service);
        $this->artisan('inventory:refresh-alerts')->assertFailed();
        $this->assertSame($businesses->pluck('id')->all(), $seen);
    }

    public function test_reminder_failure_is_isolated_to_its_workspace(): void
    {
        $businesses = Business::factory()->count(3)->create();
        $seen = [];
        $notifier = \Mockery::mock(TelegramInventoryNotifier::class);
        $notifier->shouldReceive('preferences')->times(3)->andReturnUsing(function ($business) use (&$seen, $businesses) {
            $seen[] = $business->id;
            if ($business->id === $businesses[0]->id) throw new \RuntimeException('Fixture failure');
            return ['telegram_enabled' => false];
        });
        $this->app->instance(TelegramInventoryNotifier::class, $notifier);
        $this->artisan('telegram:remind-inventory')->assertFailed();
        $this->assertSame($businesses->pluck('id')->all(), $seen);
    }

    public function test_same_event_key_is_deduplicated_per_workspace_and_recipient(): void
    {
        $users = [];
        foreach ([1, 2, 3] as $number) {
            $business = Business::factory()->create(['notification_preferences' => ['telegram_enabled' => true]]);
            $owner = User::withoutEvents(fn () => User::factory()->create(['business_id' => $business->id, 'role' => 'owner', 'status' => 'active', 'telegram_chat_id' => 50000 + $number]));
            $users[] = $owner;
            foreach ([1, 2] as $_) app(\App\Services\WorkspaceNotifier::class)->publish($business->id, 'inventory.low_stock', 'identical-event', ['product' => 'Same name', 'quantity' => 1]);
        }
        $this->assertSame(3, WorkspaceNotification::where('event_key', 'identical-event')->count());
        Queue::assertPushed(SendTelegramInventoryNotification::class, 3);
        $this->assertEqualsCanonicalizing(array_map(fn ($user) => $user->id, $users), TelegramNotificationDelivery::pluck('user_id')->all());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('preConnectionDeliveryStates')]
    public function test_alert_created_before_linking_is_delivered_once_after_owner_opts_in(bool $legacySkipped): void

    {
        $business = Business::factory()->create();
        $owner = User::withoutEvents(fn () => User::factory()->create(['business_id' => $business->id, 'role' => 'owner', 'status' => 'active', 'telegram_chat_id' => null]));
        Product::factory()->create(['business_id' => $business->id, 'stock_quantity' => 1, 'minimum_stock' => 5]);
        app(InventoryAlertService::class)->refresh($business);
        foreach (TelegramNotificationDelivery::all() as $delivery) {
            (new SendTelegramInventoryNotification($delivery->id))->handle(app(TelegramInventoryNotifier::class));
        }
        if ($legacySkipped) {
            $notification = WorkspaceNotification::where('business_id', $business->id)->where('type', 'inventory.low_stock')->sole();
            TelegramNotificationDelivery::firstOrCreate(['notification_id' => $notification->id, 'user_id' => $owner->id],
                ['business_id' => $business->id, 'status' => 'skipped']);
        }
        Queue::fake();
        $owner->forceFill(['telegram_chat_id' => 111])->save();
        Queue::fake();
        $business->update(['notification_preferences' => ['telegram_enabled' => true, 'recipients' => [$owner->id]]]);
        app(InventoryAlertService::class)->refresh($business->fresh());
        Queue::assertPushed(SendTelegramInventoryNotification::class, 1);
        foreach (Queue::pushed(SendTelegramInventoryNotification::class) as $queued) {
            $queued->handle(app(TelegramInventoryNotifier::class));
        }
        Queue::fake();
        app(InventoryAlertService::class)->refresh($business->fresh());
        Queue::assertNotPushed(SendTelegramInventoryNotification::class);
        Http::assertSentCount(1);
    }

    public static function preConnectionDeliveryStates(): array
    {
        return ['not queued before consent' => [false], 'legacy skipped delivery' => [true]];
    }
}
