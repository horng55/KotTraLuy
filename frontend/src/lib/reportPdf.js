import { apiFetch } from './api.js';
import { isTelegramMiniApp, telegramDownloadFile } from './telegram.js';

// Prefer table-row boundaries so a financial amount is never cut across pages.
export function pdfPageSlices(height, pageHeight, boundaries = []) {
  const pages = [];
  let top = 0;
  while (top < height) {
    const limit = Math.min(height, top + pageHeight);
    const candidates = boundaries.filter(value => value > top + pageHeight / 2 && value <= limit);
    const bottom = limit < height && candidates.length ? Math.max(...candidates) : limit;
    pages.push({ top, height: bottom - top });
    top = bottom;
  }
  return pages;
}

export async function createReportPdf(element, section = 'all') {
  const [{ default: html2canvas }, { jsPDF }] = await Promise.all([import('html2canvas'), import('jspdf')]);
  await document.fonts?.ready;
  const host = document.createElement('div');
  host.lang = element.closest('[lang]')?.lang || document.documentElement.lang;
  host.className = 'financial-page pdf-export';
  host.style.cssText = 'position:absolute;left:-10000px;top:0;width:794px;padding:24px;box-sizing:border-box;background:#fff;color:#17251f;';
  const clone = element.cloneNode(true);
  clone.querySelectorAll('button').forEach(button => button.remove());
  clone.querySelectorAll('[data-statement]').forEach(statement => {
    if (section !== 'all' && statement.dataset.statement !== section) statement.remove();
  });
  host.appendChild(clone);
  const style = document.createElement('style');
  style.textContent = '.pdf-export *{color:#17251f!important;background-color:transparent!important;box-shadow:none!important;overflow:visible!important;animation:none!important}.pdf-export{--border:#cdd8d1;--fp-border:#cdd8d1;--text-primary:#17251f;--text-secondary:#52635a}.pdf-export .fp-statements{display:block}.pdf-export .fp-statement{margin:18px 0}.pdf-export table{width:100%;min-width:0!important}.pdf-export .fp-report-identity{display:flex;justify-content:space-between}.pdf-export .fp-coverage{display:grid;grid-template-columns:1fr 1fr}';
  host.appendChild(style);
  document.body.appendChild(host);
  try {
    const pdf = new jsPDF({ unit: 'mm', format: 'a4' });
    const width = 190;
    const pageHeight = Math.floor(277 * host.offsetWidth / width);
    const origin = host.getBoundingClientRect().top;
    const boundaries = [...host.querySelectorAll('tr, .fp-report-identity, .fp-report-warning, li, .fp-statement > header')]
      .map(row => Math.ceil(row.getBoundingClientRect().bottom - origin));
    const pages = pdfPageSlices(host.scrollHeight, pageHeight, boundaries);
    for (const [index, slice] of pages.entries()) {
      // Render one page at a time to stay within mobile canvas memory limits.
      const canvas = await html2canvas(host, { backgroundColor: '#ffffff', scale: 1.5, logging: false,
        width: host.offsetWidth, height: slice.height, y: slice.top, windowWidth: window.innerWidth, scrollY: 0 });
      // Keep a stable A4 footer on every page. Draw it on the browser canvas
      // so Khmer text uses the loaded application font instead of jsPDF's
      // limited built-in glyph set.
      const context = canvas.getContext('2d');
      if (context) {
        const isKhmer = host.lang === 'km';
        context.save(); context.font = `11px ${isKhmer ? '"Noto Sans Khmer", ' : ''}sans-serif`;
        context.fillStyle = '#52635a'; context.textBaseline = 'bottom';
        context.fillText(isKhmer ? 'KotTraLuy · របាយការណ៍ព្រាង · មិនបានធ្វើសវនកម្ម' : 'KotTraLuy · Management-prepared draft · Not audited', 20, canvas.height - 12);
        context.restore();
      }
      if (index) pdf.addPage();
      pdf.addImage(canvas.toDataURL('image/jpeg', 0.92), 'JPEG', 10, 10, width, slice.height * width / host.offsetWidth);
      canvas.width = 0; canvas.height = 0;
    }
    return pdf.output('blob');
  } finally { host.remove(); }
}

export async function saveReportPdf(blob, filename) {
  if (isTelegramMiniApp()) {
    const body = new FormData();
    body.append('file', blob, filename);
    body.append('filename', filename);
    const { url } = await apiFetch('/reports/pdf-download', { method: 'POST', body });
    if (!telegramDownloadFile(url, filename)) throw new Error('PDF download unavailable.');
    return;
  }
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url; link.download = filename; link.rel = 'noopener';
  document.body.appendChild(link); link.click(); link.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 60000);
}
