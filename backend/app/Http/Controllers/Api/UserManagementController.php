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
        if ($response = $this->ownerOnly($request)) {
            return $response;
        }

        return response()->json(
            User::where('business_id', $request->user()->business_id)
                ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
                ->orderBy('name')
                ->get($this->visibleColumns())
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
            'role' => ['required', Rule::in(['owner', 'staff'])],
            'assigned_shift' => ['nullable', Rule::requiredIf(fn () => $request->input('role') === 'staff'), Rule::in(['A', 'B'])],
            'telegram_enabled' => ['sometimes', 'boolean'],
        ]);

        $user = User::create([
            'business_id' => $request->user()->business_id,
            'name' => $data['name'],
            'username' => strtolower($data['username']),
            'email' => $this->managedEmail($data['username']),
            'password' => $data['password'],
            'role' => $data['role'],
            'assigned_shift' => $data['role'] === 'staff' ? $data['assigned_shift'] : null,
            'status' => 'active',
            'must_change_password' => true,
            'created_by' => $request->user()->id,
            'telegram_enabled' => $data['role'] === 'staff' && ($data['telegram_enabled'] ?? false),
        ]);

        return response()->json(['data' => $user->only($this->visibleColumns())], 201);
    }

    public function update(Request $request, User $user)
    {
        if ($response = $this->ownerOnly($request)) {
            return $response;
        }

        if ($response = $this->sameBusiness($request, $user)) {
            return $response;
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'username' => ['sometimes', 'required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'role' => ['sometimes', Rule::in(['owner', 'staff'])],
            'assigned_shift' => ['nullable', Rule::in(['A', 'B'])],
            'telegram_enabled' => ['sometimes', 'boolean'],
        ]);

        $newRole = $data['role'] ?? $user->role;
        if ($newRole === 'staff' && empty($data['assigned_shift'] ?? $user->assigned_shift)) {
            return response()->json(['message' => 'Staff must be assigned to Shift A or Shift B.'], 422);
        }

        if ($user->id === $request->user()->id && $newRole !== 'owner') {
            return response()->json(['message' => 'You cannot remove your own owner role.'], 422);
        }

        if (isset($data['username'])) {
            $data['username'] = strtolower($data['username']);
        }
        $data['assigned_shift'] = $newRole === 'staff' ? ($data['assigned_shift'] ?? $user->assigned_shift) : null;
        $data['telegram_enabled'] = $newRole === 'staff'
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

        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);
        if ($user->id === $request->user()->id && $data['status'] === 'inactive') {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
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
        return (int) $user->business_id === (int) $request->user()->business_id
            ? null
            : response()->json(['message' => 'You do not have access to this user.'], 403);
    }

    private function managedEmail(string $username): string
    {
        return strtolower($username).'@managed.kottraluy.local';
    }

    private function visibleColumns(): array
    {
        return ['id', 'name', 'username', 'role', 'assigned_shift', 'status', 'must_change_password', 'last_login_at', 'telegram_chat_id', 'telegram_enabled'];
    }
}
