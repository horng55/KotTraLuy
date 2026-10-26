<?php

namespace App\Http\Controllers\Api;

use App\Models\Expense;
use Illuminate\Http\Request;

class ExpenseController
{
    public function index(Request $request)
    {
        return response()->json(
            Expense::where('business_id', $request->user()->business_id)
                ->orderByDesc('expense_date')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category' => 'required|string',
            'currency' => 'nullable|in:USD,KHR',
            'description' => 'required|string',
            'amount' => 'required|numeric|min:0',
            'expense_date' => 'required|date',
            'notes' => 'nullable|string',
            'shift_id' => 'nullable|exists:shifts,id',
        ]);

        $expense = Expense::create([
            'business_id' => $request->user()->business_id,
            'currency' => $data['currency'] ?? $request->user()->business->currency,
            'shift_id' => $data['shift_id'] ?? null,
            'user_id' => $request->user()->id,
            'category' => $data['category'],
            'description' => $data['description'],
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['data' => $expense], 201);
    }

    public function show(Request $request, Expense $expense)
    {
        if ((int) $expense->business_id !== (int) $request->user()->business_id) {
            return response()->json(['message' => 'You do not have access to this business.'], 403);
        }

        return response()->json($expense);
    }

    public function update(Request $request, Expense $expense)
    {
        if ((int) $expense->business_id !== (int) $request->user()->business_id) {
            return response()->json(['message' => 'You do not have access to this business.'], 403);
        }

        $data = $request->validate([
            'category' => 'sometimes|required|string',
            'description' => 'sometimes|required|string',
            'amount' => 'sometimes|numeric|min:0',
            'currency' => 'sometimes|in:USD,KHR',
            'expense_date' => 'sometimes|date',
            'notes' => 'nullable|string',
        ]);

        $expense->update($data);

        return response()->json($expense->fresh());
    }

    public function destroy(Request $request, Expense $expense)
    {
        if ((int) $expense->business_id !== (int) $request->user()->business_id) {
            return response()->json(['message' => 'You do not have access to this business.'], 403);
        }

        $expense->delete();

        return response()->json(['message' => 'Expense deleted successfully']);
    }
}
