<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleBasedAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_gets_personal_dashboard_but_cannot_access_owner_data(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->create(['business_id' => $business->id, 'role' => 'staff', 'assigned_shift' => 'A']);

        $this->actingAs($staff, 'sanctum')->getJson('/api/my-dashboard')->assertOk()->assertJsonPath('access_role', 'staff');
        $this->actingAs($staff, 'sanctum')->getJson('/api/dashboard')->assertOk()->assertJsonMissingPath('expenses')->assertJsonMissingPath('today_net_profit');
        $this->actingAs($staff, 'sanctum')->getJson('/api/financing/overview')->assertForbidden();
        $this->actingAs($staff, 'sanctum')->getJson('/api/users')->assertForbidden();
    }

    public function test_manager_can_run_operations_but_not_financing_or_account_administration(): void
    {
        $business = Business::factory()->create();
        $manager = User::factory()->create(['business_id' => $business->id, 'role' => 'manager', 'assigned_shift' => 'A']);

        $this->actingAs($manager, 'sanctum')->getJson('/api/dashboard')->assertOk();
        $this->actingAs($manager, 'sanctum')->getJson('/api/products')->assertOk();
        $this->actingAs($manager, 'sanctum')->getJson('/api/financing/overview')->assertForbidden();
        $this->actingAs($manager, 'sanctum')->getJson('/api/users')->assertForbidden();
    }

    public function test_manager_job_role_is_treated_as_manager_for_invited_accounts(): void
    {
        $business = Business::factory()->create();
        $manager = User::factory()->create(['business_id' => $business->id, 'role' => 'staff', 'job_role' => 'manager', 'assigned_shift' => 'A']);

        $this->actingAs($manager, 'sanctum')->getJson('/api/dashboard')->assertOk();
        $this->assertSame('manager', $manager->fresh()->accessRole());
    }
}
