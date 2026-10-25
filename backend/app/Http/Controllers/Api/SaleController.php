<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\WasteRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController
{
    /**
     * API គណនាទិន្នន័យប្រតិបត្តិការប្រចាំថ្ងៃសម្រាប់ Dashboard
     */
    public function dashboardStats(Request $request)
    {
        // យក business_id ពី user ដែល login ឬ fallback 1 បើតេស្តដោយគ្មាន auth token
        $businessId = $request->user()?->business_id ?? 1;
        $today = now()->toDateString();

        // 1. Revenue ថ្ងៃនេះ (បូកសរុបទាំងពី Telegram Bot និង Web)
        $revenue = (float) Sale::where('business_id', $businessId)
            ->whereDate('sold_at', $today)
            ->sum('total_amount');

        // 2. Sales Count ថ្ងៃនេះ
        $salesCount = Sale::where('business_id', $businessId)
            ->whereDate('sold_at', $today)
            ->count();

        // 3. Purchase Value ថ្ងៃនេះ
        $purchaseValue = (float) Purchase::where('business_id', $businessId)
            ->whereDate('purchase_date', $today)
            ->sum('total_amount');

        // 4. Waste ថ្ងៃនេះ
        $waste = (float) WasteRecord::where('business_id', $businessId)
            ->whereDate('recorded_at', $today)
            ->selectRaw('COALESCE(SUM(quantity * unit_cost_snapshot), 0) as total')
            ->value('total');

        // 5. បញ្ជីទំនិញក្នុងស្តុកសម្រាប់ Dropdown (Quick Sale / Quick Purchase)
        $products = Product::where('business_id', $businessId)->get();

        return response()->json([
            'revenue' => $revenue,
            'expenses' => $purchaseValue + $waste,
            'purchaseValue' => $purchaseValue,
            'waste' => $waste,
            'salesCount' => $salesCount,
            'products' => $products
        ]);
    }

    public function index(Request $request)
    {
        return response()->json(
            Sale::where('business_id', $request->user()->business_id)
                ->with('items.product')
                ->orderByDesc('sold_at')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sold_at' => 'required|date',
            'notes' => 'nullable|string',
            'shift_id' => 'nullable|exists:shifts,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        $shiftId = $this->resolveShiftId($request, $data['shift_id'] ?? null);

        $sale = DB::transaction(function () use ($request, $data, $shiftId) {
            $business = $request->user()->business;
            $sale = Sale::create([
                'business_id' => $business->id,
                'shift_id' => $shiftId,
                'user_id' => $request->user()->id,
                'sale_no' => 'SALE-' . now()->timestamp,
                'sold_at' => $data['sold_at'],
                'notes' => $data['notes'] ?? null,
                'total_amount' => 0,
            ]);

            $total = 0;
            foreach ($data['items'] as $item) {
                $product = Product::where('business_id', $business->id)->findOrFail($item['product_id']);

                if ($product->stock_quantity < $item['quantity']) {
                    throw new \InvalidArgumentException('Insufficient stock for product: ' . $product->name);
                }

                $lineTotal = $item['quantity'] * $item['unit_price'];

                $sale->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'unit_cost_snapshot' => $product->default_cost,
                    'line_total' => $lineTotal,
                ]);

                $product->stock_quantity = (int) $product->stock_quantity - (int) $item['quantity'];
                $product->save();

                $total += $lineTotal;
            }

            $sale->total_amount = $total;
            $sale->save();

            return $sale->load('items.product');
        });

        return response()->json(['data' => $sale], 201);
    }

    public function show(Request $request, Sale $sale)
    {
        if ((int) $sale->business_id !== (int) $request->user()->business_id) {
            return response()->json(['message' => 'You do not have access to this business.'], 403);
        }

        return response()->json($sale->load('items.product'));
    }

    public function update(Request $request, Sale $sale)
    {
        if ((int) $sale->business_id !== (int) $request->user()->business_id) {
            return response()->json(['message' => 'You do not have access to this business.'], 403);
        }

        $data = $request->validate([
            'sold_at' => 'sometimes|date',
            'notes' => 'nullable|string',
        ]);

        $sale->update($data);

        return response()->json($sale->fresh());
    }

    public function destroy(Request $request, Sale $sale)
    {
        if ((int) $sale->business_id !== (int) $request->user()->business_id) {
            return response()->json(['message' => 'You do not have access to this business.'], 403);
        }

        $sale->delete();

        return response()->json(['message' => 'Sale deleted successfully']);
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
