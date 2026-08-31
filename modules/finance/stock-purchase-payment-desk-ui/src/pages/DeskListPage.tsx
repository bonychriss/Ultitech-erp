import { useCallback, useEffect, useRef, useState, type MouseEvent } from 'react';
import {
  Inbox,
  Loader2,
  Search,
  X,
} from 'lucide-react';
import { fetchInit, fetchOrders } from '../api';
import FilterSlidersIcon from '../components/FilterSlidersIcon';
import KpiTraceModal from '../components/KpiTraceModal';
import PayPurchaseModal from '../components/PayPurchaseModal';
import PoQuickViewModal from '../components/PoQuickViewModal';
import RowActionsMenu from '../components/RowActionsMenu';
import type { DeskFilters, DeskInit, KpiTrace, KpiTraceKey, PurchaseOrderRow } from '../types';
import {
  formatDate,
  formatMoney,
  formatPaymentStatus,
  balanceDueColorClass,
  paymentStatusBadgeClass,
} from '../utils/format';
import { resolveKpiTrace } from '../utils/kpiTrace';
import { KPI_VISUALS } from '../utils/kpiVisuals';

const emptyFilters: DeskFilters = {
  q: '',
  date_from: '',
  date_to: '',
  payee: '',
  amount_min: '',
  amount_max: '',
};

function hasAdvancedFilters(filters: DeskFilters): boolean {
  return (
    filters.date_from !== ''
    || filters.date_to !== ''
    || filters.payee !== ''
    || filters.amount_min !== ''
    || filters.amount_max !== ''
  );
}

function isRowActionTarget(target: EventTarget | null): boolean {
  if (!(target instanceof Element)) return false;
  return Boolean(
    target.closest('a, button, input, select, textarea, label, [data-sppd-row-ignore]'),
  );
}

export default function DeskListPage() {
  const [init, setInit] = useState<DeskInit | null>(null);
  const [orders, setOrders] = useState<PurchaseOrderRow[]>([]);
  const [filters, setFilters] = useState<DeskFilters>(emptyFilters);
  const [draftFilters, setDraftFilters] = useState<DeskFilters>(emptyFilters);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [flash, setFlash] = useState('');
  const [payOrder, setPayOrder] = useState<PurchaseOrderRow | null>(null);
  const [previewOrder, setPreviewOrder] = useState<PurchaseOrderRow | null>(null);
  const [activeKpiTrace, setActiveKpiTrace] = useState<{ key: KpiTraceKey; trace: KpiTrace } | null>(null);
  const filterDropdownRef = useRef<HTMLDivElement>(null);

  const loadData = useCallback(async (activeFilters: DeskFilters, silent = false) => {
    if (!silent) setLoading(true);
    setError('');
    try {
      const [initData, listData] = await Promise.all([fetchInit(), fetchOrders(activeFilters)]);
      setInit(initData);
      setOrders(listData.orders);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load payment desk.');
    } finally {
      if (!silent) setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadData(filters);
  }, [filters, loadData]);

  useEffect(() => {
    if (!filtersOpen) return;

    function handlePointerDown(event: globalThis.MouseEvent) {
      if (!filterDropdownRef.current?.contains(event.target as Node)) {
        setFiltersOpen(false);
      }
    }

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setFiltersOpen(false);
      }
    }

    document.addEventListener('mousedown', handlePointerDown);
    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('mousedown', handlePointerDown);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [filtersOpen]);

  const summary = init?.summary;

  function applyFilters() {
    setFilters({ ...draftFilters });
    setFiltersOpen(false);
  }

  function openFilters() {
    setDraftFilters({ ...filters });
    setFiltersOpen(true);
  }

  function toggleFilters() {
    if (filtersOpen) {
      setFiltersOpen(false);
      return;
    }
    openFilters();
  }

  function resetFilters() {
    setDraftFilters(emptyFilters);
    setFilters(emptyFilters);
    setFiltersOpen(false);
  }

  function handlePaySuccess(message: string) {
    setFlash(message);
    loadData(filters, true);
  }

  function handleRowClick(event: MouseEvent<HTMLTableRowElement>, order: PurchaseOrderRow) {
    if (isRowActionTarget(event.target)) return;
    setPreviewOrder(order);
  }

  function openPayFromPreview(order: PurchaseOrderRow) {
    setPreviewOrder(null);
    setPayOrder(order);
  }

  function openKpiTrace(key: KpiTraceKey) {
    const trace = resolveKpiTrace(key, init, orders, filters);
    if (trace) {
      setActiveKpiTrace({ key, trace });
    }
  }

  function kpiCardProps(key: KpiTraceKey, label: string) {
    return {
      type: 'button' as const,
      className: 'sppd-kpi sppd-kpi-card',
      onClick: () => openKpiTrace(key),
      'aria-label': `View how ${label} is calculated`,
      title: 'Click to see data source and breakdown',
    };
  }

  if (loading && !init) {
    return (
      <div className="sppd-page sppd-boot-loading" role="status" aria-live="polite">
        <Loader2 className="sppd-boot-spinner" aria-hidden="true" />
        <span>Loading payment desk...</span>
      </div>
    );
  }

  return (
    <div className="sppd-page">
      <div className="sppd-page-header">
        <div className="sppd-page-header-search">
          <div className="sppd-search-field">
            <Search className="sppd-search-icon" aria-hidden="true" />
            <input
              type="search"
              className="sppd-search-input"
              placeholder="Search PO number or supplier..."
              value={draftFilters.q}
              onChange={(e) => setDraftFilters((f) => ({ ...f, q: e.target.value }))}
              onKeyDown={(e) => {
                if (e.key === 'Enter') applyFilters();
              }}
              aria-label="Search purchase orders"
            />
            {draftFilters.q.trim() !== '' && (
              <button
                type="button"
                className="sppd-search-clear"
                onClick={() => {
                  const next = { ...draftFilters, q: '' };
                  setDraftFilters(next);
                  setFilters(next);
                }}
                aria-label="Clear search"
              >
                <X className="w-4 h-4" />
              </button>
            )}
          </div>
        </div>

        <div className="sppd-page-header-actions">
          <div className="sppd-filter-dropdown" ref={filterDropdownRef}>
          <button
            type="button"
            className={`sppd-filter-btn${filtersOpen ? ' is-active' : ''}`}
            onClick={toggleFilters}
            aria-expanded={filtersOpen}
            aria-haspopup="dialog"
            aria-label="Toggle filters"
            title="Filters"
          >
            <FilterSlidersIcon className="sppd-filter-btn-icon" />
            {hasAdvancedFilters(filters) && <span className="sppd-filter-btn-dot" aria-hidden="true" />}
          </button>
          {filtersOpen && (
            <div className="sppd-filters-dropdown" role="dialog" aria-label="Filter options">
              <div className="sppd-filters sppd-filters--dropdown">
                <div className="sppd-field">
                  <label>Date from</label>
                  <input
                    type="date"
                    value={draftFilters.date_from}
                    onChange={(e) => setDraftFilters((f) => ({ ...f, date_from: e.target.value }))}
                  />
                </div>
                <div className="sppd-field">
                  <label>Date to</label>
                  <input
                    type="date"
                    value={draftFilters.date_to}
                    onChange={(e) => setDraftFilters((f) => ({ ...f, date_to: e.target.value }))}
                  />
                </div>
                <div className="sppd-field sppd-field--full">
                  <label>Payee</label>
                  <select
                    value={draftFilters.payee}
                    onChange={(e) => setDraftFilters((f) => ({ ...f, payee: e.target.value }))}
                  >
                    <option value="">All payees</option>
                    {(init?.payeeOptions ?? []).map((name) => (
                      <option key={name} value={name}>{name}</option>
                    ))}
                  </select>
                </div>
                <div className="sppd-field">
                  <label>Min amount</label>
                  <input
                    type="number"
                    min="0"
                    step="0.01"
                    value={draftFilters.amount_min}
                    onChange={(e) => setDraftFilters((f) => ({ ...f, amount_min: e.target.value }))}
                  />
                </div>
                <div className="sppd-field">
                  <label>Max amount</label>
                  <input
                    type="number"
                    min="0"
                    step="0.01"
                    value={draftFilters.amount_max}
                    onChange={(e) => setDraftFilters((f) => ({ ...f, amount_max: e.target.value }))}
                  />
                </div>
              </div>
              <div className="sppd-filters-footer">
                <button type="button" className="sppd-btn sppd-btn-secondary" onClick={resetFilters}>
                  Reset
                </button>
                <button type="button" className="sppd-btn sppd-btn-primary" onClick={applyFilters}>
                  Apply
                </button>
              </div>
            </div>
          )}
        </div>
        </div>
      </div>

      {flash && (
        <div className="sppd-flash sppd-flash-success" role="status">
          {flash}
        </div>
      )}

      {error && (
        <div className="sppd-flash sppd-flash-error" role="alert">
          {error}
        </div>
      )}

      <section className="sppd-kpi-grid" aria-label="Summary">
        <button {...kpiCardProps('unpaidPurchaseOrders', 'unpaid purchase orders')}>
          <div className={`sppd-kpi-icon ${KPI_VISUALS.unpaidPurchaseOrders.iconClass}`}>
            <KPI_VISUALS.unpaidPurchaseOrders.Icon className="w-5 h-5" aria-hidden="true" />
          </div>
          <div className="sppd-kpi-body">
            <div className="sppd-kpi-label">unpaid purchase orders</div>
            <div className="sppd-kpi-value">{summary?.unpaidCount ?? 0}</div>
          </div>
        </button>
        <button {...kpiCardProps('accountsPayable', 'account payables')}>
          <div className={`sppd-kpi-icon ${KPI_VISUALS.accountsPayable.iconClass}`}>
            <KPI_VISUALS.accountsPayable.Icon className="w-5 h-5" aria-hidden="true" />
          </div>
          <div className="sppd-kpi-body">
            <div className="sppd-kpi-label">account payables</div>
            <div className="sppd-kpi-value sppd-kpi-value--money">
              {summary
                ? formatMoney(summary.accountsPayable, summary.accountsPayableCurrency || summary.currency)
                : '-'}
            </div>
            <div className="sppd-kpi-helper">
              {summary?.accountsPayableSource === 'unpaid_pos'
                ? 'estimated from unpaid POs'
                : 'accounts payable ledger balance'}
            </div>
          </div>
        </button>
        <button {...kpiCardProps('overduePayables', 'overdue payables')}>
          <div className={`sppd-kpi-icon ${KPI_VISUALS.overduePayables.iconClass}`}>
            <KPI_VISUALS.overduePayables.Icon className="w-5 h-5" aria-hidden="true" />
          </div>
          <div className="sppd-kpi-body">
            <div className="sppd-kpi-label">overdue payables</div>
            <div className="sppd-kpi-value sppd-kpi-value--money">
              {summary
                ? formatMoney(
                    summary.overduePayables,
                    summary.overduePayablesCurrency || summary.currency,
                  )
                : '-'}
            </div>
            <div className="sppd-kpi-helper">
              {summary?.overduePayablesCount ?? 0} overdue unpaid PO
              {(summary?.overduePayablesCount ?? 0) === 1 ? '' : 's'}
            </div>
          </div>
        </button>
        <button {...kpiCardProps('listedNow', 'listed now')}>
          <div className={`sppd-kpi-icon ${KPI_VISUALS.listedNow.iconClass}`}>
            <KPI_VISUALS.listedNow.Icon className="w-5 h-5" aria-hidden="true" />
          </div>
          <div className="sppd-kpi-body">
            <div className="sppd-kpi-label">listed now</div>
            <div className="sppd-kpi-value">{orders.length}</div>
            <div className="sppd-kpi-helper">matching current filters</div>
          </div>
        </button>
      </section>

      <section className="sppd-results">
        <div className="sppd-results-head">
          <span className="sppd-results-count">{orders.length} results</span>
        </div>

        {orders.length === 0 ? (
          <div className="sppd-empty">
            <Inbox className="w-12 h-12 mx-auto mb-3 text-slate-300" />
            <p className="font-semibold text-slate-700">No purchase orders found</p>
            <p className="text-sm mt-1">Try adjusting your search or filters.</p>
          </div>
        ) : (
          <div className="sppd-table-wrap">
            <table className="sppd-table">
              <colgroup>
                <col className="sppd-col-po" />
                <col className="sppd-col-supplier" />
                <col className="sppd-col-amount" />
                <col className="sppd-col-balance" />
                <col className="sppd-col-status" />
                <col className="sppd-col-paid-by" />
                <col className="sppd-col-actions" />
              </colgroup>
              <thead>
                <tr>
                  <th className="sppd-th-po">PO / Date</th>
                  <th className="sppd-th-supplier">Supplier</th>
                  <th className="sppd-th-amount">amount</th>
                  <th className="sppd-th-balance">Balance due</th>
                  <th className="sppd-th-status">Status</th>
                  <th className="sppd-th-paid-by">paid by</th>
                  <th className="sppd-th-actions">Actions</th>
                </tr>
              </thead>
              <tbody>
                {orders.map((order) => (
                  <tr
                    key={order.id}
                    className="sppd-table-row-clickable"
                    onClick={(event) => handleRowClick(event, order)}
                    tabIndex={0}
                    onKeyDown={(event) => {
                      if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        setPreviewOrder(order);
                      }
                    }}
                    aria-label={`View summary for ${order.poNumber}`}
                  >
                    <td className="sppd-td-po">
                      <div className="sppd-po-cell">
                        <a
                          href={order.viewUrl}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="sppd-po-link"
                          data-sppd-row-ignore
                        >
                          {order.poNumber}
                        </a>
                        <div className="sppd-po-date">{formatDate(order.createdAt)}</div>
                      </div>
                    </td>
                    <td className="sppd-td-supplier">
                      <div className="sppd-supplier-text">
                        <div className="sppd-supplier-name">{order.payeeName || '-'}</div>
                        {order.description.trim() !== '' && (
                          <div className="sppd-supplier-desc">{order.description}</div>
                        )}
                      </div>
                    </td>
                    <td className="sppd-td-amount sppd-amt">{formatMoney(order.amountToPay, order.currency)}</td>
                    <td className={`sppd-td-balance sppd-amt ${balanceDueColorClass(order.balanceDue)}`}>
                      {formatMoney(order.balanceDue, order.currency)}
                    </td>
                    <td className="sppd-td-status">
                      <span className={`sppd-badge ${paymentStatusBadgeClass(order.paymentStatus)}`}>
                        {formatPaymentStatus(order.paymentStatus)}
                      </span>
                    </td>
                    <td className="sppd-td-paid-by">
                      {order.paidByName ? (
                        <span className="sppd-paid-by-name">{order.paidByName}</span>
                      ) : (
                        <span className="sppd-paid-by-empty">-</span>
                      )}
                    </td>
                    <td className="sppd-td-actions">
                      <RowActionsMenu order={order} onPay={() => setPayOrder(order)} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {previewOrder && (
        <PoQuickViewModal
          order={previewOrder}
          onClose={() => setPreviewOrder(null)}
          onPay={() => openPayFromPreview(previewOrder)}
        />
      )}

      {payOrder && init && (
        <PayPurchaseModal
          order={payOrder}
          accounts={init.accounts}
          paymentMethods={init.paymentMethods}
          onClose={() => setPayOrder(null)}
          onSuccess={handlePaySuccess}
        />
      )}

      {activeKpiTrace && (
        <KpiTraceModal
          trace={activeKpiTrace.trace}
          traceKey={activeKpiTrace.key}
          onClose={() => setActiveKpiTrace(null)}
        />
      )}
    </div>
  );
}
