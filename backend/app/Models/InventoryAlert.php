<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InventoryAlert extends Model
{
    protected $fillable = ['business_id','product_id','stock_batch_id','condition','reviewed','resolved_at','last_notified_at'];
    protected $casts = ['reviewed'=>'boolean','resolved_at'=>'datetime','last_notified_at'=>'datetime'];
    public function product() { return $this->belongsTo(Product::class); }
    public function stockBatch() { return $this->belongsTo(StockBatch::class); }
}
