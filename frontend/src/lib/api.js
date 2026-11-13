const API_BASE = '/api';

export async function apiFetch(url, options = {}) {
  const token = sessionStorage.getItem('biztrack_token');
  const hasBody = options.body !== undefined && options.body !== null;
  const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;

  const headers = {
    Accept: 'application/json',
    ...(hasBody && !isFormData && !options.headers?.['Content-Type']
      ? { 'Content-Type': 'application/json' }
      : {}),
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...(options.headers || {}),
  };

  let response;
  try {
    response = await fetch(`${API_BASE}${url}`, { ...options, headers });
  } catch (failure) {
    if (failure.name === 'AbortError') throw failure;
    const error = new Error('The API could not be reached.');
    error.code = 'network_error';
    error.status = 0;
    throw error;
  }

  const contentType = response.headers.get('content-type') || '';
  let data = {};
  if (contentType.includes('application/json')) {
    try { data = await response.json(); }
    catch { const error = new Error('Invalid API response.'); error.status = response.status; error.code = 'invalid_response'; throw error; }
  } else if (response.ok) {
    const error = new Error('Expected a JSON API response.'); error.status = response.status; error.code = 'invalid_response'; throw error;
  }

  if (!response.ok) {
    if (response.status === 401 && token && sessionStorage.getItem('biztrack_token') === token) {
      clearToken();
      window.dispatchEvent(new Event('kottraluy:session-expired'));
    }
    if (response.status === 403 && data.code === 'password_change_required') window.dispatchEvent(new Event('kottraluy:password-required'));
    const error = new Error(response.status >= 500 ? 'The API service is unavailable.' : data.message || 'Request failed');
    error.status = response.status;
    error.code = data.code || (/invalid credentials/i.test(data.message || '') ? 'authentication_failed'
      : response.status === 403 ? 'forbidden' : response.status === 422 ? 'validation_failed' : 'http_error');
    error.errors = data.errors || {};
    throw error;
  }

  return data;
}

export async function apiDownload(url, options = {}) {
  const token = sessionStorage.getItem('biztrack_token');
  let response;
  try {
    response = await fetch(`${API_BASE}${url}`, { ...options, headers: { Accept: '*/*', ...(token ? { Authorization: `Bearer ${token}` } : {}), ...(options.headers || {}) } });
  } catch (failure) {
    if (failure.name === 'AbortError') throw failure;
    const error = new Error('The API could not be reached.'); error.code = 'network_error'; error.status = 0; throw error;
  }
  if (!response.ok) {
    let data = {};
    try { data = await response.json(); } catch { /* non-JSON server response */ }
    const error = new Error(response.status >= 500 ? 'The API service is unavailable.' : data.message || 'Download failed'); error.status = response.status; error.code = data.code || (response.status === 403 ? 'forbidden' : response.status === 422 ? 'validation_failed' : 'http_error'); error.errors = data.errors || {}; throw error;
  }
  return { blob: await response.blob(), filename: response.headers.get('content-disposition')?.match(/filename="?([^";]+)"?/i)?.[1] || null };
}

export function saveToken(token) {
  sessionStorage.setItem('biztrack_token', token);
}

export function clearToken() {
  sessionStorage.removeItem('biztrack_token');
}
