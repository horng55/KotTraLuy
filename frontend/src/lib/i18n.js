// Shared translation API used by the application and workspace forms.
import en from '../locales/en.json' with { type: 'json' };
import km from '../locales/km.json' with { type: 'json' };
import sourceKeys from '../locales/sourceKeys.json' with { type: 'json' };
export const LANGUAGE_PREFERENCE_KEY = 'kottraluy-language';
const normalizeLanguage = value => value === 'km' ? 'km' : 'en';
let currentLanguage = 'en';
try { currentLanguage = normalizeLanguage(globalThis.localStorage?.getItem(LANGUAGE_PREFERENCE_KEY)); } catch { /* Preferences work in memory when storage is unavailable. */ }
const languageListeners = new Set();
const reportedMissing = new Set();
const reverseMessages = new Map(Object.entries(km).map(([key,value]) => [value,key]));
const has = (object,key) => Object.hasOwn(object,key);
export const getLanguage = () => currentLanguage;
export const getLocale = (language = currentLanguage) => language === 'km' ? 'km-KH' : 'en-US';
export function setLanguage(language) {
  currentLanguage = normalizeLanguage(language);
  try { globalThis.localStorage?.setItem(LANGUAGE_PREFERENCE_KEY, currentLanguage); } catch { /* Retain in-memory selection. */ }
  applyLanguage();
  languageListeners.forEach(listener => listener(currentLanguage));
}
function applyLanguage() {
  if (typeof document === 'undefined') return;
  document.documentElement.lang = currentLanguage;
  document.title = t('common.app_title');
  document.querySelectorAll('input,select,textarea').forEach(control => { if (control.validity.customError) validateControl(control); });
}
if (typeof window !== 'undefined') {
  applyLanguage();
  window.addEventListener('storage', event => {
    if (event.key !== LANGUAGE_PREFERENCE_KEY && event.key !== null) return;
    currentLanguage = normalizeLanguage(event.newValue); applyLanguage();
    languageListeners.forEach(listener => listener(currentLanguage));
  });
}
export function subscribeLanguage(listener) { languageListeners.add(listener); return () => languageListeners.delete(listener); }
function missing(key, language) {
  const id = `${language}:${key}`;
  if (!reportedMissing.has(id)) { reportedMissing.add(id); console.error(`[i18n] Missing translation: ${id}`); }
  return `[missing:${id}]`;
}
export function t(key, params = {}, language = currentLanguage) {
  const dictionary = language === 'km' ? km : en;
  const pluralKey = typeof params.count === 'number' ? `${key}_${new Intl.PluralRules(getLocale(language)).select(params.count)}` : key;
  const resolved = has(dictionary,pluralKey) ? pluralKey : key;
  if (!has(dictionary,resolved)) return missing(key,language);
  return dictionary[resolved].replace(/\{(\w+)\}/g, (match, name) => params[name] ?? match);
}
export function text(value, language = currentLanguage) {
  if (value == null) return '';
  const literal = String(value);
  if (has(en,literal)) return t(literal,{},language);
  const key = sourceKeys[literal];
  if (key) return t(key, {}, language);
  if (literal === 'en' || literal === 'km') return literal;
  if (!literal.trim() || /^(?:USD|KHR|KotTraLuy|Telegram|English|EN|ខ្មែរ|[\d\s.,%+−—·/():-]+)$/.test(literal)) return literal;
  return missing(literal,language);
}
// Only enums from the application are localized here; original business records stay intact.
const domainKeys = { unknown:'common.unknown', official_source_reviewed:'common.official_source_reviewed',
  requirements_need_confirmation:'common.requirements_need_confirmation', source_requires_manual_confirmation:'common.manual_confirmation_needed' };
export const domainLabel = value => domainKeys[value] ? t(domainKeys[value]) : sourceKeys[value] || has(en,value) ? text(value) : String(value ?? '');
const errorCodes = {
  network_error:'validation.network', invalid_response:'validation.invalid_response', api_unavailable:'validation.server',
  password_change_required:'validation.password_required', unauthenticated:'validation.authentication',
  authentication_failed:'auth.invalid_credentials', forbidden:'validation.permission',
  validation_failed:'validation.invalid', insufficient_stock:'expiration.insufficient_stock',
  invalid_product_image:'productImage.invalidFile', product_save_failed:'validation.server',
};
export function messageText(value, language = currentLanguage) {
  if (!value) return '';
  if (typeof value === 'string') {
    if (has(en,value) || sourceKeys[value]) return text(value,language);
    const key = reverseMessages.get(value);
    if (key) return t(key,{},language);
    if (/credentials|password is incorrect|password is invalid/i.test(value)) return t('auth.invalid_credentials',{},language);
    if (/field is required/i.test(value)) return t('validation.required',{},language);
    if (/valid email/i.test(value)) return t('validation.email',{},language);
    if (/has already been taken/i.test(value)) return t('validation.already_taken',{},language);
    if (/confirmation does not match/i.test(value)) return t('validation.confirmation',{},language);
    if (/must be at least (\d+) characters/i.test(value)) return t('validation.too_short',{min:value.match(/at least (\d+)/i)[1]},language);
    return t('validation.request_failed',{},language);
  }
  if (value.key && has(en,value.key)) return t(value.key,value.params || {},language);
  const mapped = errorCodes[value.code];
  if (mapped) return t(mapped,{},language);
  if (value.status === 401) return t('validation.authentication',{},language);
  if (value.status === 403) return t('validation.authorization',{},language);
  if (value.status === 404) return t('validation.not_found',{},language);
  if (value.status === 429) return t('validation.rate_limit',{},language);
  if (value.status >= 500) return t('validation.server',{},language);
  const errors = value.errors ? Object.values(value.errors).flat() : [];
  if (errors.length) return errors.map(error => messageText(error,language)).join(' ');
  return messageText(value.message || 'validation.request_failed',language);
}
export function clearControlValidation(event) { (event?.target || event)?.setCustomValidity?.(''); }
export function validateControl(event) {
  const control = event?.target || event;
  if (!control?.validity) return;
  control.setCustomValidity('');
  if (control.validity.valueMissing) control.setCustomValidity(t('validation.required'));
  else if (control.validity.typeMismatch) control.setCustomValidity(t('validation.type_mismatch'));
  else if (control.validity.tooShort) control.setCustomValidity(t('validation.too_short',{min:control.minLength}));
  else if (control.validity.rangeUnderflow) control.setCustomValidity(t('validation.minimum',{min:control.min}));
  else if (control.validity.rangeOverflow) control.setCustomValidity(t('validation.maximum',{max:control.max}));
  else if (!control.validity.valid) control.setCustomValidity(t('validation.invalid'));
}
export function formatDate(value, language = currentLanguage) {
  if (!value) return '—';
  const date = /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T12:00:00`) : new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  return language === 'km' ? t('common.calendar_date',{day:date.getDate(),month:t(`common.month.${date.getMonth()+1}`,{},language),year:date.getFullYear()},language) : date.toLocaleDateString(getLocale(language));
}
export function formatDateTime(value, language = currentLanguage) {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : language === 'km' ? t('common.date_time',{date:formatDate(value,language),time:formatTime(value,language)},language) : date.toLocaleString(getLocale(language));
}
export const formatTime = (value, language = currentLanguage) => new Date(value).toLocaleTimeString(getLocale(language),{hour:'2-digit',minute:'2-digit',hourCycle:'h23'});
