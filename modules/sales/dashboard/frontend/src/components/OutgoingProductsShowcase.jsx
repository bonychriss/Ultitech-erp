function productCode(productId) {
  return productId > 0
    ? `PRD-${String(productId).padStart(6, '0')}`
    : '';
}

function formatQty(n) {
  const v = Number(n) || 0;
  if (v >= 1000000) return `${(v / 1000000).toFixed(1)}M`;
  if (v >= 1000) return `${(v / 1000).toFixed(1)}K`;
  return v.toLocaleString('en', { maximumFractionDigits: 0 });
}

export default function OutgoingProductsShowcase({
  products = [],
  title = 'Most Outgoing Products',
  lookbackDays = 30,
  emptyMessage = 'No product data yet',
  placeholderIcon = 'fa-box',
  viewAllUrl = '',
  productViewUrl = '',
}) {
  const fanProducts = products.slice(0, 7);
  const listProducts = products.slice(0, 3);

  if (!products.length) {
    return (
      <div className="dash-desk-panel dash-desk-panel--fan sd-outgoing-panel">
        <div className="dash-desk-panel-head">
          <h2>{title}</h2>
        </div>
        <div className="dash-desk-panel-empty">
          <p>{emptyMessage}</p>
        </div>
      </div>
    );
  }

  return (
    <div className="dash-desk-panel dash-desk-panel--fan sd-outgoing-panel">
      <div className="dash-desk-panel-head">
        <h2>{title}</h2>
        {viewAllUrl ? (
          <a href={viewAllUrl}>View all</a>
        ) : null}
      </div>

      <div className="dash-desk-card-fan" aria-label="Outgoing products">
        {fanProducts.map((prod, index, arr) => {
          const mid = (arr.length - 1) / 2;
          const offset = index - mid;
          const href = prod.product_id > 0 && productViewUrl
            ? `${productViewUrl}${prod.product_id}`
            : viewAllUrl || '#';
          const code = prod.product_code || productCode(prod.product_id);

          return (
            <a
              key={`${prod.product_id}-${index}`}
              href={href}
              className="dash-desk-fan-card"
              style={{
                '--fan-offset': String(offset),
                zIndex: 10 + index,
              }}
              title={prod.product_name || 'Product'}
            >
              <span className="dash-desk-fan-card-media">
                {prod.image_url ? (
                  <img src={prod.image_url} alt="" loading="lazy" decoding="async" />
                ) : (
                  <span className="dash-desk-fan-card-fallback" aria-hidden="true">
                    <i className={`fas ${prod.placeholder_icon || placeholderIcon}`} />
                  </span>
                )}
              </span>
              <span className="dash-desk-fan-card-caption">
                <span className="dash-desk-fan-card-name">{prod.product_name || 'Product'}</span>
                {code ? (
                  <span className="dash-desk-fan-card-code">{code}</span>
                ) : null}
              </span>
            </a>
          );
        })}
      </div>

      <ul className="dash-desk-list dash-desk-list--compact">
        {listProducts.map((product, index) => {
          const customer = product.top_customer_name || 'Unknown customer';
          const orders = Number(product.outgoing_count) || 0;
          const qty = Number(product.total_qty) || 0;
          const href = product.product_id > 0 && productViewUrl
            ? `${productViewUrl}${product.product_id}`
            : viewAllUrl || '#';

          return (
            <li key={`${product.product_id}-${index}`}>
              <a href={href}>
                <span className="dash-desk-list-copy">
                  <span className="dash-desk-list-title">
                    {product.product_name}
                  </span>
                  <span className="dash-desk-list-meta">
                    {customer}
                    {' · '}
                    {lookbackDays}
                    d
                    {' · '}
                    {orders}
                    {' '}
                    {orders === 1 ? 'order' : 'orders'}
                  </span>
                </span>
                <span className="dash-desk-list-amount">
                  {qty > 0 ? `${formatQty(qty)} units` : `${orders}×`}
                </span>
              </a>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
