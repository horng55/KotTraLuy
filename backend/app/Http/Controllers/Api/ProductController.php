<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use Illuminate\Http\Request;
use App\Models\InventoryAlert;
use App\Services\InventoryAlertService;
use App\Services\ProductImageService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function store(Request $request, ProductImageService $images)
    {
        if (!$request->user()->canDo('inventory.manage')) {
            return response()->json(['message' => 'Owner access only.'], 403);
        }

        abort_if($request->has('default_cost') && !$request->user()->canDo('reports.financial'), 403, 'Financial permission is required to change product costs.');
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
            'image' => 'nullable|file|mimes:jpeg,jpg,png,webp|max:5120',
        ]);
        $imageKey = null;
        try {
            $product = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $data, $images, &$imageKey) {
                \App\Models\Business::whereKey($request->user()->business_id)->lockForUpdate()->firstOrFail();
                $product = Product::create([
                    'business_id' => $request->user()->business_id,
                    'category_id' => $data['category_id'] ?? null,
                    'name' => $data['name'],
                    'sku' => $data['sku'] ?? null,
                    'unit' => $data['unit'] ?? 'pcs',
                    'selling_price' => $data['selling_price'] ?? 0,
                    'default_cost' => $data['default_cost'] ?? 0,
                    'minimum_stock' => $data['minimum_stock'] ?? 0,
                    'stock_quantity' => (int) ($data['stock_quantity'] ?? 0),
                    'is_active' => $data['is_active'] ?? true,
                ]);
                if ($request->hasFile('image')) {
                    $imageKey = $images->store($request->file('image'), (int) $product->business_id, (int) $product->id);
                    $product->update(['image_storage_key' => $imageKey]);
                }
                if ($product->stock_quantity > 0) {
                    \App\Models\StockBatch::create([
                        'business_id' => $product->business_id,
                        'product_id' => $product->id,
                        'batch_code' => $data['batch_code'] ?? 'OPEN-'.$product->id,
                        'received_date' => now($request->user()->business->timezone ?: 'Asia/Phnom_Penh')->toDateString(),
                        'quantity_received' => $product->stock_quantity,
                        'quantity_remaining' => $product->stock_quantity,
                        'unit_cost' => $product->default_cost,
                        'expiry_date' => $data['expiry_date'] ?? null,
                    ]);
                }
                return $product->fresh();
            });
        } catch (\InvalidArgumentException $e) {
            if ($imageKey) $images->delete($imageKey);
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_product_image'], 422);
        } catch (\Throwable $e) {
            if ($imageKey) $images->delete($imageKey);
            report($e);
            return response()->json(['message' => 'The product could not be saved right now.', 'code' => 'product_save_failed'], 500);
        }
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
        return response()->json(InventoryAlert::where('business_id', $request->user()->business_id)->whereNull('resolved_at')->with(['product', 'stockBatch'])->latest()->get()->map(function ($alert) use ($request) {
            $alert->setAttribute('time_remaining', $alert->stockBatch?->expiry_date ? app(\App\Services\ExpirationCountdown::class)->format($alert->stockBatch->expiry_date->toDateString(), $request->user()->preferred_language ?: 'en') : null);
            return $alert;
        }));
    }

    public function update(Request $request, Product $product, ProductImageService $images)
    {
        if ((int) $product->business_id !== (int) $request->user()->business_id) {
            return response()->json(['message' => 'You do not have access to this business.'], 403);
        }

        if (!$request->user()->canDo('inventory.manage')) {
            return response()->json(['message' => 'Owner access only.'], 403);
        }

        abort_if($request->has('default_cost') && !$request->user()->canDo('reports.financial'), 403, 'Financial permission is required to change product costs.');
        $data = $request->validate([
            'name' => 'sometimes|required|string',
            'sku' => 'nullable|string',
            'category_id' => ['nullable', \Illuminate\Validation\Rule::exists('product_categories','id')->where('business_id',$request->user()->business_id)],
            'unit' => 'nullable|string',
            'selling_price' => 'sometimes|numeric|min:0',
            'default_cost' => 'sometimes|numeric|min:0',
            'minimum_stock' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
            'image' => 'nullable|file|mimes:jpeg,jpg,png,webp|max:5120',
            'remove_image' => 'sometimes|boolean',
        ]);
        $oldKey = $product->image_storage_key; $newKey = null;
        unset($data['image'], $data['remove_image']);
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($product, $data, $request, $images, &$newKey) {
                $product->update($data);
                if ($request->hasFile('image')) {
                    $newKey = $images->store($request->file('image'), (int) $product->business_id, (int) $product->id);
                    $product->update(['image_storage_key' => $newKey]);
                } elseif ($request->boolean('remove_image')) {
                    $product->update(['image_storage_key' => null]);
                }
            });
        } catch (\InvalidArgumentException $e) {
            if ($newKey) $images->delete($newKey);
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_product_image'], 422);
        } catch (\Throwable $e) {
            if ($newKey) $images->delete($newKey);
            throw $e;
        }
        if ($oldKey && ($newKey || $request->boolean('remove_image'))) $images->delete($oldKey);

        return response()->json($product);
    }

    public function image(Request $request, Product $product): StreamedResponse
    {
        abort_unless((int) $product->business_id === (int) $request->user()->business_id, 403);
        abort_unless($product->image_storage_key && Storage::disk('local')->exists($product->image_storage_key), 404);
        $mime = match (strtolower(pathinfo($product->image_storage_key, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'image/webp',
        };
        return Storage::disk('local')->response($product->image_storage_key, basename($product->image_storage_key), ['Content-Type' => $mime, 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, Product $product)
    {
        if ((int) $product->business_id !== (int) $request->user()->business_id) {
            return response()->json(['message' => 'You do not have access to this business.'], 403);
        }

        if (!$request->user()->canDo('inventory.manage')) {
            return response()->json(['message' => 'Owner access only.'], 403);
        }

        $product->update(['is_active'=>false]);

        return response()->json(['message' => 'Product deleted successfully']);
    }
}
