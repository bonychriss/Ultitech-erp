function getApiBase() {
  if (typeof window !== 'undefined' && window.__REVENUE_API_BASE__) {
    return String(window.__REVENUE_API_BASE__).replace(/\/$/, '');
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

export async function fetchDeskInit() {
  const res = await fetch(`${getApiBase()}/desk-init.php`);
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function fetchDeskEntries(filters = {}) {
  const params = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) {
      params.set(key, String(value));
    }
  });
  const qs = params.toString();
  const res = await fetch(`${getApiBase()}/entries.php${qs ? `?${qs}` : ''}`);
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function fetchCreateInit() {
  const res = await fetch(`${getApiBase()}/create-init.php`);
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function fetchExchangeRate(currency) {
  const code = encodeURIComponent(String(currency || 'TZS'));
  const res = await fetch(`${getApiBase()}/exchange-rate.php?currency=${code}`);
  const data = await parseJson(res);
  if (!res.ok && !data.ok) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function submitCreateEntry(formData) {
  const res = await fetch(`${getApiBase()}/create-entry.php`, {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
  });
  const data = await parseJson(res);
  if (!res.ok && !data.ok) {
    if (Array.isArray(data.errors) && data.errors.length) {
      const err = new Error(data.errors.join(' '));
      err.errors = data.errors;
      throw err;
    }
    throw new Error(data.error || data.errors?.[0] || `Request failed (${res.status})`);
  }
  return data;
}

export async function fetchDetailInit(entryId) {
  const id = encodeURIComponent(String(entryId));
  const res = await fetch(`${getApiBase()}/detail-init.php?id=${id}`);
  const data = await parseJson(res);
  if (!res.ok || !data.ok) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function fetchPaymentInit(entryId) {
  const id = encodeURIComponent(String(entryId));
  const res = await fetch(`${getApiBase()}/payment-init.php?id=${id}`);
  const data = await parseJson(res);
  if (!res.ok || !data.ok) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function submitPayment(formData) {
  const res = await fetch(`${getApiBase()}/record-payment.php`, {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
  });
  const data = await parseJson(res);
  if (!res.ok && !data.ok) {
    if (Array.isArray(data.errors) && data.errors.length) {
      const err = new Error(data.errors.join(' '));
      err.errors = data.errors;
      throw err;
    }
    throw new Error(data.error || data.errors?.[0] || `Request failed (${res.status})`);
  }
  return data;
}

export function deskPageUrl(file, extraParams = {}) {
  const params = new URLSearchParams({ module: 'revenue', ...extraParams });
  return `./${file}?${params.toString()}`;
}

export function exportUrl(filters = {}) {
  const params = new URLSearchParams({ module: 'revenue', export: 'csv' });
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined && key !== 'page' && key !== 'per_page') {
      params.set(key, String(value));
    }
  });
  return `./revenue_entries.php?${params.toString()}`;
}

function parseDownloadFilename(contentDisposition, fallback = 'revenue_entries.csv') {
  const header = String(contentDisposition || '');
  const utfMatch = /filename\*=UTF-8''([^;]+)/i.exec(header);
  if (utfMatch?.[1]) {
    try {
      return decodeURIComponent(utfMatch[1].trim().replace(/["']/g, ''));
    } catch {
      /* fall through */
    }
  }
  const plainMatch = /filename="?([^";]+)"?/i.exec(header);
  if (plainMatch?.[1]) {
    return plainMatch[1].trim();
  }
  return fallback;
}

export function triggerBrowserDownload(blob, filename) {
  const objectUrl = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = objectUrl;
  link.download = filename;
  link.rel = 'noopener';
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.setTimeout(() => URL.revokeObjectURL(objectUrl), 2000);
}

/**
 * Fetch revenue CSV export with optional progress callback.
 * @param {Record<string, string>} filters
 * @param {(percent: number) => void} [onProgress]
 */
export async function downloadRevenueCsv(filters = {}, onProgress) {
  const url = exportUrl(filters);
  const res = await fetch(url, { credentials: 'same-origin' });

  if (!res.ok) {
    const text = await res.text();
    let message = `Export failed (${res.status})`;
    try {
      const data = JSON.parse(text);
      if (data?.error) message = String(data.error);
    } catch {
      const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 160);
      if (snippet && !snippet.startsWith('<!')) message = snippet;
    }
    throw new Error(message);
  }

  const contentType = String(res.headers.get('content-type') || '').toLowerCase();
  if (
    !contentType.includes('csv')
    && !contentType.includes('text/plain')
    && !contentType.includes('octet-stream')
    && contentType !== ''
  ) {
    throw new Error('Server did not return a CSV file. Please try again.');
  }

  const total = Number(res.headers.get('content-length')) || 0;
  let blob;

  if (res.body && typeof res.body.getReader === 'function') {
    const reader = res.body.getReader();
    const chunks = [];
    let received = 0;
    let simulated = 8;

    onProgress?.(simulated);

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      chunks.push(value);
      received += value.length;

      if (total > 0) {
        onProgress?.(Math.min(99, Math.round((received / total) * 100)));
      } else {
        simulated = Math.min(92, simulated + 4);
        onProgress?.(simulated);
      }
    }

    blob = new Blob(chunks, { type: 'text/csv;charset=utf-8' });
  } else {
    onProgress?.(55);
    blob = await res.blob();
    onProgress?.(100);
  }

  onProgress?.(100);

  return {
    blob,
    filename: parseDownloadFilename(
      res.headers.get('content-disposition'),
      `revenue_entries_${new Date().toISOString().slice(0, 10)}.csv`,
    ),
  };
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

export async function commitImportRows(payload) {
  const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
  const timeoutMs = 90000;
  const timer = controller ? setTimeout(() => controller.abort(), timeoutMs) : null;
  try {
    const res = await fetch(`${getApiBase()}/import-commit.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
      signal: controller?.signal,
    });
    const data = await parseJson(res);
    if (!res.ok || data.error || data.ok === false) {
      throw new Error(data.error || data.message || `Request failed (${res.status})`);
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

export async function fetchKpiAiConfirmation(key, { trace = {} } = {}) {
  const params = new URLSearchParams({ key, ai: '1' });
  const res = await fetch(`${getApiBase()}/kpi-confirm.php?${params.toString()}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ trace }),
  });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}
