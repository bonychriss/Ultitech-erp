import React, { useState } from 'react';
import { ArrowLeftRight, Package } from 'lucide-react';
import { Product } from '../types';

interface InventoryProps {
  products: Product[];
  currencySymbol: string;
  stockValue: number;
  listedCount: number;
  searchTerm?: string;
  onOpenMovement?: (product: Product) => void;
  compact?: boolean;
}

function ProductThumb({ product }: { product: Product }) {
  const [failed, setFailed] = useState(false);
  const showImage = Boolean(product.imageUrl) && !failed;

  return (
    <div className="sms-product-thumb">
      {showImage ? (
        <img
          src={product.imageUrl}
          alt={product.name}
          loading="lazy"
          onError={() => setFailed(true)}
        />
      ) : (
        <Package className="w-5 h-5 text-slate-400" aria-hidden="true" />
      )}
    </div>
  );
}

export default function Inventory({
  products,
  onOpenMovement,
  compact = false,
}: InventoryProps) {
  return (
    <div className={compact ? 'sms-desk-table-wrap' : 'sms-table-card'}>
      <div className={compact ? undefined : 'sms-table-wrap'}>
        <table className={`sms-table sms-inventory-table${compact ? ' sms-desk-table' : ''}`}>
          <thead>
            <tr>
              <th className="sms-col-image">Image</th>
              <th className="sms-col-product">Product</th>
              <th>Category</th>
              <th className="text-center">On Hand</th>
              {onOpenMovement && <th className="text-right">Actions</th>}
            </tr>
          </thead>
          <tbody>
            {products.map((p) => {
              const isLow = p.stock > 0 && p.stock <= p.minStock;
              const isOut = p.stock <= 0;

              return (
                <tr key={p.id}>
                  <td className="sms-col-image">
                    <ProductThumb product={p} />
                  </td>
                  <td className="sms-product-cell">
                    <div className="sms-product-name">
                      {p.name}
                      {isOut && <span className="sms-badge sms-badge-out">Out</span>}
                      {isLow && !isOut && <span className="sms-badge sms-badge-low">Low</span>}
                    </div>
                    <div className="sms-product-meta">
                      <span className="sms-product-code">{p.sku || '—'}</span>
                    </div>
                  </td>
                  <td className="sms-category-text">{p.category || 'Uncategorized'}</td>
                  <td className="text-center">
                    <div className={`sms-stock-qty ${isOut ? 'is-out' : isLow ? 'is-low' : 'is-ok'}`}>
                      {p.stock}
                      <span> {p.unit}</span>
                    </div>
                    <div className="sms-stock-alert">Alert: ≤{p.minStock}</div>
                  </td>
                  {onOpenMovement && (
                    <td className="text-right">
                      <button
                        type="button"
                        onClick={() => onOpenMovement(p)}
                        className="sms-desk-btn sms-desk-btn-secondary sms-desk-btn-sm"
                        title="Record stock movement"
                      >
                        <ArrowLeftRight className="w-3.5 h-3.5" />
                        Move
                      </button>
                    </td>
                  )}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
