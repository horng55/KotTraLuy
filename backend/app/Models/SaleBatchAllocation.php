<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SaleBatchAllocation extends Model
{
    protected $fillable = ['sale_id','sale_item_id','stock_batch_id','quantity','unit_cost'];
    protected $casts = ['quantity'=>'integer','unit_cost'=>'decimal:2'];
}
