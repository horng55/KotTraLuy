import { test } from 'node:test';
import assert from 'node:assert/strict';
import { validateFinancialResponse, validateReportParameters } from '../src/lib/financialReports.js';

test('date validation rejects missing, reversed, malformed and impossible dates', () => {
  assert.equal(validateReportParameters({from:'',to:'2026-11-11',currency:'USD'}).from,"common.enter_a_valid_from_date");
  assert.ok(validateReportParameters({from:'2026-11-12',to:'2026-11-11',currency:'USD'}).to);
  assert.ok(validateReportParameters({from:'2026-02-30',to:'2026-11-11',currency:'USD'}).from);
  assert.ok(validateReportParameters({from:'11/01/2026',to:'2026-11-11',currency:'USD'}).from);
  assert.deepEqual(validateReportParameters({from:'2024-02-29',to:'2024-02-29',currency:'KHR'}),{});
});
test('only the supported reporting currencies are accepted', () => {
  assert.ok(validateReportParameters({from:'2026-01-01',to:'2026-11-11',currency:'EUR'}).currency);
  assert.deepEqual(validateReportParameters({from:'2026-01-01',to:'2026-11-11',currency:'USD'}),{});
});
test('a response for another period or currency cannot be displayed as the requested report', () => {
  const parameters = {from:'2026-01-01',to:'2026-11-11',currency:'USD'};
  const response = {...parameters,business:{name:'Validation fixture'},generated_at:'2026-11-11T12:00:00Z',profit_and_loss:{},balance_sheet:null,cash_flow:null};
  assert.equal(validateFinancialResponse(response,parameters),response);
  assert.throws(()=>validateFinancialResponse({...response,currency:'KHR'},parameters));
  assert.throws(()=>validateFinancialResponse({...response,from:'2026-02-01'},parameters));
  assert.throws(()=>validateFinancialResponse({},parameters));
});
