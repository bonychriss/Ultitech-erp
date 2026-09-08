function getApiBase() {
  if (typeof window !== 'undefined' && window.__INVOICES_API_BASE__) {
    return String(window.__INVOICES_API_BASE__).replace(/\/$/, '');
  }
  return './api';
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

function createInitQueryParams() {
  const params = new URLSearchParams(window.location.search);
  const docFromWindow = typeof window !== 'undefined' ? String(window.__INVOICES_DOCUMENT_TYPE__ || '').toLowerCase() : '';
  if (!params.has('document') && (docFromWindow === 'quote' || docFromWindow === 'invoice')) {
    params.set('document', docFromWindow);
  }
  if (!params.has('document') && !params.has('mode')) {
    const path = typeof window !== 'undefined' ? String(window.location.pathname || '') : '';
    if (/quote-create/i.test(path)) {
      params.set('document', 'quote');
    }
  }
  return params;
}

export async function fetchCreateInit() {
  const params = createInitQueryParams();
  const qs = params.toString();
  const res = await fetch(`${getApiBase()}/create-init.php${qs ? `?${qs}` : ''}`, { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function fetchExchangeRate(currency, apiUrl) {
  const code = encodeURIComponent(currency || 'TZS');
  const base = apiUrl || `${getApiBase()}/../payments/exchange_rate.php`;
  const res = await fetch(`${base}?currency=${code}`, {
    credentials: 'same-origin',
  });
  return parseJson(res);
}

export async function submitCreateQuote(formData) {
  formData.append('_api', '1');
  const res = await fetch(`${getApiBase()}/create-quote.php`, {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });
  const data = await parseJson(res);
  if (!res.ok || data.error || data.ok === false) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function submitCreateInvoice(formData) {
  formData.append('_api', '1');
  const res = await fetch(`${getApiBase()}/create-invoice.php`, {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });
  const data = await parseJson(res);
  if (!res.ok || data.error || data.ok === false) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

function withQuery(base, params) {
  const qs = params instanceof URLSearchParams
    ? params.toString()
    : new URLSearchParams(params || {}).toString();
  if (!qs) return String(base);
  return `${base}${String(base).includes('?') ? '&' : '?'}${qs}`;
}

function orderIdFromPathOrWindow() {
  if (typeof window === 'undefined') return '';
  const fromWindow = Number(window.__INVOICES_ORDER_ID__ || 0);
  if (fromWindow > 0) return String(fromWindow);
  const path = String(window.location.pathname || '');
  const m = path.match(/\/quote\/(\d+)\/edit/i) || path.match(/[?&]id=(\d+)/i);
  return m ? m[1] : '';
}

export async function fetchQuoteEditInit() {
  const params = new URLSearchParams(window.location.search);
  const orderId = params.get('id') || orderIdFromPathOrWindow();
  if (orderId && !params.has('id')) {
    params.set('id', orderId);
  }
  if (!params.has('module')) {
    params.set('module', 'sales');
  }

  const url = typeof window !== 'undefined' && window.__INVOICES_QUOTE_EDIT_INIT_URL__
    ? withQuery(window.__INVOICES_QUOTE_EDIT_INIT_URL__, params)
    : `${getApiBase()}/quote-edit-init.php${params.toString() ? `?${params.toString()}` : ''}`;

  const res = await fetch(url, { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function submitQuoteEdit(formData) {
  formData.append('_api', '1');
  if (!formData.get('order_id')) {
    const orderId = orderIdFromPathOrWindow();
    if (orderId) formData.append('order_id', orderId);
  }
  const url = typeof window !== 'undefined' && window.__INVOICES_QUOTE_EDIT_SAVE_URL__
    ? String(window.__INVOICES_QUOTE_EDIT_SAVE_URL__)
    : `${getApiBase()}/quote-edit-save.php`;
  const res = await fetch(url, {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });
  const data = await parseJson(res);
  if (!res.ok || data.error || data.ok === false) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}
