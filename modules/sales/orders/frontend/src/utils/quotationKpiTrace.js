const PENDING_STATUSES = ['quotation', 'draft', 'sent'];

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
      label: 'Since start',
      sublabel: 'All quotations on system',
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

function buildListedNowTrace(filteredQuotations, filters, defaultCurrency) {
  const count = filteredQuotations.length;
  const total = filteredQuotations.reduce((sum, row) => sum + (Number(row.total_amount) || 0), 0);
  const hasFilters = Boolean(filters.search?.trim() || filters.statusFilter || filters.myQuotationsOnly);

  return buildTrace({
    title: 'Listed now',
    headline: count,
    mode: 'count',
    rows: filteredQuotations,
    defaultCurrency,
    confirmation: count === 0
      ? 'No quotations match your current search or filters.'
      : `${formatCount(count)} listed quotation${count === 1 ? '' : 's'} worth ${formatMoney(total, defaultCurrency)}.`,
    footnote: hasFilters
      ? 'These figures follow your active search and filters only.'
      : 'These figures match the quotations currently shown in the table.',
  });
}

function buildTotalQuotationsTrace(quotations, defaultCurrency) {
  const count = quotations.length;
  const total = quotations.reduce((sum, row) => sum + (Number(row.total_amount) || 0), 0);

  return buildTrace({
    title: 'Total quotations',
    headline: count,
    mode: 'count',
    rows: quotations,
    defaultCurrency,
    confirmation: count === 0
      ? 'There are no quotations saved yet.'
      : `You have ${formatCount(count)} quotation${count === 1 ? '' : 's'} in total worth ${formatMoney(total, defaultCurrency)}.`,
    footnote: 'Counts every quotation saved for this company.',
  });
}

function buildTotalValueTrace(quotations, defaultCurrency) {
  const total = quotations.reduce((sum, row) => sum + (Number(row.total_amount) || 0), 0);

  return buildTrace({
    title: 'Total value',
    headline: total,
    mode: 'value',
    rows: quotations,
    defaultCurrency,
    confirmation: quotations.length === 0
      ? 'No quotation amounts to total yet.'
      : `Combined quotation value on the system is ${formatMoney(total, defaultCurrency)}.`,
    footnote: `Amounts are shown in ${defaultCurrency}.`,
  });
}

function buildPendingTrace(quotations, defaultCurrency) {
  const pendingRows = quotations.filter((row) => isPendingStatus(row.status));
  const count = pendingRows.length;
  const total = pendingRows.reduce((sum, row) => sum + (Number(row.total_amount) || 0), 0);

  return buildTrace({
    title: 'Pending',
    headline: count,
    mode: 'count',
    rows: pendingRows,
    defaultCurrency,
    confirmation: count === 0
      ? 'No quotations are waiting in Quotation, Draft, or Sent status.'
      : `${formatCount(count)} pending quotation${count === 1 ? '' : 's'} worth ${formatMoney(total, defaultCurrency)}.`,
    footnote: 'Pending includes Quotation, Draft, and Sent statuses.',
  });
}

export function resolveQuotationKpiTrace(key, context) {
  const {
    quotations = [],
    filteredQuotations = [],
    filters = {},
    defaultCurrency = 'TZS',
  } = context;

  switch (key) {
    case 'totalQuotations':
      return buildTotalQuotationsTrace(quotations, defaultCurrency);
    case 'totalValue':
      return buildTotalValueTrace(quotations, defaultCurrency);
    case 'pending':
      return buildPendingTrace(quotations, defaultCurrency);
    case 'listedNow':
      return buildListedNowTrace(filteredQuotations, filters, defaultCurrency);
    default:
      return null;
  }
}
