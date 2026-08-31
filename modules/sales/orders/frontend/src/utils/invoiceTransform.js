const STATUS_LINES_CREATE = [
  'Reading order lines...',
  'Confirming sales order...',
  'Applying totals and tax...',
  'Generating invoice number...',
  'Creating invoice...',
];

const STATUS_LINES_OPEN = [
  'Locating linked invoice...',
  'Opening invoice...',
];

const INVOICED_ORDERS_STORAGE_KEY = 'sales_desk_invoiced_orders';

function escapeHtml(text) {
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function getOrdersApiBase() {
  if (typeof window !== 'undefined' && window.__SALES_ORDERS_API_BASE__) {
    return String(window.__SALES_ORDERS_API_BASE__).replace(/\/$/, '');
  }
  if (typeof window === 'undefined') return './api';
  const path = window.location.pathname || '';
  const marker = '/modules/sales/orders/';
  const idx = path.indexOf(marker);
  if (idx !== -1) {
    return `${path.slice(0, idx + marker.length)}api`;
  }
  return './api';
}

function parseOrderIdFromHref(href) {
  try {
    const url = new URL(href, window.location.origin);
    const fromQuery = url.searchParams.get('order_id');
    if (fromQuery) return parseInt(fromQuery, 10);
  } catch {
    const match = href.match(/[?&]order_id=(\d+)/);
    if (match) return parseInt(match[1], 10);
  }
  return 0;
}

function isInvoiceViewHref(href) {
  return /\/invoices\/view\.php/i.test(href) || (/\/invoices\//i.test(href) && /[?&]id=\d+/i.test(href) && !/[?&]order_id=/i.test(href));
}

function isConvertHref(href) {
  if (isInvoiceViewHref(href)) return false;
  return /\/invoices\/create\.php/i.test(href) && parseOrderIdFromHref(href) > 0;
}

export function rememberOrderInvoiced(orderId) {
  const id = parseInt(String(orderId), 10);
  if (!id || typeof window === 'undefined') return;
  try {
    const raw = window.sessionStorage.getItem(INVOICED_ORDERS_STORAGE_KEY);
    const list = raw ? JSON.parse(raw) : [];
    const next = Array.isArray(list) ? list : [];
    if (!next.includes(id)) next.push(id);
    window.sessionStorage.setItem(INVOICED_ORDERS_STORAGE_KEY, JSON.stringify(next));
  } catch {
    // ignore storage errors
  }
}

export function applyInvoicedStatusToRows(rows) {
  if (!Array.isArray(rows) || typeof window === 'undefined') return rows;
  try {
    const raw = window.sessionStorage.getItem(INVOICED_ORDERS_STORAGE_KEY);
    if (!raw) return rows;
    const ids = new Set((JSON.parse(raw) || []).map((v) => Number(v)));
    if (!ids.size) return rows;
    return rows.map((row) => (
      ids.has(Number(row?.id)) ? { ...row, status: 'invoiced' } : row
    ));
  } catch {
    return rows;
  }
}

async function convertOrderToInvoice(orderId, module) {
  const params = new URLSearchParams();
  params.set('order_id', String(orderId));
  if (module) params.set('module', module);

  const res = await fetch(`${getOrdersApiBase()}/convert-invoice.php?${params.toString()}`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });
  const text = await res.text();
  let data = {};
  try {
    data = text ? JSON.parse(text) : {};
  } catch {
    throw new Error('Invalid response while creating the invoice.');
  }
  if (!res.ok || !data.ok) {
    throw new Error(data.error || `Invoice conversion failed (${res.status})`);
  }
  return data;
}

function teardownOverlay(overlay) {
  const scrollRestore = parseInt(overlay?.dataset?.scrollY || '0', 10) || 0;
  document.body.classList.remove('ov-inv-transform-lock');
  document.body.style.position = '';
  document.body.style.top = '';
  document.body.style.left = '';
  document.body.style.right = '';
  document.body.style.width = '';
  window.scrollTo(0, scrollRestore);
  overlay?.remove();
}

/**
 * Full-screen transformation animation, then navigate to invoice URL.
 *
 * @param {string} targetUrl
 * @param {{ sourceLabel?: string, documentNumber?: string, existingInvoice?: boolean, orderId?: number, module?: string }} [options]
 */
export function runInvoiceTransformThenNavigate(targetUrl, options = {}) {
  const href = String(targetUrl || '').trim();
  if (!href || typeof window === 'undefined') {
    return;
  }

  const sourceLabel = options.sourceLabel || 'Sales Order';
  const documentNumber = options.documentNumber || '';
  const orderId = parseInt(String(options.orderId || parseOrderIdFromHref(href) || 0), 10);
  const module = options.module || 'sales';
  const existingInvoice = Boolean(options.existingInvoice) || isInvoiceViewHref(href);
  const shouldConvert = !existingInvoice && isConvertHref(href) && orderId > 0;
  const statusLines = existingInvoice ? STATUS_LINES_OPEN : STATUS_LINES_CREATE;
  const durationMs = existingInvoice ? 1400 : 2600;

  const existing = document.querySelector('.ov-inv-transform');
  if (existing) existing.remove();

  const overlay = document.createElement('div');
  overlay.className = 'ov-inv-transform';
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-modal', 'true');
  overlay.setAttribute('aria-label', existingInvoice ? 'Opening invoice' : 'Converting to invoice');
  overlay.innerHTML = `
    <div class="ov-inv-transform__backdrop">
      <div class="ov-inv-transform__stage">
        <div class="ov-inv-transform__doc ov-inv-transform__doc--source">
          <div class="ov-inv-transform__doc-sheet">
            <div class="ov-inv-transform__doc-lines" aria-hidden="true">
              <span></span><span></span><span></span><span></span>
            </div>
            <div class="ov-inv-transform__doc-icon"><i class="fas fa-file-alt" aria-hidden="true"></i></div>
          </div>
          <div class="ov-inv-transform__doc-label">${escapeHtml(sourceLabel)}</div>
          ${documentNumber ? `<div class="ov-inv-transform__doc-number">${escapeHtml(documentNumber)}</div>` : ''}
        </div>
        <div class="ov-inv-transform__beam" aria-hidden="true">
          <div class="ov-inv-transform__beam-track">
            <div class="ov-inv-transform__beam-fill"></div>
            <div class="ov-inv-transform__beam-dot"></div>
          </div>
          <i class="fas fa-arrow-right ov-inv-transform__beam-arrow"></i>
        </div>
        <div class="ov-inv-transform__doc ov-inv-transform__doc--target">
          <div class="ov-inv-transform__doc-sheet ov-inv-transform__doc-sheet--invoice">
            <div class="ov-inv-transform__doc-lines" aria-hidden="true">
              <span></span><span></span><span></span><span></span>
            </div>
            <div class="ov-inv-transform__doc-icon"><i class="fas fa-file-invoice-dollar" aria-hidden="true"></i></div>
          </div>
          <div class="ov-inv-transform__doc-label">Invoice</div>
        </div>
      </div>
      <p class="ov-inv-transform__status">${escapeHtml(statusLines[0])}</p>
      <div class="ov-inv-transform__progress" aria-hidden="true"><span class="ov-inv-transform__progress-bar"></span></div>
    </div>
  `;

  document.body.appendChild(overlay);
  document.body.classList.add('ov-inv-transform-lock');

  const scrollY = window.scrollY || window.pageYOffset || 0;
  document.body.style.position = 'fixed';
  document.body.style.top = `-${scrollY}px`;
  document.body.style.left = '0';
  document.body.style.right = '0';
  document.body.style.width = '100%';
  overlay.dataset.scrollY = String(scrollY);

  const statusEl = overlay.querySelector('.ov-inv-transform__status');
  let lineIndex = 0;
  const lineInterval = window.setInterval(() => {
    lineIndex = (lineIndex + 1) % statusLines.length;
    if (statusEl) statusEl.textContent = statusLines[lineIndex];
  }, existingInvoice ? 500 : 520);

  const convertPromise = shouldConvert
    ? convertOrderToInvoice(orderId, module)
    : Promise.resolve(null);

  requestAnimationFrame(() => {
    requestAnimationFrame(() => overlay.classList.add('ov-inv-transform--active'));
  });

  window.setTimeout(() => {
    window.clearInterval(lineInterval);
    overlay.classList.add('ov-inv-transform--complete');

    Promise.resolve(convertPromise)
      .then((result) => {
        let finalHref = href;
        if (result && result.redirect) {
          finalHref = result.redirect;
        }
        if (result?.stock_deduction?.attempted && statusEl) {
          const sd = result.stock_deduction;
          statusEl.textContent = sd.message
            || (sd.success ? 'Stock deducted successfully.' : 'Stock was not deducted.');
        }
        if (shouldConvert && orderId > 0) {
          rememberOrderInvoiced(orderId);
        }
        const exitDelay = result?.stock_deduction?.attempted ? 900 : 320;
        window.setTimeout(() => {
          overlay.classList.add('ov-inv-transform--exit');
          window.setTimeout(() => {
            teardownOverlay(overlay);
            window.location.href = finalHref;
          }, 380);
        }, exitDelay);
      })
      .catch((err) => {
        teardownOverlay(overlay);
        const message = err?.message || 'Could not create the invoice.';
        if (typeof window.Swal !== 'undefined') {
          window.Swal.fire('Invoice failed', message, 'error');
        } else {
          window.alert(message);
        }
      });
  }, durationMs);
}

export function resolveInvoiceSourceLabel(status) {
  const st = String(status || '').toLowerCase().trim();
  if (st === 'draft' || st === 'quotation' || st === 'sent') {
    return 'Quotation';
  }
  return 'Sales Order';
}

export function handleInvoiceLinkClick(event, href, options) {
  if (event) {
    event.preventDefault();
    event.stopPropagation();
  }
  runInvoiceTransformThenNavigate(href, options);
}
