import { roleOf, roleTranslation } from '../lib/permissions';
import PermissionEditor from './PermissionEditor';
import { messageText } from '../lib/i18n';
import { t as tr, formatDateTime } from '../lib/i18n';
import { useEffect, useState } from 'react';
import { CheckCircle2, KeyRound, Pencil, Plus, Power, Search, ShieldCheck, UserCheck, Users, XCircle } from 'lucide-react';
import { apiFetch } from '../lib/api';
import TeamMemberDialog from './TeamMemberDialog';
import InvitationDialog from './InvitationDialog';
import { Select } from './FormUI';

export default function UserManagementPanel({ currentUser }) {
  const owner = roleOf(currentUser) === 'owner';
  const [permissionUser, setPermissionUser] = useState(null);
  const [users, setUsers] = useState(null);
  const [invitations, setInvitations] = useState([]);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [search, setSearch] = useState('');
  const [role, setRole] = useState('');
  const [shift, setShift] = useState('');
  const [status, setStatus] = useState('');
  const [dialog, setDialog] = useState(null);
  const load = async () => { const [data, pending] = await Promise.all([apiFetch('/users'), apiFetch('/invitations')]); setUsers(data); setInvitations(pending); };
  useEffect(() => {
    let active = true;
    let loading = false;
    const refresh = async () => {
      if (loading || document.visibilityState === 'hidden') return;
      loading = true;
      try {
        const [data, latest] = await Promise.all([apiFetch('/users', { cache: 'no-store' }), apiFetch('/invitations', { cache: 'no-store' })]);
        if (active) { setUsers(data); setInvitations(latest); }
      } catch (failure) { if (active) setError(failure); }
      finally { loading = false; }
    };
    refresh();
    const timer = window.setInterval(refresh, 10000);
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', refresh);
    return () => { active = false; window.clearInterval(timer); window.removeEventListener('focus', refresh); document.removeEventListener('visibilitychange', refresh); };
  }, []);
  const saved = async text => { await load(); setMessage(text); setError(''); };
  const members = users || [];
  const visible = members.filter(user => `${user.name} ${user.username || ''}`.toLowerCase().includes(search.toLowerCase()) && (!role || role === user.role) && (!shift || shift === user.assigned_shift) && (!status || status === user.status));
  const revoke = async invitation => { if (!window.confirm(tr('team.revoke_invitation_confirm'))) return; try { await apiFetch(`/invitations/${invitation.id}/revoke`, { method: 'POST' }); await load(); setMessage(tr('team.invitation_revoked')); } catch (failure) { setError(failure); } };
  return <section id="settings-team" className="st-team"><header className="st-team-heading"><div><span className="st-eyebrow">{tr("common.people_permissions")}</span><h2>{tr("team.team_members")}</h2><p>{tr("team.manage_staff_accounts_roles_and_work_shifts")}</p></div>{<button className="st-button primary" onClick={() => setDialog({ mode: 'invite' })}><Plus size={16} />{tr("team.add_team_member")}</button>}</header>
    <div className="st-team-summary">{[[Users, tr("team.total_members"), members.length], [UserCheck, tr("team.active_members"), members.filter(user => user.status === 'active').length], [CheckCircle2, tr("team.staff"), members.filter(user => user.role === 'staff').length], [ShieldCheck, tr("common.administrators"), members.filter(user => user.role === 'owner').length]].map(([Icon, title, count]) => <article key={title}><Icon size={18} /><strong>{users ? count : '—'}</strong><span>{title}</span></article>)}</div>
    {error && <div role="alert" className="st-error">{messageText(error)}<button className="st-text-button" onClick={() => { setError(''); load().catch(failure => setError(failure)); }}>{tr("common.retry")}</button></div>}
    {message && <p className="st-success" role="status">{messageText(message)}</p>}
    <div className="st-team-ledger"><div className="st-team-filters"><label className="st-team-search"><Search size={16} /><input aria-label={tr("team.search_staff")} placeholder={tr("common.search_by_name_or_username")} value={search} onChange={event => setSearch(event.target.value)} /></label><Select aria-label={tr("team.filter_by_role")} value={role} onChange={setRole} options={[["", tr("team.all_roles")],["manager", tr("team.manager")],["member", tr("team.member")],["owner", tr("common.admin_owner")],["staff", tr("team.staff")]]} placeholder={tr("team.filter_by_role")} /><Select aria-label={tr("team.filter_by_shift")} value={shift} onChange={setShift} options={[["", tr("team.all_shifts")],["A", tr("team.shift_a")],["B", tr("team.shift_b")]]} placeholder={tr("team.filter_by_shift")} /><Select aria-label={tr("common.filter_by_status")} value={status} onChange={setStatus} options={[["", tr("common.all_statuses")],["active", tr("common.active")],["inactive", tr("common.inactive")]]} placeholder={tr("common.filter_by_status")} /></div>
      <div className="st-table-scroll"><table><thead><tr>{[tr("common.name"), tr("common.username"), tr("team.role"), tr("team.shift"), tr("telegram.telegram_access"), tr("common.status"), tr("common.actions")].map(title => <th key={title}>{title}</th>)}</tr></thead><tbody>{visible.map(user => <tr key={user.id}><td><div className="st-member"><span className="st-avatar">{user.name?.slice(0, 2).toUpperCase()}</span><div><strong>{user.name}{user.id === currentUser?.id && <small className="st-you">{tr("common.you")}</small>}</strong><small>{user.must_change_password ? tr("auth.password_change_required") : tr("team.workspace_member")}</small></div></div></td><td>{user.username || tr("common.email_login")}</td><td><span className={`st-role ${user.role}`}>{tr(roleTranslation(user))}</span></td><td>{user.role === 'owner' ? tr("team.all_shifts") : user.assigned_shift ? tr("team.shift_label",{shift:user.assigned_shift}) : tr("common.not_assigned_2")}</td><td><span className={user.telegram_enabled || user.role === 'owner' ? 'st-enabled' : 'st-muted'}>{user.role === 'owner' ? tr("common.owner_access") : user.telegram_enabled ? tr("common.enabled") : tr("common.disabled")}</span></td><td><span className={`st-status ${user.status}`}>{user.status === 'active' ? tr("common.active") : user.status === 'inactive' ? tr("common.inactive") : tr("common.unknown")}</span></td><td><div className="st-member-actions">{(owner || ['staff','member'].includes(roleOf(user))) && <><button aria-label={tr("common.edit_record",{reference:user.name})} title={tr("team.edit_member")} onClick={() => setDialog({ mode: 'edit', user })}><Pencil size={14} /></button>{owner && <button aria-label={tr("team.reset_for",{name:user.name})} title={tr("auth.reset_password_2")} disabled={user.id === currentUser?.id} onClick={() => setDialog({ mode: 'reset', user })}><KeyRound size={14} /></button>}<button aria-label={tr(user.status === 'active' ? "common.deactivate_person" : "common.activate_person",{name:user.name})} title={user.status === 'active' ? tr("team.deactivate_member_2") : tr("team.activate_member_2")} disabled={user.id === currentUser?.id} onClick={() => setDialog({ mode: 'status', user })}><Power size={14} /></button>{owner && user.role !== 'owner' && <button aria-label={tr("rbac.permissions")} title={tr("rbac.permissions")} onClick={() => setPermissionUser(user)}><ShieldCheck size={14} /></button>}</>}</div></td></tr>)}</tbody></table></div>
      {!visible.length && <div className="st-team-empty"><Users size={24} /><strong>{users == null ? error ? tr("team.team_members_could_not_be_loaded") : tr("team.loading_team_members") : members.length ? tr("team.no_matching_team_members") : tr("team.build_your_team")}</strong><p>{members.length ? tr("common.try_a_different_name_or_adjust_your_filters") : tr("team.create_individual_accounts_to_manage_roles_and_work_shifts")}</p></div>}
      <div className="st-table-footer">{users ? tr("team.member_count",{count:visible.length,total:users.length}) : tr("settings.waiting_for_workspace_data")}<span>{tr("common.administrators_have_owner_permissions")}</span></div>
    </div>
    {<div className="st-invitations"><h3>{tr('team.invitations')}</h3>{invitations.length ? <div className="st-table-scroll"><table><thead><tr><th>{tr('common.name')}</th><th>{tr('team.contact')}</th><th>{tr('team.role')}</th><th>{tr('common.status')}</th><th>{tr('common.actions')}</th></tr></thead><tbody>{invitations.map(inv => <tr key={inv.id}><td>{inv.name || tr('common.not_provided')}</td><td>{inv.email || inv.phone}</td><td>{tr(`team.${inv.role}`)}</td><td><span className={`st-status ${inv.status}`}>{tr(`team.invitation_${inv.status}`)}</span>{inv.status === 'accepted' && inv.accepted_at && <small className="st-accepted-date">{tr('team.accepted_on', { date: formatDateTime(inv.accepted_at) })}</small>}</td><td><button className="st-icon-button" disabled={inv.status !== 'pending'} aria-label={tr('team.revoke_invitation')} title={tr('team.revoke_invitation')} onClick={() => revoke(inv)}><XCircle size={15} /></button></td></tr>)}</tbody></table></div> : <p className="st-muted-copy">{tr('team.no_invitations')}</p>}</div>}
    {permissionUser && <PermissionEditor user={permissionUser} close={() => { setPermissionUser(null); load().catch(setError); }} />}
    {dialog?.mode === 'invite' && <InvitationDialog currentUser={currentUser} close={() => setDialog(null)} onSaved={async text => { await load(); setMessage(text); setError(''); }} />}
    {dialog && dialog.mode !== 'invite' && <TeamMemberDialog key={`${dialog.mode}-${dialog.user?.id || 'new'}`} {...dialog} currentUser={currentUser} close={() => setDialog(null)} onSaved={saved} />}
  </section>;
}
