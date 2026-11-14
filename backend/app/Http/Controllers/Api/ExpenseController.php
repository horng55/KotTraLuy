<?php

namespace App\Http\Controllers\Api;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\WorkspaceNotifier;

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
            'shift_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('shifts', 'id')->where('business_id', $request->user()->business_id)],
        ]);

        $expense = DB::transaction(function () use ($request, $data) {
            $business = $request->user()->business;
            \App\Models\Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            return Expense::create([
                'business_id' => $business->id,
                'currency' => $data['currency'] ?? $business->currency,
                'shift_id' => $data['shift_id'] ?? null,
                'user_id' => $request->user()->id,
                'category' => trim($data['category']),
                'description' => trim($data['description']),
                'amount' => $data['amount'],
                'expense_date' => $data['expense_date'],
                'notes' => $data['notes'] ?? null,
            ]);
        });
        app(WorkspaceNotifier::class)->publish($expense->business_id, 'expense.created', 'expense.created:'.$expense->id, [
            'description' => $expense->description, 'amount' => $expense->amount, 'currency' => $expense->currency,
        ], 'expense', $expense->id);

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
