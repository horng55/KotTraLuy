<?php
namespace Tests\Feature;

use App\Models\{Business, Product, User, StockBatch, WorkspaceNotification};
use App\Services\InventoryAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpirationInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function setupInventory(): array {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-12 18:00:00','UTC'));
        $business = Business::factory()->create(['timezone'=>'Asia/Phnom_Penh']);
        $owner = User::factory()->create(['business_id'=>$business->id,'role'=>'owner']);
        $this->actingAs($owner,'sanctum');
        $product = Product::factory()->create(['business_id'=>$business->id,'stock_quantity'=>0]);
        return [$business,$owner,$product];
    }
    private function batch(Product $product, ?string $date, int $quantity = 5): StockBatch {
        $batch = StockBatch::create(['business_id'=>$product->business_id,'product_id'=>$product->id,'batch_code'=>uniqid(),
            'received_date'=>'2026-11-01','quantity_received'=>$quantity,'quantity_remaining'=>$quantity,'unit_cost'=>2,'expiry_date'=>$date]);
        $product->increment('stock_quantity',$quantity);
        return $batch;
    }
    public function test_date_boundaries_distinct_counts_and_zero_quantity(): void {
        [$business,,$product] = $this->setupInventory();
        // Business date is November 13 despite the UTC date being November 12.
        foreach (['2026-12-13','2026-11-20','2026-11-13','2026-11-12',null,'2026-12-14'] as $date) $this->batch($product,$date);
        $this->batch($product,'2026-11-01',0);
        $this->getJson('/api/inventory-expiration')->assertOk()->assertJsonPath('business_date','2026-11-13')
            ->assertJsonPath('expired',1)->assertJsonPath('expiring_soon',1)->assertJsonPath('batches.total',4);
        $this->assertEquals(30,$product->fresh()->stock_quantity);
        $this->assertEquals(30,$product->batches()->sum('quantity_remaining'));
    }
    public function test_notifications_are_persistent_deduplicated_updated_and_resolved(): void {
        [$business,,$product] = $this->setupInventory();
        $batch = $this->batch($product,'2026-11-20');
        app(InventoryAlertService::class)->refresh($business->fresh());
        app(InventoryAlertService::class)->refresh($business->fresh());
        $this->assertEquals(1,WorkspaceNotification::where('type','inventory.expiring_soon')->count());
        $batch->update(['quantity_remaining'=>3]);
        $this->assertEquals(3,WorkspaceNotification::where('type','inventory.expiring_soon')->sole()->data['params']['quantity']);
        $batch->update(['quantity_remaining'=>0]);
        $this->assertNotNull(WorkspaceNotification::where('type','inventory.expiring_soon')->sole()->read_at);
    }
    public function test_fefo_blocks_manual_expired_allocations_and_keeps_totals(): void {
        [,,$product] = $this->setupInventory();
        $expired = $this->batch($product,'2026-11-12');
        $later = $this->batch($product,'2026-12-01');
        $today = $this->batch($product,'2026-11-13');
        $payload = ['sold_at'=>now()->toIso8601String(),'items'=>[['product_id'=>$product->id,'quantity'=>2,'unit_price'=>4]]];
        $this->postJson('/api/sales',[...$payload,'allocations'=>[['product_id'=>$product->id,'batch_id'=>$expired->id,'quantity'=>2]]])->assertUnprocessable();
        $this->assertDatabaseCount('sales',0);
        $this->postJson('/api/sales',$payload)->assertCreated();
        $this->assertEquals(3,$today->fresh()->quantity_remaining);
        $this->assertEquals(5,$later->fresh()->quantity_remaining);
        $this->assertEquals(5,$expired->fresh()->quantity_remaining);
        $this->assertEquals($product->fresh()->stock_quantity,$product->batches()->sum('quantity_remaining'));
        $this->postJson('/api/sales',[...$payload,'items'=>[['product_id'=>$product->id,'quantity'=>9,'unit_price'=>4]]])->assertUnprocessable();
    }
    public function test_disposal_is_audited_scoped_and_never_revenue(): void {
        [,,$product] = $this->setupInventory();
        $batch = $this->batch($product,'2026-11-01');
        $this->postJson('/api/stock-batches/'.$batch->id.'/dispose',['quantity'=>2,'reason'=>'Expired'])->assertCreated();
        $this->assertDatabaseHas('waste_records',['stock_batch_id'=>$batch->id,'quantity'=>2,'reason'=>'Expired']);
        $this->assertEquals(3,$batch->fresh()->quantity_remaining);
        $this->assertEquals(3,$product->fresh()->stock_quantity);
        $this->assertDatabaseCount('sales',0);
        $this->postJson('/api/stock-batches/'.$batch->id.'/dispose',['quantity'=>4,'reason'=>'Expired'])->assertUnprocessable();
        $other = User::factory()->create(['business_id'=>Business::factory()->create()->id]);
        $this->actingAs($other,'sanctum')->postJson('/api/stock-batches/'.$batch->id.'/dispose',['quantity'=>1,'reason'=>'Expired'])->assertForbidden();
    }
    public function test_opening_stock_without_expiry_and_legacy_receipts_remain_consistent(): void {
        [$business] = $this->setupInventory();
        $response = $this->postJson('/api/products',['name'=>'Undated','stock_quantity'=>8,'selling_price'=>3,'default_cost'=>1])->assertCreated();
        $product = Product::findOrFail($response->json('id'));
        $this->assertNull($product->batches()->sole()->expiry_date);
        $legacy = Product::factory()->create(['business_id'=>$business->id,'stock_quantity'=>9]);
        $this->postJson('/api/purchases',['purchase_date'=>'2026-11-13','items'=>[['product_id'=>$legacy->id,'quantity'=>3,'unit_cost'=>1,'expiry_date'=>'2026-12-01']]])->assertCreated();
        $this->assertEquals(12,$legacy->batches()->sum('quantity_remaining'));
        $this->assertEquals(12,$legacy->fresh()->stock_quantity);
        $this->putJson('/api/inventory-expiration/settings',['days'=>14])->assertOk();
        $this->assertEquals(14,$business->fresh()->expiry_warning_days);
    }

    public function test_batch_edits_are_audited_and_staff_cannot_change_expiration(): void {
        [$business,,$product] = $this->setupInventory();
        $batch = $this->batch($product,'2026-11-20');
        $this->patchJson('/api/stock-batches/'.$batch->id,['batch_code'=>'UPDATED','expiry_date'=>null])->assertOk();
        $this->assertDatabaseHas('batch_audits',['stock_batch_id'=>$batch->id]);
        $this->assertNull($batch->fresh()->expiry_date);
        $this->getJson('/api/inventory-expiration')->assertJsonPath('batches.total',0);
        $this->assertNotNull(WorkspaceNotification::where('type','inventory.expiring_soon')->sole()->read_at);
        $staff = User::factory()->create(['business_id'=>$business->id,'role'=>'staff']);
        $this->actingAs($staff,'sanctum')->putJson('/api/inventory-expiration/settings',['days'=>60])->assertForbidden();
        $this->patchJson('/api/stock-batches/'.$batch->id,['batch_code'=>'STAFF','expiry_date'=>'2026-12-01'])->assertForbidden();
    }

    public function test_general_waste_keeps_legacy_batches_consistent_and_products_do_not_leak(): void {
        [$business,,$product] = $this->setupInventory();
        $this->batch($product,'2026-11-01');
        $this->batch($product,null);
        $this->postJson('/api/waste',['product_id'=>$product->id,'quantity'=>6,'reason'=>'Damaged'])->assertCreated();
        $this->assertEquals(4,$product->fresh()->stock_quantity);
        $this->assertEquals(4,$product->batches()->sum('quantity_remaining'));
        $other = Product::factory()->create(['business_id'=>Business::factory()->create()->id]);
        $this->batch($other,null,100);
        $this->getJson('/api/products')->assertJsonCount(1)->assertJsonPath('0.sellable_quantity',4);
        $this->getJson('/api/products/'.$other->id)->assertForbidden();
    }
}
