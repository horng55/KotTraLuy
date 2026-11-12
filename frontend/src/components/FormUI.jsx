import { t as tr, messageText } from '../lib/i18n';
import { cloneElement, useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { ChevronDown, Package, Search, X } from 'lucide-react';

export function AppDialog({ title, section, description, onClose, busy, children, footer, language = 'en' }) {
  const titleId = useId();
  const dialog = useRef(null);
  const close = useRef(onClose);
  const working = useRef(busy);
  useEffect(() => { close.current = onClose; working.current = busy; });
  useEffect(() => {
    const previous = document.activeElement;
    const overflow = document.body.style.overflow;
    const htmlOverflow = document.documentElement.style.overflow;
    document.body.style.overflow = 'hidden';
    document.documentElement.style.overflow = 'hidden';
    const node = dialog.current;
    const focusable = () => [...node.querySelectorAll('button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex="0"]')].filter(el => el.getClientRects().length);
    (node.querySelector('[data-autofocus]:not(:disabled)') || focusable()[0] || node).focus();
    const keydown = event => {
      if (event.key === 'Escape') {
        const picker = event.target.closest('.kt-product-select');
        if (picker?.querySelector('[aria-expanded="true"]')) return;
        event.preventDefault(); event.stopPropagation(); if (!working.current) close.current();
      }
      if (event.key === 'Tab') {
        const controls = focusable(); const first = controls[0]; const last = controls.at(-1);
        if (!first) { event.preventDefault(); node.focus(); }
        else if (event.shiftKey && (document.activeElement === first || !node.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && (document.activeElement === last || !node.contains(document.activeElement))) { event.preventDefault(); first.focus(); }
      }
    };
    document.addEventListener('keydown', keydown, true);
    const root = document.getElementById('root'); const oldInert = root?.inert;
    if (root) root.inert = true;
    return () => { document.body.style.overflow = overflow; document.documentElement.style.overflow = htmlOverflow; document.removeEventListener('keydown', keydown, true); if (root) root.inert = oldInert; if (previous?.isConnected) previous.focus(); };
  }, []);
  return createPortal(<div className="kt-dialog-overlay" lang={language}><section ref={dialog} className="kt-dialog" role="dialog" aria-modal="true" aria-labelledby={titleId} aria-busy={busy} tabIndex={-1}><header className="kt-dialog-header"><div><p className="kt-kicker">{section}</p><h2 id={titleId}>{title}</h2>{description && <p className="kt-description">{description}</p>}</div><button type="button" className="kt-icon-button" aria-label={language === 'km' ? 'បិទ' : tr("common.close_dialog")} disabled={busy} onClick={onClose}><X size={19} /></button></header><div className="kt-dialog-body">{children}</div><div className="kt-dialog-footer">{footer}</div></section></div>, document.body);
}

export function FormField({ label, hint, error, children }) {
  const id = useId();
  return <div className="kt-field"><label htmlFor={id}>{label}</label>{cloneElement(children, { id, 'aria-invalid': error ? true : undefined, 'aria-describedby': error || hint ? `${id}-help` : undefined })}{(error || hint) && <p id={`${id}-help`} className={error ? 'kt-field-error' : 'kt-field-hint'}>{error ? messageText(error) : hint}</p>}</div>;
}
export function TextInput(props) { return <input {...props} className={`kt-input ${props.className || ''}`} />; }
export function NumberInput(props) { return <TextInput type="number" inputMode="decimal" {...props} />; }
export function PrimaryButton(props) { return <button type="button" {...props} className={`kt-button kt-primary ${props.className || ''}`} />; }
export function SecondaryButton(props) { return <button type="button" {...props} className={`kt-button kt-secondary ${props.className || ''}`} />; }
export function FormError({ children }) { return children ? <div className="kt-error" role="alert">{children}</div> : null; }
export function EmptyState({ title, description, children }) { return <div className="kt-empty"><span><Package size={24} /></span><h3>{title}</h3><p>{description}</p>{children}</div>; }

export function ProductSelect({ products, value, onChange, placeholder, emptyLabel, stockLabel, disabled, id, ...accessibility }) {
  const [open, setOpen] = useState(false); const [query, setQuery] = useState('');
  const container = useRef(null); const trigger = useRef(null); const search = useRef(null); const listId = useId();
  const selected = products.find(p => String(p.id) === String(value));
  const matches = products.filter(p => `${p.name} ${p.sku || ''}`.toLowerCase().includes(query.toLowerCase()));
  useEffect(() => { if (open) search.current?.focus(); }, [open]);
  useEffect(() => { const outside = event => { if (!container.current?.contains(event.target)) setOpen(false); }; document.addEventListener('pointerdown', outside); return () => document.removeEventListener('pointerdown', outside); }, []);
  const select = product => { onChange(String(product.id)); setOpen(false); setQuery(''); trigger.current?.focus(); };
  return <div className="kt-product-select" ref={container} onKeyDown={event => { if (event.key === 'Escape' && open) { event.preventDefault(); event.stopPropagation(); setOpen(false); trigger.current?.focus(); } if (event.key === 'ArrowDown' && !open) { event.preventDefault(); setOpen(true); } }}><button {...accessibility} id={id} data-autofocus ref={trigger} type="button" className="kt-input kt-select-trigger" aria-haspopup="listbox" aria-controls={listId} aria-expanded={open} disabled={disabled} onClick={() => setOpen(!open)}><span>{selected ? selected.name : placeholder}</span><ChevronDown size={16} /></button>{open && <div className="kt-select-menu"><div className="kt-search"><Search size={15} /><input ref={search} value={query} placeholder={placeholder} aria-label={placeholder} onChange={event => setQuery(event.target.value)} onKeyDown={event => { if (event.key === 'ArrowDown') { event.preventDefault(); container.current.querySelector('[role=option]')?.focus(); } if (event.key === 'Enter') { event.preventDefault(); if (matches.length === 1) select(matches[0]); } }} /></div><div id={listId} role="listbox" aria-label={placeholder}>{matches.length ? matches.map((p, index) => <button type="button" role="option" aria-selected={String(value) === String(p.id)} key={p.id} onClick={() => select(p)} onKeyDown={event => { if (['ArrowDown', 'ArrowUp'].includes(event.key)) { event.preventDefault(); const options = container.current.querySelectorAll('[role=option]'); options[(index + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length].focus(); } }}><strong>{p.name}</strong><small>{p.stock_quantity} {p.unit || ''} · {stockLabel}</small></button>) : <p className="kt-field-hint">{emptyLabel}</p>}</div></div>}</div>;
}
