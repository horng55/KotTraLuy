import { t as translate, messageText, formatDateTime } from '../lib/i18n';
import { useEffect, useState } from 'react';
import { ArrowUpRight, Bell, BellRing, CheckCheck, ChevronLeft, ChevronRight, BellCheck, FileCheck2, Package, ReceiptText, ShieldCheck, ShoppingCart, Users, WalletCards } from 'lucide-react';
import { apiFetch } from '../lib/api';
import { useNotifications } from '../lib/NotificationContext';
import { notificationCategories, notificationText, relativeTime } from '../lib/notificationText';
import { FilterSelect } from './FormUI';

const targets = new Set(['inventory', 'records', 'alerts', 'more', 'telegram', 'packs', 'business']);
const icons = { sales: ShoppingCart, inventory: Package, expenses: ReceiptText, team: Users, financing: FileCheck2, system: ShieldCheck };
export default function NotificationsPanel({ language = 'en', onNavigate, onClose, fullPage = false }) {
  const { unreadCount, revision, enabled, error: pollingError, mutating, refresh, markRead, markUnread, markAllRead } = useNotifications();
  const [unread, setUnread] = useState(false); const [category, setCategory] = useState(''); const [page, setPage] = useState(1);
  const [result, setResult] = useState(null); const [loading, setLoading] = useState(true); const [error, setError] = useState(''); const [retry, setRetry] = useState(0);
  const km = language === 'km';
  useEffect(() => {
    const controller = new AbortController();
    const load = async () => {
      setLoading(true); setError('');
      if (!enabled) { setLoading(false); setResult(null); return; }
      try { const data = await apiFetch(`/notifications?${new URLSearchParams({ unread: unread ? '1' : '0', ...(category ? { category } : {}), page, per_page: fullPage ? 20 : 8 })}`, { signal: controller.signal }); if (!controller.signal.aborted) { setResult(data); if (page > data.last_page) setPage(Math.max(1, data.last_page)); } }
      catch (failure) { if (!controller.signal.aborted) setError(failure); }
      finally { if (!controller.signal.aborted) setLoading(false); }
    };
    const timer = setTimeout(load, 0); return () => { clearTimeout(timer); controller.abort(); };
  }, [enabled, revision, unread, category, page, fullPage, retry]);
  const action = async callback => { setError(''); try { await callback(); } catch (failure) { setError(failure); } };
  const open = async item => { if (!item.read_at) { const success = await markRead(item.id); if (!success) return; } if (targets.has(item.action_page)) { onNavigate(item.action_page, { entity_type: item.entity_type, entity_id: item.entity_id, type: item.type }); onClose?.(); } };
  const rows = result?.data || [];
  return <section className={`notifications-panel ${fullPage ? 'notifications-full' : ''}`} aria-label={translate("common.notifications",{},language)}>
    <header className="nt-header"><div><h2>{translate("common.notifications",{},language)}</h2><span>{translate("notifications.unread_count",{count:unreadCount.toLocaleString(km?'km-KH':'en-US')},language)}</span></div><button disabled={!unreadCount || mutating || !enabled} onClick={() => action(markAllRead)}><CheckCheck size={15} />{translate("common.mark_all_as_read",{},language)}</button></header>
    <div className="nt-filters"><div role="group" aria-label={translate("common.filter_notifications",{},language)}>{[[false, 'common.all'], [true, 'common.unread']].map(([value, en]) => <button key={en} aria-pressed={unread === value} onClick={() => { setUnread(value); setPage(1); }}>{translate(en,{},language)}</button>)}</div>{fullPage && <label><span className="nt-sr-only">{translate("common.notification_type",{},language)}</span><FilterSelect value={category} onChange={value => { setCategory(value); setPage(1); }} options={[["", translate("common.all_types",{},language)], ...notificationCategories.map(key => [key, translate(`notifications.category.${key}`,{},language)])]} placeholder={translate("common.notification_type",{},language)} /></label>}</div>
    <div className="nt-list" aria-busy={loading}>
      {(error || pollingError) && <div className="nt-error" role="alert"><p>{translate("common.unable_to_load_or_update_notifications",{},language)}</p><small>{messageText(error || pollingError)}</small><button onClick={() => { setRetry(value => value + 1); refresh(); }}>{translate("common.retry",{},language)}</button></div>}
      {loading && !result ? <div className="nt-empty"><Bell size={22} /><p>{translate("common.loading_notifications",{},language)}</p></div> : !rows.length && !error ? <div className="nt-empty"><BellCheck size={26} /><strong>{unread ? translate("common.no_unread_notifications",{},language) : translate("common.you_re_all_caught_up",{},language)}</strong><p>{translate("common.new_business_updates_will_appear_here",{},language)}</p></div> : rows.map(item => { const copy = notificationText(item, language); const Icon = item.type === 'payment.received' ? WalletCards : icons[item.category] || BellRing; return <article className={`nt-item ${item.read_at ? '' : 'is-unread'}`} key={item.id}><span className="nt-item-icon"><Icon size={17} /></span><div className="nt-item-content"><button className="nt-open" disabled={mutating} onClick={() => action(() => open(item))}><strong>{copy.title}</strong><p>{copy.message}</p></button><div className="nt-item-meta"><time dateTime={item.created_at} title={formatDateTime(item.created_at,language)}>{relativeTime(item.created_at, language)}</time>{fullPage && <button disabled={mutating} onClick={() => action(() => item.read_at ? markUnread(item.id) : markRead(item.id))}>{item.read_at ? translate("common.mark_unread",{},language) : translate("common.mark_read",{},language)}</button>}</div></div>{!item.read_at && <span className="nt-unread-dot" aria-label={translate("common.unread",{},language)} />}</article>; })}
    </div>
    {fullPage ? <footer className="nt-pagination"><span>{translate("notifications.total_count",{count:result?.total||0},language)}</span><div><button aria-label={translate("common.previous_page",{},language)} disabled={loading || page <= 1} onClick={() => setPage(value => value - 1)}><ChevronLeft size={16} /></button><span>{page} / {result?.last_page || 1}</span><button aria-label={translate("common.next_page",{},language)} disabled={loading || page >= (result?.last_page || 1)} onClick={() => setPage(value => value + 1)}><ChevronRight size={16} /></button></div></footer> : <footer className="nt-footer"><button onClick={() => { onNavigate('notifications'); onClose?.(); }}>{translate("common.view_all_notifications",{},language)}<ArrowUpRight size={14} /></button></footer>}
  </section>;
}
