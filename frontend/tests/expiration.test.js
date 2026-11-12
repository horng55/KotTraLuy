import test from 'node:test';
import assert from 'node:assert/strict';
import { text } from '../src/lib/i18n.js';
import { notificationText } from '../src/lib/notificationText.js';

test('expiration controls translate immediately in both directions without placeholder glyphs', () => {
  for (const label of ['Expiration Date','Expiring Soon','Expired Products','Expires Today','Batch Number','Days Remaining','Product Expiration Alerts','Dispose Stock','Disposal Quantity','Disposal Reason','View Batch','No Expiration','Safe','Status','Save record']) {
    const khmer = text(label,'km');
    assert.match(khmer, /[\u1780-\u17ff]/u, label);
    assert.doesNotMatch(khmer, /\?{2,}|\ufffd/u, label);
    assert.equal(text(label,'en'),label);
  }
});

test('persisted expiration event names and date-only values render in both languages', () => {
  for (const type of ['inventory.expired','inventory.expires_today','inventory.expiring_soon']) {
    for (const language of ['en','km']) {
      const result = notificationText({type,params:{product:'Milk 1L',quantity:3,unit:'bottles',expiry_date:'2026-11-12'}},language);
      assert.match(result.message,/Milk 1L/);
      assert.doesNotMatch(result.message,/\{\w+\}/);
      if(language==='km') assert.match(result.title,/[\u1780-\u17ff]/u);
    }
  }
});
