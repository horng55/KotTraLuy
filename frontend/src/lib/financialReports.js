import { text } from './i18n.js';
const coverageLabels = { sales:"sales.sales_2", expenses:"sales.expenses", customer_payments:"sales.customer_payments", historical_sales:"reports.sales_through_cutoff", historical_expenses:"reports.expenses_through_cutoff", currency_records:"reports.recorded_currencies", selected_currency:"reports.selected_currency" };
export const reportText = (value, language='en') => text(coverageLabels[value] || value, language);
export const statementTitles = { profit_and_loss: "reports.profit_loss_statement", balance_sheet: "reports.balance_sheet", cash_flow: "reports.cash_flow_statement" };
export const limitationKeys = {"incomplete_inventory":"reports.limitation.incomplete_inventory","recorded_activity_only":"reports.limitation.recorded_activity_only","unrecorded_accounts":"reports.limitation.unrecorded_accounts","partial_cash_flow":"reports.limitation.partial_cash_flow","legacy_cost_currency":"reports.limitation.legacy_cost_currency","inventory_snapshot":"reports.limitation.inventory_snapshot","separate_currencies":"reports.limitation.separate_currencies","incomplete_costs":"reports.limitation.incomplete_costs","incomplete_receivables":"reports.limitation.incomplete_receivables"};
export function validateReportParameters({ from, to, currency }) {
  const validDate = value => /^\d{4}-\d{2}-\d{2}$/.test(value || '') && !value.startsWith('0000') && Number.isFinite(Date.parse(`${value}T00:00:00Z`)) && new Date(`${value}T00:00:00Z`).toISOString().slice(0, 10) === value;
  const errors = {};
  if (!validDate(from)) errors.from = "common.enter_a_valid_from_date";
  if (!validDate(to)) errors.to = "common.enter_a_valid_to_date";
  if (!errors.from && !errors.to && from > to) errors.to = "common.to_date_must_be_on_or_after_from_date";
  if (!['USD', 'KHR'].includes(currency)) errors.currency = "common.choose_usd_or_khr";
  return errors;
}
export function validateFinancialResponse(data, parameters) {
  if (!data || data.currency !== parameters.currency || data.from !== parameters.from || data.to !== parameters.to || !data.generated_at || !Number.isFinite(Date.parse(data.generated_at)) || !data.business || !['profit_and_loss', 'balance_sheet', 'cash_flow'].every(key => data[key] === null || (typeof data[key] === 'object' && !Array.isArray(data[key])))) {
    const error = new Error('Unexpected financial report response.'); error.code = 'invalid_response'; throw error;
  }
  return data;
}
