import {
  AlertTriangle,
  ArrowDown,
  ArrowUp,
  Clock,
  DollarSign,
  Download,
  FileText,
  Handshake,
  Loader2,
  Plus,
  Receipt,
  Sparkles,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
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
    <div className="kpi-card">
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

function SalesTable({ rows, currency, emptyMessage, emptyPrompt = null }) {
  if (!rows?.length) {
    if (emptyPrompt) return emptyPrompt;
    return <div className="ms-empty">{emptyMessage}</div>;
  }

  return (
    <div className="table-responsive">
      <table className="ms-desk-table">
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
          {rows.map((row) => (
            <tr key={`${row.ref_number}-${row.id}`}>
              <td><a href={row.url}>{row.ref_number}</a></td>
              <td>{row.customer_name}</td>
              <td><span className={`ms-status ${statusClass(row.status)}`}>{statusLabel(row.status)}</span></td>
              <td className="text-end">{formatMoney(row.total_amount, currency)}</td>
              <td>{timeAgo(row.created_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export default function MySalesPage() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [downloadOpen, setDownloadOpen] = useState(false);
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

  return (
    <div className={isEmptyActivity ? 'ms-page ms-page--empty' : 'ms-page'}>
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

      <p className="ms-period">
        Performance for
        {' '}
        <strong>{data.user?.display_name}</strong>
        {' '}
        -
        {' '}
        {data.period_label}
      </p>

      <div className={`ms-actions${isEmptyActivity ? ' ms-actions--nudge' : ''}`}>
        {urls.download_record ? (
          <button type="button" className="ms-btn ms-btn--download" onClick={() => setDownloadOpen(true)}>
            <Download size={14} />
            Download PDF
          </button>
        ) : null}
        <a href={urls.create_quote} className="ms-btn ms-btn--purple ms-btn--primary-cta">
          <Plus size={14} />
          New Quote
        </a>
        {urls.create_invoice ? (
          <a href={urls.create_invoice} className="ms-btn ms-btn--ghost ms-btn--secondary-cta">
            <Receipt size={14} />
            New Invoice
          </a>
        ) : null}
      </div>

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
            <div className="d-flex justify-content-between align-items-center mb-2">
              <h3 className="dash-card-title mb-0">My Recent Orders</h3>
              {canExpandOrders ? (
                <button
                  type="button"
                  className="ms-card-link ms-card-link-btn"
                  onClick={() => setShowAllOrders((value) => !value)}
                >
                  {showAllOrders ? 'Show less' : 'View all'}
                </button>
              ) : null}
            </div>
            <SalesTable
              rows={visibleOrders}
              currency={currency}
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
            <div className="d-flex justify-content-between align-items-center mb-2">
              <h3 className="dash-card-title mb-0">My Recent Invoices</h3>
              {canExpandInvoices ? (
                <button
                  type="button"
                  className="ms-card-link ms-card-link-btn"
                  onClick={() => setShowAllInvoices((value) => !value)}
                >
                  {showAllInvoices ? 'Show less' : 'View all'}
                </button>
              ) : null}
            </div>
            <SalesTable
              rows={visibleInvoices}
              currency={currency}
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
