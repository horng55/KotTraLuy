import { test } from 'node:test';
import assert from 'node:assert/strict';
import { pdfPageSlices } from '../src/lib/reportPdf.js';
import { telegramDownloadFile } from '../src/lib/telegram.js';

test('PDF pagination keeps rows intact and covers the entire report', () => {
  assert.deepEqual(pdfPageSlices(2500, 1000, [380, 780, 1200, 1680, 2100]), [
    { top: 0, height: 780 }, { top: 780, height: 900 }, { top: 1680, height: 820 },
  ]);
  assert.deepEqual(pdfPageSlices(2300, 1000), [
    { top: 0, height: 1000 }, { top: 1000, height: 1000 }, { top: 2000, height: 300 },
  ]);
});

test('Telegram uses native HTTPS downloads on supported clients and external links on older ones', t => {
  const original = globalThis.window;
  globalThis.window = { location: { href: 'https://example.com/app' } };
  t.after(() => { globalThis.window = original; });
  const calls = [];
  const webApp = { initData: 'signed', version: '8.0', downloadFile: params => calls.push(params), openLink: url => calls.push(url) };
  assert.equal(telegramDownloadFile('/api/reports/pdf-download/temporary', 'report.pdf', webApp), true);
  assert.deepEqual(calls[0], { url: 'https://example.com/api/reports/pdf-download/temporary', file_name: 'report.pdf' });
  webApp.version = '7.10';
  assert.equal(telegramDownloadFile('/file', 'report.pdf', webApp), true);
  assert.equal(calls[1], 'https://example.com/file');
  assert.equal(telegramDownloadFile('blob:https://example.com/private', 'report.pdf', webApp), false);
  assert.equal(telegramDownloadFile('/file', 'report.pdf', { ...webApp, initData: '' }), false);
});
