import { t as tr } from '../lib/i18n';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Check, Monitor, Moon, Sun } from 'lucide-react';
import { useTheme } from '../lib/theme';

export default function ThemeSelector() {
  const { preference, setTheme } = useTheme();
  const [open, setOpen] = useState(false);
  const container = useRef(null);
  const trigger = useRef(null);
  const Icon = preference === 'system' ? Monitor : preference === 'dark' ? Moon : Sun;
  useEffect(() => {
    const outside = event => { if (!container.current?.contains(event.target)) setOpen(false); };
    document.addEventListener('pointerdown', outside);
    return () => document.removeEventListener('pointerdown', outside);
  }, []);
  useLayoutEffect(() => { if (open) container.current?.querySelector('[aria-checked="true"]')?.focus(); }, [open]);
  const showMenu = () => setOpen(true);
  const choose = mode => { setTheme(mode); setOpen(false); trigger.current?.focus(); };
  const keyDown = event => {
    if (event.key === 'Escape') { event.preventDefault(); setOpen(false); trigger.current?.focus(); return; }
    if (!['ArrowDown', 'ArrowUp', tr("common.home"), 'End'].includes(event.key)) return;
    event.preventDefault();
    if (!open) { showMenu(); return; }
    const items = [...container.current.querySelectorAll('[role="menuitemradio"]')];
    const index = items.indexOf(document.activeElement);
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : (index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
    items[next]?.focus();
  };
  return <div ref={container} className="theme-selector" onKeyDown={keyDown} onBlur={event => { if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false); }}>
    <button ref={trigger} className="theme-trigger" type="button" aria-label={tr("common.color_theme")} aria-haspopup="menu" aria-expanded={open} onClick={() => open ? setOpen(false) : showMenu()}><Icon size={18} aria-hidden="true" /></button>
    {open && <div className="theme-menu" role="menu" aria-label={tr("common.color_theme")}>{[['light', tr("common.light"), Sun], ['dark', tr("common.dark"), Moon], ['system', tr("common.system"), Monitor]].map(([mode, label, ModeIcon]) => <button key={mode} type="button" role="menuitemradio" aria-checked={preference === mode} tabIndex={-1} onClick={() => choose(mode)}><ModeIcon size={16} aria-hidden="true" /><span>{label}</span>{preference === mode && <Check size={14} aria-hidden="true" />}</button>)}</div>}
  </div>;
}
