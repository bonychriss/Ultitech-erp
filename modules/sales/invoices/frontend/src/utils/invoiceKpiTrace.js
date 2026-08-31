function formatMoney(value, currency = 'TZS') {
  const code = String(currency || 'TZS').toUpperCase();
  const amount = Number(value) || 0;
  return `${code} ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatCount(value) {
  return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Number(value) || 0);
}

function parseInvoiceDate(invoice) {
  const raw = invoice?.invoice_date || invoice?.created_at || '';
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
    const date = parseInvoiceDate(row);
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

function rowAmount(row, amountField) {
  if (amountField === 'balance_due') {
    return Number(row.balance_due) || 0;
  }
  return Number(row.total_amount) || 0;
}

function buildPeriodBreakdown(rows, defaultCurrency, amountField = 'total_amount') {
  const now = new Date();
  const currentMonth = now.getMonth();
  const currentYear = now.getFullYear();
  const monthLabel = now.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
  const valueLabel = amountField === 'balance_due' ? 'balance' : 'value';

  let monthCount = 0;
  let monthValue = 0;
  let yearCount = 0;
  let yearValue = 0;
  let allCount = 0;
  let allValue = 0;

  rows.forEach((row) => {
    const amount = rowAmount(row, amountField);
    const date = parseInvoiceDate(row);

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
      valueLabel,
    },
    {
      key: 'year',
      label: 'This year',
      sublabel: String(currentYear),
      count: yearCount,
      value: yearValue,
      countDisplay: formatCount(yearCount),
      amountDisplay: formatMoney(yearValue, defaultCurrency),
      valueLabel,
    },
    {
      key: 'all',
      label: 'Since start',
      sublabel: 'All invoices on system',
      count: allCount,
      value: allValue,
      countDisplay: formatCount(allCount),
      amountDisplay: formatMoney(allValue, defaultCurrency),
      valueLabel,
    },
  ];
}

function isOutstandingInvoice(invoice) {
  return (Number(invoice.balance_due) || 0) > 0.005;
}

function buildTrace({
  title,
  headline,
  mode,
  rows,
  defaultCurrency,
  confirmation,
  footnote,
  amountField = 'total_amount',
  durationLabel = 'Invoice period',
}) {
  return {
    title,
    headline: String(headline),
    mode,
    currency: defaultCurrency,
    confirmation,
    footnote,
    durationLabel,
    duration: formatDurationRange(rows),
    periods: buildPeriodBreakdown(rows, defaultCurrency, amountField),
    entitySingular: 'invoice',
    entityPlural: 'invoices',
  };
}

export function formatInvoiceKpiHeadline(trace) {
  if (trace.mode === 'value') {
    const amount = Number.parseFloat(trace.headline);
    if (Number.isFinite(amount)) {
      return formatMoney(amount, trace.currency ?? 'TZS');
    }
  }

  return trace.headline;
}

function buildListedNowTrace(filteredInvoices, filters, defaultCurrency) {
  const count = filteredInvoices.length;
  const total = filteredInvoices.reduce((sum, row) => sum + (Number(row.total_amount) || 0), 0);
  const hasFilters = Boolean(filters.search?.trim() || filters.statusFilter || filters.myInvoicesOnly);

  return buildTrace({
    title: 'Listed now',
    headline: count,
    mode: 'count',
    rows: filteredInvoices,
    defaultCurrency,
    confirmation: count === 0
      ? 'No invoices match your current search or filters.'
      : `${formatCount(count)} listed invoice${count === 1 ? '' : 's'} worth ${formatMoney(total, defaultCurrency)}.`,
    footnote: hasFilters
      ? 'These figures follow your active search and filters only.'
      : 'These figures match the invoices currently shown in the table.',
  });
}

function buildTotalInvoicesTrace(invoices, defaultCurrency) {
  const count = invoices.length;
  const total = invoices.reduce((sum, row) => sum + (Number(row.total_amount) || 0), 0);

  return buildTrace({
    title: 'Total invoices',
    headline: count,
    mode: 'count',
    rows: invoices,
    defaultCurrency,
    confirmation: count === 0
      ? 'There are no invoices saved yet.'
      : `You have ${formatCount(count)} invoice${count === 1 ? '' : 's'} in total worth ${formatMoney(total, defaultCurrency)}.`,
    footnote: 'Counts every invoice saved for this company.',
  });
}

function buildTotalValueTrace(invoices, defaultCurrency) {
  const total = invoices.reduce((sum, row) => sum + (Number(row.total_amount) || 0), 0);

  return buildTrace({
    title: 'Total value',
    headline: total,
    mode: 'value',
    rows: invoices,
    defaultCurrency,
    confirmation: invoices.length === 0
      ? 'No invoice amounts to total yet.'
      : `Combined invoice value on the system is ${formatMoney(total, defaultCurrency)}.`,
    footnote: `Amounts are shown in ${defaultCurrency}.`,
  });
}

function buildOutstandingTrace(invoices, defaultCurrency) {
  const outstandingRows = invoices.filter(isOutstandingInvoice);
  const count = outstandingRows.length;
  const totalBalance = outstandingRows.reduce((sum, row) => sum + (Number(row.balance_due) || 0), 0);

  return buildTrace({
    title: 'Outstanding',
    headline: count,
    mode: 'count',
    rows: outstandingRows,
    defaultCurrency,
    amountField: 'balance_due',
    confirmation: count === 0
      ? 'No invoices currently have an outstanding balance.'
      : `${formatCount(count)} invoice${count === 1 ? '' : 's'} with outstanding balance totalling ${formatMoney(totalBalance, defaultCurrency)}.`,
    footnote: 'Outstanding includes invoices with a balance due greater than zero.',
  });
}

export function resolveInvoiceKpiTrace(key, context) {
  const {
    invoices = [],
    filteredInvoices = [],
    filters = {},
    defaultCurrency = 'TZS',
  } = context;

  switch (key) {
    case 'totalInvoices':
      return buildTotalInvoicesTrace(invoices, defaultCurrency);
    case 'totalValue':
      return buildTotalValueTrace(invoices, defaultCurrency);
    case 'outstanding':
      return buildOutstandingTrace(invoices, defaultCurrency);
    case 'listedNow':
      return buildListedNowTrace(filteredInvoices, filters, defaultCurrency);
    default:
      return null;
  }
}
