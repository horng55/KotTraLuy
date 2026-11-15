<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Models\WorkspaceNotification;
use App\Services\InventoryAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_count_and_list_share_the_same_eight_record_inbox_and_read_mutations(): void
    {
        $business = Business::factory()->create();
        $owner = User::withoutEvents(fn () => User::factory()->create(['business_id' => $business->id, 'role' => 'owner', 'status' => 'active']));
        for ($i = 1; $i <= 8; $i++) {
            WorkspaceNotification::create(['id' => (string) Str::uuid(), 'type' => 'business.incomplete', 'business_id' => $business->id,
                'notifiable_type' => $owner->getMorphClass(), 'notifiable_id' => $owner->id, 'event_key' => 'demo:'.$i,
                'owner_only' => false, 'data' => ['params' => ['missing_fields' => ['name']]]]);
        }
        $this->actingAs($owner, 'sanctum');
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('unread_count', 8);
        $this->getJson('/api/notifications?unread=1&per_page=8')->assertOk()->assertJsonPath('total', 8)->assertJsonCount(8, 'data')->assertJsonPath('unread_count', 8);
        $id = WorkspaceNotification::firstOrFail()->id;
        $this->patchJson('/api/notifications/'.$id.'/read')->assertOk()->assertJsonPath('unread_count', 7);
        $this->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('unread_count', 0);
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('unread_count', 0);
    }

    public function test_sale_currency_defaults_are_taken_from_the_saved_record(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id]);
        $staff = User::factory()->create(['business_id' => $business->id, 'role' => 'staff']);
        $sale = \App\Models\Sale::create(['business_id' => $business->id, 'user_id' => $staff->id, 'sale_no' => 'DEFAULT-CURRENCY', 'sold_at' => now(), 'total_amount' => 20]);
        $notification = WorkspaceNotification::where('type', 'sale.created')->where('notifiable_id', $owner->id)->sole();
        $this->assertNotNull($notification->data['params']['currency']);
        $this->assertSame($sale->fresh()->currency, $notification->data['params']['currency']);
    }

    public function test_navigation_is_allowlisted_and_moving_workspace_hides_previous_inbox(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id]);
        $item = WorkspaceNotification::where('notifiable_id', $owner->id)->firstOrFail();
        $data = $item->data;
        $data['action_page'] = 'https://untrusted.example';
        $item->update(['data' => $data]);
        $this->actingAs($owner, 'sanctum');
        $this->getJson('/api/notifications')->assertOk()->assertJsonFragment([
            'id' => $item->id,
            'action_page' => \App\Services\WorkspaceNotifier::TYPES[$item->type][1],
        ]);
        $owner->update(['business_id' => Business::factory()->create()->id]);
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('unread_count', 0);
        $this->patchJson('/api/notifications/'.$item->id.'/read')->assertNotFound();
    }

    public function test_resolved_stock_conditions_can_recur_without_duplicate_active_alerts(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'stock_quantity' => 3, 'minimum_stock' => 5]);
        $product->update(['stock_quantity' => 10]);
        $product->update(['stock_quantity' => 2]);
        app(InventoryAlertService::class)->refresh($business);
        $this->assertSame(2, WorkspaceNotification::where('type', 'inventory.low_stock')->where('notifiable_id', $owner->id)->count());
    }

    public function test_staff_sale_and_real_stock_condition_generate_deduplicated_scoped_notifications(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id]);
        $staff = User::factory()->create(['business_id' => $business->id, 'role' => 'staff']);
        $peer = User::factory()->create(['business_id' => $business->id, 'role' => 'staff']);
        \App\Models\Shift::create(['business_id' => $business->id, 'user_id' => $staff->id, 'shift_type' => 'A', 'status' => 'open', 'started_at' => now(), 'opening_cash' => 0]);
        $product = Product::factory()->create(['business_id' => $business->id, 'stock_quantity' => 10, 'minimum_stock' => 5]);
        $this->actingAs($staff, 'sanctum');
        $payload = ['sold_at' => now()->toDateTimeString(), 'currency' => 'USD', 'idempotency_key' => 'notification-sale', 'payment_received' => 0, 'items' => [['product_id' => $product->id, 'quantity' => 7, 'unit_price' => 5]]];
        $this->postJson('/api/sales', $payload)->assertSuccessful();
        $created = WorkspaceNotification::where('type', 'sale.created')->where('notifiable_id', $owner->id)->sole();
        $this->assertEquals(35, $created->data['params']['amount']);
        $this->assertSame(0, WorkspaceNotification::where('notifiable_id', $peer->id)->where('type', 'sale.created')->count());
        $this->assertSame(1, WorkspaceNotification::where('type', 'inventory.low_stock')->where('notifiable_id', $owner->id)->count());
        $this->assertSame(0, WorkspaceNotification::where('type', 'inventory.low_stock')->where('notifiable_id', $staff->id)->count());
        $before = WorkspaceNotification::count();
        $this->postJson('/api/sales', $payload)->assertOk()->assertJsonPath('duplicate', true);
        app(InventoryAlertService::class)->refresh($business);
        $this->assertSame($before, WorkspaceNotification::count());
        $this->actingAs($owner, 'sanctum');
        $this->getJson('/api/notifications?category=inventory')->assertOk()->assertJsonPath('data.0.params.quantity', 3);
    }

    public function test_read_state_persists_and_recipient_workspace_and_role_boundaries_are_enforced(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id]);
        $peer = User::factory()->create(['business_id' => $business->id]);
        $other = User::factory()->create(['business_id' => Business::factory()->create()->id]);
        $item = WorkspaceNotification::where('notifiable_id', $owner->id)->firstOrFail();
        $this->actingAs($other, 'sanctum')->patchJson('/api/notifications/'.$item->id.'/read')->assertNotFound();
        $this->actingAs($peer, 'sanctum')->patchJson('/api/notifications/'.$item->id.'/read')->assertNotFound();
        $this->actingAs($owner, 'sanctum');
        $this->patchJson('/api/notifications/'.$item->id.'/read')->assertOk();
        $this->assertNotNull($item->fresh()->read_at);
        $this->getJson('/api/notifications?unread=1')->assertOk()->assertJsonMissing(['id' => $item->id]);
        $this->patchJson('/api/notifications/'.$item->id.'/unread')->assertOk();
        $this->assertNull($item->fresh()->read_at);
        $this->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('unread_count', 0);
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('unread_count', 0);
        $owner->update(['role' => 'staff']);
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('total', 0);
        $owner->update(['status' => 'inactive']);
        $this->getJson('/api/notifications')->assertForbidden();
    }

    public function test_authentication_and_filters_are_required_and_validated(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
        $user = User::factory()->create(['business_id' => Business::factory()->create()->id]);
        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/notifications?category=unknown')->assertUnprocessable();
        $this->getJson('/api/notifications?per_page=500')->assertUnprocessable();
        $this->getJson('/api/notifications?per_page=1')->assertOk()->assertJsonPath('per_page', 1);
    }

    public function test_team_telegram_pack_expense_and_security_events_use_real_models_without_credentials(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id]);
        $staff = User::factory()->create(['business_id' => $business->id, 'role' => 'staff']);
        $staff->update(['status' => 'inactive']);
        $staff->update(['status' => 'active']);
        $staff->update(['telegram_chat_id' => '123456789']);
        $staff->update(['password' => 'another-secure-password']);
        \App\Models\Expense::create(['business_id' => $business->id, 'user_id' => $staff->id, 'currency' => 'USD', 'amount' => 12, 'category' => 'Transport', 'description' => 'Delivery', 'expense_date' => now()->toDateString()]);
        \App\Models\PreparationPack::create(['business_id' => $business->id, 'status' => 'draft', 'snapshot' => []]);
        foreach (['team.created', 'team.deactivated', 'team.activated', 'expense.created', 'pack.created', 'business.incomplete'] as $type) {
            $this->assertTrue(WorkspaceNotification::where('type', $type)->where('notifiable_id', $owner->id)->exists(), $type);
        }
        foreach (['telegram.connected', 'security.password_changed'] as $type) {
            $notification = WorkspaceNotification::where('type', $type)->where('notifiable_id', $staff->id)->sole();
            $this->assertStringNotContainsString('123456789', json_encode($notification->data));
            $this->assertStringNotContainsString('another-secure-password', json_encode($notification->data));
            $this->assertFalse(WorkspaceNotification::where('type', $type)->where('notifiable_id', $owner->id)->exists());
        }
    }

    public function test_rolled_back_business_events_do_not_leave_notifications(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id]);
        $before = WorkspaceNotification::count();
        \Illuminate\Support\Facades\DB::beginTransaction();
        User::factory()->create(['business_id' => $business->id, 'role' => 'staff']);
        $this->assertGreaterThan($before, WorkspaceNotification::count());
        \Illuminate\Support\Facades\DB::rollBack();
        $this->assertSame($before, WorkspaceNotification::count());
    }

    public function test_sale_changes_and_payment_retries_generate_the_correct_events(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id]);
        $staff = User::factory()->create(['business_id' => $business->id, 'role' => 'staff']);
        $sale = \App\Models\Sale::create(['business_id' => $business->id, 'user_id' => $staff->id, 'currency' => 'USD', 'sale_no' => 'NOTIFY-1', 'sold_at' => now(), 'total_amount' => 35, 'payment_received' => 0, 'outstanding_balance' => 35]);
        $this->actingAs($owner, 'sanctum');
        $this->patchJson('/api/sales/'.$sale->id, ['notes' => 'Customer requested delivery'])->assertOk();
        $this->postJson('/api/sales/'.$sale->id.'/payments', ['amount' => 15, 'idempotency_key' => 'notify-payment'])->assertOk();
        $this->postJson('/api/sales/'.$sale->id.'/payments', ['amount' => 15, 'idempotency_key' => 'notify-payment'])->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, WorkspaceNotification::where('type', 'payment.received')->where('notifiable_id', $owner->id)->count());
        $this->assertEquals(15, WorkspaceNotification::where('type', 'payment.received')->where('notifiable_id', $owner->id)->sole()->data['params']['amount']);
        $this->deleteJson('/api/sales/'.$sale->id)->assertOk();
        foreach (['sale.updated', 'sale.unpaid', 'sale.deleted'] as $type) {
            $this->assertSame(1, WorkspaceNotification::where('type', $type)->where('notifiable_id', $owner->id)->count());
        }
    }

    public function test_expiry_time_transitions_and_out_of_stock_are_real_deduplicated_events(): void
    {
        $business = Business::factory()->create(['expiry_warning_days' => 7]);
        $owner = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'stock_quantity' => 10]);
        $expiry = now($business->timezone)->addDays(2)->startOfDay();
        \App\Models\StockBatch::create(['business_id' => $business->id, 'product_id' => $product->id, 'batch_code' => 'NOTIFY-EXPIRY', 'received_date' => now()->toDateString(), 'quantity_received' => 10, 'quantity_remaining' => 10, 'unit_cost' => 1, 'expiry_date' => $expiry->toDateString()]);
        $this->assertSame(1, WorkspaceNotification::where('type', 'inventory.expiring_soon')->where('notifiable_id', $owner->id)->count());
        $this->travelTo($expiry);
        app(InventoryAlertService::class)->refresh($business);
        $this->assertSame(1, WorkspaceNotification::where('type', 'inventory.expires_today')->where('notifiable_id', $owner->id)->count());
        $this->travelTo($expiry->copy()->addDay());
        app(InventoryAlertService::class)->refresh($business);
        $this->assertSame(1, WorkspaceNotification::where('type', 'inventory.expired')->where('notifiable_id', $owner->id)->count());
        $this->assertSame(1, WorkspaceNotification::where('type', 'inventory.out_of_stock')->where('notifiable_id', $owner->id)->count());
        $count = WorkspaceNotification::count();
        app(InventoryAlertService::class)->refresh($business);
        $this->assertSame($count, WorkspaceNotification::count());
        $this->travelBack();
    }
}
