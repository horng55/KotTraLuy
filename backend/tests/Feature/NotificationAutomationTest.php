<?php

namespace Tests\Feature;

use App\Jobs\{RecordQueueHeartbeat, SendTelegramInventoryNotification};
use App\Models\{Business, User, WorkspaceNotification, TelegramNotificationDelivery};
use App\Services\TelegramInventoryNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Config, Http, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Config::set('services.telegram.bot_token', 'fake-token');
        Http::preventStrayRequests();
    }

    private function delivery(array $attributes = []): TelegramNotificationDelivery
    {
        $business = Business::factory()->create(['notification_preferences' => ['telegram_enabled' => true, 'reminder_frequency_hours' => 24]]);
        $user = User::withoutEvents(fn () => User::factory()->create(['business_id' => $business->id, 'role' => 'owner', 'status' => 'active', 'telegram_chat_id' => 1000 + $business->id]));
        $notification = WorkspaceNotification::create([
            'id' => (string) Str::uuid(), 'type' => 'inventory.low_stock', 'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id, 'business_id' => $business->id, 'event_key' => (string) Str::uuid(),
            'data' => ['params' => ['product' => 'Test', 'quantity' => 1], 'entity_type' => null],
        ]);
        return TelegramNotificationDelivery::create(array_merge([
            'notification_id' => $notification->id, 'user_id' => $user->id, 'business_id' => $business->id, 'status' => 'pending',
        ], $attributes));
    }

    public function test_reminders_claim_due_sent_delivery_once_without_resetting_pending_or_failed(): void
    {
        Queue::fake();
        $due = $this->delivery(['status' => 'sent', 'sent_at' => now()->subHours(25)]);
        $pending = $this->delivery(['next_attempt_at' => now()->addMinutes(15)]);
        $failed = $this->delivery(['status' => 'failed']);
        $recent = $this->delivery(['status' => 'sent', 'sent_at' => now()]);
        $this->artisan('telegram:remind-inventory')->assertSuccessful();
        $this->artisan('telegram:remind-inventory')->assertSuccessful();
        $this->assertSame('pending', $due->fresh()->status);
        $this->assertSame($pending->next_attempt_at->timestamp, $pending->fresh()->next_attempt_at->timestamp);
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertSame('sent', $recent->fresh()->status);
        $this->assertSame(4, TelegramNotificationDelivery::count());
        Queue::assertPushed(SendTelegramInventoryNotification::class, 1);
    }

    public function test_future_retry_is_released_instead_of_silently_dropped(): void
    {
        $delivery = $this->delivery(['next_attempt_at' => now()->addMinutes(5)]);
        Http::preventStrayRequests();
        $job = (new SendTelegramInventoryNotification($delivery->id))->withFakeQueueInteractions();
        $job->handle(app(TelegramInventoryNotifier::class));
        $job->assertReleased();
        Http::assertNothingSent();
        $this->assertSame(0, (int) $delivery->fresh()->attempts);
    }

    public function test_transient_failure_matches_queue_backoff_and_success_is_not_resent(): void
    {
        Config::set('services.telegram.bot_token', 'fake-token');
        $delivery = $this->delivery();
        Http::fakeSequence()->push(['ok' => false], 503)->push(['ok' => true, 'result' => ['message_id' => 42]]);
        $job = new SendTelegramInventoryNotification($delivery->id);
        try {
            $job->handle(app(TelegramInventoryNotifier::class));
            $this->fail('Expected transient Telegram failure');
        } catch (\RuntimeException $failure) {
            $this->assertSame('transient', $delivery->fresh()->error_category);
        }
        $this->assertEqualsWithDelta(60, now()->diffInSeconds($delivery->fresh()->next_attempt_at), 1);
        $this->travel(61)->seconds();
        $job->handle(app(TelegramInventoryNotifier::class));
        $job->handle(app(TelegramInventoryNotifier::class));
        $this->assertSame('sent', $delivery->fresh()->status);
        Http::assertSentCount(2);
        $this->travelBack();
    }

    public function test_delivery_lock_blocks_concurrent_execution(): void
    {
        $delivery = $this->delivery();
        $job = (new SendTelegramInventoryNotification($delivery->id))->withFakeQueueInteractions();
        $middleware = $job->middleware()[0];
        $lock = Cache::lock($middleware->getLockKey($job), 75);
        $this->assertTrue($lock->get());
        try {
            $middleware->handle($job, fn () => $this->fail('Concurrent delivery must not execute'));
            $job->assertReleased(60);
        } finally {
            $lock->release();
        }
    }

    public function test_resolved_alert_is_skipped_and_late_duplicate_failure_cannot_overwrite_success(): void
    {
        $delivery = $this->delivery();
        $notification = $delivery->notification;
        $notification->update(['data' => [...$notification->data, 'resolved_at' => now()->toIso8601String()]]);
        $job = new SendTelegramInventoryNotification($delivery->id);
        $job->handle(app(TelegramInventoryNotifier::class));
        $this->assertSame('skipped', $delivery->fresh()->status);
        Http::assertNothingSent();
        $delivery->update(['status' => 'sent']);
        $job->failed(new \RuntimeException('Late duplicate exhausted attempts'));
        $this->assertSame('sent', $delivery->fresh()->status);
    }

    public function test_health_requires_real_queue_scheduler_and_inventory_heartbeats(): void
    {
        Config::set('queue.default', 'database');
        $this->artisan('notifications:health')->assertFailed();
        Cache::put('operations:scheduler-heartbeat', now()->timestamp, 600);
        Cache::put('operations:inventory-heartbeat', now()->timestamp, 600);
        (new RecordQueueHeartbeat)->handle();
        $this->artisan('notifications:health')->assertSuccessful();
        $this->travel(6)->minutes();
        $this->artisan('notifications:health')->assertFailed();
        $this->travelBack();
    }
}
