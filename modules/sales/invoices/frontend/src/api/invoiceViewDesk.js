function getApiBase() {
  if (typeof window !== 'undefined' && window.__INVOICES_API_BASE__) {
    return String(window.__INVOICES_API_BASE__).replace(/\/$/, '');
  }
  return './api';
}

export function getConfig() {
  if (typeof window !== 'undefined' && window.__INVOICES_CFG__ && typeof window.__INVOICES_CFG__ === 'object') {
    return window.__INVOICES_CFG__;
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

export async function fetchInvoiceViewInit(params) {
  const qs = params instanceof URLSearchParams ? params.toString() : new URLSearchParams(params).toString();
  const res = await fetch(`${getApiBase()}/view-init.php${qs ? `?${qs}` : ''}`, { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function postInvoiceStatusAction(invoiceId, action, module) {
  const params = new URLSearchParams();
  params.set('id', String(invoiceId));
  if (module) params.set('module', module);

  const res = await fetch(`${getApiBase()}/view-status.php?${params.toString()}`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action }),
  });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function submitEmailWithPdf(targetUrl, pdfDataUri) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = targetUrl;

  const inputPdf = document.createElement('input');
  inputPdf.type = 'hidden';
  inputPdf.name = 'pdf_base64';
  inputPdf.value = pdfDataUri;
  form.appendChild(inputPdf);

  document.body.appendChild(form);
  form.submit();
}
