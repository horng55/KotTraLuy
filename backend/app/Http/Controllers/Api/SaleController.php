<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\WasteRecord;
use App\Models\StockBatch;
use App\Models\SalePayment;
use App\Services\InventoryAlertService;
use App\Models\StockMovement;
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
                ->with(['items.product', 'payments'])
                ->orderByDesc('sold_at')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sold_at' => 'required|date',
            'notes' => 'nullable|string',
            'currency' => 'nullable|in:USD,KHR',
            'shift_id' => 'nullable|exists:shifts,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'allocations' => 'nullable|array',
            'allocations.*.product_id' => 'required|integer',
            'allocations.*.batch_id' => 'required|integer',
            'allocations.*.quantity' => 'required|integer|min:1',
            'discount_amount' => 'nullable|numeric|min:0',
            'payment_received' => 'nullable|numeric|min:0',
            'idempotency_key' => 'nullable|string|max:100',
        ]);

        $shiftId = $this->resolveShiftId($request, $data['shift_id'] ?? null);

        if (!empty($data['idempotency_key'])) {
            $existing = Sale::where('business_id', $request->user()->business_id)->where('idempotency_key', $data['idempotency_key'])->with(['items.product', 'payments'])->first();
            if ($existing) return response()->json(['data' => $existing, 'duplicate' => true]);
        }

        $sale = DB::transaction(function () use ($request, $data, $shiftId) {
            $business = $request->user()->business;
            \App\Models\Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $sale = Sale::create([
                'business_id' => $business->id,
                'currency' => $data['currency'] ?? $business->currency,
                'shift_id' => $shiftId,
                'user_id' => $request->user()->id,
                'sale_no' => 'SALE-' . now()->timestamp,
                'sold_at' => $data['sold_at'],
                'notes' => $data['notes'] ?? null,
                'discount_amount' => $data['discount_amount'] ?? 0,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'total_amount' => 0,
            ]);

            $total = 0;
            foreach ($data['items'] as $item) {
                $product = Product::where('business_id', $business->id)->lockForUpdate()->findOrFail($item['product_id']);

                if (!$product->is_active || $product->stock_quantity < $item['quantity']) throw \Illuminate\Validation\ValidationException::withMessages(['items'=>['Insufficient non-expired stock.']]);
                $lineTotal = max(0, $item['quantity'] * $item['unit_price'] - ($item['discount'] ?? 0));
                $allocations = collect($data['allocations'] ?? [])->where('product_id', $product->id)->values();
                if ($allocations->isEmpty()) {
                    if (!$product->batches()->exists() && $product->stock_quantity > 0) {
                        StockBatch::create(['business_id'=>$business->id,'product_id'=>$product->id,'batch_code'=>'LEGACY-'.$product->id,'received_date'=>$product->created_at?->toDateString() ?? now()->toDateString(),'quantity_received'=>$product->stock_quantity,'quantity_remaining'=>$product->stock_quantity,'unit_cost'=>$product->default_cost,'expiry_date'=>null]);
                    }
                    $today = now($business->timezone ?: 'Asia/Phnom_Penh')->toDateString();
                    $allocations = StockBatch::where('business_id', $business->id)->where('product_id', $product->id)->where('quantity_remaining', '>', 0)->where(function ($q) use ($today) { $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today); })->orderByRaw('expiry_date IS NULL, expiry_date ASC')->orderBy('received_date')->orderBy('id')->lockForUpdate()->get()->map(fn ($batch) => ['batch_id' => $batch->id, 'quantity' => 0]);
                    $remaining = $item['quantity'];
                    $allocations = $allocations->map(function ($allocation) use (&$remaining) { $batch = StockBatch::find($allocation['batch_id']); $take = min($remaining, $batch->quantity_remaining); $remaining -= $take; $allocation['quantity'] = $take; return $allocation; })->filter(fn ($allocation) => $allocation['quantity'] > 0);
                }
                if ($allocations->sum('quantity') !== (int) $item['quantity']) throw \Illuminate\Validation\ValidationException::withMessages(['items'=>['Insufficient non-expired stock.']]);

                $sale->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'unit_cost_snapshot' => 0,
                    'line_total' => $lineTotal,
                ]);

                $saleItem = $sale->items()->latest('id')->first();
                $cost = 0; $itemQuantity = 0;
                foreach ($allocations as $allocation) {
                    $batch = StockBatch::where('business_id', $business->id)->whereKey($allocation['batch_id'])->lockForUpdate()->firstOrFail();
                    if ((int) $batch->product_id !== (int) $product->id || $batch->quantity_remaining < $allocation['quantity'] || ($batch->expiry_date && $batch->expiry_date->toDateString() < now($business->timezone ?: 'Asia/Phnom_Penh')->toDateString())) throw \Illuminate\Validation\ValidationException::withMessages(['items'=>['Batch allocation is no longer available. Please review again.']]);
                    $batch->quantity_remaining -= $allocation['quantity']; $batch->save();
                    $saleItem->allocations()->create(['sale_id'=>$sale->id,'stock_batch_id'=>$batch->id,'quantity'=>$allocation['quantity'],'unit_cost'=>$batch->unit_cost]);
                    $cost += (float) $batch->unit_cost * $allocation['quantity']; $itemQuantity += $allocation['quantity'];
                }
                $saleItem->unit_cost_snapshot = $itemQuantity ? $cost / $itemQuantity : 0; $saleItem->save();

                $product->stock_quantity = (int) $product->stock_quantity - (int) $item['quantity'];
                $product->save();
                StockMovement::create(['business_id'=>$business->id,'product_id'=>$product->id,'shift_id'=>$shiftId,'movement_type'=>'sale','reference_type'=>'sale','reference_id'=>$sale->id,'quantity_in'=>0,'quantity_out'=>$item['quantity'],'unit_cost'=>$saleItem->unit_cost_snapshot,'occurred_at'=>now(),'created_by'=>$request->user()->id]);

                $total += $lineTotal;
            }

            $sale->total_amount = max(0, $total - ($data['discount_amount'] ?? 0));
            $sale->payment_received = min((float) ($data['payment_received'] ?? 0), (float) $sale->total_amount);
            $sale->outstanding_balance = max(0, (float) $sale->total_amount - (float) $sale->payment_received);
            $sale->save();

            if ($sale->payment_received > 0) SalePayment::create(['business_id'=>$business->id,'sale_id'=>$sale->id,'user_id'=>$request->user()->id,'amount'=>$sale->payment_received,'currency'=>$sale->currency,'paid_at'=>now()]);
            app(InventoryAlertService::class)->refresh($business);

            return $sale->load('items.product');
        });

        return response()->json(['data' => $sale], 201);
    }

    public function addPayment(Request $request, Sale $sale)
    {
        if ((int) $sale->business_id !== (int) $request->user()->business_id) return response()->json(['message'=>'You do not have access to this business.'], 403);
        $data = $request->validate(['amount'=>'required|numeric|min:0.01','paid_at'=>'nullable|date','notes'=>'nullable|string','idempotency_key'=>'nullable|string|max:100']);
        if (!empty($data['idempotency_key']) && SalePayment::where('business_id',$sale->business_id)->where('idempotency_key',$data['idempotency_key'])->exists()) return response()->json(['data'=>$sale->load('payments'),'duplicate'=>true]);
        if ((float) $data['amount'] > (float) $sale->outstanding_balance) return response()->json(['message'=>'Payment exceeds outstanding balance.'],422);
        DB::transaction(function () use ($request,$sale,$data) { SalePayment::create(['business_id'=>$sale->business_id,'sale_id'=>$sale->id,'user_id'=>$request->user()->id,'amount'=>$data['amount'],'currency'=>$sale->currency,'paid_at'=>$data['paid_at'] ?? now(),'idempotency_key'=>$data['idempotency_key'] ?? null,'notes'=>$data['notes'] ?? null]); $sale->payment_received += $data['amount']; $sale->outstanding_balance = max(0,(float)$sale->total_amount-(float)$sale->payment_received); $sale->save(); });
        return response()->json(['data'=>$sale->fresh()->load('payments')]);
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
