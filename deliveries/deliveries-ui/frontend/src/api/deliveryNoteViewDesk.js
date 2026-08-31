import { CFG } from '../config.js';

function getApiBase() {
  if (CFG.deliveryNoteViewInitUrl) {
    const url = String(CFG.deliveryNoteViewInitUrl);
    return url.replace(/\/delivery-note-view-init\.php.*$/, '');
  }
  if (typeof window !== 'undefined' && window.__DELIVERIES_API_BASE__) {
    return String(window.__DELIVERIES_API_BASE__).replace(/\/$/, '');
  }
  return '../deliveries-ui/api';
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

export async function fetchDeliveryNoteViewInit(params) {
  const qs = params instanceof URLSearchParams ? params.toString() : new URLSearchParams(params).toString();
  const base = getApiBase();
  const url = `${base}/delivery-note-view-init.php${qs ? `?${qs}` : ''}`;
  const res = await fetch(url, { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}
