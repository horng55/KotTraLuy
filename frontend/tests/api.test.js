import { test } from 'node:test';
import assert from 'node:assert/strict';
import { apiFetch } from '../src/lib/api.js';

test('API failures are classified safely and stale unauthorized responses cannot end a newer session', async t => {
  const original = { fetch: globalThis.fetch, sessionStorage: globalThis.sessionStorage, window: globalThis.window };
  const values = new Map([['biztrack_token', 'test-session']]);
  globalThis.sessionStorage = { getItem: key => values.get(key) ?? null, removeItem: key => values.delete(key) };
  globalThis.window = new EventTarget();
  t.after(() => { Object.assign(globalThis, original); });
  globalThis.fetch = async () => { throw new TypeError('Failed to fetch'); };
  await assert.rejects(apiFetch('/reports/financial'), e => e.code === 'network_error' && e.status === 0);
  globalThis.fetch = async () => new Response(JSON.stringify({ message: 'private server trace' }), { status: 500, headers: { 'Content-Type': 'application/json' } });
  await assert.rejects(apiFetch('/reports/financial'), e => e.status === 500 && !e.message.includes('private'));
  globalThis.fetch = async () => new Response('<html>wrong service</html>', { headers: { 'Content-Type': 'text/html' } });
  await assert.rejects(apiFetch('/reports/financial'), e => e.code === 'invalid_response');
  let expired = 0;
  window.addEventListener('kottraluy:session-expired', () => expired++);
  globalThis.fetch = async () => {
    values.set('biztrack_token', 'new-session');
    return new Response('{}', { status: 401, headers: { 'Content-Type': 'application/json' } });
  };
  await assert.rejects(apiFetch('/reports/financial'));
  assert.equal(expired, 0);
  assert.equal(values.get('biztrack_token'), 'new-session');
  globalThis.fetch = async () => new Response('{}', { status: 401, headers: { 'Content-Type': 'application/json' } });
  await assert.rejects(apiFetch('/reports/financial'));
  assert.equal(expired, 1);
  assert.equal(values.has('biztrack_token'), false);
});
