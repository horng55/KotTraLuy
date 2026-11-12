import { useSyncExternalStore } from 'react';

const modes = new Set(['light', 'dark', 'system']);
const media = window.matchMedia('(prefers-color-scheme: dark)');
function apply(preference) {
  const mode = modes.has(preference) ? preference : 'system';
  const root = document.documentElement;
  root.dataset.themePreference = mode;
  const telegramTheme = root.dataset.telegramColorScheme;
  root.dataset.theme = mode === 'system'
    ? (telegramTheme === 'dark' || telegramTheme === 'light' ? telegramTheme : window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
    : mode;
  root.style.colorScheme = root.dataset.theme;
}
export function setTheme(preference) {
  apply(preference);
  try { localStorage.setItem('kottraluy-theme', document.documentElement.dataset.themePreference); } catch { /* Theme switching works when storage is restricted. */ }
  window.dispatchEvent(new Event('kottraluy-theme-change'));
}
function subscribe(listener) {
  const systemChanged = () => { apply(document.documentElement.dataset.themePreference); listener(); };
  const stored = event => { if (event.key === 'kottraluy-theme' || event.key === null) { apply(event.newValue || 'system'); listener(); } };
  window.addEventListener('kottraluy-theme-change', listener);
  window.addEventListener('storage', stored);
  window.addEventListener('focus', systemChanged);
  document.addEventListener('visibilitychange', systemChanged);
  media.addEventListener('change', systemChanged);
  return () => { window.removeEventListener('kottraluy-theme-change', listener); window.removeEventListener('storage', stored); window.removeEventListener('focus', systemChanged); document.removeEventListener('visibilitychange', systemChanged); media.removeEventListener('change', systemChanged); };
}
function snapshot() { return `${document.documentElement.dataset.themePreference || 'system'}:${document.documentElement.dataset.theme || 'light'}`; }
export function useTheme() {
  const value = useSyncExternalStore(subscribe, snapshot);
  const [preference, resolved] = value.split(':');
  return { preference, resolved, setTheme };
}
