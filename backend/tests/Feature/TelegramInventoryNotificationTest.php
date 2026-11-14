<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramInventoryNotification;
use App\Models\{Business, Product, StockBatch, TelegramNotificationDelivery, User, WorkspaceNotification};
use App\Services\{InventoryAlertService, TelegramInventoryNotifier};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramInventoryNotificationTest extends TestCase
{
    use RefreshDatabase;

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
                && str_contains($request['text'], '5');
        });

        app(InventoryAlertService::class)->refresh($business->fresh());
        $this->assertSame(1, WorkspaceNotification::where('business_id', $business->id)->where('type', 'inventory.expiring_soon')->count());
        $this->assertSame(1, TelegramNotificationDelivery::where('notification_id', $notification->id)->where('user_id', $owner->id)->count());
    }
}
