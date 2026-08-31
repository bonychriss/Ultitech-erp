import {
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import { createPortal } from 'react-dom';
import {
  CheckCircle2,
  Clock,
  FileText,
  Loader2,
  Search,
  SlidersHorizontal,
  Wallet,
  X,
} from 'lucide-react';
import { fetchQuotationsInit, submitDeleteForm } from '../api/quotationsDesk';
import QuotationKpiTraceModal from '../components/QuotationKpiTraceModal';
import { resolveQuotationKpiTrace } from '../utils/quotationKpiTrace';
import {
  handleInvoiceLinkClick,
  resolveInvoiceSourceLabel,
  applyInvoicedStatusToRows,
} from '../utils/invoiceTransform';

const AVATAR_STYLES = [
  { background: '#e0f2fe', color: '#0369a1' },
  { background: '#ede9fe', color: '#6d28d9' },
  { background: '#d1fae5', color: '#047857' },
  { background: '#fef3c7', color: '#b45309' },
  { background: '#ffe4e6', color: '#be123c' },
  { background: '#cffafe', color: '#0e7490' },
  { background: '#e0e7ff', color: '#4338ca' },
  { background: '#ccfbf1', color: '#0f766e' },
];

const STATUS_OPTIONS = [
  { value: '', label: 'All statuses' },
  { value: 'quotation', label: 'Quotation' },
  { value: 'draft', label: 'Draft' },
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'invoiced', label: 'Invoiced' },
  { value: 'processing', label: 'Processing' },
  { value: 'shipped', label: 'Shipped' },
  { value: 'delivered', label: 'Delivered' },
  { value: 'paid', label: 'Paid' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
];

function orderCanDirectInvoice(status) {
  const st = (status || '').toLowerCase().trim();
  if (['cancelled', 'canceled', 'delivered', 'invoiced', 'paid'].includes(st)) return false;
  return ['draft', 'quotation', 'sent', 'confirmed'].includes(st);
}

function rowHasInvoice(row) {
  const st = (row?.status || '').toLowerCase().trim();
  return st === 'invoiced' || st === 'paid' || Boolean(row?.has_invoice);
}

function buildInvoiceCreateHref(urls, orderId) {
  const base = urls?.invoice_create || '../invoices/create.php';
  const join = base.includes('?') ? '&' : '?';
  return `${base}${join}order_id=${encodeURIComponent(String(orderId))}`;
}

function invoiceTransformOptions(row) {
  const st = (row?.status || '').toLowerCase().trim();
  return {
    sourceLabel: resolveInvoiceSourceLabel(row?.status),
    documentNumber: row?.order_number || '',
    existingInvoice: st === 'invoiced' || st === 'paid',
    orderId: row?.id,
  };
}

function statusClass(status) {
  const st = (status || '').toLowerCase().trim();
  if (st === 'invoiced') return 'qt-status--teal';
  if (['confirmed', 'paid', 'completed', 'delivered'].includes(st)) return 'qt-status--green';
  if (['quotation', 'sent'].includes(st)) return 'qt-status--cyan';
  if (['draft', 'pending', 'processing'].includes(st)) return 'qt-status--amber';
  if (['cancelled', 'canceled'].includes(st)) return 'qt-status--red';
  if (st === 'shipped') return 'qt-status--purple';
  return 'qt-status--gray';
}

function statusLabel(status) {
  const st = (status || '').toLowerCase().trim();
  const labels = {
    draft: 'Draft',
    quotation: 'Quotation',
    confirmed: 'Confirmed',
    invoiced: 'Invoiced',
    processing: 'Processing',
    pending: 'Pending',
    shipped: 'Shipped',
    delivered: 'Delivered',
    paid: 'Paid',
    completed: 'Completed',
    cancelled: 'Cancelled',
  };
  return labels[st] || (status || '-');
}

function initials(name) {
  if (!name || !String(name).trim()) return '?';
  return String(name).split(/\s+/).map((w) => w[0]).join('').toUpperCase().slice(0, 2);
}

function avatarStyle(name) {
  const s = name && String(name).trim() || '';
  if (!s) return { background: '#f3f4f6', color: '#6b7280' };
  let h = 0;
  for (let i = 0; i < s.length; i++) h = (h << 5) - h + s.charCodeAt(i) | 0;
  return AVATAR_STYLES[Math.abs(h) % AVATAR_STYLES.length];
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount || 0);
}

function formatDateTime(dateStr) {
  if (!dateStr) return '-';
  const d = new Date(dateStr.includes('T') ? dateStr : dateStr.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return '-';
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
}

function fmtBig(n) {
  return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(n || 0);
}

function buildUrl(base, id) {
  if (!base) return '#';
  const sep = base.includes('?') ? '&' : '?';
  return `${base}${sep}id=${id}`;
}

function TypeBadge({ type }) {
  const t = (type || 'spare').toLowerCase();
  if (t === 'truck') {
    return <span className="qt-type-badge qt-type-badge--truck"><i className="fas fa-truck" /> TRUCK</span>;
  }
  return <span className="qt-type-badge qt-type-badge--spare"><i className="fas fa-wrench" /> SPARE</span>;
}

function StatusPill({ status }) {
  return <span className={`qt-status ${statusClass(status)}`}>{statusLabel(status)}</span>;
}

export default function QuotationsListPage() {
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [myQuotationsOnly, setMyQuotationsOnly] = useState(false);
  const [draftStatusFilter, setDraftStatusFilter] = useState('');
  const [draftMyQuotationsOnly, setDraftMyQuotationsOnly] = useState(false);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);
  const [selectedIds, setSelectedIds] = useState(() => new Set());
  const [openMenuId, setOpenMenuId] = useState(null);
  const [filterPanelStyle, setFilterPanelStyle] = useState(null);
  const [activeKpiTrace, setActiveKpiTrace] = useState(null);

  const filterDropdownRef = useRef(null);
  const filterBtnRef = useRef(null);
  const filterPanelRef = useRef(null);
  const searchExpandRef = useRef(null);
  const searchInputRef = useRef(null);
  const mobileSearchInputRef = useRef(null);

  const loadInit = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const data = await fetchQuotationsInit();
      setInit(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load quotations.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { loadInit(); }, [loadInit]);

  useEffect(() => {
    if (openMenuId == null) return undefined;
    const close = (ev) => {
      if (ev.target?.closest?.('[data-qt-actions]')) return;
      setOpenMenuId(null);
    };
    document.addEventListener('click', close);
    return () => document.removeEventListener('click', close);
  }, [openMenuId]);

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
    if (!searchOpen) return undefined;
    function handlePointerDown(event) {
      if (!searchExpandRef.current?.contains(event.target)) {
        if (search.trim() === '') setSearchOpen(false);
      }
    }
    document.addEventListener('mousedown', handlePointerDown);
    return () => document.removeEventListener('mousedown', handlePointerDown);
  }, [searchOpen, search]);

  const quotations = useMemo(
    () => applyInvoicedStatusToRows(init?.quotations || []),
    [init?.quotations],
  );
  const urls = init?.urls || {};
  const currentUserId = init?.current_user_id || 0;
  const isRoadmaster = !!init?.is_roadmaster;
  const supportsOrderTypeSplit = !!init?.supports_order_type_split;
  const useRmShell = !!init?.use_rm_shell_layout;
  const defaultCurrency = init?.default_currency || 'TZS';

  const filteredQuotations = useMemo(() => quotations.filter((q) => {
    const mineOk = !myQuotationsOnly || Number(q.created_by) === Number(currentUserId);
    const qSearch = search.toLowerCase();
    const matchesSearch = !qSearch
      || (q.order_number || '').toLowerCase().includes(qSearch)
      || (q.company_name || '').toLowerCase().includes(qSearch)
      || (q.salesperson || '').toLowerCase().includes(qSearch);
    const matchesStatus = !statusFilter || (q.status || '').toLowerCase() === statusFilter;
    return mineOk && matchesSearch && matchesStatus;
  }), [quotations, search, statusFilter, myQuotationsOnly, currentUserId]);

  const quotationStats = useMemo(() => {
    const y = new Date().getFullYear();
    let totalVal = 0;
    let pending = 0;
    let convertedYtd = 0;
    quotations.forEach((q) => {
      totalVal += parseFloat(q.total_amount) || 0;
      const st = (q.status || '').toLowerCase();
      if (['quotation', 'draft', 'sent'].includes(st)) pending += 1;
      if (['confirmed', 'invoiced', 'processing', 'shipped', 'delivered', 'paid', 'completed'].includes(st)) {
        const d = q.created_at ? new Date(q.created_at) : null;
        if (d && !Number.isNaN(d.getTime()) && d.getFullYear() === y) convertedYtd += 1;
      }
    });
    return { total: quotations.length, totalVal, pending, convertedYtd };
  }, [quotations]);

  const kpiTraceContext = useMemo(() => ({
    quotations,
    filteredQuotations,
    filters: {
      search,
      statusFilter,
      myQuotationsOnly,
    },
    statusOptions: STATUS_OPTIONS,
    defaultCurrency,
  }), [quotations, filteredQuotations, search, statusFilter, myQuotationsOnly, defaultCurrency]);

  const selectedList = useMemo(
    () => filteredQuotations.filter((q) => selectedIds.has(q.id)),
    [filteredQuotations, selectedIds],
  );
  const canDelete = selectedList.length > 0 && selectedList.every((q) => (q.status || '').toLowerCase() === 'quotation');
  const canInvoice = selectedList.length === 1
    && orderCanDirectInvoice(selectedList[0]?.status)
    && !rowHasInvoice(selectedList[0]);
  const hasActiveFilters = !!statusFilter || myQuotationsOnly;
  const hasSearchValue = search.trim() !== '';

  const activeFilterChips = useMemo(() => {
    const chips = [];
    if (statusFilter) {
      const label = STATUS_OPTIONS.find((o) => o.value === statusFilter)?.label || statusFilter;
      chips.push({ key: 'status', label: `Status: ${label}` });
    }
    if (myQuotationsOnly) chips.push({ key: 'mine', label: 'My quotations' });
    return chips;
  }, [statusFilter, myQuotationsOnly]);

  function toggleSelection(id, e) {
    e.stopPropagation();
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function handleSelectAll(e) {
    if (e.target.checked) {
      setSelectedIds(new Set(filteredQuotations.map((q) => q.id)));
    } else {
      setSelectedIds(new Set());
    }
  }

  function handleDelete() {
    if (!window.confirm('Delete selected quotations? (Only quotation status, no invoice)')) return;
    submitDeleteForm(urls.delete_post || 'create.php', Array.from(selectedIds));
  }

  function handleNewClick() {
    if (!isRoadmaster) {
      window.location.href = urls.create_new || 'create.php?mode=new';
      return;
    }
    if (typeof window.Swal === 'undefined') {
      window.location.href = urls.create_spare || 'create.php?mode=new&type=spare';
      return;
    }
    window.Swal.fire({
      title: 'Select Quotation Type',
      text: 'What kind of quotation would you like to create?',
      icon: 'question',
      showDenyButton: true,
      showCancelButton: true,
      confirmButtonText: '<i class="fas fa-truck me-2"></i> Truck Quote',
      denyButtonText: '<i class="fas fa-cogs me-2"></i> Spare Part Quote',
      confirmButtonColor: '#714b67',
      denyButtonColor: '#008784',
      cancelButtonColor: '#94a3b8',
    }).then((result) => {
      if (result.isConfirmed) window.location.href = urls.create_truck || 'create.php?mode=new&type=truck';
      else if (result.isDenied) window.location.href = urls.create_spare || 'create.php?mode=new&type=spare';
    });
  }

  function goView(id) {
    window.location.href = buildUrl(urls.view || 'view.php', id);
  }

  function openKpiTrace(key) {
    const trace = resolveQuotationKpiTrace(key, kpiTraceContext);
    if (trace) {
      setActiveKpiTrace({ key, trace });
    }
  }

  function kpiCardProps(key, label) {
    return {
      type: 'button',
      className: 'exp-desk-kpi exp-desk-kpi-card exp-desk-kpi-card--clickable',
      onClick: () => openKpiTrace(key),
      'aria-label': `View summary for ${label}`,
      title: 'Click to see breakdown',
    };
  }

  function toggleFilters() {
    setFiltersOpen((open) => {
      const next = !open;
      if (next) {
        setDraftStatusFilter(statusFilter);
        setDraftMyQuotationsOnly(myQuotationsOnly);
      }
      return next;
    });
  }

  function applyFilters() {
    setStatusFilter(draftStatusFilter);
    setMyQuotationsOnly(draftMyQuotationsOnly);
    setFiltersOpen(false);
  }

  function resetFilters() {
    setStatusFilter('');
    setMyQuotationsOnly(false);
    setDraftStatusFilter('');
    setDraftMyQuotationsOnly(false);
    setFiltersOpen(false);
  }

  function clearAdvancedFilter(key) {
    if (key === 'status') setStatusFilter('');
    if (key === 'mine') setMyQuotationsOnly(false);
  }

  function clearSearch() {
    setSearch('');
    setSearchOpen(false);
  }

  function toggleMobileSearch() {
    setSearchOpen((open) => !open);
    if (!searchOpen) {
      window.setTimeout(() => mobileSearchInputRef.current?.focus(), 0);
    }
  }

  function renderSearchField(inputRef, id = 'qt-desk-search') {
    return (
      <div className="exp-desk-search-field">
        <Search className="exp-desk-search-icon" aria-hidden="true" />
        <input
          ref={inputRef}
          id={id}
          type="search"
          className="exp-desk-search-input"
          placeholder="Search number, customer, salesperson..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          autoComplete="off"
          aria-label="Search quotations"
        />
        {search.trim() !== '' && (
          <button type="button" className="exp-desk-search-clear" onClick={clearSearch} aria-label="Clear search">
            <X className="w-4 h-4" />
          </button>
        )}
      </div>
    );
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
            <p className="exp-desk-filters-sub">Narrow the list by status or ownership.</p>
          </div>
          <button type="button" className="exp-desk-filters-close" onClick={() => setFiltersOpen(false)} aria-label="Close filters">
            <X size={16} aria-hidden="true" />
          </button>
        </div>

        <div className="exp-desk-filters-section">
          <div className="exp-desk-filters-section-label">Details</div>
          <div className="exp-desk-filters-grid">
            <div className="exp-desk-field exp-desk-field--full">
              <label htmlFor="qtFilterStatus">Status</label>
              <select
                id="qtFilterStatus"
                value={draftStatusFilter}
                onChange={(e) => setDraftStatusFilter(e.target.value)}
              >
                {STATUS_OPTIONS.map((opt) => (
                  <option key={opt.value || 'all'} value={opt.value}>{opt.label}</option>
                ))}
              </select>
            </div>
            <div className="exp-desk-field exp-desk-field--full">
              <label htmlFor="qtFilterScope">Scope</label>
              <select
                id="qtFilterScope"
                value={draftMyQuotationsOnly ? 'mine' : 'all'}
                onChange={(e) => setDraftMyQuotationsOnly(e.target.value === 'mine')}
              >
                <option value="all">All quotations</option>
                <option value="mine">My quotations</option>
              </select>
            </div>
          </div>
        </div>

        <div className="exp-desk-filters-footer">
          <button type="button" className="exp-desk-btn exp-desk-btn-ghost" onClick={() => {
            setDraftStatusFilter('');
            setDraftMyQuotationsOnly(false);
          }}
          >
            Clear
          </button>
          <button type="button" className="exp-desk-btn exp-desk-btn-primary" onClick={applyFilters}>
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
        <span>Loading quotations...</span>
      </div>
    );
  }

  if (error || !init) {
    return (
      <div className="exp-desk-page">
        <div className="exp-desk-flash exp-desk-flash-error" role="alert">{error || 'Could not load quotations.'}</div>
      </div>
    );
  }

  const createQuotationBtn = isRoadmaster ? (
    <button type="button" className="exp-desk-btn exp-desk-btn-primary exp-desk-btn-create" onClick={handleNewClick}>
      <span className="exp-desk-btn-label-desktop">Create quotation</span>
      <span className="exp-desk-btn-label-mobile">New</span>
    </button>
  ) : (
    <a href={urls.create_new} className="exp-desk-btn exp-desk-btn-primary exp-desk-btn-create">
      <span className="exp-desk-btn-label-desktop">Create quotation</span>
      <span className="exp-desk-btn-label-mobile">New</span>
    </a>
  );

  return (
    <div className="exp-desk-page">
      {init.flash && <div className="exp-desk-flash exp-desk-flash-success" role="status">{init.flash}</div>}
      {renderFiltersPanel()}

      <div className="exp-desk-page-header">
        <div className="exp-desk-page-header-search exp-desk-page-header-search--desktop">
          {renderSearchField(searchInputRef, 'qt-desk-search-desktop')}
        </div>

        <div className="exp-desk-page-header-actions">
          <div className="exp-desk-toolbar-secondary">
            <div className={`exp-desk-search-expand${searchOpen ? ' is-open' : ''}`} ref={searchExpandRef}>
              <button
                type="button"
                className={`exp-desk-search-toggle${searchOpen ? ' is-active' : ''}${hasSearchValue ? ' has-value' : ''}`}
                onClick={toggleMobileSearch}
                aria-expanded={searchOpen}
                aria-controls="qt-desk-search-mobile-panel"
                title="Search quotations"
              >
                <Search size={18} aria-hidden="true" />
              </button>
              <div id="qt-desk-search-mobile-panel" className={`exp-desk-search-panel${searchOpen ? ' is-open' : ''}`}>
                {renderSearchField(mobileSearchInputRef, 'qt-desk-search-mobile')}
              </div>
            </div>

            <div className={`exp-desk-filter-dropdown${filtersOpen ? ' is-open' : ''}`} ref={filterDropdownRef}>
              <button
                ref={filterBtnRef}
                type="button"
                className={`exp-desk-filter-btn${filtersOpen ? ' is-active' : ''}`}
                onClick={toggleFilters}
                aria-expanded={filtersOpen}
                aria-haspopup="dialog"
                title="Filters"
              >
                <SlidersHorizontal size={18} aria-hidden="true" />
                {hasActiveFilters && <span className="exp-desk-filter-dot" aria-hidden="true" />}
              </button>
            </div>

            {useRmShell && (
              <a href={urls.settings} className="qt-settings-btn" title="Sales settings">
                <i className="fas fa-cog" />
              </a>
            )}

            {createQuotationBtn}
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

      {activeKpiTrace && (
        <QuotationKpiTraceModal
          trace={activeKpiTrace.trace}
          onClose={() => setActiveKpiTrace(null)}
        />
      )}

      <section className="exp-desk-kpi-grid qt-kpi-grid" aria-label="Summary">
        <button {...kpiCardProps('totalQuotations', 'total quotations')}>
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--violet">
            <FileText size={20} aria-hidden="true" />
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">total quotations</div>
            <div className="exp-desk-kpi-value">{fmtBig(quotationStats.total)}</div>
          </div>
        </button>
        <button {...kpiCardProps('totalValue', 'total value')}>
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--indigo">
            <Wallet size={20} aria-hidden="true" />
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">total value</div>
            <div className="exp-desk-kpi-value exp-desk-kpi-value--money">
              {defaultCurrency} {formatCurrency(quotationStats.totalVal)}
            </div>
          </div>
        </button>
        <button {...kpiCardProps('pending', 'pending')}>
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--amber">
            <Clock size={20} aria-hidden="true" />
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">pending</div>
            <div className="exp-desk-kpi-value">{fmtBig(quotationStats.pending)}</div>
          </div>
        </button>
        <button {...kpiCardProps('listedNow', 'listed now')}>
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--teal">
            <CheckCircle2 size={20} aria-hidden="true" />
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">listed now</div>
            <div className="exp-desk-kpi-value">{filteredQuotations.length}</div>
            <div className="exp-desk-kpi-helper">matching current filters</div>
          </div>
        </button>
      </section>

      <section className="exp-desk-results qt-quotations-table">
        <div className="exp-desk-results-head">
          <span className="exp-desk-results-count">
            {filteredQuotations.length} {filteredQuotations.length === 1 ? 'result' : 'results'}
          </span>
        </div>

        {selectedIds.size > 0 && (
          <div className="qt-bulk-bar">
            <span>{selectedIds.size} selected</span>
            {canInvoice && (
              <a
                className="qt-bulk-btn"
                href={buildInvoiceCreateHref(urls, Array.from(selectedIds)[0])}
                onClick={(e) => handleInvoiceLinkClick(
                  e,
                  buildInvoiceCreateHref(urls, Array.from(selectedIds)[0]),
                  { ...invoiceTransformOptions(selectedList[0]), module: init?.module || 'sales' },
                )}
              >
                <i className="fas fa-file-invoice-dollar" style={{ color: '#2563eb', marginRight: 6 }} />
                Invoice
              </a>
            )}
            {canDelete && (
              <button type="button" className="qt-bulk-btn qt-bulk-btn--danger" onClick={handleDelete}>
                <i className="fas fa-trash-alt" style={{ marginRight: 6 }} />
                Delete
              </button>
            )}
          </div>
        )}

        {filteredQuotations.length === 0 ? (
          <div className="exp-desk-empty">
            <FileText className="exp-desk-empty-icon" aria-hidden="true" />
            <p className="exp-desk-empty-title">No quotations found</p>
            <p className="exp-desk-empty-sub">Try adjusting your search or filters.</p>
          </div>
        ) : (
          <div className="exp-desk-table-wrap">
            <table className="exp-desk-table">
              <thead>
                <tr>
                  <th className="qt-col-check">
                    <input
                      type="checkbox"
                      className="qt-checkbox"
                      onChange={handleSelectAll}
                      checked={filteredQuotations.length > 0 && filteredQuotations.every((q) => selectedIds.has(q.id))}
                    />
                  </th>
                  <th>Number</th>
                  <th>Customer</th>
                  <th>Salesperson</th>
                  <th>Date</th>
                  <th>Status</th>
                  <th className="exp-desk-amt">Total</th>
                  <th className="qt-col-actions">Actions</th>
                </tr>
              </thead>
              <tbody>
                {filteredQuotations.map((q) => (
                  <tr
                    key={q.id}
                    className="exp-desk-row-clickable"
                    tabIndex={0}
                    onClick={() => goView(q.id)}
                    onKeyDown={(event) => {
                      if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        goView(q.id);
                      }
                    }}
                  >
                    <td className="qt-col-check" onClick={(e) => e.stopPropagation()}>
                      <input
                        type="checkbox"
                        className="qt-checkbox"
                        checked={selectedIds.has(q.id)}
                        onChange={(e) => toggleSelection(q.id, e)}
                      />
                    </td>
                    <td>
                      <span className="exp-desk-ref">{q.order_number}</span>
                      {supportsOrderTypeSplit && q.order_type && (
                        <div className="exp-desk-cell-sub"><TypeBadge type={q.order_type} /></div>
                      )}
                    </td>
                    <td>
                      <span
                        className="exp-desk-cell-main"
                        title={q.company_name || ''}
                        style={{ textTransform: isRoadmaster ? 'uppercase' : 'none' }}
                      >
                        {q.company_name || '-'}
                      </span>
                    </td>
                    <td>
                      <div className="qt-salesperson-cell">
                        <span className="qt-avatar" style={avatarStyle(q.salesperson)}>{initials(q.salesperson)}</span>
                        <span className="qt-salesperson-name" title={q.salesperson || ''}>{q.salesperson || '-'}</span>
                      </div>
                    </td>
                    <td><span className="exp-desk-subdate">{formatDateTime(q.created_at)}</span></td>
                    <td><StatusPill status={q.status} /></td>
                    <td className="exp-desk-amt">{formatCurrency(q.total_amount)}</td>
                    <td className="qt-col-actions" onClick={(e) => e.stopPropagation()} data-qt-actions="1">
                      <div className="qt-actions-menu">
                        <button
                          type="button"
                          className="qt-actions-btn"
                          aria-label="Actions"
                          onClick={(e) => {
                            e.stopPropagation();
                            setOpenMenuId((id) => (id === q.id ? null : q.id));
                          }}
                        >
                          <i className="fas fa-ellipsis-vertical" />
                        </button>
                        {openMenuId === q.id && (
                          <div className="qt-actions-dropdown">
                            <a href={buildUrl(urls.view, q.id)}><i className="fas fa-eye" style={{ width: 20, color: '#94a3b8' }} /> View</a>
                            <a href={buildUrl(urls.print, q.id)} target="_blank" rel="noopener noreferrer"><i className="fas fa-print" style={{ width: 20, color: '#94a3b8' }} /> Print</a>
                            {orderCanDirectInvoice(q.status) && !rowHasInvoice(q) ? (
                              <a
                                href={buildInvoiceCreateHref(urls, q.id)}
                                onClick={(e) => handleInvoiceLinkClick(
                                  e,
                                  buildInvoiceCreateHref(urls, q.id),
                                  { ...invoiceTransformOptions(q), module: init?.module || 'sales' },
                                )}
                              >
                                <i className="fas fa-file-invoice-dollar" style={{ width: 20, color: '#2563eb' }} />
                                {' '}
                                Invoice
                              </a>
                            ) : null}
                          </div>
                        )}
                      </div>
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
