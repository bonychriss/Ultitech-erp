import {
  AlertTriangle,
  ArrowDown,
  ArrowRight,
  ArrowUp,
  ChevronDown,
  ChevronRight,
  CircleCheck,
  Clock,
  DollarSign,
  Download,
  FileText,
  Handshake,
  Loader2,
  Plus,
  Receipt,
  ShoppingCart,
  Sparkles,
  Zap,
} from 'lucide-react';
import { createPortal } from 'react-dom';
import { useCallback, useEffect, useRef, useState } from 'react';
import DownloadPdfModal from '../components/DownloadPdfModal.jsx';
import EmptyListPrompt from '../components/EmptyListPrompt.jsx';
import { fetchMySalesInit } from '../api/mySalesDesk.js';
import {
  formatMoney,
  formatNumber,
  formatPercent,
  statusLabel,
  timeAgo,
} from '../utils/mySalesFormat.js';

function KpiCard({ icon, iconClass, title, value, subtext, trend, trendClass }) {
  return (
    <div className={`kpi-card kpi-card--tone-${iconClass}`}>
      <div className="kpi-card-header">
        <div className={`kpi-card-icon ${iconClass}`}>{icon}</div>
        <div className="kpi-card-title">{title}</div>
      </div>
      <div className="kpi-card-value">
        {value}
        {trend != null ? (
          <span className={`kpi-trend-indicator ${trendClass || ''}`}>{trend}</span>
        ) : null}
      </div>
      {subtext ? <div className="kpi-card-subtext">{subtext}</div> : null}
    </div>
  );
}

function statusClass(status) {
  const st = String(status || '').toLowerCase();
  if (['paid', 'confirmed', 'delivered', 'completed'].includes(st)) return 'ms-status--green';
  if (['quotation', 'draft', 'sent', 'processing'].includes(st)) return 'ms-status--amber';
  if (['overdue', 'cancelled'].includes(st)) return 'ms-status--red';
  return 'ms-status--blue';
}

function statusIcon(tone) {
  if (tone === 'amber') return Clock;
  if (tone === 'red') return AlertTriangle;
  return CircleCheck;
}

function customerInitials(name) {
  const words = String(name || '').trim().split(/\s+/).filter(Boolean);
  return words.slice(0, 2).map((word) => word[0].toUpperCase()).join('') || '-';
}

function MobileSalesRecords({ rows, currency, recordType }) {
  const recordLabel = recordType === 'invoice' ? 'Sales invoice' : 'Sales order';

  return (
    <div className="ms-mobile-records">
      {rows.map((row) => {
        const tone = statusClass(row.status).replace('ms-status--', '');
        const StatusIcon = statusIcon(tone);
        const fullCustomerName = String(row.customer_name || '').trim();

        return (
          <a
            key={`${row.ref_number}-${row.id}`}
            className="ms-mobile-record"
            href={row.url}
          >
            <div className="ms-mobile-record-main">
              <div className="ms-mobile-record-info">
                <span className="ms-mobile-record-avatar" aria-hidden="true">
                  {customerInitials(fullCustomerName)}
                </span>
                <span className="ms-mobile-record-copy">
                  <span className="ms-mobile-record-reference">{row.ref_number}</span>
                  <span
                    className="ms-mobile-record-customer"
                    title={fullCustomerName || undefined}
                    aria-label={fullCustomerName || '-'}
                  >
                    {displayCustomerName(fullCustomerName)}
                  </span>
                </span>
              </div>
              <span className="ms-mobile-record-summary">
                <span className="ms-mobile-record-amount">{formatMoney(row.total_amount, currency)}</span>
                <span className={`ms-orders-status ms-orders-status--${tone}`}>
                  <StatusIcon size={14} aria-hidden="true" />
                  {statusLabel(row.status)}
                </span>
              </span>
            </div>
            <span className="ms-mobile-record-footer">
              <span>{timeAgo(row.created_at)}</span>
              <span>
                <FileText size={15} aria-hidden="true" />
                {recordLabel}
              </span>
              <ChevronRight size={17} aria-hidden="true" />
            </span>
          </a>
        );
      })}
    </div>
  );
}

function displayCustomerName(name) {
  const fullName = String(name || '').trim();
  const words = fullName.split(/\s+/).filter(Boolean);
  return words.length > 2 ? words.slice(0, 2).join(' ') : fullName || '-';
}

function RecentSalesTable({ rows, currency, recordType, emptyMessage, emptyPrompt = null }) {
  if (!rows?.length) {
    if (emptyPrompt) return emptyPrompt;
    return <div className="ms-empty">{emptyMessage}</div>;
  }

  return (
    <>
      <div className="table-responsive ms-orders-table-scroll">
        <table className="ms-orders-table">
          <thead>
            <tr>
              <th>Reference</th>
              <th>Customer</th>
              <th>Status</th>
              <th className="text-end">Amount</th>
              <th>When</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => {
              const tone = statusClass(row.status).replace('ms-status--', '');
              const StatusIcon = statusIcon(tone);
              const ReferenceIcon = recordType === 'invoice' ? Receipt : FileText;
              const fullCustomerName = String(row.customer_name || '').trim();

              return (
                <tr key={`${row.ref_number}-${row.id}`}>
                  <td>
                    <div className="ms-orders-reference">
                      <span className="ms-orders-reference-icon" aria-hidden="true">
                        <ReferenceIcon size={18} />
                      </span>
                      <a href={row.url}>{row.ref_number}</a>
                    </div>
                  </td>
                  <td>
                    <div className="ms-orders-customer">
                      <span
                        className="ms-orders-customer-name"
                        title={fullCustomerName || undefined}
                        aria-label={fullCustomerName || '-'}
                      >
                        {displayCustomerName(fullCustomerName)}
                      </span>
                    </div>
                  </td>
                  <td>
                    <span className={`ms-orders-status ms-orders-status--${tone}`}>
                      <StatusIcon size={17} aria-hidden="true" />
                      {statusLabel(row.status)}
                    </span>
                  </td>
                  <td className="text-end ms-orders-amount">{formatMoney(row.total_amount, currency)}</td>
                  <td>
                    <div className="ms-orders-when">
                      <span>{timeAgo(row.created_at)}</span>
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      <MobileSalesRecords rows={rows} currency={currency} recordType={recordType} />
    </>
  );
}

export default function MySalesPage() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [downloadOpen, setDownloadOpen] = useState(false);
  const [headerActionsEl, setHeaderActionsEl] = useState(null);
  const [actionsOpen, setActionsOpen] = useState(false);
  const actionsMenuRef = useRef(null);
  const [showAllOrders, setShowAllOrders] = useState(false);
  const [showAllInvoices, setShowAllInvoices] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams(window.location.search);
      const payload = await fetchMySalesInit(params);
      setData(payload);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load My Sales.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    setHeaderActionsEl(document.getElementById('my-sales-header-actions'));
  }, []);

  useEffect(() => {
    if (!actionsOpen) return undefined;

    const onDocumentMouseDown = (event) => {
      if (actionsMenuRef.current && !actionsMenuRef.current.contains(event.target)) {
        setActionsOpen(false);
      }
    };
    const onDocumentKeyDown = (event) => {
      if (event.key === 'Escape') setActionsOpen(false);
    };

    document.addEventListener('mousedown', onDocumentMouseDown);
    document.addEventListener('keydown', onDocumentKeyDown);
    return () => {
      document.removeEventListener('mousedown', onDocumentMouseDown);
      document.removeEventListener('keydown', onDocumentKeyDown);
    };
  }, [actionsOpen]);

  if (loading) {
    return (
      <div className="ms-loading">
        <Loader2 size={20} style={{ animation: 'spin 1s linear infinite' }} />
        <span>Loading My Sales...</span>
      </div>
    );
  }

  if (error) {
    return <div className="alert alert-danger" role="alert">{error}</div>;
  }

  if (!data) return null;

  const currency = data.currency || 'TZS';
  const metrics = data.metrics || {};
  const yearly = data.yearly || {};
  const urls = data.urls || {};
  const salesTrend = Number(metrics.sales_trend) || 0;
  const trendUp = salesTrend >= 0;
  const previewLimit = Number(data.list_preview_limit) || 5;
  const allOrders = data.recent_orders || [];
  const allInvoices = data.recent_invoices || [];
  const visibleOrders = showAllOrders ? allOrders : allOrders.slice(0, previewLimit);
  const visibleInvoices = showAllInvoices ? allInvoices : allInvoices.slice(0, previewLimit);
  const canExpandOrders = allOrders.length > previewLimit;
  const canExpandInvoices = allInvoices.length > previewLimit;
  const isEmptyActivity = allOrders.length === 0
    && allInvoices.length === 0
    && Number(metrics.monthly_sales) === 0;
  const hasHeaderActions = Boolean(urls.download_record || urls.create_quote || urls.create_invoice);

  return (
    <div className={isEmptyActivity ? 'ms-page ms-page--empty' : 'ms-page'}>
      {headerActionsEl && hasHeaderActions ? createPortal(
        <div
          className={`ms-header-actions${actionsOpen ? ' is-open' : ''}${isEmptyActivity ? ' is-nudge' : ''}`}
          ref={actionsMenuRef}
        >
          <button
            type="button"
            className="ms-header-actions-trigger"
            aria-haspopup="menu"
            aria-expanded={actionsOpen}
            aria-controls="my-sales-actions-menu"
            onClick={() => setActionsOpen((open) => !open)}
          >
            <span className="ms-header-actions-bolt" aria-hidden="true">
              <Zap size={16} strokeWidth={2.4} />
            </span>
            Actions
            <ChevronDown size={16} className="ms-header-actions-caret" aria-hidden="true" />
          </button>
          {actionsOpen ? (
            <div className="ms-header-actions-menu" id="my-sales-actions-menu" role="menu">
              {urls.download_record ? (
                <button
                  type="button"
                  className="ms-header-actions-item"
                  role="menuitem"
                  onClick={() => {
                    setActionsOpen(false);
                    setDownloadOpen(true);
                  }}
                >
                  <span className="ms-header-actions-icon ms-header-actions-icon--download" aria-hidden="true">
                    <Download size={15} />
                  </span>
                  Download PDF
                </button>
              ) : null}
              {urls.create_quote ? (
                <a
                  className="ms-header-actions-item"
                  role="menuitem"
                  href={urls.create_quote}
                  onClick={() => setActionsOpen(false)}
                >
                  <span className="ms-header-actions-icon ms-header-actions-icon--quote" aria-hidden="true">
                    <Plus size={15} />
                  </span>
                  New Quote
                </a>
              ) : null}
              {urls.create_invoice ? (
                <a
                  className="ms-header-actions-item"
                  role="menuitem"
                  href={urls.create_invoice}
                  onClick={() => setActionsOpen(false)}
                >
                  <span className="ms-header-actions-icon ms-header-actions-icon--invoice" aria-hidden="true">
                    <Receipt size={15} />
                  </span>
                  New Invoice
                </a>
              ) : null}
            </div>
          ) : null}
        </div>,
        headerActionsEl,
      ) : null}

      {isEmptyActivity ? (
        <div className="ms-empty-hero" role="status">
          <div className="ms-empty-hero-icon" aria-hidden="true">
            <Sparkles size={22} />
          </div>
          <div>
            <p className="ms-empty-hero-title">Start your first sale</p>
            <p className="ms-empty-hero-text">
              Create a quotation or invoice to begin tracking your performance here.
            </p>
          </div>
        </div>
      ) : null}

      <DownloadPdfModal
        open={downloadOpen}
        onClose={() => setDownloadOpen(false)}
        downloadBaseUrl={urls.download_record}
        module={data.module || 'sales'}
        defaults={data.export_defaults}
      />

      <div className={`kpi-overview${isEmptyActivity ? ' ms-kpi-overview--nudge' : ''}`}>
        <KpiCard
          icon={<DollarSign size={14} />}
          iconClass="blue"
          title="My Monthly Sales"
          value={formatMoney(metrics.monthly_sales, currency)}
          trend={trendUp ? <ArrowUp size={12} /> : <ArrowDown size={12} />}
          trendClass={trendUp ? 'text-success' : 'text-danger'}
          subtext={(
            <>
              <span className={trendUp ? 'text-success' : 'text-danger'} style={{ fontWeight: 700 }}>
                {Math.abs(salesTrend)}
                %
              </span>
              {' '}
              from last month
            </>
          )}
        />
        <KpiCard
          icon={<Clock size={14} />}
          iconClass="orange"
          title="My Pending Orders"
          value={formatNumber(metrics.pending_orders)}
          subtext="Draft and quotation"
        />
        <KpiCard
          icon={<AlertTriangle size={14} />}
          iconClass="red"
          title="My Overdue Invoices"
          value={formatNumber(metrics.overdue_invoices)}
          subtext={metrics.overdue_invoices > 0 ? 'Action required' : 'All clear'}
        />
        <KpiCard
          icon={<Handshake size={14} />}
          iconClass="green"
          title="Commission This Month"
          value={formatMoney(metrics.commission_earned, currency)}
          subtext="Your earned commission"
        />
      </div>

      <div className="row g-3 mb-3">
        <div className="col-xl-6">
          <div className={`dash-card ms-list-card${isEmptyActivity ? ' ms-list-card--nudge' : ''}`}>
            <div className="ms-orders-card-header">
              <span className="ms-orders-card-icon" aria-hidden="true">
                <ShoppingCart size={24} strokeWidth={2.2} />
              </span>
              <div className="ms-orders-card-heading">
                <h3 className="ms-orders-card-title">My Recent Orders</h3>
                <p className="ms-orders-card-subtitle">Your latest sales orders at a glance</p>
              </div>
              {canExpandOrders ? (
                <button
                  type="button"
                  className="ms-orders-view-all"
                  onClick={() => setShowAllOrders((value) => !value)}
                >
                  {showAllOrders ? 'Show less' : 'View all'}
                  <ArrowRight size={18} aria-hidden="true" />
                </button>
              ) : null}
            </div>
            <RecentSalesTable
              rows={visibleOrders}
              currency={currency}
              recordType="order"
              emptyMessage="No orders yet."
              emptyPrompt={isEmptyActivity ? (
                <EmptyListPrompt
                  variant="orders"
                  icon={<FileText size={28} strokeWidth={1.6} />}
                  title="No quotations yet"
                  message="Create your first quote to win a customer and track it here."
                  actionLabel="Create Quote"
                  actionHref={urls.create_quote}
                />
              ) : null}
            />
          </div>
        </div>
        <div className="col-xl-6">
          <div className={`dash-card ms-list-card${isEmptyActivity ? ' ms-list-card--nudge ms-list-card--nudge-delayed' : ''}`}>
            <div className="ms-orders-card-header">
              <span className="ms-orders-card-icon" aria-hidden="true">
                <Receipt size={24} strokeWidth={2.2} />
              </span>
              <div className="ms-orders-card-heading">
                <h3 className="ms-orders-card-title">My Recent Invoices</h3>
                <p className="ms-orders-card-subtitle">Your latest sales invoices at a glance</p>
              </div>
              {canExpandInvoices ? (
                <button
                  type="button"
                  className="ms-orders-view-all"
                  onClick={() => setShowAllInvoices((value) => !value)}
                >
                  {showAllInvoices ? 'Show less' : 'View all'}
                  <ArrowRight size={18} aria-hidden="true" />
                </button>
              ) : null}
            </div>
            <RecentSalesTable
              rows={visibleInvoices}
              currency={currency}
              recordType="invoice"
              emptyMessage="No invoices yet."
              emptyPrompt={isEmptyActivity ? (
                <EmptyListPrompt
                  variant="invoices"
                  icon={<Receipt size={28} strokeWidth={1.6} />}
                  title="No invoices yet"
                  message="Turn a quote into revenue - create an invoice when you are ready to bill."
                  actionLabel="Create Invoice"
                  actionHref={urls.create_invoice}
                />
              ) : null}
            />
          </div>
        </div>
      </div>

      <div className="dash-card">
        <h3 className="dash-card-title mb-2">My Yearly Target</h3>
        <div className="d-flex justify-content-between align-items-end mb-1">
          <div>
            <div className="text-muted small">Achieved</div>
            <div className="fw-bold text-primary">{formatMoney(yearly.sales, currency)}</div>
          </div>
          <div className="text-end">
            <div className="text-muted small">Target</div>
            <div className="fw-bold">{formatMoney(yearly.target, currency)}</div>
          </div>
        </div>
        <div className="progress" style={{ height: '8px' }}>
          <div className="progress-bar bg-primary" role="progressbar" style={{ width: `${yearly.percent || 0}%` }} />
        </div>
        <div className="text-center mt-2 small text-muted">
          {formatPercent(yearly.percent)}
          % Completed
        </div>
      </div>
    </div>
  );
}
