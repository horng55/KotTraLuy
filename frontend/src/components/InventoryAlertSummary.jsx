import { t as tr } from '../lib/i18n';
import { useEffect, useState } from 'react';
import { CheckCircle2, PackagePlus, TriangleAlert } from 'lucide-react';
import { apiFetch } from '../lib/api';

const labels = { expired: "common.expired", expires_today: "common.expires_today", expiring_soon: "common.expiring_soon", expiry_unknown: "common.expiry_unknown", out_of_stock: "stock.out_of_stock_2", low_stock: "stock.low_stock_2" };

export default function InventoryAlertSummary({ compact = false, products = [], onViewAll, onAddProduct }) {
  const [alerts, setAlerts] = useState([]);
  const [loaded, setLoaded] = useState(false);
  useEffect(() => { let active = true; apiFetch('/inventory-alerts').then((data) => { if (active) setAlerts(data); }).catch(() => {}).finally(() => { if (active) setLoaded(true); }); return () => { active = false; }; }, []);
  const visible = compact ? alerts.slice(0, 3) : alerts.slice(0, 8);
  const hasProducts = products.length > 0;
  const emptyTitle = hasProducts ? tr("stock.no_stock_alerts") : tr("stock.no_products_yet");
  const emptyCopy = hasProducts ? tr("stock.your_current_stock_and_expiry_conditions_look_clear") : tr("stock.add_a_product_to_start_tracking_stock_levels_and_alerts");
  return <section className={`panel full-panel inventory-alert-summary ${compact ? 'compact' : ''}`}><div className="panel-title"><div><h2>{tr("stock.stock_alerts")}</h2><p>{compact ? tr("stock.low_stock_and_expiry_conditions") : tr("stock.stock_and_expiry_conditions_that_need_attention")}</p></div></div>{!compact && <div className="alert-card-grid">{[['low_stock', tr("stock.low_stock_2")], ['out_of_stock', tr("stock.out_of_stock_2")], ['expiring_soon', tr("common.expiring_soon")]].map(([condition, title]) => <div className="alert-card" key={condition}><span>{title}</span><strong>{alerts.filter((alert) => alert.condition === condition).length}</strong><small>{tr("common.active_alerts")}</small></div>)}</div>}{visible.length ? visible.map((alert) => <div className="alert-row" key={alert.id}><TriangleAlert size={16} /><span>{alert.product?.name || tr("stock.product")} · {tr(labels[alert.condition] || 'common.unknown')}</span><strong>{alert.stock_batch?.batch_code || ''}{alert.stock_batch?.expiry_date ? ` · ${alert.stock_batch.expiry_date}` : ''}</strong></div>) : loaded && <div className={`empty-state dashboard-empty-state ${hasProducts ? 'success-state' : ''}`}><span className="empty-state-icon">{hasProducts ? <CheckCircle2 size={21} /> : <PackagePlus size={21} />}</span><strong>{emptyTitle}</strong><p>{emptyCopy}</p>{!hasProducts && <button className="button secondary small" onClick={onAddProduct}><PackagePlus size={14} />{tr("stock.add_product_3")}</button>}</div>}{compact && visible.length > 0 && <button className="text-button view-all-button" onClick={onViewAll}>{tr("common.view_all_2")}</button>}</section>;
}
