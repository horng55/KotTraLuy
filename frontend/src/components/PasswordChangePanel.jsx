import { useState } from 'react';
import { apiFetch } from '../lib/api';

export default function PasswordChangePanel({ required = false, onChanged }) {
  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [message, setMessage] = useState('');

  const submit = async (event) => {
    event.preventDefault(); setMessage('');
    try {
      await apiFetch('/change-password', { method: 'POST', body: JSON.stringify(form) });
      setMessage('Password changed successfully.'); onChanged?.();
    } catch (error) { setMessage(error.message); }
  };

  return (
    <section className={`${required ? '' : 'mt-6'} rounded-2xl border border-amber-200 bg-white p-5 shadow-sm`}>
      <h2 className="text-lg font-semibold">{required ? 'Change your temporary password' : 'Change password'}</h2>
      {required && <p className="mt-1 text-sm text-amber-700">You must set a private password before using the workspace.</p>}
      <form onSubmit={submit} className="mt-4 grid gap-3 md:grid-cols-3">
        <input required type="password" placeholder="Current password" value={form.current_password} onChange={(e) => setForm({ ...form, current_password: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2" />
        <input required minLength="8" type="password" placeholder="New password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2" />
        <input required minLength="8" type="password" placeholder="Confirm new password" value={form.password_confirmation} onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2" />
        <button className="rounded-lg bg-slate-900 px-4 py-2 font-medium text-white md:col-span-3">Change password</button>
      </form>
      {message && <p className="mt-3 text-sm text-slate-700">{message}</p>}
    </section>
  );
}
