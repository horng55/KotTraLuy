<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BizTrackMvpTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_start_and_close_shift(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'staff',
        ]);

        $this->actingAs($user, 'sanctum');

        $startResponse = $this->postJson('/api/shifts', [
            'shift_type' => 'morning',
            'opening_cash' => 250.00,
            'notes' => 'Opening shift',
        ]);

        $startResponse->assertStatus(201)
            ->assertJsonPath('data.shift_type', 'morning');

        $shift = Shift::first();

        $closeResponse = $this->postJson('/api/shifts/' . $shift->id . '/close', [
            'closing_cash' => 420.50,
            'notes' => 'Shift closed',
        ]);

        $closeResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'closed');
    }

    public function test_purchase_increases_stock_and_sale_reduces_stock(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'manager',
        ]);

        $product = Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Milk',
            'sku' => 'MILK-01',
            'selling_price' => 3.50,
            'default_cost' => 1.20,
            'minimum_stock' => 5,
            'stock_quantity' => 0,
        ]);

        $shift = Shift::factory()->create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'open',
        ]);

        $this->actingAs($user, 'sanctum');

        $purchaseResponse = $this->postJson('/api/purchases', [
            'supplier_name' => 'Local Supplier',
            'purchase_date' => now()->toDateString(),
            'reference_no' => 'PO-101',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 10,
                'unit_cost' => 1.20,
            ]],
            'notes' => 'Milk restock',
            'shift_id' => $shift->id,
        ]);

        $purchaseResponse->assertStatus(201);
        $this->assertEquals(10, $product->fresh()->stock_quantity);

        $saleResponse = $this->postJson('/api/sales', [
            'sold_at' => now()->toDateTimeString(),
            'notes' => 'Cash sale',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 4,
                'unit_price' => 3.50,
            ]],
            'shift_id' => $shift->id,
        ]);

        $saleResponse->assertStatus(201);
        $this->assertEquals(6, $product->fresh()->stock_quantity);
    }

    public function test_waste_reduces_stock_and_tracks_cost(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'manager',
        ]);

        $product = Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Croissant',
            'sku' => 'CRO-01',
            'default_cost' => 0.80,
            'selling_price' => 2.50,
            'minimum_stock' => 10,
        ]);

        $product->stock_quantity = 12;
        $product->save();

        $shift = Shift::factory()->create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'open',
        ]);

        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/waste', [
            'product_id' => $product->id,
            'quantity' => 2,
            'reason' => 'expired',
            'notes' => 'Two croissants expired',
            'shift_id' => $shift->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.quantity', 2);

        $this->assertEquals(10, $product->fresh()->stock_quantity);
    }

    public function test_owner_can_create_product_with_initial_stock(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
        ]);

        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/products', [
            'name' => 'Milk',
            'sku' => 'MILK-01',
            'unit' => 'litre',
            'stock_quantity' => 25,
            'default_cost' => 1.20,
            'selling_price' => 3.50,
            'minimum_stock' => 5,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Milk')
            ->assertJsonPath('stock_quantity', 25);

        $this->assertDatabaseHas('products', [
            'business_id' => $business->id,
            'name' => 'Milk',
            'stock_quantity' => 25,
        ]);
    }

    public function test_staff_cannot_access_owner_only_dashboard_and_other_business_data(): void
    {
        $businessA = Business::factory()->create(['name' => 'Business A']);
        $businessB = Business::factory()->create(['name' => 'Business B']);

        $user = User::factory()->create([
            'business_id' => $businessA->id,
            'role' => 'staff',
        ]);

        $product = Product::factory()->create([
            'business_id' => $businessB->id,
            'name' => 'Private Product',
        ]);

        $this->actingAs($user, 'sanctum');

        $ownerDashboard = $this->getJson('/api/dashboard');
        $ownerDashboard->assertStatus(403);

        $this->getJson('/api/products/' . $product->id)
            ->assertStatus(403);
    }
}
