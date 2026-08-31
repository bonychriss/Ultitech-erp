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

export async function fetchInvoicesInit() {
  const params = new URLSearchParams(window.location.search);
  const qs = params.toString();
  const res = await fetch(`${getApiBase()}/list-init.php${qs ? `?${qs}` : ''}`, { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function deleteInvoices(ids, deleteUrl) {
  const fd = new FormData();
  ids.forEach((id) => fd.append('ids[]', id));
  const search = typeof window !== 'undefined' ? window.location.search : '';
  const url = `${deleteUrl}${search || ''}`;
  const res = await fetch(url, {
    method: 'POST',
    body: fd,
    credentials: 'same-origin',
  });
  const data = await parseJson(res);
  if (!res.ok && !(data.deleted || []).length) {
    throw new Error(data.message || `Request failed (${res.status})`);
  }
  return data;
}
