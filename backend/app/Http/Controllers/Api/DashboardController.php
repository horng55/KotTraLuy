<?php

namespace App\Http\Controllers\Api;

use App\Models\{Sale, Expense, Product, InventoryAlert, Shift};
use Illuminate\Http\Request;

class DashboardController
{
    public function index(Request $request)
    {
        $user = $request->user();
        $business = $user->business;
        $localNow = now($business->timezone ?: 'Asia/Phnom_Penh');
        $today = $localNow->toDateString();
        $start = $localNow->copy()->startOfDay()->setTimezone('UTC');
        $end = $localNow->copy()->endOfDay()->setTimezone('UTC');
        $salesQuery = Sale::where('business_id', $business->id);
        if (!$user->canDo('sales.read.all')) $salesQuery->where('user_id', $user->id);
        $canReadSales = $user->canDo('sales.read.own') || $user->canDo('sales.read.all');
        $sales = $canReadSales ? (clone $salesQuery)->whereBetween('sold_at', [$start, $end])->where('currency', $business->currency)->latest('sold_at')->get() : collect();
        $expenses = ($user->canDo('expenses.manage') || $user->canDo('reports.financial')) ? Expense::where('business_id', $business->id)->where('currency', $business->currency)->whereDate('expense_date', $today)->get() : collect();
        $products = $user->canDo('inventory.read') ? Product::where('business_id', $business->id)->get() : collect();
        $result = [
            'business' => $business, 'access_role' => $user->accessRole(), 'assigned_shift' => $user->assigned_shift,
            'open_shift' => $user->canDo('shifts.own') ? Shift::where('business_id', $business->id)->where('user_id', $user->id)->where('status', 'open')->latest('started_at')->first() : null,
            'products' => $products,
            'alerts' => $user->canDo('inventory.read') ? InventoryAlert::where('business_id', $business->id)->whereNull('resolved_at')->with('product:id,name,unit,image_storage_key')->latest()->limit(5)->get() : [],
        ];
        if ($canReadSales) $result += ['today_sales_count' => $sales->count(), 'today_sales_total' => round((float) $sales->sum('total_amount'), 2), 'today_revenue' => round((float) $sales->sum('total_amount'), 2), 'revenue' => round((float) $sales->sum('total_amount'), 2), 'salesCount' => $sales->count(), 'number_of_sales' => $sales->count(), 'recent_sales' => $sales->take(8)->values(), 'today_payments_received' => round((float) $sales->sum('payment_received'), 2), 'today_unpaid_balances' => round((float) $sales->sum('outstanding_balance'), 2)];
        if ($user->canDo('expenses.manage')) $result += ['today_expenses' => round((float) $expenses->sum('amount'), 2), 'expenses' => round((float) $expenses->sum('amount'), 2)];
        if ($user->canDo('reports.financial') && $user->canDo('sales.read.all')) {
            $cost = $sales->sum(fn ($sale) => $sale->items()->sum(\Illuminate\Support\Facades\DB::raw('quantity * unit_cost_snapshot')));
            $result['today_net_profit'] = round((float) $sales->sum('total_amount') - $cost - (float) $expenses->sum('amount'), 2);
        }
        return response()->json($result);
    }

    public function personal(Request $request) { return $this->index($request); }
}
