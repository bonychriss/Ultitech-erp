import React, { useMemo, useState } from 'react';
import { HiOutlineCube, HiOutlineMagnifyingGlass } from 'react-icons/hi2';
import ProductThumb from './ProductThumb';
import './catalogue-desk.css';
import './products-desk.css';

function moneySymbol(currency) {
  const c = String(currency || 'TZS').toUpperCase();
  if (c === 'USD') return '$';
  if (c === 'EUR') return '€';
  return 'TSh ';
}

function formatMoney(n, currency) {
  const num = Number(n || 0);
  return `${moneySymbol(currency)}${num.toLocaleString('en', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

function matchesQuery(product, q) {
  const tokens = String(q || '')
    .trim()
    .toLowerCase()
    .split(/\s+/)
    .filter(Boolean);
  if (!tokens.length) return true;
  const hay = [
    product.name,
    product.product_code,
    product.brand,
    product.category_name,
    product.supplier_name,
  ]
    .map((v) => String(v || '').toLowerCase())
    .join(' ');
  return tokens.every((t) => hay.includes(t));
}

export default function Catalogue({ data }) {
  const {
    products = [],
    categories = [],
    showCost = false,
    filterSearch = '',
    filterCategory = '',
    detailUrl = 'product-detail.php',
  } = data || {};

  const [search, setSearch] = useState(String(filterSearch || ''));
  const [category, setCategory] = useState(String(filterCategory || ''));

  const filtered = useMemo(() => {
    return products.filter((p) => {
      if (category && String(p.category_id) !== String(category)) return false;
      return matchesQuery(p, search);
    });
  }, [products, search, category]);

  const detailHref = (id) => {
    const base = String(detailUrl || 'product-detail.php');
    const sep = base.includes('?') ? '&' : '?';
    return `${base}${sep}id=${encodeURIComponent(id)}`;
  };

  return (
    <div className="cat-desk">
      <div className="cat-desk-toolbar">
        <label className="cat-desk-search" aria-label="Search catalogue">
          <HiOutlineMagnifyingGlass size={18} aria-hidden="true" />
          <input
            type="search"
            placeholder="Search products…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            autoComplete="off"
          />
        </label>

        <select
          className="cat-desk-select"
          value={category}
          onChange={(e) => setCategory(e.target.value)}
          aria-label="Filter by category"
        >
          <option value="">All categories</option>
          {categories.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </select>

        <div className="cat-desk-count">
          {filtered.length} of {products.length} product{products.length === 1 ? '' : 's'}
        </div>
      </div>

      {filtered.length === 0 ? (
        <div className="cat-desk-empty">
          <HiOutlineCube size={48} aria-hidden="true" />
          <p>
            {products.length === 0
              ? 'No products in the catalogue yet.'
              : 'No products match your search.'}
          </p>
        </div>
      ) : (
        <div className="cat-desk-grid">
          {filtered.map((product) => {
            const qty = Number(product.quantity ?? 0);
            const eyebrow =
              product.brand || product.supplier_name || product.category_name || 'Product';
            return (
              <a
                key={product.id}
                className="cat-desk-card"
                href={detailHref(product.id)}
              >
                <div className="cat-desk-card-media">
                  <ProductThumb
                    src={product.image_url || ''}
                    alt={product.name || ''}
                    className="prod-desk-thumb cat-desk-thumb-fill"
                    size={28}
                  />
                </div>
                <div className="cat-desk-card-body">
                  <span className="cat-desk-eyebrow">{eyebrow}</span>
                  <div className="cat-desk-name" title={product.name}>
                    {product.name || 'Untitled'}
                  </div>
                  {product.product_code ? (
                    <div className="cat-desk-code">{product.product_code}</div>
                  ) : null}
                  {showCost ? (
                    <div className="cat-desk-cost">
                      Cost: {formatMoney(product.buying_price, product.currency)}
                    </div>
                  ) : null}
                  <div className="cat-desk-price">
                    {formatMoney(product.unit_price, product.currency)}
                  </div>
                  <div className={`cat-desk-stock ${qty > 0 ? 'is-in' : 'is-out'}`}>
                    {qty > 0 ? `In stock: ${qty}` : 'Out of stock'}
                  </div>
                </div>
              </a>
            );
          })}
        </div>
      )}
    </div>
  );
}
