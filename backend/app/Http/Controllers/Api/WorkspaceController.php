<?php

namespace App\Http\Controllers\Api;

use App\Models\{Business, User};
use App\Services\RolePermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkspaceController
{
    public function index(Request $request)
    {
        $user = $request->user();
        $memberships = DB::table('business_user')->where('user_id', $user->id)->where('status', 'active')->get()->keyBy('business_id');
        $primary = (int) $user->getRawOriginal('business_id');
        return Business::whereIn('id', [...$memberships->keys(), $primary])->get(['id', 'name', 'currency'])->map(fn ($business) => [
            ...$business->toArray(), 'role' => $business->id === $primary ? ($user->getRawOriginal('role') === 'owner' ? 'owner' : ($user->getRawOriginal('job_role') ?: $user->getRawOriginal('role'))) : $memberships[$business->id]->role,
            'current' => (int) $business->id === (int) $user->business_id,
        ]);
    }

    public function switch(Request $request, Business $business)
    {
        $user = $request->user();
        $primary = (int) $user->getRawOriginal('business_id');
        $membership = DB::table('business_user')->where('user_id', $user->id)->where('business_id', $business->id)->where('status', 'active')->first();
        abort_unless((int) $business->id === $primary || $membership, 403);
        // A new scoped session leaves other browser/device workspace sessions intact.
        return response()->json(['token' => $user->createToken('workspace', ['workspace:'.$business->id])->plainTextToken]);
    }

    public function permissions(Request $request)
    {
        abort_unless($request->user()->accessRole() === 'owner', 403);
        return ['permissions' => RolePermissions::ASSIGNABLE];
    }

    public function updatePermissions(Request $request, User $user)
    {
        $owner = $request->user();
        abort_unless($owner->accessRole() === 'owner', 403);
        $membership = DB::table('business_user')->where('business_id', $owner->business_id)->where('user_id', $user->id)->first();
        abort_unless((int) $user->getRawOriginal('business_id') === (int) $owner->business_id || $membership, 403);
        $role = (int) $user->getRawOriginal('business_id') === (int) $owner->business_id ? $user->accessRole() : $membership->role;
        abort_if($role === 'owner', 403, 'Owner permissions cannot be restricted.');
        $data = $request->validate(['permissions' => ['present', 'nullable', 'array'], 'permissions.*' => ['string', 'distinct', Rule::in(RolePermissions::ASSIGNABLE)]]);
        DB::table('business_user')->updateOrInsert(['business_id' => $owner->business_id, 'user_id' => $user->id], ['role' => $role, 'permissions' => $data['permissions'] === null ? null : json_encode($data['permissions']), 'updated_at' => now()]);
        return ['saved' => true];
    }
}
