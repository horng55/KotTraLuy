import { t } from '../lib/i18n';
import { FinancialPageHeader } from './FinancialPageUI';
import NotificationsPanel from './NotificationsPanel';

export default function NotificationsPage({ language, onNavigate }) {
  return <section className="financial-page notifications-page"><FinancialPageHeader title={t('common.notifications')} breadcrumb={t('settings.workspace')} description={t('settings.stay_up_to_date_with_business_activity_and_workspace_updates')} /><NotificationsPanel fullPage language={language} onNavigate={onNavigate} /></section>;
}
