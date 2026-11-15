<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Shift;
use App\Models\StockBatch;
use App\Services\InventoryAlertService;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController
{
    public function index(Request $request)
    {
        return response()->json(
            Purchase::where('business_id', $request->user()->business_id)
                ->with('items.product')
                ->orderByDesc('purchase_date')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_name' => 'nullable|string',
            'purchase_date' => 'required|date',
            'reference_no' => 'nullable|string',
            'notes' => 'nullable|string',
            'currency' => 'nullable|in:USD,KHR',
            'shift_id' => 'nullable|exists:shifts,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.batch_code' => 'nullable|string|max:100',
            'items.*.received_date' => 'nullable|date',
            'items.*.expiry_date' => 'nullable|date_format:Y-m-d',
        ]);

        $shiftId = $this->resolveShiftId($request, $data['shift_id'] ?? null);

        $purchase = DB::transaction(function () use ($request, $data, $shiftId) {
            $business = $request->user()->business;
            \App\Models\Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $purchase = Purchase::create([
                'business_id' => $business->id,
                'currency' => $data['currency'] ?? $business->currency,
                'shift_id' => $shiftId,
                'user_id' => $request->user()->id,
                'supplier_name' => $data['supplier_name'] ?? null,
                'purchase_date' => $data['purchase_date'],
                'reference_no' => $data['reference_no'] ?? null,
                'notes' => $data['notes'] ?? null,
                'total_amount' => 0,
            ]);

            $total = 0;
            foreach ($data['items'] as $item) {
                $product = Product::where('business_id', $business->id)->lockForUpdate()->findOrFail($item['product_id']);
                \App\Services\BatchInventory::preserveLegacy($product);
                $lineTotal = $item['quantity'] * $item['unit_cost'];

                $purchase->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'line_total' => $lineTotal,
                ]);

                StockBatch::create([
                    'business_id' => $business->id, 'product_id' => $product->id, 'purchase_id' => $purchase->id,
                    'batch_code' => $item['batch_code'] ?? ('PUR-'.$purchase->id.'-'.$product->id),
                    'received_date' => $item['received_date'] ?? $data['purchase_date'],
                    'quantity_received' => $item['quantity'], 'quantity_remaining' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'], 'expiry_date' => $item['expiry_date'] ?? null,
                ]);

                $product->stock_quantity = (int) $product->stock_quantity + (int) $item['quantity'];
                $product->save();
                StockMovement::create(['business_id'=>$business->id,'product_id'=>$product->id,'shift_id'=>$shiftId,'movement_type'=>'receipt','reference_type'=>'purchase','reference_id'=>$purchase->id,'quantity_in'=>$item['quantity'],'quantity_out'=>0,'unit_cost'=>$item['unit_cost'],'occurred_at'=>now(),'created_by'=>$request->user()->id]);

                $total += $lineTotal;
            }

            $purchase->total_amount = $total;
            $purchase->save();

            app(InventoryAlertService::class)->refresh($business);
            return $purchase->load('items.product');
        });

        return response()->json(['data' => $purchase], 201);
    }

    public function show(Request $request, Purchase $purchase)
    {
        if ((int) $purchase->business_id !== (int) $request->user()->business_id) {
            return response()->json(['message' => 'You do not have access to this business.'], 403);
        }

        return response()->json($purchase->load('items.product'));
    }

    public function update(Request $request, Purchase $purchase)
    {
        if ((int) $purchase->business_id !== (int) $request->user()->business_id) {
            return response()->json(['message' => 'You do not have access to this business.'], 403);
        }

        $data = $request->validate([
            'supplier_name' => 'nullable|string',
            'purchase_date' => 'sometimes|date',
            'reference_no' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $purchase->update($data);

        return response()->json($purchase->fresh());
    }

    public function destroy(Request $request, Purchase $purchase)
    {
        if ((int) $purchase->business_id !== (int) $request->user()->business_id) {
            return response()->json(['message' => 'You do not have access to this business.'], 403);
        }

        $purchase->delete();

        return response()->json(['message' => 'Purchase deleted successfully']);
    }

    private function resolveShiftId(Request $request, ?int $requestedShiftId): ?int
    {
        $query = Shift::where('business_id', $request->user()->business_id);

        if ($requestedShiftId !== null) {
            $shift = $query->whereKey($requestedShiftId)->firstOrFail();
            if (!$request->user()->canDo('shifts.manage') && (int) $shift->user_id !== (int) $request->user()->id) {
                abort(403, 'You do not have access to this shift.');
            }

            return $shift->id;
        }

        return (clone $query)
            ->where('user_id', $request->user()->id)
            ->where('status', 'open')
            ->value('id');
    }
}
