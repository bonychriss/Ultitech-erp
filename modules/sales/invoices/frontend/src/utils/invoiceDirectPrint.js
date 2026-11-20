/**
 * Build the print-desk URL for one invoice (no catalogue).
 */
export function buildInvoicePrintSrc(printUrl, { autoprint = false } = {}) {
  const raw = String(printUrl || '').trim();
  if (!raw) return '';

  try {
    const url = new URL(raw, window.location.href);
    url.searchParams.set('embed', '1');
    url.searchParams.set('invoice_only', '1');
    if (autoprint) url.searchParams.set('autoprint', '1');
    else url.searchParams.delete('autoprint');
    return url.toString();
  } catch (_) {
    const join = raw.includes('?') ? '&' : '?';
    return `${raw}${join}embed=1&invoice_only=1${autoprint ? '&autoprint=1' : ''}`;
  }
}

/**
 * Print an already-loaded same-origin iframe document.
 */
export function printInvoiceFrame(frame) {
  try {
    const win = frame?.contentWindow;
    if (!win) return false;
    win.focus();
    win.print();
    return true;
  } catch (err) {
    console.error('Invoice iframe print failed:', err);
    return false;
  }
}

/**
 * Open the system print dialog for this invoice.
 * Prefer the dedicated print desk (clean A4 layout) opened synchronously
 * from the click so columns are not crushed by the app chrome.
 */
export function printInvoiceDirect(printUrl) {
  const href = buildInvoicePrintSrc(printUrl, { autoprint: true });
  if (href) {
    const win = window.open(href, 'ov-invoice-print', 'width=980,height=720');
    if (win) return;
  }

  // Popup blocked: print the on-page layout.
  try {
    window.print();
  } catch (err) {
    console.error('On-page invoice print failed:', err);
  }
}
