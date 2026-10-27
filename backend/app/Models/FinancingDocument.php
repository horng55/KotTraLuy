<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancingDocument extends Model
{
    protected $fillable = ['business_id', 'document_key', 'availability', 'verification_status', 'notes'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
