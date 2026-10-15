/**
 * Build an invoice view URL that returns to the current Revenue list
 * (same filters + selected/preview entry).
 */
export function revenueInvoiceHref(entry) {
  const base = String(entry?.invoice_view_url || '').trim();
  if (!base || typeof window === 'undefined') return '';

  const back = new URL(window.location.href);
  const entryId = Number(entry?.id) || 0;
  if (entryId > 0) {
    back.searchParams.set('sel', String(entryId));
    back.searchParams.set('preview', String(entryId));
  }
  const returnTo = `${back.pathname}${back.search}${back.hash}`;
  const joiner = base.includes('?') ? '&' : '?';
  return `${base}${joiner}return=${encodeURIComponent(returnTo)}`;
}

/** Remember current Revenue list before leaving to invoice / edit / etc. */
export function rememberRevenueList(entryId, filters = {}, search = '') {
  if (typeof window === 'undefined') return;
  if (!window.erpNavBack || typeof window.erpNavBack.push !== 'function') return;

  const selectedId = Number(entryId) || 0;
  const back = new URL(window.location.href);
  const setOrKeep = (key, value) => {
    const v = String(value || '').trim();
    if (v) back.searchParams.set(key, v);
  };
  // Always re-apply known filters so a stale/partial location cannot drop them.
  setOrKeep('q', search);
  setOrKeep('date_from', filters.date_from);
  setOrKeep('date_to', filters.date_to);
  setOrKeep('customer_id', filters.customer_id);
  setOrKeep('type', filters.type);
  setOrKeep('status', filters.status);
  setOrKeep('payment', filters.payment);
  if (selectedId > 0) {
    back.searchParams.set('sel', String(selectedId));
    back.searchParams.set('preview', String(selectedId));
  }

  window.erpNavBack.push({
    href: `${back.pathname}${back.search}${back.hash}`,
    state: {
      search: search || '',
      filters: {
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
        customer_id: filters.customer_id || '',
        type: filters.type || '',
        status: filters.status || '',
        payment: filters.payment || '',
      },
      selectedId,
    },
    force: true,
  });
}
