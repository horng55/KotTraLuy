<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\WasteRecord;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WasteController
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'reason' => 'required|string',
            'notes' => 'nullable|string',
            'shift_id' => 'nullable|exists:shifts,id',
        ]);

        $product = Product::where('business_id', $request->user()->business_id)->findOrFail($data['product_id']);
        $shiftId = $this->resolveShiftId($request, $data['shift_id'] ?? null);

        if ($product->stock_quantity < $data['quantity']) {
            return response()->json(['message' => 'Waste quantity exceeds available stock.'], 422);
        }

        $record = DB::transaction(function () use ($request, $product, $data, $shiftId) {
            \App\Models\Business::whereKey($product->business_id)->lockForUpdate()->firstOrFail();
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($product->stock_quantity < $data['quantity']) throw \Illuminate\Validation\ValidationException::withMessages(['quantity'=>['Waste quantity exceeds available stock.']]);
            \App\Services\BatchInventory::preserveLegacy($product);
            $remaining = $data['quantity'];
            foreach ($product->batches()->where('quantity_remaining','>',0)->orderByRaw('expiry_date IS NULL, expiry_date ASC')->lockForUpdate()->get() as $batch) {
                $take = min($remaining,$batch->quantity_remaining);
                $batch->decrement('quantity_remaining',$take); $remaining -= $take;
                if (!$remaining) break;
            }
            if ($remaining) throw \Illuminate\Validation\ValidationException::withMessages(['quantity'=>['Waste quantity exceeds available stock.']]);
            $record = WasteRecord::create([
                'business_id' => $request->user()->business_id,
                'shift_id' => $shiftId,
                'user_id' => $request->user()->id,
                'product_id' => $product->id,
                'quantity' => $data['quantity'],
                'unit_cost_snapshot' => $product->default_cost,
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'recorded_at' => now(),
            ]);

            $product->stock_quantity = (int) $product->stock_quantity - (int) $data['quantity'];
            $product->save();

            return $record;
        });

        return response()->json(['data' => $record], 201);
    }

    private function resolveShiftId(Request $request, ?int $requestedShiftId): ?int
    {
        $query = Shift::where('business_id', $request->user()->business_id);

        if ($requestedShiftId !== null) {
            $shift = $query->whereKey($requestedShiftId)->firstOrFail();
            if ($request->user()->role !== 'owner' && (int) $shift->user_id !== (int) $request->user()->id) {
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
