<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WasteRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'shift_id',
        'user_id',
        'product_id',
        'stock_batch_id',
        'quantity',
        'unit_cost_snapshot',
        'reason',
        'notes',
        'recorded_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost_snapshot' => 'decimal:2',
        'recorded_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
