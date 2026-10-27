const API_BASE = '/api';

export async function apiFetch(url, options = {}) {
  const token = sessionStorage.getItem('biztrack_token');
  const hasBody = options.body !== undefined && options.body !== null;

  const headers = {
    Accept: 'application/json',
    ...(hasBody && !options.headers?.['Content-Type']
      ? { 'Content-Type': 'application/json' }
      : {}),
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...(options.headers || {}),
  };

  const response = await fetch(`${API_BASE}${url}`, {
    ...options,
    headers,
  });

  const contentType = response.headers.get('content-type') || '';
  const data = contentType.includes('application/json') ? await response.json() : {};

  if (!response.ok) {
    throw new Error(data.message || 'Request failed');
  }

  return data;
}

export function saveToken(token) {
  sessionStorage.setItem('biztrack_token', token);
}

export function clearToken() {
  sessionStorage.removeItem('biztrack_token');
}
