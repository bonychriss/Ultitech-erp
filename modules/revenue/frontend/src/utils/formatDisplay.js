/** Display helpers for revenue desk (digit dates, month-abbr voucher refs). */

const MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const MONTH_FROM_ABBR = Object.fromEntries(
  MONTH_NAMES.map((name) => [name.toLowerCase(), name]),
);

/** Roman month tokens sometimes used in older voucher refs. */
const MONTH_FROM_ROMAN = {
  i: 'Jan',
  ii: 'Feb',
  iii: 'Mar',
  iv: 'Apr',
  v: 'May',
  vi: 'Jun',
  vii: 'Jul',
  viii: 'Aug',
  ix: 'Sep',
  x: 'Oct',
  xi: 'Nov',
  xii: 'Dec',
};

/**
 * Format a date as DD/MM/YYYY digits (no month names).
 * @param {string|null|undefined} dateStr
 */
export function formatDateDigits(dateStr) {
  if (!dateStr) return '-';
  const raw = String(dateStr).trim();
  const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T');
  const d = new Date(normalized.includes('T') ? normalized : `${raw}T12:00:00`);
  if (Number.isNaN(d.getTime())) return raw;
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  return `${dd}/${mm}/${d.getFullYear()}`;
}

/**
 * Normalize one voucher-ref segment for display.
 * Months become Jan–Dec; years stay 4-digit; sequence digits drop leading zeros.
 * @param {string} part
 * @param {string|undefined} prev
 * @param {string|undefined} next
 */
function formatRefPart(part, prev, next) {
  const p = String(part || '').trim();
  if (!p) return p;

  const lower = p.toLowerCase();
  if (MONTH_FROM_ABBR[lower]) {
    return MONTH_FROM_ABBR[lower];
  }
  if (MONTH_FROM_ROMAN[lower]) {
    return MONTH_FROM_ROMAN[lower];
  }

  if (/^\d+$/.test(p)) {
    // Keep 4-digit years intact
    if (p.length === 4 && Number(p) >= 1900 && Number(p) <= 2100) {
      return p;
    }
    const n = parseInt(p, 10);
    const prevIsYear = prev
      && /^\d{4}$/.test(String(prev).trim())
      && Number(prev) >= 1900
      && Number(prev) <= 2100;
    const nextIsSeq = next != null && /^\d+$/.test(String(next).trim());
    // REV-2026-10-1 → month slot between year and sequence
    if (prevIsYear && nextIsSeq && n >= 1 && n <= 12) {
      return MONTH_NAMES[n - 1];
    }
    // Strip leading zeros: 004 → 4
    return String(n);
  }

  return p;
}

/**
 * Display voucher / ref codes with Jan–Dec months and no leading zeros on the sequence.
 * e.g. REV-2026-10-004 → REV-2026-Oct-4 ; REV-2026-X-1 → REV-2026-Oct-1
 * @param {string|null|undefined} value
 * @param {string} [fallback]
 */
export function formatVoucherRef(value, fallback = '-') {
  const raw = String(value || '').trim();
  if (!raw) return fallback;

  const sep = raw.includes('/') ? '/' : '-';
  if (!raw.includes(sep)) return raw;

  const parts = raw.split(sep);
  if (parts.length < 2) return raw;

  return parts
    .map((part, i) => formatRefPart(part, parts[i - 1], parts[i + 1]))
    .join(sep);
}
