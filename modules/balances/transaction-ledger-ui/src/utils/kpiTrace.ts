import type { LedgerTransaction, TxFilters, TxSummary } from '../types';
import { formatMoney } from './format';

export type KpiTraceKey = 'entries' | 'inflows' | 'outflows' | 'net';

export type KpiTraceCriterion = {
  label: string;
  value: string;
};

export type KpiTraceItem = {
  id: number;
  date: string;
  account: string;
  description: string;
  reference: string;
  typeLabel: string;
  typeClass: string;
  amount: number;
  amountDisplay: string;
  contribution: number;
};

export type KpiTrace = {
  title: string;
  headline: string;
  currency: string;
  confirmation: string;
  method: string;
  criteria: KpiTraceCriterion[];
  items: KpiTraceItem[];
  footnote?: string;
};

function filterCriteria(filters: TxFilters): KpiTraceCriterion[] {
  const lines: KpiTraceCriterion[] = [
    { label: 'Primary table', value: 'account_transactions' },
    { label: 'API action', value: 'list (transaction-ledger-ui/api/index.php)' },
  ];

  if (filters.q.trim() !== '') {
    lines.push({
      label: 'Search',
      value: `Account, description, reference, user, amount, or type contains "${filters.q.trim()}"`,
    });
  }
  if (filters.date_from !== '') {
    lines.push({ label: 'Date from', value: filters.date_from });
  }
  if (filters.date_to !== '') {
    lines.push({ label: 'Date to', value: filters.date_to });
  }
  if (filters.type !== '') {
    lines.push({ label: 'Type', value: filters.type });
  }
  if (filters.amount_min !== '') {
    lines.push({ label: 'Min amount', value: filters.amount_min });
  }
  if (filters.amount_max !== '') {
    lines.push({ label: 'Max amount', value: filters.amount_max });
  }

  const hasFilters =
    filters.q.trim() !== '' ||
    filters.date_from !== '' ||
    filters.date_to !== '' ||
    filters.type !== '' ||
    filters.amount_min !== '' ||
    filters.amount_max !== '';

  if (!hasFilters) {
    lines.push({ label: 'Active filters', value: 'None (all ledger transactions)' });
  }

  return lines;
}

function mapTx(tx: LedgerTransaction): KpiTraceItem {
  return {
    id: tx.id,
    date: tx.transactionDate,
    account: tx.accountName,
    description: tx.description || '—',
    reference: tx.referenceLabel || '—',
    typeLabel: tx.typeLabel,
    typeClass: tx.typeClass,
    amount: tx.amount,
    amountDisplay: tx.amountDisplay,
    contribution: tx.type === 'credit' ? tx.amount : -tx.amount,
  };
}

function partialFootnote(loaded: number, total: number): string | undefined {
  if (total > loaded) {
    return `Showing ${loaded} of ${total} contributing records currently loaded in the table. Switch to “View all” to include every row.`;
  }
  return undefined;
}

export function formatKpiHeadline(trace: KpiTrace): string {
  if (trace.title === 'Entries') {
    return trace.headline;
  }
  const amount = Number.parseFloat(trace.headline);
  if (Number.isFinite(amount)) {
    const abs = Math.abs(amount);
    const formatted = formatMoney(abs, trace.currency);
    return amount < 0 ? `-${formatted}` : formatted;
  }
  return trace.headline;
}

export function resolveKpiTrace(
  key: KpiTraceKey,
  summary: TxSummary | null | undefined,
  transactions: LedgerTransaction[],
  filters: TxFilters,
): KpiTrace | null {
  if (!summary) return null;

  const criteria = filterCriteria(filters);
  const totalEntries = summary.totalEntries;
  const credits = transactions.filter((tx) => tx.type === 'credit');
  const debits = transactions.filter((tx) => tx.type === 'debit');

  if (key === 'entries') {
    return {
      title: 'Entries',
      headline: String(summary.totalEntries),
      currency: 'TZS',
      confirmation:
        summary.totalEntries === 0
          ? 'No transactions match the current filters.'
          : `${summary.totalEntries} ledger ${summary.totalEntries === 1 ? 'entry is' : 'entries are'} included in this count.`,
      method: 'COUNT(*) of account_transactions rows matching the active filters',
      criteria,
      items: transactions.map(mapTx),
      footnote: partialFootnote(transactions.length, totalEntries),
    };
  }

  if (key === 'inflows') {
    return {
      title: 'Inflows',
      headline: String(summary.totalInflows),
      currency: 'TZS',
      confirmation: `Total credit movement is ${formatMoney(summary.totalInflows)} across ${summary.creditCount} credit ${summary.creditCount === 1 ? 'entry' : 'entries'}.`,
      method: "SUM(amount) where type = 'credit' under the active filters",
      criteria,
      items: credits.map(mapTx),
      footnote:
        partialFootnote(credits.length, summary.creditCount) ??
        (credits.length > 0
          ? `Loaded credit total in this list: ${formatMoney(credits.reduce((sum, tx) => sum + tx.amount, 0))}.`
          : undefined),
    };
  }

  if (key === 'outflows') {
    return {
      title: 'Outflows',
      headline: String(summary.totalOutflows),
      currency: 'TZS',
      confirmation: `Total debit movement is ${formatMoney(summary.totalOutflows)} across ${summary.debitCount} debit ${summary.debitCount === 1 ? 'entry' : 'entries'}.`,
      method: "SUM(amount) where type = 'debit' under the active filters",
      criteria,
      items: debits.map(mapTx),
      footnote:
        partialFootnote(debits.length, summary.debitCount) ??
        (debits.length > 0
          ? `Loaded debit total in this list: ${formatMoney(debits.reduce((sum, tx) => sum + tx.amount, 0))}.`
          : undefined),
    };
  }

  const net = summary.netMovement;
  return {
    title: 'Net movement',
    headline: String(net),
    currency: 'TZS',
    confirmation:
      net >= 0
        ? `Net inflow of ${formatMoney(net)} (inflows ${formatMoney(summary.totalInflows)} minus outflows ${formatMoney(summary.totalOutflows)}).`
        : `Net outflow of ${formatMoney(Math.abs(net))} (outflows ${formatMoney(summary.totalOutflows)} minus inflows ${formatMoney(summary.totalInflows)}).`,
    method: 'totalInflows ? totalOutflows from the filtered ledger',
    criteria,
    items: transactions.map(mapTx),
    footnote: partialFootnote(transactions.length, totalEntries),
  };
}
