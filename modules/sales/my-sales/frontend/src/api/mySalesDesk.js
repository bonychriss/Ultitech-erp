function getApiBase() {
  if (typeof window !== 'undefined' && window.__MY_SALES_API_BASE__) {
    return String(window.__MY_SALES_API_BASE__).replace(/\/$/, '');
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

export async function fetchMySalesInit(params = {}) {
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

export function buildMySalesExportUrl(baseUrl, { module = 'sales', include = 'both', dateFrom, dateTo }) {
  const base = String(baseUrl || '');
  const splitAt = base.indexOf('?');
  const path = splitAt >= 0 ? base.slice(0, splitAt) : base;
  const params = new URLSearchParams(splitAt >= 0 ? base.slice(splitAt + 1) : '');
  if (module) params.set('module', module);
  params.set('include', include);
  if (dateFrom) params.set('date_from', dateFrom);
  if (dateTo) params.set('date_to', dateTo);
  return `${path}?${params.toString()}`;
}

function parseDownloadFilename(contentDisposition) {
  const cd = String(contentDisposition || '');
  const utfMatch = cd.match(/filename\*=UTF-8''([^;]+)/i);
  if (utfMatch) {
    return decodeURIComponent(utfMatch[1].trim());
  }
  const plainMatch = cd.match(/filename="?([^";]+)"?/i);
  if (plainMatch) {
    return plainMatch[1].trim();
  }
  return 'my_sales_record.pdf';
}

async function readExportErrorMessage(response) {
  const text = (await response.text()).trim();
  if (text.startsWith('{')) {
    try {
      const data = JSON.parse(text);
      if (data?.error) {
        return String(data.error);
      }
    } catch {
      /* fall through */
    }
  }
  return text.replace(/\s+/g, ' ').slice(0, 160) || `Download failed (${response.status})`;
}

export function toSimpleDownloadMessage(message, include = 'both') {
  let text = String(message || '').trim();
  if (text.startsWith('{')) {
    try {
      const data = JSON.parse(text);
      text = String(data?.error || text);
    } catch {
      /* keep original */
    }
  }

  const lower = text.toLowerCase();
  if (lower.includes('no quotes') && lower.includes('no invoices')) {
    return 'Nothing to download for these dates. Try choosing different dates.';
  }
  if (lower.includes('no quotes') || include === 'quotes') {
    return 'No quotes for these dates. Try choosing different dates.';
  }
  if (lower.includes('no invoices') || include === 'invoices') {
    return 'No invoices for these dates. Try choosing different dates.';
  }
  if (lower.includes('nothing to download')) {
    return 'Nothing to download for these dates. Try choosing different dates.';
  }

  return text || 'Could not download the PDF. Please try again.';
}

/**
 * Fetch PDF export and report byte progress when available.
 * @param {(percent: number) => void} [onProgress]
 */
export async function downloadMySalesPdf(baseUrl, options, onProgress) {
  const url = buildMySalesExportUrl(baseUrl, options);
  const res = await fetch(url, { credentials: 'same-origin' });

  if (!res.ok) {
    const message = await readExportErrorMessage(res);
    throw new Error(message);
  }

  const contentType = String(res.headers.get('content-type') || '').toLowerCase();
  if (!contentType.includes('pdf') && !contentType.includes('octet-stream')) {
    throw new Error('Server did not return a PDF file. Please try again.');
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

    blob = new Blob(chunks, { type: 'application/pdf' });
  } else {
    onProgress?.(55);
    blob = await res.blob();
    onProgress?.(100);
  }

  onProgress?.(100);

  return {
    blob,
    filename: parseDownloadFilename(res.headers.get('content-disposition')),
  };
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
