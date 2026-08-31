import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import {
  AlertTriangle,
  Clock,
  Coins,
  FileText,
  Loader2,
  MoreVertical,
  Plus,
  Receipt,
  Search,
  SlidersHorizontal,
  X,
} from 'lucide-react';
import { deskPageUrl, fetchDeskEntries, fetchDeskInit } from '../api/revenueDesk';
import RevenueExportModal from '../components/RevenueExportModal';
import RevenuePayModal from '../components/RevenuePayModal';
import RevenueQuickViewModal from '../components/RevenueQuickViewModal';
import RevenueKpiTraceModal from '../components/RevenueKpiTraceModal';
import { resolveKpiTrace } from '../utils/kpiTrace';

const emptyAdvancedFilters = {
  date_from: '',
  date_to: '',
  customer_id: '',
  type: '',
  status: '',
  payment: '',
};

const emptyFilters = {
  tab: 'all',
  search: '',
  ...emptyAdvancedFilters,
  sort: 'date',
  dir: 'desc',
};

function hasAdvancedFilters(activeFilters, defaults) {
  const d = defaults || {};
  if (String(activeFilters.customer_id ?? '').trim() !== '') return true;
  if (String(activeFilters.type ?? '').trim() !== '') return true;
  if (String(activeFilters.status ?? '').trim() !== '') return true;
  if (String(activeFilters.payment ?? '').trim() !== '') return true;
  if ((activeFilters.date_from || '') !== (d.date_from || '')) return true;
  if ((activeFilters.date_to || '') !== (d.date_to || '')) return true;
  return false;
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

function formatCurrency(value) {
  const amount = Number(value) || 0;
  return `TZS ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatPctOfTotal(value) {
  const pct = Number(value) || 0;
  return `${pct.toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}% of total`;
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

function KpiCard({
  icon: Icon, iconTone, label, value, foot, onClick, ariaLabel, isMoney = true,
}) {
  return (
    <button
      type="button"
      className="rev-desk-kpi rev-desk-kpi-card rev-desk-kpi-card--clickable"
      onClick={onClick}
      aria-label={ariaLabel || `View details for ${label}`}
    >
      <div className={`rev-desk-kpi-icon rev-desk-kpi-icon--${iconTone}`} aria-hidden="true">
        <Icon size={17} strokeWidth={2} />
      </div>
      <div className="rev-desk-kpi-body">
        <div className="rev-desk-kpi-label">{label}</div>
        <div className={`rev-desk-kpi-value${isMoney ? ' rev-desk-kpi-value--money' : ''}`}>{value}</div>
        {foot ? <div className="rev-desk-kpi-foot rev-desk-kpi-foot--muted">{foot}</div> : null}
      </div>
    </button>
  );
}

function isRowActionTarget(target) {
  if (!(target instanceof Element)) return false;
  return Boolean(
    target.closest('a, button, input, select, textarea, label, [data-rev-row-ignore]'),
  );
}

function RevenueRowActionsMenu({ menu, onClose, onPay }) {
  if (!menu || typeof document === 'undefined') return null;

  const style = {
    position: 'fixed',
    top: `${menu.top}px`,
    right: `${menu.right}px`,
    zIndex: 14000,
  };

  return createPortal(
    <div className="rev-desk-row-menu rev-desk-row-menu--portal" role="menu" style={style} data-rev-row-menu>
      {menu.canEdit ? (
        <a
          role="menuitem"
          href={deskPageUrl('revenue_edit.php', { id: menu.id })}
          onClick={onClose}
        >
          Edit
        </a>
      ) : null}
      {menu.canPay ? (
        <button
          type="button"
          role="menuitem"
          className="rev-desk-row-menu-pay"
          onClick={(event) => {
            event.stopPropagation();
            onClose();
            onPay(menu.id);
          }}
        >
          Pay
        </button>
      ) : null}
    </div>,
    document.body,
  );
}

export default function RevenueDeskPage() {
  const [init, setInit] = useState(null);
  const [desk, setDesk] = useState(null);
  const [filters, setFilters] = useState(emptyFilters);
  const [draftFilters, setDraftFilters] = useState({ ...emptyAdvancedFilters });
  const [draftSearch, setDraftSearch] = useState('');
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const skipFilterFetch = useRef(true);
  const appliedSearchRef = useRef('');
  const [searchOpen, setSearchOpen] = useState(false);
  const searchInputRef = useRef(null);
  const mobileSearchInputRef = useRef(null);
  const searchExpandRef = useRef(null);
  const filterDropdownRef = useRef(null);
  const filterBtnRef = useRef(null);
  const filterPanelRef = useRef(null);
  const [payEntryId, setPayEntryId] = useState(null);
  const [previewEntry, setPreviewEntry] = useState(null);
  const [rowMenu, setRowMenu] = useState(null);
  const [successMessage, setSuccessMessage] = useState('');
  const [activeKpiTrace, setActiveKpiTrace] = useState(null);
  const [exportOpen, setExportOpen] = useState(false);

  function closeRowMenu() {
    setRowMenu(null);
  }

  function openRowMenu(event, row) {
    event.stopPropagation();
    const button = event.currentTarget;
    const rect = button.getBoundingClientRect();
    setRowMenu((current) => {
      if (current?.id === row.id) return null;
      return {
        id: row.id,
        canEdit: Boolean(row.can_edit),
        canPay: Boolean(row.can_pay),
        top: Math.round(rect.bottom + 4),
        right: Math.round(window.innerWidth - rect.right),
      };
    });
  }

  function handleEntryRowClick(event, row) {
    if (isRowActionTarget(event.target)) return;
    setPreviewEntry(row);
  }

  function openKpiTrace(key) {
    const trace = resolveKpiTrace(key, init, desk, filters);
    if (trace) {
      setActiveKpiTrace({ key, trace });
    }
  }

  async function reloadDesk(currentFilters = filters) {
    const listData = await fetchDeskEntries(currentFilters);
    setDesk(listData);
  }

  useEffect(() => {
    if (typeof window === 'undefined') return;
    const params = new URLSearchParams(window.location.search);
    const success = params.get('success');
    let changed = false;
    if (success) {
      setSuccessMessage(success);
      params.delete('success');
      changed = true;
    }
    if (params.get('open_export') === '1') {
      setExportOpen(true);
      params.delete('open_export');
      changed = true;
    }
    if (changed) {
      const qs = params.toString();
      const next = `${window.location.pathname}${qs ? `?${qs}` : ''}`;
      window.history.replaceState({}, '', next);
    }
  }, []);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError('');
      try {
        const initData = await fetchDeskInit();
        if (cancelled) return;
        setInit(initData);
        const defaults = initData.default_filters || {};
        const urlParams = typeof window !== 'undefined'
          ? new URLSearchParams(window.location.search)
          : null;
        const urlStatus = urlParams?.get('status') || '';
        const urlPayment = urlParams?.get('payment') || '';
        const urlType = urlParams?.get('type') || '';
        const initial = {
          ...emptyFilters,
          date_from: defaults.date_from || '',
          date_to: defaults.date_to || '',
          status: urlStatus,
          payment: urlPayment,
          type: urlType,
        };
        setFilters(initial);
        setDraftFilters({
          date_from: initial.date_from,
          date_to: initial.date_to,
          customer_id: initial.customer_id,
          type: initial.type,
          status: initial.status,
          payment: initial.payment,
        });
        const listData = await fetchDeskEntries(initial);
        if (!cancelled) setDesk(listData);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Failed to load revenues.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, []);

  useEffect(() => {
    if (!init) return undefined;
    if (skipFilterFetch.current) {
      skipFilterFetch.current = false;
      return undefined;
    }
    let cancelled = false;
    (async () => {
      setError('');
      try {
        const listData = await fetchDeskEntries(filters);
        if (!cancelled) setDesk(listData);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Failed to load revenues.');
        }
      }
    })();
    return () => { cancelled = true; };
  }, [filters, init]);

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
    if (!rowMenu) return undefined;

    function handlePointerDown(event) {
      if (!(event.target instanceof Element)) return;
      if (event.target.closest('[data-rev-row-menu]')) return;
      closeRowMenu();
    }

    function handleKeyDown(event) {
      if (event.key === 'Escape') closeRowMenu();
    }

    function handleRepositionClose() {
      closeRowMenu();
    }

    document.addEventListener('mousedown', handlePointerDown);
    document.addEventListener('keydown', handleKeyDown);
    window.addEventListener('resize', handleRepositionClose);
    window.addEventListener('scroll', handleRepositionClose, true);
    return () => {
      document.removeEventListener('mousedown', handlePointerDown);
      document.removeEventListener('keydown', handleKeyDown);
      window.removeEventListener('resize', handleRepositionClose);
      window.removeEventListener('scroll', handleRepositionClose, true);
    };
  }, [rowMenu]);

  useEffect(() => {
    if (filtersOpen) {
      document.body.style.overflow = 'hidden';
      return () => { document.body.style.overflow = ''; };
    }
    return undefined;
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

  useEffect(() => {
    const next = draftSearch.trim();
    if (next === appliedSearchRef.current) return undefined;
    const timer = window.setTimeout(() => {
      appliedSearchRef.current = next;
      setFilters((current) => (
        current.search === next ? current : { ...current, search: next }
      ));
    }, 280);
    return () => window.clearTimeout(timer);
  }, [draftSearch]);

  function applySearch() {
    const next = draftSearch.trim();
    appliedSearchRef.current = next;
    setFilters((current) => (
      current.search === next ? current : { ...current, search: next }
    ));
  }

  function clearSearch(inputRef) {
    appliedSearchRef.current = '';
    setDraftSearch('');
    setFilters((current) => (
      current.search === '' ? current : { ...current, search: '' }
    ));
    setSearchOpen(false);
    if (inputRef?.current) {
      inputRef.current.focus();
    }
  }

  function toggleMobileSearch() {
    setSearchOpen((open) => !open);
  }

  function renderSearchField(inputRef, id = 'rev-desk-search') {
    return (
      <div className="rev-desk-search-field">
        <Search className="rev-desk-search-icon" aria-hidden="true" />
        <input
          ref={inputRef}
          id={id}
          type="text"
          role="searchbox"
          className="rev-desk-search-input"
          placeholder="Search voucher, customer, narration"
          value={draftSearch}
          onChange={(e) => setDraftSearch(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') applySearch();
            if (e.key === 'Escape') clearSearch(inputRef);
          }}
          aria-label="Search revenues"
          autoComplete="off"
        />
        {(draftSearch.trim() !== '' || filters.search.trim() !== '') && (
          <button
            type="button"
            className="rev-desk-search-clear"
            onClick={() => clearSearch(inputRef)}
            aria-label="Clear search"
          >
            <X size={16} aria-hidden="true" />
          </button>
        )}
      </div>
    );
  }

  const hasSearchValue = filters.search.trim() !== '' || draftSearch.trim() !== '';
  const defaultFilters = init?.default_filters || {};

  function applyFilters() {
    setFilters((current) => ({ ...current, ...draftFilters }));
    setFiltersOpen(false);
  }

  function openFilters() {
    setDraftFilters({
      date_from: filters.date_from,
      date_to: filters.date_to,
      customer_id: filters.customer_id,
      type: filters.type,
      status: filters.status,
      payment: filters.payment,
    });
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
    setDraftFilters({ ...emptyAdvancedFilters });
  }

  function resetFilters() {
    const cleared = {
      ...emptyAdvancedFilters,
      date_from: defaultFilters.date_from || '',
      date_to: defaultFilters.date_to || '',
    };
    setDraftFilters(cleared);
    setFilters((current) => ({ ...current, ...cleared }));
    setFiltersOpen(false);
  }

  function clearAdvancedFilter(key) {
    if (key === 'date_from') {
      const cleared = {
        date_from: defaultFilters.date_from || '',
        date_to: defaultFilters.date_to || '',
      };
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

    if (activeFilters.date_from || activeFilters.date_to) {
      const fromLabel = activeFilters.date_from ? formatFilterDateLabel(activeFilters.date_from) : 'Any';
      const toLabel = activeFilters.date_to ? formatFilterDateLabel(activeFilters.date_to) : 'Any';
      if (
        (activeFilters.date_from || '') !== (defaultFilters.date_from || '')
        || (activeFilters.date_to || '') !== (defaultFilters.date_to || '')
      ) {
        chips.push({
          key: 'date_from',
          label: `Date: ${fromLabel} - ${toLabel}`,
        });
      }
    }

    if (activeFilters.customer_id) {
      const customer = customers.find((c) => String(c.id) === String(activeFilters.customer_id));
      chips.push({
        key: 'customer_id',
        label: `Customer: ${customer?.name || activeFilters.customer_id}`,
      });
    }

    if (activeFilters.type) {
      const typeOpt = typeOptions.find((opt) => opt.value === activeFilters.type);
      chips.push({
        key: 'type',
        label: `Type: ${typeOpt?.label || activeFilters.type}`,
      });
    }

    if (activeFilters.status) {
      const statusOpt = statusOptions.find((opt) => opt.value === activeFilters.status);
      chips.push({
        key: 'status',
        label: `Status: ${statusOpt?.label || activeFilters.status}`,
      });
    }

    if (activeFilters.payment) {
      chips.push({
        key: 'payment',
        label: `Payment: ${activeFilters.payment}`,
      });
    }

    return chips;
  }

  function renderFiltersPanel() {
    if (!filtersOpen) return null;

    return createPortal(
      <div
        className="rev-desk-filters-backdrop"
        role="presentation"
        onClick={() => setFiltersOpen(false)}
      >
        <div
          ref={filterPanelRef}
          className="rev-desk-filters-panel"
          role="dialog"
          aria-label="Filter options"
          onClick={(event) => event.stopPropagation()}
        >
        <div className="rev-desk-filters-head">
          <div>
            <h2 className="rev-desk-filters-title">Filters</h2>
            <p className="rev-desk-filters-sub">Narrow the list by date, customer, type, status, and payment.</p>
          </div>
          <button
            type="button"
            className="rev-desk-filters-close"
            onClick={() => setFiltersOpen(false)}
            aria-label="Close filters"
          >
            <X size={16} aria-hidden="true" />
          </button>
        </div>

        <div className="rev-desk-filters-body">
        <div className="rev-desk-filters-section">
          <div className="rev-desk-filters-section-label">Date range</div>
          <div className="rev-desk-date-presets" role="group" aria-label="Quick date ranges">
            <button type="button" className="rev-desk-date-preset" onClick={() => setDatePreset('month')}>
              This month
            </button>
            <button type="button" className="rev-desk-date-preset" onClick={() => setDatePreset('30d')}>
              Last 30 days
            </button>
            <button type="button" className="rev-desk-date-preset" onClick={() => setDatePreset('clear')}>
              Clear dates
            </button>
          </div>
          <div className="rev-desk-filters-grid rev-desk-filters-grid--dates">
            <div className="rev-desk-field">
              <label htmlFor="rev-filter-date-from">From</label>
              <input
                id="rev-filter-date-from"
                type="date"
                value={draftFilters.date_from}
                onChange={(e) => setDraftFilters((f) => ({ ...f, date_from: e.target.value }))}
              />
            </div>
            <div className="rev-desk-field">
              <label htmlFor="rev-filter-date-to">To</label>
              <input
                id="rev-filter-date-to"
                type="date"
                value={draftFilters.date_to}
                onChange={(e) => setDraftFilters((f) => ({ ...f, date_to: e.target.value }))}
              />
            </div>
          </div>
        </div>

        <div className="rev-desk-filters-section">
          <div className="rev-desk-filters-section-label">Details</div>
          <div className="rev-desk-filters-grid">
            <div className="rev-desk-field">
              <label htmlFor="rev-filter-type">Type</label>
              <select
                id="rev-filter-type"
                value={draftFilters.type}
                onChange={(e) => setDraftFilters((f) => ({ ...f, type: e.target.value }))}
              >
                {typeOptions.map((opt) => (
                  <option key={opt.value || 'all'} value={opt.value}>{opt.label}</option>
                ))}
              </select>
            </div>
            <div className="rev-desk-field">
              <label htmlFor="rev-filter-status">Status</label>
              <select
                id="rev-filter-status"
                value={draftFilters.status}
                onChange={(e) => setDraftFilters((f) => ({ ...f, status: e.target.value }))}
              >
                {statusOptions.map((opt) => (
                  <option key={opt.value || 'all'} value={opt.value}>{opt.label}</option>
                ))}
              </select>
            </div>
            <div className="rev-desk-field rev-desk-field--full">
              <label htmlFor="rev-filter-customer">Customer</label>
              <select
                id="rev-filter-customer"
                value={draftFilters.customer_id}
                onChange={(e) => setDraftFilters((f) => ({ ...f, customer_id: e.target.value }))}
              >
                <option value="">All customers</option>
                {customers.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </div>
            <div className="rev-desk-field rev-desk-field--full">
              <label htmlFor="rev-filter-payment">Payment</label>
              <select
                id="rev-filter-payment"
                value={draftFilters.payment}
                onChange={(e) => setDraftFilters((f) => ({ ...f, payment: e.target.value }))}
              >
                <option value="">All methods</option>
                {paymentModes.map((pm) => (
                  <option key={pm} value={pm}>{pm}</option>
                ))}
              </select>
            </div>
          </div>
        </div>
        </div>

        <div className="rev-desk-filters-footer">
          <button type="button" className="rev-desk-btn rev-desk-btn-ghost" onClick={resetDraftFilters}>
            Clear
          </button>
          <button
            type="button"
            className="rev-desk-btn rev-desk-btn-primary"
            onClick={applyFilters}
          >
            Apply filters
          </button>
        </div>
        </div>
      </div>,
      document.body,
    );
  }

  function toggleSort(column) {
    setFilters((current) => {
      const nextDir = current.sort === column && current.dir === 'desc' ? 'asc' : 'desc';
      return { ...current, sort: column, dir: nextDir };
    });
  }

  const entries = desk?.data ?? [];
  const kpi = desk?.kpi ?? {};
  const invoiceKpi = desk?.invoice_kpi ?? {};
  const customers = init?.customers ?? [];
  const paymentModes = init?.payment_modes ?? [];
  const statusOptions = init?.status_options ?? [];
  const typeOptions = init?.type_options ?? [];
  const activeFilterChips = buildActiveFilterChips(filters);

  if (loading && !desk) {
    return (
      <div className="rev-desk-page">
        <div className="rev-desk-loading" aria-live="polite" aria-busy="true">
          <Loader2 className="animate-spin" size={20} aria-hidden="true" />
          <span>Loading revenues</span>
        </div>
      </div>
    );
  }

  return (
    <div className="rev-desk-page">
      {error && <div className="rev-desk-error">{error}</div>}

      <div className="rev-desk-page-header">
        <div className="rev-desk-page-header-search rev-desk-page-header-search--desktop">
          {renderSearchField(searchInputRef, 'rev-desk-search-desktop')}
        </div>

        <div className="rev-desk-page-header-actions">
          <div className="rev-desk-toolbar-secondary">
            <div
              className={`rev-desk-search-expand${searchOpen ? ' is-open' : ''}`}
              ref={searchExpandRef}
            >
              <button
                type="button"
                className={`rev-desk-search-toggle${searchOpen ? ' is-active' : ''}${hasSearchValue ? ' has-value' : ''}`}
                onClick={toggleMobileSearch}
                aria-expanded={searchOpen}
                aria-controls="rev-desk-search-mobile-panel"
                title="Search revenues"
              >
                <Search size={18} aria-hidden="true" />
              </button>
              <div
                id="rev-desk-search-mobile-panel"
                className={`rev-desk-search-panel${searchOpen ? ' is-open' : ''}`}
              >
                {renderSearchField(mobileSearchInputRef, 'rev-desk-search-mobile')}
              </div>
            </div>
            <div
              className={`rev-desk-filter-dropdown${filtersOpen ? ' is-open' : ''}`}
              ref={filterDropdownRef}
            >
              <button
                ref={filterBtnRef}
                type="button"
                className={`rev-desk-filter-btn${filtersOpen ? ' is-active' : ''}`}
                onClick={toggleFilters}
                aria-expanded={filtersOpen}
                aria-haspopup="dialog"
                title="Filters"
              >
                <SlidersHorizontal size={18} strokeWidth={2.25} aria-hidden="true" />
                {hasAdvancedFilters(filters, defaultFilters) && (
                  <span className="rev-desk-filter-dot" aria-hidden="true" />
                )}
              </button>
            </div>
            <a
              href={deskPageUrl('revenue_create.php')}
              className="rev-desk-btn rev-desk-btn-primary rev-desk-btn-create"
              aria-label="New revenue"
            >
              <Plus size={16} aria-hidden="true" />
              <span className="rev-desk-btn-label-desktop">New revenue</span>
              <span className="rev-desk-btn-label-mobile">New</span>
            </a>
          </div>
        </div>
      </div>

      {successMessage ? (
        <div className="rev-desk-success" role="status">{successMessage}</div>
      ) : null}

      {activeFilterChips.length > 0 && (
        <div className="rev-desk-active-filters" aria-label="Active filters">
          {activeFilterChips.map((chip) => (
            <button
              key={chip.key}
              type="button"
              className="rev-desk-filter-chip"
              onClick={() => clearAdvancedFilter(chip.key)}
              title="Remove filter"
            >
              <span>{chip.label}</span>
              <X size={12} aria-hidden="true" />
            </button>
          ))}
          <button type="button" className="rev-desk-filter-chip rev-desk-filter-chip--clear" onClick={resetFilters}>
            Clear all
          </button>
        </div>
      )}

      {renderFiltersPanel()}

      <div className="rev-desk-kpi-grid" aria-label="Revenue summary">
        <KpiCard
          icon={Coins}
          iconTone="purple"
          label="Total (Incl. Tax)"
          value={formatCurrency(kpi.sum_total)}
          onClick={() => openKpiTrace('totalInclTax')}
        />
        <KpiCard
          icon={Clock}
          iconTone="orange"
          label="Outstanding (AR)"
          value={formatCurrency(kpi.outstanding)}
          onClick={() => openKpiTrace('outstandingAr')}
        />
        <KpiCard
          icon={FileText}
          iconTone="blue"
          label="Total Invoices"
          value={formatCurrency(invoiceKpi.total)}
          foot={invoiceKpi.count ? `${invoiceKpi.count.toLocaleString('en-US')} in view` : 'All invoices in view'}
          onClick={() => openKpiTrace('totalInvoices')}
        />
        <KpiCard
          icon={Receipt}
          iconTone="teal"
          label="Outstanding Invoices"
          value={formatCurrency(invoiceKpi.outstanding)}
          foot={formatPctOfTotal(invoiceKpi.pct_outstanding)}
          onClick={() => openKpiTrace('outstandingInvoices')}
        />
        <KpiCard
          icon={AlertTriangle}
          iconTone="red"
          label="Overdue Invoices"
          value={formatCurrency(invoiceKpi.overdue)}
          foot={formatPctOfTotal(invoiceKpi.pct_overdue)}
          onClick={() => openKpiTrace('overdueInvoices')}
        />
      </div>

      <section className="rev-desk-results" aria-label="Revenue entries">
        <div className="rev-desk-results-head">
          <span className="rev-desk-results-count">
            {desk?.total ?? entries.length} {(desk?.total ?? entries.length) === 1 ? 'entry' : 'entries'}
          </span>
        </div>

        <div className="rev-desk-table-wrap">
        <table className="rev-desk-table">
          <colgroup>
            <col className="col-voucher" />
            <col className="col-date" />
            <col className="col-customer" />
            <col className="col-type" />
            <col className="col-total" />
            <col className="col-paid" />
            <col className="col-balance" />
            <col className="col-status" />
            <col className="col-actions" />
          </colgroup>
          <thead>
            <tr>
              <th className="col-voucher">Voucher</th>
              <th className="col-date">
                <button type="button" className="rev-desk-th-sort" onClick={() => toggleSort('date')}>
                  Date
                  {filters.sort === 'date' ? (
                    <span className="rev-desk-sort-hint">{filters.dir === 'desc' ? ' ↓' : ' ↑'}</span>
                  ) : null}
                </button>
              </th>
              <th className="col-customer">Customer</th>
              <th className="col-type">Type</th>
              <th className="col-total">
                <button type="button" className="rev-desk-th-sort rev-desk-th-sort--money" onClick={() => toggleSort('amount')}>
                  Total
                  {filters.sort === 'amount' ? (
                    <span className="rev-desk-sort-hint">{filters.dir === 'desc' ? ' ↓' : ' ↑'}</span>
                  ) : null}
                </button>
              </th>
              <th className="col-paid">Paid</th>
              <th className="col-balance">Balance</th>
              <th className="col-status">Status</th>
              <th className="col-actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            {entries.length === 0 ? (
              <tr>
                <td colSpan={9}>
                  <div className="rev-desk-empty">No revenue entries match your filters.</div>
                </td>
              </tr>
            ) : entries.map((row) => (
              <tr
                key={row.id}
                className="rev-desk-row-clickable"
                tabIndex={0}
                onClick={(event) => handleEntryRowClick(event, row)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    setPreviewEntry(row);
                  }
                }}
              >
                <td className="col-voucher">
                  <span className="rev-desk-voucher" title={row.voucher_number || `#${row.id}`}>
                    {row.voucher_number || `#${row.id}`}
                  </span>
                </td>
                <td className="col-date">{formatDate(row.entry_date)}</td>
                <td className="col-customer">
                  <div className="rev-cust">
                    <span
                      className={`rev-cust-av rev-cust-av--tone-${Number(row.customer_avatar_tone ?? 0) % 10}`}
                      aria-hidden="true"
                    >
                      {row.customer_initials || '?'}
                    </span>
                    <div className="rev-cust-body">
                      <span className="rev-cust-name" title={row.customer_display || undefined}>
                        {row.customer_display || '-'}
                      </span>
                      {row.customer_code_display && row.customer_code_display !== '-' ? (
                        <span className="rev-cust-code">{row.customer_code_display}</span>
                      ) : null}
                    </div>
                  </div>
                </td>
                <td className="col-type">
                  <div className="rev-type-cell">
                    <span className={`rev-type rev-type--${row.type_class || 'other'}`}>{row.type_label}</span>
                    {row.linked_invoice_number ? (
                      <span className="rev-type-meta">{row.linked_invoice_number}</span>
                    ) : null}
                  </div>
                </td>
                <td className="col-total">
                  <span className="rev-desk-amt" title={formatCurrency(row.amount_total)}>
                    {formatCurrency(row.amount_total)}
                  </span>
                </td>
                <td className="col-paid">
                  <span
                    className={`rev-desk-amt ${Number(row.balance_due) <= 0.009 ? 'rev-desk-amt--paid-clear' : 'rev-desk-amt--muted'}`}
                    title={formatCurrency(row.amount_paid)}
                  >
                    {formatCurrency(row.amount_paid)}
                  </span>
                </td>
                <td className="col-balance">
                  <span
                    className={`rev-desk-amt ${Number(row.balance_due) > 0.009 ? 'rev-desk-amt--due' : 'rev-desk-amt--clear'}`}
                    title={formatCurrency(row.balance_due)}
                  >
                    {formatCurrency(row.balance_due)}
                  </span>
                </td>
                <td className="col-status">
                  <span className={`rev-status rev-status--${row.status_class || 'unpaid'}`}>
                    {row.status_label}
                  </span>
                </td>
                <td className="col-actions" data-rev-row-ignore data-rev-row-menu>
                  {row.can_edit || row.can_pay ? (
                    <div className={`rev-desk-row-menu-wrap${rowMenu?.id === row.id ? ' is-open' : ''}`}>
                      <button
                        type="button"
                        className="rev-desk-row-menu-btn"
                        aria-label="Row actions"
                        aria-haspopup="menu"
                        aria-expanded={rowMenu?.id === row.id}
                        onClick={(event) => openRowMenu(event, row)}
                      >
                        <MoreVertical size={16} strokeWidth={2} aria-hidden="true" />
                      </button>
                    </div>
                  ) : (
                    <span className="rev-desk-row-menu-empty" aria-hidden="true">—</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        </div>
      </section>

      {rowMenu ? (
        <RevenueRowActionsMenu
          menu={rowMenu}
          onClose={closeRowMenu}
          onPay={(entryId) => setPayEntryId(entryId)}
        />
      ) : null}

      <RevenueExportModal
        open={exportOpen}
        onClose={() => setExportOpen(false)}
        defaults={{
          status: filters.status || '',
          date_from: filters.date_from || '',
          date_to: filters.date_to || '',
        }}
      />

      {activeKpiTrace ? (
        <RevenueKpiTraceModal
          trace={activeKpiTrace.trace}
          traceKey={activeKpiTrace.key}
          onClose={() => setActiveKpiTrace(null)}
        />
      ) : null}

      {previewEntry ? (
        <RevenueQuickViewModal
          entry={previewEntry}
          onClose={() => setPreviewEntry(null)}
          onPay={(entryId) => setPayEntryId(entryId)}
        />
      ) : null}

      {payEntryId ? createPortal(
        <RevenuePayModal
          entryId={payEntryId}
          onClose={() => setPayEntryId(null)}
          onSuccess={async (result) => {
            setPayEntryId(null);
            setSuccessMessage(result.message || 'Payment recorded successfully.');
            try {
              await reloadDesk();
            } catch (err) {
              setError(err instanceof Error ? err.message : 'Payment saved but list could not refresh.');
            }
          }}
        />,
        document.body,
      ) : null}
    </div>
  );
}
