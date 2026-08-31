export function formatDateTime(value: string): string {
  if (!value) return '-';
  const normalized = value.includes('T') ? value : value.replace(' ', 'T');
  const d = new Date(normalized);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

export function formatMoney(amount: number, currency = 'TZS'): string {
  const safeAmount = Number.isFinite(amount) ? amount : 0;
  return `${currency} ${safeAmount.toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

export function typeBadgeClass(typeClass: string): string {
  if (typeClass === 'transfer') return 'tl-badge tl-badge--transfer';
  if (typeClass === 'credit') return 'tl-badge tl-badge--credit';
  return 'tl-badge tl-badge--debit';
}

export function amountClass(type: 'credit' | 'debit'): string {
  return type === 'credit' ? 'tl-amt tl-amt--in' : 'tl-amt tl-amt--out';
}
