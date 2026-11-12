import { messageText } from '../lib/i18n';
import { useRef, useState } from 'react';
import { apiFetch } from '../lib/api';
import { uiText } from '../lib/workspaceUI';
import { AppDialog, FormError, FormField, NumberInput, PrimaryButton, SecondaryButton, TextInput } from './FormUI';

export default function ProductDialog({ product, close, onSaved, onManageBatches, language = 'en', currency = 'USD' }) {
  const t = key => uiText(key, language); const editing = Boolean(product);
  const [form, setForm] = useState({ expiry_date:'', batch_code:'', name: product?.name || '', sku: product?.sku || '', unit: product?.unit || 'pcs', selling_price: product?.selling_price ?? '', default_cost: product?.default_cost ?? '', minimum_stock: product?.minimum_stock ?? 0, stock_quantity: product?.stock_quantity ?? 0 });
  const [saving, setSaving] = useState(false); const [error, setError] = useState(''); const [errors, setErrors] = useState({}); const busy = useRef(false); const confirmed = useRef(false);
  const update = (name, value) => { setForm(current => ({ ...current, [name]: value })); setErrors(current => ({ ...current, [name]: '' })); };
  const save = async event => {
    event.preventDefault(); if (busy.current || confirmed.current) return;
    const invalid = {};
    if (!form.name.trim()) invalid.name = t('required');
    for (const name of ['selling_price','default_cost','minimum_stock',...(!editing ? ['stock_quantity'] : [])]) {
      if (!Number.isFinite(Number(form[name])) || Number(form[name]) < 0 || (['minimum_stock','stock_quantity'].includes(name) && !Number.isInteger(Number(form[name])))) invalid[name] = t('validAmount');
    }
    setErrors(invalid); if (Object.keys(invalid).length) return;
    busy.current = true; setSaving(true); setError('');
    try { await apiFetch(editing ? `/products/${product.id}` : '/products', { method: editing ? 'PATCH' : 'POST', body: JSON.stringify({ ...form, name: form.name.trim(), selling_price: Number(form.selling_price || 0), default_cost: Number(form.default_cost || 0), minimum_stock: Number(form.minimum_stock), ...(editing ? { stock_quantity: undefined } : { stock_quantity: Number(form.stock_quantity) }) }) }); confirmed.current = true; }
    catch (failure) { setError(failure); setErrors(Object.fromEntries(Object.entries(failure.errors || {}).map(([key,messages]) => [key,{status:422,errors:messages}]))); }
    finally { setSaving(false); busy.current = false; }
    if (confirmed.current) await onSaved();
  };
  const field = (name, label, numeric = false, options = {}) => { const Input = numeric ? NumberInput : TextInput; return <FormField label={label} error={errors[name]}><Input value={form[name]} onChange={event=>update(name,event.target.value)} disabled={saving} {...(numeric ? {min:0,step:['minimum_stock','stock_quantity'].includes(name)?1:0.01} : {})} {...options} /></FormField>; };
  return <AppDialog title={t(editing ? 'editProduct' : 'addProduct')} section={t('stock')} onClose={close} busy={saving} language={language} footer={<div className="kt-footer-actions">{editing && <SecondaryButton disabled={saving} onClick={onManageBatches}>{t('batches')}</SecondaryButton>}<SecondaryButton disabled={saving} onClick={close}>{t('cancel')}</SecondaryButton><PrimaryButton type="submit" form="product-form" disabled={saving}>{t(saving?'saving':'saveProduct')}</PrimaryButton></div>}><form id="product-form" className="kt-form" onSubmit={save} noValidate><FormError>{messageText(error)}</FormError>{field('name',t('name'),false,{'data-autofocus':true,placeholder:t('name'),required:true})}<div className="kt-two-columns">{field('sku',t('sku'),false,{placeholder:t('optional')})}{field('unit',t('unit'))}</div><div className="kt-two-columns">{field('selling_price',`${t('unitPrice')} (${currency})`,true)}{field('default_cost',`${t('cost')} (${currency})`,true)}</div><div className="kt-two-columns">{field('minimum_stock',t('minimum'),true)}{!editing && field('stock_quantity',t('opening'),true)}</div>{!editing && <div className="kt-two-columns">{field('expiry_date',t("expiration.expiration_date"),false,{type:'date'})}{field('batch_code',t("expiration.batch_number"),false,{maxLength:100})}</div>}</form></AppDialog>;
}
