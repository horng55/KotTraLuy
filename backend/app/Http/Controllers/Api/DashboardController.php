<?php

namespace App\Http\Controllers\Api;

use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\WasteRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController
{
    public function index(Request $request)
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Owner access only.'], 403);
        }

        $business = $request->user()->business;
        $today = now()->toDateString();

        $sales = Sale::where('business_id', $business->id)
            ->where('currency', $business->currency)
            ->whereDate('sold_at', $today)
            ->get();

        $periodSales = Sale::where('business_id', $business->id)
            ->where('currency', $business->currency)
            ->where('sold_at', '>=', now()->subDays(6)->startOfDay())
            ->get()
            ->groupBy(fn ($sale) => $sale->sold_at->format('D'));

        $periodExpenses = Expense::where('business_id', $business->id)
            ->where('currency', $business->currency)
            ->where('expense_date', '>=', now()->subDays(6)->toDateString())
            ->get()
            ->groupBy(fn ($expense) => $expense->expense_date->format('D'));

        $purchases = (float) Purchase::where('business_id', $business->id)
            ->where('currency', $business->currency)
            ->whereDate('purchase_date', $today)
            ->sum('total_amount');

        $expenses = (float) Expense::where('business_id', $business->id)
            ->where('currency', $business->currency)
            ->whereDate('expense_date', $today)
            ->sum('amount');

        $waste = (float) WasteRecord::where('business_id', $business->id)
            ->whereDate('recorded_at', $today)
            ->sum(DB::raw('quantity * unit_cost_snapshot'));

        $totalRevenue = (float) $sales->sum('total_amount');
        $salesCount = $sales->count();

        // ទាញយកបញ្ជីផលិតផលសម្រាប់ Dropdown Quick Sale / Purchase លើ Web
        $products = Product::where('business_id', $business->id)->get();
        $recentRecords = collect()
            ->merge(Sale::where('business_id', $business->id)->where('currency', $business->currency)->latest('sold_at')->limit(5)->get()->map(fn ($sale) => ['type' => 'Sale', 'description' => $sale->sale_no, 'date' => $sale->sold_at->toDateString(), 'amount' => (float) $sale->total_amount, 'currency' => $sale->currency]))
            ->merge(Expense::where('business_id', $business->id)->where('currency', $business->currency)->latest('expense_date')->limit(5)->get()->map(fn ($expense) => ['type' => 'Expense', 'description' => $expense->description, 'date' => $expense->expense_date->toDateString(), 'amount' => (float) $expense->amount, 'currency' => $expense->currency]))
            ->sortByDesc('date')->take(5)->values();
        $trend = collect(range(6, 0))->map(function ($daysAgo) use ($periodSales, $periodExpenses) {
            $label = now()->subDays($daysAgo)->format('D');
            return ['label' => $label, 'revenue' => round((float) $periodSales->get($label, collect())->sum('total_amount'), 2), 'expenses' => round((float) $periodExpenses->get($label, collect())->sum('amount'), 2)];
        });

        return response()->json([
            'business' => $business,
            
            // Format សម្រាប់ Web Dashboard ថ្មី
            'revenue' => round($totalRevenue, 2),
            'expenses' => round($expenses, 2),
            'purchaseValue' => round($purchases, 2),
            'waste' => round($waste, 2),
            'salesCount' => $salesCount,
            'products' => $products,

            // Format ចាស់ (ការពារកុំឱ្យបាក់មុខងារផ្សេងទៀត)
            'today_revenue' => round($totalRevenue, 2),
            'today_expenses' => round($expenses, 2),
            'purchase_value' => round($purchases, 2),
            'waste_cost' => round($waste, 2),
            'number_of_sales' => $salesCount,
            'recent_records' => $recentRecords,
            'trend' => $trend,
        ]);
    }
}
