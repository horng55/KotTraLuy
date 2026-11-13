<?php
namespace App\Services;
use App\Models\Business;
use App\Models\InventoryAlert;
use App\Models\Product;
use App\Models\StockBatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class InventoryAlertService
{
    public function refresh(Business $business): void
    {
        DB::transaction(function () use ($business) {
        // Serialize refreshes for one workspace, including scheduled/HTTP overlap.
        $business = Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
        InventoryAlert::where('business_id', $business->id)->whereNull('resolved_at')->whereNotIn('product_id', $business->products()->where('is_active', true)->select('id'))->update(['resolved_at' => now()]);
        $today = Carbon::now($business->timezone ?: 'Asia/Phnom_Penh')->toDateString();
        $soon = Carbon::parse($today)->addDays((int) $business->expiry_warning_days)->toDateString();
        foreach ($business->products()->where('is_active', true)->with('batches')->lazyById(200) as $product) {
            $batchQuantity = $product->batches->filter(fn ($batch) => $batch->quantity_remaining > 0 && (!$batch->expiry_date || $batch->expiry_date->toDateString() >= $today))->sum('quantity_remaining');
            $sellable = $product->batches->isNotEmpty() ? (int) $batchQuantity : (int) $product->stock_quantity;
            $this->sync($business, $product, null, $sellable === 0 ? 'out_of_stock' : (($sellable <= $product->minimum_stock) ? 'low_stock' : null));
            foreach ($product->batches as $batch) {
                $condition = $batch->quantity_remaining <= 0 ? null : ($batch->expiry_date === null ? null : ($batch->expiry_date->toDateString() < $today ? 'expired' : ($batch->expiry_date->toDateString() === $today ? 'expires_today' : ($batch->expiry_date->toDateString() <= $soon ? 'expiring_soon' : null))));
                $this->sync($business, $product, $batch, $condition);
            }
        }
        \App\Models\WorkspaceNotification::where('business_id',$business->id)->whereIn('type',['inventory.expired','inventory.expires_today','inventory.expiring_soon'])->whereNull('data->resolved_at')->get()->each(function ($notification) use ($today,$soon) {
            $params = $notification->data['params'] ?? [];
            $batch = StockBatch::where('business_id',$notification->business_id)->find($params['batch_id'] ?? 0);
            $date = $batch?->expiry_date?->toDateString();
            $condition = !$date || !$batch->quantity_remaining || !$batch->product?->is_active ? null : ($date < $today ? 'expired' : ($date === $today ? 'expires_today' : ($date <= $soon ? 'expiring_soon' : null)));
            if ($notification->type !== 'inventory.'.$condition || $date !== ($params['expiry_date'] ?? null)) {
                $data = $notification->data; $data['resolved_at']=now()->toIso8601String(); $notification->update(['read_at'=>$notification->read_at ?? now(),'data'=>$data]);
            }
        });
        });
    }
    private function sync(Business $business, Product $product, ?StockBatch $batch, ?string $condition): void
    {
        $query = InventoryAlert::where('business_id', $business->id)->where('product_id', $product->id);
        if ($batch) $query->where('stock_batch_id', $batch->id); else $query->whereNull('stock_batch_id');
        if ($condition) {
            (clone $query)->where('condition', '!=', $condition)->whereNull('resolved_at')->update(['resolved_at' => now()]);
            $query->where('condition', $condition)->whereNull('resolved_at')->firstOrCreate(['business_id'=>$business->id,'product_id'=>$product->id,'stock_batch_id'=>$batch?->id,'condition'=>$condition]);
            if ($batch && in_array($condition,['expired','expires_today','expiring_soon'])) {
                $params = ['product'=>$product->name,'quantity'=>$batch->quantity_remaining,'unit'=>$product->unit,'expiry_date'=>$batch->expiry_date->toDateString(),'batch_id'=>$batch->id];
                $event = 'expiration:'.$batch->id.':'.$batch->expiry_date->toDateString().':'.$condition.':'.$business->expiry_warning_days;
                app(WorkspaceNotifier::class)->publish($business->id,'inventory.'.$condition,$event,$params,'product',$product->id);
                \App\Models\WorkspaceNotification::where('business_id',$business->id)->where('event_key',$event)->get()->each(function ($notification) use ($params) { $data = $notification->data; if (($data['params'] ?? []) !== $params) { $data['params']=$params; $notification->update(['data'=>$data]); } });
            }
        }
        else { $query->whereNull('resolved_at')->update(['resolved_at'=>now()]); }
        $this->resolveNotifications($business, $product, $batch, $condition);
    }

    private function resolveNotifications(Business $business, Product $product, ?StockBatch $batch, ?string $condition): void
    {
        \App\Models\WorkspaceNotification::where('business_id', $business->id)
            ->whereIn('type', ['inventory.low_stock', 'inventory.out_of_stock', 'inventory.expired', 'inventory.expires_today', 'inventory.expiring_soon'])
            ->whereNull('data->resolved_at')->get()->each(function ($notification) use ($product, $batch, $condition) {
                $data = $notification->data;
                if (($data['entity_type'] ?? null) !== 'product' || (int) ($data['entity_id'] ?? 0) !== (int) $product->id) return;
                $params = $data['params'] ?? [];
                if ((int) ($params['batch_id'] ?? 0) !== (int) ($batch?->id ?? 0)) return;
                $expected = $condition ? 'inventory.'.$condition : null;
                if ($notification->type === $expected) return;
                $data['resolved_at'] = now()->toIso8601String();
                $notification->update(['read_at' => $notification->read_at ?? now(), 'data' => $data]);
            });
    }
}
