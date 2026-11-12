<?php
namespace App\Services;

use App\Models\Product;
use App\Models\StockBatch;

class BatchInventory
{
    // Caller holds the product lock. Preserve undated historical stock before a receipt.
    public static function preserveLegacy(Product $product): void
    {
        if (!$product->batches()->exists() && $product->stock_quantity > 0) {
            StockBatch::create(['business_id'=>$product->business_id, 'product_id'=>$product->id,
                'batch_code'=>'LEGACY-'.$product->id, 'received_date'=>$product->created_at->toDateString(),
                'quantity_received'=>$product->stock_quantity, 'quantity_remaining'=>$product->stock_quantity,
                'unit_cost'=>$product->default_cost, 'expiry_date'=>null]);
        }
    }
}
