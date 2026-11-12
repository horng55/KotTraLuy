<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\Purchase;
use App\Models\WasteRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class ReportService
{
    public function financialReport(Business $business, string $from, string $to, ?string $currency = null): array
    {
        return app(FinancialReportService::class)->generate($business, $from, $to, $currency);
    }
    public function rangeSummary(Business $business, string $from, string $to): array
    {
        $sales = Sale::where('business_id', $business->id)->whereBetween('sold_at', [$from.' 00:00:00', $to.' 23:59:59'])->with('items')->get();
        $revenue = $sales->sum(fn ($sale) => (float) $sale->total_amount);
        $payments = DB::table('sale_payments')->where('business_id',$business->id)->whereBetween('paid_at',[$from.' 00:00:00',$to.' 23:59:59'])->sum('amount');
        $expenses = Expense::where('business_id',$business->id)->whereBetween('expense_date',[$from,$to])->sum('amount');
        $purchases = Purchase::where('business_id',$business->id)->whereBetween('purchase_date',[$from,$to])->sum('total_amount');
        $waste = WasteRecord::where('business_id',$business->id)->whereBetween('recorded_at',[$from.' 00:00:00',$to.' 23:59:59'])->sum(DB::raw('quantity * unit_cost_snapshot'));
        $cogs = $sales->sum(fn ($sale) => $sale->items->sum(fn ($item) => (float)$item->unit_cost_snapshot * (int)$item->quantity));
        return ['label'=>'Business Record Summary — Self-reported','from'=>$from,'to'=>$to,'net_sales'=>round($revenue,2),'customer_payments_received'=>round($payments,2),'unpaid_balances'=>round($sales->sum('outstanding_balance'),2),'expenses'=>round($expenses,2),'stock_purchases'=>round($purchases,2),'waste'=>round($waste,2),'cogs'=>round($cogs,2),'profit'=>round($revenue-$cogs-$expenses,2),'profit_method'=>'Stored batch unit cost (FIFO allocation); excludes stock purchases until sold.','currency'=>$business->currency];
    }
    public function dailySummary(Business $business, string $date): array
    {
        $sales = Sale::where('business_id', $business->id)
            ->whereDate('sold_at', $date)
            ->with('items')->get();

        $revenue = $sales->sum(fn ($sale) => (float) $sale->total_amount);
        $expenses = Expense::where('business_id', $business->id)->whereDate('expense_date', $date)->sum('amount');
        $purchases = Purchase::where('business_id', $business->id)->whereDate('purchase_date', $date)->sum('total_amount');
        $waste = WasteRecord::where('business_id', $business->id)->whereDate('recorded_at', $date)->sum(DB::raw('quantity * unit_cost_snapshot'));

        $estimatedCogs = $sales->sum(fn ($sale) => $sale->items->sum(fn ($item) => (float) $item->unit_cost_snapshot * (int) $item->quantity));
        $estimatedProfit = $revenue - $estimatedCogs - $expenses;

        return [
            'date' => $date,
            'revenue' => round($revenue, 2),
            'estimated_cogs' => round($estimatedCogs, 2),
            'operating_expenses' => round($expenses, 2),
            'purchase_value' => round($purchases, 2),
            'estimated_profit' => round($estimatedProfit, 2),
            'profit_margin' => $revenue > 0 ? round((($estimatedProfit / $revenue) * 100), 2) : 0,
            'number_of_sales' => $sales->count(),
            'waste_cost' => round($waste, 2),
        ];
    }

    public function monthlySummary(Business $business, string $month): array
    {
        $start = now()->create($month . '-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $sales = Sale::where('business_id', $business->id)
            ->whereBetween('sold_at', [$start, $end])
            ->with('items')
            ->get();

        $revenue = $sales->sum(fn ($sale) => (float) $sale->total_amount);
        $expenses = Expense::where('business_id', $business->id)->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])->sum('amount');
        $purchaseValue = Purchase::where('business_id', $business->id)->whereBetween('purchase_date', [$start->toDateString(), $end->toDateString()])->sum('total_amount');
        $wasteCost = WasteRecord::where('business_id', $business->id)->whereBetween('recorded_at', [$start, $end])->sum(DB::raw('quantity * unit_cost_snapshot'));
        $estimatedCogs = $sales->sum(fn ($sale) => $sale->items->sum(fn ($item) => (float) $item->unit_cost_snapshot * (int) $item->quantity));
        $estimatedNetProfit = $revenue - $estimatedCogs - $expenses;

        return [
            'month' => $month,
            'total_revenue' => round($revenue, 2),
            'estimated_cogs' => round($estimatedCogs, 2),
            'operating_expenses' => round($expenses, 2),
            'estimated_profit' => round($estimatedNetProfit, 2),
            'profit_margin' => $revenue > 0 ? round((($estimatedNetProfit / $revenue) * 100), 2) : 0,
            'purchase_value' => round($purchaseValue, 2),
            'waste_cost' => round($wasteCost, 2),
            'total_orders' => $sales->count(),
        ];
    }
}
