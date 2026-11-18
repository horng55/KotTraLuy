<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramInventoryNotification;
use App\Models\{Business, Product, StockBatch, TelegramNotificationDelivery, User, WorkspaceNotification};
use App\Services\{InventoryAlertService, TelegramInventoryNotifier};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramInventoryNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_persist_and_connection_status_belongs_to_current_account(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Queue::fake();
        Config::set('services.telegram.bot_token', 'test-token');
        Config::set('services.telegram.bot_username', 'KotTraLuyBot');
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 42]]));
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id, 'role' => 'owner', 'status' => 'active', 'telegram_chat_id' => null]);
        $recipient = User::factory()->create(['business_id' => $business->id, 'status' => 'active', 'telegram_chat_id' => 12345]);
        $settings = ['telegram_enabled' => true, 'low_stock' => false, 'out_of_stock' => true, 'expiring_soon' => true, 'expired' => false, 'reminder_frequency_hours' => 6, 'recipients' => [$recipient->id], 'preferred_language' => 'km'];
        $this->actingAs($owner, 'sanctum')->putJson('/api/telegram/notification-settings', $settings)->assertOk();
        $this->getJson('/api/telegram/notification-settings')->assertOk()->assertJsonFragment($settings);
        $this->assertSame('km', $owner->fresh()->preferred_language);
        $this->assertSame([$recipient->id], $business->fresh()->notification_preferences['recipients']);
        $this->getJson('/api/telegram/status')->assertOk()->assertJsonPath('connected', false)->assertJsonPath('test_eligible', false)->assertJsonPath('user_id', $owner->id)->assertJsonPath('workspace_id', $business->id)->assertJsonPath('bot_username', 'KotTraLuyBot');
        $this->postJson('/api/telegram/test-notification')->assertStatus(422);
        $owner->forceFill(['telegram_chat_id' => 67890])->save();
        $this->getJson('/api/telegram/status')->assertOk()->assertJsonPath('connected', true)->assertJsonPath('test_eligible', true);
        $this->postJson('/api/telegram/test-notification')->assertOk()->assertJsonPath('accepted', true);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['chat_id'] === 67890 && str_contains($request['text'], 'សាកល្បង'));
        $outsider = User::factory()->create();
        $this->putJson('/api/telegram/notification-settings', [...$settings, 'recipients' => [$outsider->id]])->assertStatus(422);
    }

    public function test_inventory_message_escapes_html_and_keeps_the_mini_app_button(): void
    {
        Config::set('services.telegram.bot_token', 'test-token');
        Config::set('services.telegram.mini_app_url', 'https://demo.example.test/mini');
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 43]]));
        $this->travelTo(\Carbon\Carbon::parse('2026-11-17 16:00:00', 'Asia/Phnom_Penh'));
        app(TelegramInventoryNotifier::class)->send(123, ['type' => 'inventory.expiring_soon', 'product' => '<Coca & Cola>', 'batch_code' => '<OPEN-45>', 'expiry_date' => '2026-11-18', 'days_remaining' => 1.291666666667, 'quantity' => 200, 'unit' => '<pcs>', 'workspace' => '<Tovnah>', 'product_id' => 5], null);
        Http::assertSent(fn ($request) => str_contains($request['text'], '🟠 EXPIRING SOON') && str_contains($request['text'], '&lt;Coca &amp; Cola&gt;') && str_contains($request['text'], '&lt;OPEN-45&gt;') && str_contains($request['text'], '1 day, 7 hours') && !str_contains($request['text'], '1.291666') && $request['parse_mode'] === 'HTML' && $request['reply_markup']['inline_keyboard'][0][0]['web_app']['url'] === 'https://demo.example.test/mini?inventory_product=5');
        $this->travelBack();
    }

    public function test_shared_notifications_are_mirrored_to_linked_users_once_and_security_stays_private(): void
    {
        Queue::fake();
        Config::set('services.telegram.bot_token', 'test-token');
        Config::set('services.telegram.mini_app_url', 'https://demo.example.test');
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 101]]));
        $business = Business::factory()->create();
        $owner = User::withoutEvents(fn () => User::factory()->create(['business_id' => $business->id, 'role' => 'owner', 'status' => 'active', 'telegram_chat_id' => 111]));
        $staff = User::withoutEvents(fn () => User::factory()->create(['business_id' => $business->id, 'role' => 'staff', 'status' => 'active', 'telegram_enabled' => true, 'telegram_chat_id' => 222]));
        $unlinked = User::withoutEvents(fn () => User::factory()->create(['business_id' => $business->id, 'role' => 'staff', 'status' => 'active']));
        foreach ([$staff, $unlinked] as $member) {
            \Illuminate\Support\Facades\DB::table('business_user')->insert(['business_id' => $business->id, 'user_id' => $member->id, 'role' => 'staff', 'permissions' => json_encode(['sales.read.all'])]);
        }
        $notifier = app(\App\Services\WorkspaceNotifier::class);
        $params = ['actor' => '<Staff>', 'reference' => 'SALE-1', 'amount' => 25, 'currency' => 'USD'];
        $notifier->publish($business->id, 'sale.created', 'mirror-sale', $params);
        $notifier->publish($business->id, 'sale.created', 'mirror-sale', $params);
        $alerts = WorkspaceNotification::where('event_key', 'mirror-sale')->get();
        $this->assertCount(3, $alerts);
        $deliveries = TelegramNotificationDelivery::whereIn('notification_id', $alerts->pluck('id'))->get();
        $this->assertEqualsCanonicalizing([$owner->id, $staff->id], $deliveries->pluck('user_id')->all());
        foreach ($deliveries as $delivery) {
            $job = new SendTelegramInventoryNotification($delivery->id);
            $job->handle(app(TelegramInventoryNotifier::class));
            $job->handle(app(TelegramInventoryNotifier::class));
            $this->assertSame('sent', $delivery->fresh()->status);
        }
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'SALE-1') && str_contains($request['text'], '&lt;Staff&gt;') && str_contains($request['text'], '25.00 USD'));
        $this->actingAs($staff, 'sanctum')->getJson('/api/notifications?category=sales')->assertOk()->assertJsonPath('total', 1);
        $notifier->publish($business->id, 'security.password_changed', 'private-password', [], 'user', $staff->id, [$staff->id], false);
        $this->assertSame([$staff->id], WorkspaceNotification::where('event_key', 'private-password')->pluck('notifiable_id')->all());
    }

    public function test_inventory_alerts_reach_all_active_workspace_members_with_selected_telegram_recipients(): void
    {
        Queue::fake();
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id, 'role' => 'owner', 'status' => 'active', 'telegram_chat_id' => 112233]);
        $staff = User::factory()->create(['business_id' => $business->id, 'role' => 'staff', 'status' => 'active']);
        $inactive = User::factory()->create(['business_id' => $business->id, 'status' => 'inactive']);
        $outsider = User::factory()->create();
        $business->update(['notification_preferences' => ['telegram_enabled' => true, 'recipients' => [$owner->id]]]);
        app(\App\Services\WorkspaceNotifier::class)->publish($business->id, 'inventory.low_stock', 'shared-stock-alert', ['product' => 'Coca', 'quantity' => 2]);

        $alerts = WorkspaceNotification::where('event_key', 'shared-stock-alert')->get();
        $this->assertEqualsCanonicalizing([$owner->id, $staff->id], $alerts->pluck('notifiable_id')->all());
        $this->assertTrue($alerts->every(fn ($alert) => !$alert->owner_only));
        $this->assertSame([$owner->id], TelegramNotificationDelivery::whereIn('notification_id', $alerts->pluck('id'))->pluck('user_id')->all());
        $this->actingAs($staff, 'sanctum')->getJson('/api/notifications?category=inventory')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_expiration_alert_is_queued_delivered_in_khmer_and_deduplicated(): void
    {
        Config::set('services.telegram.bot_token', 'test-token');
        Config::set('services.telegram.mini_app_url', 'https://demo.example.test/mini');
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 901]]));
        Queue::fake();

        $business = Business::factory()->create([
            'timezone' => 'Asia/Phnom_Penh',
            'expiry_warning_days' => 30,
            'notification_preferences' => [
                'telegram_enabled' => true,
                'expiring_soon' => true,
                'recipients' => [],
            ],
        ]);
        $owner = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
            'telegram_chat_id' => 7654321,
            'preferred_language' => 'km',
        ]);
        Queue::fake();
        $product = Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Coca',
            'stock_quantity' => 4,
            'minimum_stock' => 10,
        ]);
        $batch = StockBatch::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'batch_code' => 'COCA-EXP-01',
            'received_date' => now()->subDays(10)->toDateString(),
            'quantity_received' => 4,
            'quantity_remaining' => 4,
            'unit_cost' => 1,
            'expiry_date' => now()->addDays(5)->toDateString(),
        ]);

        app(InventoryAlertService::class)->refresh($business->fresh());

        $this->assertCount(2, Queue::pushed(SendTelegramInventoryNotification::class));
        $notification = WorkspaceNotification::where('business_id', $business->id)
            ->where('type', 'inventory.expiring_soon')->sole();
        $this->assertSame('COCA-EXP-01', $notification->data['params']['batch_code']);
        $this->assertArrayHasKey('days_remaining', $notification->data['params']);

        $delivery = TelegramNotificationDelivery::where('notification_id', $notification->id)->where('user_id', $owner->id)->sole();
        (new SendTelegramInventoryNotification($delivery->id))->handle(app(TelegramInventoryNotifier::class));
        $this->assertSame('sent', $delivery->fresh()->status);

        Http::assertSent(function ($request) use ($batch, $product) {
            return str_ends_with($request->url(), '/sendMessage')
                && $request['chat_id'] === 7654321
                && str_contains($request['text'], 'ជិតផុតកំណត់')
                && str_contains($request['text'], $product->name)
                && str_contains($request['text'], $batch->batch_code)
                && str_contains($request['text'], 'COCA-EXP-01');
        });

        app(InventoryAlertService::class)->refresh($business->fresh());
        $this->assertSame(1, WorkspaceNotification::where('business_id', $business->id)->where('type', 'inventory.expiring_soon')->count());
        $this->assertSame(1, TelegramNotificationDelivery::where('notification_id', $notification->id)->where('user_id', $owner->id)->count());
    }

    public function test_delivery_is_skipped_when_workspace_scope_does_not_match(): void
    {
        Queue::fake();
        $firstBusiness = Business::factory()->create();
        $secondBusiness = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $firstBusiness->id, 'telegram_chat_id' => 7654321, 'telegram_enabled' => true]);
        $notification = WorkspaceNotification::create([
            'id' => (string) Str::uuid(), 'type' => 'inventory.low_stock', 'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id, 'business_id' => $secondBusiness->id, 'event_key' => 'test:mismatch',
            'data' => ['params' => [], 'entity_type' => null, 'entity_id' => null],
        ]);
        $delivery = TelegramNotificationDelivery::create([
            'notification_id' => $notification->id, 'user_id' => $user->id, 'business_id' => $secondBusiness->id, 'status' => 'pending',
        ]);

        (new SendTelegramInventoryNotification($delivery->id))->handle(app(TelegramInventoryNotifier::class));

        $this->assertSame('skipped', $delivery->fresh()->status);
        Http::assertNothingSent();
    }
}
