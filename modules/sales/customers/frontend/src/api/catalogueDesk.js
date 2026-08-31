function getApiBase() {
  if (typeof window !== 'undefined' && window.__CUSTOMERS_DESK_API_BASE__) {
    return String(window.__CUSTOMERS_DESK_API_BASE__).replace(/\/$/, '');
  }
  if (typeof window !== 'undefined' && window.__CUSTOMER_CATALOGUE_API_BASE__) {
    return String(window.__CUSTOMER_CATALOGUE_API_BASE__).replace(/\/$/, '');
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

export async function fetchCatalogueInit(params = {}) {
  const qs = params instanceof URLSearchParams
    ? params.toString()
    : new URLSearchParams(params).toString();
  const res = await fetch(`${getApiBase()}/init.php${qs ? `?${qs}` : ''}`, { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function fetchViewInit(params = {}) {
  const qs = params instanceof URLSearchParams
    ? params.toString()
    : new URLSearchParams(params).toString();
  const res = await fetch(`${getApiBase()}/view-init.php${qs ? `?${qs}` : ''}`, { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function fetchIndexInit(params = {}) {
  const qs = params instanceof URLSearchParams
    ? params.toString()
    : new URLSearchParams(params).toString();
  const res = await fetch(`${getApiBase()}/index-init.php${qs ? `?${qs}` : ''}`, { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function fetchAddInit(params = {}) {
  const qs = params instanceof URLSearchParams
    ? params.toString()
    : new URLSearchParams(params).toString();
  const res = await fetch(`${getApiBase()}/add-init.php${qs ? `?${qs}` : ''}`, { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function submitAddCustomer(payload) {
  const res = await fetch(`${getApiBase()}/add-save.php`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function fetchEditInit(params = {}) {
  const qs = params instanceof URLSearchParams
    ? params.toString()
    : new URLSearchParams(params).toString();
  const res = await fetch(`${getApiBase()}/edit-init.php${qs ? `?${qs}` : ''}`, { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function submitEditCustomer(payload) {
  const res = await fetch(`${getApiBase()}/edit-save.php`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}
