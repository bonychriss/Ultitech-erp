function formatMoney(value, currency = 'TZS') {

  const amount = Number(value) || 0;

  return `${currency} ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

}



function isCurrentMonth(dateStr) {

  if (!dateStr) return false;

  const normalized = dateStr.includes('T') ? dateStr : `${dateStr}T12:00:00`;

  const date = new Date(normalized);

  const now = new Date();

  return date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth();

}



function isOverdueInvoice(row) {

  const due = row.due_date || '';

  if (!due) return false;

  const normalized = due.includes('T') ? due : `${due}T12:00:00`;

  const dueDate = new Date(normalized);

  const today = new Date();

  today.setHours(0, 0, 0, 0);

  dueDate.setHours(0, 0, 0, 0);

  return dueDate < today && Number(row.balance_due) > 0.009;

}



function filterCriteria(filters, init) {

  const lines = [

    { label: 'Primary table', value: 'revenue_entries' },

    { label: 'API', value: 'modules/revenue/api/entries.php' },

    { label: 'Result limit', value: '5000 entries' },

  ];



  if (filters.search?.trim()) {

    lines.push({ label: 'Search', value: filters.search.trim() });

  }

  if (filters.status) {

    const opt = (init?.status_options || []).find((o) => o.value === filters.status);

    lines.push({ label: 'Status', value: opt?.label || filters.status });

  }

  if (filters.type) {

    const opt = (init?.type_options || []).find((o) => o.value === filters.type);

    lines.push({ label: 'Type', value: opt?.label || filters.type });

  }

  if (filters.customer_id) {

    const cust = (init?.customers || []).find((c) => String(c.id) === String(filters.customer_id));

    lines.push({ label: 'Customer', value: cust?.name || `Customer #${filters.customer_id}` });

  }

  if (filters.payment) {

    lines.push({ label: 'Payment mode', value: filters.payment });

  }

  if (filters.date_from) {

    lines.push({ label: 'Date from', value: filters.date_from });

  }

  if (filters.date_to) {

    lines.push({ label: 'Date to', value: filters.date_to });

  }



  const hasFilters = Object.keys(filters).some((key) => {

    if (['sort', 'dir', 'tab'].includes(key)) return false;

    if (key === 'search') return filters.search?.trim() !== '';

    return String(filters[key] ?? '').trim() !== '';

  });



  if (!hasFilters) {

    lines.push({ label: 'Active filters', value: 'None (all revenue entries)' });

  }



  return lines;

}



function invoiceFilterCriteria(filters, init) {

  const lines = [

    { label: 'Primary table', value: 'invoices' },

    { label: 'API', value: 'modules/revenue/api/entries.php (invoice_kpi)' },

  ];



  if (filters.search?.trim()) {

    lines.push({ label: 'Search', value: filters.search.trim() });

  }

  if (filters.customer_id) {

    const cust = (init?.customers || []).find((c) => String(c.id) === String(filters.customer_id));

    lines.push({ label: 'Customer', value: cust?.name || `Customer #${filters.customer_id}` });

  }

  if (filters.date_from) {

    lines.push({ label: 'Invoice date from', value: filters.date_from });

  }

  if (filters.date_to) {

    lines.push({ label: 'Invoice date to', value: filters.date_to });

  }



  const hasFilters = (filters.search?.trim() !== '')

    || String(filters.customer_id ?? '').trim() !== ''

    || String(filters.date_from ?? '').trim() !== ''

    || String(filters.date_to ?? '').trim() !== '';



  if (!hasFilters) {

    lines.push({ label: 'Active filters', value: 'None (all invoices)' });

  }



  return lines;

}



function mapEntryToTraceItem(entry, contribution, note) {

  return {

    id: entry.id,

    voucherNumber: entry.voucher_number || '',

    customer: entry.customer_display || entry.customer_name || '',

    date: entry.entry_date || '',

    type: entry.type_label || '',

    status: entry.status_label || '',

    amount: Number(entry.amount_total) || 0,

    contribution: Number(contribution) || 0,

    note,

  };

}



function mapInvoiceToTraceItem(row, contribution, note) {

  return {

    id: row.id,

    voucherNumber: row.invoice_number || '',

    customer: row.customer_name || '',

    date: row.invoice_date || '',

    type: 'Invoice',

    status: row.status || (row.is_paid ? 'Paid' : 'Unpaid'),

    amount: Number(row.total_amount) || 0,

    contribution: Number(contribution) || 0,

    note,

  };

}



function buildContext(desk) {

  const kpi = desk?.kpi || {};

  const month = desk?.month || {};

  const invoiceKpi = desk?.invoice_kpi || {};

  const entries = Array.isArray(desk?.data) ? desk.data : [];



  return {

    sum_net: kpi.sum_net ?? 0,

    sum_vat: kpi.sum_vat ?? 0,

    sum_total: kpi.sum_total ?? 0,

    outstanding: kpi.outstanding ?? 0,

    month_revenue: month.revenue ?? 0,

    month_count: month.count ?? 0,

    entry_count: entries.length,

    invoice_total: invoiceKpi.total ?? 0,

    invoice_paid: invoiceKpi.paid ?? 0,

    invoice_outstanding: invoiceKpi.outstanding ?? 0,

    invoice_overdue: invoiceKpi.overdue ?? 0,

    invoice_count: invoiceKpi.count ?? 0,

    invoice_pct_outstanding: invoiceKpi.pct_outstanding ?? 0,

    invoice_pct_overdue: invoiceKpi.pct_overdue ?? 0,

  };

}



function baseTrace(key, title, headline, method, desk, filters, init, items, footnote = '', source = 'revenue_entries') {

  const context = buildContext(desk);

  const criteria = source === 'invoices'

    ? invoiceFilterCriteria(filters, init)

    : filterCriteria(filters, init);



  return {

    key,

    title,

    headline: String(headline),

    currency: 'TZS',

    source,

    method,

    criteria,

    confirmation: '',

    viaAi: false,

    items,

    footnote,

    context,

    illustration: key,

  };

}



export function formatKpiHeadline(trace) {

  const amount = Number.parseFloat(trace.headline);

  if (Number.isFinite(amount)) {

    return formatMoney(amount, trace.currency ?? 'TZS');

  }

  return trace.headline;

}



export function resolveKpiTrace(key, init, desk, filters) {

  if (!desk) return null;



  const entries = Array.isArray(desk.data) ? desk.data : [];

  const kpi = desk.kpi || {};

  const month = desk.month || {};

  const invoiceKpi = desk.invoice_kpi || {};

  const invoiceRows = Array.isArray(invoiceKpi.trace_rows) ? invoiceKpi.trace_rows : [];

  const context = buildContext(desk);

  const monthLabel = new Date().toLocaleDateString('en-US', { month: 'long', year: 'numeric' });



  if (key === 'totalNet') {

    return {

      ...baseTrace(

        key,

        'Total Revenue (Net)',

        kpi.sum_net ?? 0,

        'SUM(amount_exclusive) on revenue entries matching your current filters',

        desk,

        filters,

        init,

        entries.map((row) => mapEntryToTraceItem(row, row.amount_exclusive, 'Net amount on this entry')),

      ),

      confirmation: `Net revenue is ${formatMoney(kpi.sum_net)} across ${entries.length} matching entries.`,

      context,

    };

  }



  if (key === 'totalVat') {

    return {

      ...baseTrace(

        key,

        'Total Tax (VAT)',

        kpi.sum_vat ?? 0,

        'SUM(vat_amount) on revenue entries matching your current filters',

        desk,

        filters,

        init,

        entries.map((row) => mapEntryToTraceItem(row, row.vat_amount, 'VAT on this entry')),

      ),

      confirmation: `Total VAT is ${formatMoney(kpi.sum_vat)} across ${entries.length} matching entries.`,

      context,

    };

  }



  if (key === 'totalInclTax') {

    return {

      ...baseTrace(

        key,

        'Total (Incl. Tax)',

        kpi.sum_total ?? 0,

        'SUM(amount_total) on revenue entries matching your current filters',

        desk,

        filters,

        init,

        entries.map((row) => mapEntryToTraceItem(row, row.amount_total, 'Gross total on this entry')),

      ),

      confirmation: `Gross revenue is ${formatMoney(kpi.sum_total)} across ${entries.length} matching entries.`,

      context,

    };

  }



  if (key === 'outstandingAr') {

    const outstandingEntries = entries.filter((row) => Number(row.balance_due) > 0.009);

    return {

      ...baseTrace(

        key,

        'Outstanding (AR)',

        kpi.outstanding ?? 0,

        'SUM(amount_total - total_paid) for non-pending entries with a remaining balance',

        desk,

        filters,

        init,

        outstandingEntries.map((row) => mapEntryToTraceItem(row, row.balance_due, 'Outstanding balance')),

        outstandingEntries.length < entries.length

          ? `Showing ${outstandingEntries.length} entries with a balance due.`

          : '',

      ),

      confirmation: Number(kpi.outstanding) > 0.009

        ? `${formatMoney(kpi.outstanding)} is still outstanding on accounts receivable.`

        : 'All matching entries are fully paid.',

      context,

    };

  }



  if (key === 'thisMonth') {

    const monthEntries = entries.filter((row) => isCurrentMonth(row.entry_date));

    return {

      ...baseTrace(

        key,

        'This Month',

        month.revenue ?? 0,

        `SUM(amount_total) where entry_date is in ${monthLabel}`,

        desk,

        filters,

        init,

        monthEntries.map((row) => mapEntryToTraceItem(row, row.amount_total, `Dated in ${monthLabel}`)),

        `${month.count ?? monthEntries.length} entries dated in ${monthLabel}.`,

      ),

      confirmation: `${month.count ?? monthEntries.length} entries in ${monthLabel} total ${formatMoney(month.revenue)}.`,

      context,

    };

  }



  if (key === 'totalInvoices') {

    return {

      ...baseTrace(

        key,

        'Total Invoices',

        invoiceKpi.total ?? 0,

        'SUM(total_amount) on sales invoices matching date, customer, and search filters',

        desk,

        filters,

        init,

        invoiceRows.map((row) => mapInvoiceToTraceItem(row, row.total_amount, 'Invoice total')),

        invoiceKpi.count

          ? `${invoiceKpi.count} invoice${invoiceKpi.count === 1 ? '' : 's'} in scope.`

          : '',

        'invoices',

      ),

      confirmation: `Total invoice value is ${formatMoney(invoiceKpi.total)} across ${invoiceKpi.count ?? 0} invoices.`,

      context,

    };

  }



  if (key === 'outstandingInvoices') {

    const openRows = invoiceRows.filter((row) => Number(row.balance_due) > 0.009);

    return {

      ...baseTrace(

        key,

        'Outstanding Invoices',

        invoiceKpi.outstanding ?? 0,

        'SUM(balance_due) where the invoice still has an unpaid balance',

        desk,

        filters,

        init,

        openRows.map((row) => mapInvoiceToTraceItem(row, row.balance_due, 'Unpaid balance')),

        openRows.length < invoiceRows.length

          ? `${openRows.length} of ${invoiceRows.length} sample invoices shown have a balance due.`

          : '',

        'invoices',

      ),

      confirmation: `${formatMoney(invoiceKpi.outstanding)} remains outstanding (${Number(invoiceKpi.pct_outstanding || 0).toFixed(1)}% of invoice total).`,

      context,

    };

  }



  if (key === 'overdueInvoices') {

    const overdueRows = invoiceRows.filter((row) => isOverdueInvoice(row));

    return {

      ...baseTrace(

        key,

        'Overdue Invoices',

        invoiceKpi.overdue ?? 0,

        'SUM(balance_due) where due_date is before today and balance is still unpaid',

        desk,

        filters,

        init,

        overdueRows.map((row) => mapInvoiceToTraceItem(row, row.balance_due, 'Overdue balance')),

        overdueRows.length

          ? `${overdueRows.length} overdue invoice${overdueRows.length === 1 ? '' : 's'} in sample breakdown.`

          : 'No overdue invoices in the current sample.',

        'invoices',

      ),

      confirmation: `${formatMoney(invoiceKpi.overdue)} is overdue (${Number(invoiceKpi.pct_overdue || 0).toFixed(1)}% of invoice total).`,

      context,

    };

  }



  return null;

}


