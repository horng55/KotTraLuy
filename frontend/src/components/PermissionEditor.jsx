import { useEffect, useState } from 'react';
import { apiFetch } from '../lib/api';
import { messageText, t } from '../lib/i18n';
import { AppDialog, FormError, PrimaryButton, SecondaryButton } from './FormUI';

const labels = {
  'dashboard.operational': 'common.dashboard', 'sales.read.all': 'rbac.sales_all', 'sales.read.own': 'rbac.sales_own',
  'sales.create': 'sales.new_sale', 'sales.modify': 'sales.edit_transaction', 'inventory.read': 'stock.view_stock',
  'inventory.manage': 'stock.manage_stock', 'inventory.dispose': 'expiration.dispose_stock', 'inventory.settings': 'expiration.settings_title',
  'reports.operational': 'sales.sales_report', 'reports.financial': 'reports.financial_reports', 'purchases.manage': 'stock.stock_in',
  'expenses.manage': 'sales.expenses', 'waste.manage': 'rbac.waste', 'shifts.manage': 'rbac.shifts_all', 'shifts.own': 'team.assigned_work_shift',
  'team.manage': 'team.team_management', 'telegram.settings': 'telegram.inventory_notifications', 'financing': 'financing.financing_preparation',
};

export default function PermissionEditor({ user, close }) {
  const [supported, setSupported] = useState([]);
  const [selected, setSelected] = useState(user.permissions || []);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  useEffect(() => { let active = true; apiFetch('/permissions').then(value => { if (active) setSupported(value.permissions); }).catch(failure => { if (active) setError(failure); }); return () => { active = false; }; }, []);
  const save = async permissions => {
    setBusy(true); setError('');
    try { await apiFetch(`/users/${user.id}/permissions`, { method: 'PUT', body: JSON.stringify({ permissions }) }); close(); }
    catch (failure) { setError(failure); }
    finally { setBusy(false); }
  };
  return <AppDialog title={`${t('rbac.permissions')}: ${user.name}`} onClose={close} footer={<><SecondaryButton disabled={busy} onClick={() => save(null)}>{t('rbac.restore_defaults')}</SecondaryButton><PrimaryButton disabled={busy || !supported.length} onClick={() => save(selected.filter(permission => supported.includes(permission)))}>{t('common.save_changes')}</PrimaryButton></>}>
    <p>{t('rbac.workspace_permissions_description')}</p>
    <div className="ig-setting-grid">{supported.filter(permission => ['owner', 'manager'].includes(user.access_role || user.job_role || user.role) || !['team.manage', 'telegram.settings', 'financing'].includes(permission)).map(permission => <label key={permission}><input type="checkbox" checked={selected.includes(permission)} onChange={event => setSelected(current => event.target.checked ? [...current, permission] : current.filter(value => value !== permission))} />{t(labels[permission])}</label>)}</div>
    {error && <FormError>{messageText(error)}</FormError>}
  </AppDialog>;
}
