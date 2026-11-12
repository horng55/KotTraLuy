<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleBatchAllocation;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_currencies_are_filtered_without_conversion_and_incomplete_cash_flow_is_not_a_total(): void
    {
        $business = Business::factory()->create(['currency' => 'USD']);
        $user = User::factory()->create(['business_id' => $business->id]);
        foreach (['USD' => [30, 3, 15], 'KHR' => [12000, 2000, 5000]] as $currency => [$revenue, $expense, $payment]) {
            $sale = Sale::create(['business_id' => $business->id, 'user_id' => $user->id, 'currency' => $currency, 'sale_no' => $currency, 'sold_at' => '2026-11-08 12:00:00', 'total_amount' => $revenue, 'payment_received' => $payment, 'outstanding_balance' => $revenue - $payment]);
            SalePayment::create(['business_id' => $business->id, 'sale_id' => $sale->id, 'user_id' => $user->id, 'currency' => $currency, 'amount' => $payment, 'paid_at' => '2026-11-08 12:01:00']);
            Expense::create(['business_id' => $business->id, 'user_id' => $user->id, 'currency' => $currency, 'amount' => $expense, 'category' => 'Rent', 'description' => 'Rent', 'expense_date' => '2026-11-08']);
        }
        $this->actingAs($user, 'sanctum');
        foreach (['USD' => [30, 3, 15], 'KHR' => [12000, 2000, 5000]] as $currency => [$revenue, $expense, $payment]) {
            $this->getJson('/api/reports/financial?from=2026-11-01&to=2026-11-30&currency='.$currency)
                ->assertOk()->assertJsonPath('currency', $currency)
                ->assertJsonPath('profit_and_loss.sales_revenue', $revenue)
                ->assertJsonPath('profit_and_loss.total_operating_expenses', $expense)
                ->assertJsonPath('profit_and_loss.net_profit', null)
                ->assertJsonPath('cash_flow.operating.customer_receipts', $payment)
                ->assertJsonPath('cash_flow.net_change_in_cash', null)
                ->assertJsonPath('cash_flow.investing.equipment_purchases', null)
                ->assertJsonPath('balance_sheet.equity.retained_earnings_and_current_earnings', null)
                ->assertJsonPath('data_coverage.sales', 1);
        }
    }

    public function test_historical_receivables_do_not_include_later_payments_or_current_inventory(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $sale = Sale::create(['business_id' => $business->id, 'user_id' => $user->id, 'currency' => 'USD', 'sale_no' => 'PAID-LATER', 'sold_at' => '2026-11-08 23:59:59', 'total_amount' => 30, 'payment_received' => 30, 'outstanding_balance' => 0]);
        SalePayment::create(['business_id' => $business->id, 'sale_id' => $sale->id, 'user_id' => $user->id, 'currency' => 'USD', 'amount' => 30, 'paid_at' => '2026-11-09 00:00:00']);
        Sale::create(['business_id' => $business->id, 'user_id' => $user->id, 'currency' => 'USD', 'sale_no' => 'OUTSIDE', 'sold_at' => '2026-11-09 00:00:00', 'total_amount' => 999]);
        $this->actingAs($user, 'sanctum')->getJson('/api/reports/financial?from=2026-11-08&to=2026-11-08&currency=USD')
            ->assertOk()->assertJsonPath('from', '2026-11-08')->assertJsonPath('to', '2026-11-08')
            ->assertJsonPath('profit_and_loss.net_revenue', 30)
            ->assertJsonPath('balance_sheet.assets.accounts_receivable', 30)
            ->assertJsonPath('balance_sheet.assets.inventory_at_cost', null)
            ->assertJsonPath('cash_flow.operating.customer_receipts', 0);
    }

    public function test_current_inventory_is_valued_only_in_its_purchase_currency(): void
    {
        $business = Business::factory()->create(['currency' => 'USD']);
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'stock_quantity' => 5]);
        $today = now($business->timezone)->toDateString();
        foreach (['USD' => [2, 5], 'KHR' => [3, 1000]] as $currency => [$quantity, $cost]) {
            $purchase = \App\Models\Purchase::create(['business_id' => $business->id, 'user_id' => $user->id, 'currency' => $currency, 'purchase_date' => $today, 'total_amount' => $quantity * $cost]);
            StockBatch::create(['business_id' => $business->id, 'product_id' => $product->id, 'purchase_id' => $purchase->id, 'batch_code' => $currency, 'received_date' => $today, 'quantity_received' => $quantity, 'quantity_remaining' => $quantity, 'unit_cost' => $cost]);
        }
        $this->actingAs($user, 'sanctum');
        foreach (['USD' => 10, 'KHR' => 3000] as $currency => $value) {
            $this->getJson('/api/reports/financial?from='.$today.'&to='.$today.'&currency='.$currency)
                ->assertOk()->assertJsonPath('balance_sheet.assets.inventory_at_cost', $value);
        }
    }

    public function test_cross_currency_costs_are_not_used_as_if_they_were_converted(): void
    {
        $business = Business::factory()->create(['currency' => 'USD']);
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id]);
        $sale = Sale::create(['business_id' => $business->id, 'user_id' => $user->id, 'currency' => 'KHR', 'sale_no' => 'KHR-COST', 'sold_at' => '2026-11-08', 'total_amount' => 12000]);
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 12000, 'line_total' => 12000, 'unit_cost_snapshot' => 2]);
        $this->actingAs($user, 'sanctum')->getJson('/api/reports/financial?from=2026-11-01&to=2026-11-30&currency=KHR')
            ->assertOk()->assertJsonPath('profit_and_loss.net_revenue', 12000)
            ->assertJsonPath('profit_and_loss.cost_of_goods_sold', null)->assertJsonPath('profit_and_loss.net_profit', null)
            ->assertJsonPath('statement_status.profit_and_loss', 'incomplete');
    }

    public function test_empty_reports_and_parameter_authentication_validation(): void
    {
        $path = '/api/reports/financial';
        $this->getJson($path.'?from=2026-11-01&to=2026-11-30&currency=USD')->assertUnauthorized();
        $user = User::factory()->create(['business_id' => Business::factory()->create()->id]);
        $this->actingAs($user, 'sanctum');
        foreach (['from=2026-11-30&to=2026-11-01&currency=USD', 'from=2026-02-30&to=2026-11-01&currency=USD', 'from=2026-11-01&to=2026-11-30&currency=EUR', 'from=&to=2026-11-30&currency=USD'] as $invalid) {
            $this->getJson($path.'?'.$invalid)->assertUnprocessable();
        }
        foreach (['USD', 'KHR'] as $currency) {
            $this->getJson($path.'?from=2026-11-01&to=2026-11-30&currency='.$currency)
                ->assertOk()->assertJsonPath('profit_and_loss.net_profit', 0)
                ->assertJsonPath('cash_flow.net_change_in_cash', null)->assertJsonPath('data_coverage.sales', 0);
        }
        $user->update(['status' => 'inactive']);
        $this->getJson($path.'?from=2026-11-01&to=2026-11-30&currency=USD')->assertForbidden();
    }

    public function test_fifo_cogs_credit_sales_and_inventory_are_reported_without_expensing_purchase(): void
    {
        $business = Business::factory()->create(['currency' => 'USD']);
        $user = User::factory()->create(['business_id' => $business->id, 'role' => 'owner']);
        $product = Product::factory()->create(['business_id' => $business->id, 'stock_quantity' => 1, 'default_cost' => 5]);
        $batch = StockBatch::create(['business_id' => $business->id, 'product_id' => $product->id, 'batch_code' => 'B-1', 'received_date' => '2026-11-01', 'quantity_received' => 3, 'quantity_remaining' => 1, 'unit_cost' => 5]);
        $sale = Sale::create(['business_id' => $business->id, 'currency' => 'USD', 'user_id' => $user->id, 'sale_no' => 'S-1', 'sold_at' => '2026-11-08 10:00:00', 'total_amount' => 30, 'discount_amount' => 0, 'payment_received' => 15, 'outstanding_balance' => 15]);
        $item = SaleItem::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 15, 'unit_cost_snapshot' => 5, 'line_total' => 30]);
        SaleBatchAllocation::create(['sale_id' => $sale->id, 'sale_item_id' => $item->id, 'stock_batch_id' => $batch->id, 'quantity' => 2, 'unit_cost' => 5]);
        SalePayment::create(['business_id' => $business->id, 'sale_id' => $sale->id, 'user_id' => $user->id, 'amount' => 15, 'currency' => 'USD', 'paid_at' => '2026-11-08 10:01:00']);
        Expense::create(['business_id' => $business->id, 'currency' => 'USD', 'user_id' => $user->id, 'category' => 'Rent', 'description' => 'Shop rent', 'amount' => 3, 'expense_date' => '2026-11-08']);

        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/reports/financial?from=2026-11-01&to=2026-11-30&currency=USD')
            ->assertOk()
            ->assertJsonPath('profit_and_loss.sales_revenue', 30)
            ->assertJsonPath('profit_and_loss.cost_of_goods_sold', 10)
            ->assertJsonPath('profit_and_loss.net_profit', 17)
            ->assertJsonPath('balance_sheet.assets.accounts_receivable', 15)
            ->assertJsonPath('cash_flow.operating.customer_receipts', 15);
    }

    public function test_financial_report_cannot_include_another_business(): void
    {
        $business = Business::factory()->create(); $other = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id, 'role' => 'owner']);
        $otherUser = User::factory()->create(['business_id' => $other->id]);
        Sale::create(['business_id' => $other->id, 'currency' => 'USD', 'user_id' => $otherUser->id, 'sale_no' => 'OTHER', 'sold_at' => '2026-11-08 10:00:00', 'total_amount' => 999]);
        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/reports/financial?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonPath('profit_and_loss.sales_revenue', 0);
    }
}
