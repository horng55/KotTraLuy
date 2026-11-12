import { text as uiLabel, t as tr } from '../lib/i18n';
import { useState } from 'react';
import { ArrowUpRight, BarChart3, Building2, ChevronRight, FileCheck2, Files, Landmark, Search, Send, Settings2, ShieldCheck, Sparkles, Users } from 'lucide-react';
import ShiftPanel from './ShiftPanel';
import UserManagementPanel from './UserManagementPanel';

const groups = [
  { title: "settings.business_settings", copy: "financing.your_business_identity_and_financial_toolkit", icon: Building2, links: [['business', "common.business_profile", "settings.manage_your_business_information_and_workspace_details", Building2], ['reports', "reports.financial_reports", "reports.view_profit_loss_balance_sheet_and_cash_flow", BarChart3], ['options', "financing.finance_options_2", "financing.compare_available_financing_information", Landmark], ['prepare', "financing.financing_preparation", "financing.organize_funding_information_and_next_steps", Sparkles]] },
  { title: "documents.workspace_documents", copy: "common.keep_important_information_organized_and_ready", icon: Files, links: [['documents', "documents.document_checklist_2", "documents.track_required_business_documents", FileCheck2], ['packs', "financing.preparation_packs", "common.review_and_manage_preparation_snapshots", Files]] },
  { title: "team.team_management", copy: "team.manage_access_and_stay_connected_to_your_team", icon: Users, links: [['team', "common.user_management", "team.manage_team_members_account_roles_and_work_shifts", Users], ['telegram', "telegram.telegram_bot_access", "telegram.connect_and_manage_your_telegram_integration", Send]] },
];

export default function MorePage({ onNavigate, currentUser }) {
  const [query, setQuery] = useState('');
  const [section, setSection] = useState('all');
  const visible = groups.map(group => ({ ...group, links: group.links.filter(([key, title, description]) => (key !== 'team' || currentUser?.role === 'owner') && `${uiLabel(group.title)} ${uiLabel(title)} ${uiLabel(description)} ${group.title} ${title} ${description}`.toLowerCase().includes(query.toLowerCase())) })).filter(group => group.links.length && (section === 'all' || group.title === section));
  const navigate = key => {
    if (key === 'team') { setQuery(''); setSection("team.team_management"); requestAnimationFrame(() => document.getElementById('settings-team')?.scrollIntoView({ behavior: 'smooth', block: 'start' })); return; }
    onNavigate(key);
  };
  return <div className="settings-workspace">
    <div className="st-breadcrumb">{tr("settings.workspace")}<ChevronRight size={12} /><span>{tr("settings.settings_2")}</span></div>
    <header className="st-heading"><div><span className="st-eyebrow">{tr("settings.workspace_control_center")}</span><h1>{tr("settings.settings_2")}</h1><p>{tr("financing.manage_your_business_workspace_financial_tools_team_members_and_preferences")}</p></div><span className="st-private"><ShieldCheck size={15} />{tr("settings.private_workspace")}</span></header>
    <div className="st-layout"><aside className="st-local-nav"><span className="st-nav-label">{tr("settings.settings")}</span><button aria-current={section === 'all' ? 'page' : undefined} onClick={() => setSection('all')}><Settings2 size={16} />{tr("settings.all_settings")}</button>{groups.map(({ title, icon: Icon }) => <button key={title} aria-current={section === title ? 'page' : undefined} onClick={() => setSection(title)}><Icon size={16} />{uiLabel(title)}</button>)}<div className="st-nav-note"><ShieldCheck size={18} /><strong>{tr("settings.your_workspace_your_control")}</strong><p>{tr("team.keep_your_business_details_current_and_give_each_team_member_their_own_account")}</p></div></aside>
      <main className="st-content"><label className="st-settings-search"><Search size={18} /><input aria-label={tr("settings.search_settings")} value={query} onChange={event => setQuery(event.target.value)} placeholder={tr("settings.search_settings_tools_and_integrations_2")} /><kbd>{tr("common.search")}</kbd></label>
        <div className="st-settings-groups">{visible.map(group => <section className="st-group" key={group.title}><header><h2>{uiLabel(group.title)}</h2><p>{uiLabel(group.copy)}</p></header><div className="st-link-grid">{group.links.map(([key, title, description, Icon]) => <button key={key} onClick={() => navigate(key)}><span className="st-link-icon"><Icon size={19} /></span><div><strong>{uiLabel(title)}</strong><small>{uiLabel(description)}</small></div><ArrowUpRight size={16} /></button>)}</div></section>)}</div>
        {!visible.length && <div className="st-no-results"><Search size={24} /><h2>{tr("settings.no_settings_found")}</h2><p>{tr("common.try_a_different_name_tool_or_category")}</p><button className="st-button" onClick={() => { setQuery(''); setSection('all'); }}>{tr("common.clear_search")}</button></div>}
        {currentUser?.role === 'staff' && !query && <ShiftPanel user={currentUser} />}
        {currentUser?.role === 'owner' && !query && (section === 'all' || section === "team.team_management") && <UserManagementPanel currentUser={currentUser} />}
      </main>
    </div>
  </div>;
}
