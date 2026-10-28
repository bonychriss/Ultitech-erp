const PENDING_STATUSES = ['quotation', 'draft', 'sent'];
const CONVERTED_STATUSES = [
  'confirmed',
  'invoiced',
  'processing',
  'shipped',
  'delivered',
  'paid',
  'completed',
];

function formatMoney(value, currency = 'TZS') {
  const code = String(currency || 'TZS').toUpperCase();
  const amount = Number(value) || 0;
  return `${code} ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatCount(value) {
  return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Number(value) || 0);
}

function parseQuotationDate(quotation) {
  const raw = quotation?.created_at || '';
  if (!raw) return null;
  const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T');
  const date = new Date(normalized);
  return Number.isNaN(date.getTime()) ? null : date;
}

function formatShortDate(date) {
  return date.toLocaleDateString('en-GB', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  });
}

function formatDurationRange(rows) {
  let minDate = null;
  let maxDate = null;

  rows.forEach((row) => {
    const date = parseQuotationDate(row);
    if (!date) return;
    if (!minDate || date < minDate) minDate = date;
    if (!maxDate || date > maxDate) maxDate = date;
  });

  if (!minDate || !maxDate) {
    return null;
  }

  const dayMs = 24 * 60 * 60 * 1000;
  const days = Math.max(1, Math.round((maxDate - minDate) / dayMs) + 1);
  const sameDay = minDate.toDateString() === maxDate.toDateString();

  return {
    from: formatShortDate(minDate),
    to: formatShortDate(maxDate),
    days,
    label: sameDay
      ? `${formatShortDate(minDate)} (1 day)`
      : `${formatShortDate(minDate)} to ${formatShortDate(maxDate)} (${days} days)`,
  };
}

function buildPeriodBreakdown(rows, defaultCurrency) {
  const now = new Date();
  const currentMonth = now.getMonth();
  const currentYear = now.getFullYear();
  const monthLabel = now.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

  let monthCount = 0;
  let monthValue = 0;
  let yearCount = 0;
  let yearValue = 0;
  let allCount = 0;
  let allValue = 0;

  rows.forEach((row) => {
    const amount = Number(row.total_amount) || 0;
    const date = parseQuotationDate(row);

    allCount += 1;
    allValue += amount;

    if (!date) return;

    if (date.getFullYear() === currentYear) {
      yearCount += 1;
      yearValue += amount;
      if (date.getMonth() === currentMonth) {
        monthCount += 1;
        monthValue += amount;
      }
    }
  });

  return [
    {
      key: 'month',
      label: 'This month',
      sublabel: monthLabel,
      count: monthCount,
      value: monthValue,
      countDisplay: formatCount(monthCount),
      amountDisplay: formatMoney(monthValue, defaultCurrency),
    },
    {
      key: 'year',
      label: 'This year',
      sublabel: String(currentYear),
      count: yearCount,
      value: yearValue,
      countDisplay: formatCount(yearCount),
      amountDisplay: formatMoney(yearValue, defaultCurrency),
    },
    {
      key: 'all',
      label: 'In this list',
      sublabel: 'Matching current filters',
      count: allCount,
      value: allValue,
      countDisplay: formatCount(allCount),
      amountDisplay: formatMoney(allValue, defaultCurrency),
    },
  ];
}

function isPendingStatus(status) {
  return PENDING_STATUSES.includes(String(status || '').toLowerCase().trim());
}

function isConvertedStatus(status) {
  return CONVERTED_STATUSES.includes(String(status || '').toLowerCase().trim());
}

function hasActiveListFilters(filters = {}) {
  return Boolean(filters.search?.trim() || filters.statusFilter || filters.myQuotationsOnly);
}

function filterFootnote(filters) {
  return hasActiveListFilters(filters)
    ? 'These figures follow your active search and filters only (same rows as the table).'
    : 'These figures match the quotations currently shown in the table.';
}

function buildTrace({
  title,
  headline,
  mode,
  rows,
  defaultCurrency,
  confirmation,
  footnote,
}) {
  return {
    title,
    headline: String(headline),
    mode,
    currency: defaultCurrency,
    confirmation,
    footnote,
    duration: formatDurationRange(rows),
    periods: buildPeriodBreakdown(rows, defaultCurrency),
  };
}

export function formatQuotationKpiHeadline(trace) {
  if (trace.mode === 'value') {
    const amount = Number.parseFloat(trace.headline);
    if (Number.isFinite(amount)) {
      return formatMoney(amount, trace.currency ?? 'TZS');
    }
  }

  return trace.headline;
}

function buildTotalQuotationsTrace(rows, filters, defaultCurrency) {
  const count = rows.length;
  const total = rows.reduce((sum, row) => sum + (Number(row.total_amount) || 0), 0);

  return buildTrace({
    title: 'Total quotations',
    headline: count,
    mode: 'count',
    rows,
    defaultCurrency,
    confirmation: count === 0
      ? (hasActiveListFilters(filters)
        ? 'No quotations match your current search or filters.'
        : 'There are no quotations saved yet.')
      : `${formatCount(count)} quotation${count === 1 ? '' : 's'} in this list worth ${formatMoney(total, defaultCurrency)}.`,
    footnote: filterFootnote(filters),
  });
}

function buildTotalValueTrace(rows, filters, defaultCurrency) {
  const total = rows.reduce((sum, row) => sum + (Number(row.total_amount) || 0), 0);

  return buildTrace({
    title: 'Total value',
    headline: total,
    mode: 'value',
    rows,
    defaultCurrency,
    confirmation: rows.length === 0
      ? 'No quotation amounts to total for the current list.'
      : `Combined value of listed quotations is ${formatMoney(total, defaultCurrency)}.`,
    footnote: `${filterFootnote(filters)} Amounts are shown in ${defaultCurrency}.`,
  });
}

function buildPendingTrace(rows, filters, defaultCurrency) {
  const pendingRows = rows.filter((row) => isPendingStatus(row.status));
  const count = pendingRows.length;
  const total = pendingRows.reduce((sum, row) => sum + (Number(row.total_amount) || 0), 0);

  return buildTrace({
    title: 'Pending',
    headline: count,
    mode: 'count',
    rows: pendingRows,
    defaultCurrency,
    confirmation: count === 0
      ? 'No listed quotations are waiting in Quotation, Draft, or Sent status.'
      : `${formatCount(count)} pending quotation${count === 1 ? '' : 's'} worth ${formatMoney(total, defaultCurrency)}.`,
    footnote: `${filterFootnote(filters)} Pending includes Quotation, Draft, and Sent statuses.`,
  });
}

function buildConvertedTrace(rows, filters, defaultCurrency) {
  const convertedRows = rows.filter((row) => isConvertedStatus(row.status));
  const count = convertedRows.length;
  const total = convertedRows.reduce((sum, row) => sum + (Number(row.total_amount) || 0), 0);

  return buildTrace({
    title: 'Converted',
    headline: count,
    mode: 'count',
    rows: convertedRows,
    defaultCurrency,
    confirmation: count === 0
      ? 'No listed quotations have moved past pending yet.'
      : `${formatCount(count)} converted quotation${count === 1 ? '' : 's'} worth ${formatMoney(total, defaultCurrency)}.`,
    footnote: `${filterFootnote(filters)} Converted includes Confirmed, Invoiced, and later fulfilment statuses.`,
  });
}

export function resolveQuotationKpiTrace(key, context) {
  const {
    filteredQuotations = [],
    filters = {},
    defaultCurrency = 'TZS',
  } = context;

  const rows = filteredQuotations;

  switch (key) {
    case 'totalQuotations':
      return buildTotalQuotationsTrace(rows, filters, defaultCurrency);
    case 'totalValue':
      return buildTotalValueTrace(rows, filters, defaultCurrency);
    case 'pending':
      return buildPendingTrace(rows, filters, defaultCurrency);
    case 'converted':
    case 'listedNow':
      return key === 'listedNow'
        ? buildTotalQuotationsTrace(rows, filters, defaultCurrency)
        : buildConvertedTrace(rows, filters, defaultCurrency);
    default:
      return null;
  }
}
