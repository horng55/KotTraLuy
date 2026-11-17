<?php

namespace App\Models;

use App\Services\RolePermissions;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'business_id',
        'name',
        'username',
        'email',
        'phone',
        'password',
        'role',
        'job_role',
        'assigned_shift',
        'status',
        'must_change_password',
        'created_by',
        'last_login_at',
        'telegram_chat_id',
        'telegram_enabled',
        'preferred_language',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'telegram_chat_id' => 'integer',
            'telegram_enabled' => 'boolean',
            'preferred_language' => 'string',
        ];
    }

    public ?array $workspaceContext = null;

    public function useWorkspace(int $businessId, bool $allowInactive = false): bool
    {
        $membership = \Illuminate\Support\Facades\DB::table('business_user')->where('business_id', $businessId)->where('user_id', $this->id)->first();
        $primary = $businessId === (int) $this->getRawOriginal('business_id');
        if ((!$allowInactive && $this->getRawOriginal('status') !== 'active') || (!$primary && !$membership) || (!$allowInactive && $membership && $membership->status !== 'active')) return false;
        $this->workspaceContext = $membership ? (array) $membership : ['business_id' => $businessId];
        if ($primary) unset($this->workspaceContext['role'], $this->workspaceContext['assigned_shift'], $this->workspaceContext['telegram_enabled'], $this->workspaceContext['status']);
        $this->unsetRelation('business');
        return true;
    }

    public function getBusinessIdAttribute($value) { return $this->workspaceContext['business_id'] ?? $value; }
    public function getRoleAttribute($value) { return $this->workspaceContext['role'] ?? $value; }
    public function getStatusAttribute($value) { return $this->workspaceContext['status'] ?? $value; }
    public function getJobRoleAttribute($value) { return isset($this->workspaceContext['role']) ? null : $value; }
    public function getAssignedShiftAttribute($value) { return $this->workspaceContext !== null && array_key_exists('assigned_shift', $this->workspaceContext) ? $this->workspaceContext['assigned_shift'] : $value; }
    public function getTelegramEnabledAttribute($value) { return (bool) ($this->workspaceContext['telegram_enabled'] ?? $value); }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function accessRole(): string
    {
        return RolePermissions::role($this);
    }

    public function canDo(string $permission): bool
    {
        return RolePermissions::allows($this, $permission);
    }

    public function permissionList(): array
    {
        return RolePermissions::for($this);
    }
}
