<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\ContactNormalizer;
use App\Services\InvitationDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InvitationController
{
    public function index(Request $request)
    {
        if ($response = $this->ownerOnly($request)) return $response;
        return response()->json(WorkspaceInvitation::where('business_id', $request->user()->business_id)->when($request->user()->accessRole() !== 'owner', fn ($query) => $query->where('role', '!=', 'manager'))->latest()->get())
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, InvitationDeliveryService $delivery)
    {
        if ($response = $this->ownerOnly($request)) return $response;
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'contact' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in($request->user()->accessRole() === 'owner' ? ['manager', 'cashier', 'staff', 'member'] : ['cashier', 'staff', 'member'])],
        ]);
        $contact = ContactNormalizer::parse($data['contact']);
        if (!$contact['channel']) {
            return response()->json(['message' => 'Enter a valid email address or international phone number, such as +855 12 345 678.'], 422);
        }
        $query = WorkspaceInvitation::where('business_id', $request->user()->business_id)
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
        if ($contact['email']) $query->where('email', $contact['email']); else $query->where('phone', $contact['phone']);
        if ($query->exists()) return response()->json(['message' => 'An active invitation already exists for this contact.'], 422);

        $rawToken = Str::random(64);
        $invitation = WorkspaceInvitation::create([
            'business_id' => $request->user()->business_id,
            'invited_by' => $request->user()->id,
            'name' => trim($data['name'] ?? '') ?: null,
            'email' => $contact['email'], 'phone' => $contact['phone'], 'channel' => $contact['channel'],
            'role' => $data['role'], 'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays(7), 'delivery_status' => 'manual',
        ]);
        $shareUrl = rtrim(config('app.url'), '/') . '/?invite=' . urlencode($rawToken);
        $status = $delivery->deliver($invitation->load('business'), $shareUrl);
        if ($status !== 'failed') $invitation->forceFill(['delivery_status' => $status])->save();
        return response()->json([
            'data' => $invitation->fresh(), 'share_url' => $shareUrl,
            'delivery_status' => $status, 'manual_share_required' => $status !== 'sent',
        ], 201);
    }

    public function show(string $token)
    {
        $invitation = WorkspaceInvitation::with('business')->where('token_hash', hash('sha256', $token))->first();
        if (!$invitation) return response()->json(['message' => 'This invitation is invalid.'], 404);
        return response()->json([
            'name' => $invitation->name, 'role' => $invitation->role, 'channel' => $invitation->channel,
            'expires_at' => $invitation->expires_at, 'accepted_at' => $invitation->accepted_at,
            'status' => $invitation->status, 'workspace' => ['id' => $invitation->business_id, 'name' => $invitation->business?->name],
        ]);
    }

    public function acceptExisting(Request $request, string $token)
    {
        $result = DB::transaction(function () use ($request, $token) {
            $invitation = WorkspaceInvitation::where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            $user = $request->user();
            abort_if(!$invitation || $invitation->status !== 'pending', 422, 'This invitation is no longer available.');
            abort_unless($user->status === 'active', 403, 'This account cannot join the invited workspace.');
            $matches = ($invitation->email && strcasecmp((string) $user->email, (string) $invitation->email) === 0)
                || ($invitation->phone && (string) $user->phone === (string) $invitation->phone);
            abort_unless($matches, 403, 'Sign in with the invited account to continue.');
            DB::table('business_user')->insertOrIgnore(['business_id' => $invitation->business_id, 'user_id' => $user->id, 'role' => $invitation->role === 'manager' ? 'manager' : ($invitation->role === 'member' ? 'member' : 'staff'), 'created_at' => now(), 'updated_at' => now()]);
            if ((int) $user->getRawOriginal('business_id') === (int) $invitation->business_id && $user->role === 'staff') $user->forceFill(['job_role' => $invitation->role])->save();
            $invitation->forceFill(['accepted_user_id' => $user->id, 'accepted_at' => now()])->save();
            return ['user' => $user->load('business'), 'token' => $user->createToken('invitation-accept', ['workspace:'.$invitation->business_id])->plainTextToken];
        });
        return response()->json($result);
    }

    public function accept(Request $request, string $token)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'preferred_language' => ['nullable', Rule::in(['en', 'km'])],
        ]);
        $result = DB::transaction(function () use ($token, $data) {
            $invitation = WorkspaceInvitation::where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            abort_if(!$invitation || $invitation->revoked_at, 404, 'This invitation is invalid, expired, or revoked.');
            if ($invitation->accepted_at) {
                $user = $invitation->acceptedUser;
                // A consumed token alone must never issue a new session.
                abort_unless($user && $user->status === 'active'
                    && (int) $user->business_id === (int) $invitation->business_id
                    && Hash::check($data['password'], $user->password), 403, 'Sign in to the accepting account.');
                return ['user' => $user->load('business'), 'token' => $user->createToken('invitation-accept')->plainTextToken];
            }
            abort_if($invitation->expires_at->isPast(), 404, 'This invitation is invalid, expired, or revoked.');
            abort_unless(in_array($invitation->role, ['manager', 'cashier', 'staff', 'member'], true), 422, 'Invalid invitation role.');
            abort_if($invitation->email && User::where('email', $invitation->email)->exists(), 422, 'An account already exists for this email address.');
            abort_if($invitation->phone && User::where('phone', $invitation->phone)->exists(), 422, 'An account already exists for this phone number.');
            $username = 'member_' . $invitation->id . '_' . Str::lower(Str::random(5));
            $email = $invitation->email ?: 'phone.' . hash('sha256', $invitation->phone) . '@managed.kottraluy.local';
            $user = User::create([
                'business_id' => $invitation->business_id, 'name' => $data['name'], 'username' => $username,
                'email' => $email, 'phone' => $invitation->phone, 'password' => Hash::make($data['password']),
                'role' => $invitation->role === 'member' ? 'member' : 'staff', 'job_role' => $invitation->role === 'member' ? null : $invitation->role, 'assigned_shift' => 'A', 'status' => 'active', 'must_change_password' => false,
                'created_by' => $invitation->invited_by, 'preferred_language' => $data['preferred_language'] ?? 'en',
            ]);
            DB::table('business_user')->insertOrIgnore(['business_id' => $invitation->business_id, 'user_id' => $user->id, 'role' => 'staff', 'created_at' => now(), 'updated_at' => now()]);
            $invitation->forceFill(['accepted_user_id' => $user->id, 'accepted_at' => now()])->save();
            return ['user' => $user->load('business'), 'token' => $user->createToken('invitation-accept')->plainTextToken];
        });
        return response()->json($result);
    }

    public function revoke(Request $request, WorkspaceInvitation $invitation)
    {
        if ($response = $this->ownerOnly($request)) return $response;
        abort_if($request->user()->accessRole() !== 'owner' && $invitation->role === 'manager', 403);
        if ((int) $invitation->business_id !== (int) $request->user()->business_id) return response()->json(['message' => 'You do not have access to this invitation.'], 403);
        DB::transaction(function () use ($invitation) {
            $locked = WorkspaceInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if (!$locked->accepted_at) $locked->forceFill(['revoked_at' => now()])->save();
        });
        return response()->json(['data' => $invitation->fresh()]);
    }

    private function findUsable(string $token): ?WorkspaceInvitation
    {
        return WorkspaceInvitation::where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->first();
    }

    private function ownerOnly(Request $request)
    {
        return in_array($request->user()->accessRole(), ['owner', 'manager'], true) && $request->user()->canDo('team.manage') ? null : response()->json(['message' => 'Owner access only.'], 403);
    }
}
