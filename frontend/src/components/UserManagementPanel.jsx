import { useEffect, useState } from 'react';
import { apiFetch } from '../lib/api';

const emptyForm = { name: '', username: '', password: '', role: 'staff', assigned_shift: 'A' };

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
    <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="mb-5">
        <h2 className="text-lg font-semibold">User management</h2>
        <p className="mt-1 text-sm text-slate-600">Create individual accounts and assign staff to Shift A or B.</p>
      </div>

      <form onSubmit={createUser} className="grid gap-3 rounded-xl bg-slate-50 p-4 md:grid-cols-2 xl:grid-cols-5">
        <input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Full name" className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" />
        <input required value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} placeholder="Username" className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" />
        <input required minLength="8" type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} placeholder="Temporary password" className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" />
        <select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
          <option value="staff">Staff</option>
          <option value="owner">Owner</option>
        </select>
        {form.role === 'staff' ? (
          <select value={form.assigned_shift} onChange={(e) => setForm({ ...form, assigned_shift: e.target.value })} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
            <option value="A">Shift A</option>
            <option value="B">Shift B</option>
          </select>
        ) : <div />}
        <button disabled={loading} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-60 md:col-span-2 xl:col-span-5">
          {loading ? 'Creating...' : 'Create user'}
        </button>
      </form>

      {message && <p className="mt-3 text-sm text-slate-700">{message}</p>}

      <div className="mt-5 overflow-x-auto">
        <table className="w-full min-w-[760px] text-left text-sm">
          <thead className="border-b border-slate-200 text-slate-500">
            <tr><th className="p-3">Name</th><th className="p-3">Username</th><th className="p-3">Role</th><th className="p-3">Shift</th><th className="p-3">Status</th><th className="p-3">Actions</th></tr>
          </thead>
          <tbody>
            {users.map((user) => (
              <tr key={user.id} className="border-b border-slate-100">
                <td className="p-3 font-medium">{user.name}</td>
                <td className="p-3">{user.username || 'Email login'}</td>
                <td className="p-3">
                  <select value={user.role} onChange={(e) => updateUser(user, { role: e.target.value, assigned_shift: e.target.value === 'staff' ? (user.assigned_shift || 'A') : null })} className="rounded border border-slate-300 px-2 py-1">
                    <option value="owner">Owner</option><option value="staff">Staff</option>
                  </select>
                </td>
                <td className="p-3">
                  {user.role === 'staff' ? (
                    <select value={user.assigned_shift || 'A'} onChange={(e) => updateUser(user, { assigned_shift: e.target.value })} className="rounded border border-slate-300 px-2 py-1">
                      <option value="A">Shift A</option><option value="B">Shift B</option>
                    </select>
                  ) : 'Any'}
                </td>
                <td className="p-3"><span className={`rounded-full px-2 py-1 text-xs font-semibold ${user.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'}`}>{user.status}</span></td>
                <td className="p-3">
                  <div className="flex gap-2">
                    <button type="button" onClick={() => resetPassword(user)} className="rounded border border-slate-300 px-2 py-1">Reset password</button>
                    <button type="button" onClick={() => toggleStatus(user)} className="rounded border border-slate-300 px-2 py-1">{user.status === 'active' ? 'Deactivate' : 'Activate'}</button>
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
