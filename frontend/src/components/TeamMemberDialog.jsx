import { messageText } from '../lib/i18n';
import { t as tr } from '../lib/i18n';
import { useEffect, useRef, useState } from 'react';
import { ShieldCheck, UserPlus, X } from 'lucide-react';
import { apiFetch } from '../lib/api';

export default function TeamMemberDialog({ mode, user, currentUser, close, onSaved }) {
  const [form, setForm] = useState({ name: user?.name || '', username: user?.username || '', password: '', role: user?.role || 'staff', assigned_shift: user?.assigned_shift || 'A', telegram_enabled: Boolean(user?.telegram_enabled) });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const dialog = useRef(null);
  useEffect(() => { const previous = document.activeElement; dialog.current?.querySelector('input,button')?.focus(); return () => previous?.focus(); }, []);
  const change = event => setForm(current => ({ ...current, [event.target.name]: event.target.type === 'checkbox' ? event.target.checked : event.target.value }));
  const isStatus = mode === 'status';
  const nextStatus = user?.status === 'active' ? 'inactive' : 'active';
  const title = mode === 'create' ? tr("team.add_team_member") : mode === 'edit' ? tr("team.edit_team_member") : mode === 'reset' ? tr("auth.reset_password") : nextStatus === 'inactive' ? tr("team.deactivate_member") : tr("team.activate_member");
  const submit = async event => {
    event.preventDefault(); setBusy(true); setError('');
    const data = mode === 'reset' ? { password: form.password } : isStatus ? { status: nextStatus } : { name: form.name.trim(), username: form.username.trim(), role: form.role, assigned_shift: form.role === 'staff' ? form.assigned_shift : null, telegram_enabled: form.role === 'staff' && form.telegram_enabled, ...(mode === 'create' ? { password: form.password } : {}) };
    const url = mode === 'create' ? '/users' : `/users/${user.id}${mode === 'reset' ? '/reset-password' : isStatus ? '/status' : ''}`;
    try { await apiFetch(url, { method: mode === 'edit' ? 'PATCH' : 'POST', body: JSON.stringify(data) }); await onSaved(mode === 'create' ? tr("auth.team_member_created_share_their_temporary_password_securely") : mode === 'reset' ? tr("auth.password_reset_existing_sessions_have_been_revoked") : tr("team.team_member_updated")); close(); } catch (failure) { setError(failure); } finally { setBusy(false); }
  };
  const keyDown = event => {
    if (event.key === 'Escape' && !busy) close();
    if (event.key !== 'Tab') return;
    const focusable = [...dialog.current.querySelectorAll('button:not(:disabled),input:not(:disabled),select:not(:disabled)')];
    const first = focusable[0]; const last = focusable.at(-1);
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
  };
  return <div className="st-modal-backdrop"><section ref={dialog} className="st-modal" role="dialog" aria-modal="true" aria-labelledby="team-dialog-title" onKeyDown={keyDown}><header><span className="st-link-icon">{isStatus ? <ShieldCheck size={21} /> : <UserPlus size={21} />}</span><button aria-label={tr("team.close_team_dialog")} onClick={close} disabled={busy}><X size={20} /></button></header><h2 id="team-dialog-title">{title}</h2><p>{isStatus ? tr(nextStatus === 'inactive' ? "team.deactivate_confirmation" : "team.activate_confirmation",{name:user.name}) : mode === 'reset' ? tr("team.reset_description",{name:user.name}) : tr("team.give_every_team_member_their_own_secure_workspace_account")}</p><form onSubmit={submit}>
    {(mode === 'create' || mode === 'edit') && <><label>{tr("common.full_name")}<input name="name" autoComplete="name" required maxLength={255} value={form.name} onChange={change} /></label><label>{tr("common.username")}<input name="username" autoComplete="off" required minLength={3} maxLength={50} pattern="[A-Za-z0-9._\-]+" title={tr("common.use_3_50_letters_numbers_dots_underscores_or_hyphens")} value={form.username} onChange={change} /><small>{tr("common.3_50_characters_letters_numbers_dots_underscores_and_hyphens")}</small></label></>}
    {(mode === 'create' || mode === 'reset') && <label>{tr("auth.temporary_password")}<input name="password" type="password" autoComplete="new-password" required minLength={8} value={form.password} onChange={change} /><small>{tr("team.at_least_8_characters_the_member_must_change_it_after_signing_in")}</small></label>}
    {(mode === 'create' || mode === 'edit') && <><label>{tr("team.role")}<select name="role" value={form.role} onChange={change} disabled={mode === 'edit' && user?.id === currentUser?.id}><option value="staff">{tr("team.staff")}</option><option value="owner">{tr("common.admin_owner")}</option></select><small>{tr("settings.administrators_have_full_owner_access_to_the_business_workspace")}</small></label>{form.role === 'staff' && <><label>{tr("team.work_shift")}<select name="assigned_shift" value={form.assigned_shift} onChange={change} required><option value="A">{tr("team.shift_a")}</option><option value="B">{tr("team.shift_b")}</option></select></label><label className="st-checkbox"><input name="telegram_enabled" type="checkbox" checked={form.telegram_enabled} onChange={change} /><span><strong>{tr("sales.telegram_sales_access")}</strong><small>{tr("sales.allow_this_member_to_record_sales_through_telegram")}</small></span></label></>}</>}
    {error && <p className="st-error" role="alert">{messageText(error)}</p>}<div className="st-modal-actions"><button type="button" className="st-button" onClick={close} disabled={busy}>{tr("common.cancel")}</button><button className={`st-button ${isStatus && nextStatus === 'inactive' ? 'danger' : 'primary'}`} disabled={busy}>{busy ? tr("common.saving") : mode === 'create' ? tr("common.create_user") : mode === 'reset' ? tr("auth.reset_password") : isStatus ? nextStatus === 'inactive' ? tr("common.deactivate") : tr("common.activate") : tr("common.save_changes")}</button></div></form></section></div>;
}
