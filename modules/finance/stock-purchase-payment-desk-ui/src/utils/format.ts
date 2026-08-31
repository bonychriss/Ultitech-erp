export function formatDate(value: string): string {
  if (!value) return '-';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

export function formatMoney(amount: number, currency: string): string {
  const safeAmount = Number.isFinite(amount) ? amount : 0;
  const safeCurrency = currency?.trim() || 'TZS';
  return `${safeCurrency} ${safeAmount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export function formatPaymentStatus(value: string): string {
  const normalized = value.trim().toLowerCase().replace(/_/g, ' ');
  if (!normalized) return 'Unpaid';
  if (normalized.includes('partial')) return 'Partially paid';
  return normalized.replace(/\b\w/g, (char) => char.toUpperCase());
}

export function paymentStatusBadgeClass(value: string): string {
  const normalized = value.trim().toLowerCase();
  if (normalized === 'paid') return 'sppd-badge-paid';
  if (normalized.includes('partial')) return 'sppd-badge-partial';
  return 'sppd-badge-unpaid';
}

export function isPaidStatus(value: string): boolean {
  return value.trim().toLowerCase() === 'paid';
}

export function hasBalanceDue(amount: number): boolean {
  return amount > 0.009;
}

export function balanceDueColorClass(amount: number): string {
  return hasBalanceDue(amount) ? 'sppd-amt-due' : 'sppd-amt-zero';
}
