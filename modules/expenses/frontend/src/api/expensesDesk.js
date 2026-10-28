function getApiBase() {
  if (typeof window !== 'undefined' && window.__EXPENSES_API_BASE__) {
    return String(window.__EXPENSES_API_BASE__).replace(/\/$/, '');
  }
  return './api';
}

export function getDeskMode() {
  if (typeof window !== 'undefined' && window.__EXPENSES_DESK_MODE__) {
    return String(window.__EXPENSES_DESK_MODE__).trim().toLowerCase();
  }
  return 'expenses';
}

export function isPurchasesDesk() {
  return getDeskMode() === 'purchases';
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
        : snippet === ''
          ? 'API returned an empty response.'
          : `Invalid API response: ${snippet}`,
    );
  }
}

export async function fetchDeskInit() {
  const params = new URLSearchParams();
  if (isPurchasesDesk()) params.set('desk', 'purchases');
  const qs = params.toString();
  const res = await fetch(`${getApiBase()}/desk-init.php${qs ? `?${qs}` : ''}`);
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function fetchDeskExpenses(filters = {}) {
  const params = new URLSearchParams();
  if (isPurchasesDesk()) {
    params.set('desk', 'purchases');
    params.set('purpose', 'stock_purchase');
  }
  if (filters.search) params.set('search', filters.search);
  if (filters.status) params.set('status', filters.status);
  if (filters.category) params.set('category', filters.category);
  if (filters.date_from) params.set('date_from', filters.date_from);
  if (filters.date_to) params.set('date_to', filters.date_to);
  if (filters.payment_method) params.set('payment_method', filters.payment_method);
  if (filters.amount_min) params.set('amount_min', filters.amount_min);
  if (filters.amount_max) params.set('amount_max', filters.amount_max);
  if (String(filters.only_unpaid || '') === '1') params.set('only_unpaid', '1');

  const qs = params.toString();
  const url = `${getApiBase()}/expenses.php${qs ? `?${qs}` : ''}`;
  const res = await fetch(url);
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export function deskPageUrl(file, extraParams = {}) {
  const module = isPurchasesDesk() ? 'purchases' : 'expenses';
  const params = new URLSearchParams({ module, ...extraParams });
  if (isPurchasesDesk()) {
    // Purchases list lives at modules/purchases; create/import stay on expenses if ever used.
    if (String(file || '').toLowerCase() === 'index.php') {
      return `../purchases/index.php?${params.toString()}`;
    }
  }
  return `./${file}?${params.toString()}`;
}

export function purchasesCreateVoucherUrl() {
  if (typeof window !== 'undefined' && window.__PURCHASES_CREATE_VOUCHER_URL__) {
    return String(window.__PURCHASES_CREATE_VOUCHER_URL__);
  }
  return '../../employee/create-voucher.php?module=voucher&purpose=stock_purchase';
}

/**
 * Full payment-voucher detail URL (POs + attachments live on view-voucher).
 */
export async function fetchPurchasesVoucherPreview(pvId) {
  const id = Number(pvId || 0);
  if (id <= 0) throw new Error('Missing voucher id.');
  const res = await fetch(`${getApiBase()}/voucher-preview.php?pv_id=${encodeURIComponent(String(id))}`);
  const data = await parseJson(res);
  if (!res.ok || !data.ok) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export function purchasesVoucherViewUrl(expense) {
  if (!expense || typeof expense !== 'object') return '';
  const boot = typeof expense.voucher_url === 'string' ? expense.voucher_url.trim() : '';
  const withFrom = (url) => {
    if (!url) return '';
    try {
      const u = new URL(url, window.location.href);
      u.searchParams.set('from', 'purchases');
      u.searchParams.set('module', 'purchases');
      return u.pathname + u.search + u.hash;
    } catch {
      const join = String(url).includes('?') ? '&' : '?';
      return `${url}${join}from=purchases&module=purchases`;
    }
  };
  if (boot !== '') return withFrom(boot);
  const pvId = Number(expense.pv_id || 0);
  if (pvId <= 0) return '';
  return withFrom(`../../employee/view-voucher.php?id=${pvId}`);
}

export function exportUrl(filters = {}) {
  const params = new URLSearchParams({ module: 'expenses' });
  if (filters.search) params.set('search', filters.search);
  if (filters.status) params.set('status', filters.status);
  if (filters.category) params.set('category', filters.category);
  if (filters.date_from) params.set('date_from', filters.date_from);
  if (filters.date_to) params.set('date_to', filters.date_to);
  if (filters.payment_method) params.set('payment_method', filters.payment_method);
  if (filters.amount_min) params.set('amount_min', filters.amount_min);
  if (filters.amount_max) params.set('amount_max', filters.amount_max);
  if (String(filters.only_unpaid || '') === '1') params.set('only_unpaid', '1');
  return `./export.php?${params.toString()}`;
}

export async function fetchCreateInit() {
  const res = await fetch(`${getApiBase()}/create-init.php`);
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function fetchEditInit(id) {
  const res = await fetch(`${getApiBase()}/edit-init.php?id=${encodeURIComponent(id)}`);
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function fetchExchangeRate(currency) {
  const code = encodeURIComponent(currency || 'TZS');
  const res = await fetch(`${getApiBase()}/exchange_rate.php?currency=${code}`);
  return parseJson(res);
}

export async function fetchKpiAiConfirmation(key, { listedCount = 0, filters = {} } = {}) {
  const params = new URLSearchParams({ key, ai: '1' });
  const res = await fetch(`${getApiBase()}/kpi-confirm.php?${params.toString()}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ listedCount, filters }),
  });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function fetchInsightsStats(month = '') {
  const params = new URLSearchParams();
  if (month) params.set('month', month);
  const qs = params.toString();
  const res = await fetch(`${getApiBase()}/stats.php${qs ? `?${qs}` : ''}`);
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function submitCreateExpense(formData) {
  const res = await fetch(`${getApiBase()}/create-expense.php`, {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
  });
  const data = await parseJson(res);
  if (!res.ok || data.error || data.ok === false) {
    const message = data.error
      || (Array.isArray(data.errors) ? data.errors.join(' ') : '')
      || `Request failed (${res.status})`;
    throw new Error(message);
  }
  return data;
}

/** Best-effort draft save when the user leaves without posting (tab close, navigation). */
export function submitCreateExpenseDraftOnLeave(formData) {
  const url = `${getApiBase()}/create-expense.php`;
  if (typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
    if (navigator.sendBeacon(url, formData)) {
      return true;
    }
  }
  if (typeof fetch === 'function') {
    void fetch(url, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      keepalive: true,
    });
    return true;
  }
  return false;
}

export function submitUpdateExpenseDraftOnLeave(formData) {
  const url = `${getApiBase()}/update-expense.php`;
  if (typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
    if (navigator.sendBeacon(url, formData)) {
      return true;
    }
  }
  if (typeof fetch === 'function') {
    void fetch(url, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      keepalive: true,
    });
    return true;
  }
  return false;
}

export async function submitUpdateExpense(formData) {
  const res = await fetch(`${getApiBase()}/update-expense.php`, {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
  });
  const data = await parseJson(res);
  if (!res.ok || data.error || data.ok === false) {
    const message = data.error
      || (Array.isArray(data.errors) ? data.errors.join(' ') : '')
      || `Request failed (${res.status})`;
    throw new Error(message);
  }
  return data;
}

export async function deleteDraftExpense(id, csrfToken) {
  const formData = new FormData();
  formData.append('id', String(id));
  formData.append('csrf_token', csrfToken);
  const res = await fetch(`${getApiBase()}/delete-draft.php`, {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
  });
  const data = await parseJson(res);
  if (!res.ok || data.error || data.ok === false) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function postDraftExpense(id, csrfToken) {
  const res = await fetch(`${getApiBase()}/post-draft.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({
      id,
      csrf_token: csrfToken,
    }),
  });
  const data = await parseJson(res);
  if (!res.ok || data.error || data.ok === false) {
    throw new Error(data.error || data.errors?.[0] || `Request failed (${res.status})`);
  }
  return data;
}

export async function fetchPostDraftPreview(id) {
  const res = await fetch(`${getApiBase()}/post-draft-preview.php?id=${encodeURIComponent(id)}`, {
    credentials: 'same-origin',
  });
  const data = await parseJson(res);
  if (!res.ok || data.error || data.ok === false) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export function importTemplateUrl() {
  return `${getApiBase()}/import-template.php`;
}

export async function fetchImportInit() {
  const res = await fetch(`${getApiBase()}/import-init.php`);
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function previewImportFile(file, { csrfToken, defaultYear }) {
  const formData = new FormData();
  formData.append('file', file);
  formData.append('csrf_token', csrfToken);
  formData.append('default_year', String(defaultYear));
  const res = await fetch(`${getApiBase()}/import-preview.php`, {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
  });
  const data = await parseJson(res);
  if (!res.ok || data.error || data.ok === false) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function classifyImportRows({ csrfToken, rows }) {
  const res = await fetch(`${getApiBase()}/import-classify.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({
      csrf_token: csrfToken,
      rows,
    }),
  });
  const data = await parseJson(res);
  if (!res.ok || data.error || data.ok === false) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function commitImportRows(payload) {
  const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
  const timeoutMs = 90000;
  const timer = controller
    ? setTimeout(() => controller.abort(), timeoutMs)
    : null;
  try {
    const res = await fetch(`${getApiBase()}/import-commit.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ ...payload, post_to_ledger: false }),
      signal: controller?.signal,
    });
    const data = await parseJson(res);
    if (!res.ok || data.error || data.ok === false) {
      throw new Error(data.error || `Request failed (${res.status})`);
    }
    return data;
  } catch (err) {
    if (err?.name === 'AbortError') {
      throw new Error('Import timed out. Try again with fewer rows, or check the server.');
    }
    throw err;
  } finally {
    if (timer) clearTimeout(timer);
  }
}
