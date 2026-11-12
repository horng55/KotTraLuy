import { test } from 'node:test';
import assert from 'node:assert/strict';
import { t, text, messageText, domainLabel, setLanguage, getLanguage, subscribeLanguage, validateControl, clearControlValidation, formatDate, formatDateTime } from '../src/lib/i18n.js';
import { relativeTime, notificationCategories } from '../src/lib/notificationText.js';

test('Settings and shared labels switch both directions without a page reload', () => {
  let updates=0;
  const unsubscribe=subscribeLanguage(()=>updates++);
  for(const language of ['km','en','km','en']) {
    setLanguage(language);
    assert.equal(getLanguage(),language);
    for(const key of ['settings.workspace_control_center','settings.all_settings','settings.business_settings','documents.workspace_documents','team.team_management','financing.finance_options_2','financing.financing_preparation','common.user_management','settings.search_settings_tools_and_integrations_2']) {
      assert.doesNotMatch(t(key),/missing:/);
      if(language==='km')assert.match(t(key),/[\u1780-\u17ff]/u);
      else assert.match(t(key),/[a-z]/i);
    }
  }
  unsubscribe();assert.equal(updates,4);
});

test('preferences survive a fresh module load and work when storage is blocked', async () => {
  const previous=globalThis.localStorage;
  const stored=new Map();
  globalThis.localStorage={getItem:key=>stored.get(key),setItem:(key,value)=>stored.set(key,value)};
  setLanguage('km');
  const fresh=await import('../src/lib/i18n.js?persisted-preference');
  assert.equal(fresh.getLanguage(),'km');
  // Signing in changes the session token, not the existing language preference.
  assert.equal(stored.get('kottraluy-language'),'km');
  globalThis.localStorage={getItem(){throw Error('denied');},setItem(){throw Error('denied');}};
  assert.doesNotThrow(()=>setLanguage('en'));
  globalThis.localStorage=previous;
});

test('stored messages, errors and native validation follow the current language', () => {
  setLanguage('en');const storedError=text('This field is required.');
  setLanguage('km');assert.match(messageText(storedError),/[\u1780-\u17ff]/u);
  assert.match(messageText({status:401,code:'authentication_failed'}),/[\u1780-\u17ff]/u);
  assert.match(messageText({status:500,message:'SQL private error'}),/[\u1780-\u17ff]/u);
  assert.doesNotMatch(messageText({status:422,message:'Unknown private backend text'}),/private|backend/i);
  const control={validity:{valueMissing:true},setCustomValidity(value){this.message=value;}};
  validateControl({target:control});assert.match(control.message,/[\u1780-\u17ff]/u);
  setLanguage('en');validateControl(control);assert.equal(control.message,t('validation.required'));
  clearControlValidation({target:control});assert.equal(control.message,'');
});

test('interpolation and pluralization preserve original business names', () => {
  for(const language of ['km','en']) {
    const name='Milk 1L · ហាងសុខ';
    assert.ok(t('common.activate_person',{name},language).includes(name));
    assert.equal(domainLabel(name),name);
  }
  assert.equal(t('notifications.relative.minute.past',{count:1},'en'),'1 minute ago');
  assert.equal(t('notifications.relative.minute.past',{count:3},'en'),'3 minutes ago');
  assert.match(relativeTime(new Date(Date.now()-120000).toISOString(),'km'),/[\u1780-\u17ff]/u);
  assert.deepEqual(notificationCategories,['sales','inventory','expenses','team','financing','system']);
});

test('missing keys are visible and logged once instead of silently falling back to English', () => {
  const original=console.error,messages=[];console.error=message=>messages.push(message);
  try {
    assert.equal(t('test.missing_translation',{},'km'),'[missing:km:test.missing_translation]');
    t('test.missing_translation',{},'km');assert.equal(messages.length,1);
  }finally{console.error=original;setLanguage('en');}
});

test('Khmer calendar dates and notification times do not depend on browser Khmer ICU support', () => {
  assert.equal(formatDate('2026-11-12','km'),'12 វិច្ឆិកា 2026');
  assert.match(formatDateTime('2026-11-12T10:00:00','km'),/វិច្ឆិកា 2026 ម៉ោង/);
  assert.doesNotMatch(formatDateTime('2026-11-12T10:00:00','km'),/AM|PM|missing/);
});
