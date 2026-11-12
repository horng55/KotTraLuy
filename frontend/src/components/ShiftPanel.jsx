import { messageText } from '../lib/i18n';
import { t as tr } from '../lib/i18n';
import { useEffect, useState } from 'react';
import { apiFetch } from '../lib/api';

export default function ShiftPanel({ user }) {
  const [shifts, setShifts] = useState([]);
  const [message, setMessage] = useState('');
  const [loading, setLoading] = useState(false);
  const [ownerShift, setOwnerShift] = useState('A');

  const loadShifts = async () => {
    try { setShifts(await apiFetch('/shifts')); } catch (error) { setMessage(error); }
  };

  useEffect(() => {
    let active = true;
    apiFetch('/shifts')
      .then((data) => { if (active) setShifts(data); })
      .catch((error) => { if (active) setMessage(error); });

    return () => { active = false; };
  }, []);

  if (user?.role !== 'staff') return null;

  const openShift = shifts.find((shift) => shift.user_id === user.id && shift.status === 'open');
  const shiftType = user.role === 'staff' ? user.assigned_shift : ownerShift;

  const startShift = async () => {
    setLoading(true); setMessage('');
    try {
      await apiFetch('/shifts', { method: 'POST', body: JSON.stringify({ shift_type: shiftType, opening_cash: 0 }) });
      setMessage(`Shift ${shiftType} started.`); await loadShifts();
    } catch (error) { setMessage(error); } finally { setLoading(false); }
  };

  const closeShift = async () => {
    setLoading(true); setMessage('');
    try {
      await apiFetch(`/shifts/${openShift.id}/close`, { method: 'POST', body: JSON.stringify({}) });
      setMessage('Shift closed.'); await loadShifts();
    } catch (error) { setMessage(error); } finally { setLoading(false); }
  };

  return (
    <section className="mt-6 rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-5 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div><h2 className="text-lg font-semibold">{tr("team.my_shift")}</h2><p className="text-sm text-[var(--text-secondary)]">{tr("sales.sales_from_telegram_are_attached_to_your_open_shift")}</p></div>
        {openShift ? <span className="rounded-full bg-[var(--surface-accent)] px-3 py-1 text-sm font-semibold text-[var(--accent-foreground)]">{tr("team.shift_open",{shift:openShift.shift_type})}</span> : <span className="rounded-full bg-[var(--surface-subtle)] px-3 py-1 text-sm text-[var(--text-secondary)]">{tr("team.no_open_shift")}</span>}
      </div>
      <div className="mt-4 flex items-center gap-3">
        {!openShift && user.role === 'owner' && <select value={ownerShift} onChange={(e) => setOwnerShift(e.target.value)} className="rounded-lg border border-[var(--border)] px-3 py-2"><option value="A">{tr("team.shift_a")}</option><option value="B">{tr("team.shift_b")}</option></select>}
        <button type="button" disabled={loading} onClick={openShift ? closeShift : startShift} className={`rounded-lg px-4 py-2 text-sm font-medium text-[var(--on-primary)] disabled:opacity-60 ${openShift ? 'bg-[var(--danger-solid)]' : 'bg-[var(--brand-primary)]'}`}>{loading ? tr("common.please_wait") : openShift ? tr("team.close_shift") : tr("team.start_shift",{shift:shiftType})}</button>
      </div>
      {message && <p className="mt-3 text-sm text-[var(--text-secondary)]">{messageText(message)}</p>}
    </section>
  );
}
