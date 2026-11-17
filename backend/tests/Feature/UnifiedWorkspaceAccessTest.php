<?php

namespace Tests\Feature;

use App\Models\{Business, Product, Sale, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Queue};
use Tests\TestCase;

class UnifiedWorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    private function member(Business $business, string $role): User
    {
        return User::factory()->create(['business_id' => $business->id, 'role' => $role, 'status' => 'active', 'must_change_password' => false]);
    }

    public function test_owner_manager_staff_and_member_use_one_dashboard_with_filtered_data(): void
    {
        $business = Business::factory()->create();
        Product::factory()->create(['business_id' => $business->id, 'default_cost' => 987]);
        foreach (['owner', 'manager', 'staff', 'member'] as $role) {
            $user = $this->member($business, $role);
            $response = $this->actingAs($user, 'sanctum')->getJson('/api/dashboard')->assertOk()->assertJsonPath('access_role', $role);
            if ($role === 'owner') $response->assertJsonPath('products.0.default_cost', '987.00');
            else $response->assertJsonMissingPath('products.0.default_cost')->assertJsonMissingPath('today_net_profit');
            if (in_array($role, ['staff', 'member'], true)) {
                $response->assertJsonMissingPath('expenses');
                foreach (['/api/users', '/api/invitations', '/api/financing/overview', '/api/telegram/notification-settings', '/api/reports/financial', '/api/reports/financial.xlsx', '/api/reports/sales.xlsx'] as $url) $this->getJson($url)->assertForbidden();
                $this->postJson('/api/products', [])->assertForbidden();
                $this->getJson('/api/telegram/status')->assertOk();
            }
        }
    }

    public function test_personal_sales_are_scoped_and_other_members_records_cannot_be_opened_or_changed(): void
    {
        $business = Business::factory()->create();
        $staff = $this->member($business, 'staff');
        $other = $this->member($business, 'staff');
        foreach ([$staff, $other] as $index => $user) Sale::withoutEvents(fn () => Sale::create(['business_id' => $business->id, 'user_id' => $user->id, 'sale_no' => 'S-'.$index, 'currency' => 'USD', 'sold_at' => now(), 'total_amount' => $index ? 999 : 15]));
        $this->actingAs($staff, 'sanctum')->getJson('/api/sales')->assertOk()->assertJsonCount(1)->assertJsonPath('0.user_id', $staff->id);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('today_revenue', 15);
        $foreign = Sale::where('user_id', $other->id)->sole();
        $this->getJson('/api/sales/'.$foreign->id)->assertForbidden();
        $this->patchJson('/api/sales/'.$foreign->id, ['notes' => 'no'])->assertForbidden();
        $this->deleteJson('/api/sales/'.$foreign->id)->assertForbidden();
    }

    public function test_owner_assigns_workspace_permissions_without_changing_role_and_staff_cannot_gain_hub_access(): void
    {
        $business = Business::factory()->create();
        $owner = $this->member($business, 'owner');
        $manager = $this->member($business, 'manager');
        $staff = $this->member($business, 'staff');
        $this->actingAs($owner, 'sanctum')->putJson('/api/users/'.$manager->id.'/permissions', ['permissions' => ['sales.read.own', 'inventory.read', 'financing', 'telegram.settings']])->assertOk();
        $this->actingAs($manager->fresh(), 'sanctum')->getJson('/api/financing/overview')->assertOk();
        $this->getJson('/api/telegram/notification-settings')->assertOk();
        $this->getJson('/api/reports/range')->assertForbidden();
        $this->postJson('/api/products', [])->assertForbidden();
        $this->assertSame('manager', $manager->fresh()->role);
        $this->actingAs($owner->fresh(), 'sanctum')->putJson('/api/users/'.$staff->id.'/permissions', ['permissions' => ['inventory.read', 'financing', 'team.manage', 'telegram.settings']])->assertOk();
        $this->actingAs($staff->fresh(), 'sanctum')->getJson('/api/financing/overview')->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/telegram/notification-settings')->assertForbidden();
        $this->putJson('/api/users/'.$manager->id.'/permissions', ['permissions' => ['reports.financial']])->assertForbidden();
    }

    public function test_workspace_switch_uses_membership_role_and_never_mutates_primary_identity(): void
    {
        $primary = Business::factory()->create();
        $secondary = Business::factory()->create();
        $outside = Business::factory()->create();
        $user = $this->member($primary, 'owner');
        DB::table('business_user')->insert(['business_id' => $secondary->id, 'user_id' => $user->id, 'role' => 'member', 'permissions' => json_encode(['inventory.read'])]);
        $this->actingAs($user, 'sanctum')->getJson('/api/workspaces')->assertOk()->assertJsonCount(2);
        $this->postJson('/api/workspaces/'.$outside->id.'/switch')->assertForbidden();
        $token = $this->postJson('/api/workspaces/'.$secondary->id.'/switch')->assertOk()->json('token');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertOk()->assertJsonPath('business.id', $secondary->id)->assertJsonPath('access_role', 'member')->assertJsonPath('role', 'member');
        $this->getJson('/api/financing/overview')->assertForbidden();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonMissingPath('today_revenue');
        $this->assertSame($primary->id, $user->fresh()->business_id);
        $this->assertSame('owner', $user->fresh()->role);
        DB::table('business_user')->where('business_id', $secondary->id)->where('user_id', $user->id)->update(['status' => 'inactive']);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/dashboard')->assertForbidden();
    }

    public function test_nested_inventory_costs_and_revoked_financial_downloads_are_protected(): void
    {
        $business = Business::factory()->create();
        $staff = $this->member($business, 'member');
        $product = Product::factory()->create(['business_id' => $business->id, 'default_cost' => 451]);
        \App\Models\StockBatch::create(['business_id' => $business->id, 'product_id' => $product->id, 'batch_code' => 'PRIVATE-COST', 'received_date' => now()->toDateString(), 'quantity_received' => 3, 'quantity_remaining' => 3, 'unit_cost' => 451, 'expiry_date' => now()->addDays(3)->toDateString()]);
        $this->actingAs($staff, 'sanctum')->getJson('/api/products/'.$product->id)->assertOk()->assertJsonMissingPath('default_cost')->assertJsonMissingPath('batches.0.unit_cost');
        $this->getJson('/api/inventory-expiration')->assertOk()->assertJsonMissingPath('batches.data.0.unit_cost');
        $manager = $this->member($business, 'manager');
        $this->actingAs($manager, 'sanctum')->patchJson('/api/products/'.$product->id, ['name' => 'Updated name'])->assertOk();
        $this->assertSame('451.00', $product->fresh()->default_cost);
        $this->patchJson('/api/products/'.$product->id, ['default_cost' => 0])->assertForbidden();
        DB::table('business_user')->insert(['business_id' => $business->id, 'user_id' => $staff->id, 'role' => 'member', 'permissions' => json_encode(['reports.financial'])]);
        $token = str_repeat('a', 64);
        \Illuminate\Support\Facades\Cache::put('pdf-export:'.$token, ['contents' => base64_encode('%PDF-test'), 'filename' => 'KotTraLuy_Test.pdf', 'user_id' => $staff->id, 'business_id' => $business->id], now()->addMinutes(5));
        $this->get('/api/reports/pdf-download/'.$token)->assertOk();
        DB::table('business_user')->where('business_id', $business->id)->where('user_id', $staff->id)->update(['permissions' => '[]']);
        $this->get('/api/reports/pdf-download/'.$token)->assertForbidden();
    }

    public function test_existing_account_accepts_another_workspace_invitation_without_changing_primary_role(): void
    {
        $primary = Business::factory()->create();
        $secondary = Business::factory()->create();
        $user = $this->member($primary, 'owner');
        $inviter = $this->member($secondary, 'owner');
        $rawToken = str_repeat('b', 64);
        \App\Models\WorkspaceInvitation::create(['business_id' => $secondary->id, 'invited_by' => $inviter->id, 'email' => $user->email, 'channel' => 'email', 'role' => 'manager', 'token_hash' => hash('sha256', $rawToken), 'expires_at' => now()->addDay(), 'delivery_status' => 'manual']);
        $this->actingAs($user, 'sanctum')->postJson('/api/invitations/'.$rawToken.'/accept-existing')->assertOk();
        $this->assertDatabaseHas('business_user', ['business_id' => $secondary->id, 'user_id' => $user->id, 'role' => 'manager']);
        $this->assertSame('owner', $user->fresh()->role);
        $this->assertSame($primary->id, $user->fresh()->business_id);
    }

    public function test_delegated_team_management_cannot_promote_roles_or_reset_account_credentials(): void
    {
        $business = Business::factory()->create();
        $owner = $this->member($business, 'owner');
        $manager = $this->member($business, 'manager');
        $staff = $this->member($business, 'staff');
        DB::table('business_user')->insert(['business_id' => $business->id, 'user_id' => $manager->id, 'role' => 'manager', 'permissions' => json_encode(['team.manage'])]);
        $this->actingAs($manager, 'sanctum')->getJson('/api/users')->assertOk();
        $this->getJson('/api/invitations')->assertOk();
        $this->patchJson('/api/users/'.$staff->id, ['assigned_shift' => 'B'])->assertOk();
        $this->assertSame('B', $staff->fresh()->assigned_shift);
        $this->patchJson('/api/users/'.$staff->id, ['role' => 'owner'])->assertForbidden();
        $this->patchJson('/api/users/'.$owner->id, ['assigned_shift' => 'B'])->assertForbidden();
        $this->postJson('/api/users/'.$staff->id.'/reset-password', ['password' => 'different-password'])->assertForbidden();
        $this->postJson('/api/invitations', ['contact' => 'invited@example.test', 'role' => 'manager'])->assertStatus(422);
        $this->putJson('/api/users/'.$staff->id.'/permissions', ['permissions' => ['reports.financial']])->assertForbidden();
    }

    public function test_owner_can_create_and_edit_member_without_losing_shift_or_telegram_access(): void
    {
        $business = Business::factory()->create();
        $owner = $this->member($business, 'owner');
        $created = $this->actingAs($owner, 'sanctum')->postJson('/api/users', ['name' => 'Member account', 'username' => 'rbac.member', 'password' => 'temporary-password', 'role' => 'member', 'assigned_shift' => 'A', 'telegram_enabled' => true])->assertCreated()->assertJsonPath('data.role', 'member')->assertJsonPath('data.assigned_shift', 'A')->assertJsonPath('data.telegram_enabled', true);
        $this->patchJson('/api/users/'.$created->json('data.id'), ['name' => 'Updated member', 'role' => 'member'])->assertOk()->assertJsonPath('data.role', 'member')->assertJsonPath('data.assigned_shift', 'A')->assertJsonPath('data.telegram_enabled', true);
    }

    public function test_operational_reports_respect_currency_and_personal_sales_grants(): void
    {
        $business = Business::factory()->create();
        $manager = $this->member($business, 'manager');
        $staff = $this->member($business, 'staff');
        foreach ([[$manager, 'USD', 10], [$manager, 'KHR', 4000], [$staff, 'USD', 3]] as $index => [$user, $currency, $amount]) {
            Sale::withoutEvents(fn () => Sale::create(['business_id' => $business->id, 'user_id' => $user->id, 'sale_no' => 'REPORT-'.$index, 'currency' => $currency, 'sold_at' => '2026-11-17 12:00:00', 'total_amount' => $amount]));
        }
        $range = '/api/reports/range?from=2026-11-17&to=2026-11-17';
        $this->actingAs($manager, 'sanctum')->getJson($range.'&currency=KHR')->assertOk()->assertJsonPath('net_sales', 4000)->assertJsonPath('currency', 'KHR')->assertJsonMissingPath('profit');
        $this->getJson($range.'&currency=USD')->assertOk()->assertJsonPath('net_sales', 13);
        $this->getJson($range.'&currency=EUR')->assertStatus(422);
        DB::table('business_user')->insert(['business_id' => $business->id, 'user_id' => $staff->id, 'role' => 'staff', 'permissions' => json_encode(['sales.read.own', 'reports.operational'])]);
        $this->actingAs($staff, 'sanctum')->getJson($range.'&currency=USD')->assertOk()->assertJsonPath('net_sales', 3)->assertJsonMissingPath('expenses')->assertJsonMissingPath('profit');
        $this->getJson('/api/reports/daily?date=2026-11-17&currency=USD')->assertOk()->assertJsonPath('revenue', 3)->assertJsonMissingPath('estimated_profit');
        $this->getJson('/api/reports/monthly?month=2026-11&currency=USD')->assertOk()->assertJsonPath('total_revenue', 3)->assertJsonMissingPath('estimated_profit');
    }
}
