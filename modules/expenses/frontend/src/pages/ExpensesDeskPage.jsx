import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import {
  Eye,
  LayoutGrid,
  Loader2,
  MoreVertical,
  Pencil,
  Plus,
  Search,
  SlidersHorizontal,
  Trash2,
  X,
} from 'lucide-react';
import ExpenseQuickViewModal from '../components/ExpenseQuickViewModal';
import ExpensePostConfirmModal from '../components/ExpensePostConfirmModal';
import ExpenseKpiTraceModal from '../components/ExpenseKpiTraceModal';
import ExpenseAnalyticsPanel from '../components/ExpenseAnalyticsPanel';
import ExportMenu from '../components/ExportMenu';
import ExportRangeModal from '../components/ExportRangeModal';
import ExpenseCreatePage from './ExpenseCreatePage';
import ExpenseStatusBadge, { canDeleteDraftExpense, expenseStatusLabel } from '../components/ExpenseStatusBadge';
import {
  deleteDraftExpense,
  deskPageUrl,
  fetchDeskExpenses,
  fetchDeskInit,
  fetchPostDraftPreview,
  isPurchasesDesk,
  postDraftExpense,
  purchasesCreateVoucherUrl,
  purchasesVoucherViewUrl,
} from '../api/expensesDesk';
import { exportExpensesExcel, exportExpensesPdf } from '../utils/exportExpenses';
import { resolveKpiTrace } from '../utils/kpiTrace';
import { buildListedSummaryStats } from '../utils/summaryStats';

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

const STATUS_BREAKDOWN_META = [
  { key: 'posted', label: 'Posted', color: '#22c55e' },
  { key: 'paid', label: 'Paid', color: '#3b82f6' },
  { key: 'approved', label: 'Approved', color: '#14b8a6' },
  { key: 'pending', label: 'Pending', color: '#f59e0b' },
  { key: 'confirming', label: 'Confirming', color: '#a855f7' },
  { key: 'draft', label: 'Draft', color: '#94a3b8' },
  { key: 'rejected', label: 'Rejected', color: '#ef4444' },
];

function buildStatusBreakdownFromRows(rows) {
  const counts = Object.create(null);
  for (const row of rows) {
    const key = expenseStatusLabel(row).toLowerCase();
    counts[key] = (counts[key] || 0) + 1;
  }
  return STATUS_BREAKDOWN_META
    .map((meta) => ({ ...meta, count: counts[meta.key] || 0 }))
    .filter((row) => row.count > 0);
}

const STATUS_LABELS = {
  posted: 'Posted',
  unposted: 'Unposted',
  draft: 'Draft',
  rejected: 'Rejected',
  pending: 'Pending',
};

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

function defaultListFilters() {
  return { ...emptyFilters, ...thisMonthDateRange() };
}

function filtersFromLocationSearch() {
  const base = defaultListFilters();
  if (typeof window === 'undefined') return base;
  const params = new URLSearchParams(window.location.search);
  const status = String(params.get('status') || '').trim().toLowerCase();
  const allowed = new Set(['draft', 'posted', 'unposted', 'pending', 'rejected']);
  if (allowed.has(status)) {
    base.status = status;
  }
  // Explicit URL dates override the default month filter (empty = all dates).
  if (params.has('date_from') || params.has('date_to')) {
    base.date_from = String(params.get('date_from') || '').trim();
    base.date_to = String(params.get('date_to') || '').trim();
  }
  return base;
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

/** Shrink KPI money text for large amounts so the card size stays fixed. */
function kpiMoneySizeClass(formatted) {
  const digits = String(formatted || '').replace(/\D/g, '').length;
  if (digits >= 13) return ' is-xxl';
  if (digits >= 11) return ' is-xl';
  if (digits >= 9) return ' is-lg';
  return '';
}

function KpiMoney({ amount, currencyCode = 'TZS' }) {
  const text = formatCurrency(amount, currencyCode);
  return (
    <div
      className={`exp-desk-kpi-value exp-desk-kpi-value--money${kpiMoneySizeClass(text)}`}
      title={text}
    >
      {text}
    </div>
  );
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

function partyInitials(name) {
  const parts = String(name || '')
    .trim()
    .split(/\s+/)
    .filter(Boolean);
  if (parts.length === 0) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return `${parts[0][0] || ''}${parts[1][0] || ''}`.toUpperCase();
}

function partyAvatarTone(name) {
  const raw = String(name || '');
  let hash = 0;
  for (let i = 0; i < raw.length; i += 1) {
    hash = (hash + raw.charCodeAt(i) * (i + 1)) % 10;
  }
  return hash;
}

function isRowActionTarget(target) {
  if (!(target instanceof Element)) return false;
  return Boolean(
    target.closest('a, button, input, select, textarea, label, [data-exp-row-ignore], [data-exp-row-menu]'),
  );
}

function ExpenseRowActionsMenu({ menu, onClose, onView, onEdit, onDelete, deleting }) {
  if (!menu || typeof document === 'undefined') return null;

  const style = {
    position: 'fixed',
    top: `${menu.top}px`,
    right: `${menu.right}px`,
    zIndex: 14000,
  };

  return createPortal(
    <div className="exp-desk-row-menu exp-desk-row-menu--portal" role="menu" style={style} data-exp-row-menu>
      <button
        type="button"
        role="menuitem"
        className="exp-desk-row-menu-item"
        onClick={(event) => {
          event.stopPropagation();
          onClose();
          onView(menu.expense);
        }}
      >
        <Eye size={15} aria-hidden="true" />
        View
      </button>
      {menu.canEdit ? (
        <button
          type="button"
          role="menuitem"
          className="exp-desk-row-menu-item"
          onClick={(event) => {
            event.stopPropagation();
            onClose();
            onEdit(menu.expense);
          }}
        >
          <Pencil size={15} aria-hidden="true" />
          Edit
        </button>
      ) : null}
      {menu.canDelete ? (
        <>
          <div className="exp-desk-row-menu-divider" role="separator" />
          <button
            type="button"
            role="menuitem"
            className="exp-desk-row-menu-item exp-desk-row-menu-delete"
            disabled={deleting}
            onClick={(event) => {
              event.stopPropagation();
              if (deleting) return;
              onClose();
              onDelete(menu.expense);
            }}
          >
            <Trash2 size={15} aria-hidden="true" />
            Delete
          </button>
        </>
      ) : null}
    </div>,
    document.body,
  );
}

export default function ExpensesDeskPage() {
  const purchasesDesk = isPurchasesDesk();
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
  const [recordModal, setRecordModal] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [exportingExcel, setExportingExcel] = useState(false);
  const [exportingPdf, setExportingPdf] = useState(false);
  const [exportModalOpen, setExportModalOpen] = useState(false);
  const [exportFormat, setExportFormat] = useState('pdf');
  const [exportModalError, setExportModalError] = useState(null);
  const [rowMenu, setRowMenu] = useState(null);
  const [selectedId, setSelectedId] = useState(0);
  const [showAnalytics, setShowAnalytics] = useState(false);
  const [importNotice, setImportNotice] = useState(() => importedNoticeFromLocationSearch());
  const filterDropdownRef = useRef(null);
  const filterBtnRef = useRef(null);
  const filterPanelRef = useRef(null);
  const [filterPanelStyle, setFilterPanelStyle] = useState(null);
  const searchExpandRef = useRef(null);
  const searchInputRef = useRef(null);
  const mobileSearchInputRef = useRef(null);
  const appliedSearchRef = useRef((initialFilters.search || '').trim());

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

  async function fetchExpensesForExport(range) {
    const activeFilters = {
      ...filters,
      date_from: range.allTime ? '' : (range.startDate || ''),
      date_to: range.allTime ? '' : (range.endDate || ''),
    };
    const listData = await fetchDeskExpenses(activeFilters);
    return Array.isArray(listData.data) ? listData.data : [];
  }

  function openExportModal(format) {
    setExportFormat(format);
    setExportModalError(null);
    setExportModalOpen(true);
  }

  function closeExportModal() {
    if (exportingExcel || exportingPdf) return;
    setExportModalOpen(false);
    setExportModalError(null);
  }

  async function handleExportRange(range) {
    const isExcel = exportFormat === 'excel';
    const setBusy = isExcel ? setExportingExcel : setExportingPdf;
    setBusy(true);
    setExportModalError(null);
    setError('');
    try {
      const rows = await fetchExpensesForExport(range);
      if (isExcel) {
        await exportExpensesExcel(rows);
      } else {
        const subtitle = range.allTime
          ? 'All time'
          : `${range.startDate} to ${range.endDate}`;
        await exportExpensesPdf(rows, { title: 'Expenses report', subtitle });
      }
      setExportModalOpen(false);
    } catch (err) {
      setExportModalError(
        err instanceof Error
          ? err.message
          : (isExcel ? 'Failed to export Excel' : 'Failed to export PDF report'),
      );
    } finally {
      setBusy(false);
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
    const next = (draftFilters.search || '').trim();
    if (next === appliedSearchRef.current) return undefined;
    const timer = window.setTimeout(() => {
      appliedSearchRef.current = next;
      setFilters((current) => (
        current.search === next ? current : { ...current, search: next }
      ));
    }, 280);
    return () => window.clearTimeout(timer);
  }, [draftFilters.search]);

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
  const summaryStats = useMemo(
    () => buildListedSummaryStats(expenses),
    [expenses],
  );
  const statusBreakdown = useMemo(
    () => buildStatusBreakdownFromRows(expenses),
    [expenses],
  );
  function applyFilters() {
    setFilters((current) => ({ ...current, ...draftFilters, search: current.search }));
    setFiltersOpen(false);
  }

  function applySearch() {
    const next = (draftFilters.search || '').trim();
    appliedSearchRef.current = next;
    setFilters((current) => (
      current.search === next ? current : { ...current, search: next }
    ));
  }

  function onSearchChange(value) {
    setDraftFilters((f) => ({ ...f, search: value }));
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
        label: isThisMonthRange(activeFilters)
          ? 'Date: This month'
          : `Date: ${fromLabel} - ${toLabel}`,
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

  function clearSearch(inputRef) {
    appliedSearchRef.current = '';
    setDraftFilters((f) => (f.search === '' ? f : { ...f, search: '' }));
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

  function closeRowMenu() {
    setRowMenu(null);
  }

  function openRowMenu(event, expense) {
    event.stopPropagation();
    const button = event.currentTarget;
    const rect = button.getBoundingClientRect();
    const canMutate = canDeleteDraftExpense(expense);
    const menuId = expense.row_key || expense.id;
    setRowMenu((current) => {
      if (current?.id === menuId) return null;
      return {
        id: menuId,
        expense,
        canEdit: canMutate,
        canDelete: canMutate,
        top: Math.round(rect.bottom + 4),
        right: Math.round(window.innerWidth - rect.right),
      };
    });
  }

  function openExpenseDetails(expense) {
    setSelectedId(expense.row_key || Number(expense.id) || 0);
    if (purchasesDesk) {
      const voucherUrl = purchasesVoucherViewUrl(expense);
      if (voucherUrl) {
        window.location.href = voucherUrl;
        return;
      }
    }
    setPreviewExpense(expense);
  }

  function handleExpenseRowClick(event, expense) {
    if (isRowActionTarget(event.target)) return;
    openExpenseDetails(expense);
  }

  useEffect(() => {
    if (!rowMenu) return undefined;
    const onPointerDown = (event) => {
      const target = event.target;
      if (target instanceof Element && target.closest('[data-exp-row-menu]')) return;
      closeRowMenu();
    };
    const onKeyDown = (event) => {
      if (event.key === 'Escape') closeRowMenu();
    };
    const onScroll = () => closeRowMenu();
    document.addEventListener('mousedown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    window.addEventListener('scroll', onScroll, true);
    return () => {
      document.removeEventListener('mousedown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
      window.removeEventListener('scroll', onScroll, true);
    };
  }, [rowMenu]);

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
    const showClear = draftFilters.search.trim() !== '' || filters.search.trim() !== '';
    return (
      <div className="exp-desk-search-field">
        <Search className="exp-desk-search-icon" aria-hidden="true" />
        <input
          ref={inputRef}
          id={id}
          type="text"
          role="searchbox"
          className="exp-desk-search-input"
          placeholder={purchasesDesk ? 'Search voucher #, payee, description' : 'Search expense #, description, accounts'}
          value={draftFilters.search}
          onChange={(e) => onSearchChange(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              applySearch();
              e.currentTarget.blur();
            }
            if (e.key === 'Escape') clearSearch(inputRef);
          }}
          aria-label={purchasesDesk ? 'Search purchases' : 'Search expenses'}
          autoComplete="off"
        />
        {showClear && (
          <button
            type="button"
            className="exp-desk-search-clear"
            onMouseDown={(e) => e.preventDefault()}
            onClick={() => clearSearch(inputRef)}
            aria-label="Clear search"
          >
            <X size={16} aria-hidden="true" />
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
            <button
              type="button"
              className={`exp-desk-date-preset${isThisMonthRange(draftFilters) ? ' is-active' : ''}`}
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
    <div className="exp-desk-page exp-desk-page--analytics-off">
      {previewExpense && !purchasesDesk ? (
        <ExpenseQuickViewModal
          expense={previewExpense}
          onClose={() => setPreviewExpense(null)}
          onDeleteDraft={handleDeleteDraft}
          onPostDraft={handlePostDraft}
          onEditDraft={(expense) => {
            setPreviewExpense(null);
            setRecordModal({ editId: expense.id });
          }}
          deleting={deletingDraftId === previewExpense.id}
          posting={postingDraftId === previewExpense.id}
        />
      ) : null}
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
                title={purchasesDesk ? 'Search purchases' : 'Search expenses'}
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
                <SlidersHorizontal size={18} strokeWidth={2.25} aria-hidden="true" />
                {hasAdvancedFilters(filters) && <span className="exp-desk-filter-dot" aria-hidden="true" />}
              </button>
            </div>
            {purchasesDesk ? (
              <a
                href={purchasesCreateVoucherUrl()}
                className="exp-desk-btn exp-desk-btn-primary exp-desk-btn-create"
                aria-label="Create purchase"
              >
                <Plus size={16} aria-hidden="true" />
                <span className="exp-desk-btn-label-desktop">Create purchase</span>
                <span className="exp-desk-btn-label-mobile">New</span>
              </a>
            ) : (
              <button
                type="button"
                className="exp-desk-btn exp-desk-btn-primary exp-desk-btn-create"
                aria-label="Record expense"
                onClick={() => setRecordModal({ editId: null })}
              >
                <Plus size={16} aria-hidden="true" />
                <span className="exp-desk-btn-label-desktop">Record expense</span>
                <span className="exp-desk-btn-label-mobile">New</span>
              </button>
            )}
          </div>
        </div>
      </div>

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

      <section className="exp-desk-kpi-grid exp-desk-kpi-grid--dash" aria-label="Summary">
        <button {...kpiCardProps('totalExclusive', 'total expenses exclusive')}>
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--violet">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <circle cx="12" cy="12" r="10" />
              <path d="M12 6v12M8 10h5a2.5 2.5 0 0 1 0 5H8" />
            </svg>
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">{purchasesDesk ? 'Total Purchases' : 'Total Expenses (Exclusive)'}</div>
            <KpiMoney amount={summaryStats.total_exclusive} />
            <div className="exp-desk-kpi-helper">{purchasesDesk ? 'voucher totals' : 'amount − VAT'}</div>
          </div>
        </button>
        {!purchasesDesk && (
        <button {...kpiCardProps('inputVat', 'Input VAT')}>
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--indigo">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <path d="M4 7h16M4 12h10M4 17h7" />
              <path d="M18 12v7M15.5 16.5 18 19l2.5-2.5" />
            </svg>
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">Input VAT</div>
            <KpiMoney amount={summaryStats.input_vat} />
          </div>
        </button>
        )}
        <button {...kpiCardProps('totalInclusive', purchasesDesk ? 'Listed vouchers' : 'Total Expenses (VAT + Exclusive)')}>
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--teal">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <rect x="2" y="7" width="20" height="14" rx="2" />
              <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2" />
            </svg>
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">{purchasesDesk ? 'Listed now' : 'Total Expenses (VAT + Exclusive)'}</div>
            {purchasesDesk ? (
              <div className="exp-desk-kpi-value">{expenses.length}</div>
            ) : (
              <KpiMoney amount={summaryStats.total_inclusive} />
            )}
            <div className="exp-desk-kpi-helper">{purchasesDesk ? 'matching filters' : 'gross'}</div>
          </div>
        </button>
        <button {...kpiCardProps('totalBank', 'Total Bank')}>
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--blue">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <path d="M3 10h18L12 3 3 10z" />
              <path d="M5 10v8h14v-8" />
              <path d="M9 18v-4h6v4" />
            </svg>
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">Total Bank</div>
            <KpiMoney amount={summaryStats.total_bank} />
            <div className="exp-desk-kpi-helper">bank / mobile / cheque</div>
          </div>
        </button>
        <button {...kpiCardProps('totalCash', 'Total Cash')}>
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--amber">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <rect x="2" y="6" width="20" height="12" rx="2" />
              <circle cx="12" cy="12" r="2.5" />
            </svg>
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">Total Cash</div>
            <KpiMoney amount={summaryStats.total_cash} />
          </div>
        </button>
        <button {...kpiCardProps('totalPayables', 'Total Payables')}>
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--rose">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <path d="M8 7h11a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H8l-4 3V9a2 2 0 0 1 2-2z" />
              <path d="M10 12h6M10 15h4" />
            </svg>
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">Total Payables</div>
            <KpiMoney amount={summaryStats.total_payables} />
            <div className="exp-desk-kpi-helper">Credit payment type</div>
          </div>
        </button>
      </section>

      {showAnalytics ? (
        <ExpenseAnalyticsPanel
          trend={stats?.trend || []}
          statusBreakdown={statusBreakdown}
          trendFrom={stats?.trend_from || ''}
          trendTo={stats?.trend_to || ''}
          totalRecords={expenses.length}
        />
      ) : null}

      <section className="exp-desk-results" aria-label={purchasesDesk ? 'Stock purchase vouchers' : 'Expense entries'}>
        <div className="exp-desk-results-head">
          <span className="exp-desk-results-count">
            {expenses.length} {expenses.length === 1 ? 'entry' : 'entries'}
          </span>
          <div className="exp-desk-results-controls">
            <ExportMenu
              exportingExcel={exportingExcel}
              exportingPdf={exportingPdf}
              onExportExcel={() => openExportModal('excel')}
              onExportPdf={() => openExportModal('pdf')}
            />
            <button
              type="button"
              className={`exp-desk-layout-toggle${showAnalytics ? ' is-active' : ''}`}
              onClick={() => setShowAnalytics((v) => !v)}
              title={showAnalytics ? 'Hide analytics' : 'Show analytics'}
              aria-pressed={showAnalytics}
            >
              <LayoutGrid size={16} aria-hidden="true" />
            </button>
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

        {loading ? (
          <div className="exp-desk-loading" role="status">
            <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
            <span>Refreshing...</span>
          </div>
        ) : null}

        <div className="exp-desk-table-wrap">
          <table className="exp-desk-table exp-desk-table--ledger">
            <colgroup>
              <col className="col-ref" />
              <col className="col-date" />
              <col className="col-payee" />
              <col className="col-type" />
              <col className="col-total" />
              <col className="col-exclusive" />
              <col className="col-vat" />
              <col className="col-status" />
              <col className="col-actions" />
            </colgroup>
            <thead>
              <tr>
                <th className="col-ref">Reference</th>
                <th className="col-date">Date</th>
                <th className="col-payee">Payee</th>
                <th className="col-type">Type</th>
                <th className="col-total">Total</th>
                <th className="col-exclusive">Exclusive</th>
                <th className="col-vat">VAT</th>
                <th className="col-status">Status</th>
                <th className="col-actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              {expenses.length === 0 ? (
                <tr>
                  <td colSpan={9}>
                    <div className="exp-desk-empty">
                      {purchasesDesk
                        ? 'No Stock Purchase vouchers match your filters.'
                        : 'No expense entries match your filters.'}
                    </div>
                  </td>
                </tr>
              ) : (
                expenses.map((expense) => {
                  const payeeName = expenseDisplayName(expense) || '-';
                  const rowKey = expense.row_key || `exp-${expense.id}`;
                  const selectedKey = selectedId === (expense.row_key || expense.id)
                    || selectedId === Number(expense.id);
                  const totalAmt = Math.max(0, Number(expense.amount) || 0);
                  const vatAmt = Math.max(0, Math.min(totalAmt, Number(expense.tax_amount) || 0));
                  const exclusiveAmt = Math.max(0, totalAmt - vatAmt);
                  const accountLabel = expense.category_name || expense.main_account_name || '';
                  return (
                    <tr
                      key={rowKey}
                      data-exp-entry-id={rowKey}
                      className={`exp-desk-row-clickable${selectedKey ? ' is-selected' : ''}`}
                      tabIndex={0}
                      onClick={(event) => handleExpenseRowClick(event, expense)}
                      onKeyDown={(event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                          event.preventDefault();
                          openExpenseDetails(expense);
                        }
                      }}
                    >
                      <td className="col-ref">
                        <span className="exp-desk-ref" title={expense.expense_number || `#${expense.id}`}>
                          {expense.expense_number || `#${expense.id}`}
                        </span>
                      </td>
                      <td className="col-date">{formatDate(expense.date)}</td>
                      <td className="col-payee">
                        <div className="exp-desk-party">
                          <span
                            className={`exp-desk-party-av exp-desk-party-av--tone-${partyAvatarTone(payeeName)}`}
                            aria-hidden="true"
                          >
                            {partyInitials(payeeName)}
                          </span>
                          <div className="exp-desk-party-body">
                            <span className="exp-desk-party-name" title={payeeName}>
                              {payeeName}
                            </span>
                          </div>
                        </div>
                      </td>
                      <td className="col-type">
                        <div className="exp-desk-type-cell">
                          <span className="exp-desk-type">
                            {expense.payment_method_label || '-'}
                          </span>
                          {accountLabel ? (
                            <span className="exp-desk-type-meta" title={accountLabel}>{accountLabel}</span>
                          ) : expense.source_account_name ? (
                            <span className="exp-desk-type-meta">{expense.source_account_name}</span>
                          ) : null}
                        </div>
                      </td>
                      <td className="col-total">
                        <span className="exp-desk-amt" title={formatCurrency(totalAmt, expense.currency_code)}>
                          {formatCurrency(totalAmt, expense.currency_code)}
                        </span>
                      </td>
                      <td className="col-exclusive">
                        <span className="exp-desk-amt exp-desk-amt--muted" title={formatCurrency(exclusiveAmt, expense.currency_code)}>
                          {formatCurrency(exclusiveAmt, expense.currency_code)}
                        </span>
                      </td>
                      <td className="col-vat">
                        <span className="exp-desk-amt exp-desk-amt--muted" title={formatCurrency(vatAmt, expense.currency_code)}>
                          {formatCurrency(vatAmt, expense.currency_code)}
                        </span>
                      </td>
                      <td className="col-status">
                        <ExpenseStatusBadge expense={expense} />
                      </td>
                      <td className="col-actions" data-exp-row-ignore data-exp-row-menu>
                        <div className={`exp-desk-row-menu-wrap${rowMenu?.id === rowKey ? ' is-open' : ''}`}>
                          <button
                            type="button"
                            className="exp-desk-row-menu-btn"
                            aria-label="Row actions"
                            aria-haspopup="menu"
                            aria-expanded={rowMenu?.id === rowKey}
                            onClick={(event) => openRowMenu(event, expense)}
                          >
                            <MoreVertical size={16} strokeWidth={2} aria-hidden="true" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>

        <div className="exp-desk-pagination">
          <span className="exp-desk-pagination-meta">
            {expenses.length === 0
              ? 'Showing 0 records'
              : `Showing ${expenses.length} record${expenses.length === 1 ? '' : 's'}`}
          </span>
        </div>
      </section>

      {rowMenu ? (
        <ExpenseRowActionsMenu
          menu={rowMenu}
          onClose={closeRowMenu}
          deleting={deletingDraftId === rowMenu.id}
          onView={(expense) => {
            openExpenseDetails(expense);
          }}
          onEdit={(expense) => {
            setPreviewExpense(null);
            setRecordModal({ editId: expense.id });
          }}
          onDelete={handleDeleteDraft}
        />
      ) : null}

      {recordModal ? (
        <ExpenseCreatePage
          key={recordModal.editId ? `edit-${recordModal.editId}` : 'create'}
          asModal
          editId={recordModal.editId || null}
          onClose={async () => {
            setRecordModal(null);
            await loadData(filters, true);
          }}
          onSaved={async () => {
            setRecordModal(null);
            await loadData(filters, true);
          }}
        />
      ) : null}

      <ExportRangeModal
        open={exportModalOpen}
        format={exportFormat}
        exporting={exportFormat === 'excel' ? exportingExcel : exportingPdf}
        error={exportModalError}
        onClose={closeExportModal}
        onExport={handleExportRange}
      />
    </div>
  );
}
