import { messageText } from '../lib/i18n';
import { t as tr } from '../lib/i18n';
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
    } catch (error) { setMessage(error); }
  };

  return (
    <section className={`${required ? '' : 'mt-6'} rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-5 shadow-sm`}>
      <h2 className="text-lg font-semibold">{required ? tr("auth.change_your_temporary_password") : tr("auth.change_password")}</h2>
      {required && <p className="mt-1 text-sm text-[var(--warning-foreground)]">{tr("auth.you_must_set_a_private_password_before_using_the_workspace")}</p>}
      <form onSubmit={submit} className="mt-4 grid gap-3 md:grid-cols-3">
        <input required type="password" placeholder={tr("auth.current_password")} value={form.current_password} onChange={(e) => setForm({ ...form, current_password: e.target.value })} className="rounded-lg border border-[var(--border)] px-3 py-2" />
        <input required minLength="8" type="password" placeholder={tr("auth.new_password")} value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} className="rounded-lg border border-[var(--border)] px-3 py-2" />
        <input required minLength="8" type="password" placeholder={tr("auth.confirm_new_password")} value={form.password_confirmation} onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} className="rounded-lg border border-[var(--border)] px-3 py-2" />
        <button className="rounded-lg bg-[var(--surface-subtle)] px-4 py-2 font-medium text-[var(--on-primary)] md:col-span-3">{tr("auth.change_password")}</button>
      </form>
      {message && <p className="mt-3 text-sm text-[var(--text-secondary)]">{messageText(message)}</p>}
    </section>
  );
}
