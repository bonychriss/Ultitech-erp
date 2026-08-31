export function getCfg() {
  if (typeof window !== 'undefined' && window.__PETTY_CASH_CFG__ && typeof window.__PETTY_CASH_CFG__ === 'object') {
    return window.__PETTY_CASH_CFG__;
  }
  return {};
}

export function getPageKey() {
  if (typeof window !== 'undefined' && window.__PETTY_CASH_PAGE__) {
    return String(window.__PETTY_CASH_PAGE__);
  }
  return getCfg().page || 'desk';
}

export function formatMoney(value) {
  const amount = Number(value) || 0;
  return `TZS ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export function normalizeDateInput(dateStr) {
  const raw = String(dateStr ?? '').trim();
  if (!raw || raw.startsWith('0000-00-00')) return '';
  if (raw.includes('T')) return raw;
  if (raw.includes(' ')) return raw.replace(' ', 'T');
  return `${raw}T12:00:00`;
}

export function parseDateValue(dateStr) {
  const normalized = normalizeDateInput(dateStr);
  if (!normalized) return null;
  const date = new Date(normalized);
  return Number.isNaN(date.getTime()) ? null : date;
}

export function formatDate(dateStr) {
  const date = parseDateValue(dateStr);
  if (!date) return '-';
  return date.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

export function todayIso() {
  return new Date().toISOString().slice(0, 10);
}

export function currentYear() {
  return new Date().getFullYear();
}
