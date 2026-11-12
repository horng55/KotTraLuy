import { useCallback, useEffect, useRef, useState } from 'react';
import { apiFetch } from '../lib/api';
import { NotificationContext } from '../lib/NotificationContext';

export default function NotificationProvider({ user, children }) {
  const enabled = Boolean(user?.id && user?.business_id && user.status === 'active' && !user.must_change_password);
  const [state, setState] = useState({ unreadCount: 0, revision: 0, error: '' });
  const [mutating, setMutating] = useState(false);
  const generation = useRef(0);
  const mutation = useRef(false);
  const controller = useRef(null);
  const mounted = useRef(true);
  const refresh = useCallback(async () => {
    if (!enabled || document.hidden || mutation.current || controller.current) return;
    const request = new AbortController(); controller.current = request;
    const version = generation.current;
    try { const result = await apiFetch('/notifications/unread-count', { signal: request.signal }); if (!request.signal.aborted && generation.current === version && mounted.current) setState(current => ({ unreadCount: result.unread_count, revision: current.revision + 1, error: '' })); }
    catch (error) { if (!request.signal.aborted && mounted.current) setState(current => ({ ...current, error })); }
    finally { if (controller.current === request) controller.current = null; }
  }, [enabled]);
  useEffect(() => {
    mounted.current = true;
    const start = setTimeout(refresh, 0); const timer = setInterval(refresh, 30000);
    window.addEventListener('focus', refresh); window.addEventListener('online', refresh); document.addEventListener('visibilitychange', refresh);
    return () => { mounted.current = false; clearTimeout(start); clearInterval(timer); controller.current?.abort(); controller.current = null; window.removeEventListener('focus', refresh); window.removeEventListener('online', refresh); document.removeEventListener('visibilitychange', refresh); };
  }, [refresh]);
  const mutate = async (url, method) => {
    if (!enabled || mutation.current) return false;
    mutation.current = true; generation.current++; setMutating(true);
    try { const result = await apiFetch(url, { method }); if (mounted.current) setState(current => ({ unreadCount: result.unread_count, revision: current.revision + 1, error: '' })); return true; }
    catch (error) { if (mounted.current) setState(current => ({ ...current, error })); throw error; }
    finally { generation.current++; mutation.current = false; if (mounted.current) setMutating(false); }
  };
  return <NotificationContext.Provider value={{ ...state, enabled, mutating, refresh, markRead: id => mutate(`/notifications/${encodeURIComponent(id)}/read`, 'PATCH'), markUnread: id => mutate(`/notifications/${encodeURIComponent(id)}/unread`, 'PATCH'), markAllRead: () => mutate('/notifications/read-all', 'POST') }}>{children}</NotificationContext.Provider>;
}
