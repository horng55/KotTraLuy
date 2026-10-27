<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\FinancingDocument;
use App\Models\FinancingProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancingPreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_profile_and_document_availability_are_scoped_to_the_business(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id, 'role' => 'owner']);
        $this->actingAs($user, 'sanctum');

        $this->putJson('/api/financing/business', [
            'name' => 'Corner shop', 'activity' => 'Retail', 'province' => 'Phnom Penh',
            'operating_started_on' => now()->subYear()->toDateString(), 'registration_status' => 'informal',
        ])->assertOk()->assertJsonPath('business.registration_status', 'informal');

        $this->putJson('/api/financing/documents/identity_document', [
            'availability' => 'available', 'verification_status' => 'not_verified',
        ])->assertOk()->assertJsonPath('document.availability', 'available');

        $this->assertDatabaseHas('financing_documents', ['business_id' => $business->id, 'document_key' => 'identity_document', 'availability' => 'available']);
    }

    public function test_financing_product_evaluation_keeps_unknown_rules_unknown(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id, 'role' => 'owner']);
        $this->actingAs($user, 'sanctum');

        $response = $this->getJson('/api/financing/products')->assertOk();
        $response->assertJsonFragment(['status' => 'bank_confirmation_needed']);
        $response->assertJsonFragment(['status' => 'information_missing']);
    }

    public function test_preparation_pack_is_an_immutable_snapshot(): void
    {
        $business = Business::factory()->create(['name' => 'Snapshot shop']);
        $user = User::factory()->create(['business_id' => $business->id, 'role' => 'owner']);
        FinancingProfile::create(['business_id' => $business->id, 'requested_amount' => 2500, 'currency' => 'USD', 'purpose' => 'Working capital']);
        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/financing/packs', ['product_key' => 'aba-small-business-loan'])->assertCreated();
        $packId = $response->json('pack.id');
        $business->update(['name' => 'Renamed shop']);

        $this->getJson('/api/financing/packs')->assertOk()->assertJsonPath('0.snapshot.business.name', 'Snapshot shop');
        $this->assertDatabaseHas('preparation_packs', ['id' => $packId, 'status' => 'draft']);
    }
}
