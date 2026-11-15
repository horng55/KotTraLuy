import { messageText, t as tr } from '../lib/i18n';
import { useEffect, useRef, useState } from 'react';
import { Check, Link2, Send, UserPlus, X } from 'lucide-react';
import { apiFetch } from '../lib/api';
import { getTelegramWebApp, isTelegramMiniApp, telegramHaptic } from '../lib/telegram';
import { Select } from './FormUI';

export default function InvitationDialog({ close, onSaved }) {
  const [form, setForm] = useState({ name: '', contact: '', role: 'staff' });
  const [busy, setBusy] = useState(false); const [error, setError] = useState(''); const [result, setResult] = useState(null); const [copied, setCopied] = useState(false); const [copyError, setCopyError] = useState(''); const [sharing, setSharing] = useState(false);
  const dialog = useRef(null); const linkInput = useRef(null);
  useEffect(() => { const previous = document.activeElement; dialog.current?.querySelector('input,button')?.focus(); return () => previous?.focus(); }, []);
  const change = event => setForm(current => ({ ...current, [event.target.name]: event.target.value }));
  const submit = async event => {
    event.preventDefault(); setBusy(true); setError('');
    try { const response = await apiFetch('/invitations', { method: 'POST', body: JSON.stringify({ ...form, name: form.name.trim() || null, contact: form.contact.trim() }) }); setResult(response); await onSaved(tr('team.invitation_created')); } catch (failure) { setError(failure); } finally { setBusy(false); }
  };
  const copyLink = async () => {
    if (!result?.share_url) return;
    setCopyError('');
    try {
      if (window.isSecureContext && typeof navigator.clipboard?.writeText === 'function') await navigator.clipboard.writeText(result.share_url);
      else { const input = linkInput.current; if (!input) throw new Error('Clipboard is unavailable.'); input.focus(); input.select(); if (typeof document.execCommand !== 'function' || !document.execCommand('copy')) throw new Error('Clipboard permission was denied.'); }
      setCopied(true); telegramHaptic('success'); window.setTimeout(() => setCopied(false), 2500);
    } catch { linkInput.current?.focus(); linkInput.current?.select(); setCopyError(tr('team.copy_link_failed')); telegramHaptic('error'); }
  };
  const shareViaTelegram = () => {
    if (!result?.share_url) return;
    setSharing(true); setCopyError('');
    try { const shareUrl = `https://t.me/share/url?url=${encodeURIComponent(result.share_url)}`; const webApp = getTelegramWebApp(); if (isTelegramMiniApp(webApp) && typeof webApp.openTelegramLink === 'function') webApp.openTelegramLink(shareUrl); else if (isTelegramMiniApp(webApp) && typeof webApp.openLink === 'function') webApp.openLink(shareUrl); else window.open(shareUrl, '_blank', 'noopener,noreferrer'); }
    catch { setCopyError(tr('team.telegram_share_failed')); } finally { setSharing(false); }
  };
  const keyDown = event => { if (event.key === 'Escape' && !busy) close(); if (event.key !== 'Tab') return; const focusable = [...dialog.current.querySelectorAll('button:not(:disabled),input:not(:disabled),select:not(:disabled)')]; const first = focusable[0]; const last = focusable.at(-1); if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); } };
  return <div className="st-modal-backdrop"><section ref={dialog} className="st-modal" role="dialog" aria-modal="true" aria-labelledby="invite-dialog-title" onKeyDown={keyDown}><header><span className="st-link-icon"><UserPlus size={21} /></span><button type="button" aria-label={tr('team.close_team_dialog')} onClick={close} disabled={busy}><X size={20} /></button></header><h2 id="invite-dialog-title">{tr('team.invite_employee')}</h2><p>{tr('team.invite_employee_description')}</p>{result ? <div className="st-invite-result"><strong>{tr('team.invitation_pending')}</strong><p>{result.manual_share_required ? tr('team.share_invitation_link') : tr('team.invitation_sent')}</p><div className="st-copy-link"><input ref={linkInput} readOnly value={result.share_url} aria-label={tr('team.invitation_link')} onFocus={event => event.target.select()} /><button type="button" className="st-button" onClick={copyLink} disabled={!result.share_url}>{copied ? <Check size={14} /> : <Link2 size={14} />}{copied ? tr('team.copied') : tr('team.copy_link')}</button></div>{copied && <p className="st-success" role="status">{tr('team.invitation_link_copied')}</p>}{copyError && <p className="st-error" role="alert">{messageText(copyError)}</p>}<div className="st-modal-actions"><button type="button" className="st-button" onClick={shareViaTelegram} disabled={!result.share_url || sharing}><Send size={14} />{tr('team.share_via_telegram')}</button><button type="button" className="st-button primary" onClick={close}>{tr('common.done')}</button></div></div> : <form onSubmit={submit}><label>{tr('team.employee_name_optional')}<input name="name" autoComplete="name" maxLength={255} value={form.name} onChange={change} /></label><label>{tr('team.email_or_phone')}<input name="contact" required autoComplete="email tel" placeholder={tr('team.contact_placeholder')} value={form.contact} onChange={change} /><small>{tr('team.email_or_phone_help')}</small></label><label>{tr('team.role')}<Select name="role" value={form.role} onChange={value => setForm(current => ({ ...current, role: value }))} options={[["manager", tr('team.manager')],["cashier", tr('team.cashier')],["staff", tr('team.staff')]]} placeholder={tr('team.role')} /></label>{error && <p className="st-error" role="alert">{messageText(error)}</p>}<div className="st-modal-actions"><button type="button" className="st-button" onClick={close} disabled={busy}>{tr('common.cancel')}</button><button className="st-button primary" disabled={busy}>{busy ? tr('common.saving') : tr('team.send_invitation')}</button></div></form>}</section></div>;
}
