import { useState } from 'react';
import { starRatingParts } from '../utils/dashboardFormat.js';

function ProductStars({ rating }) {
  const { fullStars, halfStar, emptyStars, label } = starRatingParts(rating);
  return (
    <div className="product-rating">
      {Array.from({ length: fullStars }, (_, i) => (
        <i key={`f-${i}`} className="fas fa-star text-warning" />
      ))}
      {halfStar ? <i className="fas fa-star-half-alt text-warning" /> : null}
      {Array.from({ length: emptyStars }, (_, i) => (
        <i key={`e-${i}`} className="far fa-star text-warning" />
      ))}
      <span className="product-rating-val">{label}</span>
    </div>
  );
}

function ProductImage({ product }) {
  const [failed, setFailed] = useState(false);
  const icon = product.placeholder_icon || 'fa-box';

  if (product.image_url && !failed) {
    return (
      <img
        src={product.image_url}
        alt=""
        loading="lazy"
        onError={() => setFailed(true)}
      />
    );
  }

  return (
    <div className="product-placeholder">
      <i className={`fas ${icon}`} />
    </div>
  );
}

export default function MostSoldProductsList({ products = [], emptyMessage, placeholderIcon = 'fa-box' }) {
  if (!products.length) {
    return (
      <div className="text-center text-muted py-4 most-sold-empty">
        {emptyMessage || 'No product data yet'}
      </div>
    );
  }

  return (
    <div className="products-list sd-card-scroll">
      {products.map((product, index) => (
        <div className="product-item" key={`${product.product_id}-${index}`}>
          <div className="product-rank">{index + 1}</div>
          <div className="product-image">
            <ProductImage product={{ ...product, placeholder_icon: product.placeholder_icon || placeholderIcon }} />
          </div>
          <div className="product-info">
            <div className="product-name">{product.product_name}</div>
            <ProductStars rating={product.rating} />
            <div className="product-meta">
              {product.top_customer_name ? (
                <>
                  <i className="fas fa-user" style={{ fontSize: '0.7rem' }} />
                  {' '}
                  {product.top_customer_name}
                </>
              ) : (
                <>
                  Product ID:
                  {' '}
                  {product.product_id}
                </>
              )}
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}
