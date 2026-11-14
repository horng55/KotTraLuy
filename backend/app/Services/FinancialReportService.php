<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FinancialReportService
{
    public function generate(Business $business, string $from, string $to, ?string $currency): array
    {
        return DB::transaction(function () use ($business, $from, $to, $currency) {
            $currency = $currency ?: $business->currency;
            // Legacy records may have a null currency. Treat them as the
            // workspace currency without performing any conversion.
            $inCurrency = static fn ($query) => $query->where(function ($scope) use ($currency, $business) {
                $scope->where('currency', $currency)->when($currency === $business->currency, fn ($same) => $same->orWhereNull('currency'));
            });
            $start = Carbon::parse($from)->startOfDay();
            $end = Carbon::parse($to)->endOfDay();
            $sales = $inCurrency(Sale::where('business_id', $business->id))
                ->whereBetween('sold_at', [$start, $end])->with('items.allocations')->get();
            $allSales = $inCurrency(Sale::where('business_id', $business->id))
                ->where('sold_at', '<=', $end)->with(['payments' => fn ($query) => $query->where('business_id', $business->id)])->get();
            $expenses = $inCurrency(Expense::where('business_id', $business->id))
                ->whereBetween('expense_date', [$from, $to])->get();
            $currencies = collect([
                ...Sale::where('business_id', $business->id)->whereBetween('sold_at', [$start, $end])->pluck('currency')->map(fn ($value) => $value ?: $business->currency),
                ...Purchase::where('business_id', $business->id)->whereBetween('purchase_date', [$from, $to])->pluck('currency'),
                ...Expense::where('business_id', $business->id)->whereBetween('expense_date', [$from, $to])->pluck('currency')->map(fn ($value) => $value ?: $business->currency),
                ...DB::table('sale_payments')->where('business_id', $business->id)->whereBetween('paid_at', [$start, $end])->pluck('currency')->map(fn ($value) => $value ?: $business->currency),
            ])->filter()->unique()->sort()->values()->all();

            // Costs have their purchase currency, or the workspace cost currency
            // for legacy/opening batches. Never treat a USD unit cost as KHR.
            $batches = DB::table('stock_batches as batch')->leftJoin('purchases as purchase', function ($join) {
                $join->on('purchase.id', '=', 'batch.purchase_id')->on('purchase.business_id', '=', 'batch.business_id');
            })->where('batch.business_id', $business->id)->select('batch.*', 'purchase.currency as purchase_currency')->get()->keyBy('id');
            $cogs = 0.0;
            $costsComplete = true;
            foreach ($sales as $sale) {
                if ($sale->items->isEmpty() && (float) $sale->total_amount > 0) $costsComplete = false;
                foreach ($sale->items as $item) {
                    if ($item->allocations->isNotEmpty()) {
                        if ((int) $item->allocations->sum('quantity') !== (int) $item->quantity) $costsComplete = false;
                        foreach ($item->allocations as $allocation) {
                            $batch = $batches->get($allocation->stock_batch_id);
                            if (!$batch || ($batch->purchase_currency ?: $business->currency) !== $currency) $costsComplete = false;
                            $cogs += (float) $allocation->unit_cost * (int) $allocation->quantity;
                        }
                    } else {
                        if ($business->currency !== $currency || $item->unit_cost_snapshot === null) $costsComplete = false;
                        $cogs += (float) $item->unit_cost_snapshot * (int) $item->quantity;
                    }
                }
            }
            $netRevenue = (float) $sales->sum('total_amount');
            $discounts = (float) $sales->sum('discount_amount');
            $expenseGroups = $expenses->groupBy(fn ($expense) => $expense->category ?: 'Other')
                ->map(fn ($items) => round($items->sum('amount'), 2))->sortKeys()->all();
            $operatingExpenses = array_sum($expenseGroups);
            $grossProfit = $costsComplete ? $netRevenue - $cogs : null;
            $netProfit = $costsComplete ? $grossProfit - $operatingExpenses : null;

            // Current outstanding_balance cannot be used for an earlier cutoff:
            // payments after the requested date must not reduce historical AR.
            $receivables = 0.0;
            $receivablesComplete = true;
            foreach ($allSales as $sale) {
                $ledgerTotal = (float) $sale->payments->sum('amount');
                if (abs($ledgerTotal - (float) $sale->payment_received) > 0.005
                    || abs((float) $sale->total_amount - (float) $sale->payment_received - (float) $sale->outstanding_balance) > 0.005
                    || $sale->payments->contains(fn ($payment) => ($payment->currency ?: $business->currency) !== $currency)) {
                    $receivablesComplete = false;
                }
                $paidAtCutoff = $sale->payments->filter(fn ($payment) => $payment->paid_at->lte($end))->sum('amount');
                $receivables += max(0, (float) $sale->total_amount - (float) $paidAtCutoff);
            }
            // Batch remaining quantities are a current snapshot, not a historical ledger.
            $batchQuantities = $batches->groupBy('product_id')->map(fn ($items) => (int) $items->sum('quantity_remaining'));
            $inventoryComplete = Product::where('business_id', $business->id)->get(['id', 'stock_quantity'])
                ->every(fn ($product) => (int) $product->stock_quantity === ($batchQuantities->get($product->id) ?? 0));
            $inventory = $inventoryComplete && $to === now($business->timezone ?: config('app.timezone'))->toDateString()
                ? round($batches->filter(fn ($batch) => ($batch->purchase_currency ?: $business->currency) === $currency)
                    ->sum(fn ($batch) => (float) $batch->quantity_remaining * (float) $batch->unit_cost), 2) : null;
            $receipts = (float) DB::table('sale_payments')->where('business_id', $business->id)->where(function ($scope) use ($currency, $business) { $scope->where('currency', $currency)->when($currency === $business->currency, fn ($same) => $same->orWhereNull('currency')); })
                ->whereBetween('paid_at', [$start, $end])->sum('amount');

            $codes = ['recorded_activity_only', 'partial_cash_flow', 'legacy_cost_currency', 'inventory_snapshot'];
            if (collect($currencies)->contains(fn ($value) => $value !== $currency)) $codes[] = 'separate_currencies';
            if (!$costsComplete) $codes[] = 'incomplete_costs';
            if (!$receivablesComplete) $codes[] = 'incomplete_receivables';
            if (!$inventoryComplete) $codes[] = 'incomplete_inventory';
            $owner = $business->ownerUsers()->first();
            return [
                'status' => 'draft', 'currency' => $currency, 'from' => $from, 'to' => $to, 'generated_at' => now()->toIso8601String(),
                'business' => ['name' => $business->name, 'address' => $business->address, 'phone' => $business->phone, 'business_type' => $business->business_type, 'owner' => $owner?->name, 'timezone' => $business->timezone ?: config('app.timezone')],
                'profit_and_loss' => [
                    'sales_revenue' => round($netRevenue + $discounts, 2), 'sales_returns' => null,
                    'sales_discounts' => round($discounts, 2), 'net_revenue' => round($netRevenue, 2),
                    'cost_of_goods_sold' => $costsComplete ? round($cogs, 2) : null,
                    'gross_profit' => $grossProfit === null ? null : round($grossProfit, 2), 'operating_expenses' => $expenseGroups,
                    'total_operating_expenses' => round($operatingExpenses, 2), 'net_profit' => $netProfit === null ? null : round($netProfit, 2),
                ],
                'balance_sheet' => ['as_of' => $to, 'assets' => ['cash_and_bank' => null, 'accounts_receivable' => $receivablesComplete ? round($receivables, 2) : null, 'inventory_at_cost' => $inventory, 'equipment_net' => null],
                    'liabilities' => ['accounts_payable' => null, 'outstanding_loans' => null],
                    'equity' => ['owners_capital' => null, 'retained_earnings_and_current_earnings' => null, 'owner_withdrawals' => null],
                    'validation' => ['assets_equal_liabilities_equity' => null, 'status' => 'incomplete']],
                'cash_flow' => ['beginning_cash' => null, 'operating' => ['customer_receipts' => round($receipts, 2), 'supplier_payments' => null, 'operating_expenses_paid' => null],
                    'investing' => ['equipment_purchases' => null, 'equipment_disposals' => null],
                    'financing' => ['loan_proceeds' => null, 'loan_principal_repayments' => null, 'owner_contributions' => null, 'owner_withdrawals' => null],
                    'net_change_in_cash' => null, 'ending_cash' => null, 'validation' => ['beginning_plus_net_equals_ending' => null, 'ending_equals_balance_sheet_cash' => null, 'status' => 'incomplete']],
                'statement_status' => ['profit_and_loss' => $costsComplete ? 'recorded_activity' : 'incomplete', 'balance_sheet' => 'incomplete', 'cash_flow' => 'incomplete'],
                'limitation_codes' => $codes,
                'limitations' => ['Only records in the requested currency are included; no conversion is performed.', 'Cash flow is incomplete because opening balances and other account movements are not recorded.', 'Inventory quantities are available only as a current snapshot.'],
                'data_coverage' => ['sales' => $sales->count(), 'expenses' => $expenses->count(), 'historical_sales' => $allSales->count(),
                    'historical_expenses' => Expense::where('business_id', $business->id)->where('currency', $currency)->where('expense_date', '<=', $to)->count(),
                    'currency_records' => $currencies, 'selected_currency' => $currency, 'customer_payments' => DB::table('sale_payments')->where('business_id', $business->id)->where(function ($scope) use ($currency, $business) { $scope->where('currency', $currency)->when($currency === $business->currency, fn ($same) => $same->orWhereNull('currency')); })->whereBetween('paid_at', [$start, $end])->count()],
            ];
        });
    }
}
