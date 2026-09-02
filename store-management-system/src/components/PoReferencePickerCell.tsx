import React, { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Loader2 } from 'lucide-react';
import { fetchProductPoReferences } from '../api';
import type { ProductPoReference } from '../types';
import { filterPoReferencesBySearch } from '../utils/poReferenceSearch';

const LIST_CAP = 120;
const referenceCache = new Map<string, ProductPoReference[]>();

function cacheKey(productId: string, sku: string): string {
  return `${productId}|${sku.trim().toLowerCase()}`;
}

interface PoReferencePickerCellProps {
  productId: string;
  productSku: string;
  displayValue: string;
  open: boolean;
  cellKey: string;
  onPick: (reference: ProductPoReference) => void;
}

export default function PoReferencePickerCell({
  productId,
  productSku,
  displayValue,
  open,
  cellKey,
  onPick,
}: PoReferencePickerCellProps) {
  const [query, setQuery] = useState('');
  const [highlight, setHighlight] = useState(0);
  const [picked, setPicked] = useState(false);
  const [loading, setLoading] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [references, setReferences] = useState<ProductPoReference[]>([]);
  const inputRef = useRef<HTMLInputElement>(null);
  const [menuPos, setMenuPos] = useState({ top: 0, left: 0, width: 320 });

  const hasProduct = Boolean(productId.trim() || productSku.trim());

  useEffect(() => {
    if (!open) {
      setQuery('');
      setHighlight(0);
      setPicked(false);
      setLoadError(null);
      return;
    }
    setQuery('');
    setHighlight(0);
    setPicked(false);
    requestAnimationFrame(() => inputRef.current?.focus());

    if (!hasProduct) {
      setReferences([]);
      setLoading(false);
      setLoadError(null);
      return;
    }

    const key = cacheKey(productId, productSku);
    const cached = referenceCache.get(key);
    if (cached) {
      setReferences(cached);
      setLoading(false);
      setLoadError(null);
      return;
    }

    let cancelled = false;
    setLoading(true);
    setLoadError(null);
    fetchProductPoReferences(productId, productSku)
      .then((rows) => {
        if (cancelled) return;
        referenceCache.set(key, rows);
        setReferences(rows);
      })
      .catch((err) => {
        if (cancelled) return;
        setReferences([]);
        setLoadError(err instanceof Error ? err.message : 'Failed to load PO references');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [open, cellKey, productId, productSku, hasProduct]);

  const matches = useMemo(() => filterPoReferencesBySearch(references, query), [references, query]);
  const shown = matches.slice(0, LIST_CAP);
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
  }, [showMenu, query, shown.length, loading, loadError]);

  useEffect(() => {
    setHighlight((h) => (shown.length === 0 ? 0 : Math.min(h, shown.length - 1)));
  }, [shown.length]);

  const pick = (reference: ProductPoReference) => {
    setPicked(true);
    setQuery('');
    onPick(reference);
  };

  if (!hasProduct) {
    return <span className="sms-excel-readonly">{displayValue || ''}</span>;
  }

  if (!showMenu) {
    return (
      <span
        className="sms-excel-readonly"
        onClick={() => {
          if (open) setPicked(false);
        }}
      >
        {displayValue || ''}
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
        placeholder={displayValue || 'Search PO references...'}
        aria-label="Search PO references"
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
            {loading ? (
              <span className="sms-product-picker-loading">
                <Loader2 className="w-3.5 h-3.5 animate-spin" aria-hidden="true" />
                Loading PO references...
              </span>
            ) : loadError ? (
              loadError
            ) : query.trim()
              ? `${matches.length} match${matches.length === 1 ? '' : 'es'}`
              : `${references.length} PO reference${references.length === 1 ? '' : 's'} for this product`}
            {!loading && matches.length > LIST_CAP ? ` (showing ${LIST_CAP})` : ''}
          </div>
          {loading ? null : loadError ? (
            <div className="sms-product-picker-empty">{loadError}</div>
          ) : shown.length === 0 ? (
            <div className="sms-product-picker-empty">
              {query.trim()
                ? `No PO references match "${query.trim()}"`
                : 'No open purchase orders contain this product'}
            </div>
          ) : (
            shown.map((reference, index) => (
              <button
                type="button"
                key={`${reference.source}-${reference.poId}-${reference.lineId ?? reference.poNumber}`}
                role="option"
                className={`sms-product-picker-item sms-product-picker-item--stacked${index === highlight ? ' is-active' : ''}`}
                onMouseEnter={() => setHighlight(index)}
                onMouseDown={(e) => {
                  e.preventDefault();
                  e.stopPropagation();
                  pick(reference);
                }}
              >
                <span className="sms-product-picker-name">{reference.poNumber}</span>
                <span className="sms-product-picker-sku">
                  {reference.supplierName || 'Supplier'}
                  {' ù '}
                  {reference.qtyRemaining.toLocaleString()} remaining
                  {reference.receiveStatus ? ` ù ${reference.receiveStatus}` : ''}
                </span>
              </button>
            ))
          )}
        </div>,
        document.body
      )}
    </div>
  );
}
