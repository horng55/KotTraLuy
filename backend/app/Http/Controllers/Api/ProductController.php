<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use Illuminate\Http\Request;
use App\Models\InventoryAlert;
use App\Services\InventoryAlertService;

class ProductController
{
    public function index(Request $request)
    {
        return response()->json(
            Product::where('business_id', $request->user()->business_id)
                ->withSum(['batches as sellable_quantity' => fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date','>=',now($request->user()->business->timezone ?: 'Asia/Phnom_Penh')->toDateString())], 'quantity_remaining')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request)
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Owner access only.'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string',
            'sku' => 'nullable|string',
            'category_id' => ['nullable', \Illuminate\Validation\Rule::exists('product_categories','id')->where('business_id',$request->user()->business_id)],
            'unit' => 'nullable|string',
            'selling_price' => 'numeric|min:0',
            'default_cost' => 'numeric|min:0',
            'minimum_stock' => 'nullable|integer|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'expiry_date' => 'nullable|date_format:Y-m-d',
            'batch_code' => 'nullable|string|max:100',
        ]);

        $product = \Illuminate\Support\Facades\DB::transaction(function () use ($request,$data) {
        \App\Models\Business::whereKey($request->user()->business_id)->lockForUpdate()->firstOrFail();
        $product = Product::create([
            ...$data,
            'business_id' => $request->user()->business_id,
            'stock_quantity' => (int) ($data['stock_quantity'] ?? 0),
        ]);

        if ($product->stock_quantity > 0) {
            \App\Models\StockBatch::create(['business_id'=>$product->business_id,'product_id'=>$product->id,'batch_code'=>$data['batch_code'] ?? 'OPEN-'.$product->id,'received_date'=>now($request->user()->business->timezone ?: 'Asia/Phnom_Penh')->toDateString(),'quantity_received'=>$product->stock_quantity,'quantity_remaining'=>$product->stock_quantity,'unit_cost'=>$product->default_cost,'expiry_date'=>$data['expiry_date'] ?? null]);
        }
        return $product;
        });
        return response()->json($product, 201);
    }

    public function show(Request $request, Product $product)
    {
        if ((int) $product->business_id !== (int) $request->user()->business_id) {
            return response()->json(['message' => 'You do not have access to this business.'], 403);
        }

        $product->load('batches');
        $business=$request->user()->business; $today=now($business->timezone ?: 'Asia/Phnom_Penh')->startOfDay();
        foreach ($product->batches as $batch) {
            $days=$batch->expiry_date ? (int) $today->diffInDays($batch->expiry_date,false) : null;
            $batch->setAttribute('expiration_status',$days === null ? 'no_expiration' : ($days < 0 ? 'expired' : ($days === 0 ? 'expires_today' : ($days <= $business->expiry_warning_days ? 'expiring_soon' : 'safe'))));
        }
        return response()->json($product);
    }

    public function alerts(Request $request)
    {
        app(InventoryAlertService::class)->refresh($request->user()->business);
        return response()->json(InventoryAlert::where('business_id', $request->user()->business_id)->whereNull('resolved_at')->with(['product', 'stockBatch'])->latest()->get());
    }

    public function update(Request $request, Product $product)
    {
        if ((int) $product->business_id !== (int) $request->user()->business_id) {
            return response()->json(['message' => 'You do not have access to this business.'], 403);
        }

        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Owner access only.'], 403);
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string',
            'sku' => 'nullable|string',
            'category_id' => 'nullable|exists:product_categories,id',
            'unit' => 'nullable|string',
            'selling_price' => 'sometimes|numeric|min:0',
            'default_cost' => 'sometimes|numeric|min:0',
            'minimum_stock' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $product->update($data);

        return response()->json($product);
    }

    public function destroy(Request $request, Product $product)
    {
        if ((int) $product->business_id !== (int) $request->user()->business_id) {
            return response()->json(['message' => 'You do not have access to this business.'], 403);
        }

        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Owner access only.'], 403);
        }

        $product->update(['is_active'=>false]);

        return response()->json(['message' => 'Product deleted successfully']);
    }
}
