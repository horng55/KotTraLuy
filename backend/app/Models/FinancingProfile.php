<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancingProfile extends Model
{
    protected $fillable = [
        'business_id', 'requested_amount', 'currency', 'purpose', 'requested_timing',
        'business_registration_status', 'bank_statement_status', 'collateral_status', 'notes',
    ];

    protected $casts = ['requested_amount' => 'decimal:2'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
