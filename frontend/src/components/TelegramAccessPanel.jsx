import { useEffect, useState } from 'react';
import { ExternalLink, ShieldCheck } from 'lucide-react';
import { apiFetch } from '../lib/api';
import telegramBotIcon from '../assets/icons8-bot-50.svg';

export default function TelegramAccessPanel({ user }) {
  const [account, setAccount] = useState(user);
  const [status, setStatus] = useState(null);
  const [connection, setConnection] = useState(null);
  const [message, setMessage] = useState('');
  const [loading, setLoading] = useState(false);

  const load = async () => {
    try { setStatus(await apiFetch('/telegram/status')); } catch (error) { setMessage(error.message); }
  };

  useEffect(() => {
    let active = true;
    Promise.all([apiFetch('/user'), apiFetch('/telegram/status')])
      .then(([currentAccount, currentStatus]) => {
        if (active) { setAccount(currentAccount); setStatus(currentStatus); }
      })
      .catch((error) => { if (active) setMessage(error.message); });
    return () => { active = false; };
  }, []);

  const connect = async () => {
    setLoading(true); setMessage('');
    try { setConnection(await apiFetch('/telegram/link-code', { method: 'POST' })); }
    catch (error) { setMessage(error.message); }
    finally { setLoading(false); }
  };

  const disconnect = async () => {
    setLoading(true); setMessage('');
    try { await apiFetch('/telegram/link', { method: 'DELETE' }); setConnection(null); await load(); }
    catch (error) { setMessage(error.message); }
    finally { setLoading(false); }
  };

  const enabled = account?.role === 'owner' || account?.telegram_enabled;

  return <section className="panel full-panel">
    <div className="panel-title"><div><h2>Telegram bot access</h2><p>Record sales from Telegram while your assigned shift is open.</p></div></div>
    <div className="notice"><ShieldCheck size={17} /><span>{enabled ? 'Your owner has enabled this account for Telegram records.' : 'Telegram access is disabled. Ask the business owner to enable it in User management.'}</span></div>
    {enabled && <div className="telegram-access-actions">
      {status?.connected ? <><p>Connected Telegram account: <strong>{status.telegram_chat}</strong></p><button className="button secondary" disabled={loading} onClick={disconnect}>Disconnect</button></> : <button className="button primary" disabled={loading} onClick={connect}><img className="inline-icon" src={telegramBotIcon} alt="" width="15" height="15" /> {loading ? 'Preparing link…' : 'Connect Telegram'}</button>}
      {connection && <div className="notice"><span>Send <strong>{connection.command}</strong> to the bot, then open <a href={connection.bot_url} target="_blank" rel="noreferrer">Telegram <ExternalLink size={13} /></a>. This link expires in 5 minutes.</span></div>}
    </div>}
    {message && <p className="form-error">{message}</p>}
  </section>;
}
