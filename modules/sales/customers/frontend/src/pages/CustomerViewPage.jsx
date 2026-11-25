import {
  useEffect,
  useMemo,
  useState,
} from 'react';
import {
  ArrowLeft,
  BarChart3,
  Building2,
  ChevronRight,
  CreditCard,
  Loader2,
  Mail,
  MapPin,
  Pencil,
  Phone,
  Plus,
  Scale,
  ShoppingCart,
  UserRound,
  WalletCards,
} from 'lucide-react';
import { createPortal } from 'react-dom';
import { fetchViewInit } from '../api/catalogueDesk';

const CURRENCY_SYMBOLS = {
  USD: '$',
  TZS: 'TSh ',
  KES: 'KSh ',
  EUR: '\u20ac',
  GBP: '\u00a3',
};

const STATUS_BADGE = {
  green: 'cv-status cv-status--green',
  blue: 'cv-status cv-status--blue',
  red: 'cv-status cv-status--red',
  gray: 'cv-status cv-status--gray',
};

function getDeskCfg() {
  if (typeof window === 'undefined') return {};
  return window.__CUSTOMERS_DESK_CFG__ || window.__CUSTOMER_CATALOGUE_CFG__ || {};
}

function formatCurrency(val) {
  return new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(val || 0);
}

function getCurrencySymbol(curr) {
  const code = (curr || 'TZS').toUpperCase();
  return CURRENCY_SYMBOLS[code] || `${code} `;
}

function formatDate(dateStr) {
  if (!dateStr) return 'N/A';
  const d = new Date(dateStr);
  if (Number.isNaN(d.getTime())) return 'N/A';
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function getStatusColor(status) {
  const s = String(status || '').toLowerCase();
  if (['completed', 'delivered', 'paid', 'approved', 'invoiced'].includes(s)) return 'green';
  if (['pending', 'processing', 'draft', 'quotation'].includes(s)) return 'blue';
  if (['cancelled', 'rejected', 'failed'].includes(s)) return 'red';
  return 'gray';
}

function cellOrDash(value) {
  const text = value == null ? '' : String(value).trim();
  return text || 'N/A';
}

function buildOrderViewUrl(base, orderId, module) {
  const params = new URLSearchParams();
  params.set('id', String(orderId));
  if (module) params.set('module', module);
  const sep = base.includes('?') ? '&' : '?';
  return `${base}${sep}${params.toString()}`;
}

export default function CustomerViewPage() {
  const deskCfg = useMemo(() => getDeskCfg(), []);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [isDesktopHeader, setIsDesktopHeader] = useState(() => (
    typeof window !== 'undefined' && window.matchMedia('(min-width: 768px)').matches
  ));

  useEffect(() => {
    const mediaQuery = window.matchMedia('(min-width: 768px)');
    const syncHeader = () => setIsDesktopHeader(mediaQuery.matches);
    syncHeader();
    mediaQuery.addEventListener('change', syncHeader);
    return () => mediaQuery.removeEventListener('change', syncHeader);
  }, []);

  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams(window.location.search);
    if (!params.get('module') && deskCfg.module) {
      params.set('module', deskCfg.module);
    }
    if (!params.get('id') && deskCfg.customer_id) {
      params.set('id', String(deskCfg.customer_id));
    }

    setLoading(true);
    setError('');
    fetchViewInit(params)
      .then((payload) => {
        if (!cancelled) setData(payload);
      })
      .catch((err) => {
        if (!cancelled) setError(err.message || 'Failed to load customer.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [deskCfg.customer_id, deskCfg.module]);

  const customer = data?.customer;
  const recentOrders = data?.recent_orders || [];
  const urls = data?.urls || {};
  const currencySymbol = getCurrencySymbol(customer?.currency);
  const balance = Number(customer?.current_balance || 0);
  const summary = data?.invoice_summary;

  if (loading && !data) {
    return (
      <div className="exp-desk-page exp-desk-boot-loading" role="status">
        <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
        <span>Loading customer profile...</span>
      </div>
    );
  }

  if (error && !customer) {
    return (
      <div className="exp-desk-page cv-page">
        <div className="exp-desk-flash exp-desk-flash-error" role="alert">{error}</div>
        {urls.customers_index && (
          <a href={urls.customers_index} className="exp-desk-btn exp-desk-btn-secondary cv-back-link">
            <ArrowLeft aria-hidden="true" />
            Back to customers
          </a>
        )}
      </div>
    );
  }

  const headerToolsEl = document.getElementById('exp-desk-header-tools');
  const pageActions = (
    <div className="cv-page-actions">
      {urls.new_quote && (
        <a href={urls.new_quote} className="exp-desk-btn exp-desk-btn-primary cv-action-btn">
          <Plus aria-hidden="true" />
          New Quote
        </a>
      )}
      {urls.edit && (
        <a href={urls.edit} className="exp-desk-btn exp-desk-btn-secondary cv-action-btn">
          <Pencil aria-hidden="true" />
          Edit
        </a>
      )}
      {urls.customers_index && (
        <a href={urls.customers_index} className="exp-desk-btn exp-desk-btn-secondary cv-action-btn">
          <ArrowLeft aria-hidden="true" />
          Back
        </a>
      )}
    </div>
  );

  return (
    <div className="exp-desk-page cv-page">
      {error && (
        <div className="exp-desk-flash exp-desk-flash-error" role="alert">{error}</div>
      )}

      {isDesktopHeader && headerToolsEl
        ? createPortal(pageActions, headerToolsEl)
        : <div className="cv-page-header">{pageActions}</div>}

      <div className="cv-layout">
        <aside className="cv-sidebar">
          <section className="cv-card cv-profile-card">
            {customer?.status && (
              <span className={`cv-profile-status ${String(customer.status).trim().toLowerCase() === 'active' ? 'is-active' : ''}`}>
                {customer.status}
              </span>
            )}
            <div className="cv-profile-icon" aria-hidden="true">
              <Building2 />
            </div>
            <h2 className="cv-profile-name">{cellOrDash(customer?.company_name)}</h2>
            <p className="cv-profile-code">{cellOrDash(customer?.customer_code)}</p>

            <div className="cv-profile-badges">
              <span className="cv-type-badge">{cellOrDash(customer?.customer_type)}</span>
              <span className={`cv-balance-badge ${balance > 0 ? 'cv-balance-badge--due' : 'cv-balance-badge--clear'}`}>
                Balance:
                {' '}
                {currencySymbol}
                {formatCurrency(balance)}
              </span>
            </div>

            <dl className="cv-detail-list">
              <div className="cv-detail-item">
                <UserRound aria-hidden="true" />
                <div><dt>Contact Person</dt><dd>{cellOrDash(customer?.contact_person)}</dd></div>
              </div>
              <div className="cv-detail-item">
                <Mail aria-hidden="true" />
                <div><dt>Email</dt><dd>{cellOrDash(customer?.email)}</dd></div>
              </div>
              <div className="cv-detail-item">
                <Phone aria-hidden="true" />
                <div><dt>Phone</dt><dd>{cellOrDash(customer?.phone)}</dd></div>
              </div>
              <div className="cv-detail-item">
                <MapPin aria-hidden="true" />
                <div><dt>Address</dt><dd>{cellOrDash(customer?.address_line || customer?.address)}</dd></div>
              </div>
            </dl>

            <div className="cv-card-head cv-finance-head">
              <WalletCards aria-hidden="true" />
              <h3 className="cv-card-title">Financial Details</h3>
            </div>
            <dl className="cv-finance-list">
              <div className="cv-finance-row">
                <dt>Payment Terms</dt>
                <dd>{cellOrDash(customer?.payment_terms)}</dd>
              </div>
              <div className="cv-finance-row">
                <dt>Credit Limit</dt>
                <dd>
                  {currencySymbol}
                  {formatCurrency(customer?.credit_limit)}
                </dd>
              </div>
              <div className="cv-finance-row">
                <dt>TIN Number</dt>
                <dd>{cellOrDash(customer?.tin)}</dd>
              </div>
              <div className="cv-finance-row">
                <dt>VRN Number</dt>
                <dd>{cellOrDash(customer?.vrn)}</dd>
              </div>
            </dl>
          </section>
        </aside>

        <section className="cv-main">
          <div className="cv-card cv-orders-card">
            <div className="cv-card-head">
              <ShoppingCart aria-hidden="true" />
              <h3 className="cv-card-title">Recent Sales Orders</h3>
            </div>

            {recentOrders.length === 0 ? (
              <div className="exp-desk-empty cv-empty">
                <p>No recent orders found.</p>
              </div>
            ) : (
              <div className="cv-table-wrap">
                <table className="cv-table">
                  <thead>
                    <tr>
                      <th>Order #</th>
                      <th>Date</th>
                      <th>Status</th>
                      <th>Total</th>
                      <th className="cv-table-action-col">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    {recentOrders.map((order) => {
                      const statusKey = getStatusColor(order.status);
                      const orderUrl = buildOrderViewUrl(
                        urls.order_view || '../orders/view.php',
                        order.id,
                        data?.module,
                      );
                      return (
                        <tr
                          key={order.id}
                          className="cv-order-row"
                          onClick={() => { window.location.href = orderUrl; }}
                        >
                          <td data-label="Order #" className="cv-order-number">{cellOrDash(order.order_number)}</td>
                          <td data-label="Date">{formatDate(order.created_at)}</td>
                          <td data-label="Status">
                            <span className={STATUS_BADGE[statusKey] || STATUS_BADGE.gray}>
                              {cellOrDash(order.status)}
                            </span>
                          </td>
                          <td data-label="Total" className="cv-order-total">
                            {currencySymbol}
                            {formatCurrency(order.total_amount)}
                          </td>
                          <td data-label="Action" className="cv-table-action-col">
                            <a
                              href={orderUrl}
                              className="cv-order-link"
                              title="View order"
                              onClick={(e) => e.stopPropagation()}
                            >
                              <ChevronRight aria-hidden="true" />
                            </a>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
          <section className="cv-card cv-summary" aria-label="Sales and account summary">
            <div className="cv-card-head">
              <BarChart3 aria-hidden="true" />
              <h3 className="cv-card-title">Sales &amp; Account Summary</h3>
            </div>
            {summary?.error ? (
              <p className="cv-summary-note" role="status">{summary.error}</p>
            ) : summary && (
              <>
                <div className="cv-summary-grid">
                  {[
                    { label: 'Total Sales', value: summary.total_sales, icon: ShoppingCart, tone: 'sales' },
                    { label: 'Total Payments', value: summary.total_payments, icon: CreditCard, tone: 'payments' },
                    { label: 'Outstanding Balance', value: summary.outstanding, icon: Scale, tone: 'balance' },
                  ].map(({ label, value, icon: Icon, tone }) => (
                    <div className={`cv-summary-item cv-summary-item--${tone}`} key={label}>
                      <span className="cv-summary-icon"><Icon aria-hidden="true" /></span>
                      <div><span className="cv-summary-label">{label}</span><strong>{getCurrencySymbol(summary.currency)}{formatCurrency(value)}</strong></div>
                    </div>
                  ))}
                </div>
                <p className="cv-summary-note">Issued invoices in {summary.currency}; other currencies are excluded.
                  {summary.unknown_currency_count > 0 && ` ${summary.unknown_currency_count} invoice(s) without a known currency are excluded.`}
                </p>
              </>
            )}
          </section>
        </section>
      </div>
    </div>
  );
}
