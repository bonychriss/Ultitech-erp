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
  AlertCircle,
  Clock,
  FileText,
  Loader2,
  Search,
  SlidersHorizontal,
  Wallet,
  X,
} from 'lucide-react';
import { deleteInvoices, fetchInvoicesInit, postCorrection, resolveInvoiceRevenueEntry } from '../api/invoicesListDesk';
import InvoiceKpiTraceModal from '../components/InvoiceKpiTraceModal';
import InvoiceRegisterPaymentModal from '../components/InvoiceRegisterPaymentModal.jsx';
import { resolveInvoiceKpiTrace } from '../utils/invoiceKpiTrace';

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
  { value: 'draft', label: 'Draft' },
  { value: 'sent', label: 'Sent' },
  { value: 'invoiced', label: 'Invoiced' },
  { value: 'overdue', label: 'Overdue' },
  { value: 'paid', label: 'Paid' },
  { value: 'cancelled', label: 'Cancelled' },
];

function statusClass(status) {
  const st = (status || '').toLowerCase().trim();
  if (st === 'paid') return 'qt-status--green';
  if (['sent', 'invoiced'].includes(st)) return 'qt-status--cyan';
  if (['draft', 'overdue'].includes(st)) return 'qt-status--amber';
  if (['cancelled', 'canceled'].includes(st)) return 'qt-status--red';
  return 'qt-status--gray';
}

function statusLabel(status) {
  const st = (status || '').toLowerCase().trim();
  const labels = {
    draft: 'Draft',
    sent: 'Sent',
    invoiced: 'Sent',
    overdue: 'Overdue',
    paid: 'Paid',
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

function formatDate(dateStr) {
  if (!dateStr) return '-';
  const d = new Date(dateStr.includes('T') ? dateStr : dateStr.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return '-';
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
}

function parseDateOnly(dateStr) {
  if (!dateStr) return null;
  const raw = String(dateStr).trim();
  const iso = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (iso) {
    return new Date(Number(iso[1]), Number(iso[2]) - 1, Number(iso[3]));
  }
  const d = new Date(raw.includes('T') ? raw : raw.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return null;
  return new Date(d.getFullYear(), d.getMonth(), d.getDate());
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

function thisMonthDateRange(now = new Date()) {
  const from = new Date(now.getFullYear(), now.getMonth(), 1);
  return {
    date_from: toInputDate(from),
    date_to: toInputDate(now),
  };
}

function isThisMonthRange(filters, now = new Date()) {
  const month = thisMonthDateRange(now);
  return (
    String(filters?.date_from || '') === month.date_from
    && String(filters?.date_to || '') === month.date_to
  );
}

function invoiceInDateRange(invoice, dateFrom, dateTo) {
  if (!dateFrom && !dateTo) return true;
  const invDate = parseDateOnly(invoice?.invoice_date || invoice?.created_at);
  if (!invDate) return false;
  if (dateFrom) {
    const from = parseDateOnly(dateFrom);
    if (from && invDate.getTime() < from.getTime()) return false;
  }
  if (dateTo) {
    const to = parseDateOnly(dateTo);
    if (to && invDate.getTime() > to.getTime()) return false;
  }
  return true;
}

function isInvoiceDueDateOverdue(invoice) {
  const status = (invoice?.status || '').toLowerCase().trim();
  if (['paid', 'cancelled', 'canceled', 'draft'].includes(status)) return false;
  if ((parseFloat(invoice?.balance_due) || 0) <= 0.005) return false;
  const due = parseDateOnly(invoice?.due_date);
  if (!due) return false;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return due.getTime() <= today.getTime();
}

function canPayInvoice(invoice) {
  const status = (invoice?.status || '').toLowerCase().trim();
  if (['paid', 'cancelled', 'canceled', 'draft'].includes(status)) return false;
  return (parseFloat(invoice?.balance_due) || 0) > 0.005;
}

function formatDueDateRelative(dateStr) {
  const due = parseDateOnly(dateStr);
  if (!due) return { label: '-', title: '' };

  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const diffDays = Math.round((due.getTime() - today.getTime()) / 86400000);

  let label;
  if (diffDays === 0) label = 'Today';
  else if (diffDays > 0) label = `In ${diffDays} day${diffDays === 1 ? '' : 's'}`;
  else {
    const daysAgo = Math.abs(diffDays);
    label = `${daysAgo} day${daysAgo === 1 ? '' : 's'} ago`;
  }

  const title = formatDate(dateStr);
  return { label, title: title !== '-' ? title : '' };
}

function DueDateCell({ invoice }) {
  const dateLabel = formatDate(invoice?.due_date);
  const { label: relativeLabel } = formatDueDateRelative(invoice?.due_date);
  const overdue = isInvoiceDueDateOverdue(invoice);
  const title = relativeLabel && relativeLabel !== '-' ? relativeLabel : undefined;

  return (
    <span
      className={`exp-desk-subdate inv-due-date-label${overdue ? ' inv-due-date--overdue' : ''}`}
      title={title}
    >
      {dateLabel}
    </span>
  );
}

function fmtBig(n) {
  return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(n || 0);
}

function buildUrl(base, id) {
  if (!base) return '#';
  const sep = base.includes('?') ? '&' : '?';
  return `${base}${sep}id=${id}`;
}

const LIST_STATE_KEY = 'inv-list-state';

function readListState() {
  const params = new URLSearchParams(window.location.search);
  const fromUrl = {
    search: params.get('q') || '',
    status: params.get('status') || '',
    mine: params.get('mine') === '1',
    date_from: params.get('date_from') || '',
    date_to: params.get('date_to') || '',
    opened: Number(params.get('sel') || 0) || 0,
  };
  if (
    fromUrl.search
    || fromUrl.status
    || fromUrl.mine
    || fromUrl.date_from
    || fromUrl.date_to
    || fromUrl.opened
  ) {
    return fromUrl;
  }
  try {
    const saved = JSON.parse(window.sessionStorage.getItem(LIST_STATE_KEY) || 'null');
    window.sessionStorage.removeItem(LIST_STATE_KEY);
    if (saved && typeof saved === 'object') {
      return {
        search: String(saved.search || ''),
        status: String(saved.status || ''),
        mine: Boolean(saved.mine),
        date_from: String(saved.date_from || ''),
        date_to: String(saved.date_to || ''),
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
  setOrDel('date_from', state.date_from);
  setOrDel('date_to', state.date_to);
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
    return <span className="qt-type-badge qt-type-badge--truck"><i className="fas fa-truck" /> TRUCK</span>;
  }
  return <span className="qt-type-badge qt-type-badge--spare"><i className="fas fa-wrench" /> SPARE</span>;
}

function StatusPill({ status }) {
  return <span className={`qt-status ${statusClass(status)}`}>{statusLabel(status)}</span>;
}

function applyDeleteResponse(data, setInvoices, setSelectedIds) {
  const removed = new Set((data.deleted || []).map((row) => Number(row.id)));
  if (removed.size > 0) {
    setInvoices((prev) => prev.filter((inv) => !removed.has(Number(inv.id))));
    setSelectedIds((prev) => {
      const next = new Set(prev);
      removed.forEach((id) => next.delete(id));
      return next;
    });
  }
  if (data.ok && !(data.errors || []).length) {
    if (typeof window.Swal !== 'undefined') {
      window.Swal.fire({
        icon: 'success',
        title: data.message || 'Deleted',
        timer: 2200,
        showConfirmButton: false,
      });
    }
    return;
  }
  if ((data.deleted || []).length > 0 && (data.errors || []).length > 0) {
    const detail = (data.errors || []).map((err) => `#${err.id}: ${err.message || 'Failed'}`).join('\n');
    if (typeof window.Swal !== 'undefined') {
      window.Swal.fire({
        icon: 'warning',
        title: data.message || 'Some invoices could not be deleted',
        text: detail,
      });
    }
    return;
  }
  if (typeof window.Swal !== 'undefined') {
    window.Swal.fire({
      icon: 'error',
      title: 'Could not delete',
      text: (data.errors && data.errors[0] && data.errors[0].message) || data.message || 'Unknown error',
    });
  }
}

export default function InvoicesListPage() {
  const [init, setInit] = useState(null);
  const [invoices, setInvoices] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [restored] = useState(() => readListState());
  const [search, setSearch] = useState(restored.search);
  const [statusFilter, setStatusFilter] = useState(restored.status);
  const [myInvoicesOnly, setMyInvoicesOnly] = useState(restored.mine);
  const [dateFrom, setDateFrom] = useState(restored.date_from || '');
  const [dateTo, setDateTo] = useState(restored.date_to || '');
  const [openedId, setOpenedId] = useState(restored.opened);
  const [draftStatusFilter, setDraftStatusFilter] = useState('');
  const [draftMyInvoicesOnly, setDraftMyInvoicesOnly] = useState(false);
  const [draftDateFrom, setDraftDateFrom] = useState('');
  const [draftDateTo, setDraftDateTo] = useState('');
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);
  const [selectedIds, setSelectedIds] = useState(() => new Set());
  const [openMenuId, setOpenMenuId] = useState(null);
  const [filterPanelStyle, setFilterPanelStyle] = useState(null);
  const [activeKpiTrace, setActiveKpiTrace] = useState(null);
  const [payEntryId, setPayEntryId] = useState(null);
  const [payBusyId, setPayBusyId] = useState(null);
  const [notice, setNotice] = useState('');

  const filterDropdownRef = useRef(null);
  const filterBtnRef = useRef(null);
  const filterPanelRef = useRef(null);
  const searchExpandRef = useRef(null);
  const selectionAnchorRef = useRef(null);
  const searchInputRef = useRef(null);
  const mobileSearchInputRef = useRef(null);

  const loadInit = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const data = await fetchInvoicesInit();
      setInit(data);
      setInvoices(Array.isArray(data.invoices) ? data.invoices : []);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load invoices.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { loadInit(); }, [loadInit]);

  useEffect(() => {
    writeListStateToUrl({
      search,
      status: statusFilter,
      mine: myInvoicesOnly,
      date_from: dateFrom,
      date_to: dateTo,
      opened: openedId,
    });
  }, [search, statusFilter, myInvoicesOnly, dateFrom, dateTo, openedId]);

  useEffect(() => {
    if (!init || !openedId) return undefined;
    const timer = window.setTimeout(() => {
      const row = document.querySelector(`[data-invoice-id="${openedId}"]`);
      if (row && typeof row.scrollIntoView === 'function') {
        row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
    }, 80);
    return () => window.clearTimeout(timer);
  }, [init, openedId]);

  useEffect(() => {
    if (openMenuId == null) return undefined;
    const close = (ev) => {
      if (ev.target?.closest?.('[data-inv-actions]')) return;
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

  const urls = init?.urls || {};
  const currentUserId = init?.current_user_id || 0;
  const isRoadmaster = !!init?.is_roadmaster;
  const isAdmin = !!init?.is_admin;
  const canRegisterPayment = !!init?.can_register_payment;
  const supportsTruckInvoices = !!init?.supports_truck_invoices;
  const supportsOrderTypeSplit = !!init?.supports_order_type_split;
  const defaultCurrency = init?.default_currency || 'TZS';

  const filteredInvoices = useMemo(() => invoices.filter((inv) => {
    const mineOk = !myInvoicesOnly || Number(inv.created_by) === Number(currentUserId);
    const qSearch = search.toLowerCase();
    const matchesSearch = !qSearch
      || (inv.invoice_number || '').toLowerCase().includes(qSearch)
      || (inv.order_number || '').toLowerCase().includes(qSearch)
      || (inv.customer_name || '').toLowerCase().includes(qSearch)
      || (inv.salesperson || '').toLowerCase().includes(qSearch);
    const matchesStatus = !statusFilter || (inv.status || '').toLowerCase() === statusFilter;
    const matchesDate = invoiceInDateRange(inv, dateFrom, dateTo);
    return mineOk && matchesSearch && matchesStatus && matchesDate;
  }), [invoices, search, statusFilter, myInvoicesOnly, dateFrom, dateTo, currentUserId]);

  /** Summary cards always match the rows currently shown in the table (like revenue). */
  const invoiceStats = useMemo(() => {
    let totalVal = 0;
    let outstanding = 0;
    let overdue = 0;
    filteredInvoices.forEach((inv) => {
      totalVal += parseFloat(inv.total_amount) || 0;
      const bal = parseFloat(inv.balance_due) || 0;
      if (bal > 0.005) outstanding += 1;
      if (isInvoiceDueDateOverdue(inv)) overdue += 1;
    });
    return {
      total: filteredInvoices.length,
      totalVal,
      outstanding,
      overdue,
    };
  }, [filteredInvoices]);

  const kpiTraceContext = useMemo(() => ({
    invoices,
    filteredInvoices,
    filters: {
      search,
      statusFilter,
      myInvoicesOnly,
      dateFrom,
      dateTo,
    },
    defaultCurrency,
  }), [invoices, filteredInvoices, search, statusFilter, myInvoicesOnly, dateFrom, dateTo, defaultCurrency]);

  const hasActiveFilters = !!statusFilter || myInvoicesOnly || !!dateFrom || !!dateTo;
  const hasSearchValue = search.trim() !== '';

  const activeFilterChips = useMemo(() => {
    const chips = [];
    if (dateFrom || dateTo) {
      const fromLabel = dateFrom ? formatFilterDateLabel(dateFrom) : 'Any';
      const toLabel = dateTo ? formatFilterDateLabel(dateTo) : 'Any';
      chips.push({
        key: 'date_from',
        label: isThisMonthRange({ date_from: dateFrom, date_to: dateTo })
          ? 'Date: This month'
          : `Date: ${fromLabel} – ${toLabel}`,
      });
    }
    if (statusFilter) {
      const label = STATUS_OPTIONS.find((o) => o.value === statusFilter)?.label || statusFilter;
      chips.push({ key: 'status', label: `Status: ${label}` });
    }
    if (myInvoicesOnly) chips.push({ key: 'mine', label: 'My invoices' });
    return chips;
  }, [statusFilter, myInvoicesOnly, dateFrom, dateTo]);

  function toggleSelection(id) {
    selectionAnchorRef.current = id;
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function selectRange(id) {
    const ids = filteredInvoices.map((inv) => inv.id);
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

  function handleRowClick(event, invoice) {
    if (event.ctrlKey || event.metaKey) {
      event.preventDefault();
      toggleSelection(invoice.id);
      return;
    }
    if (event.shiftKey) {
      event.preventDefault();
      selectRange(invoice.id);
      return;
    }
    goView(invoice.id);
  }

  async function handleBulkDelete() {
    if (!isAdmin || selectedIds.size === 0 || !urls.delete) return;
    const ids = Array.from(selectedIds);
    if (typeof window.Swal !== 'undefined') {
      const result = await window.Swal.fire({
        title: ids.length === 1 ? 'Delete invoice?' : `Delete ${ids.length} invoices?`,
        text: 'Only unpaid test or mistaken invoices can be deleted. This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#94a3b8',
      });
      if (!result.isConfirmed) return;
    } else if (!window.confirm(ids.length === 1 ? 'Delete invoice?' : `Delete ${ids.length} invoices?`)) {
      return;
    }

    try {
      const data = await deleteInvoices(ids, urls.delete);
      applyDeleteResponse(data, setInvoices, setSelectedIds);
    } catch (err) {
      if (typeof window.Swal !== 'undefined') {
        window.Swal.fire({ icon: 'error', title: 'Delete failed', text: err.message || 'Network error' });
      } else {
        window.alert(err.message || 'Delete failed');
      }
    }
  }

  async function handleReportInvoice(inv, e) {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    setOpenMenuId(null);
    const correctionsUrl = urls.corrections;
    if (!correctionsUrl) return;
    const status = String(inv.status || '').toLowerCase();
    if (['cancelled', 'canceled'].includes(status) || inv.correction_status === 'pending') return;

    let reason = '';
    if (typeof window.Swal !== 'undefined') {
      const result = await window.Swal.fire({
        title: 'Report wrong invoice',
        text: `${inv.invoice_number || 'This invoice'} stays on record until an admin reverses it.`,
        input: 'textarea',
        inputPlaceholder: 'What is wrong with this invoice?',
        inputAttributes: { maxlength: 500 },
        showCancelButton: true,
        confirmButtonText: 'Report',
        confirmButtonColor: '#b45309',
        cancelButtonColor: '#94a3b8',
        inputValidator: (value) => (!value || !String(value).trim() ? 'Say what is wrong.' : undefined),
      });
      if (!result.isConfirmed) return;
      reason = String(result.value || '');
    } else {
      reason = window.prompt('What is wrong with this invoice?') || '';
      if (!reason.trim()) return;
    }

    try {
      const data = await postCorrection(correctionsUrl, { action: 'report', invoice_id: inv.id, reason });
      setInvoices((rows) => rows.map((row) => (row.id === inv.id ? { ...row, correction_status: 'pending' } : row)));
      setNotice(data.message || 'Reported. An admin will approve the reversal.');
    } catch (err) {
      if (typeof window.Swal !== 'undefined') {
        window.Swal.fire({ icon: 'error', title: 'Could not report', text: err.message || 'Network error' });
      } else {
        window.alert(err.message || 'Could not report');
      }
    }
  }

  async function handleDeleteInvoice(inv, e) {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    setOpenMenuId(null);
    if (!isAdmin || !urls.delete) return;

    if (typeof window.Swal !== 'undefined') {
      const result = await window.Swal.fire({
        title: 'Delete invoice?',
        html: `Permanently remove <strong>${inv.invoice_number || `#${inv.id}`}</strong>?<br><span style="font-size:13px;color:#64748b">Only unpaid test or mistaken invoices can be deleted.</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#94a3b8',
      });
      if (!result.isConfirmed) return;
    } else if (!window.confirm(`Delete invoice ${inv.invoice_number || inv.id}?`)) {
      return;
    }

    try {
      const data = await deleteInvoices([inv.id], urls.delete);
      applyDeleteResponse(data, setInvoices, setSelectedIds);
    } catch (err) {
      if (typeof window.Swal !== 'undefined') {
        window.Swal.fire({ icon: 'error', title: 'Delete failed', text: err.message || 'Network error' });
      } else {
        window.alert(err.message || 'Delete failed');
      }
    }
  }

  function invoiceCreateUrl(orderType) {
    const params = new URLSearchParams(window.location.search || '');
    params.set('type', orderType);
    return `${urls.create || 'create.php'}?${params.toString()}`;
  }

  function handleNewClick() {
    if (!supportsTruckInvoices) {
      window.location.href = urls.create || 'create.php';
      return;
    }
    if (typeof window.Swal === 'undefined') {
      window.location.href = invoiceCreateUrl('spare');
      return;
    }
    window.Swal.fire({
      title: 'Create New Invoice',
      text: 'What kind of invoice would you like to create?',
      icon: 'question',
      showDenyButton: true,
      showCancelButton: true,
      confirmButtonText: '<i class="fas fa-truck me-2"></i> Truck Invoice',
      denyButtonText: '<i class="fas fa-cogs me-2"></i> Spare Invoice',
      confirmButtonColor: '#0D2A4A',
      denyButtonColor: '#6d28d9',
      cancelButtonColor: '#94a3b8',
    }).then((result) => {
      if (result.isConfirmed) window.location.href = urls.create_truck || invoiceCreateUrl('truck');
      else if (result.isDenied) window.location.href = urls.create_spare || invoiceCreateUrl('spare');
    });
  }

  function markOpened(id) {
    const nextId = Number(id || 0);
    if (!nextId) return;
    const state = {
      search,
      status: statusFilter,
      mine: myInvoicesOnly,
      date_from: dateFrom,
      date_to: dateTo,
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

  async function handlePayInvoice(inv, event) {
    event?.stopPropagation?.();
    setOpenMenuId(null);
    if (!canRegisterPayment || !canPayInvoice(inv) || payBusyId) return;

    setPayBusyId(inv.id);
    try {
      const data = await resolveInvoiceRevenueEntry(inv.id);
      const entryId = Number(data.revenue_entry_id || 0);
      if (entryId <= 0) {
        throw new Error('Could not prepare payment for this invoice.');
      }
      setPayEntryId(entryId);
    } catch (err) {
      if (typeof window.Swal !== 'undefined') {
        window.Swal.fire({
          icon: 'error',
          title: 'Could not open payment',
          text: err instanceof Error ? err.message : 'Payment form failed to load.',
        });
      } else {
        window.alert(err instanceof Error ? err.message : 'Payment form failed to load.');
      }
    } finally {
      setPayBusyId(null);
    }
  }

  async function handlePaymentSuccess(result) {
    setPayEntryId(null);
    if (typeof window.Swal !== 'undefined') {
      window.Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: result?.message || 'Payment recorded successfully.',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
      });
    }
    try {
      await loadInit();
    } catch (err) {
      if (typeof window.Swal !== 'undefined') {
        window.Swal.fire({
          icon: 'warning',
          title: 'Payment saved',
          text: err instanceof Error ? err.message : 'List could not refresh.',
        });
      }
    }
  }

  function toggleFilters() {
    setFiltersOpen((open) => {
      const next = !open;
      if (next) {
        setDraftStatusFilter(statusFilter);
        setDraftMyInvoicesOnly(myInvoicesOnly);
        setDraftDateFrom(dateFrom);
        setDraftDateTo(dateTo);
      }
      return next;
    });
  }

  function setDatePreset(preset) {
    const now = new Date();
    if (preset === 'month') {
      const month = thisMonthDateRange(now);
      setDraftDateFrom(month.date_from);
      setDraftDateTo(month.date_to);
      return;
    }
    if (preset === '30d') {
      const from = new Date(now);
      from.setDate(from.getDate() - 30);
      setDraftDateFrom(toInputDate(from));
      setDraftDateTo(toInputDate(now));
      return;
    }
    setDraftDateFrom('');
    setDraftDateTo('');
  }

  function applyFilters() {
    setStatusFilter(draftStatusFilter);
    setMyInvoicesOnly(draftMyInvoicesOnly);
    setDateFrom(draftDateFrom);
    setDateTo(draftDateTo);
    setFiltersOpen(false);
  }

  function resetFilters() {
    setStatusFilter('');
    setMyInvoicesOnly(false);
    setDateFrom('');
    setDateTo('');
    setDraftStatusFilter('');
    setDraftMyInvoicesOnly(false);
    setDraftDateFrom('');
    setDraftDateTo('');
    setFiltersOpen(false);
  }

  function clearAdvancedFilter(key) {
    if (key === 'status') setStatusFilter('');
    if (key === 'mine') setMyInvoicesOnly(false);
    if (key === 'date_from') {
      setDateFrom('');
      setDateTo('');
      setDraftDateFrom('');
      setDraftDateTo('');
    }
  }

  function clearSearch() {
    setSearch('');
    setSearchOpen(false);
  }

  function openKpiTrace(key) {
    const trace = resolveInvoiceKpiTrace(key, kpiTraceContext);
    if (trace) {
      setActiveKpiTrace({ key, trace });
    }
  }

  function kpiCardProps(key, label, tone) {
    return {
      type: 'button',
      className: `inv-kpi inv-kpi--${tone}`,
      onClick: () => openKpiTrace(key),
      'aria-label': `View summary for ${label}`,
      title: 'Click to see breakdown',
    };
  }

  function toggleMobileSearch() {
    setSearchOpen((open) => !open);
    if (!searchOpen) {
      window.setTimeout(() => mobileSearchInputRef.current?.focus(), 0);
    }
  }

  function renderSearchField(inputRef, id = 'inv-desk-search') {
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
          aria-label="Search invoices"
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
            <p className="exp-desk-filters-sub">Narrow the list by date, status, or ownership.</p>
          </div>
          <button type="button" className="exp-desk-filters-close" onClick={() => setFiltersOpen(false)} aria-label="Close filters">
            <X size={16} aria-hidden="true" />
          </button>
        </div>

        <div className="exp-desk-filters-section">
          <div className="exp-desk-filters-section-label">Date range</div>
          <div className="exp-desk-date-presets" role="group" aria-label="Quick date ranges">
            <button
              type="button"
              className={`exp-desk-date-preset${isThisMonthRange({ date_from: draftDateFrom, date_to: draftDateTo }) ? ' is-active' : ''}`}
              onClick={() => setDatePreset('month')}
            >
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
              <label htmlFor="invFilterDateFrom">From</label>
              <input
                id="invFilterDateFrom"
                type="date"
                value={draftDateFrom}
                onChange={(e) => setDraftDateFrom(e.target.value)}
              />
            </div>
            <div className="exp-desk-field">
              <label htmlFor="invFilterDateTo">To</label>
              <input
                id="invFilterDateTo"
                type="date"
                value={draftDateTo}
                onChange={(e) => setDraftDateTo(e.target.value)}
              />
            </div>
          </div>
        </div>

        <div className="exp-desk-filters-section">
          <div className="exp-desk-filters-section-label">Details</div>
          <div className="exp-desk-filters-grid">
            <div className="exp-desk-field exp-desk-field--full">
              <label htmlFor="invFilterStatus">Status</label>
              <select
                id="invFilterStatus"
                value={draftStatusFilter}
                onChange={(e) => setDraftStatusFilter(e.target.value)}
              >
                {STATUS_OPTIONS.map((opt) => (
                  <option key={opt.value || 'all'} value={opt.value}>{opt.label}</option>
                ))}
              </select>
            </div>
            <div className="exp-desk-field exp-desk-field--full">
              <label htmlFor="invFilterScope">Scope</label>
              <select
                id="invFilterScope"
                value={draftMyInvoicesOnly ? 'mine' : 'all'}
                onChange={(e) => setDraftMyInvoicesOnly(e.target.value === 'mine')}
              >
                <option value="all">All invoices</option>
                <option value="mine">My invoices</option>
              </select>
            </div>
          </div>
        </div>

        <div className="exp-desk-filters-footer">
          <button type="button" className="exp-desk-btn exp-desk-btn-ghost" onClick={() => {
            setDraftStatusFilter('');
            setDraftMyInvoicesOnly(false);
            setDraftDateFrom('');
            setDraftDateTo('');
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
      <div className="exp-desk-page exp-desk-page--analytics-off exp-desk-boot-loading" role="status" aria-live="polite">
        <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
        <span>Loading invoices...</span>
      </div>
    );
  }

  if (error || !init) {
    return (
      <div className="exp-desk-page">
        <div className="exp-desk-flash exp-desk-flash-error" role="alert">{error || 'Could not load invoices.'}</div>
      </div>
    );
  }

  const createInvoiceBtn = supportsTruckInvoices ? (
    <button type="button" className="exp-desk-btn exp-desk-btn-primary exp-desk-btn-create" onClick={handleNewClick}>
      <span className="exp-desk-btn-label-desktop">Create invoice</span>
      <span className="exp-desk-btn-label-mobile">New</span>
    </button>
  ) : (
    <a href={urls.create || 'create.php'} className="exp-desk-btn exp-desk-btn-primary exp-desk-btn-create">
      <span className="exp-desk-btn-label-desktop">Create invoice</span>
      <span className="exp-desk-btn-label-mobile">New</span>
    </a>
  );

  return (
    <div className="exp-desk-page exp-desk-page--analytics-off">
      {init.flash && <div className="exp-desk-flash exp-desk-flash-success" role="status">{init.flash}</div>}
      {notice ? (
        <div className="exp-desk-flash-ok" role="status">
          <span>{notice}</span>
          <button type="button" className="exp-desk-flash-dismiss" aria-label="Dismiss" onClick={() => setNotice('')}>×</button>
        </div>
      ) : null}
      {renderFiltersPanel()}

      <div className="exp-desk-page-header">
        <div className="exp-desk-page-header-search exp-desk-page-header-search--desktop">
          {renderSearchField(searchInputRef, 'inv-desk-search-desktop')}
        </div>

        <div className="exp-desk-page-header-actions">
          <div className="exp-desk-toolbar-secondary">
            <div className={`exp-desk-search-expand${searchOpen ? ' is-open' : ''}`} ref={searchExpandRef}>
              <button
                type="button"
                className={`exp-desk-search-toggle${searchOpen ? ' is-active' : ''}${hasSearchValue ? ' has-value' : ''}`}
                onClick={toggleMobileSearch}
                aria-expanded={searchOpen}
                aria-controls="inv-desk-search-mobile-panel"
                title="Search invoices"
              >
                <Search size={18} aria-hidden="true" />
              </button>
              <div id="inv-desk-search-mobile-panel" className={`exp-desk-search-panel${searchOpen ? ' is-open' : ''}`}>
                {renderSearchField(mobileSearchInputRef, 'inv-desk-search-mobile')}
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

            {createInvoiceBtn}
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
            All data
          </button>
        </div>
      )}

      {activeKpiTrace && (
        <InvoiceKpiTraceModal
          trace={activeKpiTrace.trace}
          onClose={() => setActiveKpiTrace(null)}
        />
      )}

      <section className="exp-desk-kpi-grid inv-kpi-grid" aria-label="Summary">
        <button {...kpiCardProps('totalInvoices', 'total invoices', 'invoices')} title="Invoices matching current filters. Click to see breakdown">
          <div className="inv-kpi-top">
            <span className="inv-kpi-label">Total invoices</span>
            <span className="inv-kpi-icon"><FileText size={16} aria-hidden="true" /></span>
          </div>
          <strong className="inv-kpi-value">{fmtBig(invoiceStats.total)}</strong>
        </button>
        <button {...kpiCardProps('totalValue', 'total value', 'value')} title="Sum of listed invoice totals. Click to see breakdown">
          <div className="inv-kpi-top">
            <span className="inv-kpi-label">Total value</span>
            <span className="inv-kpi-icon"><Wallet size={16} aria-hidden="true" /></span>
          </div>
          <strong className="inv-kpi-value inv-kpi-value--money">
            {defaultCurrency} {formatCurrency(invoiceStats.totalVal)}
          </strong>
        </button>
        <button {...kpiCardProps('outstanding', 'outstanding', 'outstanding')} title="Listed invoices with a balance due. Click to see breakdown">
          <div className="inv-kpi-top">
            <span className="inv-kpi-label">Outstanding</span>
            <span className="inv-kpi-icon"><AlertCircle size={16} aria-hidden="true" /></span>
          </div>
          <strong className="inv-kpi-value">{fmtBig(invoiceStats.outstanding)}</strong>
        </button>
        <button {...kpiCardProps('overdue', 'overdue', 'listed')} title="Listed invoices past due with a balance. Click to see breakdown">
          <div className="inv-kpi-top">
            <span className="inv-kpi-label">Overdue</span>
            <span className="inv-kpi-icon"><Clock size={16} aria-hidden="true" /></span>
          </div>
          <strong className="inv-kpi-value">{fmtBig(invoiceStats.overdue)}</strong>
        </button>
      </section>

      <section className="exp-desk-results inv-invoices-table" aria-label="Invoices">
        <div className="exp-desk-results-head">
          <span className="exp-desk-results-count">
            {filteredInvoices.length} {filteredInvoices.length === 1 ? 'invoice' : 'invoices'}
          </span>
        </div>

        {isAdmin && selectedIds.size > 0 && (
          <div className="qt-bulk-bar">
            <span>{selectedIds.size} selected</span>
            <button type="button" className="qt-bulk-btn qt-bulk-btn--danger" onClick={handleBulkDelete}>
              <i className="fas fa-trash-alt" style={{ marginRight: 6 }} />
              Delete
            </button>
          </div>
        )}

        {filteredInvoices.length === 0 ? (
          <div className="exp-desk-empty">
            <FileText className="exp-desk-empty-icon" aria-hidden="true" />
            <p className="exp-desk-empty-title">No invoices found</p>
            <p className="exp-desk-empty-sub">Try adjusting your search or filters.</p>
          </div>
        ) : (
          <div className="exp-desk-table-wrap">
            <table className="exp-desk-table inv-desk-table">
              <colgroup>
                <col className="inv-col-check" />
                <col className="inv-col-number" />
                <col className="inv-col-customer" />
                <col className="inv-col-salesperson" />
                <col className="inv-col-invoice-date" />
                <col className="inv-col-due-date" />
                <col className="inv-col-total" />
                <col className="inv-col-status" />
                <col className="inv-col-actions" />
              </colgroup>
              <thead>
                <tr>
                  <th className="qt-col-check qt-col-serial inv-col-check" title={`${filteredInvoices.length} invoices in this list`}>
                    No.
                  </th>
                  <th className="inv-col-number">Number</th>
                  <th className="inv-col-customer">Customer</th>
                  <th className="inv-col-salesperson">Salesperson</th>
                  <th className="inv-col-invoice-date">Invoice date</th>
                  <th className="inv-col-due-date">Due date</th>
                  <th className="inv-col-total">Total</th>
                  <th className="inv-col-status">Status</th>
                  <th className="qt-col-actions inv-col-actions" aria-label="Actions" />
                </tr>
              </thead>
              <tbody>
                {filteredInvoices.map((inv, index) => (
                  <tr
                    key={inv.id}
                    data-invoice-id={inv.id}
                    aria-current={openedId === Number(inv.id) ? 'true' : undefined}
                    className={`exp-desk-row-clickable${selectedIds.has(inv.id) ? ' qt-row-selected' : ''}${openedId === Number(inv.id) ? ' is-opened' : ''}`}
                    tabIndex={0}
                    onClick={(event) => handleRowClick(event, inv)}
                    onKeyDown={(event) => {
                      if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        goView(inv.id);
                      }
                    }}
                  >
                    <td className="qt-col-check qt-col-serial inv-col-check">{index + 1}</td>
                    <td className="inv-col-number">
                      <span className="inv-number-cell">
                        <span className="exp-desk-ref">{inv.invoice_number}</span>
                        {supportsOrderTypeSplit && inv.order_type && (
                          <span className="inv-number-type"><TypeBadge type={inv.order_type} /></span>
                        )}
                      </span>
                    </td>
                    <td className="inv-col-customer">
                      <span
                        className="exp-desk-cell-main inv-cell-customer"
                        title={inv.customer_name || ''}
                      >
                        {inv.customer_name || '-'}
                      </span>
                    </td>
                    <td className="inv-col-salesperson">
                      <div className="qt-salesperson-cell">
                        <span className="qt-avatar" style={avatarStyle(inv.salesperson)}>{initials(inv.salesperson)}</span>
                        <span className="qt-salesperson-name" title={inv.salesperson || ''}>{inv.salesperson || '-'}</span>
                      </div>
                    </td>
                    <td className="inv-col-invoice-date">
                      <span className="exp-desk-subdate inv-date-value">
                        {formatDate(inv.invoice_date || inv.created_at)}
                      </span>
                    </td>
                    <td className="inv-col-due-date">
                      <DueDateCell invoice={inv} />
                    </td>
                    <td className="exp-desk-amt inv-col-total">{formatCurrency(inv.total_amount)}</td>
                    <td className="inv-col-status"><StatusPill status={inv.status} /></td>
                    <td className="qt-col-actions inv-col-actions" onClick={(e) => e.stopPropagation()} data-inv-actions="1">
                      <div className="qt-actions-menu">
                        <button
                          type="button"
                          className="qt-actions-btn"
                          aria-label="Actions"
                          onClick={(e) => {
                            e.stopPropagation();
                            setOpenMenuId((id) => (id === inv.id ? null : inv.id));
                          }}
                        >
                          <i className="fas fa-ellipsis-vertical" />
                        </button>
                        {openMenuId === inv.id && (
                          <div className="qt-actions-dropdown">
                            <a href={buildUrl(urls.view, inv.id)} onClick={() => markOpened(inv.id)}><i className="fas fa-eye" style={{ width: 20, color: '#94a3b8' }} /> View</a>
                            <a href={buildUrl(urls.print, inv.id)} target="_blank" rel="noopener noreferrer" onClick={() => markOpened(inv.id)}><i className="fas fa-print" style={{ width: 20, color: '#94a3b8' }} /> Print</a>
                            {canRegisterPayment && canPayInvoice(inv) ? (
                              <button
                                type="button"
                                onClick={(e) => handlePayInvoice(inv, e)}
                                disabled={payBusyId === inv.id}
                              >
                                <i className={`fas ${payBusyId === inv.id ? 'fa-spinner fa-spin' : 'fa-money-bill-wave'}`} style={{ width: 20, color: '#94a3b8' }} />
                                {payBusyId === inv.id ? 'Opening...' : 'Pay'}
                              </button>
                            ) : null}
                            {!['cancelled', 'canceled'].includes(String(inv.status || '').toLowerCase()) && inv.correction_status !== 'pending' ? (
                              <button type="button" onClick={(e) => handleReportInvoice(inv, e)}>
                                <i className="fas fa-flag" style={{ width: 20, color: '#94a3b8' }} /> Report wrong invoice
                              </button>
                            ) : null}
                            {inv.correction_status === 'pending' ? (
                              <span className="inv-report-waiting">Waiting for admin</span>
                            ) : null}
                            {isAdmin && (
                              <button type="button" className="qt-actions-delete" onClick={(e) => handleDeleteInvoice(inv, e)}>
                                <i className="fas fa-trash-alt" style={{ width: 20, color: '#94a3b8' }} /> Delete
                              </button>
                            )}
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

      {payEntryId ? createPortal(
        <InvoiceRegisterPaymentModal
          entryId={payEntryId}
          onClose={() => setPayEntryId(null)}
          onSuccess={handlePaymentSuccess}
        />,
        document.body,
      ) : null}
    </div>
  );
}
