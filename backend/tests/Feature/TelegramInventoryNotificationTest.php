<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Services\TelegramInventoryNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Bus;
use App\Jobs\SendTelegramInventoryNotification;
use App\Services\WorkspaceNotifier;
use Tests\TestCase;

class TelegramInventoryNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_message_is_accepted_by_telegram_and_contains_product_context(): void
    {
        Config::set('services.telegram.bot_token', 'test-token');
        Config::set('services.telegram.mini_app_url', 'https://kottraluy.tech');
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]], 200)]);
        $business = Business::factory()->create(['notification_preferences' => ['telegram_enabled' => true]]);
        $product = Product::factory()->create(['business_id' => $business->id, 'name' => 'Coca', 'stock_quantity' => 0]);
        $result = app(TelegramInventoryNotifier::class)->send(12345, ['type' => 'inventory.out_of_stock', 'product' => 'Coca', 'product_id' => $product->id, 'quantity' => 0, 'unit' => 'pcs', 'workspace' => $business->name], $product, 'en');

        $this->assertSame('77', $result['message_id']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === 12345 && str_contains($request['text'], 'Coca') && str_contains($request['text'], 'OUT OF STOCK'));
    }

    public function test_settings_are_owner_scoped_and_recipients_are_validated(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id, 'role' => 'owner']);
        $staff = User::factory()->create(['business_id' => $business->id, 'role' => 'staff', 'telegram_enabled' => true]);
        $this->actingAs($owner, 'sanctum')->putJson('/api/telegram/notification-settings', [
            'telegram_enabled' => true, 'low_stock' => true, 'out_of_stock' => true, 'expiring_soon' => true, 'expired' => true,
            'reminder_frequency_hours' => 6, 'recipients' => [$staff->id],
        ])->assertOk()->assertJsonPath('reminder_frequency_hours', 6)->assertJsonPath('recipients.0', $staff->id);
        $this->assertSame([$staff->id], $business->fresh()->notification_preferences['recipients']);
    }

    public function test_repeated_inventory_event_creates_one_delivery_job(): void
    {
        Bus::fake();
        $business = Business::factory()->create(['notification_preferences' => ['telegram_enabled' => true]]);
        $owner = User::factory()->create(['business_id' => $business->id, 'role' => 'owner', 'telegram_chat_id' => 12345]);
        $product = Product::factory()->create(['business_id' => $business->id, 'name' => 'Vital']);
        $notifier = app(WorkspaceNotifier::class);
        $notifier->publish($business->id, 'inventory.low_stock', 'inventory.alert:product:low', ['product' => 'Vital', 'quantity' => 2, 'unit' => 'pcs'], 'product', $product->id);
        $notifier->publish($business->id, 'inventory.low_stock', 'inventory.alert:product:low', ['product' => 'Vital', 'quantity' => 2, 'unit' => 'pcs'], 'product', $product->id);

        $this->assertDatabaseCount('telegram_notification_deliveries', 1);
        Bus::assertDispatchedTimes(SendTelegramInventoryNotification::class, 1);
        $this->assertDatabaseHas('telegram_notification_deliveries', ['user_id' => $owner->id, 'status' => 'pending']);
    }
}
