import { useEffect, useRef, useState } from 'react';

export function parseSaleTranscript(transcript, products, selectedId) {
  const normalized = transcript.replace(/[០-៩]/g, digit => String('០១២៣៤៥៦៧៨៩'.indexOf(digit))).toLowerCase();
  const matches = products.filter(p => normalized.includes(p.name.toLowerCase()) || (p.sku && normalized.includes(p.sku.toLowerCase())));
  const product = matches.length === 1 ? matches[0] : products.find(p => String(p.id) === String(selectedId));
  const changes = matches.length === 1 ? { productId: String(product.id), unitPrice: String(product.selling_price ?? '') } : {};
  const quantity = normalized.match(/(?:quantity|qty|ចំនួន)\s*[:=]?\s*(\d+)(?![\d.])/);
  const price = normalized.match(/(?:price|តម្លៃ)\s*[:=]?\s*(\d+(?:\.\d+)?)/);
  if (quantity && Number(quantity[1]) >= 1) changes.quantity = quantity[1];
  if (price && product) changes.unitPrice = price[1];
  return changes;
}

export default function useSaleVoice({ products, selectedId, onRecognized }) {
  const [voice, setVoice] = useState({ state: 'idle', transcript: '' });
  const recognition = useRef(null); const latest = useRef({ products, selectedId, onRecognized });
  useEffect(() => { latest.current = { products, selectedId, onRecognized }; });
  useEffect(() => () => { if (recognition.current) { recognition.current.onresult = null; recognition.current.onerror = null; recognition.current.onend = null; recognition.current.abort(); recognition.current = null; } }, []);
  const available = Boolean(window.SpeechRecognition || window.webkitSpeechRecognition);
  const start = () => {
    const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!Recognition) { setVoice({ state: 'error', transcript: '' }); return; }
    if (recognition.current) return;
    const instance = new Recognition(); recognition.current = instance;
    instance.lang = 'km-KH'; instance.interimResults = false; instance.continuous = false;
    let handled = false;
    instance.onstart = () => setVoice({ state: 'listening', transcript: '' });
    instance.onresult = event => {
      const transcript = event.results?.[0]?.[0]?.transcript?.trim(); handled = true;
      if (!transcript) { setVoice({ state: 'error', transcript: '' }); return; }
      const current = latest.current;
      current.onRecognized(parseSaleTranscript(transcript, current.products, current.selectedId));
      setVoice({ state: 'success', transcript });
    };
    instance.onerror = () => { handled = true; setVoice({ state: 'error', transcript: '' }); recognition.current = null; };
    instance.onend = () => { recognition.current = null; if (!handled) setVoice({ state: 'error', transcript: '' }); };
    try { setVoice({ state: 'listening', transcript: '' }); instance.start(); } catch { recognition.current = null; setVoice({ state: 'error', transcript: '' }); }
  };
  const stop = () => { if (recognition.current) { setVoice(current => ({ ...current, state: 'processing' })); recognition.current.stop(); } };
  return { ...voice, available, start, stop, dismiss: () => setVoice({ state: 'idle', transcript: '' }) };
}
