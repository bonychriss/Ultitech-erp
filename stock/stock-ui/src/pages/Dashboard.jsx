import React, { useEffect, useRef, useState } from 'react';
import {
  HiOutlineArrowTrendingUp,
  HiOutlineCamera,
  HiOutlineCheckBadge,
  HiOutlineChevronRight,
  HiOutlineCube,
  HiOutlineDocumentText,
  HiOutlineExclamationTriangle,
  HiOutlinePlus,
  HiOutlineShoppingBag,
  HiOutlineTruck,
  HiOutlineUsers,
  HiOutlineClipboardDocumentList,
  HiOutlineArrowPath,
} from 'react-icons/hi2';
import './dashboard-desk.css';

function formatMoney(n) {
  const v = Number(n) || 0;
  if (v >= 1000000) return `TZS ${(v / 1000000).toFixed(1)}M`;
  if (v >= 1000) return `TZS ${(v / 1000).toFixed(1)}K`;
  return `TZS ${v.toLocaleString('en', { maximumFractionDigits: 0 })}`;
}

function formatDay(dateStr) {
  if (!dateStr) return '';
  const normalized = String(dateStr).includes('T')
    ? String(dateStr)
    : String(dateStr).replace(' ', 'T');
  const d = new Date(normalized);
  if (Number.isNaN(d.getTime())) return String(dateStr);
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
}

function Thumb({ src }) {
  const [loaded, setLoaded] = useState(false);
  const [failed, setFailed] = useState(false);
  const imgRef = useRef(null);

  useEffect(() => {
    setLoaded(false);
    setFailed(false);
  }, [src]);

  useEffect(() => {
    const img = imgRef.current;
    if (!src || !img) return;
    if (img.complete && img.naturalWidth > 0) setLoaded(true);
  }, [src]);

  if (!src || failed) {
    return (
      <span className="dash-desk-thumb is-empty" aria-hidden="true">
        <HiOutlineCamera style={{ width: 16, height: 16 }} />
      </span>
    );
  }

  return (
    <span className={`dash-desk-thumb${loaded ? ' is-loaded' : ' is-loading'}`} aria-busy={!loaded}>
      {!loaded ? <span className="dash-desk-thumb-skeleton" aria-hidden="true" /> : null}
      <img
        ref={imgRef}
        src={src}
        alt=""
        loading="lazy"
        decoding="async"
        className={loaded ? 'is-visible' : ''}
        onLoad={() => setLoaded(true)}
        onError={() => setFailed(true)}
      />
    </span>
  );
}

export default function Dashboard({ data }) {
  const {
    date_label = '',
    total_products = 0,
    in_stock = 0,
    low_stock = 0,
    out_of_stock = 0,
    total_suppliers = 0,
    today_purchases_total = 0,
    products_growth_pct = null,
    outgoing_units = 0,
    low_stock_items = [],
    recent_purchases = [],
    recent_purchase_products = [],
    top_sellers = [],
    product_of_week = null,
    links = {},
  } = data || {};

  const isEmpty = Number(total_products) === 0;
  const statusTotal = Math.max(1, Number(in_stock) + Number(low_stock) + Number(out_of_stock));
  const pctIn = Math.round((Number(in_stock) / statusTotal) * 100);
  const pctLow = Math.round((Number(low_stock) / statusTotal) * 100);
  const pctOut = Math.max(0, 100 - pctIn - pctLow);

  const addProductHref = links.add_product || 'modules/products/add.php';
  const purchaseCreateHref = links.purchase_create || 'modules/purchases/domestic_create.php';

  return (
    <div className="dash-desk">
      {isEmpty ? (
        <>
          <section className="dash-desk-hero" aria-label="Stock home">
            <div className="dash-desk-hero-copy">
              <h1 className="dash-desk-title">Stock</h1>
              <p className="dash-desk-lede">
                Start by adding products, then track purchases and stock levels from one place.
              </p>
              {date_label ? <p className="dash-desk-date">{date_label}</p> : null}
            </div>
            <div className="dash-desk-hero-cta">
              <a className="dash-desk-btn dash-desk-btn--primary" href={addProductHref}>
                <HiOutlinePlus aria-hidden="true" />
                Add product
              </a>
              <a className="dash-desk-btn dash-desk-btn--ghost" href={purchaseCreateHref}>
                <HiOutlineShoppingBag aria-hidden="true" />
                New purchase
              </a>
            </div>
          </section>
          <section className="dash-desk-empty" aria-label="Get started">
            <div className="dash-desk-empty-icon" aria-hidden="true">
              <HiOutlineCube />
            </div>
            <h2>Your catalogue is empty</h2>
            <p>Add your first product to begin managing stock, purchases, and shipments.</p>
            <a className="dash-desk-btn dash-desk-btn--primary" href={links.add_product}>
              <HiOutlinePlus aria-hidden="true" />
              Add your first product
            </a>
          </section>
        </>
      ) : (
        <>
          <section className="dash-desk-metrics" aria-label="At a glance">
            <div className="dash-desk-metrics-top">
              <div>
                <h1 className="dash-desk-title dash-desk-title--compact">Stock</h1>
                {date_label ? <p className="dash-desk-date">{date_label}</p> : null}
              </div>
              <div className="dash-desk-hero-cta">
                <a className="dash-desk-btn dash-desk-btn--primary" href={addProductHref}>
                  <HiOutlinePlus aria-hidden="true" />
                  Add product
                </a>
                <a className="dash-desk-btn dash-desk-btn--ghost" href={purchaseCreateHref}>
                  <HiOutlineShoppingBag aria-hidden="true" />
                  New purchase
                </a>
              </div>
            </div>
            <div className="dash-desk-metric-grid">
              <a href={links.products} className="dash-desk-metric">
                <span className="dash-desk-metric-icon dash-desk-metric-icon--teal" aria-hidden="true">
                  <HiOutlineCube />
                </span>
                <span className="dash-desk-metric-body">
                  <span className="dash-desk-metric-label">Products</span>
                  <span className="dash-desk-metric-value">{Number(total_products).toLocaleString()}</span>
                  {products_growth_pct != null ? (
                    <span className={`dash-desk-metric-trend${products_growth_pct < 0 ? ' is-down' : ''}`}>
                      <HiOutlineArrowTrendingUp aria-hidden="true" />
                      {products_growth_pct > 0 ? '+' : ''}
                      {products_growth_pct}% vs last month
                    </span>
                  ) : (
                    <span className="dash-desk-metric-hint">In your catalogue</span>
                  )}
                </span>
              </a>
              <a href={links.products} className="dash-desk-metric">
                <span className="dash-desk-metric-icon dash-desk-metric-icon--green" aria-hidden="true">
                  <HiOutlineCheckBadge />
                </span>
                <span className="dash-desk-metric-body">
                  <span className="dash-desk-metric-label">Healthy stock</span>
                  <span className="dash-desk-metric-value">{Number(in_stock).toLocaleString()}</span>
                  <span className="dash-desk-metric-hint">Above reorder level</span>
                </span>
              </a>
              <a href={links.products_low || links.products} className="dash-desk-metric dash-desk-metric--warn">
                <span className="dash-desk-metric-icon dash-desk-metric-icon--warn" aria-hidden="true">
                  <HiOutlineExclamationTriangle />
                </span>
                <span className="dash-desk-metric-body">
                  <span className="dash-desk-metric-label">Needs restock</span>
                  <span className="dash-desk-metric-value">
                    {(Number(low_stock) + Number(out_of_stock)).toLocaleString()}
                  </span>
                  <span className="dash-desk-metric-hint">
                    {Number(out_of_stock)} out · {Number(low_stock)} low
                  </span>
                </span>
              </a>
              <a href={links.suppliers} className="dash-desk-metric">
                <span className="dash-desk-metric-icon dash-desk-metric-icon--slate" aria-hidden="true">
                  <HiOutlineUsers />
                </span>
                <span className="dash-desk-metric-body">
                  <span className="dash-desk-metric-label">Suppliers</span>
                  <span className="dash-desk-metric-value">{Number(total_suppliers).toLocaleString()}</span>
                  <span className="dash-desk-metric-hint">Ready to order from</span>
                </span>
              </a>
            </div>

            <div className="dash-desk-status" aria-label="Stock health">
              <div className="dash-desk-status-top">
                <span>Stock health</span>
                <span>{Number(total_products).toLocaleString()} total</span>
              </div>
              <div className="dash-desk-status-bar" role="img" aria-label={`In stock ${pctIn}%, low ${pctLow}%, out ${pctOut}%`}>
                <span className="is-in" style={{ width: `${pctIn}%` }} />
                <span className="is-low" style={{ width: `${pctLow}%` }} />
                <span className="is-out" style={{ width: `${pctOut}%` }} />
              </div>
              <div className="dash-desk-status-legend">
                <span><i className="is-in" /> In stock {Number(in_stock).toLocaleString()}</span>
                <span><i className="is-low" /> Low {Number(low_stock).toLocaleString()}</span>
                <span><i className="is-out" /> Out {Number(out_of_stock).toLocaleString()}</span>
              </div>
            </div>
          </section>

          <section className="dash-desk-panels" aria-label="Activity">
            <div className="dash-desk-panel">
              <div className="dash-desk-panel-head">
                <h2>Needs restock</h2>
                <a href={links.products_low || links.products}>View all</a>
              </div>
              {(low_stock_items || []).length === 0 ? (
                <div className="dash-desk-panel-empty">
                  <HiOutlineClipboardDocumentList aria-hidden="true" />
                  <p>Everything looks healthy. No restock needed right now.</p>
                </div>
              ) : (
                <ul className="dash-desk-list">
                  {low_stock_items.map((item) => (
                    <li key={item.id}>
                      <a href={`${links.product_view || 'modules/products/view.php?id='}${item.id}`}>
                        <Thumb src={item.image_url} />
                        <span className="dash-desk-list-copy">
                          <span className="dash-desk-list-title">{item.name}</span>
                          <span className="dash-desk-list-meta">
                            {item.product_code || 'No code'} · Qty {Number(item.quantity).toLocaleString()}
                            {item.reorder_level != null ? ` / reorder ${Number(item.reorder_level).toLocaleString()}` : ''}
                          </span>
                        </span>
                        <span className={`dash-desk-badge dash-desk-badge--${item.status === 'out' ? 'danger' : 'warn'}`}>
                          {item.status === 'out' ? 'Out' : 'Low'}
                        </span>
                      </a>
                    </li>
                  ))}
                </ul>
              )}
            </div>

            <div className="dash-desk-panel dash-desk-panel--fan">
              <div className="dash-desk-panel-head">
                <h2>Recent purchases</h2>
                <a href={links.purchases}>View all</a>
              </div>
              {(recent_purchases || []).length === 0 ? (
                <div className="dash-desk-panel-empty">
                  <HiOutlineShoppingBag aria-hidden="true" />
                  <p>No purchases yet. Create one when you need to restock.</p>
                  <a className="dash-desk-btn dash-desk-btn--ghost dash-desk-btn--sm" href={links.purchase_create}>
                    New purchase
                  </a>
                </div>
              ) : (
                <>
                  <div className="dash-desk-card-fan" aria-label="Purchased products">
                    {(recent_purchase_products || []).length > 0
                      ? recent_purchase_products.slice(0, 7).map((prod, index, arr) => {
                          const mid = (arr.length - 1) / 2;
                          const offset = index - mid;
                          const href = prod.id
                            ? `${links.product_view || 'modules/products/view.php?id='}${prod.id}`
                            : links.purchases;
                          return (
                            <a
                              key={prod.id || index}
                              href={href}
                              className="dash-desk-fan-card"
                              style={{
                                ['--fan-offset']: String(offset),
                                zIndex: 10 + index,
                              }}
                              title={prod.name || 'Product'}
                            >
                              <span className="dash-desk-fan-card-media">
                                {prod.image_url ? (
                                  <img src={prod.image_url} alt="" loading="lazy" decoding="async" />
                                ) : (
                                  <span className="dash-desk-fan-card-fallback" aria-hidden="true">
                                    <HiOutlineCube />
                                  </span>
                                )}
                              </span>
                              <span className="dash-desk-fan-card-caption">
                                <span className="dash-desk-fan-card-name">{prod.name || 'Product'}</span>
                                {prod.product_code ? (
                                  <span className="dash-desk-fan-card-code">{prod.product_code}</span>
                                ) : null}
                              </span>
                            </a>
                          );
                        })
                      : null}
                    {(recent_purchase_products || []).length === 0 ? (
                      <div className="dash-desk-panel-empty" style={{ padding: '1.5rem 1rem' }}>
                        <p>Purchases found, but no product images yet.</p>
                      </div>
                    ) : null}
                  </div>
                  <ul className="dash-desk-list dash-desk-list--compact">
                    {recent_purchases.slice(0, 3).map((p) => (
                      <li key={p.id}>
                        <a href={`${links.purchase_view || 'modules/purchases/view_po.php?id='}${p.id}`}>
                          <span className="dash-desk-list-copy">
                            <span className="dash-desk-list-title">
                              {p.product_name || p.supplier_name || `Purchase #${p.id}`}
                            </span>
                            <span className="dash-desk-list-meta">
                              {p.supplier_name ? `${p.supplier_name} · ` : ''}
                              {formatDay(p.created_at)}
                              {p.status ? ` · ${p.status}` : ''}
                            </span>
                          </span>
                          <span className="dash-desk-list-amount">{formatMoney(p.total_amount)}</span>
                        </a>
                      </li>
                    ))}
                  </ul>
                </>
              )}
              {Number(today_purchases_total) > 0 ? (
                <p className="dash-desk-panel-footnote">
                  Today’s purchases: <strong>{formatMoney(today_purchases_total)}</strong>
                </p>
              ) : null}
            </div>
          </section>

          {(product_of_week || (top_sellers && top_sellers.length > 0) || Number(outgoing_units) > 0) ? (
            <section className="dash-desk-movers" aria-label="Moving this month">
              <div className="dash-desk-movers-head">
                <h2>Moving this month</h2>
                {product_of_week ? (
                  <a
                    className="dash-desk-movers-week"
                    href={`${links.product_view || 'modules/products/view.php?id='}${product_of_week.id}`}
                    title={product_of_week.name}
                  >
                    <Thumb src={product_of_week.image_url} />
                    <span>
                      <span className="dash-desk-movers-week-label">Product of the week</span>
                      <span className="dash-desk-movers-week-name">{product_of_week.name}</span>
                    </span>
                  </a>
                ) : null}
              </div>
              <div className="dash-desk-movers-row">
                <div className="dash-desk-movers-item dash-desk-movers-item--summary">
                  <span className="dash-desk-movers-icon dash-desk-movers-icon--summary" aria-hidden="true">
                    <HiOutlineArrowPath />
                  </span>
                  <span className="dash-desk-movers-copy">
                    <span className="dash-desk-movers-title">Outgoing · 30 days</span>
                    <span className="dash-desk-movers-value">
                      {Number(outgoing_units).toLocaleString()}
                      <span className="dash-desk-movers-unit"> units moved</span>
                    </span>
                  </span>
                </div>
                {(top_sellers || []).slice(0, 3).map((s, index) => (
                  <a
                    key={s.id}
                    className={`dash-desk-movers-item dash-desk-movers-item--product dash-desk-movers-item--rank-${index + 1}`}
                    href={`${links.product_view || 'modules/products/view.php?id='}${s.id}`}
                  >
                    <span className="dash-desk-movers-rank" aria-hidden="true">{index + 1}</span>
                    <Thumb src={s.image_url} />
                    <span className="dash-desk-movers-copy">
                      <span className="dash-desk-movers-title">{s.name}</span>
                      <span className="dash-desk-movers-sub">
                        {Number(s.quantity).toLocaleString()} units
                      </span>
                    </span>
                    <HiOutlineChevronRight className="dash-desk-movers-chevron" aria-hidden="true" />
                  </a>
                ))}
              </div>
            </section>
          ) : null}

          <nav className="dash-desk-more" aria-label="More stock tools">
            <a className="dash-desk-more-item" href={links.movements}>
              <span className="dash-desk-more-icon" aria-hidden="true">
                <HiOutlineTruck />
              </span>
              <span className="dash-desk-more-copy">
                <span className="dash-desk-more-title">Stock movements</span>
                <span className="dash-desk-more-sub">View all stock movements</span>
              </span>
              <HiOutlineChevronRight className="dash-desk-more-chevron" aria-hidden="true" />
            </a>
            <a className="dash-desk-more-item" href={links.suppliers}>
              <span className="dash-desk-more-icon" aria-hidden="true">
                <HiOutlineUsers />
              </span>
              <span className="dash-desk-more-copy">
                <span className="dash-desk-more-title">Suppliers</span>
                <span className="dash-desk-more-sub">Manage your suppliers</span>
              </span>
              <HiOutlineChevronRight className="dash-desk-more-chevron" aria-hidden="true" />
            </a>
            <a className="dash-desk-more-item" href={links.uploads}>
              <span className="dash-desk-more-icon" aria-hidden="true">
                <HiOutlineDocumentText />
              </span>
              <span className="dash-desk-more-copy">
                <span className="dash-desk-more-title">Uploaded files</span>
                <span className="dash-desk-more-sub">Documents &amp; attachments</span>
              </span>
              <HiOutlineChevronRight className="dash-desk-more-chevron" aria-hidden="true" />
            </a>
          </nav>
        </>
      )}
    </div>
  );
}
