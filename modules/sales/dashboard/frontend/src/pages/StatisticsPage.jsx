import { useCallback, useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { Package, RefreshCw, Search, ShoppingCart, Truck } from 'lucide-react';

function formatNumber(value, maximumFractionDigits = 0) {
  return new Intl.NumberFormat(undefined, { maximumFractionDigits }).format(Number(value) || 0);
}

function SummaryCard({ icon: Icon, label, shortLabel, value, tone, subtext }) {
  return (
    <div className={`kpi-card kpi-card--tone-${tone}`}>
      <div className="kpi-card-header">
        <div className={`kpi-card-icon ${tone}`}><Icon size={14} aria-hidden="true" /></div>
        <div className="kpi-card-title">
          <span className="sales-stat-kpi-title-full">{label}</span>
          <span className="sales-stat-kpi-title-short">{shortLabel || label}</span>
        </div>
      </div>
      <div className="kpi-card-value">{value}</div>
      {subtext ? <div className="kpi-card-subtext">{subtext}</div> : null}
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
  const [headerToolsEl, setHeaderToolsEl] = useState(null);
  const [isDesktopHeader, setIsDesktopHeader] = useState(() => (
    typeof window !== 'undefined' && window.matchMedia('(min-width: 768px)').matches
  ));

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

  useEffect(() => {
    setHeaderToolsEl(document.getElementById('sales-stat-header-tools'));
    const media = window.matchMedia('(min-width: 768px)');
    const syncHeaderMode = () => setIsDesktopHeader(media.matches);
    syncHeaderMode();
    media.addEventListener('change', syncHeaderMode);
    return () => media.removeEventListener('change', syncHeaderMode);
  }, []);

  const products = useMemo(() => {
    const query = search.trim().toLowerCase();
    const items = data?.products || [];
    if (!query) return items;
    return items.filter((product) => (
      [product.product_name, product.product_code, product.product_id, product.top_customer_name]
        .some((value) => String(value || '').toLowerCase().includes(query))
    ));
  }, [data, search]);

  const summary = data?.summary || {};

  const searchField = (
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
  );

  const refreshButton = (
    <button className="sales-stat-refresh" type="button" onClick={load} disabled={loading}>
      <RefreshCw size={16} className={loading ? 'sales-stat-spinning' : ''} />
      Refresh
    </button>
  );

  const headerControls = (
    <div className="sales-stat-header-tools">
      {isDesktopHeader ? <div className="sales-stat-header-search">{searchField}</div> : null}
      {refreshButton}
    </div>
  );

  return (
    <main className="sales-statistics-page">
      {headerToolsEl ? createPortal(headerControls, headerToolsEl) : null}
      {!isDesktopHeader ? (
        <div className="sales-statistics-header sales-statistics-header--mobile">
          {searchField}
        </div>
      ) : null}

      <section className="kpi-overview sales-stat-summary" aria-label="Year-to-date product summary">
        <SummaryCard
          icon={Package}
          label="Products listed"
          shortLabel="Products"
          value={formatNumber(summary.product_count)}
          tone="blue"
          subtext="Year to date"
        />
        <SummaryCard
          icon={Truck}
          label="Units across listed products"
          shortLabel="Units"
          value={formatNumber(summary.total_units, 2)}
          tone="green"
          subtext="Year to date"
        />
        <SummaryCard
          icon={ShoppingCart}
          label="Product-order occurrences"
          shortLabel="Orders"
          value={formatNumber(summary.product_order_count)}
          tone="violet"
          subtext="Year to date"
        />
      </section>

      <section className="sales-stat-results">
        <div className="sales-statistics-card-head">
          <div>
            <h2>Most outgoing products</h2>
            <p>Ranked by number of sales orders; units sold are shown for each product.</p>
          </div>
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
