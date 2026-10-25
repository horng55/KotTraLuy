import { useCallback, useEffect, useState } from 'react';
import { apiFetch, clearToken, saveToken } from './lib/api';
import PasswordChangePanel from './components/PasswordChangePanel';
import ShiftPanel from './components/ShiftPanel';
import UserManagementPanel from './components/UserManagementPanel';

const defaultForm = {
  name: '',
  login: '',
  email: '',
  password: '',
  business_name: '',
  role: 'owner',
};

const toneClasses = {
  emerald: 'text-emerald-600',
  rose: 'text-rose-600',
  indigo: 'text-indigo-600',
  amber: 'text-amber-600',
};

export default function App() {
  const [token, setToken] = useState(localStorage.getItem('biztrack_token') || '');
  const [currentUser, setCurrentUser] = useState(null);
  const [miniAppAuthenticating, setMiniAppAuthenticating] = useState(
    () => Boolean(window.Telegram?.WebApp?.initData)
  );
  const [authMode, setAuthMode] = useState('register');
  const [form, setForm] = useState(defaultForm);
  const [dashboard, setDashboard] = useState(null);
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(false);
  const [productLoading, setProductLoading] = useState(false);
  const [transactionLoading, setTransactionLoading] = useState(false);
  const [error, setError] = useState('');
  const [telegramCode, setTelegramCode] = useState('');
  const [telegramCodeExpiresAt, setTelegramCodeExpiresAt] = useState('');
  const [telegramCommand, setTelegramCommand] = useState('');
  const [telegramBotUrl, setTelegramBotUrl] = useState('');
  const [telegramStatus, setTelegramStatus] = useState({ connected: false, telegram_chat: null });
  const [telegramLoading, setTelegramLoading] = useState(false);
  const [telegramLinkError, setTelegramLinkError] = useState('');
  const [telegramSecondsRemaining, setTelegramSecondsRemaining] = useState(0);
  const [telegramCopyMessage, setTelegramCopyMessage] = useState('');
  const [productForm, setProductForm] = useState({
    name: '',
    sku: '',
    unit: 'pcs',
    stock_quantity: '0',
    default_cost: '0',
    selling_price: '0',
    minimum_stock: '0',
  });
  const [saleForm, setSaleForm] = useState({ productId: '', quantity: '1', unitPrice: '0' });
  const [purchaseForm, setPurchaseForm] = useState({ productId: '', quantity: '1', unitCost: '0' });

  useEffect(() => {
    const telegramWebApp = window.Telegram?.WebApp;

    if (!telegramWebApp) return;

    telegramWebApp.ready();
    telegramWebApp.expand();
    telegramWebApp.setHeaderColor?.('#0f172a');
    telegramWebApp.setBackgroundColor?.('#f1f5f9');

    if (!telegramWebApp.initData) {
      return;
    }

    apiFetch('/telegram/mini-app/auth', {
      method: 'POST',
      body: JSON.stringify({ init_data: telegramWebApp.initData }),
    })
      .then((result) => {
        saveToken(result.token);
        setToken(result.token);
        setCurrentUser(result.user);
      })
      .catch((err) => setError(err.message))
      .finally(() => setMiniAppAuthenticating(false));
  }, []);

  // áž‘áž¶áž‰áž™áž€áž‘áž·áž“áŸ’áž“áž“áŸáž™ Dashboard & Products
  const fetchDashboard = useCallback(async (knownUser = currentUser) => {
    if (!token) return;

    try {
      const user = knownUser || await apiFetch('/user');
      if (!knownUser) setCurrentUser(user);
      if (user.must_change_password) return;
      const productList = await apiFetch('/products');
      setProducts(productList);
      if (user.role === 'owner') {
        setDashboard(await apiFetch('/dashboard'));
      } else {
        setDashboard(null);
      }
    } catch (err) {
      setError(err.message);
    }
  }, [token, currentUser]);

  // Real-time Background Polling: Sync áž‘áž·áž“áŸ’áž“áž“áŸáž™áž–áž¸ Telegram Bot ážšáŸ€áž„ážšáž¶áž›áŸ‹ 3 ážœáž·áž“áž¶áž‘áž¸
  useEffect(() => {
    if (!token || currentUser?.must_change_password) return;

    const initialLoad = setTimeout(() => fetchDashboard(), 0);

    const interval = setInterval(() => {
      fetchDashboard().catch((err) => console.error('Background sync failed:', err));
    }, 3000);

    return () => {
      clearTimeout(initialLoad);
      clearInterval(interval);
    };
  }, [token, currentUser?.must_change_password, fetchDashboard]);

  useEffect(() => {
    if (!token || !currentUser || currentUser.must_change_password) return;

    apiFetch('/telegram/status')
      .then((status) => setTelegramStatus(status))
      .catch((err) => setTelegramLinkError(err.message));
  }, [token, currentUser]);

  useEffect(() => {
    if (!telegramCodeExpiresAt) return undefined;

    const updateRemainingTime = () => {
      const remaining = Math.max(
        0,
        Math.ceil((new Date(telegramCodeExpiresAt).getTime() - Date.now()) / 1000)
      );
      setTelegramSecondsRemaining(remaining);
    };

    const initialUpdate = setTimeout(updateRemainingTime, 0);
    const interval = setInterval(updateRemainingTime, 1000);

    return () => {
      clearTimeout(initialUpdate);
      clearInterval(interval);
    };
  }, [telegramCodeExpiresAt]);

  const handleChange = (event) => {
    const { name, value } = event.target;
    setForm((current) => ({ ...current, [name]: value }));
  };

  const handleProductFieldChange = (event) => {
    const { name, value } = event.target;
    setProductForm((current) => ({ ...current, [name]: value }));
  };

  const handleProductSubmit = async (event) => {
    event.preventDefault();
    setError('');
    setProductLoading(true);

    try {
      await apiFetch('/products', {
        method: 'POST',
        body: JSON.stringify({
          name: productForm.name,
          sku: productForm.sku,
          unit: productForm.unit,
          stock_quantity: Number(productForm.stock_quantity || 0),
          default_cost: Number(productForm.default_cost || 0),
          selling_price: Number(productForm.selling_price || 0),
          minimum_stock: Number(productForm.minimum_stock || 0),
          is_active: true,
        }),
      });

      setProductForm({
        name: '',
        sku: '',
        unit: 'pcs',
        stock_quantity: '0',
        default_cost: '0',
        selling_price: '0',
        minimum_stock: '0',
      });

      await fetchDashboard();
    } catch (err) {
      setError(err.message);
    } finally {
      setProductLoading(false);
    }
  };

  const handleDemoSeed = async () => {
    if (!token) return;

    setError('');
    setTransactionLoading(true);

    try {
      const demoProducts = [
        { name: 'Milk', sku: 'MILK-001', unit: 'bottle', stock_quantity: 30, default_cost: 2.5, selling_price: 3.5, minimum_stock: 5 },
        { name: 'Bread', sku: 'BREAD-001', unit: 'loaf', stock_quantity: 20, default_cost: 1.2, selling_price: 2, minimum_stock: 5 },
        { name: 'Eggs', sku: 'EGGS-001', unit: 'tray', stock_quantity: 40, default_cost: 0.8, selling_price: 1.2, minimum_stock: 10 },
      ];

      await Promise.all(
        demoProducts.map((product) =>
          apiFetch('/products', {
            method: 'POST',
            body: JSON.stringify(product),
          })
        )
      );

      await fetchDashboard();
    } catch (err) {
      setError(err.message);
    } finally {
      setTransactionLoading(false);
    }
  };

  const handleQuickSale = async (event) => {
    event.preventDefault();
    setError('');
    setTransactionLoading(true);

    try {
      await apiFetch('/sales', {
        method: 'POST',
        body: JSON.stringify({
          sold_at: new Date().toISOString(),
          notes: 'Quick sale from demo dashboard',
          items: [
            {
              product_id: Number(saleForm.productId),
              quantity: Number(saleForm.quantity || 1),
              unit_price: Number(saleForm.unitPrice || 0),
            },
          ],
        }),
      });

      setSaleForm({ productId: '', quantity: '1', unitPrice: '0' });
      await fetchDashboard();
    } catch (err) {
      setError(err.message);
    } finally {
      setTransactionLoading(false);
    }
  };

  const handleQuickPurchase = async (event) => {
    event.preventDefault();
    setError('');
    setTransactionLoading(true);

    try {
      await apiFetch('/purchases', {
        method: 'POST',
        body: JSON.stringify({
          supplier_name: 'Demo Supplier',
          purchase_date: new Date().toISOString().split('T')[0],
          reference_no: 'DEMO-001',
          notes: 'Quick purchase from demo dashboard',
          items: [
            {
              product_id: Number(purchaseForm.productId),
              quantity: Number(purchaseForm.quantity || 1),
              unit_cost: Number(purchaseForm.unitCost || 0),
            },
          ],
        }),
      });

      setPurchaseForm({ productId: '', quantity: '1', unitCost: '0' });
      await fetchDashboard();
    } catch (err) {
      setError(err.message);
    } finally {
      setTransactionLoading(false);
    }
  };

  const handleAuthSubmit = async (event) => {
    event.preventDefault();
    setError('');
    setLoading(true);

    try {
      const endpoint = authMode === 'login' ? '/login' : '/register';
      const payload =
        authMode === 'login'
          ? { login: form.login, password: form.password }
          : {
              name: form.name,
              email: form.email,
              password: form.password,
              business_name: form.business_name,
              role: form.role,
            };

      const result = await apiFetch(endpoint, {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      if (result.token) {
        saveToken(result.token);
        setToken(result.token);
        setCurrentUser(result.user);
        setForm(defaultForm);
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  const handleLogout = async () => {
    try {
      await apiFetch('/logout', { method: 'POST' });
    } catch {
      // Always clear the local credential even if the server is unavailable.
    }
    clearToken();
    setToken('');
    setCurrentUser(null);
    setDashboard(null);
    setProducts([]);
    setTelegramStatus({ connected: false, telegram_chat: null });
    setTelegramCode('');
    setTelegramCommand('');
    setTelegramBotUrl('');
  };

  const refreshTelegramStatus = async () => {
    setTelegramLoading(true);
    setTelegramLinkError('');

    try {
      const status = await apiFetch('/telegram/status');
      setTelegramStatus(status);

      if (status.connected) {
        setTelegramCode('');
        setTelegramCodeExpiresAt('');
        setTelegramCommand('');
        setTelegramBotUrl('');
      }
    } catch (err) {
      setTelegramLinkError(err.message);
    } finally {
      setTelegramLoading(false);
    }
  };

  const handleCreateTelegramCode = async () => {
    setTelegramLinkError('');
    setTelegramCopyMessage('');
    setTelegramCode('');
    setTelegramCodeExpiresAt('');
    setTelegramLoading(true);

    try {
      const result = await apiFetch('/telegram/link-code', { method: 'POST' });
      setTelegramCode(result.code);
      setTelegramCodeExpiresAt(result.expires_at);
      setTelegramCommand(result.command);
      setTelegramBotUrl(result.bot_url);
      setTelegramSecondsRemaining(
        Math.max(0, Math.ceil((new Date(result.expires_at).getTime() - Date.now()) / 1000))
      );
    } catch (err) {
      setTelegramLinkError(err.message);
    } finally {
      setTelegramLoading(false);
    }
  };

  const handleCopyTelegramCommand = async () => {
    try {
      await navigator.clipboard.writeText(telegramCommand);
      setTelegramCopyMessage('Command copied.');
    } catch {
      setTelegramCopyMessage('Copy failed. Select the command and copy it manually.');
    }
  };

  const handleDisconnectTelegram = async () => {
    if (!window.confirm('Disconnect this Telegram account from KotTraLuy?')) return;

    setTelegramLoading(true);
    setTelegramLinkError('');

    try {
      await apiFetch('/telegram/link', { method: 'DELETE' });
      setTelegramStatus({ connected: false, telegram_chat: null });
      setTelegramCode('');
      setTelegramCodeExpiresAt('');
      setTelegramCommand('');
      setTelegramBotUrl('');
    } catch (err) {
      setTelegramLinkError(err.message);
    } finally {
      setTelegramLoading(false);
    }
  };

  const cards = dashboard
    ? [
        { label: 'Revenue', value: `$${Number(dashboard.today_revenue || 0).toFixed(2)}`, tone: 'emerald' },
        { label: 'Expenses', value: `$${Number(dashboard.today_expenses || 0).toFixed(2)}`, tone: 'rose' },
        { label: 'Purchase Value', value: `$${Number(dashboard.purchase_value || 0).toFixed(2)}`, tone: 'indigo' },
        { label: 'Sales Count', value: String(dashboard.number_of_sales || 0), tone: 'amber' },
      ]
    : [
        { label: 'Revenue', value: '$0.00', tone: 'emerald' },
        { label: 'Expenses', value: '$0.00', tone: 'rose' },
        { label: 'Purchase Value', value: '$0.00', tone: 'indigo' },
        { label: 'Sales Count', value: '0', tone: 'amber' },
      ];

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      <div className="mx-auto max-w-7xl p-4 md:p-8">
        <header className="mb-6 flex flex-col gap-4 rounded-2xl bg-slate-900 p-5 text-white md:flex-row md:items-center md:justify-between">
          <div>
            <p className="text-xs uppercase tracking-[0.2em] text-emerald-300">BizTrack</p>
            <h1 className="mt-2 text-2xl font-bold">Smart Daily Records & Business Insights</h1>
          </div>
          <div className="flex items-center gap-3">
            <span className="rounded-full bg-emerald-500/20 px-3 py-1 text-sm font-medium text-emerald-200">
              {currentUser ? `${currentUser.role === 'owner' ? 'Owner' : 'Staff'}${currentUser.assigned_shift ? ` · Shift ${currentUser.assigned_shift}` : ''}` : token ? 'Loading...' : 'Guest'}
            </span>
            <span className="rounded-full bg-slate-700 px-3 py-1 text-sm text-slate-200">
              {dashboard?.business?.name || currentUser?.business?.name || 'Business'}
            </span>
            {token && (
              <button
                type="button"
                onClick={handleLogout}
                className="rounded-full bg-white px-3 py-1 text-sm font-medium text-slate-900"
              >
                Logout
              </button>
            )}
          </div>
        </header>

        {miniAppAuthenticating ? (
          <section className="mx-auto max-w-xl rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm">
            <p className="font-medium text-slate-800">Opening KotTraLuy...</p>
            <p className="mt-2 text-sm text-slate-500">Securely verifying your Telegram account.</p>
          </section>
        ) : !token ? (
          <section className="mx-auto max-w-xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div className="mb-5 flex gap-2 rounded-full bg-slate-100 p-1">
              <button
                type="button"
                onClick={() => setAuthMode('register')}
                className={`flex-1 rounded-full px-4 py-2 text-sm font-medium ${
                  authMode === 'register' ? 'bg-slate-900 text-white' : 'text-slate-600'
                }`}
              >
                Register
              </button>
              <button
                type="button"
                onClick={() => setAuthMode('login')}
                className={`flex-1 rounded-full px-4 py-2 text-sm font-medium ${
                  authMode === 'login' ? 'bg-slate-900 text-white' : 'text-slate-600'
                }`}
              >
                Login
              </button>
            </div>

            <form onSubmit={handleAuthSubmit} className="space-y-4">
              {authMode === 'register' && (
                <>
                  <input
                    name="name"
                    value={form.name}
                    onChange={handleChange}
                    placeholder="Full name"
                    className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 outline-none focus:border-emerald-500"
                  />
                  <input
                    name="business_name"
                    value={form.business_name}
                    onChange={handleChange}
                    placeholder="Business name"
                    className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 outline-none focus:border-emerald-500"
                  />
                </>
              )}

              {authMode === 'login' ? (
                <input
                  name="login"
                  value={form.login}
                  onChange={handleChange}
                  placeholder="Username or email"
                  className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 outline-none focus:border-emerald-500"
                />
              ) : (
                <input
                  name="email"
                  type="email"
                  value={form.email}
                  onChange={handleChange}
                  placeholder="Owner email"
                  className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 outline-none focus:border-emerald-500"
                />
              )}

              <input
                name="password"
                type="password"
                value={form.password}
                onChange={handleChange}
                placeholder="Password"
                className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 outline-none focus:border-emerald-500"
              />

              {error && <p className="text-sm text-rose-600">{error}</p>}

              <button
                type="submit"
                disabled={loading}
                className="w-full rounded-xl bg-emerald-600 px-4 py-3 font-medium text-white disabled:opacity-60"
              >
                {loading ? 'Please wait...' : authMode === 'login' ? 'Login' : 'Create account'}
              </button>
            </form>
          </section>
        ) : !currentUser ? (
          <section className="rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm">
            Loading your account...
          </section>
        ) : currentUser.must_change_password ? (
          <PasswordChangePanel required onChanged={() => { setError(''); setCurrentUser((user) => ({ ...user, must_change_password: false })); }} />
        ) : (
          <>
            {currentUser.role === 'owner' && <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
              {cards.map((card) => (
                <div key={card.label} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                  <p className="text-sm text-slate-500">{card.label}</p>
                  <p className={`mt-3 text-3xl font-bold ${toneClasses[card.tone]}`}>{card.value}</p>
                </div>
              ))}
            </section>}

            <ShiftPanel user={currentUser} />

            {currentUser.role === 'owner' && <UserManagementPanel />}

            <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
              <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <h2 className="text-lg font-semibold">Telegram connection</h2>
                  <p className="mt-1 text-sm text-slate-600">
                    Connect your private Telegram account to @KotTraLuyBot.
                  </p>
                </div>
                <span
                  className={`w-fit rounded-full px-3 py-1 text-xs font-semibold ${
                    telegramStatus.connected
                      ? 'bg-emerald-100 text-emerald-700'
                      : telegramCode && telegramSecondsRemaining > 0
                        ? 'bg-amber-100 text-amber-700'
                        : 'bg-slate-100 text-slate-600'
                  }`}
                >
                  {telegramStatus.connected
                    ? 'Connected'
                    : telegramCode && telegramSecondsRemaining > 0
                      ? 'Pending'
                      : 'Not connected'}
                </span>
              </div>

              {telegramStatus.connected ? (
                <div className="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                  <p className="font-medium text-emerald-800">Telegram is connected.</p>
                  {telegramStatus.telegram_chat && (
                    <p className="mt-1 text-sm text-emerald-700">
                      Chat identifier: {telegramStatus.telegram_chat}
                    </p>
                  )}
                  <button
                    type="button"
                    onClick={handleDisconnectTelegram}
                    disabled={telegramLoading}
                    className="mt-3 rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm font-medium text-rose-700 disabled:opacity-60"
                  >
                    Disconnect Telegram
                  </button>
                </div>
              ) : telegramCode && telegramSecondsRemaining > 0 ? (
                <div className="mt-4 space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                  <p className="text-sm text-amber-900">
                    Open the bot, then send the one-time command below in your private chat.
                  </p>
                  <div className="break-all rounded-lg bg-white p-3 font-mono text-sm text-slate-900">
                    {telegramCommand}
                  </div>
                  <p className="text-sm font-medium text-amber-800">
                    Expires in {Math.floor(telegramSecondsRemaining / 60)}:
                    {String(telegramSecondsRemaining % 60).padStart(2, '0')}
                  </p>
                  <div className="flex flex-wrap gap-2">
                    <a
                      href={telegramBotUrl}
                      target="_blank"
                      rel="noreferrer"
                      className="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white"
                    >
                      Open @KotTraLuyBot
                    </a>
                    <button
                      type="button"
                      onClick={handleCopyTelegramCommand}
                      className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-800"
                    >
                      Copy command
                    </button>
                    <button
                      type="button"
                      onClick={refreshTelegramStatus}
                      disabled={telegramLoading}
                      className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-800 disabled:opacity-60"
                    >
                      Check connection
                    </button>
                    <button
                      type="button"
                      onClick={handleCreateTelegramCode}
                      disabled={telegramLoading}
                      className="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 disabled:opacity-60"
                    >
                      Generate a new token
                    </button>
                  </div>
                  {telegramCopyMessage && <p className="text-xs text-slate-600">{telegramCopyMessage}</p>}
                </div>
              ) : (
                <div className="mt-4">
                  {telegramCode && (
                    <p className="mb-3 text-sm text-amber-700">
                      That token expired. Generate a new one to continue.
                    </p>
                  )}
                  <button
                    type="button"
                    onClick={handleCreateTelegramCode}
                    disabled={telegramLoading}
                    className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
                  >
                    {telegramLoading ? 'Generating...' : 'Connect Telegram'}
                  </button>
                  <button
                    type="button"
                    onClick={refreshTelegramStatus}
                    disabled={telegramLoading}
                    className="ml-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 disabled:opacity-60"
                  >
                    Refresh status
                  </button>
                </div>
              )}
              {telegramLinkError && <p className="mt-3 text-sm text-rose-600">{telegramLinkError}</p>}
            </section>

            <section className="mt-6 grid gap-6 lg:grid-cols-[1.5fr_1fr]">
              {currentUser.role === 'owner' && <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div className="mb-4 flex items-center justify-between">
                  <h2 className="text-lg font-semibold">Todayâ€™s operations</h2>
                  <span className="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">Live</span>
                </div>

                <div className="space-y-4">
                  <div className="flex items-center justify-between rounded-xl bg-slate-50 p-3">
                    <span className="text-sm text-slate-600">Revenue</span>
                    <span className="font-semibold">${Number(dashboard?.today_revenue || 0).toFixed(2)}</span>
                  </div>
                  <div className="flex items-center justify-between rounded-xl bg-slate-50 p-3">
                    <span className="text-sm text-slate-600">Expenses</span>
                    <span className="font-semibold">${Number(dashboard?.today_expenses || 0).toFixed(2)}</span>
                  </div>
                  <div className="flex items-center justify-between rounded-xl bg-slate-50 p-3">
                    <span className="text-sm text-slate-600">Waste</span>
                    <span className="font-semibold">${Number(dashboard?.waste_cost || 0).toFixed(2)}</span>
                  </div>
                  <div className="flex items-center justify-between rounded-xl bg-slate-50 p-3">
                    <span className="text-sm text-slate-600">Sales count</span>
                    <span className="font-semibold">{Number(dashboard?.number_of_sales || 0)}</span>
                  </div>
                </div>
              </div>}

              <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 className="mb-4 text-lg font-semibold">Products</h2>

                {currentUser.role === 'owner' && <><form onSubmit={handleProductSubmit} className="mb-4 space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                  <div className="grid grid-cols-2 gap-3">
                    <input
                      name="name"
                      value={productForm.name}
                      onChange={handleProductFieldChange}
                      placeholder="Product name"
                      className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-500"
                      required
                    />
                    <input
                      name="sku"
                      value={productForm.sku}
                      onChange={handleProductFieldChange}
                      placeholder="SKU"
                      className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-500"
                    />
                  </div>

                  <div className="grid grid-cols-3 gap-3">
                    <input
                      name="stock_quantity"
                      type="number"
                      min="0"
                      value={productForm.stock_quantity}
                      onChange={handleProductFieldChange}
                      placeholder="Stock"
                      className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-500"
                    />
                    <input
                      name="default_cost"
                      type="number"
                      min="0"
                      step="0.01"
                      value={productForm.default_cost}
                      onChange={handleProductFieldChange}
                      placeholder="Cost"
                      className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-500"
                    />
                    <input
                      name="selling_price"
                      type="number"
                      min="0"
                      step="0.01"
                      value={productForm.selling_price}
                      onChange={handleProductFieldChange}
                      placeholder="Sale price"
                      className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-500"
                    />
                  </div>

                  <div className="flex items-center gap-3">
                    <input
                      name="unit"
                      value={productForm.unit}
                      onChange={handleProductFieldChange}
                      placeholder="Unit (pcs, kg, bottle)"
                      className="flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-500"
                    />
                    <input
                      name="minimum_stock"
                      type="number"
                      min="0"
                      value={productForm.minimum_stock}
                      onChange={handleProductFieldChange}
                      placeholder="Min stock"
                      className="w-28 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-500"
                    />
                  </div>

                  <button
                    type="submit"
                    disabled={productLoading}
                    className="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
                  >
                    {productLoading ? 'Saving...' : 'Add product'}
                  </button>
                </form>

                <div className="mb-3 flex gap-2">
                  <button
                    type="button"
                    onClick={handleDemoSeed}
                    disabled={transactionLoading}
                    className="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-60"
                  >
                    {transactionLoading ? 'Loading...' : 'Load demo products'}
                  </button>
                </div>
                </>}

                <div className="space-y-2">
                  {products.length === 0 ? (
                    <p className="text-sm text-slate-500">No products yet.</p>
                  ) : (
                    products.map((product) => (
                      <div key={product.id} className="flex items-center justify-between rounded-xl bg-slate-50 p-3">
                        <div>
                          <p className="font-medium text-slate-800">{product.name}</p>
                          <p className="text-xs text-slate-500">Stock: {product.stock_quantity ?? 0}</p>
                        </div>
                        <span className="text-sm font-semibold text-emerald-600">
                          ${Number(product.selling_price || 0).toFixed(2)}
                        </span>
                      </div>
                    ))
                  )}
                </div>
              </div>
            </section>

            <section className="mt-6 grid gap-6 lg:grid-cols-2">
              <form onSubmit={handleQuickSale} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 className="mb-4 text-lg font-semibold">Quick sale</h3>
                <div className="space-y-3">
                  <select
                    value={saleForm.productId}
                    onChange={(event) => setSaleForm((current) => ({ ...current, productId: event.target.value }))}
                    className="w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm outline-none focus:border-emerald-500"
                  >
                    <option value="">Select product</option>
                    {products.map((product) => (
                      <option key={product.id} value={product.id}>{product.name}</option>
                    ))}
                  </select>
                  <div className="grid grid-cols-2 gap-3">
                    <input
                      type="number"
                      min="1"
                      value={saleForm.quantity}
                      onChange={(event) => setSaleForm((current) => ({ ...current, quantity: event.target.value }))}
                      placeholder="Qty"
                      className="rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm outline-none focus:border-emerald-500"
                    />
                    <input
                      type="number"
                      min="0"
                      step="0.01"
                      value={saleForm.unitPrice}
                      onChange={(event) => setSaleForm((current) => ({ ...current, unitPrice: event.target.value }))}
                      placeholder="Unit price"
                      className="rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm outline-none focus:border-emerald-500"
                    />
                  </div>
                  <button type="submit" className="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white">
                    Record quick sale
                  </button>
                </div>
              </form>

              <form onSubmit={handleQuickPurchase} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 className="mb-4 text-lg font-semibold">Quick purchase</h3>
                <div className="space-y-3">
                  <select
                    value={purchaseForm.productId}
                    onChange={(event) => setPurchaseForm((current) => ({ ...current, productId: event.target.value }))}
                    className="w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm outline-none focus:border-emerald-500"
                  >
                    <option value="">Select product</option>
                    {products.map((product) => (
                      <option key={product.id} value={product.id}>{product.name}</option>
                    ))}
                  </select>
                  <div className="grid grid-cols-2 gap-3">
                    <input
                      type="number"
                      min="1"
                      value={purchaseForm.quantity}
                      onChange={(event) => setPurchaseForm((current) => ({ ...current, quantity: event.target.value }))}
                      placeholder="Qty"
                      className="rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm outline-none focus:border-emerald-500"
                    />
                    <input
                      type="number"
                      min="0"
                      step="0.01"
                      value={purchaseForm.unitCost}
                      onChange={(event) => setPurchaseForm((current) => ({ ...current, unitCost: event.target.value }))}
                      placeholder="Unit cost"
                      className="rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm outline-none focus:border-emerald-500"
                    />
                  </div>
                  <button type="submit" className="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white">
                    Record quick purchase
                  </button>
                </div>
              </form>
            </section>
          </>
        )}
      </div>
    </div>
  );
}
