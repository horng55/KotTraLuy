<?php
namespace App\Http\Controllers\Api;

use App\Models\{Business, Product, StockBatch, StockMovement, WasteRecord};
use App\Services\InventoryAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpirationController
{
    public function index(Request $request) {
        $business = $request->user()->business->fresh();
        app(InventoryAlertService::class)->refresh($business);
        $today = now($business->timezone ?: 'Asia/Phnom_Penh')->startOfDay();
        $end = $today->copy()->addDays($business->expiry_warning_days + 1)->toDateString();
        $base = StockBatch::where('business_id',$business->id)->where('quantity_remaining','>',0)
            ->whereHas('product',fn ($q) => $q->where('is_active',true));
        $expired = (clone $base)->where('expiry_date','<',$today->toDateString());
        $soon = (clone $base)->where('expiry_date','>=',$today->toDateString())->where('expiry_date','<',$end);
        $rows = (clone $base)->whereNotNull('expiry_date')->where('expiry_date','<',$end)
            // image_storage_key remains hidden from JSON, but is needed by the
            // Product image_available accessor used by the shared thumbnail.
            ->with('product:id,name,unit,image_storage_key')->orderBy('expiry_date')->orderBy('id')->paginate(10);
        $rows->getCollection()->transform(function ($batch) use ($today) {
            $days = (int) $today->diffInDays($batch->expiry_date,false);
            return [...$batch->toArray(),'days_remaining'=>$days,'expiration_status'=>$days < 0 ? 'expired' : ($days === 0 ? 'expires_today' : 'expiring_soon')];
        });
        return response()->json(['expired'=>(clone $expired)->distinct()->count('product_id'),
            'expiring_soon'=>(clone $soon)->distinct()->count('product_id'),
            'expired_ids'=>$expired->distinct()->pluck('product_id'), 'expiring_ids'=>$soon->distinct()->pluck('product_id'),
            'threshold'=>$business->expiry_warning_days,'business_date'=>$today->toDateString(),'batches'=>$rows]);
    }

    public function settings(Request $request) {
        abort_unless($request->user()->role === 'owner',403);
        $data = $request->validate(['days'=>'required|integer|min:1|max:3650']);
        $request->user()->business->forceFill(['expiry_warning_days'=>$data['days']])->save();
        app(InventoryAlertService::class)->refresh($request->user()->business->fresh());
        return response()->json(['days'=>$data['days']]);
    }

    public function update(Request $request, StockBatch $batch) {
        abort_unless($request->user()->role === 'owner' && (int)$batch->business_id === (int)$request->user()->business_id,403);
        $data = $request->validate(['expiry_date'=>'nullable|date_format:Y-m-d','batch_code'=>'required|string|max:100']);
        DB::transaction(function () use ($batch,$data) {
            Business::whereKey($batch->business_id)->lockForUpdate()->firstOrFail();
            $locked = StockBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $before = $locked->only(['expiry_date','batch_code']);
            $locked->update($data);
            DB::table('batch_audits')->insert(['stock_batch_id'=>$batch->id,'user_id'=>auth()->id(),'before_data'=>json_encode($before),'after_data'=>json_encode($data),'created_at'=>now()]);
        });
        return response()->json($batch->fresh());
    }

    public function dispose(Request $request, StockBatch $batch) {
        abort_unless($request->user()->role === 'owner' && (int)$batch->business_id === (int)$request->user()->business_id,403);
        $data = $request->validate(['quantity'=>'required|integer|min:1','reason'=>'required|string|max:1000']);
        $record = DB::transaction(function () use ($request,$batch,$data) {
            Business::whereKey($batch->business_id)->lockForUpdate()->firstOrFail();
            $product = Product::whereKey($batch->product_id)->lockForUpdate()->firstOrFail();
            $batch = StockBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->quantity_remaining < $data['quantity'] || $product->stock_quantity < $data['quantity']) {
                throw ValidationException::withMessages(['quantity'=>['Waste quantity exceeds available stock.']]);
            }
            $record = WasteRecord::create(['business_id'=>$batch->business_id,'user_id'=>$request->user()->id,
                'product_id'=>$product->id,'stock_batch_id'=>$batch->id,'quantity'=>$data['quantity'],
                'reason'=>$data['reason'],'unit_cost_snapshot'=>$batch->unit_cost,'recorded_at'=>now()]);
            $batch->decrement('quantity_remaining',$data['quantity']);
            $product->decrement('stock_quantity',$data['quantity']);
            StockMovement::create(['business_id'=>$batch->business_id,'product_id'=>$product->id,'movement_type'=>'waste',
                'reference_type'=>'waste','reference_id'=>$record->id,'quantity_in'=>0,'quantity_out'=>$data['quantity'],
                'unit_cost'=>$batch->unit_cost,'occurred_at'=>now(),'created_by'=>$request->user()->id]);
            app(InventoryAlertService::class)->refresh($request->user()->business);
            return $record;
        });
        return response()->json(['data'=>$record],201);
    }
}
