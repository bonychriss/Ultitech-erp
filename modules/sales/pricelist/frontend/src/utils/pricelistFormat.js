export const PLACEHOLDER_IMG =
  'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2248%22 height=%2248%22 viewBox=%220 0 24 24%22 fill=%22%239ca3af%22%3E%3Cpath d=%22M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z%22/%3E%3C/svg%3E';

export const EMPTY_CELL = '-';

export function escapeHtml(value) {
  if (value == null) return '';
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

export function formatCurrency(amount, currency = 'TZS') {
  try {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency,
      minimumFractionDigits: 0,
    }).format(Number(amount) || 0);
  } catch {
    return `${currency} ${String(Number(amount) || 0)}`;
  }
}

export function formatPlainNumber(amount) {
  return new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(Number(amount) || 0);
}

export function formatMoneyDashboard(amount, currency = 'TZS') {
  try {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(Number(amount) || 0);
  } catch {
    return `${currency} ${formatPlainNumber(amount)}`;
  }
}

export function mapProductsWithEditedPrices(products) {
  return (products || []).map((product) => ({
    ...product,
    edited_price: Number(product.selling_price) || 0,
  }));
}

export function buildVisiblePageNumbers(totalPages, page) {
  const t = totalPages;
  const p = Math.min(page, t);
  if (t <= 9) return Array.from({ length: t }, (_, i) => i + 1);
  const nums = new Set([1, t]);
  for (let i = p - 2; i <= p + 2; i += 1) {
    if (i >= 1 && i <= t) nums.add(i);
  }
  const sorted = [...nums].sort((a, b) => a - b);
  const out = [];
  for (let i = 0; i < sorted.length; i += 1) {
    if (i > 0 && sorted[i] - sorted[i - 1] > 1) out.push('ellipsis');
    out.push(sorted[i]);
  }
  return out;
}
