import { reportText } from '../lib/financialReports';

const labels = { sales_revenue:"sales.sales_revenue", sales_returns:"sales.sales_returns", sales_discounts:"sales.sales_discounts", net_revenue:"common.net_revenue", cost_of_goods_sold:"common.cost_of_goods_sold_fifo_batches", gross_profit:"reports.gross_profit", operating_expenses:"sales.operating_expenses", total_operating_expenses:"sales.total_operating_expenses", net_profit:"reports.net_profit_loss", assets:"common.assets", cash_and_bank:"common.cash_and_bank", accounts_receivable:"common.accounts_receivable", inventory_at_cost:"stock.inventory_at_cost", equipment_net:"common.equipment_net", liabilities:"common.liabilities", accounts_payable:"common.accounts_payable", outstanding_loans:"common.outstanding_loans", equity:"common.equity", owners_capital:"common.owner_s_capital", retained_earnings_and_current_earnings:"common.retained_current_earnings", owner_withdrawals:"common.owner_withdrawals", beginning_cash:"common.beginning_cash", operating:"common.operating_activities", customer_receipts:"common.customer_receipts", supplier_payments:"sales.supplier_payments", operating_expenses_paid:"sales.operating_expenses_paid", investing:"common.investing_activities", equipment_purchases:"common.equipment_purchases", equipment_disposals:"common.equipment_disposals", financing:"financing.financing_activities", loan_proceeds:"common.loan_proceeds", loan_principal_repayments:"sales.loan_principal_repayments", owner_contributions:"common.owner_contributions", net_change_in_cash:"common.net_change_in_cash", ending_cash:"common.ending_cash" };
const totals = new Set(['net_revenue','gross_profit','total_operating_expenses','net_profit','net_change_in_cash','ending_cash']);
export default function FinancialStatement({ section, currency, language = 'en', incomplete }) {
  const t = value => reportText(value,language);
  if (!section) return <p className="fp-statement-unavailable">{t("reports.this_statement_is_unavailable")}</p>;
  const rows = [];
  const append = (object, depth = 0, category = false) => Object.entries(object).forEach(([key,value])=>{
    if (key==='validation' || key==='as_of') return;
    const label = category ? key : t(labels[key] || key.replaceAll('_',' '));
    if (value && typeof value==='object') { rows.push({key:`${rows.length}-${key}`,label,heading:true}); append(value,depth+1,key==='operating_expenses'); }
    else rows.push({key:`${rows.length}-${key}`,label,value,depth,total:totals.has(key)});
  });
  append(section);
  const format = value => typeof value==='number' && Number.isFinite(value) ? new Intl.NumberFormat(language==='km'?'km-KH':'en-US',{style:'currency',currency,maximumFractionDigits:2}).format(value) : null;
  return <>{(incomplete || section.validation?.status==='incomplete') && <div className="fr-statement-note"><strong>{t("common.incomplete_data")}</strong><p>{t("reports.this_statement_is_incomplete_unavailable_amounts_are_not_treated_as_zero")}</p></div>}<div className="fp-table-scroll"><table className="fp-statement-table"><thead><tr><th>{t("common.account_description")}</th><th>{t("common.amount")} ({currency})</th></tr></thead><tbody>{rows.map(row=>row.heading?<tr className="fp-account-group" key={row.key}><th colSpan={2}>{row.label}</th></tr>:<tr className={row.total?'fp-total-row':''} key={row.key}><td className={row.depth?'fp-account-indent':''}>{row.label}</td><td>{format(row.value) ?? <span className="fp-unavailable">{t("common.not_recorded")}</span>}</td></tr>)}</tbody></table></div></>;
}
