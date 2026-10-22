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

export function summaryRowsForTrace(expenses = []) {
  return (Array.isArray(expenses) ? expenses : []).filter(inSummary);
}

export { paymentBucket, inSummary };
