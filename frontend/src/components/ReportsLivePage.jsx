import { can } from '../lib/permissions';
import { t as translate, formatDate, formatDateTime } from '../lib/i18n';
import { useEffect, useRef, useState } from 'react';
import { AlertTriangle, BarChart3, Download, FileText, LoaderCircle, Play, RefreshCw } from 'lucide-react';
import { apiDownload, apiFetch } from '../lib/api';
import { limitationKeys, reportText, statementTitles, validateFinancialResponse, validateReportParameters } from '../lib/financialReports';
import { FinancialPageHeader } from './FinancialPageUI';
import { FormField, PrimaryButton, SecondaryButton, Select, TextInput } from './FormUI';
import FinancialStatement from './FinancialStatement';
import { createReportPdf, saveReportPdf } from '../lib/reportPdf';

export default function ReportsLivePage({ language = 'en', defaultCurrency = 'USD', currentUser }) {
  const financial = can(currentUser, 'reports.financial');
  const t = value => reportText(value, language);
  const [from, setFrom] = useState(`${new Date().getFullYear()}-01-01`);
  const [to, setTo] = useState(() => { const today = new Date(); return `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`; });
  const [currency, setCurrency] = useState(() => ['KHR','USD'].includes(defaultCurrency) ? defaultCurrency : 'USD');
  const [report, setReport] = useState(null); const [status, setStatus] = useState('idle'); const [excelBusy, setExcelBusy] = useState(false); const [excelError, setExcelError] = useState('');
  const [errors, setErrors] = useState({}); const [failureStatus, setFailureStatus] = useState(null);
  const [pdfBusy, setPdfBusy] = useState(false); const [pdfError, setPdfError] = useState(false);
  const reportElement = useRef(null); const exportingPdf = useRef(false);
  const request = useRef(null); const busy = status === 'loading';
  useEffect(() => () => request.current?.abort(), []);
  const generate = async () => {
    if (request.current) return;
    const parameters = { from, to, currency }; const invalid = validateReportParameters(parameters);
    setErrors(invalid); setFailureStatus(null);
    if (Object.keys(invalid).length) { setStatus('idle'); return; }
    const controller = new AbortController(); request.current = controller;
    let timedOut = false;
    const timeout = setTimeout(() => { timedOut = true; controller.abort(); }, 25000);
    setStatus('loading'); setReport(null);
    try {
      const result = await apiFetch(`/reports/${financial ? 'financial' : 'range'}?${new URLSearchParams(parameters)}`, { signal: controller.signal });
      if (controller.signal.aborted) return;
      setReport(financial ? validateFinancialResponse(result, parameters) : { ...result, from, to, currency, business: currentUser?.business || {} }); setStatus('success');
    } catch (failure) {
      if (controller.signal.aborted && !timedOut) return;
      if (failure.status === 401 || failure.code === 'password_change_required') return;
      if (failure.status === 422 && Object.keys(failure.errors || {}).some(key => ['from','to','currency'].includes(key))) {
        const fieldMessages = { from: 'Enter a valid From Date.', to: 'To Date must be on or after From Date.', currency: 'Choose USD or KHR.' };
        setErrors(Object.fromEntries(Object.keys(failure.errors).filter(key => fieldMessages[key]).map(key => [key, fieldMessages[key]]))); setStatus('idle');
      } else { setFailureStatus(failure.status || 0); setStatus('error'); }
      // Intentionally exclude tokens, headers, response bodies and business identities.
      console.warn('Financial report request failed', { endpoint: '/api/reports/financial', status: failure.status || 0, code: timedOut ? 'timeout' : failure.code || 'request_error', ...parameters });
    } finally { clearTimeout(timeout); if (request.current === controller) request.current = null; }
  };
  const exportPdf = async section => {
    if (!report || exportingPdf.current) return;
    exportingPdf.current = true; setPdfBusy(true); setPdfError(false);
    try {
      const blob = await createReportPdf(reportElement.current, section);
      await saveReportPdf(blob, `KotTraLuy_Financial_Report_${report.from}_to_${report.to}_${section}.pdf`);
    } catch { setPdfError(true); }
    finally { exportingPdf.current = false; setPdfBusy(false); }
  };
  const exportExcel = async () => {
    if (excelBusy || !report) return;
    setExcelBusy(true); setExcelError('');
    try {
      const result = await apiDownload(`/reports/financial.xlsx?${new URLSearchParams({ from: report.from, to: report.to, currency: report.currency, language })}`);
      const url = URL.createObjectURL(result.blob); const link = document.createElement('a'); link.href = url; link.download = result.filename || `KotTraLuy_Financial_Report_${report.from}_to_${report.to}.xlsx`; link.rel = 'noopener'; document.body.appendChild(link); link.click(); link.remove(); window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (failure) { setExcelError(failure); }
    finally { setExcelBusy(false); }
  };
  const changed = report && (report.from !== from || report.to !== to || report.currency !== currency);
  const empty = report && report.data_coverage && !report.data_coverage.sales && !report.data_coverage.expenses && !report.data_coverage.customer_payments;
  const date = value => formatDate(value,language);
  const update = (field, value) => { ({ from: setFrom, to: setTo, currency: setCurrency })[field](value); setErrors(current => ({ ...current, [field]: '' })); };
  return <section className="financial-page fp-reports fr-reports" lang={language} data-localized>
    <FinancialPageHeader title={t(financial ? "reports.financial_reports" : "sales.sales_report")} breadcrumb={t("settings.workspace")} description={t("common.review_recorded_business_activity_by_period_and_currency")}><span className="fp-tag fr-draft">{t("reports.draft_statements")}</span>{report && financial && <><SecondaryButton onClick={exportExcel} disabled={excelBusy}><Download size={15}/>{t(excelBusy ? "common.generating_excel" : "common.export_excel")}</SecondaryButton><SecondaryButton onClick={() => exportPdf('all')} disabled={pdfBusy}><Download size={15}/>{t(pdfBusy ? "common.generating_pdf" : "common.export_all_pdf")}</SecondaryButton></>}</FinancialPageHeader>
    <form className="fp-card fp-report-config" onSubmit={event => { event.preventDefault(); generate(); }} noValidate>
      <header><span className="fp-icon"><BarChart3 size={20}/></span><div><h2>{t("reports.configure_your_report")}</h2><p>{t("common.only_the_selected_currency_is_included_no_automatic_conversion")}</p></div></header>
      <div className="fp-report-fields"><FormField label={t("common.from_date")} error={errors.from && t(errors.from)}><TextInput name="from" type="date" value={from} onChange={event=>update('from',event.target.value)} disabled={busy} required/></FormField><FormField label={t("common.to_date")} error={errors.to && t(errors.to)}><TextInput name="to" type="date" value={to} onChange={event=>update('to',event.target.value)} disabled={busy} required/></FormField><FormField label={t("reports.reporting_currency")} error={errors.currency && t(errors.currency)}><Select name="currency" value={currency} onChange={value=>update('currency',value)} disabled={busy} options={[["USD","USD"],["KHR","KHR"]]} placeholder={t("reports.reporting_currency")} /></FormField><div className="fr-submit"><PrimaryButton type="submit" disabled={busy}>{busy ? <LoaderCircle className="fr-spinner" size={16}/> : <Play size={15}/>} {t(busy ? 'Generating report...' : 'Generate Report')}</PrimaryButton></div></div>
      {changed && <p className="fp-filter-note" role="status">{t("reports.configuration_changed_generate_again_to_update_these_statements")}</p>}
    </form>
    {busy && <div className="fr-loading" role="status"><LoaderCircle className="fr-spinner" size={19}/>{t("reports.generating_report")}</div>}
    {pdfError && <div className="fr-error" role="alert"><AlertTriangle size={20}/><p>{t("common.pdf_export_failed")}</p><SecondaryButton onClick={() => setPdfError(false)}>{t("common.dismiss")}</SecondaryButton></div>}
    {excelError && <div className="fr-error" role="alert"><span className="fr-error-icon"><AlertTriangle size={20}/></span><div><p>{t("common.excel_export_failed")}</p><SecondaryButton onClick={() => setExcelError('')}>{t("common.dismiss")}</SecondaryButton></div></div>}
    {status === 'error' && <div className="fr-error" role="alert"><span className="fr-error-icon"><AlertTriangle size={20}/></span><div><h2>{t("reports.unable_to_generate_report")}</h2><p>{t("financing.we_couldn_t_retrieve_your_financial_information_please_try_again")}</p>{failureStatus === 403 && <p>{t("reports.you_do_not_have_access_to_generate_this_report")}</p>}<SecondaryButton onClick={generate}><RefreshCw size={15}/>{t("common.try_again")}</SecondaryButton></div></div>}
    {!report && status === 'idle' && <div className="fp-empty fp-report-empty"><span className="fp-icon"><FileText size={27}/></span><h2>{t("reports.your_report_is_ready_to_generate")}</h2><p>{t("reports.select_dates_and_currency_then_generate_your_report")}</p><div className="fp-report-preview-types">{Object.values(statementTitles).map(title=><span key={title}>{t(title)}</span>)}</div></div>}
    {report && !financial && <section className="fp-card"><h2>{t("sales.sales_report")}</h2><dl className="kt-product-details">{[['net_sales', 'sales.total_sales'], ['customer_payments_received', 'sales.payment_received'], ['unpaid_balances', 'common.outstanding_balances'], ['expenses', 'sales.total_expenses']].filter(([key]) => report[key] != null).map(([key,label]) => <div key={key}><dt>{t(label)}</dt><dd>{new Intl.NumberFormat(language === 'km' ? 'km-KH' : 'en-US', { style: 'currency', currency: report.currency }).format(report[key])}</dd></div>)}</dl></section>}
    {report && financial && <div className="fp-generated-report" ref={reportElement}><header className="fp-report-identity"><div><span className="fp-eyebrow">{t("reports.draft_statements")}</span><h2>{report.business.name}</h2>{(report.business.address || report.business.phone) && <p>{[report.business.address,report.business.phone].filter(Boolean).join(' · ')}</p>}</div><div><p><strong>{date(report.from)} — {date(report.to)}</strong></p><p>{report.currency} · {t("common.generated")} {formatDateTime(report.generated_at,language)}</p></div></header>
      <aside className="fp-notice fp-report-warning"><AlertTriangle size={20}/><div><strong>{t("common.management_prepared_draft_not_audited_or_verified")}</strong><p>{t("reports.these_statements_reflect_recorded_workspace_activity_and_available_data_only")}</p></div></aside>
      {empty && <p className="fr-empty-data" role="status">{t("common.no_activity_recorded_for_this_period_and_currency")}</p>}
      <div className="fp-statements">{Object.entries(statementTitles).map(([key,title])=><section className="fp-card fp-statement" key={key} data-statement={key}><header><div><h2>{t(title)}</h2><small>{date(report.from)} — {date(report.to)} · {report.currency}</small></div><SecondaryButton onClick={()=>exportPdf(key)} disabled={pdfBusy}><Download size={14}/>{t(pdfBusy ? "common.generating_pdf" : "common.export_pdf")}</SecondaryButton></header><FinancialStatement type={key} section={report[key]} currency={report.currency} language={language} incomplete={report.statement_status?.[key]==='incomplete'}/></section>)}</div>
      <section className="fp-card fp-report-notes"><header><AlertTriangle size={18}/><h2>{t("common.data_coverage_limitations")}</h2></header><ul>{report.limitation_codes ? report.limitation_codes.filter(key=>limitationKeys[key]).map(key=><li key={key}>{translate(limitationKeys[key],{},language)}</li>) : (report.limitations || []).map(item=><li key={item}>{item}</li>)}</ul>{report.data_coverage && <dl className="fp-coverage">{Object.entries(report.data_coverage).map(([key,value])=><div key={key}><dt>{t(key)}</dt><dd>{Array.isArray(value)?value.join(', ') || '—':value}</dd></div>)}</dl>}</section>
    </div>}
  </section>;
}
