<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_staff_with_username_and_assigned_shift(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/users', [
            'name' => 'Sokha Staff',
            'username' => 'sokha.a',
            'password' => 'temporary123',
            'role' => 'staff',
            'assigned_shift' => 'A',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.username', 'sokha.a')
            ->assertJsonPath('data.assigned_shift', 'A')
            ->assertJsonPath('data.must_change_password', true);

        $staff = User::where('username', 'sokha.a')->firstOrFail();
        $this->assertSame($owner->business_id, $staff->business_id);
        $this->assertTrue(Hash::check('temporary123', $staff->password));
    }

    public function test_staff_cannot_manage_users(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->create(['business_id' => $business->id, 'role' => 'staff']);

        $this->actingAs($staff, 'sanctum')->getJson('/api/users')->assertForbidden();
    }

    public function test_user_can_login_with_username_and_inactive_user_is_rejected(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->create([
            'business_id' => $business->id,
            'username' => 'dara.b',
            'password' => 'password123',
            'role' => 'staff',
            'assigned_shift' => 'B',
            'status' => 'active',
        ]);

        $this->postJson('/api/login', ['login' => 'DARA.B', 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('user.assigned_shift', 'B')
            ->assertJsonStructure(['token']);

        $this->assertNotNull($staff->fresh()->last_login_at);

        $staff->update(['status' => 'inactive']);
        $this->postJson('/api/login', ['login' => 'dara.b', 'password' => 'password123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('login');
    }

    public function test_owner_can_deactivate_staff_and_revoke_access(): void
    {
        $owner = $this->owner();
        $staff = User::factory()->create([
            'business_id' => $owner->business_id,
            'role' => 'staff',
            'status' => 'active',
            'telegram_chat_id' => 123456,
        ]);
        $staff->createToken('existing-session');

        $this->actingAs($owner, 'sanctum')->postJson("/api/users/{$staff->id}/status", [
            'status' => 'inactive',
        ])->assertOk()->assertJsonPath('data.status', 'inactive');

        $staff->refresh();
        $this->assertNull($staff->telegram_chat_id);
        $this->assertCount(0, $staff->tokens);
    }

    public function test_staff_can_only_start_their_assigned_shift(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'staff',
            'assigned_shift' => 'B',
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/shifts', ['shift_type' => 'A'])
            ->assertUnprocessable();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/shifts', ['shift_type' => 'B'])
            ->assertCreated()
            ->assertJsonPath('data.shift_type', 'B');
    }

    public function test_temporary_password_blocks_workspace_until_user_changes_it(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'staff',
            'password' => 'temporary123',
            'must_change_password' => true,
        ]);

        $this->actingAs($staff, 'sanctum')->getJson('/api/products')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');

        $this->actingAs($staff, 'sanctum')->postJson('/api/change-password', [
            'current_password' => 'temporary123',
            'password' => 'private-password-456',
            'password_confirmation' => 'private-password-456',
        ])->assertOk();

        $this->assertFalse($staff->fresh()->must_change_password);
        $this->actingAs($staff->fresh(), 'sanctum')->getJson('/api/products')->assertOk();
    }

    private function owner(): User
    {
        $business = Business::factory()->create();

        return User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
    }
}
