import { t as tr } from '../lib/i18n';
import { ChevronRight, ShieldCheck } from 'lucide-react';

export function FinancialPageHeader({ title, description, breadcrumb = tr("settings.settings_2"), breadcrumbTitle = title, children }) {
  return <><div className="fp-breadcrumb">{breadcrumb}<ChevronRight size={12} /><span>{breadcrumbTitle}</span></div><header className="fp-header"><div><span className="fp-eyebrow">{tr("settings.kottraluy_business_workspace")}</span><h1>{title}</h1><p>{description}</p></div>{children && <div className="fp-header-actions">{children}</div>}</header></>;
}
export function FinancialNotice({ title, children }) {
  return <aside className="fp-notice"><ShieldCheck size={20} /><div><strong>{title}</strong><p>{children}</p></div></aside>;
}
