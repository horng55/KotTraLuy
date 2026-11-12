import { messageText } from '../lib/i18n';
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Check, Mic, Plus, ShoppingCart, Trash2, WalletCards } from 'lucide-react';
import { apiFetch } from '../lib/api';
import { formatMoney, uiText } from '../lib/workspaceUI';
import useSaleVoice from '../lib/useSaleVoice';
import { AppDialog, FormError, FormField, NumberInput, PrimaryButton, ProductSelect, SecondaryButton, TextInput } from './FormUI';

export default function QuickActionsPanel({ compact = false, onSaved, language = 'en', currency = 'USD' }) {
  const [products, setProducts] = useState([]); const [mode, setMode] = useState(''); const [voiceOnOpen, setVoiceOnOpen] = useState(false);
  const [loading, setLoading] = useState(false); const [error, setError] = useState(''); const [message, setMessage] = useState('');
  const t = key => uiText(key, language);
  useEffect(() => { if (!message) return; const timer = setTimeout(() => setMessage(''), 6000); return () => clearTimeout(timer); }, [message]);
  const load = async () => { setLoading(true); setError(''); try { setProducts(await apiFetch('/products')); } catch (failure) { setError(failure); } finally { setLoading(false); } };
  useEffect(() => { const controller = new AbortController(); apiFetch('/products', { signal: controller.signal }).then(setProducts).catch(failure => { if (!controller.signal.aborted) setError(failure); }); return () => controller.abort(); }, []);
  const open = (next, voice = false) => { setMode(next); setVoiceOnOpen(voice); setMessage(''); load(); };
  return <section className={`panel quick-actions-panel ${compact ? 'compact' : ''}`} lang={language}><div className="panel-heading">{!compact && <h3>{t('save')}</h3>}</div>{compact ? <><div className="primary-action-row"><button className="button primary" onClick={() => open('sale')}><ShoppingCart size={15} />{t('saleTitle')}</button><button className="voice-button" onClick={() => open('sale', true)}><Mic size={15} />{t('voice')}</button></div><div className="secondary-action-row"><button className="button secondary" onClick={() => open('stock')}><Plus size={15} />{t('stockIn')}</button><button className="button secondary" onClick={() => open('expense')}><WalletCards size={15} />{t('expense')}</button></div></> : <div className="quick-action-grid">{[['stock',Plus,'stockIn'],['sale',ShoppingCart,'saleTitle'],['expense',WalletCards,'expense'],['waste',Trash2,'waste']].map(([key,Icon,label]) => <button key={key} className="button secondary" onClick={() => open(key)}><Icon size={15} />{t(label)}</button>)}</div>}{message && createPortal(<div className="kt-success kt-toast" role="status" lang={language}><Check size={16} />{messageText(message)}</div>, document.body)}{mode && <ActivityDialog key={mode} mode={mode} products={mode === 'sale' ? products.map(p=>({...p, on_hand_quantity:p.stock_quantity,stock_quantity:p.sellable_quantity ?? p.stock_quantity})) : products} loading={loading} loadError={messageText(error)} retry={load} language={language} currency={currency} autoVoice={voiceOnOpen} close={() => setMode('')} onSaved={async () => { setMode(''); setMessage(t('saved')); await onSaved?.(); }} />}</section>;
}

export function ActivityDialog({ mode = 'sale', products, loading, loadError, retry, language = 'en', currency = 'USD', autoVoice, initialProductId = '', close, onSaved }) {
  const t = key => uiText(key, language); const formId = `activity-${mode}`;
  const [form, setForm] = useState(() => { const selected = products.find(p => String(p.id) === String(initialProductId)); return { productId: String(initialProductId), quantity: '1', unitPrice: selected?.selling_price ?? '', payment: '0', notes: '', batchCode: '', date: new Date().toISOString().slice(0,10), expiryDate: '', unitCost: selected?.default_cost ?? '', reason: '', description: '', category: 'Operations' }; });
  const [errors, setErrors] = useState({}); const [error, setError] = useState(''); const [saving, setSaving] = useState(false);
  const busy = useRef(false); const key = useRef(`web-${crypto.randomUUID()}`); const voiceStarted = useRef(false); const confirmed = useRef(false);
  const update = (field, value) => { setForm(current => ({ ...current, [field]: value })); setErrors(current => ({ ...current, [field]: '' })); };
  const product = products.find(p => String(p.id) === String(form.productId));
  const voice = useSaleVoice({ products, selectedId: form.productId, onRecognized: changes => { if (!busy.current) setForm(current => ({ ...current, ...changes })); } });
  useEffect(() => {
    if (!autoVoice || voiceStarted.current) return;
    const timer = setTimeout(() => { if (!voiceStarted.current) { voiceStarted.current = true; voice.start(); } }, 0);
    return () => clearTimeout(timer);
  }, [autoVoice, voice]);
  const total = Number(form.quantity || 0) * Number(form.unitPrice || 0);
  const selectProduct = id => { const selected = products.find(p => String(p.id) === id); setForm(current => ({ ...current, productId: id, unitPrice: String(selected?.selling_price ?? ''), unitCost: String(selected?.default_cost ?? '') })); setErrors(current => ({ ...current, productId: '' })); };
  const submit = async event => {
    event.preventDefault(); if (busy.current || confirmed.current) return;
    const problems = {};
    if (mode !== 'expense' && !product) problems.productId = t('required');
    if (mode !== 'expense' && (!Number.isInteger(Number(form.quantity)) || Number(form.quantity) < 1)) problems.quantity = t('validQuantity');
    if (mode === 'sale' && product && Number(form.quantity) > Number(product.stock_quantity)) problems.quantity = t(Number(product.sellable_quantity ?? product.stock_quantity)===0 && Number(product.on_hand_quantity ?? product.stock_quantity)>0 ? 'Only expired stock remains. Dispose of it or add a new batch before selling.' : 'stockLimit');
    if (['sale','expense'].includes(mode) && (form.unitPrice === '' || !Number.isFinite(Number(form.unitPrice)) || Number(form.unitPrice) < 0)) problems.unitPrice = t('validAmount');
    if (mode === 'sale' && (!Number.isFinite(Number(form.payment)) || Number(form.payment) < 0 || Number(form.payment) > total)) problems.payment = t('paymentLimit');
    if (mode === 'stock' && (form.unitCost === '' || Number(form.unitCost) < 0)) problems.unitCost = t('validAmount');
    if (mode === 'expense' && !form.description.trim()) problems.description = t('required');
    if (mode === 'waste' && !form.reason.trim()) problems.reason = t('required');
    setErrors(problems); if (Object.keys(problems).length) return;
    busy.current = true; setSaving(true); setError('');
    const payload = mode === 'sale' ? { sold_at: new Date().toISOString(), currency, idempotency_key: key.current, notes: form.notes, payment_received: Number(form.payment || 0), items: [{ product_id: Number(form.productId), quantity: Number(form.quantity), unit_price: Number(form.unitPrice) }] } : mode === 'stock' ? { purchase_date: form.date, currency, notes: form.notes, items: [{ product_id: Number(form.productId), quantity: Number(form.quantity), unit_cost: Number(form.unitCost), batch_code: form.batchCode || undefined, received_date: form.date, expiry_date: form.expiryDate || undefined }] } : mode === 'expense' ? { category: form.category, description: form.description, amount: Number(form.unitPrice), currency, expense_date: form.date, notes: form.notes } : { product_id: Number(form.productId), quantity: Number(form.quantity), reason: form.reason };
    try {
      await apiFetch({sale:'/sales',stock:'/purchases',expense:'/expenses',waste:'/waste'}[mode], { method: 'POST', body: JSON.stringify(payload) }); confirmed.current = true;
    } catch (failure) {
      setError(failure);
      const mapping = { 'items.0.product_id':'productId', 'items.0.quantity':'quantity', 'items.0.unit_price':'unitPrice', 'items.0.unit_cost':'unitCost', 'items.0.expiry_date':'expiryDate', 'payment_received':'payment', 'product_id':'productId', 'quantity':'quantity', 'amount':'unitPrice', 'description':'description', 'reason':'reason', 'expense_date':'date', 'purchase_date':'date' };
      setErrors(Object.fromEntries(Object.entries(failure.errors || {}).map(([field, messages]) => [mapping[field] || field, {status:422,errors:messages}])));
    } finally { busy.current = false; setSaving(false); }
    if (confirmed.current) await onSaved();
  };
  const field = (name,label,options={}) => <FormField label={label} error={errors[name]}><NumberInput min="0" step="0.01" value={form[name]} onChange={event => update(name,event.target.value)} disabled={saving} {...options} /></FormField>;
  return <AppDialog title={t({sale:'saleTitle',stock:'stockIn',expense:'expense',waste:'waste'}[mode])} section={t(mode === 'sale' ? 'sales' : 'stock')} description={mode === 'sale' ? t('saleDescription') : undefined} language={language} onClose={close} busy={saving} footer={<>{mode === 'sale' && <SecondaryButton disabled={saving || ['listening','processing'].includes(voice.state)} onClick={voice.start}><Mic size={16} />{t('voice')}</SecondaryButton>}<div className="kt-footer-actions"><SecondaryButton disabled={saving} onClick={close}>{t('cancel')}</SecondaryButton><PrimaryButton type="submit" form={formId} disabled={saving || (mode !== 'expense' && (loading || !!loadError || !products.length))}>{saving ? t('saving') : t(mode === 'sale' ? 'saveSale' : 'save')}</PrimaryButton></div></>}>
    <form id={formId} className="kt-form" onSubmit={submit} noValidate>
      <FormError>{messageText(error)}</FormError>
      {mode === 'sale' && voice.state === 'error' && <div className="kt-error" role="alert"><strong>{t('voiceError')}</strong><p>{t('voiceErrorDescription')}</p>{!voice.available && <p>{t('voiceUnavailable')}</p>}<div className="kt-error-actions"><SecondaryButton onClick={voice.start}>{t('retry')}</SecondaryButton><SecondaryButton onClick={voice.dismiss}>{t('manual')}</SecondaryButton></div></div>}
      {mode === 'sale' && ['listening','processing','success'].includes(voice.state) && <div className="kt-voice-state" role="status">{voice.state === 'listening' ? <span className="kt-voice-dot" /> : voice.state === 'success' ? <Check size={16} /> : <Mic size={16} />}<div><strong>{t({listening:'listening',processing:'processing',success:'voiceSuccess'}[voice.state])}</strong>{voice.transcript && <p>{voice.transcript}</p>}</div>{voice.state === 'listening' && <SecondaryButton onClick={voice.stop}>{t('stop')}</SecondaryButton>}</div>}
      {mode !== 'expense' && <><FormField label={t('product')} error={errors.productId} hint={loading ? t('loading') : !products.length ? t('emptyDescription') : undefined}><ProductSelect products={products.filter(p=>p.is_active !== false)} value={form.productId} onChange={selectProduct} placeholder={t('selectProduct')} emptyLabel={t('noMatches')} stockLabel={t("common.available_2")} disabled={saving || loading} /></FormField>{loadError && <FormError>{messageText(loadError)} <SecondaryButton onClick={retry}>{t('retry')}</SecondaryButton></FormError>}</>}
      {mode === 'sale' ? <><div className="kt-two-columns">{field('quantity',`${t('quantity')}${product?.unit ? ` (${product.unit})` : ''}`,{min:1,step:1,inputMode:'numeric'})}{field('unitPrice',`${t('unitPrice')} (${currency})`,{placeholder:'0.00'})}</div><div className="kt-summary"><span>{t('total')}</span><strong>{formatMoney(Number.isFinite(total) ? total : 0,currency,language)}</strong><small>{t('outstanding')}</small><small>{formatMoney(Math.max(0,total-Number(form.payment||0)),currency,language)}</small></div><section><h3 className="kt-subsection">{t('payment')}</h3>{field('payment',`${t('received')} (${currency})`,{max:total})}</section></> : mode !== 'expense' && field('quantity',t('quantity'),{min:1,step:1,inputMode:'numeric'})}
      {mode === 'stock' && <><div className="kt-two-columns">{field('unitCost',`${t('cost')} (${currency})`)}<FormField label={t('batch')}><TextInput placeholder={t('optional')} value={form.batchCode} onChange={event=>update('batchCode',event.target.value)} disabled={saving} /></FormField></div><div className="kt-two-columns"><FormField label={t('date')} error={errors.date}><TextInput type="date" value={form.date} onChange={event=>update('date',event.target.value)} disabled={saving} required /></FormField><FormField label={t('expiry')} error={errors.expiryDate}><TextInput type="date" value={form.expiryDate} onChange={event=>update('expiryDate',event.target.value)} disabled={saving} /></FormField></div></>}
      {mode === 'expense' && <>{field('unitPrice',`${t('amount')} (${currency})`)}<FormField label={t('description')} error={errors.description}><TextInput value={form.description} onChange={event=>update('description',event.target.value)} disabled={saving} /></FormField><div className="kt-two-columns"><FormField label={t('category')}><TextInput value={form.category} onChange={event=>update('category',event.target.value)} disabled={saving} /></FormField><FormField label={t('date')} error={errors.date}><TextInput type="date" value={form.date} onChange={event=>update('date',event.target.value)} disabled={saving} /></FormField></div></>}
      {mode === 'waste' ? <FormField label={t('reason')} error={errors.reason}><TextInput value={form.reason} onChange={event=>update('reason',event.target.value)} disabled={saving} /></FormField> : <details className="kt-notes"><summary>{t('notes')}</summary><FormField label={t('notes')}><textarea className="kt-input" rows={2} placeholder={t('notesPlaceholder')} value={form.notes} onChange={event=>update('notes',event.target.value)} disabled={saving} /></FormField></details>}
    </form>
  </AppDialog>;
}
