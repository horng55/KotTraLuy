import { getLocale } from '../lib/i18n';
import { t as tr } from '../lib/i18n';
import { useState } from 'react';
import { ArrowUpRight, BarChart3, CalendarDays, Mic, Package, Plus, ReceiptText, ShoppingCart, WalletCards } from 'lucide-react';
import QuickActionsPanel from './QuickActionsPanel';
import SalesOverview from './SalesOverview';
import InventoryOverview from './InventoryOverview';
import { greetingTimeZone, useGreeting } from '../lib/useGreeting';

export default function HomePage({ dashboard, records, business, currentUser, onNavigate, reload, language }) {
  const today = dayKey(new Date());
  const [date, setDate] = useState(today);
  const currency = business?.currency || dashboard?.business?.currency || 'USD';
  const sales = records?.sales || [];
  const expenses = records?.expenses || [];
  const products = dashboard?.products || [];
  const daily = (rows, field, amount) => rows.filter(row => String(row[field] || '').slice(0, 10) === date && (row.currency || currency) === currency).reduce((sum, row) => sum + Number(row[amount] || 0), 0);
  const revenue = date === today ? dashboard?.today_revenue ?? daily(sales, 'sold_at', 'total_amount') : daily(sales, 'sold_at', 'total_amount');
  const expense = date === today ? dashboard?.today_expenses ?? daily(expenses, 'expense_date', 'amount') : daily(expenses, 'expense_date', 'amount');
  const profit = date === today ? dashboard?.today_net_profit : null;
  const previousDate = new Date(`${date}T12:00:00`); previousDate.setDate(previousDate.getDate() - 1);
  const previousTotal = (rows, field, amount) => rows.filter(row => String(row[field] || '').slice(0, 10) === dayKey(previousDate) && (row.currency || currency) === currency).reduce((sum, row) => sum + Number(row[amount] || 0), 0);
  const money = (value, code = currency) => new Intl.NumberFormat(getLocale(), { style: 'currency', currency: code }).format(Number(value || 0));
  const open = mode => document.querySelector('.bd-modal-source')?.querySelector(mode === 'sale' ? '.primary-action-row .button' : mode === 'voice' ? '.voice-button' : '.secondary-action-row .button:last-child')?.click();
  const recent = [...sales.map(row => ({ ...row, type: 'Sale', date: row.sold_at, amount: row.total_amount, description: row.notes || row.sale_no || tr("stock.product_sale"), reference: row.sale_no || row.id })), ...expenses.map(row => ({ ...row, type: 'Expense', date: row.expense_date, reference: row.id }))].sort((a, b) => String(b.date).localeCompare(String(a.date))).slice(0, 6);
  const greeting = useGreeting(language, greetingTimeZone(currentUser));
  const quick = [[ShoppingCart, tr("sales.create_new_sale"), tr("common.record_a_customer_purchase"), () => open('sale')], [ReceiptText, tr("sales.record_expense"), tr("common.keep_your_costs_organized"), () => open('expense')], [Package, tr("stock.manage_stock"), tr("stock.products_and_stock_movements"), () => onNavigate('inventory')], [BarChart3, tr("reports.view_reports"), tr("common.understand_your_performance"), () => onNavigate('reports')]];
  const steps = [!sales.length && [ShoppingCart, tr("sales.make_your_first_sale"), tr("common.start_a_clear_record_of_your_daily_income"), () => open('sale'), tr("sales.record_first_sale")], !products.length && [Package, tr("stock.build_your_inventory"), tr("stock.add_products_and_keep_stock_in_sight"), () => onNavigate('inventory'), tr("stock.add_stock_item")], !expenses.length && [ReceiptText, tr("common.keep_costs_in_sight"), tr("common.see_where_your_business_money_goes"), () => open('expense'), tr("sales.record_expense_2")]].filter(Boolean);
  return <div className="business-dashboard">
    <header className="bd-heading"><div><span className="bd-kicker">{tr("common.business_at_a_glance")}</span><h1 className="dashboard-greeting">{greeting}, {currentUser?.name || currentUser?.username || ''}!</h1><p>{tr("common.here_s_what_s_happening_with_your_business_today")}</p></div><label className="bd-date"><CalendarDays size={16} /><input aria-label={tr("common.dashboard_date")} type="date" max={today} value={date} onChange={event => event.target.value && setDate(event.target.value)} /></label></header>
    <div className="bd-actionbar"><div className="bd-period"><i />{date === today ? tr("common.today_s_overview") : tr("common.daily_overview")}<span>{currency}</span></div><div className="bd-actions"><div className="bd-secondary-actions"><button onClick={() => open('expense')}><ReceiptText size={16} />{tr("sales.record_expense")}</button><button onClick={() => open('voice')}><Mic size={16} />{tr("common.voice_input")}</button></div><button className="bd-primary" onClick={() => open('sale')}><Plus size={17} />{tr("sales.new_sale")}</button></div></div>
    <div className="bd-grid">
      <Kpi label={tr("sales.total_sales")} value={money(revenue)} icon={ShoppingCart} note={tr("sales.sales_on_selected_day")} current={revenue} previous={previousTotal(sales, 'sold_at', 'total_amount')} />
      <Kpi label={tr("sales.total_expenses")} value={money(expense)} icon={ReceiptText} note={tr("sales.expenses_on_selected_day")} current={expense} previous={previousTotal(expenses, 'expense_date', 'amount')} expense />
      <Kpi label={tr("reports.net_profit")} value={profit == null ? '—' : money(profit)} icon={WalletCards} note={profit == null ? tr("reports.view_financial_reports_for_profit") : profit < 0 ? tr("common.net_loss_on_selected_day") : tr("reports.net_profit_on_selected_day")} onClick={() => onNavigate('reports')} />
      <Kpi label={tr("stock.products_in_stock")} value={dashboard ? products.filter(product => Number(product.stock_quantity) > 0).length : '—'} icon={Package} note={tr("stock.catalog_count", {count:products.length})} />
      {!recent.length && dashboard && <section className="bd-onboarding"><div className="bd-section-heading"><div><span className="bd-kicker">{tr("common.let_s_get_started")}</span><h2>{tr("common.your_next_chapter_starts_here")}</h2><p>{tr("common.a_few_small_steps_a_clearer_picture_of_your_business")}</p></div><span className="bd-step-count">{tr("common.complete_steps", {count:3-steps.length,total:3})}</span></div><div className="bd-steps">{steps.map(([Icon, title, copy, action, label]) => <article key={title}><Icon size={20} /><h3>{title}</h3><p>{copy}</p><button onClick={action}>{label}<ArrowUpRight size={15} /></button></article>)}</div></section>}
      <SalesOverview sales={sales} currency={currency} endDate={date} />
      <section className="bd-card bd-transactions"><div className="bd-section-heading"><div><h2>{tr("sales.recent_transactions")}</h2><p>{tr("sales.your_latest_sales_and_business_expenses")}</p></div><button className="bd-link" onClick={() => onNavigate('records')}>{tr("common.view_all")}<ArrowUpRight size={15} /></button></div>{recent.length ? <div className="bd-table-scroll"><table><thead><tr>{[tr("sales.transaction_id"), tr("common.type"), tr("common.description"), tr("common.date"), tr("common.amount"), tr("common.status")].map(label => <th key={label}>{label}</th>)}</tr></thead><tbody>{recent.map(row => <tr key={`${row.type}-${row.id}`}><td>{row.reference || '—'}</td><td><span className={`bd-badge ${row.type === 'Sale' ? 'income' : 'expense'}`}>{row.type === 'Sale' ? tr("sales.sale") : tr("sales.expense")}</span></td><td>{row.description || tr("sales.business_expense")}</td><td>{String(row.date || '').slice(0, 10)}</td><td className={row.type === 'Sale' ? 'bd-income' : 'bd-outgoing'}>{row.type === 'Sale' ? '+' : '−'}{money(row.amount, row.currency || currency)}</td><td>{row.type === 'Sale' ? Number(row.outstanding_balance || 0) > 0 ? tr("common.outstanding") : tr("common.paid") : tr("common.recorded")}</td></tr>)}</tbody></table></div> : <div className="bd-inline-empty"><ReceiptText size={22} /><div><strong>{tr("sales.your_transaction_history_starts_with_one_record")}</strong><p>{tr("sales.sales_and_expenses_will_appear_here_as_you_add_them")}</p></div></div>}</section>
      <InventoryOverview products={products} loaded={Boolean(dashboard)} reloadKey={dashboard} onNavigate={onNavigate} />
      <section className="bd-quick"><div className="bd-section-heading"><div><h2>{tr("common.quick_actions")}</h2><p>{tr("common.less_admin_more_time_for_your_business")}</p></div></div><div className="bd-quick-grid">{quick.map(([Icon, title, copy, action]) => <button key={title} onClick={action}><span><Icon size={19} /></span><div><strong>{title}</strong><small>{copy}</small></div><ArrowUpRight size={16} /></button>)}</div></section>
    </div><div className="bd-modal-source"><QuickActionsPanel compact onSaved={reload} language={language} currency={currency} /></div>
  </div>;
}
function Kpi({ label, value, icon: Icon, note, onClick, current, previous, expense }) {
  const change = previous > 0 ? (current - previous) / previous * 100 : null;
  return <article className="bd-card bd-kpi"><div><span>{label}</span><Icon size={18} /></div><strong>{value}</strong>{change != null ? <small><b className={(expense ? change <= 0 : change >= 0) ? 'bd-income' : 'bd-outgoing'}>{change >= 0 ? '+' : ''}{change.toFixed(1)}%</b> {tr("common.previous_day")}</small> : onClick ? <button className="bd-link" onClick={onClick}>{note}<ArrowUpRight size={13} /></button> : <small>{note}</small>}</article>;
}
function dayKey(date) { return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`; }
