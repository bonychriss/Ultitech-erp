import { useCallback, useEffect, useRef, useState } from 'react';
import {
  ArrowDownCircle,
  ArrowDownLeft,
  ArrowLeftRight,
  ArrowUpCircle,
  ArrowUpRight,
  ClipboardList,
  Inbox,
  ListChecks,
  Loader2,
  Search,
  Sparkles,
  X,
} from 'lucide-react';
import { fetchAiSearch, fetchInit, fetchTransactions } from '../api';
import KpiTraceModal from '../components/KpiTraceModal';
import type { LedgerTransaction, TxFilters, TxInit, TxPagination, TxSummary } from '../types';
import { formatDateLabel, formatMoney } from '../utils/format';
import { resolveKpiTrace, type KpiTrace, type KpiTraceKey } from '../utils/kpiTrace';

const defaultFilters: TxFilters = {
  q: '',
  date_from: '',
  date_to: '',
  type: '',
  amount_min: '',
  amount_max: '',
  page: 1,
  per_page: 'all',
};

function preserveQueryParams(extra: Record<string, string | number | null>): string {
  const params = new URLSearchParams(window.location.search);
  // Keep desk context only — never reuse API params on the page URL.
  ['action', 'page', 'per_page', 'date_from', 'date_to', 'type', 'amount_min', 'amount_max'].forEach((key) => {
    params.delete(key);
  });
  Object.entries(extra).forEach(([key, value]) => {
    if (value === null || value === '') params.delete(key);
    else params.set(key, String(value));
  });
  const qs = params.toString();
  return qs === '' ? 'transactions.php' : `transactions.php?${qs}`;
}

function hasAdvancedFilters(filters: TxFilters): boolean {
  return (
    filters.date_from !== '' ||
    filters.date_to !== '' ||
    filters.type !== '' ||
    filters.amount_min !== '' ||
    filters.amount_max !== ''
  );
}

export default function TransactionsPage() {
  const [init, setInit] = useState<TxInit | null>(null);
  const [transactions, setTransactions] = useState<LedgerTransaction[]>([]);
  const [summary, setSummary] = useState<TxSummary | null>(null);
  const [pagination, setPagination] = useState<TxPagination | null>(null);
  const [filters, setFilters] = useState<TxFilters>(defaultFilters);
  const [draftQ, setDraftQ] = useState('');
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const [aiMode, setAiMode] = useState(false);
  const [aiSearching, setAiSearching] = useState(false);
  const [aiExplanation, setAiExplanation] = useState('');
  const [activeKpiTrace, setActiveKpiTrace] = useState<{ key: KpiTraceKey; trace: KpiTrace } | null>(
    null,
  );
  const skipNextLoadRef = useRef(false);

  const loadData = useCallback(async (activeFilters: TxFilters, hasContent: boolean) => {
    if (hasContent) setRefreshing(true);
    else setLoading(true);
    setError('');
    try {
      const [initData, listData] = await Promise.all([
        fetchInit(activeFilters),
        fetchTransactions(activeFilters),
      ]);
      setInit(initData);
      setSummary(listData.summary);
      setTransactions(listData.transactions);
      setPagination(listData.pagination);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load transaction ledger.');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    // Clean polluted API params left on the page URL (e.g. action=list).
    const params = new URLSearchParams(window.location.search);
    let dirty = false;
    ['action', 'page', 'per_page'].forEach((key) => {
      if (params.has(key)) {
        params.delete(key);
        dirty = true;
      }
    });
    if (dirty) {
      const qs = params.toString();
      const next = `${window.location.pathname}${qs ? `?${qs}` : ''}${window.location.hash}`;
      window.history.replaceState({}, '', next);
    }
  }, []);

  useEffect(() => {
    if (skipNextLoadRef.current) {
      skipNextLoadRef.current = false;
      return;
    }
    loadData(filters, Boolean(init));
    // eslint-disable-next-line react-hooks/exhaustive-deps -- reload only when filters change
  }, [filters, loadData]);

  function applySearch() {
    setAiExplanation('');
    setFilters((current) => ({
      ...defaultFilters,
      q: draftQ.trim(),
      per_page: current.per_page,
      page: 1,
    }));
  }

  async function runAiSearch() {
    const query = draftQ.trim();
    if (query === '') {
      setError('Enter a request for AI search.');
      return;
    }
    setAiSearching(true);
    setError('');
    try {
      const result = await fetchAiSearch(query, filters.per_page);
      const nextFilters: TxFilters = {
        ...defaultFilters,
        ...result.filters,
        type: (result.filters.type as TxFilters['type']) || '',
        per_page: filters.per_page,
        page: 1,
      };
      skipNextLoadRef.current = true;
      setFilters(nextFilters);
      setSummary(result.summary);
      setTransactions(result.transactions);
      setPagination(result.pagination);
      setAiExplanation(
        result.count > 0
          ? `${result.explanation} (${result.count} result${result.count === 1 ? '' : 's'})`
          : result.explanation || 'No matching transactions found.',
      );
      if (result.count === 0) {
        setError('No matching transactions found for that AI search.');
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'AI search failed.');
    } finally {
      setAiSearching(false);
    }
  }

  function submitSearch() {
    if (aiMode) {
      void runAiSearch();
      return;
    }
    applySearch();
  }

  function clearSearch() {
    setDraftQ('');
    setAiExplanation('');
    setFilters((current) => ({
      ...defaultFilters,
      per_page: current.per_page,
      page: 1,
    }));
  }

  function goToPage(page: number) {
    setFilters((current) => ({ ...current, page }));
  }

  function openKpiTrace(key: KpiTraceKey) {
    const statsForTrace = summary ?? init?.summary;
    const trace = resolveKpiTrace(key, statsForTrace, transactions, filters);
    if (trace) {
      setActiveKpiTrace({ key, trace });
    }
  }

  function kpiCardProps(key: KpiTraceKey, label: string) {
    return {
      type: 'button' as const,
      className: 'tl-kpi tl-kpi-card',
      onClick: () => openKpiTrace(key),
      'aria-label': `View how ${label} is calculated`,
      title: 'Click to see data source and breakdown',
    };
  }

  if (loading && !init) {
    return (
      <div className="tl-page tl-boot-loading" role="status" aria-live="polite">
        <Loader2 className="tl-boot-spinner" aria-hidden="true" />
        <span>Loading transaction ledger...</span>
      </div>
    );
  }

  const stats = summary ?? init?.summary;
  const netMovement = stats?.netMovement ?? 0;
  const totalRows = pagination?.totalEntries ?? 0;
  const searching = Boolean(draftQ.trim() || filters.q || hasAdvancedFilters(filters));

  return (
    <div className="tl-page">
      <header className="tl-page-header">
        <div className="tl-page-header-brand">
          <h1 className="tl-page-title">Transaction Ledger</h1>
        </div>

        <div className="tl-page-header-search">
          <div className={`tl-search-field${aiMode ? ' is-ai' : ''}`}>
            {aiMode ? (
              <Sparkles className="tl-search-icon tl-search-icon--ai" aria-hidden="true" />
            ) : (
              <Search className="tl-search-icon" aria-hidden="true" />
            )}
            <input
              type="search"
              className="tl-search-input"
              placeholder={
                aiMode
                  ? 'Ask AI, e.g. credits over 5M last month...'
                  : 'Search date, account, description, reference, user, amount...'
              }
              value={draftQ}
              onChange={(e) => setDraftQ(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') submitSearch();
              }}
              aria-label={aiMode ? 'AI search transactions' : 'Search transactions'}
              disabled={aiSearching}
            />
            <div className="tl-search-trailing">
              {draftQ.trim() !== '' && !aiSearching && (
                <button type="button" className="tl-search-clear" onClick={clearSearch} aria-label="Clear search">
                  <X className="w-4 h-4" />
                </button>
              )}
              <button
                type="button"
                className={`tl-search-ai-btn${aiMode ? ' is-active' : ''}`}
                onClick={() => {
                  if (!init?.aiConnected) {
                    setError('AI is not connected. Enable OpenAI in system settings.');
                    return;
                  }
                  setAiMode((value) => !value);
                  setAiExplanation('');
                }}
                aria-pressed={aiMode}
                aria-label={aiMode ? 'Disable AI search' : 'Enable AI search'}
                title={init?.aiConnected ? (aiMode ? 'AI search on' : 'Search by AI') : 'AI not connected'}
                disabled={aiSearching}
              >
                {aiSearching ? (
                  <Loader2 className="tl-search-ai-spin" aria-hidden="true" />
                ) : (
                  <Sparkles className="w-4 h-4" aria-hidden="true" />
                )}
              </button>
            </div>
          </div>
          {aiExplanation && (
            <p className="tl-ai-search-note" role="status">
              <Sparkles className="w-3.5 h-3.5" aria-hidden="true" />
              {aiExplanation}
            </p>
          )}
        </div>

        <div className="tl-page-header-actions">
          <a href={init?.transferUrl ?? 'transfer.php'} className="tl-btn tl-btn-primary">
            <ArrowLeftRight className="w-4 h-4" aria-hidden="true" />
            New transfer
          </a>
        </div>
      </header>

      {error && (
        <div className="tl-flash tl-flash-error" role="alert">
          {error}
        </div>
      )}

      <section className="tl-kpi-grid" aria-label="Summary">
        <button {...kpiCardProps('entries', 'entries')}>
          <div className="tl-kpi-icon tl-kpi-icon--entries">
            <ListChecks className="w-4 h-4" aria-hidden="true" />
          </div>
          <div className="tl-kpi-body">
            <div className="tl-kpi-label">entries</div>
            <div className="tl-kpi-value">{stats?.totalEntries ?? 0}</div>
            <div className="tl-kpi-helper">{stats?.periodLabel ?? 'All transactions'}</div>
          </div>
        </button>
        <button {...kpiCardProps('inflows', 'inflows')}>
          <div className="tl-kpi-icon tl-kpi-icon--in">
            <ArrowDownCircle className="w-4 h-4" aria-hidden="true" />
          </div>
          <div className="tl-kpi-body">
            <div className="tl-kpi-label">inflows</div>
            <div className="tl-kpi-value tl-kpi-value--money">{formatMoney(stats?.totalInflows ?? 0)}</div>
            <div className="tl-kpi-helper">
              {stats?.creditCount ?? 0} credit{(stats?.creditCount ?? 0) === 1 ? '' : 's'}
            </div>
          </div>
        </button>
        <button {...kpiCardProps('outflows', 'outflows')}>
          <div className="tl-kpi-icon tl-kpi-icon--out">
            <ArrowUpCircle className="w-4 h-4" aria-hidden="true" />
          </div>
          <div className="tl-kpi-body">
            <div className="tl-kpi-label">outflows</div>
            <div className="tl-kpi-value tl-kpi-value--money">{formatMoney(stats?.totalOutflows ?? 0)}</div>
            <div className="tl-kpi-helper">
              {stats?.debitCount ?? 0} debit{(stats?.debitCount ?? 0) === 1 ? '' : 's'}
            </div>
          </div>
        </button>
        <button {...kpiCardProps('net', 'net movement')}>
          <div className={`tl-kpi-icon tl-kpi-icon--net${netMovement < 0 ? ' is-negative' : ''}`}>
            <ArrowLeftRight className="w-4 h-4" aria-hidden="true" />
          </div>
          <div className="tl-kpi-body">
            <div className="tl-kpi-label">net</div>
            <div className={`tl-kpi-value tl-kpi-value--money${netMovement < 0 ? ' is-negative' : ''}`}>
              {netMovement < 0 ? `-${formatMoney(Math.abs(netMovement))}` : formatMoney(netMovement)}
            </div>
            <div className="tl-kpi-helper">{netMovement >= 0 ? 'Net inflow' : 'Net outflow'}</div>
          </div>
        </button>
      </section>

      <section className="tl-entries">
        <div className={`tl-card tl-entries-card${refreshing || aiSearching ? ' is-refreshing' : ''}`}>
          <div className="tl-entries-card-head">
            <div className="tl-entries-card-title">
              <ClipboardList className="tl-entries-card-ico" aria-hidden="true" />
              <h2>Transactions</h2>
              <span className="tl-entries-count">
                {totalRows} row{totalRows === 1 ? '' : 's'}
                {searching ? ' matching' : ''}
              </span>
            </div>
            <div className="tl-entries-meta">
              {(refreshing || aiSearching) && <Loader2 className="tl-inline-spinner" aria-hidden="true" />}
            </div>
          </div>

          {transactions.length === 0 ? (
            <div className="tl-empty">
              <Inbox className="tl-empty-icon" aria-hidden="true" />
              <p className="tl-empty-title">No transactions found</p>
              <p className="tl-empty-text">
                {searching ? 'Try different keywords or clear the search.' : 'No transactions recorded yet.'}
              </p>
            </div>
          ) : (
            <>
              <div className="tl-recent-cards" aria-label="Transactions">
                {transactions.map((tx) => {
                  const accountLine =
                    [tx.accountName, tx.referenceLabel].filter(Boolean).join(' · ') || '—';
                  const isOut = tx.type === 'debit';
                  return (
                    <button
                      key={`m-${tx.id}`}
                      type="button"
                      className="tl-recent-card"
                      onClick={() => {
                        window.location.href = tx.viewUrl;
                      }}
                    >
                      <div className="tl-recent-card-top">
                        <span className="tl-recent-desc-cell">
                          <span
                            className={`tl-recent-ico${isOut ? ' is-out' : ' is-in'}`}
                            aria-label={tx.typeLabel}
                            title={isOut ? 'Out' : 'In'}
                          >
                            {isOut ? (
                              <ArrowUpRight size={17} aria-hidden="true" />
                            ) : (
                              <ArrowDownLeft size={17} aria-hidden="true" />
                            )}
                          </span>
                          <span className="tl-recent-desc">{tx.description || '—'}</span>
                        </span>
                        <span className={`tl-recent-amt${isOut ? ' is-out' : ' is-in'}`}>
                          {tx.amountDisplay}
                        </span>
                      </div>
                      <div className="tl-recent-card-meta">
                        <div className="tl-recent-card-row">
                          <span className="tl-recent-card-label">Account</span>
                          <span className="tl-recent-card-value">{accountLine}</span>
                        </div>
                        <div className="tl-recent-card-row">
                          <span className="tl-recent-card-label">Date</span>
                          <span className="tl-recent-card-value">
                            {formatDateLabel(tx.transactionDate)}
                          </span>
                        </div>
                      </div>
                    </button>
                  );
                })}
              </div>

              <div className="tl-recent-table-wrap">
                <table className="tl-recent-table">
                  <thead>
                    <tr>
                      <th>Description</th>
                      <th>Account / Category</th>
                      <th>Date</th>
                      <th className="tl-recent-amt-h">Amount</th>
                    </tr>
                  </thead>
                  <tbody>
                    {transactions.map((tx) => {
                      const accountLine =
                        [tx.accountName, tx.referenceLabel].filter(Boolean).join(' · ') || '—';
                      const isOut = tx.type === 'debit';
                      return (
                        <tr
                          key={tx.id}
                          tabIndex={0}
                          onClick={() => {
                            window.location.href = tx.viewUrl;
                          }}
                          onKeyDown={(event) => {
                            if (event.key === 'Enter' || event.key === ' ') {
                              event.preventDefault();
                              window.location.href = tx.viewUrl;
                            }
                          }}
                        >
                          <td>
                            <span className="tl-recent-desc-cell">
                              <span
                                className={`tl-recent-ico${isOut ? ' is-out' : ' is-in'}`}
                                aria-label={tx.typeLabel}
                                title={isOut ? 'Out' : 'In'}
                              >
                                {isOut ? (
                                  <ArrowUpRight size={17} aria-hidden="true" />
                                ) : (
                                  <ArrowDownLeft size={17} aria-hidden="true" />
                                )}
                              </span>
                              <span className="tl-recent-desc">{tx.description || '—'}</span>
                            </span>
                          </td>
                          <td className="tl-recent-cat">{accountLine}</td>
                          <td className="tl-recent-date">{formatDateLabel(tx.transactionDate)}</td>
                          <td className={`tl-recent-amt${isOut ? ' is-out' : ' is-in'}`}>
                            {tx.amountDisplay}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </>
          )}

          {pagination && totalRows > 0 && (
            <div className="tl-table-footer">
              <span className="tl-footer-range">
                {pagination.viewAll
                  ? `Showing all ${pagination.totalEntries} entries`
                  : `Showing ${pagination.showingFrom}-${pagination.showingTo} of ${pagination.totalEntries}`}
              </span>
              {!pagination.viewAll && pagination.totalPages > 1 && (
                <nav className="tl-pager" aria-label="Entries pagination">
                  <a
                    href={preserveQueryParams({
                      q: filters.q || null,
                      date_from: filters.date_from || null,
                      date_to: filters.date_to || null,
                      type: filters.type || null,
                      amount_min: filters.amount_min || null,
                      amount_max: filters.amount_max || null,
                      page: pagination.page > 1 ? pagination.page - 1 : null,
                      per_page: filters.per_page === 'all' ? 'all' : null,
                    })}
                    className={pagination.page > 1 ? '' : 'is-disabled'}
                    onClick={(e) => {
                      e.preventDefault();
                      if (pagination.page > 1) goToPage(pagination.page - 1);
                    }}
                  >
                    &laquo;
                  </a>
                  {Array.from({ length: pagination.totalPages }, (_, index) => index + 1)
                    .filter((page) => page >= pagination.page - 2 && page <= pagination.page + 2)
                    .map((page) => (
                      <a
                        key={page}
                        href={preserveQueryParams({
                          q: filters.q || null,
                          date_from: filters.date_from || null,
                          date_to: filters.date_to || null,
                          type: filters.type || null,
                          amount_min: filters.amount_min || null,
                          amount_max: filters.amount_max || null,
                          page,
                          per_page: filters.per_page === 'all' ? 'all' : null,
                        })}
                        className={page === pagination.page ? 'is-active' : ''}
                        onClick={(e) => {
                          e.preventDefault();
                          goToPage(page);
                        }}
                      >
                        {page}
                      </a>
                    ))}
                  <a
                    href={preserveQueryParams({
                      q: filters.q || null,
                      date_from: filters.date_from || null,
                      date_to: filters.date_to || null,
                      type: filters.type || null,
                      amount_min: filters.amount_min || null,
                      amount_max: filters.amount_max || null,
                      page: pagination.page < pagination.totalPages ? pagination.page + 1 : null,
                      per_page: filters.per_page === 'all' ? 'all' : null,
                    })}
                    className={pagination.page < pagination.totalPages ? '' : 'is-disabled'}
                    onClick={(e) => {
                      e.preventDefault();
                      if (pagination.page < pagination.totalPages) goToPage(pagination.page + 1);
                    }}
                  >
                    &raquo;
                  </a>
                </nav>
              )}
            </div>
          )}
        </div>
      </section>

      {activeKpiTrace && (
        <KpiTraceModal
          trace={activeKpiTrace.trace}
          onClose={() => setActiveKpiTrace(null)}
        />
      )}
    </div>
  );
}
