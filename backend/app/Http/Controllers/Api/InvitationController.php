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
        return WorkspaceInvitation::where('business_id', $request->user()->business_id)->latest()->get();
    }

    public function store(Request $request, InvitationDeliveryService $delivery)
    {
        if ($response = $this->ownerOnly($request)) return $response;
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'contact' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(['manager', 'cashier', 'staff'])],
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
        $invitation = $this->findUsable($token);
        if (!$invitation) return response()->json(['message' => 'This invitation is invalid, expired, or revoked.'], 404);
        return response()->json(['name' => $invitation->name, 'role' => $invitation->role, 'channel' => $invitation->channel, 'expires_at' => $invitation->expires_at]);
    }

    public function accept(Request $request, string $token)
    {
        $invitation = $this->findUsable($token);
        if (!$invitation) return response()->json(['message' => 'This invitation is invalid, expired, or revoked.'], 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'preferred_language' => ['nullable', Rule::in(['en', 'km'])],
        ]);
        if ($invitation->email && User::where('email', $invitation->email)->exists()) return response()->json(['message' => 'An account already exists for this email address.'], 422);
        if ($invitation->phone && User::where('phone', $invitation->phone)->exists()) return response()->json(['message' => 'An account already exists for this phone number.'], 422);

        $user = DB::transaction(function () use ($invitation, $data) {
            $username = 'member_' . $invitation->id . '_' . Str::lower(Str::random(5));
            $email = $invitation->email ?: 'phone.' . hash('sha256', $invitation->phone) . '@managed.kottraluy.local';
            $user = User::create([
                'business_id' => $invitation->business_id, 'name' => $data['name'], 'username' => $username,
                'email' => $email, 'phone' => $invitation->phone, 'password' => Hash::make($data['password']),
                'role' => 'staff', 'job_role' => $invitation->role, 'assigned_shift' => 'A', 'status' => 'active', 'must_change_password' => false,
                'created_by' => $invitation->invited_by, 'preferred_language' => $data['preferred_language'] ?? 'en',
            ]);
            $invitation->forceFill(['accepted_user_id' => $user->id, 'accepted_at' => now()])->save();
            return $user;
        });
        return response()->json(['user' => $user->load('business'), 'token' => $user->createToken('invitation-accept')->plainTextToken]);
    }

    public function revoke(Request $request, WorkspaceInvitation $invitation)
    {
        if ($response = $this->ownerOnly($request)) return $response;
        if ((int) $invitation->business_id !== (int) $request->user()->business_id) return response()->json(['message' => 'You do not have access to this invitation.'], 403);
        if (!$invitation->accepted_at) $invitation->forceFill(['revoked_at' => now()])->save();
        return response()->json(['data' => $invitation->fresh()]);
    }

    private function findUsable(string $token): ?WorkspaceInvitation
    {
        return WorkspaceInvitation::where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->first();
    }

    private function ownerOnly(Request $request)
    {
        return $request->user()->role === 'owner' ? null : response()->json(['message' => 'Owner access only.'], 403);
    }
}
