<?php

namespace App\Services;

use App\Models\User;

final class RolePermissions
{
    public const MAP = [
        'owner' => ['*'],
        'manager' => [
            'dashboard.personal', 'dashboard.operational', 'sales.read.all', 'sales.read.own', 'sales.create', 'sales.modify',
            'inventory.read', 'inventory.manage', 'inventory.dispose', 'inventory.settings', 'reports.operational',
            'purchases.manage', 'expenses.manage', 'waste.manage', 'shifts.manage', 'shifts.own', 'telegram.link',
        ],
        'staff' => [
            'dashboard.personal', 'sales.read.own', 'sales.create', 'inventory.read',
            'shifts.own', 'telegram.link',
        ],
    ];

    public const ASSIGNABLE = [
        'dashboard.operational', 'sales.read.all', 'sales.read.own', 'sales.create', 'sales.modify',
        'inventory.read', 'inventory.manage', 'inventory.dispose', 'inventory.settings',
        'reports.operational', 'reports.financial', 'purchases.manage', 'expenses.manage',
        'waste.manage', 'shifts.manage', 'shifts.own', 'team.manage', 'telegram.settings', 'financing',
    ];

    public static function role(User $user): string
    {
        if ($user->role === 'owner') return 'owner';
        if ($user->role === 'manager' || $user->job_role === 'manager') return 'manager';
        if ($user->role === 'member' || $user->job_role === 'member') return 'member';
        return 'staff';
    }

    public static function allows(User $user, string $permission): bool
    {
        $permissions = self::for($user);
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public static function for(User $user): array
    {
        $role = self::role($user);
        if ($role === 'owner') return ['*'];
        $overrides = $user->workspaceContext['permissions'] ?? ($user->workspaceContext === null ? \Illuminate\Support\Facades\DB::table('business_user')->where('business_id', $user->business_id)->where('user_id', $user->id)->value('permissions') : null);
        if (is_string($overrides)) $overrides = json_decode($overrides, true);
        $permissions = is_array($overrides) ? array_intersect($overrides, self::ASSIGNABLE) : (self::MAP[$role === 'member' ? 'staff' : $role] ?? []);
        if (!in_array($role, ['owner', 'manager'], true)) $permissions = array_diff($permissions, ['team.manage', 'telegram.settings', 'financing']);
        if (array_intersect($permissions, ['sales.create', 'inventory.manage', 'inventory.dispose', 'inventory.settings', 'purchases.manage', 'waste.manage'])) $permissions[] = 'inventory.read';
        if (array_intersect($permissions, ['shifts.manage', 'sales.create'])) $permissions[] = 'shifts.own';
        if (in_array('sales.read.all', $permissions, true)) $permissions[] = 'sales.read.own';
        return array_values(array_unique([...$permissions, 'dashboard.personal', 'telegram.link']));
    }
}
