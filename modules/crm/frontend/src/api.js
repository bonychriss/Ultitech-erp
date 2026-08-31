function apiBase() {
  if (typeof window !== 'undefined' && window.__CRM_API_BASE__) {
    return String(window.__CRM_API_BASE__).replace(/\/$/, '');
  }
  return '/modules/crm/api/index.php';
}

function withModule(url) {
  const module = new URLSearchParams(window.location.search).get('module');
  if (!module) return url;
  const sep = url.includes('?') ? '&' : '?';
  return `${url}${sep}module=${encodeURIComponent(module)}`;
}

export function getBootData() {
  return (typeof window !== 'undefined' && window.__CRM_BOOT__) || {};
}

export function buildContactViewUrl(contactId, links = {}) {
  const base = links.contactViewBase || '/modules/crm/my-clients/view.php?module=crm';
  const sep = base.includes('?') ? '&' : '?';
  return `${base}${sep}id=${encodeURIComponent(String(contactId))}`;
}

async function request(url, options = {}) {
  const res = await fetch(withModule(url), {
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(options.headers || {}),
    },
    ...options,
  });
  const data = await res.json().catch(() => null);
  if (!res.ok || !data?.success) {
    throw new Error(data?.message || `Request failed (${res.status})`);
  }
  return data.data;
}

export async function fetchContacts(search = '', status = 'all') {
  const params = new URLSearchParams({ action: 'list' });
  if (search) params.set('search', search);
  if (status && status !== 'all') params.set('status', status);
  return request(`${apiBase()}?${params.toString()}`);
}

export async function fetchContact(id) {
  const params = new URLSearchParams({ action: 'get', id: String(id) });
  return request(`${apiBase()}?${params.toString()}`);
}

export async function createContact(payload) {
  return request(`${apiBase()}?action=create`, {
    method: 'POST',
    body: JSON.stringify({ action: 'create', ...payload }),
  });
}

export async function updateContact(id, payload) {
  return request(`${apiBase()}?action=update`, {
    method: 'POST',
    body: JSON.stringify({ action: 'update', id, ...payload }),
  });
}

export async function deleteContact(id) {
  return request(`${apiBase()}?action=delete`, {
    method: 'POST',
    body: JSON.stringify({ action: 'delete', id }),
  });
}

export async function fetchProspects(search = '') {
  const params = new URLSearchParams({ action: 'prospects' });
  if (search) params.set('search', search);
  params.set('limit', '500');
  return request(`${apiBase()}?${params.toString()}`);
}

export async function fetchMarketHistory() {
  return request(`${apiBase()}?action=market_history`);
}

export async function fetchMarketHistoryResults(id, mine = false) {
  const params = new URLSearchParams({ action: 'market_history_results', id: String(id) });
  if (mine) params.set('mine', '1');
  return request(`${apiBase()}?${params.toString()}`);
}

export async function deleteMarketHistory(id) {
  return request(`${apiBase()}?action=market_history_delete`, {
    method: 'POST',
    body: JSON.stringify({ action: 'market_history_delete', id }),
  });
}

export async function downloadMarketHistoryPdf(id, mine = false) {
  const params = new URLSearchParams({ action: 'market_history_pdf', id: String(id) });
  if (mine) params.set('mine', '1');
  const res = await fetch(withModule(`${apiBase()}?${params.toString()}`), {
    credentials: 'same-origin',
    headers: {
      Accept: 'application/pdf',
      'X-Requested-With': 'XMLHttpRequest',
    },
  });
  const type = String(res.headers.get('content-type') || '');
  if (!res.ok || type.includes('application/json')) {
    const data = await res.json().catch(() => null);
    throw new Error(data?.message || `PDF download failed (${res.status})`);
  }
  const blob = await res.blob();
  const disposition = String(res.headers.get('content-disposition') || '');
  const match = disposition.match(/filename="?([^"]+)"?/i);
  const filename = match ? match[1] : 'saved-search.pdf';
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename.endsWith('.pdf') ? filename : `${filename}.pdf`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

export async function fetchMarketSuggest(q, location = 'Tanzania') {
  const params = new URLSearchParams({ action: 'market_suggest', q: String(q || ''), location: String(location || '') });
  return request(`${apiBase()}?${params.toString()}`);
}

export async function runMarketSearch(q, location = 'Tanzania') {
  return request(`${apiBase()}?action=market_search`, {
    method: 'POST',
    body: JSON.stringify({ action: 'market_search', q, location }),
  });
}

export async function fetchMarketLeads(search = '', mine = false) {
  const params = new URLSearchParams({ action: 'market_leads' });
  if (search) params.set('search', search);
  if (mine) params.set('mine', '1');
  return request(`${apiBase()}?${params.toString()}`);
}

export async function importMarketLead(id, form = null) {
  const payload = form && typeof form === 'object'
    ? { action: 'market_import', id, ...form }
    : { action: 'market_import', id };
  return request(`${apiBase()}?action=market_import`, {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export async function importMarketLeadsBulk(ids) {
  return request(`${apiBase()}?action=market_import_bulk`, {
    method: 'POST',
    body: JSON.stringify({ action: 'market_import_bulk', ids }),
  });
}

export async function fetchMarketMessage() {
  return request(`${apiBase()}?action=market_message_get`);
}

export async function saveMarketMessage(payload) {
  return request(`${apiBase()}?action=market_message_save`, {
    method: 'POST',
    body: JSON.stringify({ action: 'market_message_save', ...payload }),
  });
}

export async function fetchMarketSettings() {
  return request(`${apiBase()}?action=market_settings_get`);
}

export async function fetchMarketStatus() {
  return request(`${apiBase()}?action=market_status`);
}

export async function fetchMarketAttribution(mine = true) {
  const params = new URLSearchParams({ action: 'market_attribution' });
  if (mine) params.set('mine', '1');
  return request(`${apiBase()}?${params.toString()}`);
}

export async function saveMarketSettings(payload) {
  return request(`${apiBase()}?action=market_settings_save`, {
    method: 'POST',
    body: JSON.stringify({ action: 'market_settings_save', ...payload }),
  });
}

export async function testMarketSettings(key = '') {
  return request(`${apiBase()}?action=market_settings_test`, {
    method: 'POST',
    body: JSON.stringify({ action: 'market_settings_test', key: String(key || '') }),
  });
}
