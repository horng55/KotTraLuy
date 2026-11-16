<?php

namespace App\Observers;

use App\Models\Business;
use App\Models\Expense;
use App\Models\InventoryAlert;
use App\Models\PreparationPack;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Models\WorkspaceNotification;
use App\Services\WorkspaceNotifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BusinessNotificationObserver
{
    public function __construct(private WorkspaceNotifier $notifications) {}

    private function saleParams(Sale $sale): array
    {
        // Inserted models do not automatically hydrate database defaults (for
        // example, Telegram sales omit currency). Use the persisted value.
        return ['reference' => $sale->sale_no, 'amount' => (float) $sale->total_amount, 'currency' => $sale->currency ?? $sale->fresh()?->currency];
    }

    public function created(Model $model): void
    {
        if (!$model->business_id) {
            return;
        }

        if ($model instanceof Sale) {
            $actor = User::where('business_id', $model->business_id)->find($model->user_id);
            if ($actor) {
                $this->notifications->publish($model->business_id, 'sale.created', 'sale.created:'.$model->id, [...$this->saleParams($model), 'actor' => $actor->name], 'sale', $model->id, [$actor->id]);
            }
            $this->unpaid($model);
        } elseif ($model instanceof SalePayment) {
            $this->notifications->publish($model->business_id, 'payment.received', 'payment.received:'.$model->id, ['amount' => (float) $model->amount, 'currency' => $model->currency, 'reference' => $model->sale?->sale_no], 'sale', $model->sale_id, [$model->sale?->user_id]);
        } elseif ($model instanceof Expense) {
            $actor = User::where('business_id', $model->business_id)->find($model->user_id);
            if ($actor?->role === 'staff') {
                $this->notifications->publish($model->business_id, 'expense.created', 'expense.created:'.$model->id, ['amount' => (float) $model->amount, 'currency' => $model->currency, 'actor' => $actor->name, 'description' => $model->description], 'expense', $model->id, [$actor->id]);
            }
        } elseif ($model instanceof InventoryAlert && in_array($model->condition, ['low_stock', 'out_of_stock'], true)) {
            $product = Product::where('business_id', $model->business_id)->find($model->product_id);
            if ($product) {
                $today = now(Business::find($model->business_id)?->timezone ?: 'Asia/Phnom_Penh')->toDateString();
                $quantity = $product->batches()->exists() ? (int) $product->batches()->where('quantity_remaining', '>', 0)->where(fn ($query) => $query->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today))->sum('quantity_remaining') : (int) $product->stock_quantity;
                $this->notifications->publish($model->business_id, 'inventory.'.$model->condition, 'inventory.alert:'.$model->id, ['product' => $product->name, 'quantity' => $quantity, 'unit' => $product->unit, 'expiry_date' => $model->stockBatch?->expiry_date?->toDateString()], 'product', $product->id);
            }
        } elseif ($model instanceof User) {
            $this->notifications->publish($model->business_id, 'team.created', 'team.created:'.$model->id, ['member' => $model->name, 'role' => $model->role], 'user', $model->id);
            if ($model->role === 'owner' && $model->business) {
                $this->notifications->profileIncomplete($model->business);
            }
        } elseif ($model instanceof PreparationPack) {
            $actor = auth()->user();
            $this->notifications->publish($model->business_id, 'pack.created', 'pack.created:'.$model->id, ['pack_id' => $model->id], 'pack', $model->id, $actor && (int) $actor->business_id === (int) $model->business_id ? [$actor->id] : []);
        }
    }

    public function updated(Model $model): void
    {
        if ($model instanceof Business) {
            if ($model->wasChanged(['name', 'activity', 'province'])) {
                $this->notifications->profileIncomplete($model);
            }
            return;
        }
        if (!$model->business_id) {
            return;
        }
        if ($model instanceof Sale) {
            // A sale is inserted before its line items are totaled. Finalize the
            // original notification inside that same transaction, without a second event.
            if ($model->wasChanged('total_amount')) {
                WorkspaceNotification::where('business_id', $model->business_id)->where('event_key', 'sale.created:'.$model->id)->get()->each(function ($notification) use ($model) {
                    $data = $notification->data;
                    $data['params'] = [...$data['params'], ...$this->saleParams($model)];
                    $notification->update(['data' => $data]);
                });
            }
            if (!$model->wasRecentlyCreated && $model->wasChanged(['notes', 'sold_at'])) {
                $this->notifications->publish($model->business_id, 'sale.updated', 'sale.updated:'.$model->id.':'.Str::uuid(), $this->saleParams($model), 'sale', $model->id, [$model->user_id]);
            }
            $this->unpaid($model);
        } elseif ($model instanceof User) {
            $version = (string) Str::uuid();
            if ($model->wasChanged('status')) {
                $type = $model->status === 'active' ? 'team.activated' : 'team.deactivated';
                $this->notifications->publish($model->business_id, $type, $type.':'.$model->id.':'.$version, ['member' => $model->name], 'user', $model->id, [$model->id]);
            }
            if ($model->wasChanged('telegram_chat_id')) {
                $type = $model->telegram_chat_id === null ? 'telegram.disconnected' : 'telegram.connected';
                // Never persist the Telegram chat identifier or linking credentials.
                $this->notifications->publish($model->business_id, $type, $type.':'.$model->id.':'.$version, ['member' => $model->name], 'user', $model->id, [$model->id], false);
            }
            if ($model->wasChanged('password')) {
                $type = $model->must_change_password ? 'security.password_reset' : 'security.password_changed';
                $this->notifications->publish($model->business_id, $type, $type.':'.$model->id.':'.$version, [], 'user', $model->id, [$model->id], false);
            }
        }
    }

    public function deleted(Model $model): void
    {
        if ($model instanceof Sale) {
            $this->notifications->publish($model->business_id, 'sale.deleted', 'sale.deleted:'.$model->id, $this->saleParams($model), 'sale', $model->id, [$model->user_id]);
        }
    }

    private function unpaid(Sale $sale): void
    {
        if ((float) $sale->outstanding_balance > 0) {
            $this->notifications->publish($sale->business_id, 'sale.unpaid', 'sale.unpaid:'.$sale->id, ['reference' => $sale->sale_no, 'amount' => (float) $sale->outstanding_balance, 'currency' => $sale->currency], 'sale', $sale->id, [$sale->user_id]);
        }
    }
}
