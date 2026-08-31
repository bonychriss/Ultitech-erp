export function formatNumber(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '0';
  return new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 }).format(n);
}

export function formatMoney(value, currency = 'TZS') {
  return `${currency} ${formatNumber(value)}`;
}

export function timeAgo(isoOrSql) {
  if (!isoOrSql) return '';
  const ts = new Date(String(isoOrSql).replace(' ', 'T')).getTime();
  if (!Number.isFinite(ts)) return '';
  const diff = Math.max(0, Math.floor((Date.now() - ts) / 1000));
  if (diff < 3600) {
    const mins = Math.floor(diff / 60);
    return `${mins} min ago`;
  }
  if (diff < 86400) {
    const hours = Math.floor(diff / 3600);
    return `${hours} hour${hours === 1 ? '' : 's'} ago`;
  }
  const days = Math.floor(diff / 86400);
  return `${days} day${days === 1 ? '' : 's'} ago`;
}

export function starRatingParts(rating) {
  const r = Math.max(0, Math.min(5, Number(rating) || 0));
  const fullStars = Math.floor(r);
  const halfStar = r - fullStars >= 0.5;
  const emptyStars = 5 - fullStars - (halfStar ? 1 : 0);
  return { fullStars, halfStar, emptyStars, label: r.toFixed(1) };
}

export function formatPercent(value, digits = 1) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '0';
  return n.toFixed(digits);
}

export function formatSignedMoney(value, currency = 'TZS') {
  const n = Number(value);
  if (!Number.isFinite(n)) return `${currency} 0`;
  const prefix = n > 0 ? '+' : n < 0 ? '-' : '';
  return `${prefix}${currency} ${formatNumber(Math.abs(n))}`;
}

export function formatSignedPercent(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '0%';
  const prefix = n > 0 ? '+' : '';
  return `${prefix}${Math.abs(n)}%`;
}

export function formatKpiStatValue(stat) {
  const value = stat?.value;
  const format = stat?.format || 'text';
  switch (format) {
    case 'money':
      return formatMoney(value);
    case 'money_signed':
      return formatSignedMoney(value);
    case 'number':
      return formatNumber(value);
    case 'percent':
      return `${formatPercent(value)}%`;
    case 'percent_signed':
      return formatSignedPercent(value);
    default:
      return String(value ?? '');
  }
}
