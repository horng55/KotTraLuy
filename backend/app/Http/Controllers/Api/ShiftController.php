<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\EnsureBusinessAccess;
use App\Models\Shift;
use Illuminate\Http\Request;

class ShiftController
{
    public function index(Request $request)
    {
        $query = Shift::where('business_id', $request->user()->business_id);
        if ($request->user()->role !== 'owner') {
            $query->where('user_id', $request->user()->id);
        }

        return response()->json(
            $query->orderBy('started_at', 'desc')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'shift_type' => 'required|in:A,B,morning,evening',
            'opening_cash' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $existing = Shift::where('business_id', $request->user()->business_id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'open')
            ->first();

        if ($existing) {
            return response()->json(['message' => 'You already have an open shift for this business.'], 422);
        }

        if ($request->user()->role === 'staff'
            && $request->user()->assigned_shift
            && $data['shift_type'] !== $request->user()->assigned_shift) {
            return response()->json([
                'message' => 'You may only start your assigned Shift '.$request->user()->assigned_shift.'.',
            ], 422);
        }

        $shift = Shift::create([
            'business_id' => $request->user()->business_id,
            'user_id' => $request->user()->id,
            'shift_type' => $data['shift_type'],
            'started_at' => now(),
            'opening_cash' => $data['opening_cash'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'status' => 'open',
        ]);

        return response()->json(['data' => $shift], 201);
    }

    public function show(Request $request, Shift $shift)
    {
        if ((int) $shift->business_id !== (int) $request->user()->business_id) {
            return response()->json(['message' => 'You do not have access to this business.'], 403);
        }
        if ($request->user()->role !== 'owner' && (int) $shift->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'You do not have access to this shift.'], 403);
        }

        return response()->json($shift);
    }

    public function update(Request $request, Shift $shift)
    {
        if ((int) $shift->business_id !== (int) $request->user()->business_id) {
            return response()->json(['message' => 'You do not have access to this business.'], 403);
        }
        if ($request->user()->role !== 'owner' && (int) $shift->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'You do not have access to this shift.'], 403);
        }

        $data = $request->validate([
            'notes' => 'nullable|string',
            'shift_type' => 'sometimes|in:A,B,morning,evening',
        ]);

        $shift->update($data);

        return response()->json($shift->fresh());
    }

    public function destroy(Request $request, Shift $shift)
    {
        if ((int) $shift->business_id !== (int) $request->user()->business_id) {
            return response()->json(['message' => 'You do not have access to this business.'], 403);
        }
        if ($request->user()->role !== 'owner' && (int) $shift->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'You do not have access to this shift.'], 403);
        }

        $shift->delete();

        return response()->json(['message' => 'Shift deleted successfully']);
    }

    public function close(Request $request, Shift $shift)
    {
        if ((int) $shift->business_id !== (int) $request->user()->business_id) {
            return response()->json(['message' => 'You do not have access to this business.'], 403);
        }

        if ($shift->user_id !== $request->user()->id && $request->user()->role !== 'owner') {
            return response()->json(['message' => 'Only the shift owner or owner may close this shift.'], 403);
        }

        $data = $request->validate([
            'closing_cash' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $shift->update([
            'closing_cash' => $data['closing_cash'] ?? $shift->opening_cash,
            'ended_at' => now(),
            'status' => 'closed',
            'notes' => $data['notes'] ?? $shift->notes,
        ]);

        return response()->json(['data' => $shift]);
    }
}
