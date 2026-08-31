export function formatNumber(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '0';
  return new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 }).format(n);
}

export function formatMoney(value, currency = 'TZS') {
  return `${currency} ${formatNumber(value)}`;
}

export function formatPercent(value, digits = 1) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '0';
  return n.toFixed(digits);
}

export function timeAgo(isoOrSql) {
  if (!isoOrSql) return '';
  const ts = new Date(String(isoOrSql).replace(' ', 'T')).getTime();
  if (!Number.isFinite(ts)) return '';
  const diff = Math.max(0, Math.floor((Date.now() - ts) / 1000));
  if (diff < 3600) return `${Math.floor(diff / 60)} min ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)} hour${Math.floor(diff / 3600) === 1 ? '' : 's'} ago`;
  return `${Math.floor(diff / 86400)} day${Math.floor(diff / 86400) === 1 ? '' : 's'} ago`;
}

export function statusLabel(status) {
  const st = String(status || '').toLowerCase().trim();
  const map = {
    draft: 'Draft',
    quotation: 'Quote',
    confirmed: 'Confirmed',
    invoiced: 'Invoiced',
    sent: 'Sent',
    paid: 'Paid',
    overdue: 'Overdue',
    cancelled: 'Cancelled',
    processing: 'Processing',
    shipped: 'Shipped',
    delivered: 'Delivered',
  };
  return map[st] || (status || '-');
}
