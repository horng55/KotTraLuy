import { t, text, formatDate } from './i18n.js';
const supported = new Set(["sale.created","sale.updated","sale.deleted","sale.unpaid","payment.received","expense.created","inventory.low_stock","inventory.out_of_stock","inventory.expiring_soon","inventory.expires_today","inventory.expired","team.created","team.activated","team.deactivated","telegram.connected","telegram.disconnected","pack.created","business.incomplete","security.password_changed","security.password_reset"]);
export function notificationText(notification, language = 'en') {
  const khmer = language === 'km';
  const event = supported.has(notification.type) ? notification.type : 'fallback';
  const params = { actor: text('Staff',language), description: text('Business expense',language), unit: text('Units',language), reference:'', ...(notification.params || {}) };
  const locale = khmer ? 'km-KH' : 'en-US';
  if (params.quantity != null) params.quantity = new Intl.NumberFormat(locale).format(Number(params.quantity));
  if (params.expiry_date && /^\d{4}-\d{2}-\d{2}$/.test(params.expiry_date)) {
    // A batch expiry is a calendar date, independent of the viewer's timezone.
    params.expiry_date = formatDate(params.expiry_date,language);
  }
  if (params.amount != null) { try { params.amount = new Intl.NumberFormat(khmer ? 'km-KH' : 'en-US', { style: 'currency', currency: params.currency || 'USD' }).format(Number(params.amount)); } catch { params.amount = String(params.amount); } }
  if (Array.isArray(params.missing_fields)) { const fields = {name:'common.business_name',activity:'common.business_activity',province:'common.province'}; params.missing_fields=params.missing_fields.map(field=>t(fields[field] || 'common.not_provided',{},language)).join(' / '); }
  return { title:t(`notifications.${event}.title`,params,language), message:t(`notifications.${event}.message`,params,language) };
}
export function relativeTime(value, language) {
  const date = new Date(value); if (!Number.isFinite(date.getTime())) return '';
  const seconds = Math.round((date.getTime() - Date.now()) / 1000);
  const [unit, divisor] = Math.abs(seconds) < 60 ? ['second', 1] : Math.abs(seconds) < 3600 ? ['minute', 60] : Math.abs(seconds) < 86400 ? ['hour', 3600] : ['day', 86400];
  const amount = Math.abs(Math.round(seconds / divisor));
  if (!amount) return t('notifications.relative.now',{},language);
  return t(`notifications.relative.${unit}.${seconds < 0 ? 'past' : 'future'}`,{count:amount},language);
}
export const notificationCategories = ['sales','inventory','expenses','team','financing','system'];
