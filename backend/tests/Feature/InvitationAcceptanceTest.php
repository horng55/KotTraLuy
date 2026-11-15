<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvitationAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function invitation(array $attributes = []): WorkspaceInvitation
    {
        return WorkspaceInvitation::create(array_merge([
            'business_id' => Business::factory()->create()->id,
            'email' => 'manager@example.com', 'channel' => 'email', 'role' => 'manager',
            'token_hash' => hash('sha256', 'secure-token'), 'expires_at' => now()->addDay(),
        ], $attributes));
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['name' => 'Manager', 'password' => 'password123', 'password_confirmation' => 'password123'], $extra);
    }

    public function test_owner_invites_manager_and_status_survives_repeated_fetches_and_acceptance(): void
    {
        config(['services.invitation.email_provider' => null]);
        $owner = User::factory()->create(['business_id' => Business::factory()->create()->id, 'role' => 'owner', 'status' => 'active']);
        $created = $this->actingAs($owner, 'sanctum')->postJson('/api/invitations', [
            'contact' => 'manager@example.com', 'role' => 'manager',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');
        parse_str(parse_url($created->json('share_url'), PHP_URL_QUERY), $query);
        $url = '/api/invitations/'.$query['invite'].'/accept';
        $this->getJson('/api/invitations/'.$query['invite'])->assertOk();
        $accepted = $this->postJson($url, $this->payload(['role' => 'owner', 'business_id' => 999]))
            ->assertOk()->assertJsonPath('user.business_id', $owner->business_id)
            ->assertJsonPath('user.role', 'staff')->assertJsonPath('user.job_role', 'manager');
        $this->assertDatabaseHas('workspace_invitations', ['id' => $created->json('data.id'), 'accepted_user_id' => $accepted->json('user.id')]);
        $this->assertDatabaseHas('business_user', ['business_id' => $owner->business_id, 'user_id' => $accepted->json('user.id')]);
        $this->assertNotNull(WorkspaceInvitation::find($created->json('data.id'))->accepted_at);
        $this->postJson($url, $this->payload())->assertOk()->assertJsonPath('user.id', $accepted->json('user.id'));
        $this->postJson($url, $this->payload(['password' => 'wrong-password', 'password_confirmation' => 'wrong-password']))->assertForbidden();
        $this->assertSame(1, User::where('email', 'manager@example.com')->count());
        for ($i = 0; $i < 3; $i++) $this->getJson('/api/invitations')->assertOk()->assertJsonPath('0.status', 'accepted');
    }

    public function test_invalid_expired_and_revoked_tokens_cannot_create_members(): void
    {
        $invitation = $this->invitation(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/invitations/secure-token/accept', $this->payload())->assertNotFound();
        $invitation->update(['expires_at' => now()->addDay(), 'revoked_at' => now()]);
        $this->postJson('/api/invitations/secure-token/accept', $this->payload())->assertNotFound();
        $this->postJson('/api/invitations/invalid/accept', $this->payload())->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_email_does_not_link_or_promote_an_account(): void
    {
        $this->invitation();
        $existing = User::factory()->create(['email' => 'manager@example.com', 'business_id' => Business::factory()->create()->id]);
        $this->postJson('/api/invitations/secure-token/accept', $this->payload())->assertUnprocessable();
        $this->assertNull(WorkspaceInvitation::first()->accepted_at);
        $this->assertSame($existing->business_id, $existing->fresh()->business_id);
    }

    public function test_only_workspace_owner_can_list_and_revoke_its_invitations(): void
    {
        $invitation = $this->invitation();
        $foreignOwner = User::factory()->create(['business_id' => Business::factory()->create()->id, 'role' => 'owner']);
        $this->actingAs($foreignOwner, 'sanctum')->getJson('/api/invitations')->assertOk()->assertExactJson([]);
        $this->postJson("/api/invitations/{$invitation->id}/revoke")->assertForbidden();
        $staff = User::factory()->create(['business_id' => $invitation->business_id, 'role' => 'staff']);
        $this->actingAs($staff, 'sanctum')->getJson('/api/invitations')->assertForbidden();
        $this->postJson('/api/invitations', ['contact' => 'other@example.com', 'role' => 'manager'])->assertForbidden();
    }

    public function test_invalid_role_and_inactive_accepting_account_cannot_gain_access(): void
    {
        $invitation = $this->invitation(['role' => 'owner']);
        $this->postJson('/api/invitations/secure-token/accept', $this->payload())->assertUnprocessable();
        $invitation->update(['role' => 'manager']);
        $accepted = $this->postJson('/api/invitations/secure-token/accept', $this->payload())->assertOk();
        User::findOrFail($accepted->json('user.id'))->update(['status' => 'inactive']);
        $this->postJson('/api/invitations/secure-token/accept', $this->payload())->assertForbidden();
    }

    public function test_transaction_rolls_back_membership_when_acceptance_save_fails(): void
    {
        $this->invitation();
        WorkspaceInvitation::updating(function () { throw new \RuntimeException('Simulated save failure'); });
        try {
            $this->postJson('/api/invitations/secure-token/accept', $this->payload())->assertStatus(500);
            $this->assertDatabaseCount('users', 0);
            $this->assertNull(WorkspaceInvitation::first()->accepted_at);
        } finally { WorkspaceInvitation::flushEventListeners(); }
    }

    public function test_repair_requires_explicit_acceptance_evidence_and_is_idempotent(): void
    {
        $invitation = $this->invitation();
        $user = User::factory()->create(['business_id' => $invitation->business_id, 'email' => $invitation->email,
            'role' => 'staff', 'job_role' => 'manager', 'status' => 'active']);
        $this->artisan('invitations:repair --apply')->assertSuccessful();
        $this->assertNull($invitation->fresh()->accepted_at);
        $invitation->update(['accepted_user_id' => $user->id]);
        $this->artisan('invitations:repair')->assertSuccessful();
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->artisan('invitations:repair --apply')->assertSuccessful();
        $timestamp = $invitation->fresh()->accepted_at;
        $this->assertNotNull($timestamp);
        $this->artisan('invitations:repair --apply')->assertSuccessful();
        $this->assertTrue($timestamp->equalTo($invitation->fresh()->accepted_at));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_invitation_summary_exposes_workspace_and_terminal_status_without_token(): void
    {
        $invitation = $this->invitation();
        $this->getJson('/api/invitations/secure-token')->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('workspace.id', $invitation->business_id)
            ->assertJsonMissingPath('token_hash');
        $invitation->update(['revoked_at' => now()]);
        $this->getJson('/api/invitations/secure-token')->assertOk()->assertJsonPath('status', 'revoked');
    }

    public function test_existing_invited_account_can_sign_in_and_accept_once(): void
    {
        $invitation = $this->invitation();
        $user = User::factory()->create(['business_id' => $invitation->business_id, 'email' => $invitation->email, 'password' => 'password123', 'status' => 'active']);
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/invitations/secure-token/accept-existing')->assertOk();
        $response->assertJsonPath('user.id', $user->id);
        $this->assertDatabaseHas('business_user', ['business_id' => $invitation->business_id, 'user_id' => $user->id]);
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->actingAs($user, 'sanctum')->postJson('/api/invitations/secure-token/accept-existing')->assertUnprocessable();
    }
}
