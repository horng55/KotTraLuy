import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseSaleTranscript } from '../src/lib/useSaleVoice.js';

const products = [{ id: 1, name: 'Milk', sku: 'MILK-1', selling_price: '3.50' }, { id: 2, name: 'Milk powder', selling_price: '8.00' }];

test('Khmer digits populate explicit quantity and zero price without inventing a product', () => {
  assert.deepEqual(parseSaleTranscript('ចំនួន ៣ តម្លៃ ០', products, 1), { quantity: '3', unitPrice: '0' });
});
test('ambiguous product names do not select a product or overwrite its saved price', () => {
  assert.deepEqual(parseSaleTranscript('Milk powder quantity 2', products, ''), { quantity: '2' });
});
test('recognized SKU uses the actual saved selling price', () => {
  assert.deepEqual(parseSaleTranscript('MILK-1 quantity 2', [products[0]], ''), { productId: '1', unitPrice: '3.50', quantity: '2' });
});
test('unrecognized speech and invalid fractional quantities do not fabricate field values', () => {
  assert.deepEqual(parseSaleTranscript('please record a sale', products, ''), {});
  assert.deepEqual(parseSaleTranscript('quantity 2.5', products, 1), {});
});
