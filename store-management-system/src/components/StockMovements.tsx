import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  ArrowDownCircle,
  ArrowUpCircle,
  History,
  Loader2,
  Package,
  RefreshCw,
  Search,
} from 'lucide-react';
import { fetchMovements, recordStockMovement } from '../api';
import InvoiceDispatch from './InvoiceDispatch';
import VerifyReceipts from './VerifyReceipts';
import type { MovementStats, Product, StockDirection, StockMovement } from '../types';

const OUT_REASONS = [
  'Sample / promotional giveaway',
  'Internal use',
  'Damaged or expired',
  'Transfer to another warehouse',
  'Other',
];

interface StockMovementsProps {
  warehouseId: number;
  products: Product[];
  onStockChanged: () => Promise<void>;
  preselectedProductId?: string | null;
  initialDirection?: StockDirection;
  initialOutSource?: 'invoice' | 'manual';
}

function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

function monthStartIso(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

export default function StockMovements({
  warehouseId,
  products,
  onStockChanged,
  preselectedProductId,
  initialDirection = 'in',
  initialOutSource = 'invoice',
}: StockMovementsProps) {
  const [direction, setDirection] = useState<StockDirection>(initialDirection);
  const [outSource, setOutSource] = useState<'invoice' | 'manual'>(initialOutSource);
  const [productId, setProductId] = useState(preselectedProductId ?? '');
  const [quantity, setQuantity] = useState('');
  const [reason, setReason] = useState(OUT_REASONS[0]);
  const [notes, setNotes] = useState('');
  const [saving, setSaving] = useState(false);

  const [movements, setMovements] = useState<StockMovement[]>([]);
  const [stats, setStats] = useState<MovementStats>({ totalIn: 0, totalOut: 0, netMovement: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [filterSearch, setFilterSearch] = useState('');
  const [filterType, setFilterType] = useState('');
  const [filterProductId, setFilterProductId] = useState('');
  const [filterStart, setFilterStart] = useState(monthStartIso());
  const [filterEnd, setFilterEnd] = useState(todayIso());

  const reasonOptions = OUT_REASONS;
  const selectedProduct = products.find((p) => p.id === productId) ?? null;

  const loadMovements = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const data = await fetchMovements(warehouseId, {
        productId: filterProductId || undefined,
        type: filterType || undefined,
        search: filterSearch || undefined,
        startDate: filterStart,
        endDate: filterEnd,
      });
      setMovements(data.movements);
      setStats(data.stats);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load movements');
    } finally {
      setLoading(false);
    }
  }, [warehouseId, filterProductId, filterType, filterSearch, filterStart, filterEnd]);

  useEffect(() => {
    loadMovements();
  }, [loadMovements]);

  useEffect(() => {
    setDirection(initialDirection);
  }, [initialDirection]);

  useEffect(() => {
    setOutSource(initialOutSource);
  }, [initialOutSource]);

  useEffect(() => {
    if (preselectedProductId) {
      setProductId(preselectedProductId);
    }
  }, [preselectedProductId]);

  useEffect(() => {
    setReason(OUT_REASONS[0]);
  }, [direction, outSource]);

  const sortedProducts = useMemo(
    () => [...products].sort((a, b) => a.name.localeCompare(b.name)),
    [products]
  );

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (direction !== 'out') return;
    const qty = Number(quantity);
    if (!productId) {
      alert('Please select a product.');
      return;
    }
    if (!qty || qty <= 0) {
      alert('Enter a valid quantity.');
      return;
    }
    if (direction === 'out' && selectedProduct && qty > selectedProduct.stock) {
      alert(`Only ${selectedProduct.stock} ${selectedProduct.unit} available in this warehouse.`);
      return;
    }

    setSaving(true);
    try {
      await recordStockMovement(warehouseId, {
        productId,
        direction,
        quantity: qty,
        reason,
        notes: notes.trim(),
      });
      setQuantity('');
      setNotes('');
      await onStockChanged();
      await loadMovements();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to record movement');
    } finally {
      setSaving(false);
    }
  };

  const formatDate = (iso: string) => {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleString(undefined, {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  return (
    <div className="sms-panel">
      <div className="sms-movement-form-card">
        <div className="sms-movement-form-head">
          <h3 className="sms-table-title">Verify Stock Movements</h3>
          <p className="sms-table-meta">
            Confirm goods received from Procurement, or release stock against invoices and manual outflows
          </p>
        </div>

        <div className="sms-movement-form">
          <div className="sms-direction-toggle">
            <button
              type="button"
              className={`sms-direction-btn ${direction === 'in' ? 'is-in active' : 'is-in'}`}
              onClick={() => setDirection('in')}
            >
              <ArrowDownCircle className="w-4 h-4" />
              Stock In
            </button>
            <button
              type="button"
              className={`sms-direction-btn ${direction === 'out' ? 'is-out active' : 'is-out'}`}
              onClick={() => setDirection('out')}
            >
              <ArrowUpCircle className="w-4 h-4" />
              Stock Out
            </button>
          </div>

          {direction === 'out' && (
            <div className="sms-in-source-toggle">
              <button
                type="button"
                className={`sms-in-source-btn ${outSource === 'invoice' ? 'active' : ''}`}
                onClick={() => setOutSource('invoice')}
              >
                Sales invoice
              </button>
              <button
                type="button"
                className={`sms-in-source-btn ${outSource === 'manual' ? 'active' : ''}`}
                onClick={() => setOutSource('manual')}
              >
                Manual (samples, etc.)
              </button>
            </div>
          )}
        </div>

        {direction === 'in' ? (
          <VerifyReceipts
            warehouseId={warehouseId}
            onVerified={async () => {
              await onStockChanged();
              await loadMovements();
            }}
          />
        ) : outSource === 'invoice' ? (
          <InvoiceDispatch
            warehouseId={warehouseId}
            onDispatched={async () => {
              await onStockChanged();
              await loadMovements();
            }}
          />
        ) : (
        <form onSubmit={handleSubmit} className="sms-movement-form sms-movement-form-bordered">

          <div className="sms-movement-grid">
            <div>
              <label className="sms-field-label">Product *</label>
              <select
                required
                value={productId}
                onChange={(e) => setProductId(e.target.value)}
                className="sms-input"
              >
                <option value="">Select product...</option>
                {sortedProducts.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name} ({p.sku}) � {p.stock} {p.unit} on hand
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="sms-field-label">Quantity *</label>
              <input
                type="number"
                min="1"
                required
                value={quantity}
                onChange={(e) => setQuantity(e.target.value)}
                className="sms-input"
                placeholder={selectedProduct ? `Max out: ${selectedProduct.stock}` : 'Units'}
              />
            </div>

            <div>
              <label className="sms-field-label">Reason *</label>
              <select
                required
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                className="sms-input"
              >
                {reasonOptions.map((r) => (
                  <option key={r} value={r}>
                    {r}
                  </option>
                ))}
              </select>
            </div>

            <div className="sms-movement-notes">
              <label className="sms-field-label">Additional notes</label>
              <input
                type="text"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                className="sms-input"
                placeholder="Reference number, person, or details..."
              />
            </div>
          </div>

          <div className="sms-movement-actions">
            <button
              type="submit"
              disabled={saving}
              className="sms-btn-primary sms-btn-out"
            >
              {saving ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : (
                <ArrowUpCircle className="w-4 h-4" />
              )}
              Record manual stock out
            </button>
          </div>
        </form>
        )}
      </div>

      <div className="sms-stats-grid sms-stats-grid-3">
        <div className="sms-stat-card">
          <div className="sms-stat-icon bg-emerald-50 text-emerald-600">
            <ArrowDownCircle className="w-5 h-5" />
          </div>
          <div>
            <div className="sms-stat-label">Total In</div>
            <div className="sms-stat-value">{stats.totalIn.toLocaleString()}</div>
            <div className="sms-stat-hint">Units received (filtered period)</div>
          </div>
        </div>
        <div className="sms-stat-card">
          <div className="sms-stat-icon bg-red-50 text-red-600">
            <ArrowUpCircle className="w-5 h-5" />
          </div>
          <div>
            <div className="sms-stat-label">Total Out</div>
            <div className="sms-stat-value">{stats.totalOut.toLocaleString()}</div>
            <div className="sms-stat-hint">Units dispatched (filtered period)</div>
          </div>
        </div>
        <div className="sms-stat-card">
          <div className="sms-stat-icon bg-indigo-50 text-indigo-600">
            <Package className="w-5 h-5" />
          </div>
          <div>
            <div className="sms-stat-label">Net Movement</div>
            <div className={`sms-stat-value ${stats.netMovement >= 0 ? 'text-emerald-600' : 'text-red-600'}`}>
              {(stats.netMovement > 0 ? '+' : '') + stats.netMovement.toLocaleString()}
            </div>
            <div className="sms-stat-hint">In minus out</div>
          </div>
        </div>
      </div>

      <div className="sms-panel-toolbar">
        <div className="sms-filters">
          <div className="sms-search-wrap">
            <Search className="sms-search-icon" />
            <input
              type="text"
              placeholder="Search product or SKU..."
              value={filterSearch}
              onChange={(e) => setFilterSearch(e.target.value)}
              className="sms-input sms-search-input"
            />
          </div>
          <select
            value={filterProductId}
            onChange={(e) => setFilterProductId(e.target.value)}
            className="sms-input sms-select"
          >
            <option value="">All products</option>
            {sortedProducts.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </select>
          <select
            value={filterType}
            onChange={(e) => setFilterType(e.target.value)}
            className="sms-input sms-select"
          >
            <option value="">All types</option>
            <option value="in">Stock In</option>
            <option value="out">Stock Out</option>
            <option value="adjustment">Adjustment</option>
          </select>
          <input
            type="date"
            value={filterStart}
            onChange={(e) => setFilterStart(e.target.value)}
            className="sms-input sms-date-input"
          />
          <input
            type="date"
            value={filterEnd}
            onChange={(e) => setFilterEnd(e.target.value)}
            className="sms-input sms-date-input"
          />
        </div>
        <button type="button" onClick={loadMovements} className="sms-btn-secondary" disabled={loading}>
          {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : <RefreshCw className="w-4 h-4" />}
          Refresh
        </button>
      </div>

      {error && <div className="sms-alert sms-alert-error">{error}</div>}

      <div className="sms-table-card">
        <div className="sms-table-head">
          <div>
            <h3 className="sms-table-title flex items-center gap-2">
              <History className="w-4 h-4 text-slate-400" />
              Movement History
            </h3>
            <p className="sms-table-meta">{movements.length} transactions in selected period</p>
          </div>
          <span className="sms-count-pill">{movements.length} entries</span>
        </div>

        {loading ? (
          <div className="sms-table-empty">
            <Loader2 className="w-8 h-8 animate-spin text-blue-600" />
            <span>Loading movements...</span>
          </div>
        ) : movements.length === 0 ? (
          <div className="sms-table-empty">
            <History className="w-12 h-12 text-slate-300 mb-2" />
            <span className="font-semibold text-slate-700">No movements found</span>
            <span className="text-xs text-slate-400 mt-1">Record a stock in or out above, or widen the date filter.</span>
          </div>
        ) : (
          <div className="sms-table-wrap">
            <table className="sms-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Product</th>
                  <th>Type</th>
                  <th className="text-center">Qty</th>
                  <th>Reason / Notes</th>
                </tr>
              </thead>
              <tbody>
                {movements.map((m) => (
                  <tr key={m.id}>
                    <td className="whitespace-nowrap text-slate-600">{formatDate(m.createdAt)}</td>
                    <td>
                      <div className="font-semibold text-slate-900">{m.productName}</div>
                      <div className="sms-product-meta">
                        <span className="sms-sku">{m.productSku}</span>
                        <span>{m.categoryName}</span>
                      </div>
                    </td>
                    <td>
                      {m.movementType === 'in' ? (
                        <span className="sms-move-tag sms-move-tag-in">In</span>
                      ) : m.movementType === 'out' ? (
                        <span className="sms-move-tag sms-move-tag-out">Out</span>
                      ) : (
                        <span className="sms-move-tag sms-move-tag-adj">Adj</span>
                      )}
                    </td>
                    <td className="text-center font-mono font-bold">
                      <span
                        className={
                          m.movementType === 'in'
                            ? 'text-emerald-600'
                            : m.movementType === 'out'
                              ? 'text-red-600'
                              : 'text-blue-600'
                        }
                      >
                        {m.movementType === 'in' ? '+' : m.movementType === 'out' ? '-' : '�'}
                        {m.quantity}
                      </span>
                    </td>
                    <td className="max-w-xs">
                      <div className="text-slate-700 truncate" title={m.notes}>
                        {m.notes || '�'}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
