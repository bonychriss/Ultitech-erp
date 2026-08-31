import type { DeskFilters, KpiTrace, KpiTraceItem, KpiTraceKey, PurchaseOrderRow, DeskInit } from '../types';
import { formatMoney } from './format';

function filterLabel(filters: DeskFilters): Array<{ label: string; value: string }> {
  const lines = [
    { label: 'Primary table', value: 'stocks_purchase_orders' },
    { label: 'API action', value: 'list (stock-purchase-payment-desk-ui/api/index.php)' },
    {
      label: 'Included when',
      value: 'All purchase orders (paid and unpaid)',
    },
    { label: 'Result limit', value: '500 purchase orders' },
  ];

  if (filters.q.trim() !== '') {
    lines.push({ label: 'Search', value: `PO number or supplier contains "${filters.q.trim()}"` });
  }
  if (filters.date_from !== '') {
    lines.push({ label: 'Date from', value: filters.date_from });
  }
  if (filters.date_to !== '') {
    lines.push({ label: 'Date to', value: filters.date_to });
  }
  if (filters.payee !== '') {
    lines.push({ label: 'Payee', value: filters.payee });
  }
  if (filters.amount_min !== '') {
    lines.push({ label: 'Min amount', value: filters.amount_min });
  }
  if (filters.amount_max !== '') {
    lines.push({ label: 'Max amount', value: filters.amount_max });
  }

  const hasFilters = filters.q !== ''
    || filters.date_from !== ''
    || filters.date_to !== ''
    || filters.payee !== ''
    || filters.amount_min !== ''
    || filters.amount_max !== '';

  if (!hasFilters) {
    lines.push({ label: 'Active filters', value: 'None (all purchase orders)' });
  }

  return lines;
}

function mapOrderToTraceItem(order: PurchaseOrderRow): KpiTraceItem {
  return {
    id: order.id,
    poNumber: order.poNumber,
    payeeName: order.payeeName,
    createdAt: order.createdAt,
    currency: order.currency,
    amountToPay: order.amountToPay,
    amountPaid: order.amountPaid,
    balanceDue: order.balanceDue,
    paymentStatus: order.paymentStatus,
    contribution: order.balanceDue,
    note: 'Shown in current filtered table',
  };
}

export function buildListedNowTrace(orders: PurchaseOrderRow[], filters: DeskFilters): KpiTrace {
  const totalBalance = orders.reduce((sum, order) => sum + order.balanceDue, 0);
  const currency = orders[0]?.currency ?? 'TZS';

  return {
    title: 'Listed now',
    headline: String(orders.length),
    currency,
    source: 'stocks_purchase_orders + supplier_payments',
    method: 'Rows returned by the filtered list API',
    criteria: filterLabel(filters),
    items: orders.map(mapOrderToTraceItem),
    footnote: orders.length > 0
      ? `Combined balance due in this list: ${formatMoney(totalBalance, currency)}. Paid POs are included with zero balance due.`
      : 'No rows matched the current filters. Try clearing search or filter criteria.',
  };
}

export function formatKpiHeadline(trace: KpiTrace): string {
  if (trace.title === 'Unpaid purchase orders' || trace.title === 'Listed now') {
    return trace.headline;
  }

  const amount = Number.parseFloat(trace.headline);
  if (Number.isFinite(amount)) {
    return formatMoney(amount, trace.currency ?? 'TZS');
  }

  return trace.headline;
}

function normalizeTrace(trace: KpiTrace): KpiTrace {
  return {
    ...trace,
    items: Array.isArray(trace.items) ? trace.items : [],
  };
}

export function buildSummaryTraceFallback(
  key: Exclude<KpiTraceKey, 'listedNow'>,
  init: DeskInit,
  orders: PurchaseOrderRow[],
): KpiTrace {
  const summary = init.summary;
  const currency = summary.currency || 'TZS';

  if (key === 'unpaidPurchaseOrders') {
    return {
      title: 'Unpaid purchase orders',
      headline: String(summary.unpaidCount),
      source: 'stocks_purchase_orders',
      method: 'Count of purchase orders that are not marked paid',
      criteria: [],
      items: orders.map(mapOrderToTraceItem),
      footnote: 'Reload the page if this breakdown looks incomplete.',
    };
  }

  if (key === 'accountsPayable') {
    const apCurrency = summary.accountsPayableCurrency || currency;
    const items = summary.accountsPayableSource === 'unpaid_pos'
      ? orders.map(mapOrderToTraceItem)
      : [{
          poNumber: 'ù',
          payeeName: 'Accounts Payable',
          createdAt: '',
          currency: apCurrency,
          amountToPay: summary.accountsPayable,
          amountPaid: 0,
          balanceDue: summary.accountsPayable,
          contribution: summary.accountsPayable,
          note: 'Ledger account balance',
        }];

    return {
      title: 'Account payables',
      headline: String(summary.accountsPayable),
      currency: apCurrency,
      source: summary.accountsPayableSource === 'unpaid_pos'
        ? 'stocks_purchase_orders'
        : 'financial_accounts / balances module',
      method: summary.accountsPayableSource === 'unpaid_pos'
        ? 'Estimated sum of unpaid purchase order totals'
        : 'Ledger balance from Accounts Payable account',
      criteria: [],
      items,
    };
  }

  return {
    title: 'Overdue payables',
    headline: String(summary.overduePayables),
    currency: summary.overduePayablesCurrency || currency,
    source: 'stocks_purchase_orders',
    method: 'Sum of PO totals that are unpaid and overdue',
    criteria: [],
    items: orders.map(mapOrderToTraceItem),
    footnote: 'Reload the page if this breakdown looks incomplete.',
  };
}

export function resolveKpiTrace(
  key: KpiTraceKey,
  init: DeskInit | null,
  orders: PurchaseOrderRow[],
  filters: DeskFilters,
): KpiTrace | null {
  if (key === 'listedNow') {
    return buildListedNowTrace(orders, filters);
  }

  if (!init) return null;

  const traceKey = key as Exclude<KpiTraceKey, 'listedNow'>;
  const fromApi = init.summaryTraces?.[traceKey];
  if (fromApi) {
    return normalizeTrace(fromApi);
  }

  return buildSummaryTraceFallback(traceKey, init, orders);
}
