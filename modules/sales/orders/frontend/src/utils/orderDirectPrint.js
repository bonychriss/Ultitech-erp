/**
 * Build the print-desk URL for one order/quotation (document only).
 */
export function buildOrderPrintSrc(printUrl, { autoprint = false } = {}) {
  const raw = String(printUrl || '').trim();
  if (!raw) return '';

  try {
    const url = new URL(raw, window.location.href);
    url.searchParams.set('embed', '1');
    url.searchParams.set('doc_only', '1');
    if (autoprint) url.searchParams.set('autoprint', '1');
    else url.searchParams.delete('autoprint');
    return url.toString();
  } catch (_) {
    const join = raw.includes('?') ? '&' : '?';
    return `${raw}${join}embed=1&doc_only=1${autoprint ? '&autoprint=1' : ''}`;
  }
}

/**
 * Open the system print dialog for this order/quotation.
 */
export function printOrderDirect(printUrl) {
  const href = buildOrderPrintSrc(printUrl, { autoprint: true });
  if (href) {
    const win = window.open(href, 'ov-order-print', 'width=980,height=720');
    if (win) return;
  }

  try {
    window.print();
  } catch (err) {
    console.error('On-page order print failed:', err);
  }
}
