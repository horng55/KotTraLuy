import { t as tr } from '../lib/i18n';
import { useEffect, useState } from 'react';
import { ArrowUpRight, Package } from 'lucide-react';
import { apiFetch } from '../lib/api';

export default function InventoryOverview({ products, loaded, reloadKey, onNavigate }) {
  const [alerts, setAlerts] = useState(null);
  useEffect(() => { let active = true; apiFetch('/inventory-alerts').then(data => { if (active) setAlerts(data); }).catch(() => { if (active) setAlerts(null); }); return () => { active = false; }; }, [reloadKey]);
  const total = products.length;
  const low = products.filter(product => Number(product.stock_quantity) > 0 && Number(product.stock_quantity) <= Number(product.minimum_stock)).length;
  const out = products.filter(product => Number(product.stock_quantity) <= 0).length;
  const expiring = alerts == null ? null : new Set(alerts.filter(alert => ['expiring_soon', 'expires_today'].includes(alert.condition)).map(alert => alert.product_id || alert.product?.id)).size;
  return <section className="bd-card bd-inventory"><div className="bd-section-heading"><div><h2>{tr("stock.inventory_overview")}</h2><p>{tr("stock.your_stock_health_at_a_glance")}</p></div><Package size={19} /></div><div className="bd-stock-total"><strong>{loaded ? total : '—'}</strong><span>{tr("stock.total_products_2")}</span></div><div className="bd-inventory-metrics">{[[tr("stock.low_stock_2"), low, 'warning'], [tr("stock.out_of_stock_2"), out, 'danger'], [tr("stock.expiring_products"), expiring, 'expiry']].map(([label, count, tone]) => <div className={`bd-stock-metric ${tone}`} key={label}><div><span>{label}</span><strong>{loaded && count != null ? count : '—'}</strong></div>{loaded && count != null && <progress value={count} max={Math.max(total, count, 1)} aria-label={label} />}</div>)}</div><button className="bd-stock-link" onClick={() => onNavigate('inventory')}>{tr("stock.view_stock")}<ArrowUpRight size={16} /></button><button className="bd-link" onClick={() => onNavigate('alerts')}>{tr("stock.review_stock_and_expiry_alerts")}</button></section>;
}
