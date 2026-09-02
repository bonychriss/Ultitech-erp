function getApiBase() {
  if (typeof window !== 'undefined' && window.__QUOTATIONS_API_BASE__) {
    return String(window.__QUOTATIONS_API_BASE__).replace(/\/$/, '');
  }
  return './api';
}

export function getConfig() {
  if (typeof window !== 'undefined' && window.__QUOTATIONS_CFG__ && typeof window.__QUOTATIONS_CFG__ === 'object') {
    return window.__QUOTATIONS_CFG__;
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

export async function fetchQuotationsInit() {
  const params = new URLSearchParams(window.location.search);
  const qs = params.toString();
  const res = await fetch(`${getApiBase()}/init.php${qs ? `?${qs}` : ''}`, { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export const DELETABLE_QUOTATION_STATUSES = ['quotation', 'draft', 'cancelled', 'canceled'];

export function quotationStatusIsDeletable(status) {
  return DELETABLE_QUOTATION_STATUSES.includes(String(status || '').toLowerCase());
}

export function submitDeleteForm(deletePostUrl, ids) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = deletePostUrl;
  const input = document.createElement('input');
  input.type = 'hidden';
  input.name = 'delete_ids';
  input.value = ids.join(',');
  form.appendChild(input);
  document.body.appendChild(form);
  form.submit();
}
