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
  EllipsisVertical,
  Download,
  Eye,
  FileText,
  Loader2,
  Receipt,
  Search,
  SlidersHorizontal,
  Trash2,
  Wallet,
  X,
} from 'lucide-react';
import { confirmDeleteQuotations, fetchQuotationsInit, quotationStatusIsDeletable, submitDeleteForm } from '../api/quotationsDesk';
import OrderDownloadModal from '../components/OrderDownloadModal.jsx';
import QuotationKpiTraceModal from '../components/QuotationKpiTraceModal';
import { resolveQuotationKpiTrace } from '../utils/quotationKpiTrace';
import {
  handleInvoiceLinkClick,
  resolveInvoiceSourceLabel,
  applyInvoicedStatusToRows,
} from '../utils/invoiceTransform';
import { downloadSalesDocPdfViaPrintUrl } from '../utils/listPdfDownload';

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

function buildDownloadUrl(urls, id) {
  const raw = String(urls?.print || '').trim();
  if (!raw || !id) return '#';
  const [pathPart, queryPart = ''] = raw.split('?');
  let path = pathPart.replace(/\/$/, '');
  // Short desk URLs need /sales/print/order/{id} (rewrite does not accept ?id= alone).
  if (/\/print\/(order|invoice)$/i.test(path)) {
    path = `${path}/${encodeURIComponent(String(id))}`;
  } else if (!new RegExp(`/${id}(?:/|$)`).test(path)) {
    const params = new URLSearchParams(queryPart);
    params.set('id', String(id));
    params.set('download', 'pdf');
    return `${path}?${params.toString()}`;
  }
  const params = new URLSearchParams(queryPart);
  params.set('download', 'pdf');
  return `${path}?${params.toString()}`;
}

const LIST_STATE_KEY = 'qt-list-state';

function readListState() {
  const params = new URLSearchParams(window.location.search);
  const fromUrl = {
    search: params.get('q') || '',
    status: params.get('status') || '',
    mine: params.get('mine') === '1',
    opened: Number(params.get('sel') || 0) || 0,
  };
  if (fromUrl.search || fromUrl.status || fromUrl.mine || fromUrl.opened) return fromUrl;
  try {
    const saved = JSON.parse(window.sessionStorage.getItem(LIST_STATE_KEY) || 'null');
    window.sessionStorage.removeItem(LIST_STATE_KEY);
    if (saved && typeof saved === 'object') {
      return {
        search: String(saved.search || ''),
        status: String(saved.status || ''),
        mine: Boolean(saved.mine),
        opened: Number(saved.opened || 0) || 0,
      };
    }
  } catch {
    /* storage can be blocked */
  }
  return fromUrl;
}

function writeListStateToUrl(state) {
  const url = new URL(window.location.href);
  const setOrDel = (key, value) => {
    const v = String(value || '').trim();
    if (v) url.searchParams.set(key, v);
    else url.searchParams.delete(key);
  };
  setOrDel('q', state.search);
  setOrDel('status', state.status);
  setOrDel('mine', state.mine ? '1' : '');
  setOrDel('sel', state.opened ? String(state.opened) : '');
  const next = url.pathname + url.search + url.hash;
  const cur = window.location.pathname + window.location.search + window.location.hash;
  if (next !== cur) window.history.replaceState(window.history.state, '', next);
}

function saveListStateForReturn(state) {
  try {
    window.sessionStorage.setItem(LIST_STATE_KEY, JSON.stringify(state));
  } catch {
    /* storage can be blocked */
  }
}

function TypeBadge({ type }) {
  const t = (type || 'spare').toLowerCase();
  if (t === 'truck') {
    return <span className="prod-desk-type prod-desk-type--truck">Truck</span>;
  }
  return <span className="prod-desk-type prod-desk-type--spare">Spare Part</span>;
}

function StatusPill({ status }) {
  return <span className={`qt-status ${statusClass(status)}`}>{statusLabel(status)}</span>;
}

export default function QuotationsListPage() {
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [restored] = useState(() => readListState());
  const [search, setSearch] = useState(restored.search);
  const [statusFilter, setStatusFilter] = useState(restored.status);
  const [myQuotationsOnly, setMyQuotationsOnly] = useState(restored.mine);
  const [openedId, setOpenedId] = useState(restored.opened);
  const [draftStatusFilter, setDraftStatusFilter] = useState('');
  const [draftMyQuotationsOnly, setDraftMyQuotationsOnly] = useState(false);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);
  const [selectedIds, setSelectedIds] = useState(() => new Set());
  const selectionAnchorRef = useRef(null);
  const [openMenuId, setOpenMenuId] = useState(null);
  const [filterPanelStyle, setFilterPanelStyle] = useState(null);
  const [pdfState, setPdfState] = useState('idle');
  const [pdfProgress, setPdfProgress] = useState(0);
  const [pdfMessage, setPdfMessage] = useState('Preparing document...');
  const [pdfFileName, setPdfFileName] = useState('');
  const pdfCloseTimer = useRef(null);
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
    writeListStateToUrl({
      search,
      status: statusFilter,
      mine: myQuotationsOnly,
      opened: openedId,
    });
  }, [search, statusFilter, myQuotationsOnly, openedId]);

  useEffect(() => {
    if (!init || !openedId) return undefined;
    const timer = window.setTimeout(() => {
      const row = document.querySelector(`[data-quotation-id="${openedId}"]`);
      if (row && typeof row.scrollIntoView === 'function') {
        row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
    }, 80);
    return () => window.clearTimeout(timer);
  }, [init, openedId]);

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

  useEffect(() => {
    const visible = new Set(filteredQuotations.map((q) => q.id));
    setSelectedIds((prev) => {
      let changed = false;
      const next = new Set();
      prev.forEach((id) => {
        if (visible.has(id)) next.add(id);
        else changed = true;
      });
      return changed ? next : prev;
    });
  }, [filteredQuotations]);

  /** Summary cards always match the rows currently shown in the table (like invoices). */
  const quotationStats = useMemo(() => {
    let totalVal = 0;
    let pending = 0;
    let converted = 0;
    filteredQuotations.forEach((q) => {
      totalVal += parseFloat(q.total_amount) || 0;
      const st = (q.status || '').toLowerCase().trim();
      if (['quotation', 'draft', 'sent'].includes(st)) pending += 1;
      if (['confirmed', 'invoiced', 'processing', 'shipped', 'delivered', 'paid', 'completed'].includes(st)) {
        converted += 1;
      }
    });
    return {
      total: filteredQuotations.length,
      totalVal,
      pending,
      converted,
    };
  }, [filteredQuotations]);

  const kpiTraceContext = useMemo(() => ({
    filteredQuotations,
    filters: {
      search,
      statusFilter,
      myQuotationsOnly,
    },
    statusOptions: STATUS_OPTIONS,
    defaultCurrency,
  }), [filteredQuotations, search, statusFilter, myQuotationsOnly, defaultCurrency]);

  const selectedList = useMemo(
    () => filteredQuotations.filter((q) => selectedIds.has(q.id)),
    [filteredQuotations, selectedIds],
  );
  const canDelete = selectedList.length > 0 && selectedList.every((q) => quotationStatusIsDeletable(q.status));
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

  function toggleSelection(id) {
    selectionAnchorRef.current = id;
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function toggleSelectAll() {
    setSelectedIds((prev) => {
      if (filteredQuotations.length > 0 && prev.size === filteredQuotations.length) {
        return new Set();
      }
      return new Set(filteredQuotations.map((q) => q.id));
    });
  }

  function selectRange(id) {
    const ids = filteredQuotations.map((q) => q.id);
    const end = ids.indexOf(id);
    const anchor = selectionAnchorRef.current;
    const start = anchor == null ? end : ids.indexOf(anchor);
    if (start < 0 || end < 0) {
      toggleSelection(id);
      return;
    }
    const from = Math.min(start, end);
    const to = Math.max(start, end);
    selectionAnchorRef.current = id;
    setSelectedIds((prev) => {
      const next = new Set(prev);
      for (let i = from; i <= to; i += 1) next.add(ids[i]);
      return next;
    });
  }

  function handleRowClick(event, quotation) {
    if (event.ctrlKey || event.metaKey) {
      event.preventDefault();
      toggleSelection(quotation.id);
      return;
    }
    if (event.shiftKey) {
      event.preventDefault();
      selectRange(quotation.id);
      return;
    }
    goView(quotation.id);
  }

  async function handleDeleteIds(ids) {
    const clean = ids.map((id) => Number(id)).filter((id) => id > 0);
    if (!clean.length) return;
    if (!(await confirmDeleteQuotations())) return;
    submitDeleteForm(urls.delete_post || 'create.php', clean);
  }

  async function handleDelete() {
    await handleDeleteIds(Array.from(selectedIds));
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

  function markOpened(id) {
    const nextId = Number(id || 0);
    if (!nextId) return;
    const state = {
      search,
      status: statusFilter,
      mine: myQuotationsOnly,
      opened: nextId,
    };
    setOpenedId(nextId);
    writeListStateToUrl(state);
    saveListStateForReturn(state);
  }

  function goView(id) {
    markOpened(id);
    window.location.href = buildUrl(urls.view || 'view.php', id);
  }

  function closeDownloadModal(force = false) {
    if (!force && pdfState === 'loading') return;
    if (pdfCloseTimer.current) {
      window.clearTimeout(pdfCloseTimer.current);
      pdfCloseTimer.current = null;
    }
    setPdfState('idle');
    setPdfProgress(0);
    setPdfMessage('Preparing document...');
    setPdfFileName('');
  }

  async function handleDownloadClick(event, quotation) {
    event.preventDefault();
    event.stopPropagation();
    if (pdfState === 'loading') return;

    const orderId = quotation?.id;
    const href = buildDownloadUrl(urls, orderId);
    if (!href || href === '#') return;

    setOpenMenuId(null);
    markOpened(orderId);

    const displayNumber = quotation?.order_number || String(orderId);
    const fileName = `Order_${displayNumber}.pdf`;
    setPdfFileName(fileName);
    setPdfState('loading');
    setPdfProgress(4);
    setPdfMessage('Preparing document...');

    try {
      await downloadSalesDocPdfViaPrintUrl(href, {
        onProgress: (percent, message) => {
          setPdfProgress(percent);
          if (message) setPdfMessage(message);
        },
      });
      setPdfState('success');
      setPdfProgress(100);
      setPdfMessage('Your PDF has been saved to your downloads folder.');
      if (pdfCloseTimer.current) window.clearTimeout(pdfCloseTimer.current);
      pdfCloseTimer.current = window.setTimeout(() => closeDownloadModal(true), 1800);
    } catch (err) {
      const reason = err?.message || 'Download failed.';
      setPdfState('error');
      setPdfMessage(reason);
    }
  }

  function openKpiTrace(key) {
    const trace = resolveQuotationKpiTrace(key, kpiTraceContext);
    if (trace) {
      setActiveKpiTrace({ key, trace });
    }
  }

  function kpiCardProps(key, label, tone) {
    return {
      type: 'button',
      className: `so-kpi so-kpi--${tone} so-kpi--clickable`,
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

  const pdfModalOpen = pdfState !== 'idle';

  return (
    <div className="exp-desk-page">
      <OrderDownloadModal
        open={pdfModalOpen}
        state={pdfState}
        progress={pdfProgress}
        message={pdfMessage}
        fileName={pdfFileName}
        onClose={closeDownloadModal}
      />
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

      <section className="exp-desk-kpi-grid so-kpi-grid so-kpi-grid--slim" aria-label="Summary">
        <button {...kpiCardProps('totalQuotations', 'total quotations', 'orders')} title="Quotations matching current filters. Click to see breakdown">
          <div className="so-kpi-top">
            <span className="so-kpi-label">Total quotations</span>
            <span className="so-kpi-icon"><FileText size={16} aria-hidden="true" /></span>
          </div>
          <strong className="so-kpi-value">{fmtBig(quotationStats.total)}</strong>
        </button>
        <button {...kpiCardProps('totalValue', 'total value', 'value')} title="Sum of listed quotation totals. Click to see breakdown">
          <div className="so-kpi-top">
            <span className="so-kpi-label">Total value</span>
            <span className="so-kpi-icon"><Wallet size={16} aria-hidden="true" /></span>
          </div>
          <strong className="so-kpi-value so-kpi-value--money">
            {defaultCurrency} {formatCurrency(quotationStats.totalVal)}
          </strong>
        </button>
        <button {...kpiCardProps('pending', 'pending', 'pipeline')} title="Listed quotations still in Quotation, Draft, or Sent. Click to see breakdown">
          <div className="so-kpi-top">
            <span className="so-kpi-label">Pending</span>
            <span className="so-kpi-icon"><Clock size={16} aria-hidden="true" /></span>
          </div>
          <strong className="so-kpi-value">{fmtBig(quotationStats.pending)}</strong>
        </button>
        <button {...kpiCardProps('converted', 'converted', 'listed')} title="Listed quotations that moved past pending. Click to see breakdown">
          <div className="so-kpi-top">
            <span className="so-kpi-label">Converted</span>
            <span className="so-kpi-icon"><CheckCircle2 size={16} aria-hidden="true" /></span>
          </div>
          <strong className="so-kpi-value">{fmtBig(quotationStats.converted)}</strong>
        </button>
      </section>

      <section className="exp-desk-results qt-quotations-table">
        <div className="prod-desk-results-head">
          <span className="prod-desk-results-count">
            {filteredQuotations.length} {filteredQuotations.length === 1 ? 'result' : 'results'}
          </span>
        </div>

        {filteredQuotations.length === 0 ? (
          <div className="prod-desk-empty">
            <FileText size={28} style={{ color: '#94a3b8' }} aria-hidden="true" />
            <p className="prod-desk-empty-title">No quotations found</p>
            <p className="prod-desk-empty-sub">Try adjusting your search or filters.</p>
          </div>
        ) : (
          <div className="prod-desk-table-wrap">
            <table className="prod-desk-table">
              <thead>
                <tr>
                  <th style={{ width: 40 }}>
                    <input
                      type="checkbox"
                      checked={filteredQuotations.length > 0 && selectedIds.size === filteredQuotations.length}
                      onChange={toggleSelectAll}
                      aria-label="Select all"
                    />
                  </th>
                  <th>Quotation</th>
                  {supportsOrderTypeSplit ? <th>Type</th> : null}
                  <th>Customer</th>
                  <th>Salesperson</th>
                  <th>Status</th>
                  <th>Total</th>
                  <th style={{ textAlign: 'right' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {filteredQuotations.map((q) => {
                  const isSelected = selectedIds.has(q.id);
                  const canInvoiceRow = orderCanDirectInvoice(q.status) && !rowHasInvoice(q);
                  const canDeleteRow = quotationStatusIsDeletable(q.status) && !rowHasInvoice(q);
                  return (
                    <tr
                      key={q.id}
                      data-quotation-id={q.id}
                      aria-current={openedId === Number(q.id) ? 'true' : undefined}
                      className={[
                        'exp-desk-row-clickable',
                        isSelected ? 'is-selected' : '',
                        openedId === Number(q.id) ? 'is-glow' : '',
                      ].filter(Boolean).join(' ')}
                      tabIndex={0}
                      onClick={(event) => handleRowClick(event, q)}
                      onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                          event.preventDefault();
                          goView(q.id);
                        }
                      }}
                    >
                      <td onClick={(e) => e.stopPropagation()}>
                        <input
                          type="checkbox"
                          checked={isSelected}
                          onChange={() => toggleSelection(q.id)}
                          aria-label={`Select ${q.order_number || q.id}`}
                        />
                      </td>
                      <td>
                        <div className="prod-desk-product">
                          <div style={{ minWidth: 0 }}>
                            <a
                              href={buildUrl(urls.view, q.id)}
                              className="prod-desk-name"
                              onClick={(e) => {
                                e.stopPropagation();
                                markOpened(q.id);
                              }}
                            >
                              {q.order_number || `Quotation #${q.id}`}
                            </a>
                            <div className="prod-desk-code">DATE: {formatDateTime(q.created_at)}</div>
                          </div>
                        </div>
                      </td>
                      {supportsOrderTypeSplit ? (
                        <td>
                          <TypeBadge type={q.order_type} />
                        </td>
                      ) : null}
                      <td>
                        <div
                          style={{
                            fontWeight: 600,
                            textTransform: isRoadmaster ? 'uppercase' : 'none',
                          }}
                          title={q.company_name || ''}
                        >
                          {q.company_name || '-'}
                        </div>
                      </td>
                      <td>
                        <div className="qt-salesperson-cell">
                          <span className="qt-avatar qt-avatar--sm" style={avatarStyle(q.salesperson)}>
                            {initials(q.salesperson)}
                          </span>
                          <span className="qt-salesperson-name" title={q.salesperson || ''}>
                            {q.salesperson || '-'}
                          </span>
                        </div>
                      </td>
                      <td>
                        <StatusPill status={q.status} />
                      </td>
                      <td>
                        <div className="prod-desk-muted">Amount</div>
                        <div className="prod-desk-price">
                          {formatCurrency(q.total_amount)}{' '}
                          <span style={{ fontSize: '0.7rem', fontWeight: 600 }}>
                            {q.currency || defaultCurrency}
                          </span>
                        </div>
                      </td>
                      <td className="qt-col-actions" onClick={(e) => e.stopPropagation()} data-qt-actions="1">
                        <div className="qt-actions-menu">
                          <button
                            type="button"
                            className="qt-actions-btn"
                            aria-label="Actions"
                            aria-expanded={openMenuId === q.id}
                            onClick={(e) => {
                              e.stopPropagation();
                              setOpenMenuId((id) => (id === q.id ? null : q.id));
                            }}
                          >
                            <EllipsisVertical size={18} aria-hidden="true" />
                          </button>
                          {openMenuId === q.id ? (
                            <div className="qt-actions-dropdown">
                              <a href={buildUrl(urls.view, q.id)} onClick={() => { setOpenMenuId(null); markOpened(q.id); }}>
                                <Eye size={16} className="qt-action-icon qt-action-icon--view" aria-hidden="true" /> View
                              </a>
                              <a
                                href={buildDownloadUrl(urls, q.id)}
                                onClick={(e) => handleDownloadClick(e, q)}
                              >
                                <Download size={16} className="qt-action-icon qt-action-icon--download" aria-hidden="true" /> Download
                              </a>
                              {canInvoiceRow ? (
                                <a
                                  href={buildInvoiceCreateHref(urls, q.id)}
                                  onClick={(e) => {
                                    setOpenMenuId(null);
                                    handleInvoiceLinkClick(
                                      e,
                                      buildInvoiceCreateHref(urls, q.id),
                                      { ...invoiceTransformOptions(q), module: init?.module || 'sales' },
                                    );
                                  }}
                                >
                                  <Receipt size={16} className="qt-action-icon qt-action-icon--invoice" aria-hidden="true" /> Invoice
                                </a>
                              ) : null}
                              <button
                                type="button"
                                className="qt-actions-delete"
                                onClick={(e) => {
                                  e.stopPropagation();
                                  setOpenMenuId(null);
                                  if (!canDeleteRow) {
                                    const message = 'Only a draft, quotation, or cancelled quotation with no invoice can be deleted.';
                                    if (typeof window.Swal !== 'undefined') {
                                      window.Swal.fire({
                                        icon: 'info',
                                        title: 'This quotation cannot be deleted',
                                        text: message,
                                      });
                                    } else {
                                      window.alert(message);
                                    }
                                    return;
                                  }
                                  handleDeleteIds([q.id]);
                                }}
                              >
                                <Trash2 size={16} aria-hidden="true" /> Delete
                              </button>
                            </div>
                          ) : null}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        {selectedIds.size > 0 && (
          <div className="prod-desk-bulk-bar">
            <span style={{ fontSize: '0.75rem', fontWeight: 600, borderRight: '1px solid #334155', paddingRight: '0.75rem' }}>
              {selectedIds.size} selected
            </span>
            <button type="button" onClick={() => setSelectedIds(new Set())}>
              Deselect
            </button>
            {canInvoice && (
              <a
                href={buildInvoiceCreateHref(urls, Array.from(selectedIds)[0])}
                onClick={(e) => handleInvoiceLinkClick(
                  e,
                  buildInvoiceCreateHref(urls, Array.from(selectedIds)[0]),
                  { ...invoiceTransformOptions(selectedList[0]), module: init?.module || 'sales' },
                )}
              >
                <Receipt size={14} aria-hidden="true" /> Invoice
              </a>
            )}
            {canDelete && (
              <button type="button" className="prod-desk-bulk-del" onClick={handleDelete}>
                <Trash2 size={14} aria-hidden="true" /> Delete
              </button>
            )}
          </div>
        )}
      </section>
    </div>
  );
}
