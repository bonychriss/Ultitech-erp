import { useCallback, useEffect, useMemo, useState } from 'react';
import { CalendarDays, Package, RefreshCw, Search, ShoppingCart, Truck } from 'lucide-react';

function formatNumber(value, maximumFractionDigits = 0) {
  return new Intl.NumberFormat(undefined, { maximumFractionDigits }).format(Number(value) || 0);
}

function formatDate(value) {
  if (!value) return '';
  const date = new Date(`${value}T00:00:00`);
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric' }).format(date);
}

function SummaryCard({ icon: Icon, label, value, tone }) {
  return (
    <div className={`sales-stat-summary-card sales-stat-summary-card--${tone}`}>
      <div className="sales-stat-summary-icon"><Icon size={19} aria-hidden="true" /></div>
      <div>
        <div className="sales-stat-summary-label">{label}</div>
        <div className="sales-stat-summary-value">{value}</div>
      </div>
    </div>
  );
}

function ProductImage({ product }) {
  const [failed, setFailed] = useState(false);

  if (product.image_url && !failed) {
    return (
      <img
        className="sales-stat-product-image"
        src={product.image_url}
        alt={product.product_name || 'Product'}
        loading="lazy"
        onError={() => setFailed(true)}
      />
    );
  }

  return (
    <div className="sales-stat-product-image sales-stat-product-image--fallback" aria-hidden="true">
      <Package size={20} />
    </div>
  );
}

export default function StatisticsPage() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const url = new URL(window.location.href);
      url.search = '';
      url.searchParams.set('api', 'statistics');
      url.searchParams.set('module', 'sales');
      const response = await fetch(url.toString(), { credentials: 'same-origin' });
      const payload = await response.json();
      if (!response.ok || payload.ok === false) {
        throw new Error(payload.error || `Request failed (${response.status})`);
      }
      setData(payload);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Failed to load sales statistics.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const products = useMemo(() => {
    const query = search.trim().toLowerCase();
    const items = data?.products || [];
    if (!query) return items;
    return items.filter((product) => (
      [product.product_name, product.product_code, product.product_id, product.top_customer_name]
        .some((value) => String(value || '').toLowerCase().includes(query))
    ));
  }, [data, search]);

  const period = data?.period || {};
  const summary = data?.summary || {};

  return (
    <main className="sales-statistics-page">
      <header className="sales-statistics-header">
        <div>
          <div className="sales-statistics-eyebrow"><CalendarDays size={14} /> YEAR-TO-DATE REPORT</div>
          <h1>Sales Statistics</h1>
          <p>Most outgoing products from {formatDate(period.date_from)} to {formatDate(period.date_to)}.</p>
        </div>
        <button className="sales-stat-refresh" type="button" onClick={load} disabled={loading}>
          <RefreshCw size={16} className={loading ? 'sales-stat-spinning' : ''} />
          Refresh
        </button>
      </header>

      <section className="sales-stat-summary" aria-label="Year-to-date product summary">
        <SummaryCard
          icon={Package}
          label="Products listed"
          value={formatNumber(summary.product_count)}
          tone="blue"
        />
        <SummaryCard
          icon={Truck}
          label="Units across listed products"
          value={formatNumber(summary.total_units, 2)}
          tone="green"
        />
        <SummaryCard
          icon={ShoppingCart}
          label="Product-order occurrences"
          value={formatNumber(summary.product_order_count)}
          tone="violet"
        />
      </section>

      <section className="sales-statistics-card">
        <div className="sales-statistics-card-head">
          <div>
            <h2>Most outgoing products</h2>
            <p>Ranked by number of sales orders; units sold are shown for each product.</p>
          </div>
          <label className="sales-stat-search">
            <Search size={16} aria-hidden="true" />
            <input
              type="search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Search products"
              aria-label="Search products"
            />
          </label>
        </div>

        {error ? (
          <div className="sales-stat-error" role="alert">
            <span>{error}</span>
            <button type="button" onClick={load}>Try again</button>
          </div>
        ) : loading ? (
          <div className="sales-stat-empty">Loading year-to-date product statistics...</div>
        ) : products.length ? (
          <div className="sales-stat-table-wrap">
            <table className="sales-stat-table">
              <thead>
                <tr>
                  <th scope="col">Rank</th>
                  <th scope="col">Product</th>
                  <th scope="col">Top customer</th>
                  <th scope="col" className="sales-stat-number">Orders</th>
                  <th scope="col" className="sales-stat-number">Units sold</th>
                </tr>
              </thead>
              <tbody>
                {products.map((product, index) => (
                  <tr key={`${product.product_id}-${index}`}>
                    <td><span className={`sales-stat-rank${index < 3 ? ' sales-stat-rank--top' : ''}`}>{index + 1}</span></td>
                    <td>
                      <div className="sales-stat-product">
                        <ProductImage product={product} />
                        <div className="sales-stat-product-copy">
                          <span className="sales-stat-product-name">{product.product_name || 'Product'}</span>
                          <span className="sales-stat-product-code">{product.product_code || `ID ${product.product_id}`}</span>
                        </div>
                      </div>
                    </td>
                    <td className="sales-stat-customer">{product.top_customer_name || '—'}</td>
                    <td className="sales-stat-number">{formatNumber(product.outgoing_count)}</td>
                    <td className="sales-stat-number sales-stat-units">{formatNumber(product.total_qty, 2)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="sales-stat-empty">
            {search ? 'No products match your search.' : 'No outgoing product sales found for this year yet.'}
          </div>
        )}
      </section>
    </main>
  );
}
