<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class WorkspaceInvitation extends Model
{
    protected $fillable = [
        'business_id', 'invited_by', 'accepted_user_id', 'name', 'email', 'phone',
        'channel', 'role', 'token_hash', 'expires_at', 'accepted_at', 'revoked_at',
        'delivery_status', 'delivery_error',
    ];

    protected $hidden = ['token_hash', 'delivery_error'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    protected $appends = ['status'];

    public function getStatusAttribute(): string
    {
        if ($this->revoked_at) return 'revoked';
        if ($this->accepted_at) return 'accepted';
        return $this->expires_at && $this->expires_at->isPast() ? 'expired' : 'pending';
    }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function inviter(): BelongsTo { return $this->belongsTo(User::class, 'invited_by'); }
    public function acceptedUser(): BelongsTo { return $this->belongsTo(User::class, 'accepted_user_id'); }
}
