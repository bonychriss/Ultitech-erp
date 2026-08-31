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
import { fetchSalesOrdersInit } from '../api/salesOrdersDesk';
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

export default function SalesOrdersListPage() {
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [myOrdersOnly, setMyOrdersOnly] = useState(false);
  const [draftStatusFilter, setDraftStatusFilter] = useState('');
  const [draftMyOrdersOnly, setDraftMyOrdersOnly] = useState(false);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);
  const [selectedIds, setSelectedIds] = useState(() => new Set());
  const [openMenuId, setOpenMenuId] = useState(null);
  const [filterPanelStyle, setFilterPanelStyle] = useState(null);

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
      const data = await fetchSalesOrdersInit();
      setInit(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load sales orders.');
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

  const orders = useMemo(
    () => applyInvoicedStatusToRows(init?.orders || []),
    [init?.orders],
  );
  const urls = init?.urls || {};
  const currentUserId = init?.current_user_id || 0;
  const isRoadmaster = !!init?.is_roadmaster;
  const supportsOrderTypeSplit = !!init?.supports_order_type_split;
  const useRmShell = !!init?.use_rm_shell_layout;
  const defaultCurrency = init?.default_currency || 'TZS';

  const filteredOrders = useMemo(() => orders.filter((o) => {
    const mineOk = !myOrdersOnly || Number(o.created_by) === Number(currentUserId);
    const qSearch = search.toLowerCase();
    const matchesSearch = !qSearch
      || (o.order_number || '').toLowerCase().includes(qSearch)
      || (o.company_name || '').toLowerCase().includes(qSearch)
      || (o.salesperson || '').toLowerCase().includes(qSearch);
    const matchesStatus = !statusFilter || (o.status || '').toLowerCase() === statusFilter;
    return mineOk && matchesSearch && matchesStatus;
  }), [orders, search, statusFilter, myOrdersOnly, currentUserId]);

  const orderStats = useMemo(() => {
    const terminal = new Set(['paid', 'completed', 'delivered', 'cancelled', 'canceled']);
    let totalVal = 0;
    let pipeline = 0;
    orders.forEach((o) => {
      totalVal += parseFloat(o.total_amount) || 0;
      const st = (o.status || '').toLowerCase().trim();
      if (!terminal.has(st)) pipeline += 1;
    });
    return { total: orders.length, totalVal, pipeline };
  }, [orders]);

  const selectedList = useMemo(
    () => filteredOrders.filter((o) => selectedIds.has(o.id)),
    [filteredOrders, selectedIds],
  );
  const canInvoice = selectedList.length === 1
    && orderCanDirectInvoice(selectedList[0]?.status)
    && !rowHasInvoice(selectedList[0]);
  const hasActiveFilters = !!statusFilter || myOrdersOnly;
  const hasSearchValue = search.trim() !== '';

  const activeFilterChips = useMemo(() => {
    const chips = [];
    if (statusFilter) {
      const label = STATUS_OPTIONS.find((o) => o.value === statusFilter)?.label || statusFilter;
      chips.push({ key: 'status', label: `Status: ${label}` });
    }
    if (myOrdersOnly) chips.push({ key: 'mine', label: 'My sales orders' });
    return chips;
  }, [statusFilter, myOrdersOnly]);

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
      setSelectedIds(new Set(filteredOrders.map((o) => o.id)));
    } else {
      setSelectedIds(new Set());
    }
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

  function toggleFilters() {
    setFiltersOpen((open) => {
      const next = !open;
      if (next) {
        setDraftStatusFilter(statusFilter);
        setDraftMyOrdersOnly(myOrdersOnly);
      }
      return next;
    });
  }

  function applyFilters() {
    setStatusFilter(draftStatusFilter);
    setMyOrdersOnly(draftMyOrdersOnly);
    setFiltersOpen(false);
  }

  function resetFilters() {
    setStatusFilter('');
    setMyOrdersOnly(false);
    setDraftStatusFilter('');
    setDraftMyOrdersOnly(false);
    setFiltersOpen(false);
  }

  function clearAdvancedFilter(key) {
    if (key === 'status') setStatusFilter('');
    if (key === 'mine') setMyOrdersOnly(false);
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

  function renderSearchField(inputRef, id = 'so-desk-search') {
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
          aria-label="Search sales orders"
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
              <label htmlFor="soFilterStatus">Status</label>
              <select
                id="soFilterStatus"
                value={draftStatusFilter}
                onChange={(e) => setDraftStatusFilter(e.target.value)}
              >
                {STATUS_OPTIONS.map((opt) => (
                  <option key={opt.value || 'all'} value={opt.value}>{opt.label}</option>
                ))}
              </select>
            </div>
            <div className="exp-desk-field exp-desk-field--full">
              <label htmlFor="soFilterScope">Scope</label>
              <select
                id="soFilterScope"
                value={draftMyOrdersOnly ? 'mine' : 'all'}
                onChange={(e) => setDraftMyOrdersOnly(e.target.value === 'mine')}
              >
                <option value="all">All sales orders</option>
                <option value="mine">My sales orders</option>
              </select>
            </div>
          </div>
        </div>

        <div className="exp-desk-filters-footer">
          <button type="button" className="exp-desk-btn exp-desk-btn-ghost" onClick={() => {
            setDraftStatusFilter('');
            setDraftMyOrdersOnly(false);
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
        <span>Loading sales orders...</span>
      </div>
    );
  }

  if (error || !init) {
    return (
      <div className="exp-desk-page">
        <div className="exp-desk-flash exp-desk-flash-error" role="alert">{error || 'Could not load sales orders.'}</div>
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
          {renderSearchField(searchInputRef, 'so-desk-search-desktop')}
        </div>

        <div className="exp-desk-page-header-actions">
          <div className="exp-desk-toolbar-secondary">
            <div className={`exp-desk-search-expand${searchOpen ? ' is-open' : ''}`} ref={searchExpandRef}>
              <button
                type="button"
                className={`exp-desk-search-toggle${searchOpen ? ' is-active' : ''}${hasSearchValue ? ' has-value' : ''}`}
                onClick={toggleMobileSearch}
                aria-expanded={searchOpen}
                aria-controls="so-desk-search-mobile-panel"
                title="Search sales orders"
              >
                <Search size={18} aria-hidden="true" />
              </button>
              <div id="so-desk-search-mobile-panel" className={`exp-desk-search-panel${searchOpen ? ' is-open' : ''}`}>
                {renderSearchField(mobileSearchInputRef, 'so-desk-search-mobile')}
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

      <section className="exp-desk-kpi-grid qt-kpi-grid" aria-label="Summary">
        <div className="exp-desk-kpi exp-desk-kpi-card">
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--violet">
            <FileText size={20} aria-hidden="true" />
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">total orders</div>
            <div className="exp-desk-kpi-value">{fmtBig(orderStats.total)}</div>
          </div>
        </div>
        <div className="exp-desk-kpi exp-desk-kpi-card">
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--indigo">
            <Wallet size={20} aria-hidden="true" />
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">total value</div>
            <div className="exp-desk-kpi-value exp-desk-kpi-value--money">
              {defaultCurrency} {formatCurrency(orderStats.totalVal)}
            </div>
          </div>
        </div>
        <div className="exp-desk-kpi exp-desk-kpi-card">
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--amber">
            <Clock size={20} aria-hidden="true" />
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">pipeline</div>
            <div className="exp-desk-kpi-value">{fmtBig(orderStats.pipeline)}</div>
          </div>
        </div>
        <div className="exp-desk-kpi exp-desk-kpi-card">
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--teal">
            <CheckCircle2 size={20} aria-hidden="true" />
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">listed now</div>
            <div className="exp-desk-kpi-value">{filteredOrders.length}</div>
            <div className="exp-desk-kpi-helper">matching current filters</div>
          </div>
        </div>
      </section>

      <section className="exp-desk-results qt-quotations-table">
        <div className="exp-desk-results-head">
          <span className="exp-desk-results-count">
            {filteredOrders.length} {filteredOrders.length === 1 ? 'result' : 'results'}
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
          </div>
        )}

        {filteredOrders.length === 0 ? (
          <div className="exp-desk-empty">
            <FileText className="exp-desk-empty-icon" aria-hidden="true" />
            <p className="exp-desk-empty-title">No sales orders found</p>
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
                      checked={filteredOrders.length > 0 && filteredOrders.every((o) => selectedIds.has(o.id))}
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
                {filteredOrders.map((o) => (
                  <tr
                    key={o.id}
                    className="exp-desk-row-clickable"
                    tabIndex={0}
                    onClick={() => goView(o.id)}
                    onKeyDown={(event) => {
                      if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        goView(o.id);
                      }
                    }}
                  >
                    <td className="qt-col-check" onClick={(e) => e.stopPropagation()}>
                      <input
                        type="checkbox"
                        className="qt-checkbox"
                        checked={selectedIds.has(o.id)}
                        onChange={(e) => toggleSelection(o.id, e)}
                      />
                    </td>
                    <td>
                      <span className="exp-desk-ref">{o.order_number}</span>
                      {supportsOrderTypeSplit && o.order_type && (
                        <div className="exp-desk-cell-sub"><TypeBadge type={o.order_type} /></div>
                      )}
                    </td>
                    <td>
                      <span
                        className="exp-desk-cell-main"
                        title={o.company_name || ''}
                        style={{ textTransform: isRoadmaster ? 'uppercase' : 'none' }}
                      >
                        {o.company_name || '-'}
                      </span>
                    </td>
                    <td>
                      <div className="qt-salesperson-cell">
                        <span className="qt-avatar" style={avatarStyle(o.salesperson)}>{initials(o.salesperson)}</span>
                        <span className="qt-salesperson-name" title={o.salesperson || ''}>{o.salesperson || '-'}</span>
                      </div>
                    </td>
                    <td><span className="exp-desk-subdate">{formatDateTime(o.created_at)}</span></td>
                    <td><StatusPill status={o.status} /></td>
                    <td className="exp-desk-amt">{formatCurrency(o.total_amount)}</td>
                    <td className="qt-col-actions" onClick={(e) => e.stopPropagation()} data-qt-actions="1">
                      <div className="qt-actions-menu">
                        <button
                          type="button"
                          className="qt-actions-btn"
                          aria-label="Actions"
                          onClick={(e) => {
                            e.stopPropagation();
                            setOpenMenuId((id) => (id === o.id ? null : o.id));
                          }}
                        >
                          <i className="fas fa-ellipsis-vertical" />
                        </button>
                        {openMenuId === o.id && (
                          <div className="qt-actions-dropdown">
                            <a href={buildUrl(urls.view, o.id)}><i className="fas fa-eye" style={{ width: 20, color: '#94a3b8' }} /> View</a>
                            <a href={buildUrl(urls.print, o.id)} target="_blank" rel="noopener noreferrer"><i className="fas fa-print" style={{ width: 20, color: '#94a3b8' }} /> Print</a>
                            {orderCanDirectInvoice(o.status) && !rowHasInvoice(o) ? (
                              <a
                                href={buildInvoiceCreateHref(urls, o.id)}
                                onClick={(e) => handleInvoiceLinkClick(
                                  e,
                                  buildInvoiceCreateHref(urls, o.id),
                                  { ...invoiceTransformOptions(o), module: init?.module || 'sales' },
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
