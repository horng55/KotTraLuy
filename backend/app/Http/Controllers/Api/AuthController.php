<?php

namespace App\Http\Controllers\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'username' => 'nullable|string|min:3|max:50|regex:/^[A-Za-z0-9._-]+$/|unique:users,username',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'business_name' => 'required|string',
        ]);

        $business = Business::create([
            'name' => $data['business_name'],
            'business_type' => 'retail',
            'currency' => 'USD',
            'timezone' => 'Asia/Phnom_Penh',
            'status' => 'active',
        ]);

        $user = User::create([
            'business_id' => $business->id,
            'name' => $data['name'],
            'username' => isset($data['username']) ? strtolower($data['username']) : null,
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'owner',
            'status' => 'active',
        ]);

        $token = $user->createToken('biztrack')->plainTextToken;

        return response()->json([
            'user' => $user->load('business'),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'login' => 'nullable|required_without:email|string',
            'email' => 'nullable|required_without:login|string',
            'password' => 'required|string',
        ]);

        $login = strtolower(trim($credentials['login'] ?? $credentials['email']));
        $user = User::whereRaw('LOWER(email) = ?', [$login])
            ->orWhereRaw('LOWER(username) = ?', [$login])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (($user->status ?? 'active') !== 'active') {
            throw ValidationException::withMessages([
                'login' => 'This account is inactive. Contact your business owner.',
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken('biztrack')->plainTextToken;

        return response()->json([
            'user' => $user->load('business'),
            'token' => $token,
        ]);
    }

    public function user(Request $request)
    {
        return $request->user()->load('business');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }
}
