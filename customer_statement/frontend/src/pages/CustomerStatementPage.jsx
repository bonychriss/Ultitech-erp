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
  ChevronDown,
  FileText,
  Loader2,
  Mail,
  Search,
  Users,
  X,
} from 'lucide-react';
import { downloadStatementExcel, fetchStatementInit } from '../api/statementDesk';

function formatMoney(value) {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value) || 0);
}

function rowClass(row) {
  if (row.is_opening) return 'stmt-row-opening';
  if (row.is_paid) return 'stmt-row-paid';
  return 'stmt-row-unpaid';
}

function cellOrDash(value) {
  const text = value == null ? '' : String(value).trim();
  return text || '-';
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

function ToolbarButton({ href, onClick, children, className = '', external = false }) {
  if (href) {
    return (
      <a
        href={href}
        className={`exp-desk-btn exp-desk-btn-ghost stmt-toolbar-btn ${className}`}
        target={external ? '_blank' : undefined}
        rel={external ? 'noopener noreferrer' : undefined}
      >
        {children}
      </a>
    );
  }
  return (
    <button type="button" className={`exp-desk-btn exp-desk-btn-ghost stmt-toolbar-btn ${className}`} onClick={onClick}>
      {children}
    </button>
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
  const [pickerOpen, setPickerOpen] = useState(false);
  const [pickerSearch, setPickerSearch] = useState('');
  const [pickerPopStyle, setPickerPopStyle] = useState(null);
  const [excelDownload, setExcelDownload] = useState({ status: 'idle', message: '' });

  const pickerRef = useRef(null);
  const pickerBtnRef = useRef(null);
  const pickerPopRef = useRef(null);
  const excelResetTimerRef = useRef(null);

  const loadData = useCallback(async (params) => {
    setSubmitting(true);
    setError('');
    try {
      const payload = await fetchStatementInit(params);
      setData(payload);
      setCustomerIds((payload.filters?.customer_ids || []).map(Number));
      setDateFrom(payload.filters?.date_from || '');
      setDateTo(payload.filters?.date_to || '');
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

  useEffect(() => () => {
    if (excelResetTimerRef.current) {
      window.clearTimeout(excelResetTimerRef.current);
    }
  }, []);

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
  const statement = data?.statement;
  const urls = data?.urls || {};
  const module = data?.module || 'sales';
  const selectedCustomers = statement?.selected_customers || [];
  const monthly = statement?.monthly || [];
  const hasStatement = (statement?.selected_customers || []).length > 0;

  const selectedCustomerMap = useMemo(() => {
    const map = new Map();
    customers.forEach((c) => map.set(Number(c.id), c));
    return map;
  }, [customers]);

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

  function removeCustomer(id) {
    setCustomerIds((prev) => prev.filter((cid) => cid !== id));
  }

  function applyFilters(extra = {}) {
    const params = new URLSearchParams();
    params.set('module', module);
    customerIds.forEach((id) => {
      params.append('customer_ids[]', String(id));
    });
    if (extra.period) {
      params.set('period', extra.period);
    } else {
      if (dateFrom) params.set('date_from', dateFrom);
      if (dateTo) params.set('date_to', dateTo);
    }
    const qs = params.toString();
    const nextUrl = `${window.location.pathname}${qs ? `?${qs}` : ''}`;
    window.history.replaceState({}, '', nextUrl);
    loadData(params);
  }

  function handleView(event) {
    event?.preventDefault?.();
    applyFilters();
  }

  function handleReset() {
    setCustomerIds([]);
    setDateFrom('');
    setDateTo('');
    const params = new URLSearchParams();
    params.set('module', module);
    window.history.replaceState({}, '', `${window.location.pathname}?${params.toString()}`);
    loadData(params);
  }

  function applyPeriodPreset(preset) {
    applyFilters({ period: preset });
  }

  const excelBusy = excelDownload.status === 'downloading';
  const excelDone = excelDownload.status === 'success';

  function getExcelButtonLabel(compact = false) {
    if (excelBusy) return compact ? '...' : 'Downloading...';
    if (excelDone) return compact ? 'Done' : 'Downloaded';
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

  function renderExcelDownloadButton(variant = 'filter') {
    if (!canExportExcel) return null;
    const compact = variant === 'toolbar';
    const className = [
      compact ? 'exp-desk-btn exp-desk-btn-ghost stmt-toolbar-btn stmt-excel-link' : 'stmt-excel-link',
      excelBusy ? 'is-busy' : '',
      excelDone ? 'is-done' : '',
      excelDownload.status === 'error' ? 'is-error' : '',
    ].filter(Boolean).join(' ');

    return (
      <button
        type="button"
        className={className}
        onClick={handleExcelDownload}
        disabled={excelBusy}
        title="Download Excel"
        aria-busy={excelBusy}
      >
        {excelDone ? (
          <Check className="stmt-excel-done-icon" size={18} aria-hidden="true" />
        ) : (
          <ExcelExportIcon src={urls.excel_icon} busy={excelBusy} />
        )}
        <span className={compact ? 'exp-desk-btn-label-desktop' : undefined}>{getExcelButtonLabel(compact)}</span>
      </button>
    );
  }

  function getPickerButtonLabel() {
    if (customerIds.length === 0) return 'Select customers...';
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
            <p className="stmt-picker-pop-sub">Search and tick one or more customers.</p>
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
                  <strong>{c.company_name}</strong>
                  <small>{c.customer_code}</small>
                </span>
              </label>
            );
          })}
        </div>

        <div className="stmt-picker-pop-foot">
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

  if (loading && !data) {
    return (
      <div className="exp-desk-page exp-desk-boot-loading" role="status">
        <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
        <span>Loading customer statement...</span>
      </div>
    );
  }

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

      <div className="exp-desk-page-header">
        <div className="exp-desk-page-header-search exp-desk-page-header-search--desktop">
          <span className="exp-desk-help-inline">
            <Users size={16} aria-hidden="true" />
            {' '}
            Select customer and date range to view their statement.
          </span>
        </div>
        <div className="exp-desk-page-header-actions">
          <div className="exp-desk-toolbar-secondary stmt-toolbar">
            {renderExcelDownloadButton('toolbar')}
            {exportPdfUrl && (
              <ToolbarButton href={exportPdfUrl}>
                <FileText size={16} aria-hidden="true" />
                <span className="exp-desk-btn-label-desktop">PDF</span>
              </ToolbarButton>
            )}
            {urls.whatsapp && (
              <ToolbarButton href={urls.whatsapp} external className="stmt-btn-wa">
                <i className="fab fa-whatsapp" aria-hidden="true" />
                <span className="exp-desk-btn-label-desktop">WhatsApp</span>
              </ToolbarButton>
            )}
            {urls.mailto && (
              <ToolbarButton href={urls.mailto}>
                <Mail size={16} aria-hidden="true" />
                <span className="exp-desk-btn-label-desktop">Email</span>
              </ToolbarButton>
            )}
          </div>
        </div>
      </div>

      <section className="stmt-section">
        <div className="stmt-section-head">
          <div>
            <h2 className="stmt-section-title">Filters</h2>
            <p className="stmt-section-sub">Choose a customer, then view their statement.</p>
          </div>
        </div>
        <div className="stmt-section-body">
          <form className="stmt-filters-grid" onSubmit={handleView}>
            <div className="stmt-field stmt-field--customer">
              <div className="stmt-field-head">
                <label htmlFor="stmt-customer-picker">Customer</label>
                <a href={urls.customer_catalogue} className="stmt-link-catalogue">Customer catalogue</a>
              </div>
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
                  <span className="stmt-picker-btn-label">{getPickerButtonLabel()}</span>
                  <ChevronDown size={16} className="stmt-picker-btn-chevron" aria-hidden="true" />
                </button>
                {customerIds.length > 0 && (
                  <div className="stmt-pills">
                    {customerIds.map((id) => {
                      const c = selectedCustomerMap.get(id);
                      if (!c) return null;
                      return (
                        <span key={id} className="stmt-pill">
                          <span>{c.company_name}</span>
                          <button type="button" aria-label={`Remove ${c.company_name}`} onClick={() => removeCustomer(id)}>
                            <X size={12} />
                          </button>
                        </span>
                      );
                    })}
                  </div>
                )}
              </div>
            </div>

            <div className="stmt-filters-row">
              <div className="stmt-field">
                <label htmlFor="stmt-date-from">Date from</label>
                <input id="stmt-date-from" type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
              </div>

              <div className="stmt-field">
                <label htmlFor="stmt-date-to">Date to</label>
                <input id="stmt-date-to" type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
              </div>

              <div className="stmt-filters-actions">
                <button type="button" className="exp-desk-btn exp-desk-btn-ghost" onClick={handleReset}>Reset</button>
                {renderExcelDownloadButton('filter')}
                <button type="submit" className="exp-desk-btn exp-desk-btn-primary" disabled={submitting}>
                  {submitting ? <Loader2 size={16} className="exp-create-spinner" /> : null}
                  View
                </button>
              </div>
            </div>

            {customerIds.length > 0 && (
              <div className="stmt-period-links">
                <span className="stmt-period-links-label">Quick period</span>
                <button type="button" onClick={() => applyPeriodPreset('this_month')}>This month</button>
                <button type="button" onClick={() => applyPeriodPreset('this_year')}>This year</button>
                <button type="button" onClick={() => applyPeriodPreset('last_30')}>Last 30 days</button>
              </div>
            )}
          </form>
        </div>
      </section>

      {hasStatement && (
        <>
          <section className="stmt-section">
            <div className="stmt-section-head">
              <div>
                <h2 className="stmt-section-title">
                  {selectedCustomers.length === 1
                    ? selectedCustomers[0].company_name
                    : `Selected customers (${selectedCustomers.length})`}
                </h2>
                <p className="stmt-section-sub">
                  {selectedCustomers.length === 1 && (
                    <span className="stmt-customer-code">{selectedCustomers[0].customer_code}</span>
                  )}
                  Period
                  {' '}
                  <strong>{statement.date_from}</strong>
                  {' '}
                  to
                  {' '}
                  <strong>{statement.date_to}</strong>
                </p>
              </div>
              <div className="stmt-summary-grid">
                <div>
                  Invoiced
                  <strong>{formatMoney(statement.grand_total)}</strong>
                </div>
                <div>
                  Paid
                  <strong>{formatMoney(statement.sum_paid)}</strong>
                </div>
                <div>
                  Opening
                  <strong>{formatMoney(statement.opening_balance)}</strong>
                </div>
                <div>
                  Closing
                  <strong>{formatMoney(statement.closing_balance)}</strong>
                </div>
              </div>
            </div>
          </section>

          {monthly.map((month) => (
            <section key={month.key || month.label} className="stmt-section">
              <div className="stmt-month-title">{month.label}</div>
              <div className="stmt-table-wrap">
                <table className="stmt-table">
                  <thead>
                    <tr>
                      <th>Invoice number</th>
                      <th>Invoice date</th>
                      <th className="is-center">Due (days)</th>
                      <th>Order</th>
                      <th>Status</th>
                      <th className="is-right">Total</th>
                      <th className="is-right">Paid</th>
                      <th className="is-right">Balance</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(month.rows || []).length === 0 && (
                      <tr>
                        <td colSpan={8}>No invoices found in this period.</td>
                      </tr>
                    )}
                    {(month.rows || []).map((row, idx) => (
                      <tr key={`${row.invoice_number || 'row'}-${idx}`} className={rowClass(row)}>
                        <td>{cellOrDash(row.invoice_number)}</td>
                        <td>{cellOrDash(row.invoice_date_fmt)}</td>
                        <td className="is-center">{row.is_opening ? '-' : cellOrDash(row.due_relative)}</td>
                        <td>{row.order_status || ''}</td>
                        <td>{row.payment_status_label || ''}</td>
                        <td className="is-right">{formatMoney(row.total_amount)}</td>
                        <td className="is-right">{formatMoney(row.amount_paid)}</td>
                        <td className="is-right">{formatMoney(row.line_balance)}</td>
                      </tr>
                    ))}
                    <tr className="stmt-total-row">
                      <td colSpan={5}>Month total</td>
                      <td className="is-right">{formatMoney(month.total)}</td>
                      <td className="is-right">{formatMoney(month.total_paid)}</td>
                      <td className="is-right">{formatMoney(month.total_balance)}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </section>
          ))}
        </>
      )}

      {!hasStatement && !loading && customerIds.length === 0 && (
        <div className="exp-desk-empty stmt-empty-enter">
          <div className="stmt-empty-icon exp-desk-kpi-icon exp-desk-kpi-icon--indigo stmt-empty-icon--pulse" aria-hidden="true">
            <Users size={24} />
          </div>
          <p className="exp-desk-empty-title">No customer selected</p>
          <p className="exp-desk-empty-sub">Choose one or more customers and click View.</p>
          <div className="stmt-empty-notice" role="status">
            <span className="stmt-empty-notice-shimmer" aria-hidden="true" />
            <p className="stmt-empty-notice-copy">
              This module is still being developed.
              {' '}
              <strong className="stmt-empty-notice-highlight">
                More features are coming soon
                <span className="stmt-empty-notice-dots" aria-hidden="true">
                  <span />
                  <span />
                  <span />
                </span>
              </strong>
            </p>
          </div>
        </div>
      )}
    </div>
  );
}
