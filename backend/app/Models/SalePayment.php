<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class SalePayment extends Model
{
    protected $fillable = ['business_id','sale_id','user_id','amount','currency','paid_at','idempotency_key','notes'];
    protected $casts = ['amount'=>'decimal:2','paid_at'=>'datetime'];
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
}
