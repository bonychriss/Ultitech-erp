/**
 * Mirror of expenses_listed_row_payment_bucket / expenses_build_listed_summary_stats
 * so summary cards match the filtered table.
 */

function paymentBucket(row) {
  const method = String(row?.payment_method ?? '').toLowerCase().trim();
  const label = String(row?.payment_method_label ?? '').toLowerCase().trim();
  const haystack = `${method} ${label}`;

  if (haystack !== ' ' && (haystack.includes('credit') || haystack.includes('payable'))) {
    return 'payables';
  }
  if (haystack !== ' ' && haystack.includes('cash')) {
    return 'cash';
  }
  if (
    haystack !== ' '
    && (
      haystack.includes('bank')
      || haystack.includes('mobile')
      || haystack.includes('cheque')
      || haystack.includes('check')
      || haystack.includes('transfer')
    )
  ) {
    return 'bank';
  }
  return 'other';
}

function isSpendRow(row) {
  const display = String(row?.display_status ?? '').toLowerCase().trim();
  const status = String(row?.status ?? '').toLowerCase().trim();
  if (Number(row?.is_posted) === 1 || display === 'posted' || status === 'posted') {
    return true;
  }
  if (Number(row?.is_paid) === 1 || display === 'paid' || status === 'paid') {
    return true;
  }
  return false;
}

function inSummary(row) {
  const display = String(row?.display_status ?? '').toLowerCase().trim();
  const status = String(row?.status ?? '').toLowerCase().trim();
  if (['draft', 'rejected', 'deleted'].includes(display) || ['draft', 'rejected', 'deleted'].includes(status)) {
    return false;
  }
  if (paymentBucket(row) === 'payables') {
    return true;
  }
  return isSpendRow(row);
}

export function buildListedSummaryStats(expenses = []) {
  let totalExclusive = 0;
  let inputVat = 0;
  let totalInclusive = 0;
  let totalBank = 0;
  let totalCash = 0;
  let totalPayables = 0;

  for (const row of Array.isArray(expenses) ? expenses : []) {
    if (!inSummary(row)) continue;
    const amount = Number(row.amount) || 0;
    if (amount <= 0) continue;

    let tax = Math.max(0, Number(row.tax_amount) || 0);
    if (tax > amount) tax = amount;
    const exclusive = Math.max(0, amount - tax);

    totalExclusive += exclusive;
    inputVat += tax;
    totalInclusive += amount;

    const bucket = paymentBucket(row);
    if (bucket === 'cash') totalCash += amount;
    else if (bucket === 'payables') totalPayables += amount;
    else totalBank += amount;
  }

  return {
    total_exclusive: Math.round(totalExclusive * 100) / 100,
    input_vat: Math.round(inputVat * 100) / 100,
    total_inclusive: Math.round(totalInclusive * 100) / 100,
    total_bank: Math.round(totalBank * 100) / 100,
    total_cash: Math.round(totalCash * 100) / 100,
    total_payables: Math.round(totalPayables * 100) / 100,
  };
}

function isPurchasesKpiRow(row) {
  const display = String(row?.display_status ?? '').toLowerCase().trim();
  const status = String(row?.status ?? '').toLowerCase().trim();
  if (['draft', 'rejected', 'deleted'].includes(display) || ['draft', 'rejected', 'deleted'].includes(status)) {
    return false;
  }
  return true;
}

function isPurchaseUnpaid(row) {
  if (Number(row?.is_paid) === 1) return false;
  const display = String(row?.display_status ?? '').toLowerCase().trim();
  const status = String(row?.status ?? '').toLowerCase().trim();
  if (display === 'paid' || status === 'paid') return false;
  return true;
}

function purchaseDueDate(row) {
  const raw = String(row?.due_date || row?.payment_due_date || row?.date || '').trim();
  if (!raw) return null;
  const due = new Date(raw.includes('T') ? raw : `${raw}T12:00:00`);
  if (Number.isNaN(due.getTime())) return null;
  due.setHours(0, 0, 0, 0);
  return due;
}

/**
 * Purchases desk KPIs (mirror revenue desk): exclusive, VAT, gross,
 * total purchases, outstanding unpaid, and overdue unpaid.
 */
export function buildPurchasesSummaryStats(expenses = []) {
  let totalExclusive = 0;
  let vat = 0;
  let grossTotal = 0;
  let purchaseCount = 0;
  let outstanding = 0;
  let overdue = 0;

  const today = new Date();
  today.setHours(0, 0, 0, 0);

  for (const row of Array.isArray(expenses) ? expenses : []) {
    if (!isPurchasesKpiRow(row)) continue;
    const amount = Number(row.amount) || 0;
    if (amount <= 0) continue;

    let tax = Math.max(0, Number(row.tax_amount) || 0);
    if (tax > amount) tax = amount;
    const exclusive = Math.max(0, amount - tax);

    totalExclusive += exclusive;
    vat += tax;
    grossTotal += amount;
    purchaseCount += 1;

    if (!isPurchaseUnpaid(row)) continue;
    outstanding += amount;
    const due = purchaseDueDate(row);
    if (due && due < today) {
      overdue += amount;
    }
  }

  const pctBase = grossTotal > 0.009 ? grossTotal : 0;
  return {
    total_exclusive: Math.round(totalExclusive * 100) / 100,
    vat: Math.round(vat * 100) / 100,
    gross_total: Math.round(grossTotal * 100) / 100,
    total_purchases: Math.round(grossTotal * 100) / 100,
    purchase_count: purchaseCount,
    outstanding: Math.round(outstanding * 100) / 100,
    overdue: Math.round(overdue * 100) / 100,
    pct_outstanding: pctBase > 0.009 ? (outstanding / pctBase) * 100 : 0,
    pct_overdue: pctBase > 0.009 ? (overdue / pctBase) * 100 : 0,
  };
}

export function purchasesRowsForTrace(expenses = [], key = '') {
  const rows = (Array.isArray(expenses) ? expenses : []).filter(isPurchasesKpiRow);
  if (key === 'outstanding' || key === 'overdue') {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return rows.filter((row) => {
      if (!isPurchaseUnpaid(row)) return false;
      if (key !== 'overdue') return true;
      const due = purchaseDueDate(row);
      return Boolean(due && due < today);
    });
  }
  return rows;
}

export function summaryRowsForTrace(expenses = []) {
  return (Array.isArray(expenses) ? expenses : []).filter(inSummary);
}

export { paymentBucket, inSummary };
