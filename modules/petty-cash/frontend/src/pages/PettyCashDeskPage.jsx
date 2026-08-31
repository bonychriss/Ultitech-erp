import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Download, Inbox, Loader2, Search, SlidersHorizontal, X } from 'lucide-react';
import PettyCashStatusBadge from '../components/PettyCashStatusBadge.jsx';
import PettyCashConfirmModal from '../components/PettyCashConfirmModal.jsx';
import PettyCashKpiInfoModal, { resolvePettyCashKpiInfo } from '../components/PettyCashKpiInfoModal.jsx';
import {
  deskPageUrl,
  fetchDeskInit,
  fetchDeskReplenishments,
  fetchDeskVouchers,
  postVoucherAction,
} from '../api/pettyCashDesk.js';
import { formatDate, normalizeDateInput } from '../utils/format.js';

const emptyAdvancedFilters = {
  status: '',
  category: '',
  date_from: '',
  date_to: '',
};

const emptyFilters = { search: '', ...emptyAdvancedFilters };

const STATUS_LABELS = {
  pending: 'Pending',
  approved: 'Approved',
  rejected: 'Rejected',
};

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
  const normalized = normalizeDateInput(value);
  if (!normalized) return '';
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) return '';
  return date.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

function buildActiveFilterChips(activeFilters, categories) {
  const chips = [];

  if (activeFilters.status) {
    chips.push({
      key: 'status',
      label: `Status: ${STATUS_LABELS[activeFilters.status] || activeFilters.status}`,
    });
  }

  if (activeFilters.category) {
    const cat = categories.find((c) => String(c.id) === String(activeFilters.category) || c.name === activeFilters.category);
    chips.push({
      key: 'category',
      label: `Category: ${cat?.name || activeFilters.category}`,
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

  return chips;
}

function formatCurrency(value) {
  const amount = Number(value) || 0;
  return `TZS ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function isRowActionTarget(target) {
  if (!(target instanceof Element)) return false;
  return Boolean(
    target.closest('a, button, input, select, textarea, label, [data-exp-row-ignore]'),
  );
}

export default function PettyCashDeskPage({ fullList = false }) {
  const [init, setInit] = useState(null);
  const [vouchers, setVouchers] = useState([]);
  const [replenishments, setReplenishments] = useState([]);
  const [filters, setFilters] = useState(emptyFilters);
  const [draftFilters, setDraftFilters] = useState(emptyFilters);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);
  const [draftSearch, setDraftSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState(0);
  const [approveTarget, setApproveTarget] = useState(null);
  const [activeKpi, setActiveKpi] = useState(null);
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
      const voucherFilters = fullList ? { ...activeFilters, limit: 500 } : activeFilters;
      const tasks = [fetchDeskInit(), fetchDeskVouchers(voucherFilters)];
      if (!fullList) tasks.push(fetchDeskReplenishments());
      const results = await Promise.all(tasks);
      const initData = results[0];
      const voucherData = results[1];
      setInit(initData);
      setVouchers(Array.isArray(voucherData.data) ? voucherData.data : []);
      if (!fullList) {
        const repData = results[2];
        setReplenishments(Array.isArray(repData?.data) ? repData.data : []);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load petty cash.');
    } finally {
      if (!silent) setLoading(false);
    }
  }, [fullList]);

  useEffect(() => {
    loadData(filters);
  }, [filters, loadData]);

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

  useEffect(() => {
    if (filters.search.trim() !== '') {
      setSearchOpen(true);
    }
  }, [filters.search]);

  useEffect(() => {
    if (!searchOpen) return undefined;

    function handlePointerDown(event) {
      if (!searchExpandRef.current?.contains(event.target)) {
        if (draftSearch.trim() === '' && filters.search.trim() === '') {
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
  }, [searchOpen, draftSearch, filters.search]);

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

  const stats = init?.stats || {};
  const categories = init?.categories || [];
  const canManage = Boolean(init?.can_manage);
  const urls = init?.urls || {};
  const kpiInfo = activeKpi
    ? resolvePettyCashKpiInfo(activeKpi, { stats, vouchers, replenishments })
    : null;

  function openKpiInfo(key) {
    setActiveKpi(key);
  }

  function kpiCardProps(key, label) {
    return {
      type: 'button',
      className: 'exp-desk-kpi exp-desk-kpi-card exp-desk-kpi-card--clickable',
      onClick: () => openKpiInfo(key),
      'aria-label': `Show details for ${label}`,
    };
  }
  const activeFilterChips = buildActiveFilterChips(filters, categories);
  const activeFilterCount = activeFilterChips.length;
  const hasSearchValue = filters.search.trim() !== '' || draftSearch.trim() !== '';

  function applySearch() {
    setFilters((current) => ({ ...current, search: draftSearch.trim() }));
  }

  function clearSearch() {
    setDraftSearch('');
    setFilters((current) => ({ ...current, search: '' }));
    setSearchOpen(false);
  }

  function toggleMobileSearch() {
    setSearchOpen((open) => !open);
  }

  function renderSearchField(inputRef, id = 'pc-desk-search') {
    return (
      <div className="exp-desk-search-field">
        <Search className="exp-desk-search-icon" aria-hidden="true" />
        <input
          ref={inputRef}
          id={id}
          type="search"
          className="exp-desk-search-input"
          placeholder="Search voucher #, category, description"
          value={draftSearch}
          onChange={(e) => setDraftSearch(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') applySearch();
          }}
          aria-label="Search vouchers"
        />
        {draftSearch.trim() !== '' && (
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

  function applyFilters() {
    setFilters((current) => ({
      ...current,
      status: draftFilters.status,
      category: draftFilters.category,
      date_from: draftFilters.date_from,
      date_to: draftFilters.date_to,
    }));
    setFiltersOpen(false);
  }

  function openFilters() {
    setDraftFilters((current) => ({
      ...current,
      status: filters.status,
      category: filters.category,
      date_from: filters.date_from,
      date_to: filters.date_to,
    }));
    setFiltersOpen(true);
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
            <p className="exp-desk-filters-sub">Narrow vouchers by date, status, and category.</p>
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
              <label htmlFor="pc-filterDateFrom">From</label>
              <input
                id="pc-filterDateFrom"
                type="date"
                value={draftFilters.date_from}
                onChange={(e) => setDraftFilters((f) => ({ ...f, date_from: e.target.value }))}
              />
            </div>
            <div className="exp-desk-field">
              <label htmlFor="pc-filterDateTo">To</label>
              <input
                id="pc-filterDateTo"
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
              <label htmlFor="pc-filterStatus">Status</label>
              <select
                id="pc-filterStatus"
                value={draftFilters.status}
                onChange={(e) => setDraftFilters((f) => ({ ...f, status: e.target.value }))}
              >
                <option value="">All statuses</option>
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
              </select>
            </div>
            <div className="exp-desk-field">
              <label htmlFor="pc-filterCategory">Category</label>
              <select
                id="pc-filterCategory"
                value={draftFilters.category}
                onChange={(e) => setDraftFilters((f) => ({ ...f, category: e.target.value }))}
              >
                <option value="">All categories</option>
                {categories.map((cat) => (
                  <option key={cat.id || cat.name} value={cat.name || cat.id}>
                    {cat.name}
                  </option>
                ))}
              </select>
            </div>
          </div>
        </div>

        <div className="exp-desk-filters-footer">
          <button type="button" className="exp-desk-btn exp-desk-btn-secondary" onClick={resetDraftFilters}>
            Reset
          </button>
          <button type="button" className="exp-desk-btn exp-desk-btn-primary" onClick={applyFilters}>
            Apply filters
          </button>
        </div>
      </div>
    );

    return createPortal(panel, document.body);
  }

  async function runAction(action, id) {
    if (!canManage || !id) return;
    if (action === 'reject_voucher') {
      const reason = window.prompt('Rejection reason (optional):') || '';
      setBusyId(id);
      try {
        await postVoucherAction(action, id, { reason });
        await loadData(filters, true);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Action failed.');
      } finally {
        setBusyId(0);
      }
      return;
    }
    if (action === 'approve_voucher') {
      const row = vouchers.find((item) => item.id === id) || null;
      setApproveTarget({ id, label: row?.voucher_number || `#${id}` });
      return;
    }
    setBusyId(id);
    try {
      await postVoucherAction(action, id);
      await loadData(filters, true);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Action failed.');
    } finally {
      setBusyId(0);
    }
  }

  async function confirmApproveVoucher() {
    if (!approveTarget?.id) return;
    const id = approveTarget.id;
    setBusyId(id);
    setError('');
    try {
      await postVoucherAction('approve_voucher', id);
      setApproveTarget(null);
      await loadData(filters, true);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Action failed.');
    } finally {
      setBusyId(0);
    }
  }

  if (loading && !init) {
    return (
      <div className="exp-desk-boot-loading" role="status">
        <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
        <span>Loading petty cash...</span>
      </div>
    );
  }

  return (
    <div className={fullList ? '' : 'exp-desk-page'}>
      {renderFiltersPanel()}
      <PettyCashConfirmModal
        open={Boolean(approveTarget)}
        title="Approve voucher"
        message={
          approveTarget?.label
            ? `Approve ${approveTarget.label}? It will deduct float and post to Balances.`
            : 'Approve this voucher? It will deduct float and post to Balances.'
        }
        confirmLabel="Approve"
        cancelLabel="Cancel"
        busy={Boolean(approveTarget && busyId === approveTarget.id)}
        onClose={() => {
          if (!(approveTarget && busyId === approveTarget.id)) setApproveTarget(null);
        }}
        onConfirm={confirmApproveVoucher}
      />
      {kpiInfo ? (
        <PettyCashKpiInfoModal info={kpiInfo} onClose={() => setActiveKpi(null)} />
      ) : null}
      <div className="exp-desk-page-header">
        <div className="exp-desk-page-header-search exp-desk-page-header-search--desktop">
          {renderSearchField(searchInputRef, 'pc-desk-search-desktop')}
        </div>

        <div className="exp-desk-page-header-actions">
          <div className="exp-desk-toolbar-secondary pc-desk-toolbar">
            <div
              className={`exp-desk-search-expand${searchOpen ? ' is-open' : ''}`}
              ref={searchExpandRef}
            >
              <button
                type="button"
                className={`exp-desk-search-toggle${searchOpen ? ' is-active' : ''}${hasSearchValue ? ' has-value' : ''}`}
                onClick={toggleMobileSearch}
                aria-expanded={searchOpen}
                aria-controls="pc-desk-search-mobile-panel"
                title="Search vouchers"
              >
                <Search size={18} aria-hidden="true" />
              </button>
              <div
                id="pc-desk-search-mobile-panel"
                className={`exp-desk-search-panel${searchOpen ? ' is-open' : ''}`}
              >
                {renderSearchField(mobileSearchInputRef, 'pc-desk-search-mobile')}
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
                aria-label={activeFilterCount > 0 ? `Filters (${activeFilterCount} active)` : 'Filters'}
                title="Filters"
              >
                <SlidersHorizontal size={18} aria-hidden="true" />
                {hasAdvancedFilters(filters) ? <span className="exp-desk-filter-dot" aria-hidden="true" /> : null}
              </button>
            </div>
            <a
              href={urls.reports || deskPageUrl('reports.php')}
              className="exp-desk-btn exp-desk-btn-secondary exp-desk-btn-export"
            >
              <Download size={16} aria-hidden="true" />
              <span className="exp-desk-btn-label-desktop">Download</span>
              <span className="exp-desk-btn-label-mobile">Download</span>
            </a>
            <a
              href={urls.create_voucher || deskPageUrl('create-voucher.php')}
              className="exp-desk-btn exp-desk-btn-primary exp-desk-btn-create"
            >
              <span className="exp-desk-btn-label-desktop">New voucher</span>
              <span className="exp-desk-btn-label-mobile">New</span>
            </a>
          </div>
        </div>
      </div>

      {activeFilterChips.length > 0 ? (
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
      ) : null}

      {error ? <div className="exp-desk-flash exp-desk-flash-error" role="alert">{error}</div> : null}

      {!fullList ? (
      <section className="exp-desk-kpi-grid" aria-label="Summary">
        <button {...kpiCardProps('float', 'float balance')}>
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--teal">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <rect x="2" y="5" width="20" height="14" rx="2" />
              <line x1="2" y1="10" x2="22" y2="10" />
            </svg>
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">float balance</div>
            <div className="exp-desk-kpi-value exp-desk-kpi-value--money">{formatCurrency(stats.total_balance)}</div>
          </div>
        </button>
        <button {...kpiCardProps('spent', 'total spent')}>
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--indigo">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <line x1="12" y1="1" x2="12" y2="23" />
              <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
            </svg>
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">total spent</div>
            <div className="exp-desk-kpi-value exp-desk-kpi-value--money">{formatCurrency(stats.total_spent)}</div>
          </div>
        </button>
        <button {...kpiCardProps('pending_vouchers', 'pending vouchers')}>
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--amber">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <circle cx="12" cy="12" r="10" />
              <polyline points="12 6 12 12 16 14" />
            </svg>
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">pending vouchers</div>
            <div className="exp-desk-kpi-value">{stats.pending_vouchers ?? 0}</div>
          </div>
        </button>
        <button {...kpiCardProps('pending_topups', 'pending top-ups')}>
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--violet">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7H14a3.5 3.5 0 0 1 0 7H6" />
            </svg>
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">pending top-ups</div>
            <div className="exp-desk-kpi-value">{stats.pending_replenishments ?? 0}</div>
          </div>
        </button>
      </section>
      ) : null}

      <section className="exp-desk-results">
        <div className="exp-desk-results-head">
          <span className="exp-desk-results-count">
            {vouchers.length} {vouchers.length === 1 ? 'voucher' : 'vouchers'}
          </span>
          {!fullList ? (
            <a href={deskPageUrl('vouchers/index.php')} className="exp-desk-action-link">View all</a>
          ) : null}
        </div>

        {loading ? (
          <div className="exp-desk-loading" role="status">
            <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
            <span>Refreshing...</span>
          </div>
        ) : null}

        {!loading && vouchers.length === 0 ? (
          <div className="exp-desk-empty">
            <Inbox className="exp-desk-empty-icon" aria-hidden="true" />
            <p className="exp-desk-empty-title">No vouchers found</p>
            <p className="exp-desk-empty-sub">Try adjusting search or create a new voucher.</p>
          </div>
        ) : (
          <div className="exp-desk-table-wrap">
            <table className="exp-desk-table pc-voucher-table">
              <thead>
                <tr>
                  <th>Voucher</th>
                  <th>Date</th>
                  <th>Category</th>
                  <th>Custodian</th>
                  <th>Amount</th>
                  <th>Status</th>
                  <th aria-label="Actions" />
                </tr>
              </thead>
              <tbody>
                {vouchers.map((row) => (
                  <tr
                    key={row.id}
                    className="exp-desk-row-clickable"
                    tabIndex={0}
                    onClick={(event) => {
                      if (isRowActionTarget(event.target)) return;
                      if (row.view_url) window.location.href = row.view_url;
                    }}
                    onKeyDown={(event) => {
                      if (event.key !== 'Enter' && event.key !== ' ') return;
                      if (isRowActionTarget(event.target)) return;
                      event.preventDefault();
                      if (row.view_url) window.location.href = row.view_url;
                    }}
                  >
                    <td>
                      <a href={row.view_url} className="exp-desk-ref">
                        {row.voucher_number || `#${row.id}`}
                      </a>
                      {row.description ? (
                        <div className="exp-desk-cell-sub">{row.description}</div>
                      ) : null}
                      {row.has_receipt ? (
                        <div className="pc-voucher-table__receipt">Receipt attached</div>
                      ) : null}
                    </td>
                    <td>{formatDate(row.date)}</td>
                    <td>{row.category || '—'}</td>
                    <td>{row.custodian_name || '—'}</td>
                    <td className="exp-desk-amt">{formatCurrency(row.amount)}</td>
                    <td>
                      <PettyCashStatusBadge status={row.status} isPosted={row.is_posted} />
                    </td>
                    <td className="exp-desk-row-actions" data-exp-row-ignore>
                      <div className="pc-voucher-table__actions">
                        {canManage && row.status === 'pending' ? (
                          <>
                            <button
                              type="button"
                              className="pc-voucher-card__btn pc-voucher-card__btn--approve"
                              disabled={busyId === row.id}
                              onClick={() => runAction('approve_voucher', row.id)}
                            >
                              Approve
                            </button>
                            <button
                              type="button"
                              className="pc-voucher-card__btn pc-voucher-card__btn--reject"
                              disabled={busyId === row.id}
                              onClick={() => runAction('reject_voucher', row.id)}
                            >
                              Reject
                            </button>
                          </>
                        ) : null}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {!fullList && replenishments.length > 0 ? (
        <section className="exp-desk-results">
          <div className="exp-desk-results-head">
            <span className="exp-desk-results-count">Recent top-up requests</span>
            <a href={deskPageUrl('replenishments/index.php')} className="exp-desk-action-link">View all</a>
          </div>
          <div className="exp-desk-table-wrap">
            <table className="exp-desk-table">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th>Date</th>
                  <th>Custodian</th>
                  <th>Amount</th>
                  <th>Status</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {replenishments.map((rep) => (
                  <tr key={rep.id}>
                    <td>{rep.replenishment_number || `#${rep.id}`}</td>
                    <td>{formatDate(rep.approved_at || rep.created_at)}</td>
                    <td>{rep.custodian_name || '?'}</td>
                    <td className="exp-desk-amt">{formatCurrency(rep.amount)}</td>
                    <td><PettyCashStatusBadge status={rep.status} /></td>
                    <td className="exp-desk-row-actions">
                      {canManage && rep.status === 'pending' ? (
                        <a href={rep.confirm_url} className="exp-desk-action-link">Review</a>
                      ) : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      ) : null}

      {categories.length > 0 ? (
        <p className="exp-desk-footnote" style={{ fontSize: '0.8125rem', color: '#64748b', margin: 0 }}>
          Categories:
          {' '}
          {categories.slice(0, 6).map((c) => c.name).join(' | ')}
          {' '}
          <a href={urls.categories || deskPageUrl('categories/index.php')}>Manage</a>
        </p>
      ) : null}
    </div>
  );
}
