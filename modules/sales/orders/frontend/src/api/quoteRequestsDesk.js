function getApiBase() {
  if (typeof window !== 'undefined' && window.__QUOTE_REQUESTS_API_BASE__) {
    return String(window.__QUOTE_REQUESTS_API_BASE__).replace(/\/$/, '');
  }
  return './api';
}

function withQuery(base, params) {
  const qs = params instanceof URLSearchParams
    ? params.toString()
    : new URLSearchParams(params || {}).toString();
  if (!qs) return String(base);
  return `${base}${String(base).includes('?') ? '&' : '?'}${qs}`;
}

function getInitUrl(params) {
  if (typeof window !== 'undefined' && window.__QUOTE_REQUESTS_INIT_URL__) {
    return withQuery(String(window.__QUOTE_REQUESTS_INIT_URL__), params);
  }
  const qs = params instanceof URLSearchParams
    ? params.toString()
    : new URLSearchParams(params || {}).toString();
  return `${getApiBase()}/init.php${qs ? `?${qs}` : ''}`;
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

export async function fetchQuoteRequestsInit() {
  const params = new URLSearchParams(window.location.search);
  const res = await fetch(getInitUrl(params), { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}
