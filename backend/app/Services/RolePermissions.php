<?php

namespace App\Services;

use App\Models\User;

final class RolePermissions
{
    public const MAP = [
        'owner' => ['*'],
        'manager' => [
            'dashboard.operational', 'sales.read.all', 'sales.read.own', 'sales.create', 'sales.modify',
            'inventory.read', 'inventory.manage', 'inventory.dispose', 'inventory.settings', 'reports.operational',
            'purchases.manage', 'expenses.manage', 'waste.manage', 'shifts.manage', 'shifts.own', 'telegram.link',
        ],
        'staff' => [
            'dashboard.personal', 'sales.read.own', 'sales.create', 'inventory.read',
            'shifts.own', 'telegram.link',
        ],
    ];

    public static function role(User $user): string
    {
        if ($user->role === 'owner') return 'owner';
        if ($user->role === 'manager' || $user->job_role === 'manager') return 'manager';
        return 'staff';
    }

    public static function allows(User $user, string $permission): bool
    {
        $permissions = self::MAP[self::role($user)] ?? [];
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public static function for(User $user): array
    {
        return self::MAP[self::role($user)] ?? [];
    }
}
