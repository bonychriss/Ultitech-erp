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
  Check,
  Building2,
  CalendarDays,
  ChevronLeft,
  ChevronDown,
  ChevronRight,
  CreditCard,
  Download,
  FileSpreadsheet,
  FileText,
  Filter,
  Loader2,
  Mail,
  MessageCircle,
  MoreVertical,
  Phone,
  RotateCcw,
  ReceiptText,
  Scale,
  Search,
  ShoppingCart,
  Trash2,
  UserRound,
  X,
  Zap,
} from 'lucide-react';
import { downloadStatementExcel, fetchStatementHistory, fetchStatementInit, saveStatementHistory } from '../api/statementDesk';

function formatDateInput(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function quickRangeDates(range, today = new Date()) {
  const end = formatDateInput(today);
  if (range === 'this_month') {
    return [formatDateInput(new Date(today.getFullYear(), today.getMonth(), 1)), end];
  }
  if (range === 'last_month') {
    return [
      formatDateInput(new Date(today.getFullYear(), today.getMonth() - 1, 1)),
      formatDateInput(new Date(today.getFullYear(), today.getMonth(), 0)),
    ];
  }
  const start = new Date(today.getFullYear(), today.getMonth() - 3, 1);
  const lastDay = new Date(start.getFullYear(), start.getMonth() + 1, 0).getDate();
  start.setDate(Math.min(today.getDate(), lastDay));
  return [formatDateInput(start), end];
}

function matchingQuickRange(from, to) {
  return ['this_month', 'last_month', 'last_3_months'].find((range) => {
    const [start, end] = quickRangeDates(range);
    return from === start && to === end;
  }) || 'custom';
}

function formatMoney(value) {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value) || 0);
}

function formatHistoryDate(value) {
  const date = String(value || '').slice(0, 10);
  const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);
  return parts ? `${parts[3]}/${parts[2]}/${parts[1]}` : '-';
}

function formatStatementDate(value) {
  const date = String(value || '').slice(0, 10);
  const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);
  if (!parts) return '-';
  const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  return `${parts[3]} ${monthNames[Number(parts[2]) - 1] || ''} ${parts[1]}`;
}

function cellOrDash(value) {
  const text = value == null ? '' : String(value).trim();
  return text || '-';
}

function statementInvoiceViewUrl(row, module) {
  const invoiceId = Number(row.invoice_id);
  if (!String(row.id || '').startsWith('invoice-') || !Number.isSafeInteger(invoiceId) || invoiceId <= 0) {
    return '';
  }
  return `../modules/sales/invoices/view.php?id=${encodeURIComponent(invoiceId)}&module=${encodeURIComponent(module)}`;
}

function InvoiceReference({ row, module }) {
  const reference = cellOrDash(row.reference);
  const href = statementInvoiceViewUrl(row, module);
  return href && reference !== '-'
    ? <a className="stmt-invoice-reference-link" href={href}>{reference}</a>
    : reference;
}

function ExcelExportIcon({ src, className = '', busy = false }) {
  if (!src && !busy) return null;
  return (
    <span className={`stmt-excel-icon-wrap${busy ? ' is-busy' : ''}`}>
      {busy && <span className="stmt-excel-icon-ring" aria-hidden="true" />}
      {src ? (
        <img
          src={src}
          alt=""
          className={`stmt-excel-icon${className ? ` ${className}` : ''}`}
          width={20}
          height={20}
          aria-hidden="true"
        />
      ) : (
        <Loader2 className="stmt-excel-icon stmt-excel-icon-spinner" size={20} aria-hidden="true" />
      )}
    </span>
  );
}

export default function CustomerStatementPage() {
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [data, setData] = useState(null);

  const [customerIds, setCustomerIds] = useState([]);
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [quickRange, setQuickRange] = useState('custom');
  const [pickerOpen, setPickerOpen] = useState(false);
  const [pickerSearch, setPickerSearch] = useState('');
  const [pickerPopStyle, setPickerPopStyle] = useState(null);
  const [excelDownload, setExcelDownload] = useState({ status: 'idle', message: '' });
  const [actionsOpen, setActionsOpen] = useState(false);
  const [savedStatementsOpen, setSavedStatementsOpen] = useState(false);
  const [history, setHistory] = useState({ results: [], total: 0, page: 1, page_size: 10 });
  const [historyLoading, setHistoryLoading] = useState(true);
  const [historyError, setHistoryError] = useState('');
  const [savedStatement, setSavedStatement] = useState(null);
  const [openingHistory, setOpeningHistory] = useState(false);
  const [headerActionsEl] = useState(() => document.getElementById('statement-header-actions'));
  const [rowMenuOpen, setRowMenuOpen] = useState(null);
  const [deleteConfirm, setDeleteConfirm] = useState(null);
  const [deleting, setDeleting] = useState(false);

  const pickerRef = useRef(null);
  const pickerBtnRef = useRef(null);
  const pickerPopRef = useRef(null);
  const dateFromRef = useRef(null);
  const excelResetTimerRef = useRef(null);
  const actionsRef = useRef(null);
  const actionsTriggerRef = useRef(null);
  const savedDetailsRef = useRef(null);

  const loadData = useCallback(async (params) => {
    setSubmitting(true);
    setError('');
    try {
      const payload = await fetchStatementInit(params);
      setData(payload);
      setCustomerIds((payload.filters?.customer_ids || []).map(Number));
      setDateFrom(payload.filters?.date_from || '');
      setDateTo(payload.filters?.date_to || '');
      setQuickRange(matchingQuickRange(payload.filters?.date_from || '', payload.filters?.date_to || ''));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load statement.');
    } finally {
      setSubmitting(false);
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    loadData(params);
  }, [loadData]);

  const loadHistory = useCallback(async (page = 1) => {
    setHistoryLoading(true);
    setHistoryError('');
    try {
      setHistory(await fetchStatementHistory(page));
    } catch (err) {
      setHistoryError(err instanceof Error ? err.message : 'Failed to load statement history.');
    } finally {
      setHistoryLoading(false);
    }
  }, []);

  useEffect(() => {
    loadHistory();
  }, [loadHistory]);

  useEffect(() => {
    if (savedStatement) savedDetailsRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }, [savedStatement]);

  useEffect(() => () => {
    if (excelResetTimerRef.current) {
      window.clearTimeout(excelResetTimerRef.current);
    }
  }, []);

  useEffect(() => {
    if (!actionsOpen) return undefined;
    function handleOutsideClick(event) {
      if (!actionsRef.current?.contains(event.target)) setActionsOpen(false);
    }
    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        setActionsOpen(false);
        actionsTriggerRef.current?.focus();
      }
    }
    document.addEventListener('mousedown', handleOutsideClick);
    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('mousedown', handleOutsideClick);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [actionsOpen]);

  useEffect(() => {
    if (!savedStatementsOpen) return undefined;
    function handleOutsideClick(event) {
      if (!actionsRef.current?.contains(event.target)) setSavedStatementsOpen(false);
    }
    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        setSavedStatementsOpen(false);
        actionsTriggerRef.current?.focus();
      }
    }
    document.addEventListener('mousedown', handleOutsideClick);
    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('mousedown', handleOutsideClick);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [savedStatementsOpen]);

  useEffect(() => {
    if (!pickerOpen) return undefined;
    function handlePointerDown(event) {
      const target = event.target;
      if (pickerRef.current?.contains(target)) return;
      if (pickerPopRef.current?.contains(target)) return;
      setPickerOpen(false);
    }
    function handleKeyDown(event) {
      if (event.key === 'Escape') setPickerOpen(false);
    }
    document.addEventListener('mousedown', handlePointerDown);
    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('mousedown', handlePointerDown);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [pickerOpen]);

  useLayoutEffect(() => {
    if (!pickerOpen) {
      setPickerPopStyle(null);
      return undefined;
    }
    function syncPickerPosition() {
      const btn = pickerBtnRef.current;
      if (!btn) return;
      const margin = 12;
      const rect = btn.getBoundingClientRect();
      const top = Math.round(rect.bottom + 8);
      const maxHeight = Math.max(220, window.innerHeight - top - margin);
      const panelWidth = Math.min(
        420,
        Math.max(rect.width, 320),
        window.innerWidth - margin * 2,
      );
      let left = rect.left;
      left = Math.max(margin, Math.min(left, window.innerWidth - panelWidth - margin));
      setPickerPopStyle({
        top: `${top}px`,
        left: `${left}px`,
        width: `${panelWidth}px`,
        maxHeight: `${maxHeight}px`,
      });
    }
    syncPickerPosition();
    window.addEventListener('resize', syncPickerPosition);
    window.addEventListener('scroll', syncPickerPosition, true);
    return () => {
      window.removeEventListener('resize', syncPickerPosition);
      window.removeEventListener('scroll', syncPickerPosition, true);
    };
  }, [pickerOpen]);

  const customers = data?.customers || [];
  const statement = savedStatement || data?.statement || null;
  const urls = data?.urls || {};
  const module = data?.module || 'sales';
  const selectedCustomers = statement?.selected_customers || [];
  const transactions = statement?.transactions || [];
  const hasStatement = (statement?.selected_customers || []).length > 0;

  const selectedCustomerMap = useMemo(() => {
    const map = new Map();
    customers.forEach((c) => map.set(Number(c.id), c));
    return map;
  }, [customers]);
  const selectedPickerCustomers = customerIds
    .map((id) => selectedCustomerMap.get(id))
    .filter(Boolean);

  const filteredCustomers = useMemo(() => {
    const q = pickerSearch.trim().toLowerCase();
    if (!q) return customers;
    return customers.filter((c) => (
      (c.company_name || '').toLowerCase().includes(q)
      || (c.customer_code || '').toLowerCase().includes(q)
    ));
  }, [customers, pickerSearch]);

  const canExportExcel = customerIds.length > 0;

  const exportPdfUrl = useMemo(() => {
    if (customerIds.length !== 1) return '';
    const params = new URLSearchParams();
    params.set('module', module);
    params.append('customer_ids[]', String(customerIds[0]));
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);
    params.set('download', 'pdf');
    return `${window.location.pathname}?${params.toString()}`;
  }, [customerIds, dateFrom, dateTo, module]);

  function toggleCustomer(id) {
    setCustomerIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return Array.from(next);
    });
  }

  function applyFilters() {
    const params = new URLSearchParams();
    params.set('module', module);
    customerIds.forEach((id) => {
      params.append('customer_ids[]', String(id));
    });
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);
    const qs = params.toString();
    const nextUrl = `${window.location.pathname}${qs ? `?${qs}` : ''}`;
    window.history.replaceState({}, '', nextUrl);
    loadData(params);
  }

  async function handleView(event) {
    event?.preventDefault?.();
    if (customerIds.length) {
      setSubmitting(true);
      setError('');
      try {
        await saveStatementHistory({
          customer_ids: customerIds,
          date_from: dateFrom,
          date_to: dateTo,
        }, data?.csrf_token);
        await loadHistory(1);
        setSavedStatement(null);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to save statement.');
        setSubmitting(false);
        return;
      }
    }
    applyFilters();
  }

  async function openSavedStatement(id) {
    setOpeningHistory(true);
    setHistoryError('');
    try {
      const result = await fetchStatementHistory(1, id);
      setSavedStatement(result.statement);
    } catch (err) {
      setHistoryError(err instanceof Error ? err.message : 'Failed to open statement.');
    } finally {
      setOpeningHistory(false);
    }
  }

  function handleReset() {
    setActionsOpen(false);
    setSavedStatement(null);
    setCustomerIds([]);
    setDateFrom('');
    setDateTo('');
    const params = new URLSearchParams();
    params.set('module', module);
    window.history.replaceState({}, '', `${window.location.pathname}?${params.toString()}`);
    loadData(params);
  }

  function selectQuickRange(range) {
    setQuickRange(range);
    if (range === 'custom') {
      dateFromRef.current?.focus();
      return;
    }
    const [from, to] = quickRangeDates(range);
    setDateFrom(from);
    setDateTo(to);
  }

  const excelBusy = excelDownload.status === 'downloading';
  const excelDone = excelDownload.status === 'success';

  function getExcelButtonLabel() {
    if (excelBusy) return 'Downloading...';
    if (excelDone) return 'Downloaded';
    return 'Excel';
  }

  async function handleExcelDownload(event) {
    event?.preventDefault?.();
    if (!canExportExcel || excelBusy) return;

    if (excelResetTimerRef.current) {
      window.clearTimeout(excelResetTimerRef.current);
      excelResetTimerRef.current = null;
    }

    setExcelDownload({ status: 'downloading', message: 'Preparing Excel file...' });
    setError('');

    try {
      const filename = await downloadStatementExcel({
        companyName: data?.company_name,
        filters: {
          customer_ids: customerIds,
          date_from: dateFrom,
          date_to: dateTo,
        },
        module,
      });
      setExcelDownload({
        status: 'success',
        message: `Downloaded ${filename}`,
      });
      excelResetTimerRef.current = window.setTimeout(() => {
        setExcelDownload({ status: 'idle', message: '' });
        excelResetTimerRef.current = null;
      }, 2800);
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Excel download failed.';
      setExcelDownload({ status: 'error', message });
      setError(message);
      excelResetTimerRef.current = window.setTimeout(() => {
        setExcelDownload({ status: 'idle', message: '' });
        excelResetTimerRef.current = null;
      }, 4000);
    }
  }

  function getPickerButtonLabel() {
    if (customerIds.length === 0) return 'Search customer...';
    if (customerIds.length === 1) {
      const customer = selectedCustomerMap.get(customerIds[0]);
      return customer?.company_name || '1 customer selected';
    }
    return `${customerIds.length} customers selected`;
  }

  function renderCustomerPickerPop() {
    if (!pickerOpen || !pickerPopStyle) return null;
    const panel = (
      <div
        ref={pickerPopRef}
        className="stmt-picker-pop stmt-picker-pop--fixed is-open"
        style={pickerPopStyle}
        role="dialog"
        aria-label="Select customers"
      >
        <div className="stmt-picker-pop-head">
          <div className="stmt-picker-pop-head-copy">
            <h3 className="stmt-picker-pop-title">Select customers</h3>
          </div>
          {customerIds.length > 0 && (
            <span className="stmt-picker-count">{customerIds.length} selected</span>
          )}
          <button
            type="button"
            className="stmt-picker-pop-close"
            onClick={() => setPickerOpen(false)}
            aria-label="Close customer picker"
          >
            <X size={16} aria-hidden="true" />
          </button>
        </div>

        <div className="stmt-picker-pop-search">
          <Search size={16} aria-hidden="true" />
          <input
            type="search"
            placeholder="Search by name or code..."
            value={pickerSearch}
            onChange={(e) => setPickerSearch(e.target.value)}
            autoFocus
          />
        </div>

        <div className="stmt-picker-list">
          {filteredCustomers.length === 0 && (
            <p className="stmt-picker-empty">No customers match your search.</p>
          )}
          {filteredCustomers.map((c) => {
            const isSelected = customerIds.includes(Number(c.id));
            return (
              <label
                key={c.id}
                className={`stmt-picker-item${isSelected ? ' is-selected' : ''}`}
              >
                <input
                  type="checkbox"
                  checked={isSelected}
                  onChange={() => toggleCustomer(Number(c.id))}
                />
                <span className="stmt-picker-item-copy">
                  <span className="stmt-picker-item-name">{c.company_name}</span>
                  <small>{c.customer_code}</small>
                </span>
              </label>
            );
          })}
        </div>

        <div className="stmt-picker-pop-foot">
          {urls.customer_catalogue && (
            <a href={urls.customer_catalogue} className="stmt-link-catalogue">Customer catalogue</a>
          )}
          <button
            type="button"
            className="exp-desk-btn exp-desk-btn-ghost"
            onClick={() => setCustomerIds([])}
            disabled={customerIds.length === 0}
          >
            Clear all
          </button>
          <button type="button" className="exp-desk-btn exp-desk-btn-primary" onClick={() => setPickerOpen(false)}>
            Done
          </button>
        </div>
      </div>
    );
    return createPortal(panel, document.body);
  }

  async function handleDeleteStatement(statementId) {
    setDeleteConfirm(null);
    setDeleting(true);

    try {
      const apiBase = typeof window !== 'undefined' && window.__STATEMENT_API_BASE__
        ? String(window.__STATEMENT_API_BASE__).replace(/\/$/, '')
        : './api';

      const response = await fetch(`${apiBase}/delete-statement.php`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id: statementId,
          csrf_token: data?.csrf_token,
        }),
      });

      if (!response.ok) {
        throw new Error(`Delete failed: ${response.statusText}`);
      }

      const text = await response.text();
      let result;
      try {
        result = JSON.parse(text);
      } catch {
        throw new Error('Invalid API response');
      }

      if (!result.success) {
        throw new Error(result.error || 'Delete failed');
      }

      await loadHistory(1);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to delete statement.');
    } finally {
      setDeleting(false);
    }
  }

  if (loading && !data) {
    return (
      <div className="exp-desk-page exp-desk-boot-loading" role="status">
        <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
        <span>Loading customer statement...</span>
      </div>
    );
  }

  const savedStatementsPanel = (
    <section className="stmt-actions-history" aria-labelledby="stmt-saved-statements-title">
      <div className="stmt-results-head">
        <div className="stmt-results-heading">
          <span className="stmt-results-heading-icon"><ReceiptText size={22} aria-hidden="true" /></span>
          <div>
            <h2 className="stmt-section-title" id="stmt-saved-statements-title">Saved Statements</h2>
            <p className="stmt-section-sub">Previously generated customer statements</p>
          </div>
        </div>
      </div>
      {historyError && (
        <div className="exp-desk-flash exp-desk-flash-error" role="alert">
          {historyError}{' '}
          <button type="button" onClick={() => loadHistory(history.page)}>Retry</button>
        </div>
      )}
      <div className="stmt-results-scroll">
        <table className="stmt-results-table">
          <thead>
            <tr>
              <th scope="col">#</th>
              <th scope="col">Customer</th>
              <th scope="col">Statement Period</th>
              <th scope="col">Total Sales</th>
              <th scope="col">Total Payments</th>
              <th scope="col">Balance</th>
              <th scope="col">Generated On</th>
              <th scope="col" className="is-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            {history.results.map((entry, index) => (
              <tr key={entry.id}>
                <td>{(history.page - 1) * history.page_size + index + 1}</td>
                <td>
                  <button
                    className="stmt-results-customer"
                    type="button"
                    disabled={openingHistory}
                    onClick={() => {
                      setSavedStatementsOpen(false);
                      openSavedStatement(entry.id);
                    }}
                  >
                    <span className="stmt-results-customer-icon"><UserRound size={15} aria-hidden="true" /></span>
                    {entry.customer_name}
                  </button>
                </td>
                <td>{formatHistoryDate(entry.date_from)} - {formatHistoryDate(entry.date_to)}</td>
                <td>{formatMoney(entry.total_sales)}</td>
                <td>{formatMoney(entry.total_payments)}</td>
                <td><span className="stmt-results-balance">{formatMoney(entry.balance)}</span></td>
                <td><span className="stmt-results-date"><CalendarDays size={15} aria-hidden="true" />{formatHistoryDate(entry.generated_at)}</span></td>
                <td className="stmt-results-actions">
                  <div className="stmt-row-menu-wrap">
                    <button
                      type="button"
                      className="stmt-row-menu-btn"
                      aria-haspopup="menu"
                      aria-label={`Actions for ${entry.customer_name}`}
                      onClick={() => setRowMenuOpen(rowMenuOpen === `result-${entry.id}` ? null : `result-${entry.id}`)}
                    >
                      <MoreVertical size={16} aria-hidden="true" />
                    </button>
                    {rowMenuOpen === `result-${entry.id}` && (
                      <div className="stmt-row-menu" role="menu">
                        <button
                          type="button"
                          className="stmt-row-menu-item stmt-row-menu-item--danger"
                          role="menuitem"
                          disabled={deleting}
                          onClick={() => {
                            setDeleteConfirm(`statement-${entry.id}`);
                            setRowMenuOpen(null);
                            setSavedStatementsOpen(false);
                          }}
                        >
                          <Trash2 size={14} aria-hidden="true" />
                          Delete
                        </button>
                      </div>
                    )}
                  </div>
                </td>
              </tr>
            ))}
            {!historyLoading && !historyError && history.results.length === 0 && (
              <tr><td colSpan={8}>No saved statements yet. Select customers and click View Statement.</td></tr>
            )}
            {historyLoading && <tr><td colSpan={8}>Loading statement history...</td></tr>}
          </tbody>
        </table>
      </div>
      <div className="stmt-results-footer">
        <span>
          Showing {history.total ? (history.page - 1) * history.page_size + 1 : 0} to{' '}
          {Math.min(history.page * history.page_size, history.total)} of {history.total} results
        </span>
        <div className="stmt-results-pagination" aria-label="Statement history pages">
          <button type="button" aria-label="Previous page" disabled={historyLoading || history.page <= 1} onClick={() => loadHistory(history.page - 1)}><ChevronLeft size={16} /></button>
          <span aria-current="page">{history.page}</span>
          <button type="button" aria-label="Next page" disabled={historyLoading || history.page * history.page_size >= history.total} onClick={() => loadHistory(history.page + 1)}><ChevronRight size={16} /></button>
        </div>
      </div>
    </section>
  );

  const actionsMenu = (
    <div className={`stmt-actions${actionsOpen || savedStatementsOpen ? ' is-open' : ''}`} ref={actionsRef}>
      <button
        type="button"
        ref={actionsTriggerRef}
        className="stmt-actions-trigger"
        aria-haspopup="menu"
        aria-expanded={actionsOpen}
        aria-controls="statement-actions-menu"
        onClick={() => {
          setSavedStatementsOpen(false);
          setActionsOpen((open) => !open);
        }}
        onKeyDown={(event) => {
          if (event.key !== 'ArrowDown') return;
          event.preventDefault();
          setActionsOpen(true);
          window.requestAnimationFrame(() => actionsRef.current?.querySelector('[role="menuitem"]:not(:disabled)')?.focus());
        }}
      >
        <Zap size={16} strokeWidth={2.4} className="stmt-actions-bolt" aria-hidden="true" />
        Actions
        <ChevronDown size={16} className="stmt-actions-caret" aria-hidden="true" />
      </button>
      {actionsOpen && (
        <div
          className="stmt-actions-menu"
          id="statement-actions-menu"
          role="menu"
          aria-label="Statement actions"
          onKeyDown={(event) => {
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
            const items = Array.from(event.currentTarget.querySelectorAll('[role="menuitem"]:not(:disabled)'));
            if (!items.length) return;
            event.preventDefault();
            const current = items.indexOf(document.activeElement);
            let next = 0;
            if (event.key === 'End') next = items.length - 1;
            else if (event.key === 'ArrowDown') next = (current + 1) % items.length;
            else if (event.key === 'ArrowUp') next = current <= 0 ? items.length - 1 : current - 1;
            items[next].focus();
          }}
        >
          {canExportExcel && (
            <button
              type="button"
              className="stmt-actions-item"
              role="menuitem"
              disabled={excelBusy}
              aria-busy={excelBusy}
              onClick={(event) => {
                setActionsOpen(false);
                handleExcelDownload(event);
              }}
            >
              <span className="stmt-actions-icon stmt-actions-icon--green" aria-hidden="true">
                {excelDone ? <Check size={15} /> : (
                  urls.excel_icon ? <ExcelExportIcon src={urls.excel_icon} busy={excelBusy} /> : <FileSpreadsheet size={15} />
                )}
              </span>
              {getExcelButtonLabel()}
            </button>
          )}
          {exportPdfUrl && (
            <a className="stmt-actions-item" role="menuitem" href={exportPdfUrl} onClick={() => setActionsOpen(false)}>
              <span className="stmt-actions-icon stmt-actions-icon--violet" aria-hidden="true"><FileText size={15} /></span>
              PDF
            </a>
          )}
          {urls.whatsapp && (
            <a className="stmt-actions-item" role="menuitem" href={urls.whatsapp} target="_blank" rel="noopener noreferrer" onClick={() => setActionsOpen(false)}>
              <span className="stmt-actions-icon stmt-actions-icon--green" aria-hidden="true"><MessageCircle size={15} /></span>
              WhatsApp
            </a>
          )}
          {urls.mailto && (
            <a className="stmt-actions-item" role="menuitem" href={urls.mailto} onClick={() => setActionsOpen(false)}>
              <span className="stmt-actions-icon stmt-actions-icon--slate" aria-hidden="true"><Mail size={15} /></span>
              Email
            </a>
          )}
          <button
            type="button"
            className="stmt-actions-item"
            role="menuitem"
            aria-haspopup="dialog"
            aria-controls="saved-statements-popover"
            onClick={() => {
              setActionsOpen(false);
              setSavedStatementsOpen(true);
            }}
          >
            <span className="stmt-actions-icon stmt-actions-icon--slate" aria-hidden="true"><ReceiptText size={15} /></span>
            Saved Statements
          </button>
        </div>
      )}
      {savedStatementsOpen && (
        <div
          className="stmt-saved-statements-popover"
          id="saved-statements-popover"
          role="dialog"
          aria-label="Saved statements"
        >
          {savedStatementsPanel}
        </div>
      )}
    </div>
  );

  return (
    <div className="exp-desk-page">
      {renderCustomerPickerPop()}
      {error && (
        <div className="exp-desk-flash exp-desk-flash-error" role="alert">{error}</div>
      )}
      {excelDownload.message && excelDownload.status !== 'error' && (
        <div className={`stmt-excel-live stmt-excel-live--${excelDownload.status}`} role="status" aria-live="polite">
          {excelBusy && <Loader2 className="stmt-excel-live-spinner" size={16} aria-hidden="true" />}
          {excelDone && <Check size={16} aria-hidden="true" />}
          <span>{excelDownload.message}</span>
          {excelBusy && <span className="stmt-excel-live-bar" aria-hidden="true" />}
        </div>
      )}

      {headerActionsEl ? createPortal(actionsMenu, headerActionsEl) : actionsMenu}

      <section className="stmt-section stmt-filter-panel">
        <div className="stmt-section-head">
          <div className="stmt-filter-heading">
            <Filter size={22} aria-hidden="true" />
            <div>
              <h2 className="stmt-section-title">Select Customer</h2>
            </div>
          </div>
          <button type="button" className="stmt-filter-reset" onClick={handleReset} disabled={submitting} aria-label="Reset filters" title="Reset">
            <RotateCcw size={16} aria-hidden="true" />
          </button>
        </div>
        <div className="stmt-section-body">
          <form className="stmt-filters-grid" onSubmit={handleView}>
            <div className="stmt-filters-row">
              <div className="stmt-field stmt-field--customer">
                <label className="stmt-visually-hidden" htmlFor="stmt-customer-picker">Select customer</label>
                <div className="stmt-picker" ref={pickerRef}>
                  <button
                    type="button"
                    id="stmt-customer-picker"
                    ref={pickerBtnRef}
                    className={`stmt-picker-btn${pickerOpen ? ' is-open' : ''}${customerIds.length ? ' has-value' : ''}`}
                    onClick={() => setPickerOpen((open) => !open)}
                    aria-expanded={pickerOpen}
                    aria-haspopup="dialog"
                  >
                    <Search size={17} className="stmt-filter-input-icon" aria-hidden="true" />
                    <span className="stmt-picker-btn-label">{getPickerButtonLabel()}</span>
                    <ChevronDown size={16} className="stmt-picker-btn-chevron" aria-hidden="true" />
                  </button>
                </div>
              </div>

              <div className="stmt-field">
                <label htmlFor="stmt-date-from">From</label>
                <div className="stmt-date-input">
                  <CalendarDays size={16} aria-hidden="true" />
                  <input id="stmt-date-from" ref={dateFromRef} type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setQuickRange('custom'); }} />
                </div>
              </div>

              <div className="stmt-field">
                <label htmlFor="stmt-date-to">To</label>
                <div className="stmt-date-input">
                  <CalendarDays size={16} aria-hidden="true" />
                  <input id="stmt-date-to" type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setQuickRange('custom'); }} />
                </div>
              </div>

              <div className="stmt-field stmt-field--range">
                <div className="stmt-quick-ranges" role="group" aria-label="Quick range">
                  {[
                    ['this_month', 'This Month'],
                    ['last_month', 'Last Month'],
                    ['last_3_months', 'Last 3 Months'],
                    ['custom', 'Custom'],
                  ].map(([range, label]) => (
                    <button key={range} type="button" aria-pressed={quickRange === range} onClick={() => selectQuickRange(range)}>
                      {label}
                    </button>
                  ))}
                </div>
              </div>
              <div className="stmt-filters-actions">
                <button type="submit" className="exp-desk-btn exp-desk-btn-primary stmt-filter-submit" disabled={submitting}>
                  {submitting ? <Loader2 size={16} className="exp-create-spinner" /> : <Search size={16} aria-hidden="true" />}
                  View Statement
                </button>
              </div>
            </div>
          </form>
        </div>
      </section>

      {selectedPickerCustomers.length > 0 && (
        <section className="stmt-section stmt-selected-customer-preview" aria-label="Selected customer details" aria-live="polite">
          <div className="stmt-selected-customer-icon">
            <Building2 size={26} aria-hidden="true" />
          </div>
          {selectedPickerCustomers.length === 1 ? (
            <>
              <div className="stmt-selected-customer-identity">
                <h2>{selectedPickerCustomers[0].company_name}</h2>
                <p>Customer Code: {selectedPickerCustomers[0].customer_code}</p>
              </div>
              <div className="stmt-selected-customer-contact">
                {selectedPickerCustomers[0].phone && (
                  <div>
                    <Phone size={15} aria-hidden="true" />
                    <span>{selectedPickerCustomers[0].phone}</span>
                  </div>
                )}
                {selectedPickerCustomers[0].email && (
                  <div>
                    <Mail size={15} aria-hidden="true" />
                    <span>{selectedPickerCustomers[0].email}</span>
                  </div>
                )}
              </div>
              {selectedPickerCustomers[0].address && (
                <div className="stmt-selected-customer-address">
                  <span>Address</span>
                  <strong>{selectedPickerCustomers[0].address}</strong>
                </div>
              )}
            </>
          ) : (
            <h2 className="stmt-selected-customer-count">Selected customers ({selectedPickerCustomers.length})</h2>
          )}
        </section>
      )}

      {hasStatement && (
        <>
          <section className="stmt-section stmt-customer-details-section" ref={savedDetailsRef}>
            <div className="stmt-customer-details-container">
              {/* Summary Cards */}
              <div className="stmt-summary-actions-row">
                <div className="stmt-summary-cards">
                  <div className="stmt-summary-card stmt-summary-card--sales">
                    <div className="stmt-summary-icon"><ShoppingCart size={21} aria-hidden="true" /></div>
                    <div className="stmt-summary-content">
                      <span className="stmt-summary-label">Total Sales</span>
                      <strong className="stmt-summary-value">TZS {formatMoney(statement.grand_total)}</strong>
                    </div>
                  </div>
                  <div className="stmt-summary-card stmt-summary-card--payments">
                    <div className="stmt-summary-icon"><CreditCard size={21} aria-hidden="true" /></div>
                    <div className="stmt-summary-content">
                      <span className="stmt-summary-label">Total Payments</span>
                      <strong className="stmt-summary-value">TZS {formatMoney(statement.sum_paid)}</strong>
                    </div>
                  </div>
                  <div className="stmt-summary-card stmt-summary-card--balance">
                    <div className="stmt-summary-icon"><Scale size={21} aria-hidden="true" /></div>
                    <div className="stmt-summary-content">
                      <span className="stmt-summary-label">Outstanding Balance</span>
                      <strong className="stmt-summary-value">TZS {formatMoney(statement.closing_balance)}</strong>
                    </div>
                  </div>
                </div>

                <div className="stmt-details-actions">
                  <button type="button" className="exp-desk-btn exp-desk-btn-secondary" onClick={() => window.print()}>
                    <FileText size={16} aria-hidden="true" /> Print Statement
                  </button>
                  {exportPdfUrl && (
                    <a href={exportPdfUrl} className="exp-desk-btn exp-desk-btn-primary">
                      <Download size={16} aria-hidden="true" /> Export PDF
                    </a>
                  )}
                </div>
              </div>

              {/* Period Info */}
              <p className="stmt-period-info">
                Period <strong>{statement.date_from}</strong> to <strong>{statement.date_to}</strong>
              </p>
            </div>
          </section>

          <div className="stmt-statement-results-title">
            <span className="stmt-results-heading-icon"><ReceiptText size={22} aria-hidden="true" /></span>
            <div>
              <h2 className="stmt-section-title">Statement Results</h2>
              <p className="stmt-section-sub">Sales and payment details for the selected period</p>
            </div>
          </div>

          <section className="stmt-section stmt-transaction-section">
            <div className="stmt-table-wrap stmt-transaction-scroll">
              <table className="stmt-table stmt-transaction-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Description</th>
                    <th>Reference</th>
                    <th className="is-right">Total Sales</th>
                    <th className="is-right">Total Payments</th>
                    <th className="is-right">Balance</th>
                    <th>Generated On</th>
                    <th className="is-center">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {transactions.map((row, index) => (
                    <tr key={row.id}>
                      <td>{index + 1}</td>
                      <td>{formatStatementDate(row.transaction_date)}</td>
                      <td>{row.description}</td>
                      <td><InvoiceReference row={row} module={module} /></td>
                      <td className="is-right">{formatMoney(row.total_sales)}</td>
                      <td className="is-right">{formatMoney(row.total_payments)}</td>
                      <td className="is-right">
                        <span className={`stmt-ledger-balance ${Number(row.total_payments) > 0 ? 'is-payment' : 'is-sale'}`}>
                          {formatMoney(row.balance)}
                        </span>
                      </td>
                      <td>{formatHistoryDate(row.generated_on)}</td>
                      <td className="stmt-results-actions">
                        <div className="stmt-row-menu-wrap">
                          <button
                            type="button"
                            className="stmt-row-menu-btn"
                            aria-haspopup="menu"
                            aria-label={`Actions for ${row.description}`}
                            onClick={() => setRowMenuOpen(rowMenuOpen === `transaction-${row.id}` ? null : `transaction-${row.id}`)}
                          >
                            <MoreVertical size={16} aria-hidden="true" />
                          </button>
                          {rowMenuOpen === `transaction-${row.id}` && (
                            <div className="stmt-row-menu" role="menu">
                              <a
                                href={`../modules/sales/invoices/view.php?id=${encodeURIComponent(row.invoice_id)}&module=${encodeURIComponent(module)}`}
                                className="stmt-row-menu-item"
                                role="menuitem"
                                onClick={() => setRowMenuOpen(null)}
                              >
                                <FileText size={14} aria-hidden="true" />
                                View invoice
                              </a>
                            </div>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                  {transactions.length === 0 && (
                    <tr>
                      <td colSpan={9}>No transactions found for this statement period.</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
            <div className="stmt-mobile-transaction-list" role="list" aria-label="Statement entries">
              {transactions.map((row) => {
                const isPayment = Number(row.total_payments) > 0;
                const amount = isPayment ? row.total_payments : row.total_sales;
                return (
                  <article className="stmt-mobile-transaction-card" role="listitem" key={`mobile-${row.id}`}>
                    <div className="stmt-mobile-transaction-card-top">
                      <div className="stmt-mobile-transaction-main">
                        <span className="stmt-mobile-transaction-icon" aria-hidden="true">
                          <ReceiptText size={17} />
                        </span>
                        <div className="stmt-mobile-transaction-copy">
                          <span className="stmt-mobile-transaction-description" title={row.description}>
                            {row.description || 'Statement entry'}
                          </span>
                          <span className="stmt-mobile-transaction-reference">
                            <InvoiceReference row={row} module={module} />
                          </span>
                        </div>
                      </div>
                      <div className="stmt-mobile-transaction-aside">
                        <span className="stmt-mobile-transaction-amount">{formatMoney(amount)}</span>
                        <span className={`stmt-mobile-transaction-kind${isPayment ? ' is-payment' : ' is-sale'}`}>
                          {isPayment ? 'Payment' : 'Sale'}
                        </span>
                      </div>
                    </div>
                    <div className="stmt-mobile-transaction-card-foot">
                      <div className="stmt-mobile-transaction-fact">
                        <CalendarDays size={14} aria-hidden="true" />
                        <span className="stmt-mobile-transaction-fact-body">
                          <span className="stmt-mobile-transaction-fact-label">Date</span>
                          <span className="stmt-mobile-transaction-fact-value">{formatStatementDate(row.transaction_date)}</span>
                        </span>
                      </div>
                      <div className="stmt-mobile-transaction-fact">
                        <CalendarDays size={14} aria-hidden="true" />
                        <span className="stmt-mobile-transaction-fact-body">
                          <span className="stmt-mobile-transaction-fact-label">Generated</span>
                          <span className="stmt-mobile-transaction-fact-value">{formatHistoryDate(row.generated_on)}</span>
                        </span>
                      </div>
                      <div className="stmt-mobile-transaction-fact">
                        <Scale size={14} aria-hidden="true" />
                        <span className="stmt-mobile-transaction-fact-body">
                          <span className="stmt-mobile-transaction-fact-label">Balance</span>
                          <span className="stmt-mobile-transaction-fact-value">{formatMoney(row.balance)}</span>
                        </span>
                      </div>
                      <div className="stmt-mobile-transaction-menu">
                        <div className="stmt-row-menu-wrap">
                          <button
                            type="button"
                            className="stmt-row-menu-btn"
                            aria-haspopup="menu"
                            aria-label={`Actions for ${row.description}`}
                            onClick={() => setRowMenuOpen(rowMenuOpen === `transaction-${row.id}` ? null : `transaction-${row.id}`)}
                          >
                            <MoreVertical size={16} aria-hidden="true" />
                          </button>
                          {rowMenuOpen === `transaction-${row.id}` && (
                            <div className="stmt-row-menu" role="menu">
                              <a
                                href={`../modules/sales/invoices/view.php?id=${encodeURIComponent(row.invoice_id)}&module=${encodeURIComponent(module)}`}
                                className="stmt-row-menu-item"
                                role="menuitem"
                                onClick={() => setRowMenuOpen(null)}
                              >
                                <FileText size={14} aria-hidden="true" />
                                View invoice
                              </a>
                            </div>
                          )}
                        </div>
                      </div>
                    </div>
                  </article>
                );
              })}
              {transactions.length === 0 && (
                <div className="stmt-mobile-transaction-empty" role="status">
                  No transactions found for this statement period.
                </div>
              )}
            </div>
          </section>
        </>
      )}

      {deleteConfirm && createPortal(
        <div className="stmt-delete-confirm-overlay" onClick={() => !deleting && setDeleteConfirm(null)}>
          <div className="stmt-delete-confirm-dialog" onClick={(e) => e.stopPropagation()}>
            <h3 className="stmt-delete-confirm-title">Delete statement?</h3>
            <p className="stmt-delete-confirm-message">
              This will permanently delete this saved statement from the history.
            </p>
            <div className="stmt-delete-confirm-actions">
              <button
                type="button"
                className="exp-desk-btn exp-desk-btn-ghost"
                onClick={() => setDeleteConfirm(null)}
                disabled={deleting}
              >
                Cancel
              </button>
              <button
                type="button"
                className="exp-desk-btn exp-desk-btn-danger"
                onClick={() => {
                  const statementId = deleteConfirm.replace('statement-', '');
                  handleDeleteStatement(Number(statementId));
                }}
                disabled={deleting}
              >
                {deleting ? 'Deleting...' : 'Delete'}
              </button>
            </div>
          </div>
        </div>,
        document.body,
      )}

    </div>
  );
}
