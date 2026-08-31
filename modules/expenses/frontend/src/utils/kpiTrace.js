function formatMoney(value, currency = 'TZS') {
  const code = String(currency || 'TZS').replace(/^TSh$/i, 'TZS');
  const amount = Number(value) || 0;
  return `${code} ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function filterCriteria(filters, categories = []) {
  const lines = [
    { label: 'Primary table', value: 'erp_expenses' },
    { label: 'API action', value: 'list (modules/expenses/api/expenses.php)' },
    { label: 'Excluded', value: "status = 'deleted'" },
    { label: 'Result limit', value: '5000 expenses' },
  ];

  if (filters.search?.trim()) {
    lines.push({ label: 'Search', value: `Expense #, payee, or description contains "${filters.search.trim()}"` });
  }
  if (filters.status) {
    const statusLabels = {
      posted: 'Posted',
      unposted: 'Unposted',
      draft: 'Draft',
      rejected: 'Rejected',
      pending: 'Pending approval',
    };
    lines.push({ label: 'Status', value: statusLabels[filters.status] || filters.status });
  }
  if (filters.category) {
    const cat = categories.find((c) => String(c.id) === String(filters.category));
    lines.push({ label: 'Account', value: cat?.name || `Account id ${filters.category}` });
  }
  if (filters.date_from) {
    lines.push({ label: 'Date from', value: filters.date_from });
  }
  if (filters.date_to) {
    lines.push({ label: 'Date to', value: filters.date_to });
  }
  if (filters.payment_method) {
    lines.push({
      label: 'Payment',
      value: filters.payment_method === 'bank' ? 'Bank transfer' : filters.payment_method,
    });
  }
  if (filters.amount_min) {
    lines.push({ label: 'Min amount', value: filters.amount_min });
  }
  if (filters.amount_max) {
    lines.push({ label: 'Max amount', value: filters.amount_max });
  }

  const hasFilters = Object.keys(filters).some((key) => {
    if (key === 'search') return filters.search?.trim() !== '';
    return String(filters[key] ?? '').trim() !== '';
  });

  if (!hasFilters) {
    lines.push({ label: 'Active filters', value: 'None (all non-deleted expenses)' });
  }

  return lines;
}

function mapExpenseToTraceItem(expense) {
  return {
    id: expense.id,
    expenseNumber: expense.expense_number || '',
    payee: expense.display_name || expense.payee || expense.description || '',
    date: expense.date || '',
    account: expense.category_name || expense.main_account_name || '',
    payment: expense.payment_method_label || '',
    amount: Number(expense.amount) || 0,
    currency: expense.currency_display || expense.currency_code || 'TZS',
    isPosted: Number(expense.is_posted) === 1,
    contribution: Number(expense.amount) || 0,
    note: 'Shown in current filtered table',
  };
}

export function buildListedNowTrace(expenses, filters, categories = []) {
  const totalSpend = expenses.reduce((sum, row) => sum + (Number(row.amount) || 0), 0);
  const currency = expenses[0]?.currency_display || expenses[0]?.currency_code || 'TZS';
  const count = expenses.length;

  return {
    title: 'Listed now',
    headline: String(count),
    currency,
    source: 'erp_expenses',
    method: 'Rows returned by the filtered list API for the table below',
    criteria: filterCriteria(filters, categories),
    confirmation: count === 0
      ? 'No expenses match your current search or filters. Try clearing them to see more results.'
      : `${count} ${count === 1 ? 'expense is' : 'expenses are'} shown in the table right now, based on your search and filters.`,
    viaAi: false,
    items: expenses.map(mapExpenseToTraceItem),
    footnote: count > 0
      ? `Total in this list: ${formatMoney(totalSpend, currency)}. This may differ from the summary cards if filters are active.`
      : 'No expenses matched your filters. Try clearing search or filters.',
  };
}

export function formatKpiHeadline(trace) {
  if (trace.title === 'Posted this month' || trace.title === 'Total records' || trace.title === 'Listed now') {
    return trace.headline;
  }

  const amount = Number.parseFloat(trace.headline);
  if (Number.isFinite(amount)) {
    return formatMoney(amount, trace.currency ?? 'TZS');
  }

  return trace.headline;
}

function normalizeTrace(trace) {
  return {
    ...trace,
    items: Array.isArray(trace.items) ? trace.items : [],
    criteria: Array.isArray(trace.criteria) ? trace.criteria : [],
  };
}

function buildSummaryTraceFallback(key, init, expenses) {
  const stats = init?.stats ?? {};
  const monthLabel = stats.current_month_label || 'this month';

  if (key === 'postedThisMonth') {
    return {
      title: 'Posted this month',
      headline: String(stats.posted_month_count ?? 0),
      source: 'erp_expenses',
      method: 'COUNT(*) where status is not deleted, is_posted = 1, and date is in the current calendar month',
      criteria: [{ label: 'Month', value: monthLabel }],
      confirmation: `This counts expenses that were posted in ${monthLabel}.`,
      viaAi: false,
      items: [],
      footnote: 'Reload the page if this breakdown looks incomplete.',
    };
  }

  if (key === 'monthlySpend') {
    return {
      title: 'Monthly spend',
      headline: String(stats.spend_month ?? 0),
      currency: 'TZS',
      source: 'erp_expenses',
      method: 'SUM(amount) on posted, non-deleted expenses dated in the current calendar month',
      criteria: [{ label: 'Month', value: monthLabel }],
      confirmation: `This is the total spent on posted expenses in ${monthLabel}.`,
      viaAi: false,
      items: [],
      footnote: 'Reload the page if this breakdown looks incomplete.',
    };
  }

  return {
    title: 'Total records',
    headline: String(stats.total_count ?? 0),
    source: 'erp_expenses',
    method: 'COUNT(*) of all expenses except those with status deleted',
    criteria: [],
    confirmation: 'This is how many expenses you have saved. Deleted ones are not counted.',
    viaAi: false,
    items: expenses.map(mapExpenseToTraceItem),
    footnote: 'Reload the page if this breakdown looks incomplete.',
  };
}

export function resolveKpiTrace(key, init, expenses, filters) {
  if (key === 'listedNow') {
    return buildListedNowTrace(expenses, filters, init?.categories ?? []);
  }

  if (!init) return null;

  const fromApi = init.summaryTraces?.[key];
  if (fromApi) {
    return normalizeTrace(fromApi);
  }

  return normalizeTrace(buildSummaryTraceFallback(key, init, expenses));
}
