import { getLocale } from './lib/i18n';
import { messageText } from './lib/i18n';
import { t as tr } from './lib/i18n';
import { useEffect, useState, useSyncExternalStore } from 'react';
import AppHeader from './components/AppHeader';
import NotificationProvider from './components/NotificationProvider';
import NotificationsPage from './components/NotificationsPage';
import { ArrowDown, ArrowUp, BarChart3, BookOpen, Boxes, Check, ChevronLeft, ChevronRight, CircleAlert, LayoutDashboard, Package, Plus, ShieldCheck, ShoppingCart, Sparkles, UserRound, WalletCards, X } from 'lucide-react';
import { apiFetch, clearToken, saveToken } from './lib/api';
import PasswordChangePanel from './components/PasswordChangePanel';
import TelegramAccessPanel from './components/TelegramAccessPanel';
import DocumentsPage from './components/DocumentsPage';
import PreparationPacksPage from './components/PreparationPacksPage';
import InventoryAlertsPage from './components/InventoryAlertsPage';
import InventoryLivePage from './components/InventoryLivePage';
import ProductDialog from './components/ProductDialog';
import { ActivityDialog } from './components/QuickActionsPanel';
import ReportsLivePage from './components/ReportsLivePage';
import BusinessProfilePage from './components/BusinessProfilePage';
import FinanceOptionsPage from './components/FinanceOptionsPage';
import HomePage from './components/HomePage';
import RecordsHub from './components/RecordsHub';
import MorePage from './components/MorePage';
import LoginScreen from './components/LoginScreen';
import InvitationAcceptPage from './components/InvitationAcceptPage';
import { getLanguage, setLanguage, subscribeLanguage, text as uiLabel, domainLabel, validateControl, clearControlValidation } from './lib/i18n';
import { getTelegramWebApp, isTelegramMiniApp, setTelegramBackButton, syncTelegramEnvironment, telegramHaptic } from './lib/telegram';
import { greetingTimeZone, useGreeting } from './lib/useGreeting';
import { Select } from './components/FormUI';
import './brand.css';
import BrandLogo from './components/BrandLogo';
import telegramBotIcon from './assets/icons8-bot-50.svg';
import inventoryIcon from './assets/icons8-open-box-50.svg';
import prepareIcon from './assets/icons8-robot-50.svg';
import businessIcon from './assets/icons8-business-profile-50.svg';
import checklistIcon from './assets/icons8-test-50.svg';
import recordsIcon from './assets/icons8-health-graph-50.svg';
import overviewIcon from './assets/icons8-overview-50.svg';
import financeOptionsIcon from './assets/icons8-card-payment-50.svg';
import loginLogo from './assets/kottraluy-login-logo.png';

/* The legacy Inventory and Reports components remain available for backward-compatible imports. */
/* eslint-disable no-unused-vars */

const authDefaults = { name: '', email: '', password: '', business_name: '', role: 'owner' };
const SIDEBAR_COLLAPSED_KEY = 'kottraluy_sidebar_collapsed';
const profileDefaults = { requested_amount: '', currency: 'USD', purpose: 'Working capital', requested_timing: 'Within 3 months', business_registration_status: 'unknown', bank_statement_status: 'missing', collateral_status: 'unknown' };
const ownerNavItems = [
  ['overview', "common.dashboard", BarChart3], ['records', "sales.sales_2", ShoppingCart], ['inventory', "stock.stock", Package], ['reports', "reports.reports", BarChart3], ['more', "settings.settings_2", LayoutDashboard],
];
const navItemsFor = user => user?.access_role === 'staff' || (user?.role === 'staff' && user?.job_role !== 'manager')
  ? [['overview', "common.dashboard", BarChart3], ['records', "sales.sales_2", ShoppingCart], ['inventory', "stock.stock", Package], ['profile', "common.profile", UserRound]]
  : user?.access_role === 'manager' || user?.job_role === 'manager'
    ? ownerNavItems.filter(([key]) => key !== 'more')
    : ownerNavItems;
const secondaryPages = ['alerts', 'telegram', 'business', 'prepare', 'options', 'packs', 'documents', 'notifications'];
const mainTabFor = (key) => {
  if (['prepare', 'options', 'packs', 'documents', 'business', 'telegram', 'alerts'].includes(key)) return 'more';
  return key;
};
const money = (value, currency = 'USD') => new Intl.NumberFormat(getLocale(), { style: 'currency', currency, maximumFractionDigits: 2 }).format(Number(value || 0));
const legacyTitles = { overview: "common.home", inventory: "stock.stock", records: "common.records", more: "common.more", alerts: "stock.inventory_alerts", reports: "reports.financial_reports", prepare: "financing.financing_preparation_2", options: "financing.finance_options_3", packs: "financing.preparation_packs_2", documents: "documents.document_checklist_3", business: "common.business_profile_2", telegram: "telegram.telegram_bot_access_2" };
const titleFor = (key) => ({ overview: "common.dashboard", records: "sales.sales_2", more: "settings.settings_2", notifications: "common.notifications" }[key] || legacyTitles[key] || "common.dashboard");

// Inventory deep link from a Telegram alert: ?inventory_product=ID[&inventory_batch=ID]
function readInventoryLink() {
  const params = new URLSearchParams(window.location.search);
  const productId = Number(params.get('inventory_product'));
  if (!Number.isInteger(productId) || productId <= 0) return null;
  const batchId = Number(params.get('inventory_batch'));
  return { entity_type: 'product', entity_id: productId, ...(Number.isInteger(batchId) && batchId > 0 ? { batch_id: batchId } : {}) };
}

export default function App() {
  const [token, setToken] = useState(sessionStorage.getItem('biztrack_token') || '');
  const [currentUser, setCurrentUser] = useState(null);
  const [notificationTarget, setNotificationTarget] = useState(() => readInventoryLink());
  const language = useSyncExternalStore(subscribeLanguage, getLanguage);
  const [authMode, setAuthMode] = useState('login'); const [authForm, setAuthForm] = useState(authDefaults); const [active, setActive] = useState(() => readInventoryLink() ? 'inventory' : window.history.state?.kottraluyPage || 'overview'); const [mobileNav, setMobileNav] = useState(false);
  const [sidebarCollapsed, setSidebarCollapsed] = useState(() => { try { return localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === 'true'; } catch { return false; } });
  const [dashboard, setDashboard] = useState(null); const [finance, setFinance] = useState(null); const [products, setProducts] = useState([]); const [packs, setPacks] = useState([]); const [profile, setProfile] = useState(profileDefaults); const [business, setBusiness] = useState({});
  const [financingError, setFinancingError] = useState('');
  const [records, setRecords] = useState({ sales: [], expenses: [] }); const [loading, setLoading] = useState(false); const [error, setError] = useState(''); const [showRecord, setShowRecord] = useState(false); const [showProduct, setShowProduct] = useState(false); const [editingProduct, setEditingProduct] = useState(null);
  const [telegramMiniApp, setTelegramMiniApp] = useState(() => isTelegramMiniApp(getTelegramWebApp()));

  useEffect(() => {
    const expired = () => { setToken(''); setCurrentUser(null); setDashboard(null); setFinance(null); setBusiness({}); setRecords({ sales: [], expenses: [] }); setError('Please sign in again. Your session has expired.'); };
    const passwordRequired = () => setCurrentUser(current => current ? { ...current, must_change_password: true } : current);
    window.addEventListener('kottraluy:session-expired', expired);
    window.addEventListener('kottraluy:password-required', passwordRequired);
    return () => { window.removeEventListener('kottraluy:session-expired', expired); window.removeEventListener('kottraluy:password-required', passwordRequired); };
  }, []);


  // Authenticate automatically when Telegram opens this page as a Mini App.
  // Telegram.WebApp.initData is verified by the backend before a session token
  // is issued, so the regular website login remains unchanged.
  useEffect(() => {
    const webApp = window.Telegram?.WebApp;
    if (!webApp || sessionStorage.getItem('biztrack_token')) return undefined;

    webApp.ready();
    webApp.expand?.();

    const initData = webApp.initData;
    if (!initData) return undefined;

    let activeRequest = true;
    apiFetch('/telegram/mini-app/auth', {
      method: 'POST',
      body: JSON.stringify({ init_data: initData }),
    })
      .then((result) => {
        if (!activeRequest) return;
        saveToken(result.token);
        setCurrentUser(result.user);
        setToken(result.token);
      })
      .catch((requestError) => {
        if (activeRequest) setError(requestError);
      })
      .finally(() => {
        if (activeRequest) setLoading(false);
      });

    return () => { activeRequest = false; };
  }, []);

  useEffect(() => {
    const webApp = syncTelegramEnvironment(getTelegramWebApp());
    if (!webApp) return undefined;
    const refreshTheme = () => {
      syncTelegramEnvironment(webApp);
      window.dispatchEvent(new Event('kottraluy-theme-change'));
    };
    webApp.onEvent?.('themeChanged', refreshTheme);
    webApp.onEvent?.('viewportChanged', refreshTheme);
    return () => {
      webApp.offEvent?.('themeChanged', refreshTheme);
      webApp.offEvent?.('viewportChanged', refreshTheme);
    };
  }, []);

  useEffect(() => {
    if (!telegramMiniApp) return undefined;
    return setTelegramBackButton(active !== 'overview', () => window.history.back());
  }, [telegramMiniApp, active]);

  const load = async () => {
    if (!token) return; setLoading(true); setError('');
    try {
      const user = await apiFetch('/user');
      setCurrentUser(user);
      setBusiness(user.business || {});
      if (user.must_change_password) return;
      const accessRole = user.access_role || (user.role === 'owner' ? 'owner' : user.job_role === 'manager' ? 'manager' : 'staff');
      const isOwner = accessRole === 'owner'; const isStaff = accessRole === 'staff';
      const [dash, fin, productList, saleList, expenseList, packList] = await Promise.all([
        apiFetch(isStaff ? '/my-dashboard' : '/dashboard'),
        isOwner ? apiFetch('/financing/overview') : Promise.resolve(null),
        apiFetch('/products'),
        apiFetch('/sales'),
        isStaff ? Promise.resolve([]) : apiFetch('/expenses'),
        isOwner ? apiFetch('/financing/packs').catch(() => []) : Promise.resolve([]),
      ]);
      setDashboard(dash); setFinance(fin); setProducts(productList); setPacks(packList); setRecords({ sales: saleList, expenses: expenseList }); setBusiness(fin?.business_profile || dash.business || user.business || {}); setProfile({ ...profileDefaults, ...(fin?.profile || {}) });
    } catch (e) { setError(e); } finally { setLoading(false); }
  };
  // Synchronize authenticated workspace state after login and token changes.
  // eslint-disable-next-line react-hooks/set-state-in-effect, react-hooks/exhaustive-deps
  useEffect(() => { load(); }, [token]);
  useEffect(() => {
    if (!currentUser) return;
    const allowed = [...navItemsFor(currentUser).map(([key]) => key), ...secondaryPages];
    // eslint-disable-next-line react-hooks/set-state-in-effect
    if (!allowed.includes(active)) setActive('overview');
  }, [currentUser, active]);
  useEffect(() => { document.documentElement.lang = language; document.title = active === 'more' ? `KotTraLuy · ${tr("settings.settings_2")}` : tr("common.app_title"); }, [language, active]);
  useEffect(() => {
    document.addEventListener('invalid', validateControl, true);
    document.addEventListener('input', clearControlValidation, true);
    return () => { document.removeEventListener('invalid', validateControl, true); document.removeEventListener('input', clearControlValidation, true); };
  }, []);
  useEffect(() => {
    const initialPage = window.history.state?.kottraluyPage || 'overview';
    window.history.replaceState({ kottraluyPage: initialPage }, '', window.location.href);
    const onPopState = (event) => {
      setActive(event.state?.kottraluyPage || 'overview');
      setMobileNav(false);
    };
    window.addEventListener('popstate', onPopState);
    return () => window.removeEventListener('popstate', onPopState);
  }, []);
  useEffect(() => {
    if (!readInventoryLink()) return;
    window.history.replaceState({ kottraluyPage: 'inventory' }, '', `${window.location.pathname}${window.location.hash}`);
  }, []);
  const go = (key, target = null) => {
    if (!navItemsFor(currentUser).some(([item]) => item === key) && !secondaryPages.includes(key)) {
      key = 'overview';
    }
    telegramHaptic('light');
    setNotificationTarget(target);
    if (target) load();
    if (key === active) { setMobileNav(false); return; }
    setActive(key);
    setMobileNav(false);
    window.history.pushState({ kottraluyPage: key }, '', window.location.href);
  };
  const logout = async () => {
    try {
      await apiFetch('/logout', { method: 'POST' });
      clearToken(); setToken(''); setCurrentUser(null);
      setDashboard(null); setFinance(null); setBusiness({});
      setRecords({ sales: [], expenses: [] }); setProducts([]); setPacks([]);
      setError(''); setMobileNav(false); setAuthForm(authDefaults); setAuthMode('login');
    } catch (failure) { setError(failure); }
  };  const submitAuth = async (event) => { event.preventDefault(); setLoading(true); try { const result = await apiFetch(authMode === 'login' ? '/login' : '/register', { method: 'POST', body: JSON.stringify(authMode === 'login' ? { email: authForm.email, password: authForm.password } : authForm) }); saveToken(result.token); setCurrentUser(result.user); setToken(result.token); } catch (e) { setError(e); } finally { setLoading(false); } };
  const toggleSidebar = () => setSidebarCollapsed(current => { const next = !current; try { localStorage.setItem(SIDEBAR_COLLAPSED_KEY, String(next)); } catch { /* Keep the preference in memory when storage is unavailable. */ } return next; });
  const invitationToken = new URLSearchParams(window.location.search).get('invite');
  if (!token && invitationToken) return <InvitationAcceptPage token={invitationToken} language={language} toggleLanguage={() => setLanguage(language === 'en' ? 'km' : 'en')} onAccepted={result => { setCurrentUser(result.user); setToken(result.token); window.history.replaceState({}, '', window.location.pathname); }} />;
  if (!token) return <LoginScreen mode={authMode} setMode={setAuthMode} form={authForm} setForm={setAuthForm} submit={submitAuth} loading={loading} error={messageText(error)} language={language} toggleLanguage={() => setLanguage(language === 'en' ? 'km' : 'en')} />;
  return <NotificationProvider key={`${currentUser?.id}:${currentUser?.business_id}:${currentUser?.access_role || currentUser?.role}:${currentUser?.status}`} user={currentUser}><div className={`app-shell${telegramMiniApp ? ' telegram-mini-app' : ''}`}><Sidebar active={active} go={go} mobile={mobileNav} close={() => setMobileNav(false)} collapsed={sidebarCollapsed} toggleCollapsed={toggleSidebar} language={language} toggleLanguage={() => setLanguage(language === 'en' ? 'km' : 'en')} currentUser={currentUser} /><div className="main-shell"><AppHeader title={uiLabel(titleFor(active))} business={business} currentUser={currentUser} onNavigate={go} onMenu={() => setMobileNav(true)} onLogout={logout} language={language} onLanguage={() => setLanguage(language === 'en' ? 'km' : 'en')} /><main className="page"><div className="breadcrumbs"><span>{tr("settings.workspace")}</span><ChevronRight size={14} /><strong>{business.name || tr("common.my_business")}</strong><span className="workspace-badge">{tr("reports.self_reported_workspace")}</span><span className="avatar">{(business.name || 'RA').slice(0, 2).toUpperCase()}</span></div>{active !== 'inventory' && <div className="page-heading"><div><p className="eyebrow">{uiLabel(titleFor(active))}</p><h1>{uiLabel(titleFor(active))}</h1><p className="subheading">{uiLabel(pageDescription(active))}</p></div><div className="heading-actions">{active === 'inventory' && currentUser?.access_role !== 'staff' && <button className="button primary" onClick={() => { setEditingProduct(null); setShowProduct(true); }}><Plus size={15} />{tr("stock.add_product_3")}</button>}</div></div>}{loading && <div className="loading-line"><span />{tr("settings.syncing_workspace_data")}</div>}{currentUser?.must_change_password ? <PasswordChangePanel required onChanged={() => { setCurrentUser({ ...currentUser, must_change_password: false }); load(); }} /> : <PageContent active={active} dashboard={dashboard} finance={finance} products={products} financingError={messageText(financingError)} packs={packs} profile={profile} setProfile={setProfile} business={business} setBusiness={setBusiness} records={records} onNavigate={go} reload={load} setError={setError} currentUser={currentUser} language={language} notificationTarget={notificationTarget} />}</main><MobileTabBar active={active} go={go} currentUser={currentUser} /><footer><span><span className="status-dot" />{tr("documents.data_belongs_to_this_workspace_documents_are_availability_only")}</span><button onClick={logout}>{tr("common.log_out")}</button></footer></div>{showRecord && <RecordModal products={dashboard?.products || []} close={() => setShowRecord(false)} reload={load} setError={setError} />}{showProduct && <ProductModal product={editingProduct} close={() => setShowProduct(false)} reload={load} setError={setError} />}{error && <button className="error-toast" onClick={() => setError('')}>{messageText(error)}<X size={15} /></button>}</div></NotificationProvider>;
}

function PageContent({ active, dashboard, finance, products, financingError, packs, profile, setProfile, business, setBusiness, records, onNavigate, reload, setError, currentUser, language, notificationTarget }) {
  if (active === 'notifications') return <NotificationsPage language={language} onNavigate={onNavigate} />;
  const topbar = null;
  if (active === 'overview') return <>{topbar}{currentUser?.access_role === 'staff' ? <StaffDashboard dashboard={dashboard} currentUser={currentUser} onNavigate={onNavigate} language={language} /> : <HomePage dashboard={dashboard} records={records} products={products} business={business} currentUser={currentUser} onNavigate={onNavigate} reload={reload} language={language} />}</>;
  if (active === 'records') return <>{topbar}<RecordsHub records={records} business={business} reload={reload} notificationTarget={notificationTarget} language={language} currentUser={currentUser} /></>;
  if (active === 'reports') return <>{topbar}<ReportsLivePage key={`${currentUser?.id}:${currentUser?.business_id}`} language={language} defaultCurrency={business?.currency || currentUser?.business?.currency || 'USD'} /></>;
  if (active === 'alerts') return <>{topbar}<InventoryAlertsPage onNavigate={onNavigate} /></>;
  if (active === 'inventory') return <>{topbar}<InventoryLivePage key={currentUser?.business_id} notificationTarget={notificationTarget} currentUser={currentUser} business={business} reload={reload} language={language} /></>;
  if (active === 'more') return <>{topbar}<MorePage onNavigate={onNavigate} currentUser={currentUser} /></>;
  if (active === 'prepare') return <>{topbar}<Preparation finance={finance} profile={profile} setProfile={setProfile} business={business} setBusiness={setBusiness} onNavigate={onNavigate} reload={reload} setError={setError} /></>;
  if (active === 'options') return <>{topbar}<FinanceOptionsPage products={products} error={messageText(financingError)} retry={reload} /></>;
  if (active === 'packs') return <>{topbar}<PreparationPacksPage packs={packs} products={products} profile={profile} business={business} reload={reload} onNavigate={onNavigate} /></>;
  if (active === 'documents') return <>{topbar}<DocumentsPage documents={finance?.documents || []} reload={reload} /></>;
  if (active === 'business') return <>{topbar}<BusinessProfilePage business={business} setBusiness={setBusiness} reload={reload} /></>;
  if (active === 'telegram') return <>{topbar}<TelegramAccessPanel user={currentUser} /></>;
  if (active === 'profile') return <>{topbar}<StaffProfile currentUser={currentUser} business={business} /></>;
  return <>{topbar}<TelegramAccessPanel user={currentUser} /></>;
}

function Brand({ collapsed = false }) {
  return <div className={`brand ${collapsed ? 'brand-collapsed' : ''}`} aria-label="KotTraLuy">
    <BrandLogo compact={collapsed} className={collapsed ? 'sidebar-brand-mark' : 'sidebar-brand-logo'} />
  </div>;
}
function StaffDashboard({ dashboard, currentUser, onNavigate, language }) {
  const greeting = useGreeting(language, greetingTimeZone(currentUser));
  return <div className="staff-dashboard"><section className="panel staff-welcome"><p className="eyebrow">{tr("common.dashboard")}</p><h1 className="dashboard-greeting">{greeting}, {currentUser?.name || currentUser?.username || tr("team.workspace_member")}!</h1><p>{dashboard?.business?.name || tr("common.my_business")}</p></section><div className="metrics"><Metric label={tr("sales.sales_recorded")} value={dashboard?.today_sales_count ?? 0} tone="count" note={tr("sales.recorded_today")} /><Metric label={tr("common.total")} value={money(dashboard?.today_sales_total ?? 0, dashboard?.business?.currency || 'USD')} tone="cash" note={tr("sales.your_sales_today")} /></div><section className="panel staff-shift"><div><strong>{tr("team.assigned_work_shift")}</strong><p>{dashboard?.assigned_shift ? tr("team.shift_label", { shift: dashboard.assigned_shift }) : tr("common.not_assigned")}</p></div><button className="button primary" onClick={() => onNavigate('records')}>{tr("sales.new_sale")}<ChevronRight size={15} /></button></section><section className="panel"><PanelTitle title={tr("sales.recent_transactions")} subtitle={tr("sales.your_latest_sales_and_business_expenses")} />{dashboard?.recent_sales?.length ? <RecordList records={dashboard.recent_sales.map(sale => ({ type: 'Sale', description: sale.sale_no, date: sale.sold_at, amount: sale.total_amount, currency: sale.currency }))} empty={tr("sales.no_recent_records_yet_add_a_sale_purchase_or_expense_to_start_your_history")} /> : <EmptyState icon={<ShoppingCart />} title={tr("sales.no_sales_recorded_yet")} copy={tr("sales.start_tracking_your_business_income_by_recording_your_first_sale")} />}</section><section className="panel"><PanelTitle title={tr("stock.inventory_alerts")} subtitle={tr("stock.low_stock_items_from_your_records")} />{dashboard?.alerts?.length ? dashboard.alerts.map(alert => <div className="alert-row" key={alert.id}><CircleAlert size={16} /><span>{alert.product?.name}</span><strong>{alert.condition}</strong></div>) : <EmptyState icon={<Boxes />} title={tr("stock.inventory_looks_clear")} copy={tr("stock.items_at_or_below_minimum_stock_will_appear_here")} />}</section></div>;
}
function StaffProfile({ currentUser, business }) {
  return <section className="panel staff-profile"><p className="eyebrow">{tr("common.profile_menu")}</p><h1>{currentUser?.name || tr("team.workspace_member")}</h1><dl><div><dt>{tr("common.email")}</dt><dd>{currentUser?.email || currentUser?.username || '—'}</dd></div><div><dt>{tr("settings.workspace")}</dt><dd>{business?.name || tr("common.my_business")}</dd></div><div><dt>{tr("team.workspace_role")}</dt><dd>{currentUser?.access_role === 'manager' ? tr("team.manager") : tr("team.staff")}</dd></div><div><dt>{tr("team.assigned_work_shift")}</dt><dd>{currentUser?.assigned_shift ? tr("team.shift_label", { shift: currentUser.assigned_shift }) : tr("common.not_assigned")}</dd></div></dl></section>;
}
function TelegramNavIcon({ size = 17 }) { return <img className="nav-icon" src={telegramBotIcon} alt="" aria-hidden="true" width={size} height={size} />; }
function InventoryNavIcon({ size = 17 }) { return <img className="nav-icon" src={inventoryIcon} alt="" aria-hidden="true" width={size} height={size} />; }
function PrepareNavIcon({ size = 17 }) { return <img className="nav-icon" src={prepareIcon} alt="" aria-hidden="true" width={size} height={size} />; }
function BusinessNavIcon({ size = 17 }) { return <img className="nav-icon" src={businessIcon} alt="" aria-hidden="true" width={size} height={size} />; }
function ChecklistNavIcon({ size = 17 }) { return <img className="nav-icon" src={checklistIcon} alt="" aria-hidden="true" width={size} height={size} />; }
function RecordsNavIcon({ size = 17 }) { return <img className="nav-icon" src={recordsIcon} alt="" aria-hidden="true" width={size} height={size} />; }
function OverviewNavIcon({ size = 17 }) { return <img className="nav-icon" src={overviewIcon} alt="" aria-hidden="true" width={size} height={size} />; }
function FinanceOptionsNavIcon({ size = 17 }) { return <img className="nav-icon" src={financeOptionsIcon} alt="" aria-hidden="true" width={size} height={size} />; }
function Sidebar({ active, go, mobile, close, collapsed, toggleCollapsed, language, toggleLanguage, currentUser }) { const toggleLabel = tr(collapsed ? "common.expand_sidebar" : "common.collapse_sidebar"); return <aside className={`sidebar ${mobile ? 'open' : ''} ${collapsed && !mobile ? 'collapsed' : ''}`}><div className="side-top"><Brand collapsed={collapsed && !mobile} /><button className="side-collapse" aria-label={toggleLabel} title={toggleLabel} aria-expanded={!collapsed} onClick={toggleCollapsed}>{collapsed ? <ChevronRight size={17} /> : <ChevronLeft size={17} />}</button><button className="side-close" aria-label={tr("common.close")} onClick={close}><X size={18} /></button></div><nav>{navItemsFor(currentUser).map(([key, label, Icon]) => <button key={key} className={mainTabFor(active) === key ? 'selected' : ''} aria-label={uiLabel(label)} title={collapsed ? uiLabel(label) : undefined} onClick={() => go(key)}><Icon size={17} /><span>{uiLabel(label)}</span></button>)}</nav><div className="side-foot" title={collapsed ? tr("settings.private_workspace") : undefined}><ShieldCheck size={17} /><span><strong>{tr("settings.private_workspace")}</strong><small>{tr("reports.self_reported_until_verified")}</small></span></div><LanguageSwitch language={language} toggle={toggleLanguage} /></aside>; }
function MobileTabBar({ active, go, currentUser }) { return <nav className="mobile-tab-bar">{navItemsFor(currentUser).map(([key, label, Icon]) => <button key={key} className={mainTabFor(active) === key ? 'selected' : ''} onClick={() => go(key)}><Icon size={17} /><span>{uiLabel(label)}</span></button>)}</nav>; }
function LanguageSwitch({ language, toggle }) { return <button className="language-switch" onClick={toggle} aria-label={language === 'en' ? tr("common.switch_to_khmer") : tr("common.switch_to_english")}><span className={language === 'km' ? 'current' : ''}>ខ្មែរ</span><span>/</span><span className={language === 'en' ? 'current' : ''}>English</span></button>; }
function Overview({ dashboard, finance, onNavigate }) { const revenue = dashboard?.revenue ?? 0; const expenses = dashboard?.expenses ?? 0; const purchases = dashboard?.purchaseValue ?? 0; const salesCount = dashboard?.salesCount ?? 0; const hasTrend = (dashboard?.trend || []).length >= 2; const matched = (finance?.checklist || []).filter((x) => x.status === 'matched').length; const next = (finance?.checklist || []).find((x) => x.status !== 'matched'); const alerts = (dashboard?.products || []).filter((p) => Number(p.stock_quantity) <= Number(p.minimum_stock)); return <><section className="hero"><div><p className="eyebrow light">{tr("common.your_next_chapter")}</p><h2>{tr("common.a_stronger_business_record")}<br />{tr("financing.a_clearer_funding_conversation")}</h2><p>{tr("common.next")}{next?.label || tr("financing.review_your_preparation_pack")}</p></div><button className="button cream" onClick={() => onNavigate('prepare')}>{tr("financing.prepare_for_financing_2")}<ChevronRight size={15} /></button></section><section className="metrics"><Metric label={tr("common.revenue")} value={money(revenue)} tone="up" note={tr("sales.recorded_sales")} /><Metric label={tr("sales.expenses")} value={money(expenses)} tone="down" note={tr("sales.recorded_expenses")} /><Metric label={tr("common.recorded_cash_movement")} value={money(revenue - expenses - purchases)} tone="cash" note={tr("sales.sales_expenses_purchases_not_profit")} /><Metric label={tr("sales.sales_recorded")} value={salesCount} tone="count" note={tr("sales.transactions_in_this_workspace")} /></section><div className="overview-grid"><section className="panel"><PanelTitle title={tr("financing.financial_trend")} subtitle={hasTrend ? tr("common.recorded_money_in_and_money_out") : tr("common.more_records_are_needed_before_showing_a_trend")} /><div className="legend"><span className="legend-revenue" />{tr("common.revenue")}<span className="legend-expense" />{tr("sales.expenses")}</div>{hasTrend ? <div className="chart">{dashboard.trend.map((item) => <div className="chart-column" key={domainLabel(item.label)}><div className="column revenue" style={{ height: `${Math.max(8, item.revenue / Math.max(...dashboard.trend.map((x) => x.revenue), 1) * 150)}px` }} /><div className="column expense" style={{ height: `${Math.max(8, item.expenses / Math.max(...dashboard.trend.map((x) => x.revenue), 1) * 150)}px` }} /><small>{domainLabel(item.label)}</small></div>)}</div> : <EmptyState icon={<BarChart3 />} title={tr("common.not_enough_records_yet")} copy={tr("financing.record_at_least_two_periods_of_activity_to_see_a_financial_trend")} />}</section><section className="panel"><PanelTitle title={tr("common.preparation_progress")} subtitle={tr("common.availability_not_a_score_or_approval_probability")} /><div className="readiness-number"><strong>{matched}</strong><span>{tr("financing.preparation_count",{count:matched,total:finance?.checklist?.length||0})}</span></div><div className="progress"><span style={{ width: `${finance?.checklist?.length ? matched / finance.checklist.length * 100 : 0}%` }} /></div><button className="text-button" onClick={() => onNavigate('prepare')}>{tr("common.continue_preparation")}<ChevronRight size={15} /></button></section></div><div className="overview-grid lower"><section className="panel"><PanelTitle title={tr("common.recent_records")} subtitle={tr("settings.latest_activity_in_this_workspace")} /><RecordList records={[...(dashboard?.recent_records || [])].slice(0, 4)} empty={tr("sales.no_recent_records_yet_add_a_sale_purchase_or_expense_to_start_your_history")} /></section><section className="panel"><PanelTitle title={tr("stock.inventory_alerts")} subtitle={tr("stock.low_stock_items_from_your_records")} />{alerts.length ? alerts.slice(0, 4).map((p) => <div className="alert-row" key={p.id}><CircleAlert size={16} /><span>{p.name}</span><strong>{p.stock_quantity} {p.unit}</strong></div>) : <EmptyState icon={<Boxes />} title={tr("stock.inventory_looks_clear")} copy={tr("stock.items_at_or_below_minimum_stock_will_appear_here")} />}</section></div></>; }
function Metric({ label, value, note, tone }) { return <div className="metric"><div className="metric-label"><span className={`metric-icon ${tone}`}>{tone === 'up' ? <ArrowUp size={13} /> : tone === 'down' ? <ArrowDown size={13} /> : tone === 'count' ? <BarChart3 size={13} /> : <WalletCards size={13} />}</span>{uiLabel(label)}</div><strong>{value}</strong><small>{note}</small></div>; }
function Records({ records }) { const all = [...records.sales.map((x) => ({ ...x, kind: 'Sale', date: x.sold_at, amount: x.total_amount })), ...records.expenses.map((x) => ({ ...x, kind: 'Expense', date: x.expense_date, amount: x.amount }))].sort((a, b) => new Date(b.date) - new Date(a.date)); return <section className="panel full-panel"><PanelTitle title={tr("common.business_records")} subtitle={tr("common.keep_business_money_separate_from_owner_money_each_record_stays_in_its_original_currency")} /><div className="record-table"><div className="table-head"><span>{tr("common.type")}</span><span>{tr("common.description")}</span><span>{tr("common.date")}</span><span>{tr("common.amount")}</span></div>{all.length ? all.map((r) => <div className="table-row" key={`${r.kind}-${r.id}`}><span className={`kind ${r.kind.toLowerCase()}`}>{r.kind}</span><span>{r.description || r.notes || r.sale_no || tr("common.business_activity_2")}</span><span>{r.date ? new Date(r.date).toLocaleDateString() : '—'}</span><strong>{money(r.amount, r.currency || 'USD')}</strong></div>) : <EmptyState icon={<BookOpen />} title={tr("common.no_business_records_yet")} copy={tr("sales.start_with_a_sale_or_expense_records_will_power_summaries_and_preparation_checklists")} />}</div></section>; }
function Inventory({ products, currentUser, reload, setError }) { const owner = currentUser?.role === 'owner'; const [editing, setEditing] = useState(null); const [showForm, setShowForm] = useState(false); const openForm = (product = null) => { setEditing(product); setShowForm(true); }; const remove = async (product) => { if (!window.confirm(tr("stock.confirm_delete",{name:product.name}))) return; try { await apiFetch(`/products/${product.id}`, { method: 'DELETE' }); await reload(); } catch (e) { setError(e); } }; return <section className="panel full-panel"><PanelTitle title={tr("stock.products_inventory")} subtitle={tr("stock.stock_levels_are_operational_records_not_financing_eligibility")} />{owner && <div className="inventory-actions"><button className="button primary small" onClick={() => openForm()}><Plus size={14} />{tr("stock.add_product_3")}</button></div>}<div className="product-grid">{products.length ? products.map((p) => <div className="product-card" key={p.id}><div className="product-symbol"><Package size={17} /></div><div><strong>{p.name}</strong><small>{p.sku || tr("common.no_sku")} · {p.unit}</small></div><span className={Number(p.stock_quantity) <= Number(p.minimum_stock) ? 'stock low' : 'stock'}>{p.stock_quantity}{tr("stock.in_stock_2")}</span>{owner && <div className="product-actions"><button className="text-button" onClick={() => openForm(p)}>{tr("common.edit")}</button><button className="text-button danger" onClick={() => remove(p)}>{tr("common.delete")}</button></div>}</div>) : <EmptyState icon={<Package />} title={tr("stock.no_products_yet")} copy={tr("stock.add_a_product_to_start_tracking_stock_levels_and_low_stock_alerts")} />}</div>{showForm && <ProductModal product={editing} close={() => setShowForm(false)} reload={reload} setError={setError} />}</section>; }

function Preparation({ finance, profile, setProfile, business, setBusiness, onNavigate, reload, setError }) { const checklist = finance?.checklist || []; const available = checklist.filter((x) => x.status === 'matched').length; const next = checklist.find((x) => x.status !== 'matched'); const [saving, setSaving] = useState(false); const save = async () => { setSaving(true); try { await apiFetch('/financing/business', { method: 'PUT', body: JSON.stringify({ name: business.name || '', activity: business.activity || '', province: business.province || '', operating_started_on: business.operating_started_on || null, registration_status: business.registration_status || 'unknown' }) }); await apiFetch('/financing/profile', { method: 'PUT', body: JSON.stringify({ ...profile, requested_amount: Number(profile.requested_amount || 0) }) }); await reload(); } catch (e) { setError(e); } finally { setSaving(false); } }; const jump = (id) => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' }); return <div className="prep-layout"><aside className="prep-sidebar"><div className="prep-summary"><Sparkles size={18} /><strong>{tr("financing.preparation_count",{count:available,total:checklist.length})}</strong><p>{tr("common.this_is_preparation_completeness_only_it_is_not_a_credit_score_approval_probability_or_bank_decision")}</p>{next && <small className="prep-next">{tr("common.next")}{domainLabel(next.label)}</small>}</div><button className="prep-anchor active" onClick={() => jump('business-profile')}>{tr("common.1_business_profile")}</button><button className="prep-anchor" onClick={() => jump('funding-need')}>{tr("financing.2_funding_need")}</button><button className="prep-anchor" onClick={() => onNavigate('documents')}>{tr("documents.3_document_checklist")}</button><button className="prep-anchor" onClick={() => onNavigate('options')}>{tr("stock.4_product_comparison")}</button><button className="prep-anchor" onClick={() => onNavigate('packs')}>{tr("common.5_review_and_download")}</button></aside><div className="prep-content"><section id="business-profile" className="panel"><PanelTitle title={tr("common.business_profile_2")} subtitle={tr("reports.save_informal_businesses_too_information_is_labelled_self_reported_until_verified")} /><div className="form-grid"><Field label={tr("common.business_name_2")} value={business.name} onChange={(v) => setBusiness({ ...business, name: v })} required /><Field label={tr("common.activity")} value={business.activity} onChange={(v) => setBusiness({ ...business, activity: v })} placeholder={tr("common.what_does_the_business_do")} /><Field label={tr("common.province")} value={business.province} onChange={(v) => setBusiness({ ...business, province: v })} placeholder={tr("common.e_g_phnom_penh")} /><Field label={tr("common.operating_start_date_2")} type="date" value={business.operating_started_on || ''} onChange={(v) => setBusiness({ ...business, operating_started_on: v })} /><SelectField label={tr("common.registration_status_2")} value={business.registration_status || 'unknown'} onChange={(v) => setBusiness({ ...business, registration_status: v })} options={[['unknown', 'Not sure / not provided'], ['informal', 'Informal business'], ['registered', 'Registered']]} /></div><p className="hint">{tr("reports.this_information_helps_providers_understand_your_business_context_it_remains_self_reported_until_ver")}</p></section><section id="funding-need" className="panel"><PanelTitle title={tr("financing.funding_need")} subtitle={tr("common.usd_and_khr_stay_separate_no_exchange_rate_is_inferred")} /><div className="form-grid"><div className="field"><label>{tr("common.requested_amount")}</label><div className="amount-field"><Select value={profile.currency} onChange={(value) => setProfile({ ...profile, currency: value })} options={[["USD", "USD"],["KHR", "KHR"]]} aria-label={tr("common.currency")} /><input type="number" min="0" value={profile.requested_amount || ''} onChange={(e) => setProfile({ ...profile, requested_amount: e.target.value })} placeholder="0" /></div></div><SelectField label={tr("financing.funding_purpose")} value={profile.purpose} onChange={(v) => setProfile({ ...profile, purpose: v })} options={['Working capital', 'Inventory purchase', 'Equipment', 'Business expansion'].map((x) => [x, x])} /><SelectField label={tr("common.timing")} value={profile.requested_timing} onChange={(v) => setProfile({ ...profile, requested_timing: v })} options={['Within 3 months', 'Within 6 months', 'Exploring only'].map((x) => [x, x])} /></div><p className="hint">{tr("financing.a_clear_amount_purpose_and_timing_makes_provider_conversations_more_specific")}</p><button className="button primary" onClick={save} disabled={saving}>{saving ? tr("common.saving") : tr("common.save_preparation_details")} <ChevronRight size={15} /></button></section><section className="panel"><PanelTitle title={tr("documents.known_checklist")} subtitle={tr("financing.unknown_requirements_remain_visible_for_provider_confirmation")} />{checklist.map((item) => <ChecklistRow key={item.key} item={item} />)}</section></div></div>; }
// The live Telegram connection panel replaces the former simulated preview.

function ChecklistRow({ item, compact = false }) { const labels = { matched: "common.matched", information_missing: "common.information_missing_2", requirement_mismatch: "common.requirement_mismatch", bank_confirmation_needed: "common.bank_confirmation_needed", missing: "common.missing" }; const status = item.status || 'missing'; return <div className={`checklist-row ${compact ? 'compact' : ''}`}><span className={`check-status ${status}`}>{status === 'matched' ? <Check size={13} /> : status === 'bank_confirmation_needed' ? '?' : '○'}</span><div><strong>{domainLabel(item.label)}</strong><small>{domainLabel(labels[status] || status)}</small></div><span className={`status-text ${status}`}>{domainLabel(labels[status] || status)}</span></div>; }
function RecordList({ records, empty }) { return records.length ? <div className="record-list">{records.map((r, i) => <div className="record-list-row" key={i}><span className="record-dot" /><div><strong>{r.description || r.type || tr("common.business_record")}</strong><small>{r.date || tr("common.recent")} · {r.currency || 'USD'}</small></div><strong>{money(r.amount, r.currency || 'USD')}</strong></div>)}</div> : <EmptyState icon={<BookOpen />} title={tr("common.no_recent_records")} copy={empty} />; }
function PanelTitle({ title, subtitle }) { return <div className="panel-title"><div><h2>{title}</h2><p>{subtitle}</p></div></div>; }
function EmptyState({ icon, title, copy }) { return <div className="empty-state">{icon}<strong>{title}</strong><p>{copy}</p></div>; }
function Field({ label, value, onChange, type = 'text', placeholder, required }) { return <div className="field"><label>{label}{required && <em>{tr("common.required")}</em>}</label><input type={type} value={value || ''} placeholder={placeholder} required={required} onChange={(e) => onChange(e.target.value)} /></div>; }
function SelectField({ label, value, onChange, options }) { return <div className="field"><label>{label}</label><Select value={value || ''} onChange={onChange} options={options.map(([v, text]) => [v, domainLabel(text)])} placeholder={label} /></div>; }
function AuthScreen({ mode, setMode, form, setForm, submit, loading, error, language, toggleLanguage }) { return <div className="auth-screen"><div className="auth-brand"><img className="auth-logo" src={loginLogo} alt="KotTraLuy" /></div><div className="auth-card"><div className="auth-language"><LanguageSwitch language={language} toggle={toggleLanguage} /></div><p className="eyebrow">{tr("financing.sme_financing_preparation")}</p><h1>{tr("financing.build_a_clearer_funding_conversation")}</h1><p>{tr("financing.keep_business_records_organized_understand_missing_information_and_prepare_for_a_provider_review")}</p><div className="segmented"><button className={mode === 'register' ? 'selected' : ''} onClick={() => setMode('register')}>{tr("settings.create_workspace")}</button><button className={mode === 'login' ? 'selected' : ''} onClick={() => setMode('login')}>{tr("auth.sign_in")}</button></div><form onSubmit={submit}>{mode === 'register' && <><Field label={tr("common.your_name")} required value={form.name} onChange={(v) => setForm({ ...form, name: v })} /><Field label={tr("common.business_name_2")} required value={form.business_name} onChange={(v) => setForm({ ...form, business_name: v })} /></>}<Field label={tr("common.email")} type="email" required value={form.email} onChange={(v) => setForm({ ...form, email: v })} /><Field label={tr("auth.password")} type="password" required value={form.password} onChange={(v) => setForm({ ...form, password: v })} />{error && <p className="form-error">{messageText(error)}</p>}<button className="button primary full" disabled={loading}>{loading ? tr("common.please_wait_2") : mode === 'login' ? tr("auth.sign_in") : tr("settings.create_workspace")} <ChevronRight size={15} /></button></form></div></div>; }
function ProductModal({ product, close, reload }) { return <ProductDialog product={product} close={close} onSaved={async () => { close(); await reload(); }} language={document.documentElement.lang} />; }

function RecordModal({ products, close, reload }) { return <ActivityDialog products={products} close={close} onSaved={async () => { close(); await reload(); }} language={document.documentElement.lang} />; }
function pageDescription(key) { return ({ overview: "stock.your_sales_expenses_profit_and_stock_at_a_glance", records: "common.review_and_correct_the_activity_behind_your_summaries", inventory: "stock.track_products_stock_levels_and_low_stock_reminders", reports: "reports.review_profit_loss_and_cash_flow", more: "settings.workspace_settings_and_supporting_tools", profile: "team.workspace_member", prepare: "financing.organize_what_you_know_before_speaking_with_a_provider", options: "stock.compare_product_specific_published_information_without_guessing_bank_decisions", packs: "common.review_dated_drafts_that_are_not_submitted_to_any_bank", documents: "documents.track_document_availability_without_pretending_secure_files_are_uploaded", business: "reports.keep_your_business_context_clear_and_self_reported_until_verified", telegram: "sales.connect_your_telegram_account_and_record_sales_during_your_assigned_shift" })[key] || ''; }
function sourceStatus(status) { return ({ official_source_reviewed: 'Official source reviewed', requirements_need_confirmation: 'Requirements need confirmation', source_requires_manual_confirmation: 'Manual confirmation needed' })[status] || tr("common.source_status_unknown"); }
function downloadJson(name, payload) { const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' }); const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = name; link.click(); URL.revokeObjectURL(url); }
function Reports() { const today = new Date().toISOString().slice(0, 10); const [from, setFrom] = useState(today); const [to, setTo] = useState(today); const [summary, setSummary] = useState(null); const [loading, setLoading] = useState(false); const [error, setError] = useState(''); const run = async () => { setLoading(true); setError(''); try { setSummary(await apiFetch(`/reports/range?from=${from}&to=${to}`)); } catch (e) { setError(e); } finally { setLoading(false); } }; return <section className="panel full-panel"><PanelTitle title={tr("common.business_summaries")} subtitle={tr("reports.daily_weekly_monthly_or_custom_ranges_currency_stays_separate_profit_uses_stored_batch_costs")} /><div className="form-grid"><Field label={tr("common.from")} type="date" value={from} onChange={setFrom} /><Field label={tr("common.to")} type="date" value={to} onChange={setTo} /></div><div className="heading-actions"><button className="button primary" onClick={run} disabled={loading}>{loading ? tr("common.loading") : tr("common.generate_summary")}</button>{summary && <button className="button secondary" onClick={() => window.open(`/api/reports/range.csv?from=${from}&to=${to}`, '_blank')}>{tr("common.export_csv")}</button>}</div>{error && <p className="form-error">{messageText(error)}</p>}{summary && <><div className="metrics"><Metric label={tr("sales.net_sales")} value={money(summary.net_sales, summary.currency)} note={tr("sales.sales_recorded_in_range")} tone="up" /><Metric label={tr("sales.payments_received")} value={money(summary.customer_payments_received, summary.currency)} note={tr("common.cash_received_from_customers")} tone="cash" /><Metric label={tr("common.unpaid_balances_2")} value={money(summary.unpaid_balances, summary.currency)} note={tr("sales.outstanding_at_sale_level")} tone="count" /><Metric label={tr("reports.profit")} value={money(summary.profit, summary.currency)} note={tr("common.incomplete_if_cost_data_is_missing")} tone="up" /></div><div className="notice"><CircleAlert size={17} /><span>{summary.profit_method}{tr("reports.this_is_self_reported_and_not_audited")}</span></div></>}</section>; }
