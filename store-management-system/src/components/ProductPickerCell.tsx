import React, { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Package } from 'lucide-react';
import type { Product } from '../types';
import { filterProductsBySearch } from '../utils/productSearch';

const LIST_CAP = 120;

interface ProductPickerCellProps {
  products: Product[];
  displayName: string;
  open: boolean;
  cellKey: string;
  onPick: (product: Product) => void;
}

function ProductPickerThumb({ product }: { product: Product }) {
  const [failed, setFailed] = useState(false);
  const showImage = Boolean(product.imageUrl) && !failed;

  return (
    <div className="sms-product-thumb sms-product-thumb--sm sms-product-picker-thumb" aria-hidden="true">
      {showImage ? (
        <img
          src={product.imageUrl}
          alt=""
          loading="lazy"
          onError={() => setFailed(true)}
        />
      ) : (
        <Package className="w-4 h-4 text-slate-400" />
      )}
    </div>
  );
}

export default function ProductPickerCell({
  products,
  displayName,
  open,
  cellKey,
  onPick,
}: ProductPickerCellProps) {
  const [query, setQuery] = useState('');
  const [highlight, setHighlight] = useState(0);
  const [picked, setPicked] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const [menuPos, setMenuPos] = useState({ top: 0, left: 0, width: 320 });

  const sorted = useMemo(
    () => [...products].sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' })),
    [products]
  );

  const matches = useMemo(() => filterProductsBySearch(sorted, query), [sorted, query]);
  const shown = matches.slice(0, LIST_CAP);

  useEffect(() => {
    if (!open) {
      setQuery('');
      setHighlight(0);
      setPicked(false);
      return;
    }
    setQuery('');
    setHighlight(0);
    setPicked(false);
    requestAnimationFrame(() => inputRef.current?.focus());
  }, [open, cellKey]);

  const showMenu = open && !picked;

  useLayoutEffect(() => {
    if (!showMenu) return undefined;
    const update = () => {
      const el = inputRef.current;
      if (!el) return;
      const r = el.getBoundingClientRect();
      const width = Math.min(Math.max(r.width, 320), window.innerWidth - 16);
      let left = r.left;
      if (left + width > window.innerWidth - 8) {
        left = Math.max(8, window.innerWidth - width - 8);
      }
      const estimatedHeight = Math.min(320, 44 + shown.length * 52);
      const spaceBelow = window.innerHeight - r.bottom;
      const top = spaceBelow < estimatedHeight && r.top > estimatedHeight
        ? r.top - estimatedHeight
        : r.bottom;
      setMenuPos({ top, left, width });
    };
    update();
    window.addEventListener('scroll', update, true);
    window.addEventListener('resize', update);
    return () => {
      window.removeEventListener('scroll', update, true);
      window.removeEventListener('resize', update);
    };
  }, [showMenu, query, shown.length]);

  useEffect(() => {
    setHighlight((h) => (shown.length === 0 ? 0 : Math.min(h, shown.length - 1)));
  }, [shown.length]);

  const pick = (product: Product) => {
    setPicked(true);
    setQuery('');
    onPick(product);
  };

  if (!showMenu) {
    return (
      <span
        className="sms-excel-readonly"
        onClick={() => {
          if (open) setPicked(false);
        }}
      >
        {displayName}
      </span>
    );
  }

  return (
    <div className="sms-product-picker">
      <input
        ref={inputRef}
        type="text"
        data-excel-cell={cellKey}
        className="sms-excel-input"
        value={query}
        placeholder={displayName || 'Search products...'}
        aria-label="Search products"
        autoComplete="off"
        onChange={(e) => {
          setQuery(e.target.value);
          setHighlight(0);
        }}
        onClick={(e) => e.stopPropagation()}
        onKeyDown={(e) => {
          if (e.key === 'ArrowDown') {
            e.preventDefault();
            e.stopPropagation();
            setHighlight((h) => Math.min(h + 1, Math.max(shown.length - 1, 0)));
          } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            e.stopPropagation();
            setHighlight((h) => Math.max(h - 1, 0));
          } else if (e.key === 'Enter') {
            e.preventDefault();
            e.stopPropagation();
            if (shown[highlight]) pick(shown[highlight]);
          } else if (e.key === 'Escape') {
            e.preventDefault();
            setQuery('');
          }
        }}
      />
      {createPortal(
        <div
          className="sms-product-picker-menu"
          role="listbox"
          style={{ top: menuPos.top, left: menuPos.left, width: menuPos.width }}
        >
          <div className="sms-product-picker-meta">
            {query.trim()
              ? `${matches.length} match${matches.length === 1 ? '' : 'es'}`
              : `${products.length} product${products.length === 1 ? '' : 's'}`}
            {matches.length > LIST_CAP ? ` (showing ${LIST_CAP})` : ''}
          </div>
          {shown.length === 0 ? (
            <div className="sms-product-picker-empty">No products match "{query.trim()}"</div>
          ) : (
            shown.map((product, index) => (
              <button
                type="button"
                key={product.id}
                role="option"
                className={`sms-product-picker-item${index === highlight ? ' is-active' : ''}`}
                onMouseEnter={() => setHighlight(index)}
                onMouseDown={(e) => {
                  e.preventDefault();
                  e.stopPropagation();
                  pick(product);
                }}
              >
                <ProductPickerThumb product={product} />
                <span className="sms-product-picker-name">{product.name}</span>
                <span className="sms-product-picker-sku">{product.sku || product.id}</span>
              </button>
            ))
          )}
        </div>,
        document.body
      )}
    </div>
  );
}
