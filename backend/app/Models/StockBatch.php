<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class StockBatch extends Model
{
    protected $fillable = ['business_id','product_id','purchase_id','batch_code','received_date','quantity_received','quantity_remaining','unit_cost','expiry_date'];
    protected $casts = ['received_date'=>'date','expiry_date'=>'date','quantity_received'=>'integer','quantity_remaining'=>'integer','unit_cost'=>'decimal:2'];
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function purchase(): BelongsTo { return $this->belongsTo(Purchase::class); }
}
