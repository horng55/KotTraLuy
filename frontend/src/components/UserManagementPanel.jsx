import { useEffect, useState } from 'react';
import { apiFetch } from '../lib/api';

const emptyForm = { name: '', username: '', password: '', role: 'staff', assigned_shift: 'A', telegram_enabled: false };

export default function UserManagementPanel() {
  const [users, setUsers] = useState([]);
  const [form, setForm] = useState(emptyForm);
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState('');

  const loadUsers = async () => {
    try {
      setUsers(await apiFetch('/users'));
    } catch (error) {
      setMessage(error.message);
    }
  };

  useEffect(() => {
    let active = true;
    apiFetch('/users')
      .then((data) => { if (active) setUsers(data); })
      .catch((error) => { if (active) setMessage(error.message); });

    return () => { active = false; };
  }, []);

  const createUser = async (event) => {
    event.preventDefault();
    setLoading(true);
    setMessage('');
    try {
      await apiFetch('/users', { method: 'POST', body: JSON.stringify(form) });
      setForm(emptyForm);
      setMessage('User created. Give the temporary password to the employee securely.');
      await loadUsers();
    } catch (error) {
      setMessage(error.message);
    } finally {
      setLoading(false);
    }
  };

  const updateUser = async (user, changes) => {
    setMessage('');
    try {
      await apiFetch(`/users/${user.id}`, { method: 'PATCH', body: JSON.stringify(changes) });
      await loadUsers();
    } catch (error) {
      setMessage(error.message);
    }
  };

  const toggleStatus = async (user) => {
    const nextStatus = user.status === 'active' ? 'inactive' : 'active';
    if (!window.confirm(`${nextStatus === 'inactive' ? 'Deactivate' : 'Activate'} ${user.name}?`)) return;
    try {
      await apiFetch(`/users/${user.id}/status`, {
        method: 'POST',
        body: JSON.stringify({ status: nextStatus }),
      });
      await loadUsers();
    } catch (error) {
      setMessage(error.message);
    }
  };

  const resetPassword = async (user) => {
    const password = window.prompt(`Enter a temporary password for ${user.name} (minimum 8 characters):`);
    if (!password) return;
    try {
      await apiFetch(`/users/${user.id}/reset-password`, {
        method: 'POST',
        body: JSON.stringify({ password }),
      });
      setMessage(`Password reset for ${user.name}. Their existing sessions were revoked.`);
      await loadUsers();
    } catch (error) {
      setMessage(error.message);
    }
  };

  return (
    <section className="panel mt-3 rounded-[10px] p-[18px] shadow-sm">
      <div className="mb-[18px]">
        <h2 className="font-['Space_Grotesk'] text-[15px] font-semibold text-[#152830]">User management</h2>
        <p className="mt-1 text-[11px] leading-[1.4] text-[#758a93]">Create individual accounts and assign staff to Shift A or B.</p>
      </div>

      <form onSubmit={createUser} className="grid gap-3 rounded-[9px] bg-[#f4f7f7] p-3 md:grid-cols-2 xl:grid-cols-5">
        <input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Full name" className="rounded-[6px] border border-[#d5e1e3] bg-white px-3 py-2 text-[12px] text-[#26434e]" />
        <input required value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} placeholder="Username" className="rounded-[6px] border border-[#d5e1e3] bg-white px-3 py-2 text-[12px] text-[#26434e]" />
        <input required minLength="8" type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} placeholder="Temporary password" className="rounded-[6px] border border-[#d5e1e3] bg-white px-3 py-2 text-[12px] text-[#26434e]" />
        <select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} className="rounded-[6px] border border-[#d5e1e3] bg-white px-3 py-2 text-[12px] text-[#26434e]">
          <option value="staff">Staff</option>
          <option value="owner">Owner</option>
        </select>
        {form.role === 'staff' ? (
          <>
            <select value={form.assigned_shift} onChange={(e) => setForm({ ...form, assigned_shift: e.target.value })} className="rounded-[6px] border border-[#d5e1e3] bg-white px-3 py-2 text-[12px] text-[#26434e]">
              <option value="A">Shift A</option>
              <option value="B">Shift B</option>
            </select>
            <label className="flex items-center gap-2 rounded-[6px] border border-[#d5e1e3] bg-white px-3 py-2 text-[12px] text-[#26434e]">
              <input type="checkbox" checked={form.telegram_enabled} onChange={(e) => setForm({ ...form, telegram_enabled: e.target.checked })} />
              Telegram sales access
            </label>
          </>
        ) : <div />}
        <button disabled={loading} className="rounded-[6px] bg-[#005e2a] px-4 py-2 text-[12px] font-bold text-white disabled:opacity-60 md:col-span-2 xl:col-span-5">
          {loading ? 'Creating...' : 'Create user'}
        </button>
      </form>

      {message && <p className="mt-3 text-[11px] text-[#47616c]">{message}</p>}

      <div className="mt-5 overflow-x-auto">
        <table className="w-full min-w-[760px] text-left text-[11px]">
          <thead className="border-b border-[#dfe8e9] text-[#6f838b]">
            <tr><th className="p-3 font-bold">Name</th><th className="p-3 font-bold">Username</th><th className="p-3 font-bold">Role</th><th className="p-3 font-bold">Shift</th><th className="p-3 font-bold">Telegram</th><th className="p-3 font-bold">Status</th><th className="p-3 font-bold">Actions</th></tr>
          </thead>
          <tbody>
            {users.map((user) => (
              <tr key={user.id} className="border-b border-slate-100">
                <td className="p-3 font-medium text-[#29434e]">{user.name}</td>
                <td className="p-3">{user.username || 'Email login'}</td>
                <td className="p-3">
                  <select value={user.role} onChange={(e) => updateUser(user, { role: e.target.value, assigned_shift: e.target.value === 'staff' ? (user.assigned_shift || 'A') : null })} className="rounded border border-[#d5e1e3] px-2 py-1 text-[11px] text-[#26434e]">
                    <option value="owner">Owner</option><option value="staff">Staff</option>
                  </select>
                </td>
                <td className="p-3">
                  {user.role === 'staff' ? (
                    <select value={user.assigned_shift || 'A'} onChange={(e) => updateUser(user, { assigned_shift: e.target.value })} className="rounded border border-[#d5e1e3] px-2 py-1 text-[11px] text-[#26434e]">
                      <option value="A">Shift A</option><option value="B">Shift B</option>
                    </select>
                  ) : 'Any'}
                </td>
                <td className="p-3">{user.role === 'staff' ? <label className="flex items-center gap-2 text-[10px]"><input type="checkbox" checked={Boolean(user.telegram_enabled)} onChange={(e) => updateUser(user, { telegram_enabled: e.target.checked })} /> Allow bot</label> : 'Owner access'}</td>
                <td className="p-3"><span className={`rounded-full px-2 py-1 text-[10px] font-semibold ${user.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'}`}>{user.status}</span></td>
                <td className="p-3">
                  <div className="flex gap-2">
                    <button type="button" onClick={() => resetPassword(user)} className="rounded border border-[#d5e1e3] px-2 py-1 text-[10px] text-[#47616c]">Reset password</button>
                    <button type="button" onClick={() => toggleStatus(user)} className="rounded border border-[#d5e1e3] px-2 py-1 text-[10px] text-[#47616c]">{user.status === 'active' ? 'Deactivate' : 'Activate'}</button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
