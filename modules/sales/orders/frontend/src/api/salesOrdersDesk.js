function getApiBase() {
  if (typeof window !== 'undefined' && window.__SALES_ORDERS_API_BASE__) {
    return String(window.__SALES_ORDERS_API_BASE__).replace(/\/$/, '');
  }
  return './api';
}

export function getConfig() {
  if (typeof window !== 'undefined' && window.__SALES_ORDERS_CFG__ && typeof window.__SALES_ORDERS_CFG__ === 'object') {
    return window.__SALES_ORDERS_CFG__;
  }
  return {};
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

export async function fetchSalesOrdersInit() {
  const params = new URLSearchParams(window.location.search);
  const qs = params.toString();
  const res = await fetch(`${getApiBase()}/sales-orders-init.php${qs ? `?${qs}` : ''}`, { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}
