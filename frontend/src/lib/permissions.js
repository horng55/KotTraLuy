export const roleOf = user => user?.access_role || (user?.role === 'owner' ? 'owner' : user?.job_role === 'manager' ? 'manager' : user?.role === 'member' ? 'member' : 'staff');
export const can = (user, permission) => Boolean(user && (roleOf(user) === 'owner' || user.permissions?.includes('*') || user.permissions?.includes(permission)));
export const canAny = (user, permissions) => permissions.some(permission => can(user, permission));
export const canUseHub = user => ['owner', 'manager'].includes(roleOf(user)) && canAny(user, ['team.manage', 'telegram.settings', 'financing']);
export const pagePermissions = {
  overview: ['dashboard.personal', 'dashboard.operational'], records: ['sales.read.own', 'sales.read.all', 'sales.create', 'expenses.manage'],
  inventory: ['inventory.read'], alerts: ['inventory.read'], reports: ['reports.financial', 'reports.operational'],
  business: ['financing'], prepare: ['financing'], options: ['financing'], packs: ['financing'], documents: ['financing'],
  telegram: ['telegram.link'], profile: ['dashboard.personal'], notifications: ['dashboard.personal'], team: ['team.manage'],
};
const hubPages = ['business', 'prepare', 'options', 'packs', 'documents', 'team'];
export function canAccessPage(user, page) {
  if (page === 'more') return canUseHub(user);
  if (hubPages.includes(page) && !canUseHub(user)) return false;
  return Boolean(pagePermissions[page] && canAny(user, pagePermissions[page]));
}
export const navigation = [
  { page: 'overview', label: 'common.dashboard', icon: 'dashboard', permissions: pagePermissions.overview },
  { page: 'records', label: 'sales.sales_2', icon: 'sales', permissions: pagePermissions.records },
  { page: 'inventory', label: 'stock.stock', icon: 'stock', permissions: pagePermissions.inventory },
  { page: 'reports', label: 'reports.reports', icon: 'reports', permissions: pagePermissions.reports },
  { page: 'more', label: 'common.business_hub', icon: 'hub', permissions: ['team.manage', 'telegram.settings', 'financing'] },
];
export const visibleNavigation = user => navigation.filter(item => canAccessPage(user, item.page));
export const roleTranslation = user => ({ owner: 'common.owner', manager: 'team.manager', staff: 'team.staff', member: 'team.member' }[roleOf(user)] || 'team.member');
