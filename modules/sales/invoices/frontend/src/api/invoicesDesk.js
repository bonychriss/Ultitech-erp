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

export async function fetchCreateInit() {
  const params = new URLSearchParams(window.location.search);
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
