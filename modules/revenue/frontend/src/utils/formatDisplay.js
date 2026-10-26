/** Display helpers for revenue desk (digit dates, clean voucher refs). */

const MONTH_ABBR = {
  jan: '1', feb: '2', mar: '3', apr: '4', may: '5', jun: '6',
  jul: '7', aug: '8', sep: '9', oct: '10', nov: '11', dec: '12',
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
 * Display voucher / ref codes with digit months and no leading zeros on the sequence.
 * e.g. REV-2026-Oct-004 ? REV-2026-10-4
 * @param {string|null|undefined} value
 * @param {string} [fallback]
 */
export function formatVoucherRef(value, fallback = '-') {
  const raw = String(value || '').trim();
  if (!raw) return fallback;

  const parts = raw.split('-');
  if (parts.length < 2) return raw;

  const out = parts.map((part) => {
    const p = String(part || '').trim();
    if (!p) return p;

    const monthKey = p.toLowerCase();
    if (MONTH_ABBR[monthKey]) {
      return MONTH_ABBR[monthKey];
    }

    if (/^\d+$/.test(p)) {
      // Keep 4-digit years intact
      if (p.length === 4 && Number(p) >= 1900 && Number(p) <= 2100) {
        return p;
      }
      // Strip leading zeros: 004 ? 4, 0238 ? 238
      return String(parseInt(p, 10));
    }

    return p;
  });

  return out.join('-');
}
