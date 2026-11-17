<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserManagementController
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canDo('team.manage'), 403);

        return response()->json(
            User::where(fn ($query) => $query->where('business_id', $request->user()->business_id)->orWhereIn('id', DB::table('business_user')->where('business_id', $request->user()->business_id)->select('user_id')))
                ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
                ->orderBy('name')
                ->get($this->visibleColumns())->map(function ($member) use ($request) { $member->useWorkspace((int) $request->user()->business_id, true); $member->setAttribute('permissions', $member->permissionList()); $member->setAttribute('access_role', $member->accessRole()); return $member; })
        );
    }

    public function store(Request $request)
    {
        if ($response = $this->ownerOnly($request)) {
            return $response;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:users,username'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['owner', 'manager', 'staff', 'member'])],
            'assigned_shift' => ['nullable', Rule::requiredIf(fn () => in_array($request->input('role'), ['manager', 'staff', 'member'], true)), Rule::in(['A', 'B'])],
            'telegram_enabled' => ['sometimes', 'boolean'],
        ]);

        $user = User::create([
            'business_id' => $request->user()->business_id,
            'name' => $data['name'],
            'username' => strtolower($data['username']),
            'email' => $this->managedEmail($data['username']),
            'password' => $data['password'],
            'role' => $data['role'],
            'job_role' => $data['role'] === 'manager' ? 'manager' : null,
            'assigned_shift' => in_array($data['role'], ['manager', 'staff', 'member'], true) ? $data['assigned_shift'] : null,
            'status' => 'active',
            'must_change_password' => true,
            'created_by' => $request->user()->id,
            'telegram_enabled' => in_array($data['role'], ['manager', 'staff', 'member'], true) && ($data['telegram_enabled'] ?? false),
        ]);

        return response()->json(['data' => $user->only($this->visibleColumns())], 201);
    }

    public function update(Request $request, User $user)
    {
        if ($response = $this->sameBusiness($request, $user)) {
            return $response;
        }

        abort_unless($request->user()->accessRole() === 'owner' || ($request->user()->canDo('team.manage') && $request->user()->accessRole() === 'manager' && in_array($user->accessRole(), ['staff', 'member'], true)), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'username' => ['sometimes', 'required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'role' => ['sometimes', Rule::in(['owner', 'manager', 'staff', 'member'])],
            'assigned_shift' => ['nullable', Rule::in(['A', 'B'])],
            'telegram_enabled' => ['sometimes', 'boolean'],
        ]);

        abort_if($request->user()->accessRole() !== 'owner' && isset($data['role']) && $data['role'] !== $user->role, 403, 'Only owners can change workspace roles.');
        if ((int) $user->getRawOriginal('business_id') !== (int) $request->user()->business_id) {
            abort_if((isset($data['name']) && $data['name'] !== $user->name) || (isset($data['username']) && $data['username'] !== $user->username), 403, 'Account identity belongs to the member.');
            DB::table('business_user')->where('business_id', $request->user()->business_id)->where('user_id', $user->id)->update(array_intersect_key($data, array_flip(['role', 'assigned_shift', 'telegram_enabled'])));
            $user->useWorkspace((int) $request->user()->business_id);
            return response()->json(['data' => $user->only($this->visibleColumns())]);
        }
        $newRole = $data['role'] ?? $user->role;
        if (in_array($newRole, ['manager', 'staff', 'member'], true) && empty($data['assigned_shift'] ?? $user->assigned_shift)) {
            return response()->json(['message' => 'Managers and staff must be assigned to Shift A or Shift B.'], 422);
        }

        if ($user->id === $request->user()->id && $newRole !== 'owner') {
            return response()->json(['message' => 'You cannot remove your own owner role.'], 422);
        }

        if (isset($data['username'])) {
            $data['username'] = strtolower($data['username']);
        }
        $data['assigned_shift'] = in_array($newRole, ['manager', 'staff', 'member'], true) ? ($data['assigned_shift'] ?? $user->assigned_shift) : null;
        $data['job_role'] = $newRole === 'manager' ? 'manager' : ($newRole === $user->role && $user->job_role !== 'manager' ? $user->job_role : null);
        $data['telegram_enabled'] = in_array($newRole, ['manager', 'staff', 'member'], true)
            ? ($data['telegram_enabled'] ?? (bool) $user->telegram_enabled)
            : false;

        if ($data['telegram_enabled'] === false && $user->telegram_enabled) {
            $user->forceFill(['telegram_chat_id' => null])->save();
            $user->tokens()->where('name', 'telegram-mini-app')->delete();
            DB::table('telegram_link_codes')
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]);
        }
        $user->update($data);

        return response()->json(['data' => $user->fresh()->only($this->visibleColumns())]);
    }

    public function resetPassword(Request $request, User $user)
    {
        if ($response = $this->ownerOnly($request)) {
            return $response;
        }
        if ($response = $this->sameBusiness($request, $user)) {
            return $response;
        }
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Use Change password to update your own password.'], 422);
        }

        abort_unless((int) $user->getRawOriginal('business_id') === (int) $request->user()->business_id, 403);
        $data = $request->validate(['password' => ['required', 'string', 'min:8']]);

        DB::transaction(function () use ($user, $data) {
            $user->forceFill([
                'password' => Hash::make($data['password']),
                'must_change_password' => true,
            ])->save();
            $user->tokens()->delete();
        });

        return response()->json(['message' => 'Password reset. The user must change it after logging in.']);
    }

    public function setStatus(Request $request, User $user)
    {
        if ($response = $this->ownerOnly($request)) {
            return $response;
        }
        if ($response = $this->sameBusiness($request, $user)) {
            return $response;
        }

        abort_unless($request->user()->accessRole() === 'owner' || ($request->user()->canDo('team.manage') && $request->user()->accessRole() === 'manager' && in_array($user->accessRole(), ['staff', 'member'], true)), 403);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);
        if ($user->id === $request->user()->id && $data['status'] === 'inactive') {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        if ((int) $user->getRawOriginal('business_id') !== (int) $request->user()->business_id) {
            DB::table('business_user')->where('business_id', $request->user()->business_id)->where('user_id', $user->id)->update(['status' => $data['status']]);
            return response()->json(['data' => ['id' => $user->id, 'status' => $data['status']]]);
        }
        DB::transaction(function () use ($user, $data) {
            $user->forceFill(['status' => $data['status']])->save();
            if ($data['status'] === 'inactive') {
                $user->tokens()->delete();
                $user->forceFill(['telegram_chat_id' => null])->save();
                DB::table('telegram_link_codes')
                    ->where('user_id', $user->id)
                    ->whereNull('consumed_at')
                    ->update(['consumed_at' => now(), 'updated_at' => now()]);
            }
        });

        return response()->json(['data' => $user->fresh()->only($this->visibleColumns())]);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $request->user()->forceFill([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
        ])->save();

        return response()->json(['message' => 'Password changed successfully.']);
    }

    private function ownerOnly(Request $request)
    {
        return $request->user()->role === 'owner'
            ? null
            : response()->json(['message' => 'Owner access only.'], 403);
    }

    private function sameBusiness(Request $request, User $user)
    {
        return $user->useWorkspace((int) $request->user()->business_id, true)
            ? null
            : response()->json(['message' => 'You do not have access to this user.'], 403);
    }

    private function managedEmail(string $username): string
    {
        return strtolower($username).'@managed.kottraluy.local';
    }

    private function visibleColumns(): array
    {
        return ['id', 'business_id', 'name', 'username', 'role', 'job_role', 'assigned_shift', 'status', 'must_change_password', 'last_login_at', 'telegram_chat_id', 'telegram_enabled'];
    }
}
