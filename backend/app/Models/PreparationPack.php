<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreparationPack extends Model
{
    protected $fillable = ['business_id', 'status', 'product_key', 'snapshot'];

    protected $casts = ['snapshot' => 'array'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
