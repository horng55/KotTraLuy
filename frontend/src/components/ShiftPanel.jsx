import { useEffect, useState } from 'react';
import { apiFetch } from '../lib/api';

export default function ShiftPanel({ user }) {
  const [shifts, setShifts] = useState([]);
  const [message, setMessage] = useState('');
  const [loading, setLoading] = useState(false);
  const [ownerShift, setOwnerShift] = useState('A');

  const loadShifts = async () => {
    try { setShifts(await apiFetch('/shifts')); } catch (error) { setMessage(error.message); }
  };

  useEffect(() => {
    let active = true;
    apiFetch('/shifts')
      .then((data) => { if (active) setShifts(data); })
      .catch((error) => { if (active) setMessage(error.message); });

    return () => { active = false; };
  }, []);

  const openShift = shifts.find((shift) => shift.user_id === user.id && shift.status === 'open');
  const shiftType = user.role === 'staff' ? user.assigned_shift : ownerShift;

  const startShift = async () => {
    setLoading(true); setMessage('');
    try {
      await apiFetch('/shifts', { method: 'POST', body: JSON.stringify({ shift_type: shiftType, opening_cash: 0 }) });
      setMessage(`Shift ${shiftType} started.`); await loadShifts();
    } catch (error) { setMessage(error.message); } finally { setLoading(false); }
  };

  const closeShift = async () => {
    setLoading(true); setMessage('');
    try {
      await apiFetch(`/shifts/${openShift.id}/close`, { method: 'POST', body: JSON.stringify({}) });
      setMessage('Shift closed.'); await loadShifts();
    } catch (error) { setMessage(error.message); } finally { setLoading(false); }
  };

  return (
    <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div><h2 className="text-lg font-semibold">My shift</h2><p className="text-sm text-slate-600">Transactions are automatically attached to your open shift.</p></div>
        {openShift ? <span className="rounded-full bg-emerald-100 px-3 py-1 text-sm font-semibold text-emerald-700">Shift {openShift.shift_type} open</span> : <span className="rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-600">No open shift</span>}
      </div>
      <div className="mt-4 flex items-center gap-3">
        {!openShift && user.role === 'owner' && <select value={ownerShift} onChange={(e) => setOwnerShift(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2"><option value="A">Shift A</option><option value="B">Shift B</option></select>}
        <button type="button" disabled={loading} onClick={openShift ? closeShift : startShift} className={`rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-60 ${openShift ? 'bg-rose-600' : 'bg-emerald-600'}`}>{loading ? 'Please wait...' : openShift ? 'Close shift' : `Start Shift ${shiftType}`}</button>
      </div>
      {message && <p className="mt-3 text-sm text-slate-700">{message}</p>}
    </section>
  );
}
