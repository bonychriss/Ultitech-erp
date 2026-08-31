import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Inbox, Loader2, Pencil, Search, Trash2, X } from 'lucide-react';
import ExpenseQuickViewModal from '../components/ExpenseQuickViewModal';
import ExpensePostConfirmModal from '../components/ExpensePostConfirmModal';
import ExpenseKpiTraceModal from '../components/ExpenseKpiTraceModal';
import ExpenseStatusBadge, { canDeleteDraftExpense, expenseStatusLabel } from '../components/ExpenseStatusBadge';
import {
  deleteDraftExpense,
  deskPageUrl,
  exportUrl,
  fetchDeskExpenses,
  fetchDeskInit,
  fetchPostDraftPreview,
  postDraftExpense,
} from '../api/expensesDesk';
import { resolveKpiTrace } from '../utils/kpiTrace';

const emptyAdvancedFilters = {
  status: '',
  category: '',
  date_from: '',
  date_to: '',
  payment_method: '',
  amount_min: '',
  amount_max: '',
};

const emptyFilters = { search: '', ...emptyAdvancedFilters };

const STATUS_LABELS = {
  posted: 'Posted',
  unposted: 'Unposted',
  draft: 'Draft',
  rejected: 'Rejected',
  pending: 'Pending',
};

function filtersFromLocationSearch() {
  if (typeof window === 'undefined') return emptyFilters;
  const params = new URLSearchParams(window.location.search);
  const status = String(params.get('status') || '').trim().toLowerCase();
  const allowed = new Set(['draft', 'posted', 'unposted', 'pending', 'rejected']);
  if (!allowed.has(status)) return emptyFilters;
  return { ...emptyFilters, status };
}

function importedNoticeFromLocationSearch() {
  if (typeof window === 'undefined') return '';
  const params = new URLSearchParams(window.location.search);
  if (params.get('imported') !== '1') return '';
  const count = Number(params.get('count') || 0);
  if (count > 0) {
    return `${count} expense draft${count === 1 ? '' : 's'} imported. Review and post when ready.`;
  }
  return 'Expense drafts imported. Review and post when ready.';
}

function hasAdvancedFilters(filters) {
  return Object.keys(emptyAdvancedFilters).some((key) => String(filters[key] ?? '').trim() !== '');
}

function toInputDate(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function formatFilterDateLabel(value) {
  if (!value) return '';
  const normalized = value.includes('T') ? value : `${value}T12:00:00`;
  return new Date(normalized).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

function formatCurrency(value, currencyCode) {
  const code = String(currencyCode || 'TZS').replace(/^TSh$/i, 'TZS');
  const amount = Number(value) || 0;
  return `${code} ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(dateStr) {
  if (!dateStr) return '-';
  const normalized = dateStr.includes('T') ? dateStr : `${dateStr}T12:00:00`;
  return new Date(normalized).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

function expenseDisplayName(expense) {
  const payee = String(expense?.payee ?? '').trim();
  if (payee) return payee;
  return String(expense?.description ?? expense?.display_name ?? '').trim();
}

function isRowActionTarget(target) {
  if (!(target instanceof Element)) return false;
  return Boolean(
    target.closest('a, button, input, select, textarea, label, [data-exp-row-ignore]'),
  );
}

export default function ExpensesDeskPage() {
  const initialFilters = filtersFromLocationSearch();
  const [init, setInit] = useState(null);
  const [expenses, setExpenses] = useState([]);
  const [filters, setFilters] = useState(initialFilters);
  const [draftFilters, setDraftFilters] = useState(initialFilters);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);
  const [previewExpense, setPreviewExpense] = useState(null);
  const [deletingDraftId, setDeletingDraftId] = useState(null);
  const [postingDraftId, setPostingDraftId] = useState(null);
  const [postConfirm, setPostConfirm] = useState(null);
  const [activeKpiTrace, setActiveKpiTrace] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [exportState, setExportState] = useState('idle');
  const exportResetRef = useRef(null);
  const [importNotice, setImportNotice] = useState(() => importedNoticeFromLocationSearch());
  const filterDropdownRef = useRef(null);
  const filterBtnRef = useRef(null);
  const filterPanelRef = useRef(null);
  const [filterPanelStyle, setFilterPanelStyle] = useState(null);
  const searchExpandRef = useRef(null);
  const searchInputRef = useRef(null);
  const mobileSearchInputRef = useRef(null);

  const loadData = useCallback(async (activeFilters, silent = false) => {
    if (!silent) setLoading(true);
    setError('');
    try {
      const [initData, listData] = await Promise.all([
        fetchDeskInit(),
        fetchDeskExpenses(activeFilters),
      ]);
      setInit(initData);
      setExpenses(Array.isArray(listData.data) ? listData.data : []);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load expenses.');
    } finally {
      if (!silent) setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadData(filters);
  }, [filters, loadData]);

  useEffect(() => () => {
    if (exportResetRef.current) {
      clearTimeout(exportResetRef.current);
    }
  }, []);

  async function handleExport() {
    if (exportState === 'exporting') return;

    if (exportResetRef.current) {
      clearTimeout(exportResetRef.current);
      exportResetRef.current = null;
    }

    setExportState('exporting');
    setError('');

    try {
      const res = await fetch(exportUrl(filters), { credentials: 'same-origin' });
      if (!res.ok) {
        throw new Error(`Export failed (${res.status})`);
      }

      const dispo = res.headers.get('content-disposition') || '';
      const match = dispo.match(/filename="([^"]+)"/i);
      const filename = match?.[1] || `expenses_export_${new Date().toISOString().slice(0, 10)}.csv`;
      const blob = await res.blob();
      if (!blob || blob.size === 0) {
        throw new Error('Export file was empty.');
      }

      const objectUrl = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = objectUrl;
      anchor.download = filename;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      setTimeout(() => URL.revokeObjectURL(objectUrl), 2500);

      setExportState('success');
      exportResetRef.current = setTimeout(() => {
        setExportState('idle');
        exportResetRef.current = null;
      }, 1800);
    } catch (err) {
      setExportState('error');
      setError(err instanceof Error ? err.message : 'Could not export expenses.');
      exportResetRef.current = setTimeout(() => {
        setExportState('idle');
        exportResetRef.current = null;
      }, 2200);
    }
  }

  useEffect(() => {
    if (!importNotice || typeof window === 'undefined') return undefined;
    const url = new URL(window.location.href);
    if (!url.searchParams.has('imported')) return undefined;
    url.searchParams.delete('imported');
    url.searchParams.delete('count');
    const qs = url.searchParams.toString();
    window.history.replaceState({}, '', `${url.pathname}${qs ? `?${qs}` : ''}`);
    return undefined;
  }, [importNotice]);

  useEffect(() => {
    if (!filtersOpen) return undefined;

    function handlePointerDown(event) {
      const target = event.target;
      if (filterDropdownRef.current?.contains(target)) return;
      if (filterPanelRef.current?.contains(target)) return;
      setFiltersOpen(false);
    }

    function handleKeyDown(event) {
      if (event.key === 'Escape') setFiltersOpen(false);
    }

    document.addEventListener('mousedown', handlePointerDown);
    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('mousedown', handlePointerDown);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [filtersOpen]);

  useLayoutEffect(() => {
    if (!filtersOpen) {
      setFilterPanelStyle(null);
      return undefined;
    }

    function syncFilterPanelPosition() {
      const btn = filterBtnRef.current;
      if (!btn) return;

      const margin = 12;
      const rect = btn.getBoundingClientRect();
      const top = Math.round(rect.bottom + 6);
      const isMobile = window.matchMedia('(max-width: 767.98px)').matches;
      const maxHeight = Math.max(220, window.innerHeight - top - margin);

      if (isMobile) {
        setFilterPanelStyle({
          top: `${top}px`,
          left: `${margin}px`,
          right: `${margin}px`,
          width: 'auto',
          maxHeight: `${maxHeight}px`,
        });
        return;
      }

      const panelWidth = Math.min(384, window.innerWidth - margin * 2);
      let left = rect.right - panelWidth;
      left = Math.max(margin, Math.min(left, window.innerWidth - panelWidth - margin));

      setFilterPanelStyle({
        top: `${top}px`,
        left: `${left}px`,
        width: `${panelWidth}px`,
        maxHeight: `${maxHeight}px`,
      });
    }

    syncFilterPanelPosition();
    window.addEventListener('resize', syncFilterPanelPosition);
    window.addEventListener('scroll', syncFilterPanelPosition, true);
    return () => {
      window.removeEventListener('resize', syncFilterPanelPosition);
      window.removeEventListener('scroll', syncFilterPanelPosition, true);
    };
  }, [filtersOpen]);

  useEffect(() => {
    if (filters.search.trim() !== '') {
      setSearchOpen(true);
    }
  }, [filters.search]);

  useEffect(() => {
    if (!searchOpen) return undefined;

    function handlePointerDown(event) {
      if (!searchExpandRef.current?.contains(event.target)) {
        if (draftFilters.search.trim() === '' && filters.search.trim() === '') {
          setSearchOpen(false);
        }
      }
    }

    function handleKeyDown(event) {
      if (event.key === 'Escape') setSearchOpen(false);
    }

    const focusTimer = window.setTimeout(() => {
      mobileSearchInputRef.current?.focus();
    }, 180);

    document.addEventListener('mousedown', handlePointerDown);
    document.addEventListener('keydown', handleKeyDown);
    return () => {
      window.clearTimeout(focusTimer);
      document.removeEventListener('mousedown', handlePointerDown);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [searchOpen, draftFilters.search, filters.search]);

  const stats = init?.stats;
  const categories = init?.categories ?? [];
  const paymentMethods = init?.paymentMethods ?? [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank transfer' },
  ];

  function applyFilters() {
    setFilters((current) => ({ ...current, ...draftFilters, search: current.search }));
    setFiltersOpen(false);
  }

  function applySearch() {
    setFilters((current) => ({ ...current, search: draftFilters.search }));
  }

  function openFilters() {
    setDraftFilters({ ...filters });
    setFiltersOpen(true);
  }

  function openKpiTrace(key) {
    const trace = resolveKpiTrace(key, init, expenses, filters);
    if (trace) {
      setActiveKpiTrace({ key, trace });
    }
  }

  function kpiCardProps(key, label) {
    return {
      type: 'button',
      className: 'exp-desk-kpi exp-desk-kpi-card exp-desk-kpi-card--clickable',
      onClick: () => openKpiTrace(key),
      'aria-label': `View how ${label} is calculated`,
      title: 'Click to see data source and breakdown',
    };
  }

  function toggleFilters() {
    if (filtersOpen) {
      setFiltersOpen(false);
      return;
    }
    openFilters();
  }

  function resetDraftFilters() {
    setDraftFilters((current) => ({ ...current, ...emptyAdvancedFilters }));
  }

  function resetFilters() {
    setDraftFilters((current) => ({ ...current, ...emptyAdvancedFilters }));
    setFilters((current) => ({ ...current, ...emptyAdvancedFilters }));
    setFiltersOpen(false);
  }

  function clearAdvancedFilter(key) {
    if (key === 'date_from') {
      const cleared = { date_from: '', date_to: '' };
      setDraftFilters((current) => ({ ...current, ...cleared }));
      setFilters((current) => ({ ...current, ...cleared }));
      return;
    }
    if (key === 'amount_min') {
      const cleared = { amount_min: '', amount_max: '' };
      setDraftFilters((current) => ({ ...current, ...cleared }));
      setFilters((current) => ({ ...current, ...cleared }));
      return;
    }
    setDraftFilters((current) => ({ ...current, [key]: '' }));
    setFilters((current) => ({ ...current, [key]: '' }));
  }

  function setDatePreset(preset) {
    const now = new Date();
    if (preset === 'month') {
      const from = new Date(now.getFullYear(), now.getMonth(), 1);
      setDraftFilters((current) => ({
        ...current,
        date_from: toInputDate(from),
        date_to: toInputDate(now),
      }));
      return;
    }
    if (preset === '30d') {
      const from = new Date(now);
      from.setDate(from.getDate() - 30);
      setDraftFilters((current) => ({
        ...current,
        date_from: toInputDate(from),
        date_to: toInputDate(now),
      }));
      return;
    }
    setDraftFilters((current) => ({ ...current, date_from: '', date_to: '' }));
  }

  function buildActiveFilterChips(activeFilters) {
    const chips = [];

    if (activeFilters.status) {
      chips.push({
        key: 'status',
        label: `Status: ${STATUS_LABELS[activeFilters.status] || expenseStatusLabel({ status: activeFilters.status, is_posted: activeFilters.status === 'posted' ? 1 : 0 })}`,
      });
    }

    if (activeFilters.category) {
      const account = categories.find((cat) => String(cat.id) === String(activeFilters.category));
      chips.push({
        key: 'category',
        label: `Account: ${account?.name || activeFilters.category}`,
      });
    }

    if (activeFilters.date_from || activeFilters.date_to) {
      const fromLabel = activeFilters.date_from ? formatFilterDateLabel(activeFilters.date_from) : 'Any';
      const toLabel = activeFilters.date_to ? formatFilterDateLabel(activeFilters.date_to) : 'Any';
      chips.push({
        key: 'date_from',
        label: `Date: ${fromLabel} - ${toLabel}`,
      });
    }

    if (activeFilters.payment_method) {
      const method = paymentMethods.find((row) => row.value === activeFilters.payment_method);
      chips.push({
        key: 'payment_method',
        label: `Payment: ${method?.label || activeFilters.payment_method}`,
      });
    }

    if (activeFilters.amount_min || activeFilters.amount_max) {
      const min = activeFilters.amount_min || '0';
      const max = activeFilters.amount_max || 'Any';
      chips.push({
        key: 'amount_min',
        label: `Amount: ${min} - ${max}`,
      });
    }

    return chips;
  }

  const activeFilterChips = buildActiveFilterChips(filters);

  function clearSearch() {
    const next = { ...filters, search: '' };
    setDraftFilters((f) => ({ ...f, search: '' }));
    setFilters(next);
    setSearchOpen(false);
  }

  function toggleMobileSearch() {
    setSearchOpen((open) => !open);
  }

  function handleExpenseRowClick(event, expense) {
    if (isRowActionTarget(event.target)) return;
    setPreviewExpense(expense);
  }

  const handleDeleteDraft = useCallback(async (expense) => {
    if (!expense?.id || !init?.csrf_token) return;
    const label = expense.expense_number || `draft #${expense.id}`;
    if (!window.confirm(`Delete ${label}? This cannot be undone.`)) return;

    setDeletingDraftId(expense.id);
    setError('');
    try {
      await deleteDraftExpense(expense.id, init.csrf_token);
      if (previewExpense?.id === expense.id) {
        setPreviewExpense(null);
      }
      await loadData(filters, true);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to delete draft.');
    } finally {
      setDeletingDraftId(null);
    }
  }, [init?.csrf_token, previewExpense?.id, loadData, filters]);

  const handlePostDraft = useCallback(async (expense) => {
    if (!expense?.id) return;
    setError('');
    setImportNotice('');
    setPreviewExpense(null);
    setPostConfirm({
      expense,
      preview: null,
      loading: true,
      posting: false,
      posted: null,
      error: '',
    });
    try {
      const data = await fetchPostDraftPreview(expense.id);
      setPostConfirm((current) => (
        current?.expense?.id === expense.id
          ? {
              ...current,
              preview: data.preview || null,
              loading: false,
              error: '',
            }
          : current
      ));
    } catch (err) {
      setPostConfirm((current) => (
        current?.expense?.id === expense.id
          ? {
              ...current,
              loading: false,
              error: err instanceof Error ? err.message : 'Could not load balances.',
            }
          : current
      ));
    }
  }, []);

  const confirmPostDraft = useCallback(async () => {
    const expense = postConfirm?.expense;
    if (!expense?.id || !init?.csrf_token) return;

    setPostConfirm((current) => (current ? { ...current, posting: true, error: '' } : current));
    setPostingDraftId(expense.id);
    try {
      const result = await postDraftExpense(expense.id, init.csrf_token);
      const balances = result.balances || null;
      setPostConfirm((current) => (
        current?.expense?.id === expense.id
          ? {
              ...current,
              posting: false,
              posted: balances || {
                amount: current.preview?.amount,
                currency_code: current.preview?.currency_code,
                source_account: current.preview?.source_account,
              },
              preview: current.preview
                ? {
                    ...current.preview,
                    source_account: balances?.source_account || current.preview.source_account,
                  }
                : current.preview,
            }
          : current
      ));
      setImportNotice(result.message || `${expense.expense_number || `draft #${expense.id}`} posted to the ledger.`);
      await loadData(filters, true);
    } catch (err) {
      setPostConfirm((current) => (
        current?.expense?.id === expense.id
          ? {
              ...current,
              posting: false,
              error: err instanceof Error ? err.message : 'Failed to post draft.',
            }
          : current
      ));
    } finally {
      setPostingDraftId(null);
    }
  }, [postConfirm?.expense, init?.csrf_token, loadData, filters]);

  const closePostConfirm = useCallback(() => {
    if (postConfirm?.posting) return;
    setPostConfirm(null);
  }, [postConfirm?.posting]);

  function renderSearchField(inputRef, id = 'exp-desk-search') {
    return (
      <div className="exp-desk-search-field">
        <Search className="exp-desk-search-icon" aria-hidden="true" />
        <input
          ref={inputRef}
          id={id}
          type="search"
          className="exp-desk-search-input"
          placeholder="Search expense #, description, accounts"
          value={draftFilters.search}
          onChange={(e) => setDraftFilters((f) => ({ ...f, search: e.target.value }))}
          onKeyDown={(e) => {
            if (e.key === 'Enter') applySearch();
          }}
          aria-label="Search expenses"
        />
        {draftFilters.search.trim() !== '' && (
          <button
            type="button"
            className="exp-desk-search-clear"
            onClick={clearSearch}
            aria-label="Clear search"
          >
            <X className="w-4 h-4" />
          </button>
        )}
      </div>
    );
  }

  const hasSearchValue = filters.search.trim() !== '' || draftFilters.search.trim() !== '';

  function renderFiltersPanel() {
    if (!filtersOpen || !filterPanelStyle) return null;

    const panel = (
      <div
        ref={filterPanelRef}
        className="exp-desk-filters-panel exp-desk-filters-panel--fixed"
        style={filterPanelStyle}
        role="dialog"
        aria-label="Filter options"
      >
        <div className="exp-desk-filters-head">
          <div>
            <h2 className="exp-desk-filters-title">Filters</h2>
            <p className="exp-desk-filters-sub">Narrow the list by date, status, account, and amount.</p>
          </div>
          <button
            type="button"
            className="exp-desk-filters-close"
            onClick={() => setFiltersOpen(false)}
            aria-label="Close filters"
          >
            <X size={16} aria-hidden="true" />
          </button>
        </div>

        <div className="exp-desk-filters-section">
          <div className="exp-desk-filters-section-label">Date range</div>
          <div className="exp-desk-date-presets" role="group" aria-label="Quick date ranges">
            <button type="button" className="exp-desk-date-preset" onClick={() => setDatePreset('month')}>
              This month
            </button>
            <button type="button" className="exp-desk-date-preset" onClick={() => setDatePreset('30d')}>
              Last 30 days
            </button>
            <button type="button" className="exp-desk-date-preset" onClick={() => setDatePreset('clear')}>
              Clear dates
            </button>
          </div>
          <div className="exp-desk-filters-grid exp-desk-filters-grid--dates">
            <div className="exp-desk-field">
              <label htmlFor="filterDateFrom">From</label>
              <input
                id="filterDateFrom"
                type="date"
                value={draftFilters.date_from}
                onChange={(e) => setDraftFilters((f) => ({ ...f, date_from: e.target.value }))}
              />
            </div>
            <div className="exp-desk-field">
              <label htmlFor="filterDateTo">To</label>
              <input
                id="filterDateTo"
                type="date"
                value={draftFilters.date_to}
                onChange={(e) => setDraftFilters((f) => ({ ...f, date_to: e.target.value }))}
              />
            </div>
          </div>
        </div>

        <div className="exp-desk-filters-section">
          <div className="exp-desk-filters-section-label">Details</div>
          <div className="exp-desk-filters-grid">
            <div className="exp-desk-field">
              <label htmlFor="filterStatus">Status</label>
              <select
                id="filterStatus"
                value={draftFilters.status}
                onChange={(e) => setDraftFilters((f) => ({ ...f, status: e.target.value }))}
              >
                <option value="">All statuses</option>
                          <option value="posted">Posted</option>
                          <option value="unposted">Unposted</option>
                          <option value="draft">Draft</option>
                          <option value="rejected">Rejected</option>
              </select>
            </div>
            <div className="exp-desk-field">
              <label htmlFor="filterPayment">Payment</label>
              <select
                id="filterPayment"
                value={draftFilters.payment_method}
                onChange={(e) => setDraftFilters((f) => ({ ...f, payment_method: e.target.value }))}
              >
                <option value="">All methods</option>
                {paymentMethods.map((method) => (
                  <option key={method.value} value={method.value}>{method.label}</option>
                ))}
              </select>
            </div>
            <div className="exp-desk-field exp-desk-field--full">
              <label htmlFor="filterCategory">Account</label>
              <select
                id="filterCategory"
                value={draftFilters.category}
                onChange={(e) => setDraftFilters((f) => ({ ...f, category: e.target.value }))}
              >
                <option value="">All accounts</option>
                {categories.map((cat) => (
                  <option key={cat.id} value={String(cat.id)}>{cat.name}</option>
                ))}
              </select>
            </div>
          </div>
        </div>

        <div className="exp-desk-filters-section">
          <div className="exp-desk-filters-section-label">Amount</div>
          <div className="exp-desk-filters-grid exp-desk-filters-grid--amounts">
            <div className="exp-desk-field">
              <label htmlFor="filterAmountMin">Min</label>
              <input
                id="filterAmountMin"
                type="number"
                min="0"
                step="0.01"
                placeholder="0"
                value={draftFilters.amount_min}
                onChange={(e) => setDraftFilters((f) => ({ ...f, amount_min: e.target.value }))}
              />
            </div>
            <div className="exp-desk-field">
              <label htmlFor="filterAmountMax">Max</label>
              <input
                id="filterAmountMax"
                type="number"
                min="0"
                step="0.01"
                placeholder="Any"
                value={draftFilters.amount_max}
                onChange={(e) => setDraftFilters((f) => ({ ...f, amount_max: e.target.value }))}
              />
            </div>
          </div>
        </div>

        <div className="exp-desk-filters-footer">
          <button type="button" className="exp-desk-btn exp-desk-btn-ghost" onClick={resetDraftFilters}>
            Clear
          </button>
          <button
            type="button"
            className="exp-desk-btn exp-desk-btn-primary"
            onClick={applyFilters}
          >
            Apply filters
          </button>
        </div>
      </div>
    );

    return createPortal(panel, document.body);
  }

  if (loading && !init) {
    return (
      <div className="exp-desk-page exp-desk-boot-loading" role="status" aria-live="polite">
        <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
        <span>Loading expenses...</span>
      </div>
    );
  }

  return (
    <div className="exp-desk-page">
      {previewExpense && (
        <ExpenseQuickViewModal
          expense={previewExpense}
          onClose={() => setPreviewExpense(null)}
          onDeleteDraft={handleDeleteDraft}
          onPostDraft={handlePostDraft}
          deleting={deletingDraftId === previewExpense.id}
          posting={postingDraftId === previewExpense.id}
        />
      )}
      {postConfirm?.expense && (
        <ExpensePostConfirmModal
          expense={postConfirm.expense}
          preview={postConfirm.preview}
          loading={postConfirm.loading}
          posting={postConfirm.posting}
          posted={postConfirm.posted}
          error={postConfirm.error}
          onConfirm={confirmPostDraft}
          onClose={closePostConfirm}
        />
      )}
      {activeKpiTrace && (
        <ExpenseKpiTraceModal
          trace={activeKpiTrace.trace}
          traceKey={activeKpiTrace.key}
          filters={filters}
          listedCount={expenses.length}
          onClose={() => setActiveKpiTrace(null)}
        />
      )}
      {renderFiltersPanel()}
      <div className="exp-desk-page-header">
        <div className="exp-desk-page-header-search exp-desk-page-header-search--desktop">
          {renderSearchField(searchInputRef, 'exp-desk-search-desktop')}
        </div>

        <div className="exp-desk-page-header-actions">
          <div className="exp-desk-toolbar-secondary">
            <div
              className={`exp-desk-search-expand${searchOpen ? ' is-open' : ''}`}
              ref={searchExpandRef}
            >
              <button
                type="button"
                className={`exp-desk-search-toggle${searchOpen ? ' is-active' : ''}${hasSearchValue ? ' has-value' : ''}`}
                onClick={toggleMobileSearch}
                aria-expanded={searchOpen}
                aria-controls="exp-desk-search-mobile-panel"
                title="Search expenses"
              >
                <Search size={18} aria-hidden="true" />
              </button>
              <div
                id="exp-desk-search-mobile-panel"
                className={`exp-desk-search-panel${searchOpen ? ' is-open' : ''}`}
              >
                {renderSearchField(mobileSearchInputRef, 'exp-desk-search-mobile')}
              </div>
            </div>
            <div
              className={`exp-desk-filter-dropdown${filtersOpen ? ' is-open' : ''}`}
              ref={filterDropdownRef}
            >
            <button
              ref={filterBtnRef}
              type="button"
              className={`exp-desk-filter-btn${filtersOpen ? ' is-active' : ''}`}
              onClick={toggleFilters}
              aria-expanded={filtersOpen}
              aria-haspopup="dialog"
              title="Filters"
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                <line x1="4" y1="21" x2="4" y2="14" />
                <line x1="4" y1="10" x2="4" y2="3" />
                <line x1="12" y1="21" x2="12" y2="12" />
                <line x1="12" y1="8" x2="12" y2="3" />
                <line x1="20" y1="21" x2="20" y2="16" />
                <line x1="20" y1="12" x2="20" y2="3" />
                <line x1="1" y1="14" x2="7" y2="14" />
                <line x1="9" y1="8" x2="15" y2="8" />
                <line x1="17" y1="16" x2="23" y2="16" />
              </svg>
              {hasAdvancedFilters(filters) && <span className="exp-desk-filter-dot" aria-hidden="true" />}
            </button>
          </div>
          <button
            type="button"
            className={`exp-desk-btn exp-desk-btn-secondary exp-desk-btn-export${exportState === 'exporting' ? ' is-exporting' : ''}${exportState === 'success' ? ' is-export-success' : ''}${exportState === 'error' ? ' is-export-error' : ''}`}
            onClick={handleExport}
            disabled={exportState === 'exporting'}
            aria-live="polite"
            aria-busy={exportState === 'exporting'}
          >
            {exportState === 'exporting' ? (
              <>
                <Loader2 className="exp-desk-export-spin" size={15} aria-hidden="true" />
                <span>Exporting…</span>
              </>
            ) : exportState === 'success' ? (
              <span>Exported!</span>
            ) : exportState === 'error' ? (
              <span>Try again</span>
            ) : (
              <span>Export</span>
            )}
          </button>
          <a
            href={deskPageUrl('create.php')}
            className="exp-desk-btn exp-desk-btn-primary exp-desk-btn-create"
            aria-label="Record expense"
          >
            <span className="exp-desk-btn-label-desktop">Record expense</span>
            <span className="exp-desk-btn-label-mobile">New</span>
          </a>
          </div>
        </div>
      </div>

      {activeFilterChips.length > 0 && (
        <div className="exp-desk-active-filters" aria-label="Active filters">
          {activeFilterChips.map((chip) => (
            <button
              key={chip.key}
              type="button"
              className="exp-desk-filter-chip"
              onClick={() => clearAdvancedFilter(chip.key)}
              title="Remove filter"
            >
              <span>{chip.label}</span>
              <X size={12} aria-hidden="true" />
            </button>
          ))}
          <button type="button" className="exp-desk-filter-chip exp-desk-filter-chip--clear" onClick={resetFilters}>
            Clear all
          </button>
        </div>
      )}

      {error && (
        <div className="exp-desk-flash exp-desk-flash-error" role="alert">{error}</div>
      )}
      {importNotice && (
        <div className="exp-desk-flash exp-desk-flash-ok" role="status">
          <span>{importNotice}</span>
          <button
            type="button"
            className="exp-desk-flash-dismiss"
            onClick={() => setImportNotice('')}
            aria-label="Dismiss"
          >
            <X size={14} aria-hidden="true" />
          </button>
        </div>
      )}

      <section className="exp-desk-kpi-grid" aria-label="Summary">
        <button {...kpiCardProps('postedThisMonth', 'posted this month')}>
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--violet">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
              <polyline points="22 4 12 14.01 9 11.01" />
            </svg>
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">posted this month</div>
            <div className="exp-desk-kpi-value">{stats?.posted_month_count ?? 0}</div>
          </div>
        </button>
        <button {...kpiCardProps('monthlySpend', 'monthly spend')}>
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--indigo">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
              <line x1="16" y1="2" x2="16" y2="6" />
              <line x1="8" y1="2" x2="8" y2="6" />
              <line x1="3" y1="10" x2="21" y2="10" />
            </svg>
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">monthly spend</div>
            <div className="exp-desk-kpi-value exp-desk-kpi-value--money">
              {formatCurrency(stats?.spend_month, 'TZS')}
            </div>
          </div>
        </button>
        <button {...kpiCardProps('totalRecords', 'total records')}>
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--amber">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
              <polyline points="14 2 14 8 20 8" />
            </svg>
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">total records</div>
            <div className="exp-desk-kpi-value">{stats?.total_count ?? 0}</div>
          </div>
        </button>
        <button {...kpiCardProps('listedNow', 'listed now')}>
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--teal">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <line x1="8" y1="6" x2="21" y2="6" />
              <line x1="8" y1="12" x2="21" y2="12" />
              <line x1="8" y1="18" x2="21" y2="18" />
              <line x1="3" y1="6" x2="3.01" y2="6" />
              <line x1="3" y1="12" x2="3.01" y2="12" />
              <line x1="3" y1="18" x2="3.01" y2="18" />
            </svg>
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">listed now</div>
            <div className="exp-desk-kpi-value">{expenses.length}</div>
            <div className="exp-desk-kpi-helper">matching current filters</div>
          </div>
        </button>
      </section>

      <section className="exp-desk-results">
        <div className="exp-desk-results-head">
          <span className="exp-desk-results-count">
            {expenses.length} {expenses.length === 1 ? 'result' : 'results'}
          </span>
        </div>

        {loading && (
          <div className="exp-desk-loading" role="status">
            <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
            <span>Refreshing...</span>
          </div>
        )}

        {!loading && expenses.length === 0 ? (
          <div className="exp-desk-empty">
            <Inbox className="exp-desk-empty-icon" aria-hidden="true" />
            <p className="exp-desk-empty-title">No expenses found</p>
            <p className="exp-desk-empty-sub">Try adjusting your search or filters.</p>
          </div>
        ) : (
          <div className="exp-desk-table-wrap">
            <table className="exp-desk-table">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th>Account</th>
                  <th>Payment</th>
                  <th>Status</th>
                  <th>Amount</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {expenses.map((expense) => (
                    <tr
                      key={expense.id}
                      className="exp-desk-row-clickable"
                      tabIndex={0}
                      onClick={(event) => handleExpenseRowClick(event, expense)}
                      onKeyDown={(event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                          event.preventDefault();
                          setPreviewExpense(expense);
                        }
                      }}
                    >
                      <td>
                        <span className="exp-desk-ref">{expense.expense_number || ''}</span>
                        {expenseDisplayName(expense) ? (
                          <div className="exp-desk-cell-sub exp-desk-expense-name">
                            {expenseDisplayName(expense)}
                          </div>
                        ) : null}
                        <div className="exp-desk-subdate">{formatDate(expense.date)}</div>
                      </td>
                      <td>
                        <span className="exp-desk-cell-main">
                          {expense.category_name || expense.main_account_name || ''}
                        </span>
                      </td>
                      <td>
                        <div className="exp-desk-cell-main">{expense.payment_method_label || ''}</div>
                        <div className="exp-desk-cell-sub">{expense.source_account_name || ''}</div>
                      </td>
                      <td>
                        <ExpenseStatusBadge expense={expense} />
                      </td>
                      <td className="exp-desk-amt">{formatCurrency(expense.amount, expense.currency_code)}</td>
                      <td className="exp-desk-row-actions" data-exp-row-ignore>
                        {canDeleteDraftExpense(expense) ? (
                          <div className="exp-desk-row-actions-inner">
                            <a
                              href={deskPageUrl('edit.php', { id: expense.id })}
                              className="exp-desk-row-action"
                              title="Edit draft"
                              aria-label={`Edit ${expense.expense_number || 'draft'}`}
                            >
                              <Pencil size={15} aria-hidden="true" />
                              <span className="exp-desk-row-action-label">Edit</span>
                            </a>
                            <button
                              type="button"
                              className="exp-desk-row-action exp-desk-row-action--danger"
                              title="Delete draft"
                              aria-label={`Delete ${expense.expense_number || 'draft'}`}
                              disabled={deletingDraftId === expense.id}
                              onClick={(event) => {
                                event.stopPropagation();
                                handleDeleteDraft(expense);
                              }}
                            >
                              <Trash2 size={15} aria-hidden="true" />
                              <span className="exp-desk-row-action-label">Delete</span>
                            </button>
                          </div>
                        ) : (
                          <span className="exp-desk-row-actions-empty" aria-hidden="true">—</span>
                        )}
                      </td>
                    </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
