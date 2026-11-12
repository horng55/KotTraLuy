import { messageText } from '../lib/i18n';
import { t as tr } from '../lib/i18n';
import { useState } from 'react';
import { Building2, CheckCircle2, Save } from 'lucide-react';
import { apiFetch } from '../lib/api';
import { FinancialNotice, FinancialPageHeader } from './FinancialPageUI';

export default function BusinessProfilePage({ business, setBusiness, reload }) {
  const [draft, setDraft] = useState(() => ({ name: business.name || '', activity: business.activity || '', province: business.province || '', operating_started_on: String(business.operating_started_on || '').slice(0, 10), registration_status: business.registration_status || 'unknown' }));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [saved, setSaved] = useState(false);
  const update = event => { setDraft(current => ({ ...current, [event.target.name]: event.target.value })); setSaved(false); };
  const cancel = () => { setDraft({ name: business.name || '', activity: business.activity || '', province: business.province || '', operating_started_on: String(business.operating_started_on || '').slice(0, 10), registration_status: business.registration_status || 'unknown' }); setError(''); setSaved(false); };
  const save = async event => {
    event.preventDefault(); setError(''); setSaved(false);
    if (!draft.name.trim()) { setError('Please enter your business name.'); return; }
    setBusy(true);
    try { const result = await apiFetch('/financing/business', { method: 'PUT', body: JSON.stringify({ ...draft, name: draft.name.trim(), operating_started_on: draft.operating_started_on || null }) }); setBusiness(result.business || { ...business, ...draft }); setSaved(true); await reload?.(); } catch (failure) { setError(failure); } finally { setBusy(false); }
  };
  return <section className="financial-page fp-profile"><FinancialPageHeader title={tr("common.business_profile")} description={tr("settings.manage_your_business_information_and_workspace_details")}><button form="business-profile-form" className="fp-button primary" disabled={busy}><Save size={15} />{busy ? tr("common.saving") : tr("common.save_changes")}</button></FinancialPageHeader>
    <FinancialNotice title={tr("reports.self_reported_business_information")}>{tr("documents.kottraluy_does_not_verify_business_registration_identity_or_ownership_documents")}</FinancialNotice>
    <div className="fp-profile-layout"><aside className="fp-profile-aside"><span className="fp-icon"><Building2 size={24} /></span><h2>{tr("common.your_business_identity")}</h2><p>{tr("reports.keep_your_workspace_information_accurate_these_details_help_you_organize_reports_and_prepare_for_con")}</p><span className="fp-tag">{tr("reports.self_reported")}</span></aside>
      <form id="business-profile-form" className="fp-card fp-profile-form" onSubmit={save}><fieldset disabled={busy}><section className="fp-form-section"><header><span>01</span><div><h2>{tr("common.basic_business_information")}</h2><p>{tr("common.the_essentials_that_describe_your_business")}</p></div></header><div className="fp-fields"><label className="fp-span"><span className="fp-field-label">{tr("common.business_name")}<em aria-hidden="true">*</em></span><input name="name" required maxLength={160} autoComplete="organization" placeholder={tr("common.enter_your_business_name")} value={draft.name} onChange={update} /></label><label>{tr("common.business_activity")}<input name="activity" maxLength={160} placeholder={tr("common.retail_example")} value={draft.activity} onChange={update} /></label><label>{tr("common.province")}<input name="province" maxLength={120} autoComplete="address-level1" placeholder={tr("common.e_g_phnom_penh")} value={draft.province} onChange={update} /></label></div></section>
      <section className="fp-form-section"><header><span>02</span><div><h2>{tr("common.business_operations")}</h2><p>{tr("common.your_operating_history_and_registration_information")}</p></div></header><div className="fp-fields"><label>{tr("common.operating_start_date")}<input name="operating_started_on" type="date" value={draft.operating_started_on} onChange={update} /></label><label>{tr("common.registration_status")}<select name="registration_status" required value={draft.registration_status} onChange={update}><option value="unknown">{tr("common.not_provided")}</option><option value="informal">{tr("common.informal_business")}</option><option value="registered">{tr("common.registered")}</option></select></label></div></section></fieldset>
      {error && <p className="fp-error" role="alert">{messageText(error)}</p>}{saved && <p className="fp-success" role="status"><CheckCircle2 size={16} />{tr("common.business_profile_saved")}</p>}<footer className="fp-form-footer"><span>{tr("common.required_field")}</span><div><button type="button" className="fp-button" disabled={busy} onClick={cancel}>{tr("common.cancel")}</button><button className="fp-button primary" disabled={busy}>{busy ? tr("common.saving") : tr("common.save_business_profile")}</button></div></footer></form>
    </div></section>;
}
