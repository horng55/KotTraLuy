import { messageText, formatTime } from '../lib/i18n';
import { t as tr } from '../lib/i18n';
import { useCallback, useEffect, useState } from 'react';
import { AlertCircle, CheckCircle2, Clock3, ExternalLink, Link2, RefreshCw, Send, ShieldCheck, ShoppingCart, Unlink, X } from 'lucide-react';
import { apiFetch } from '../lib/api';
import { FinancialPageHeader } from './FinancialPageUI';

export default function TelegramAccessPanel({ user }) {
  const [account, setAccount] = useState(user || null);
  const [status, setStatus] = useState(null);
  const [connection, setConnection] = useState(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState('');
  const [confirmDisconnect, setConfirmDisconnect] = useState(false);
  const [expired, setExpired] = useState(false);
  const [unavailable, setUnavailable] = useState(false);
  const load = useCallback(async () => {
    const [currentAccount, currentStatus] = await Promise.all([apiFetch('/user'), apiFetch('/telegram/status')]);
    setAccount(currentAccount); setStatus(currentStatus); setUnavailable(false);
    if (currentStatus.connected) setConnection(null);
    return currentStatus;
  }, []);
  useEffect(() => {
    let active = true;
    Promise.all([apiFetch('/user'), apiFetch('/telegram/status')]).then(([currentAccount, currentStatus]) => { if (active) { setAccount(currentAccount); setStatus(currentStatus); } }).catch(failure => { if (active) { setUnavailable(true); setError(failure); } });
    return () => { active = false; };
  }, []);
  const enabled = account ? account.role === 'owner' || Boolean(account.telegram_enabled) : null;
  const connected = status?.connected === true;
  useEffect(() => {
    if (!connection || connected || expired) return;
    let active = true;
    const timer = setInterval(async () => {
      if (Date.now() >= new Date(connection.expires_at).getTime()) { if (active) { setExpired(true); setConnection(null); } return; }
      try { const data = await apiFetch('/telegram/status'); if (active) { setStatus(data); if (data.connected) { setConnection(null); setError(''); } } } catch (failure) { if (active) setError(failure); }
    }, 8000);
    return () => { active = false; clearInterval(timer); };
  }, [connection, connected, expired]);
  const refresh = async () => { setBusy('refresh'); setError(''); try { await load(); } catch (failure) { setError(failure); setUnavailable(true); } finally { setBusy(''); } };
  const connect = async () => {
    setBusy('connect'); setError(''); setExpired(false);
    try { const current = await load(); if (current.connected) return; const data = await apiFetch('/telegram/link-code', { method: 'POST' }); if (!/^https:\/\/t\.me\//.test(data.bot_url || '') || !data.expires_at) throw new Error(tr("telegram.telegram_linking_is_unavailable_please_try_again_later")); setConnection({ bot_url: data.bot_url, expires_at: data.expires_at }); }
    catch (failure) { setError(failure); } finally { setBusy(''); }
  };
  const disconnect = async () => {
    setBusy('disconnect'); setError('');
    try { await apiFetch('/telegram/link', { method: 'DELETE' }); setConnection(null); setStatus({ connected: false, telegram_chat: null }); setConfirmDisconnect(false); await load(); } catch (failure) { setError(failure); } finally { setBusy(''); }
  };
  const statusLabel = unavailable ? tr("common.unavailable") : status == null ? tr("common.checking_connection") : connected ? tr("common.connected") : tr("common.not_connected");
  return <section className="financial-page integration-page"><FinancialPageHeader title={tr("telegram.telegram_bot_integration")} breadcrumbTitle={tr("telegram.telegram_bot_access")} description={tr("sales.connect_your_telegram_account_to_record_sales_through_kottraluy")} />
    <div className="ig-telegram-layout"><div className="ig-main"><section className="ig-card ig-connection"><header><span className="ig-telegram-icon"><Send size={26} /></span><span className={`ig-status ${connected ? 'connected' : ''}`}>{connected && <CheckCircle2 size={13} />}{statusLabel}</span></header><h2>{connected ? tr("telegram.your_telegram_account_is_connected") : tr("telegram.connect_your_telegram_account")}</h2><p>{connected ? tr("telegram.your_telegram_account_is_linked_to_this_kottraluy_workspace") : tr("sales.link_your_telegram_account_to_record_sales_through_the_kottraluy_bot")}</p>{connected && status.telegram_chat && <div className="ig-linked-account"><Link2 size={16} /><div><small>{tr("telegram.linked_telegram_account")}</small><strong>{status.telegram_chat}</strong></div><span>{tr("common.masked_for_privacy")}</span></div>}
      <div className="ig-connection-actions">{connected ? <button className="fp-button" disabled={Boolean(busy)} onClick={() => setConfirmDisconnect(true)}><Unlink size={15} />{tr("common.disconnect")}</button> : <button className="fp-button primary" disabled={!enabled || status == null || unavailable || Boolean(busy)} onClick={connect}><Link2 size={15} />{busy === 'connect' ? tr("common.preparing_connection") : connection ? tr("common.generate_new_link") : expired ? tr("common.retry_connection") : tr("telegram.connect_telegram")}</button>}<button className="ig-text-button" disabled={Boolean(busy)} onClick={refresh}><RefreshCw size={14} />{busy === 'refresh' ? tr("common.checking") : tr("common.refresh_status")}</button></div>
      {connection && !connected && <div className="ig-link-instructions"><strong>{tr("telegram.finish_linking_in_telegram")}</strong><p>{tr('telegram.link_instruction',{time:formatTime(connection.expires_at)})}</p><a className="fp-button primary" href={connection.bot_url} target="_blank" rel="noreferrer">{tr("telegram.open_kottraluy_bot")}<ExternalLink size={14} /></a><small>{tr("common.return_here_to_check_your_connection_do_not_share_your_private_linking_url")}</small></div>}
      {expired && !connected && <p className="ig-inline-note"><Clock3 size={15} />{tr("common.your_connection_link_expired_generate_a_new_link_to_try_again")}</p>}
      {enabled === false && <div className="ig-disabled"><ShieldCheck size={18} /><div><strong>{tr("settings.workspace_owner_approval_required")}</strong><p>{tr("sales.telegram_sales_access_is_disabled_ask_your_workspace_owner_to_enable_telegram_sales_access_in_user_m")}</p></div></div>}
      {error && <div className="ig-error" role="alert"><AlertCircle size={17} /><div><strong>{unavailable ? tr("telegram.telegram_integration_is_unavailable") : tr("common.unable_to_complete_this_action")}</strong><p>{messageText(error)}</p><button disabled={Boolean(busy)} className="ig-text-button" onClick={refresh}>{tr("common.retry_status_check")}</button></div></div>}
    </section>
    <section className="ig-how"><header><h2>{tr("common.how_it_works")}</h2><p>{tr("sales.three_simple_steps_to_connect_your_daily_sales")}</p></header><div className="ig-steps">{[[Link2, tr("telegram.connect_telegram"), tr("telegram.link_your_telegram_account_to_your_kottraluy_workspace")], [Send, tr("telegram.open_the_bot"), tr("telegram.open_the_kottraluy_bot_in_telegram_and_complete_its_confirmation_command")], [ShoppingCart, tr("sales.record_sales"), tr("sales.record_sales_through_telegram_when_your_assigned_shift_is_active")]].map(([Icon, title, copy], index) => <article key={title}><div><span>{index + 1}</span><Icon size={17} /></div><h3>{title}</h3><p>{copy}</p></article>)}</div></section></div>
    <aside className="ig-card ig-permissions"><header><ShieldCheck size={19} /><h2>{tr("telegram.your_telegram_access")}</h2></header><p>{tr("settings.your_workspace_permissions_and_connection_details")}</p><dl><div><dt>{tr("sales.telegram_sales_permission")}</dt><dd><span className={`ig-status ${enabled ? 'connected' : ''}`}>{enabled == null ? tr("common.not_available") : enabled ? tr("common.enabled") : tr("common.disabled")}</span></dd></div><div><dt>{tr("team.assigned_work_shift")}</dt><dd>{account?.assigned_shift ? tr("team.shift_label",{shift:account.assigned_shift}) : tr("common.not_assigned")}</dd></div><div><dt>{tr("common.connection_status")}</dt><dd>{statusLabel}</dd></div>{account && <div><dt>{tr("team.workspace_role")}</dt><dd>{account.role === 'owner' ? tr("common.owner") : tr("team.staff")}</dd></div>}</dl><div className="ig-security-note"><ShieldCheck size={16} /><p>{tr("telegram.only_your_masked_telegram_identifier_is_shown_bot_credentials_are_never_displayed")}</p></div></aside></div>
    {confirmDisconnect && <div className="ig-modal-backdrop"><section className="ig-confirm" role="dialog" aria-modal="true" aria-labelledby="disconnect-title"><header><h2 id="disconnect-title">{tr("telegram.disconnect_telegram_2")}</h2><button aria-label={tr("common.cancel_disconnect")} disabled={Boolean(busy)} onClick={() => setConfirmDisconnect(false)}><X size={18} /></button></header><p>{tr("telegram.this_removes_your_linked_telegram_account_you_can_reconnect_later_if_access_remains_enabled")}</p><div><button className="fp-button" disabled={Boolean(busy)} onClick={() => setConfirmDisconnect(false)}>{tr("common.cancel")}</button><button className="fp-button danger" disabled={Boolean(busy)} onClick={disconnect}>{busy === 'disconnect' ? tr("common.disconnecting") : tr("telegram.disconnect_telegram")}</button></div>{error && <p className="ig-error" role="alert">{messageText(error)}</p>}</section></div>}
  </section>;
}
