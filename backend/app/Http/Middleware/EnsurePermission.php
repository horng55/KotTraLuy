<?php

namespace App\Http\Middleware;

use App\Services\RolePermissions;
use Closure;
use Illuminate\Http\Request;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = $request->user();
        if (!$user || !$user->business_id || $user->status !== 'active' || !RolePermissions::allows($user, $permission)) {
            return response()->json(['message' => 'You do not have permission to perform this action.'], 403);
        }

        return $next($request);
    }
}
