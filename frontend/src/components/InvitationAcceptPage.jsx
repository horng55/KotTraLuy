import { messageText, t as tr } from '../lib/i18n';
import { useEffect, useState } from 'react';
import { CheckCircle2, Eye, EyeOff, LoaderCircle, LockKeyhole } from 'lucide-react';
import { apiFetch, saveToken } from '../lib/api';
import BrandLogo from './BrandLogo';

const initialForm = { name: '', password: '', password_confirmation: '' };

export default function InvitationAcceptPage({ token, onAccepted, language, toggleLanguage }) {
  const [invitation, setInvitation] = useState(null);
  const [form, setForm] = useState(initialForm);
  const [mode, setMode] = useState('create');
  const [showPassword, setShowPassword] = useState(false);
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [signedIn, setSignedIn] = useState(false);
  const [login, setLogin] = useState({ email: '', password: '' });

  useEffect(() => {
    let active = true;
    apiFetch(`/invitations/${encodeURIComponent(token)}`)
      .then(data => { if (active) setInvitation(data); })
      .catch(failure => { if (active) setError(failure); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [token]);

  const setField = event => setForm(current => ({ ...current, [event.target.name]: event.target.value }));
  const submitNew = async event => {
    event.preventDefault();
    if (form.password !== form.password_confirmation) { setError(tr('validation.confirmation')); return; }
    setBusy(true); setError('');
    try { const result = await apiFetch(`/invitations/${encodeURIComponent(token)}/accept`, { method: 'POST', body: JSON.stringify(form) }); saveToken(result.token); onAccepted(result); }
    catch (failure) { setError(failure); }
    finally { setBusy(false); }
  };
  const submitExisting = async event => {
    event.preventDefault(); setBusy(true); setError('');
    try {
      if (!signedIn) { const result = await apiFetch('/login', { method: 'POST', body: JSON.stringify(login) }); saveToken(result.token); setSignedIn(true); }
      const result = await apiFetch(`/invitations/${encodeURIComponent(token)}/accept-existing`, { method: 'POST' }); saveToken(result.token); onAccepted(result);
    } catch (failure) { setError(failure); }
    finally { setBusy(false); }
  };
  const statusCopy = invitation?.status === 'accepted' ? 'team.invitation_accepted' : invitation?.status === 'expired' ? 'team.invitation_expired' : invitation?.status === 'revoked' ? 'team.invitation_revoked' : invitation?.status === 'declined' ? 'team.invitation_declined' : 'team.invitation_unavailable';
  const unavailable = invitation && invitation.status !== 'pending';
  return <main className="invitation-screen"><section className="invitation-card" aria-labelledby="invitation-title">
    <header className="invitation-header"><BrandLogo className="invitation-logo" white /><button className="invitation-language" type="button" onClick={toggleLanguage}>{language === 'en' ? 'ខ្មែរ' : 'English'}</button></header>
    {loading ? <StatusState title={tr('common.loading')} copy={tr('team.invitation_loading')} /> : unavailable || error && !invitation ? <StatusState icon={<LockKeyhole size={25} />} title={tr(statusCopy)} copy={error && !invitation ? messageText(error) : tr('team.invitation_unavailable_description')} /> : <>
      <div className="invitation-intro"><span className="invitation-kicker">{tr('team.invite_employee')}</span><h1 id="invitation-title">{tr('team.join_workspace')}</h1><p>{tr('team.invitation_subtitle')}</p></div>
      <div className="invitation-summary"><div><span>{tr('team.workspace')}</span><strong>{invitation.workspace?.name || tr('common.my_business')}</strong></div><div><span>{tr('team.role')}</span><strong>{tr(`team.${invitation.role}`)}</strong></div><span className="invitation-status pending">{tr('team.invitation_pending')}</span></div>
      {mode === 'create' ? <form className="invitation-form" onSubmit={submitNew} noValidate><Field label={tr('common.full_name')} name="name" value={form.name} onChange={setField} autoComplete="name" required /><PasswordField label={tr('auth.password')} name="password" value={form.password} onChange={setField} visible={showPassword} toggle={() => setShowPassword(value => !value)} autoComplete="new-password" /><small className="invitation-help">{tr('team.password_guidance')}</small><PasswordField label={tr('auth.confirm_password')} name="password_confirmation" value={form.password_confirmation} onChange={setField} visible={showPassword} toggle={() => setShowPassword(value => !value)} autoComplete="new-password" error={form.password_confirmation && form.password !== form.password_confirmation ? tr('validation.confirmation') : ''} />{error && <p className="invitation-error" role="alert">{messageText(error)}</p>}<SubmitButton busy={busy} label={tr('team.accept_invitation')} /></form> : <form className="invitation-form" onSubmit={submitExisting} noValidate>{!signedIn ? <><Field label={tr('common.email')} value={login.email} onChange={event => setLogin({ ...login, email: event.target.value })} autoComplete="email" required type="email" /><PasswordField label={tr('auth.password')} value={login.password} onChange={event => setLogin({ ...login, password: event.target.value })} visible={showPassword} toggle={() => setShowPassword(value => !value)} autoComplete="current-password" /></> : <p className="invitation-success" role="status"><CheckCircle2 size={18} />{tr('team.signed_in_accepting')}</p>}{error && <p className="invitation-error" role="alert">{messageText(error)}</p>}<SubmitButton busy={busy} label={tr('team.sign_in_to_accept')} /></form>}
      <button className="invitation-mode" type="button" onClick={() => { setMode(mode === 'create' ? 'existing' : 'create'); setError(''); }}>{mode === 'create' ? tr('team.existing_account_prompt') : tr('team.use_new_account')}</button>
    </>}
  </section></main>;
}

function Field({ label, name, value, onChange, ...props }) { return <label className="invitation-field"><span>{label}</span><input name={name} value={value} onChange={onChange} {...props} /></label>; }
function PasswordField({ label, name, value, onChange, visible, toggle, error, ...props }) { return <label className="invitation-field"><span>{label}</span><span className="invitation-password"><input name={name} type={visible ? 'text' : 'password'} value={value} onChange={onChange} {...props} /><button type="button" aria-label={visible ? tr('team.hide_password') : tr('team.show_password')} onClick={toggle}>{visible ? <EyeOff size={17} /> : <Eye size={17} />}</button></span>{error && <small className="invitation-field-error">{error}</small>}</label>; }
function SubmitButton({ busy, label }) { return <button className="button primary full invitation-submit" disabled={busy}>{busy && <LoaderCircle className="invitation-spinner" size={17} />}{busy ? tr('common.saving') : label}</button>; }
function StatusState({ icon = <LoaderCircle className="invitation-spinner" size={25} />, title, copy }) { return <div className="invitation-status-state"><span>{icon}</span><h1>{title}</h1><p>{copy}</p></div>; }
