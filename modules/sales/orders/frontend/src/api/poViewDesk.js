function getApiBase() {
  if (typeof window !== 'undefined' && window.__SALES_ORDERS_API_BASE__) {
    return String(window.__SALES_ORDERS_API_BASE__).replace(/\/$/, '');
  }
  return './api';
}

export function getPoDeskCfg() {
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

export async function fetchPoViewInit(params) {
  const qs = params instanceof URLSearchParams ? params.toString() : new URLSearchParams(params).toString();
  const res = await fetch(`${getApiBase()}/view-init.php${qs ? `?${qs}` : ''}`, { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export function submitPoEmailForm(formData) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'view_po.php?id=' + encodeURIComponent(String(formData.po_id || ''));

  Object.entries(formData).forEach(([key, value]) => {
    if (key === 'po_id') return;
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = key;
    input.value = String(value ?? '');
    form.appendChild(input);
  });

  document.body.appendChild(form);
  form.submit();
}

export async function fetchPoReceiptInfo(poId, withAi = true) {
  const params = new URLSearchParams();
  params.set('id', String(poId));
  if (!withAi) params.set('ai', '0');
  const res = await fetch(`${getApiBase()}/po-receipt-info.php?${params.toString()}`, { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export function submitPoActionForm(poId, action, extra = {}) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'view_po.php?id=' + encodeURIComponent(String(poId));

  const actionInput = document.createElement('input');
  actionInput.type = 'hidden';
  actionInput.name = 'action';
  actionInput.value = action;
  form.appendChild(actionInput);

  Object.entries(extra).forEach(([key, value]) => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = key;
    input.value = String(value ?? '');
    form.appendChild(input);
  });

  document.body.appendChild(form);
  form.submit();
}
