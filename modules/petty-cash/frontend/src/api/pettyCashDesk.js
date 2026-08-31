function getApiBase() {
  if (typeof window !== 'undefined' && window.__PETTY_CASH_API_BASE__) {
    return String(window.__PETTY_CASH_API_BASE__).replace(/\/$/, '');
  }
  return './api';
}

function getCompanySlug() {
  if (typeof window === 'undefined') return '';
  const fromCfg = window.__PETTY_CASH_CFG__ && typeof window.__PETTY_CASH_CFG__ === 'object'
    ? String(window.__PETTY_CASH_CFG__.company_slug || '').trim()
    : '';
  if (fromCfg) return fromCfg;
  try {
    return String(new URLSearchParams(window.location.search).get('company_slug') || '').trim();
  } catch {
    return '';
  }
}

function apiUrl(path, params = {}) {
  const base = getApiBase();
  const cleanPath = String(path || '').replace(/^\/+/, '');
  const url = new URL(`${base}/${cleanPath}`, window.location.href);
  Object.entries(params).forEach(([k, v]) => {
    if (v !== '' && v != null) url.searchParams.set(k, String(v));
  });
  const slug = getCompanySlug();
  if (slug && !url.searchParams.get('company_slug')) {
    url.searchParams.set('company_slug', slug);
  }
  return `${url.pathname}${url.search}${url.hash}`;
}

function getModuleBase() {
  if (typeof window !== 'undefined' && window.__PETTY_CASH_CFG__ && typeof window.__PETTY_CASH_CFG__ === 'object') {
    return String(window.__PETTY_CASH_CFG__.module_base || '').replace(/\/$/, '');
  }
  return '';
}

async function parseJson(response) {
  const text = await response.text();
  try {
    return JSON.parse(text);
  } catch {
    const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 160);
    throw new Error(
      snippet.startsWith('<!')
        ? 'API returned HTML instead of JSON. Check that you are still logged in.'
        : snippet || 'Invalid API response.',
    );
  }
}

export async function fetchDeskInit() {
  const res = await fetch(apiUrl('desk-init.php'), { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

export async function fetchDeskVouchers(filters = {}) {
  const res = await fetch(apiUrl('vouchers.php', filters), { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

export async function fetchDeskReplenishments() {
  const res = await fetch(apiUrl('replenishments.php'), { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

export async function postVoucherAction(action, id, extra = {}) {
  const res = await fetch(apiUrl('vouchers.php'), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ action, id, ...extra }),
  });
  const data = await parseJson(res);
  if (!res.ok || data.error) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

export async function fetchCreateInit() {
  const res = await fetch(apiUrl('create-init.php'), { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

export async function submitCreateVoucher(formData) {
  const res = await fetch(apiUrl('create-voucher.php'), {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
  });
  const data = await parseJson(res);
  if (!res.ok || data.error) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

export async function fetchReplenishmentInit() {
  const res = await fetch(apiUrl('replenishment-init.php'), { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

export async function submitReplenishmentRequest(payload) {
  const res = await fetch(apiUrl('replenishment-request.php'), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await parseJson(res);
  if (!res.ok || data.error) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

export async function fetchVoucherView(id) {
  const res = await fetch(apiUrl('voucher-view.php', { id }), { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

export async function fetchReports(params = {}) {
  const res = await fetch(apiUrl('reports.php', params), { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

export async function fetchReplenishmentsList(params = {}) {
  const res = await fetch(apiUrl('replenishments.php', params), { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

export async function postReplenishmentAction(action, id, extra = {}) {
  const res = await fetch(apiUrl('replenishments.php'), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ action, id, ...extra }),
  });
  const data = await parseJson(res);
  if (!res.ok || data.error) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

export async function fetchReplenishmentDetail(repId, viewOnly = false) {
  const params = { rep_id: String(repId) };
  if (viewOnly) params.view = '1';
  const res = await fetch(apiUrl('replenishment-detail.php', params), { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

export async function approveReplenishmentDetail(repId) {
  const res = await fetch(apiUrl('replenishment-detail.php'), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ rep_id: repId }),
  });
  const data = await parseJson(res);
  if (!res.ok || data.error) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

export async function fetchCategories() {
  const res = await fetch(apiUrl('categories.php'), { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

export async function createCategory(name) {
  const res = await fetch(apiUrl('categories.php'), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ name }),
  });
  const data = await parseJson(res);
  if (!res.ok || data.error) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

/** @param {string} file e.g. index.php or vouchers/index.php */
export function deskPageUrl(file, extraParams = {}) {
  const params = new URLSearchParams({ module: 'petty_cash', ...extraParams });
  const slug = getCompanySlug();
  if (slug && !params.get('company_slug')) {
    params.set('company_slug', slug);
  }
  const cleanFile = String(file || '').replace(/^\.\//, '').replace(/^\/+/, '');
  const cfgBase = getModuleBase();
  if (cfgBase) {
    return `${cfgBase}/${cleanFile}?${params.toString()}`;
  }

  const path = String(window.location.pathname || '');
  const marker = '/modules/petty-cash/';
  const idx = path.indexOf(marker);
  if (idx !== -1) {
    return `${path.slice(0, idx + marker.length)}${cleanFile}?${params.toString()}`;
  }

  return `./${cleanFile}?${params.toString()}`;
}
