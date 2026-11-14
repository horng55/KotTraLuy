import { getLocale, domainLabel } from '../lib/i18n';
import { messageText } from '../lib/i18n';
import { t as tr } from '../lib/i18n';
import { useEffect, useState } from 'react';
import { ArrowDownUp, ArrowUpRight, ChevronLeft, ChevronRight, Download, Eye, Filter, Pencil, Plus, ReceiptText, Search, ShoppingBag, Trash2, WalletCards, X } from 'lucide-react';
import { apiDownload, apiFetch } from '../lib/api';
import QuickActionsPanel from './QuickActionsPanel';
import ReportsLivePage from './ReportsLivePage';
import TransactionDialog from './TransactionDialog';
import { Select } from './FormUI';

const defaults = { search: '', from: '', to: '', status: '', method: '', category: '', min: '', max: '' };
const money = (value, currency) => new Intl.NumberFormat(getLocale(), { style: 'currency', currency }).format(Number(value || 0));
const statusOf = row => row.kind === 'expense' ? 'recorded' : Number(row.outstanding_balance) > 0 ? Number(row.payment_received) > 0 ? 'partial' : 'unpaid' : 'paid';

export default function RecordsHub({ records, business, reload, notificationTarget, language }) {
  const [tab, setTab] = useState('sales');
  const [filters, setFilters] = useState(defaults);
  const [advanced, setAdvanced] = useState(false);
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState({ field: 'date', asc: false });
  const [dialog, setDialog] = useState(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (!notificationTarget) return;
    const expense = notificationTarget.entity_type === 'expense';
    const record = (expense ? records?.expenses : records?.sales)?.find(row => String(row.id) === String(notificationTarget.entity_id));
    const timer = setTimeout(() => {
      setTab(expense ? "expenses" : "sales");
      if (record) setDialog({ mode: 'view', row: { ...record, kind: expense ? 'expense' : 'sale', reference: record.sale_no || String(record.id), date: String(record.sold_at || record.expense_date || '').slice(0, 10), amount: Number(expense ? record.amount : record.total_amount), description: record.notes || record.description || tr("stock.product_sale") } });
    }, 0);
    return () => clearTimeout(timer);
  }, [notificationTarget, records]);
  const sales = (records?.sales || []).map(row => ({ ...row, kind: 'sale', reference: row.sale_no || String(row.id), date: String(row.sold_at || '').slice(0, 10), amount: Number(row.total_amount), description: row.notes || row.items?.map(item => item.product?.name).filter(Boolean).join(', ') || tr("stock.product_sale") }));
  const expenses = (records?.expenses || []).map(row => ({ ...row, kind: 'expense', reference: String(row.id), date: String(row.expense_date || '').slice(0, 10), amount: Number(row.amount) }));
  const unpaid = sales.filter(row => Number(row.outstanding_balance) > 0);
  const currency = business?.currency || 'USD';
  const [selectedCurrency, setSelectedCurrency] = useState('');
  const code = selectedCurrency || currency;
  const currencies = [...new Set([currency, ...sales, ...expenses].map(row => typeof row === 'string' ? row : row.currency || currency))];
  const inCurrency = rows => rows.filter(row => (row.currency || currency) === code);
  const sum = (rows, field = 'amount') => inCurrency(rows).reduce((total, row) => total + Number(row[field] || 0), 0);
  const source = tab === 'expenses' ? expenses : tab === 'unpaid' ? unpaid : sales;
  const update = (key, value) => { setFilters(current => ({ ...current, [key]: value })); setPage(1); };
  const openAction = mode => document.querySelector(`.sales-action-source ${mode === 'sale' ? '.primary-action-row .button' : '.secondary-action-row .button:last-child'}`)?.click();
  const filtered = inCurrency(source).filter(row => {
    const query = `${row.reference} ${row.description || ''} ${row.category || ''}`.toLowerCase();
    const amount = tab === 'unpaid' ? Number(row.outstanding_balance) : row.amount;
    return query.includes(filters.search.toLowerCase()) && (!filters.from || row.date >= filters.from) && (!filters.to || row.date <= filters.to) && (!filters.status || statusOf(row) === filters.status) && (!filters.method || (row.payment_method || '__not_recorded') === filters.method) && (!filters.category || (row.category || '__uncategorized') === filters.category) && (filters.min === '' || amount >= Number(filters.min)) && (filters.max === '' || amount <= Number(filters.max));
  }).sort((a, b) => (sort.field === 'amount' ? tab === 'unpaid' ? Number(a.outstanding_balance) - Number(b.outstanding_balance) : a.amount - b.amount : String(a[sort.field] || '').localeCompare(String(b[sort.field] || ''))) * (sort.asc ? 1 : -1));
  const pages = Math.max(1, Math.ceil(filtered.length / 10));
  const currentPage = Math.min(page, pages);
  const rows = filtered.slice((currentPage - 1) * 10, currentPage * 10);
  const changeSort = field => setSort(current => ({ field, asc: current.field === field ? !current.asc : true }));
  const exportRows = async () => {
    if (tab === 'sales') {
      try { const today = new Date().toISOString().slice(0, 10); const result = await apiDownload(`/reports/sales.xlsx?${new URLSearchParams({ from: filters.from || '2000-01-01', to: filters.to || today, currency: code })}`); const url = URL.createObjectURL(result.blob); const link = document.createElement('a'); link.href = url; link.download = result.filename || `KotTraLuy_Sales_${code}.xlsx`; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); } catch (failure) { setError(failure); }
      return;
    }
    const data = [[tr("sales.transaction_id"), tr("common.date"), tr("common.description"), tr("sales.payment_method"), tr("common.amount"), tr("common.currency"), tr("common.status")], ...filtered.map(row => [row.reference, row.date, row.description, row.payment_method || tr("common.not_recorded"), tab === 'unpaid' ? row.outstanding_balance : row.amount, row.currency || currency, domainLabel(statusOf(row))])];
    const csv = data.map(row => row.map(value => `"${String(value ?? '').replace(/^[=+@\-\t\r]/, "'$&").replaceAll('"', '""')}"`).join(',')).join('\r\n');
    const url = URL.createObjectURL(new Blob(['\uFEFF', csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a'); link.href = url; link.download = `${tab}-${code}.csv`; link.click(); URL.revokeObjectURL(url);
  };
  const remove = async () => {
    setBusy(true); setError('');
    try { await apiFetch(`/${dialog.row.kind === 'sale' ? "sales" : "expenses"}/${dialog.row.id}`, { method: 'DELETE' }); setDialog(null); await reload?.(); } catch (failure) { setError(failure); } finally { setBusy(false); }
  };
  const switchTab = next => { setTab(next); setFilters(defaults); setPage(1); };
  return <section className="sales-workspace">
    <div className="sales-breadcrumb">{tr("settings.workspace")}<ChevronRight size={12} /><span>{tr("sales.sales_2")}</span></div>
    <header className="sales-heading"><div><span className="sales-eyebrow">{tr("common.your_business_records")}</span><h1>{tr("sales.sales_management")}</h1><p>{tr("sales.manage_your_sales_expenses_unpaid_balances_and_financial_transactions")}</p></div><div className="sales-heading-actions"><button className="sales-button" onClick={exportRows} disabled={tab === 'reports'}><Download size={15} />{tr("common.export")}</button><button className="sales-button primary" onClick={() => openAction(tab === 'expenses' ? 'expense' : 'sale')}><Plus size={16} />{tab === 'expenses' ? tr("sales.record_expense") : tr("sales.new_sale")}</button></div></header>
    <div className="sales-summary-heading"><span>{tr("common.all_time_records")}</span><label>{tr("common.currency")}<Select aria-label={tr("common.summary_currency")} value={code} onChange={value => { setSelectedCurrency(value); setPage(1); }} options={currencies.map(item => [item, item])} placeholder={tr("common.currency")} /></label></div>
    <div className="sales-summary">{[[ShoppingBag, tr("sales.total_sales"), sum(sales), tr("sales.recorded_count",{count:inCurrency(sales).length})], [ReceiptText, tr("sales.total_expenses"), sum(expenses), tr("sales.expense_count",{count:inCurrency(expenses).length})], [WalletCards, tr("common.outstanding_balances"), sum(unpaid, 'outstanding_balance'), tr("sales.unpaid_count",{count:inCurrency(unpaid).length})], [ArrowUpRight, tr("reports.net_profit"), null, tr("reports.open_reports_for_calculated_profit")]].map(([Icon, label, amount, note]) => <article key={label}><div><span>{label}</span><Icon size={18} /></div><strong>{amount == null ? '—' : money(amount, code)}</strong>{amount == null ? <button onClick={() => switchTab('reports')}>{note}<ArrowUpRight size={12} /></button> : <small>{note}</small>}</article>)}</div>
    <nav className="sales-tabs" aria-label={tr("sales.transaction_sections")}>{[["sales", tr("sales.sale"), ShoppingBag, sales.length], ["expenses", tr("sales.expenses"), ReceiptText, expenses.length], ['unpaid', tr("common.unpaid_balances"), WalletCards, unpaid.length], ['reports', tr("reports.reports"), ArrowUpRight, null]].map(([key, label, Icon, count]) => <button key={key} aria-current={tab === key ? 'page' : undefined} onClick={() => switchTab(key)}><Icon size={16} />{label}{count != null && <span>{count}</span>}</button>)}</nav>
    {error && <div className="sales-error" role="alert">{messageText(error)}<button aria-label={tr("common.dismiss_error")} onClick={() => setError('')}><X size={16} /></button></div>}
    {tab === 'reports' ? <><div className="sales-report-options">{[[tr("sales.sales_report"), tr("sales.review_income_and_transaction_history"), "sales"], [tr("sales.expense_report"), tr("common.explore_categories_and_operating_costs"), "expenses"]].map(([title, copy, target]) => <button key={title} onClick={() => switchTab(target)}><ReceiptText size={20} /><div><strong>{title}</strong><small>{copy}</small></div><ArrowUpRight size={16} /></button>)}</div><ReportsLivePage language={language} defaultCurrency={currency} /></> : <>
      {tab === 'expenses' && <div className="sales-categories">{[...new Set(inCurrency(expenses).map(row => row.category || '__uncategorized'))].map(category => <button key={category} onClick={() => update('category', filters.category === category ? '' : category)} aria-pressed={filters.category === category}><span>{category === '__uncategorized' ? tr("common.uncategorized") : category}</span><strong>{money(sum(expenses.filter(row => (row.category || '__uncategorized') === category)), code)}</strong></button>)}</div>}
      {tab === 'unpaid' && <div className="sales-unpaid-note"><WalletCards size={18} /><span><strong>{tr("sales.follow_up",{count:inCurrency(unpaid).length})}</strong> · {tr("sales.outstanding_note",{amount:money(sum(unpaid, 'outstanding_balance'), code)})}</span></div>}
      <section className="sales-ledger"><div className="sales-ledger-title"><div><h2>{tab === 'expenses' ? tr("sales.expense_transactions") : tab === 'unpaid' ? tr("sales.outstanding_payments") : tr("sales.sales_transactions")}</h2><p>{tr("common.records_count",{count:filtered.length,currency:code})}</p></div><button className="sales-button" onClick={() => setAdvanced(!advanced)} aria-expanded={advanced}><Filter size={14} />{tr("common.advanced_filters")}</button></div>
        <div className="sales-filters"><label className="sales-search"><Search size={16} /><input aria-label={tr("sales.search_transactions")} placeholder={tr("sales.search_transactions_2")} value={filters.search} onChange={event => update('search', event.target.value)} /></label><label>{tr("common.from")}<input type="date" value={filters.from} max={filters.to || undefined} onChange={event => update('from', event.target.value)} /></label><label>{tr("common.to")}<input type="date" min={filters.from || undefined} value={filters.to} onChange={event => update('to', event.target.value)} /></label><label>{tr("common.status")}<Select value={filters.status} onChange={value => update('status', value)} options={[["", tr("common.all_statuses")], ...(tab === 'expenses' ? ['recorded'] : ['paid','partial','unpaid']).map(status => [status, domainLabel(status)])]} placeholder={tr("common.status")} /></label><label>{tr("sales.payment")}<Select value={filters.method} onChange={value => update('method', value)} options={[["", tr("common.all_methods")], ...[...new Set(source.map(row => row.payment_method || '__not_recorded'))].map(method => [method, method === '__not_recorded' ? tr('common.not_recorded') : method])]} placeholder={tr("sales.payment")} /></label><button className="sales-clear" onClick={() => { setFilters(defaults); setPage(1); }}>{tr("common.clear_filters")}</button></div>
        {advanced && <div className="sales-advanced"><label>{tr("common.minimum_amount")}<input type="number" min="0" value={filters.min} onChange={event => update('min', event.target.value)} /></label><label>{tr("common.maximum_amount")}<input type="number" min="0" value={filters.max} onChange={event => update('max', event.target.value)} /></label>{tab === 'expenses' && <label>{tr("common.category")}<Select value={filters.category} onChange={value => update('category', value)} options={[["", tr("common.all_categories")], ...[...new Set(expenses.map(row => row.category).filter(Boolean))].map(category => [category, category])]} placeholder={tr("common.category")} /></label>}</div>}
        {rows.length ? <div className="sales-table-scroll"><table><thead><tr>{[['reference', tr("sales.transaction_id")], ['date', tr("common.date")], ['description', tab === 'unpaid' ? tr("common.customer_description") : tr("common.description")], ['payment_method', tr("sales.payment_method")], ['amount', tab === 'unpaid' ? tr("common.outstanding") : tr("common.amount")]].map(([field, label]) => <th key={field} aria-sort={sort.field === field ? sort.asc ? 'ascending' : 'descending' : 'none'}><button onClick={() => changeSort(field)}>{label}<ArrowDownUp size={11} /></button></th>)}<th>{tr("common.status")}</th><th>{tr("common.actions")}</th></tr></thead><tbody>{rows.map(row => <tr key={row.id}><td><strong>{row.reference}</strong><small>{row.kind === 'sale' ? tr("sales.sale") : row.category}</small></td><td>{row.date}</td><td>{row.description || '—'}</td><td>{row.payment_method || tr("common.not_recorded")}</td><td className="sales-amount">{money(tab === 'unpaid' ? row.outstanding_balance : row.amount, code)}</td><td><span className={`sales-status ${statusOf(row).toLowerCase()}`}>{domainLabel(statusOf(row))}</span></td><td><div className="sales-row-actions"><button aria-label={tr("common.view_record",{reference:row.reference})} onClick={() => setDialog({ mode: 'view', row })}><Eye size={15} /></button><button aria-label={tr("common.edit_record",{reference:row.reference})} onClick={() => setDialog({ mode: 'edit', row })}><Pencil size={14} /></button><button aria-label={tr("common.delete_record",{reference:row.reference})} onClick={() => setDialog({ mode: 'delete', row })}><Trash2 size={14} /></button>{row.kind === 'sale' && Number(row.outstanding_balance) > 0 && <button className="sales-payment" onClick={() => setDialog({ mode: 'payment', row })}>{tr("sales.record_payment_2")}</button>}</div></td></tr>)}</tbody></table></div> : <div className="sales-empty"><span><ShoppingBag size={25} /></span><h3>{source.length ? tr("sales.no_matching_transactions") : tab === 'expenses' ? tr("sales.no_expenses_recorded_yet") : tab === 'unpaid' ? tr("common.you_re_all_caught_up_2") : tr("sales.no_sales_recorded_yet")}</h3><p>{source.length ? tr("common.try_adjusting_your_search_or_clearing_the_filters") : tab === 'unpaid' ? tr("sales.sales_with_an_outstanding_balance_will_appear_here") : tab === 'expenses' ? tr("sales.record_operating_costs_to_keep_a_clear_view_of_your_expenses") : tr("sales.start_tracking_your_business_income_by_recording_your_first_sale")}</p>{tab !== 'unpaid' && !source.length && <button className="sales-button primary" onClick={() => openAction(tab === 'expenses' ? 'expense' : 'sale')}><Plus size={15} />{tab === 'expenses' ? tr("sales.record_first_expense") : tr("sales.create_your_first_sale")}</button>}</div>}
        <footer className="sales-pagination"><span>{tr("common.pagination",{from:filtered.length?(currentPage-1)*10+1:0,to:Math.min(currentPage*10,filtered.length),total:filtered.length})}</span><div><button aria-label={tr("common.previous_page")} disabled={currentPage === 1} onClick={() => setPage(currentPage - 1)}><ChevronLeft size={15} /></button><span>{tr("common.page_count",{page:currentPage,total:pages})}</span><button aria-label={tr("common.next_page")} disabled={currentPage === pages} onClick={() => setPage(currentPage + 1)}><ChevronRight size={15} /></button></div></footer>
      </section></>}
    <div className="sales-action-source"><QuickActionsPanel compact onSaved={reload} language={language} currency={currency} /></div>
    {dialog && <TransactionDialog key={`${dialog.mode}-${dialog.row.id}`} dialog={dialog} close={() => setDialog(null)} reload={reload} onDelete={remove} deleting={busy} />}
  </section>;
}
