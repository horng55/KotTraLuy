import { getLocale } from '../lib/i18n';
import { t as tr } from '../lib/i18n';
import { useState } from 'react';
import { TrendingUp } from 'lucide-react';

export default function SalesOverview({ sales, currency, endDate }) {
  const [days, setDays] = useState(7);
  const points = Array.from({ length: days }, (_, index) => {
    const date = new Date(`${endDate}T12:00:00`); date.setDate(date.getDate() - days + index + 1);
    const key = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    const rows = sales.filter(sale => String(sale.sold_at).slice(0, 10) === key && (sale.currency || currency) === currency);
    return { date: key, value: rows.reduce((total, sale) => total + Number(sale.total_amount || 0), 0), count: rows.length };
  });
  const total = points.reduce((sum, point) => sum + point.value, 0);
  const max = Math.max(...points.map(point => point.value), 1);
  const coords = points.map((point, index) => `${65 + index / (days - 1) * 885},${185 - point.value / max * 145}`);
  const money = value => new Intl.NumberFormat(getLocale(), { style: 'currency', currency, maximumFractionDigits: 0 }).format(value);
  return <section className="bd-card bd-sales"><div className="bd-section-heading"><div><h2>{tr("sales.sales_overview")}</h2><p>{tr("common.a_daily_view_of_your_business_momentum")}</p></div><div className="bd-chart-filters" aria-label={tr("sales.sales_period")}>{[7, 30, 90].map(period => <button key={period} aria-pressed={period === days} onClick={() => setDays(period)}>{period}{tr("common.days")}</button>)}</div></div><div className="bd-chart-summary"><strong>{money(total)}</strong><span>{tr("sales.chart_summary",{days,currency})}</span></div>{points.some(point => point.count) ? <div className="bd-chart"><svg viewBox="0 0 1000 225" role="img" aria-label={tr("sales.chart_description",{days,amount:money(total)})}><defs><linearGradient id="sales-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="var(--chart-line)" stopOpacity=".16" /><stop offset="100%" stopColor="var(--chart-line)" stopOpacity="0" /></linearGradient></defs>{[0, .5, 1].map(ratio => <g key={ratio}><line x1="65" x2="950" y1={185 - ratio * 145} y2={185 - ratio * 145} stroke="var(--text-secondary)" strokeDasharray="4 5" /><text x="0" y={189 - ratio * 145} fill="var(--text-secondary)" fontSize="11">{money(max * ratio)}</text></g>)}<path d={`M${coords.join(' L')} L950,185 L65,185 Z`} fill="url(#sales-fill)" /><polyline points={coords.join(' ')} fill="none" stroke="var(--chart-line)" strokeWidth="3" strokeLinejoin="round" strokeLinecap="round" />{points.map((point, index) => <circle key={point.date} cx={65 + index / (days - 1) * 885} cy={185 - point.value / max * 145} r="3" fill="var(--chart-line)"><title>{point.date}: {money(point.value)}</title></circle>)}{[0, Math.floor((days - 1) / 2), days - 1].map(index => <text key={index} x={65 + index / (days - 1) * 885} y="218" textAnchor={index === 0 ? 'start' : index === days - 1 ? 'end' : 'middle'} fill="var(--text-secondary)" fontSize="12">{points[index].date.slice(5)}</text>)}</svg></div> : <div className="bd-chart-empty"><TrendingUp size={28} /><strong>{tr("sales.your_sales_story_is_just_beginning")}</strong><p>{tr("sales.no_sales_recorded_in_this_period_your_daily_performance_will_appear_here")}</p></div>}</section>;
}
