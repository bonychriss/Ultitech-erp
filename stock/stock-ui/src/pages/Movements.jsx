import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  HiOutlineAdjustmentsHorizontal,
  HiOutlineArrowDown,
  HiOutlineArrowDownTray,
  HiOutlineArrowUp,
  HiOutlineArrowUpTray,
  HiOutlineCamera,
  HiOutlineCheck,
  HiOutlineClipboardDocumentList,
  HiOutlineCube,
  HiOutlineXMark,
} from 'react-icons/hi2';
import './movements-desk.css';

function formatDay(dateStr) {
  if (!dateStr) return '—';
  const normalized = String(dateStr).includes('T')
    ? String(dateStr)
    : String(dateStr).replace(' ', 'T');
  const d = new Date(normalized);
  if (Number.isNaN(d.getTime())) return String(dateStr);
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function formatTime(dateStr) {
  if (!dateStr) return '';
  const normalized = String(dateStr).includes('T')
    ? String(dateStr)
    : String(dateStr).replace(' ', 'T');
  const d = new Date(normalized);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false });
}

function typeLabel(type) {
  return String(type || '').toLowerCase() === 'out' ? 'OUT' : 'IN';
}

function MovThumb({ src }) {
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
      <span className="mov-desk-thumb is-empty" aria-hidden="true">
        <HiOutlineCamera style={{ width: 16, height: 16 }} />
      </span>
    );
  }

  return (
    <span className={`mov-desk-thumb${loaded ? ' is-loaded' : ' is-loading'}`} aria-busy={!loaded}>
      {!loaded ? <span className="mov-desk-thumb-skeleton" aria-hidden="true" /> : null}
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

function buildCsvBlob(rows) {
  const headers = ['Date', 'Time', 'Product', 'Code', 'Type', 'Quantity'];
  const lines = [headers.join(',')];
  rows.forEach((row) => {
    const qty = Number(row.quantity || 0);
    const cols = [
      formatDay(row.created_at),
      formatTime(row.created_at),
      row.product_name || '',
      row.product_code || '',
      typeLabel(row.movement_type),
      qty,
    ].map((v) => `"${String(v).replace(/"/g, '""')}"`);
    lines.push(cols.join(','));
  });
  return new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
}

function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}

export default function Movements({ data }) {
  const {
    movements = [],
    products = [],
    product_id: initialProductId = '',
    type: initialType = '',
    start_date: initialStart = '',
    end_date: initialEnd = '',
    formAction = 'movements.php',
    stats = null,
  } = data || {};

  const [productId, setProductId] = useState(String(initialProductId || ''));
  const [type, setType] = useState(String(initialType || ''));
  const [startDate, setStartDate] = useState(String(initialStart || ''));
  const [endDate, setEndDate] = useState(String(initialEnd || ''));
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [exportState, setExportState] = useState('idle'); // idle | loading | done
  const filterWrapRef = useRef(null);
  const exportTimerRef = useRef(null);

  const filtersActive = Boolean(productId || type || startDate || endDate);

  useEffect(() => () => {
    if (exportTimerRef.current) clearTimeout(exportTimerRef.current);
  }, []);

  useEffect(() => {
    if (!filtersOpen) return undefined;
    const onPointerDown = (e) => {
      if (!filterWrapRef.current?.contains(e.target)) setFiltersOpen(false);
    };
    const onKeyDown = (e) => {
      if (e.key === 'Escape') setFiltersOpen(false);
    };
    document.addEventListener('mousedown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('mousedown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [filtersOpen]);

  const runExport = () => {
    if (!movements.length || exportState === 'loading') return;
    if (exportTimerRef.current) clearTimeout(exportTimerRef.current);
    setExportState('loading');

    exportTimerRef.current = setTimeout(() => {
      try {
        const blob = buildCsvBlob(movements);
        downloadBlob(blob, `stock-movements-${new Date().toISOString().slice(0, 10)}.csv`);
        setExportState('done');
        exportTimerRef.current = setTimeout(() => {
          setExportState('idle');
          exportTimerRef.current = null;
        }, 1600);
      } catch (err) {
        setExportState('idle');
        exportTimerRef.current = null;
      }
    }, 650);
  };

  const computedStats = useMemo(() => {
    if (stats && typeof stats === 'object') {
      return {
        total_in: Number(stats.total_in || 0),
        total_out: Number(stats.total_out || 0),
        net: Number(stats.net != null ? stats.net : (Number(stats.total_in || 0) - Number(stats.total_out || 0))),
      };
    }
    let totalIn = 0;
    let totalOut = 0;
    movements.forEach((m) => {
      const qty = Math.abs(Number(m.quantity || 0));
      if (String(m.movement_type || '').toLowerCase() === 'out') totalOut += qty;
      else totalIn += qty;
    });
    return { total_in: totalIn, total_out: totalOut, net: totalIn - totalOut };
  }, [movements, stats]);

  const sortedMovements = useMemo(() => {
    const rows = Array.isArray(movements) ? [...movements] : [];
    rows.sort((a, b) => {
      const ta = Date.parse(String(a.created_at || '').replace(' ', 'T')) || 0;
      const tb = Date.parse(String(b.created_at || '').replace(' ', 'T')) || 0;
      if (tb !== ta) return tb - ta;
      return Number(b.id || 0) - Number(a.id || 0);
    });
    return rows;
  }, [movements]);

  const navigateWithFilters = (next = {}) => {
    const nextProduct = next.productId !== undefined ? next.productId : productId;
    const nextType = next.type !== undefined ? next.type : type;
    const nextStart = next.startDate !== undefined ? next.startDate : startDate;
    const nextEnd = next.endDate !== undefined ? next.endDate : endDate;
    const params = new URLSearchParams();
    if (nextProduct) params.set('product_id', nextProduct);
    if (nextType) params.set('type', nextType);
    if (nextStart) params.set('start_date', nextStart);
    if (nextEnd) params.set('end_date', nextEnd);
    const qs = params.toString();
    window.location.href = qs ? `${formAction}?${qs}` : formAction;
  };

  const applyFilters = (e) => {
    e.preventDefault();
    setFiltersOpen(false);
    navigateWithFilters();
  };

  const resetFilters = () => {
    setFiltersOpen(false);
    window.location.href = formAction;
  };

  const net = Number(computedStats.net || 0);

  return (
    <div className="mov-desk">
      <div className="mov-desk-toolbar">
        <div className="mov-desk-toolbar-actions">
          <div className="mov-desk-filter-wrap" ref={filterWrapRef}>
            <button
              type="button"
              className={`mov-desk-filter-btn${filtersOpen || filtersActive ? ' is-active' : ''}`}
              onClick={() => setFiltersOpen((v) => !v)}
              aria-expanded={filtersOpen}
              title="Filters"
            >
              <HiOutlineAdjustmentsHorizontal style={{ width: 18, height: 18 }} aria-hidden="true" />
              {filtersActive ? <span className="mov-desk-filter-dot" aria-hidden="true" /> : null}
            </button>
            {filtersOpen ? (
              <form
                className="mov-desk-filter-panel"
                role="dialog"
                aria-label="Movement filters"
                onSubmit={applyFilters}
              >
                <div className="mov-desk-filter-panel-head">
                  <div>
                    <h3>Filters</h3>
                    <p>Product, direction, and dates</p>
                  </div>
                  <button
                    type="button"
                    className="mov-desk-filter-close"
                    onClick={() => setFiltersOpen(false)}
                    aria-label="Close"
                  >
                    <HiOutlineXMark style={{ width: 16, height: 16 }} />
                  </button>
                </div>
                <div className="mov-desk-filter-grid">
                  <div className="mov-desk-field">
                    <label htmlFor="mov-product">Product</label>
                    <select
                      id="mov-product"
                      className="mov-desk-select"
                      value={productId}
                      onChange={(e) => setProductId(e.target.value)}
                    >
                      <option value="">All products</option>
                      {products.map((p) => (
                        <option key={p.id} value={String(p.id)}>
                          {p.product_code}
                          {' - '}
                          {p.name}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div className="mov-desk-field">
                    <label htmlFor="mov-type">Direction</label>
                    <select
                      id="mov-type"
                      className="mov-desk-select"
                      value={type}
                      onChange={(e) => setType(e.target.value)}
                    >
                      <option value="">In &amp; Out</option>
                      <option value="in">In only</option>
                      <option value="out">Out only</option>
                    </select>
                  </div>
                  <div className="mov-desk-field">
                    <label>Date range</label>
                    <div className="mov-desk-dates">
                      <input
                        type="date"
                        className="mov-desk-input"
                        value={startDate}
                        onChange={(e) => setStartDate(e.target.value)}
                        aria-label="Start date"
                      />
                      <span>to</span>
                      <input
                        type="date"
                        className="mov-desk-input"
                        value={endDate}
                        onChange={(e) => setEndDate(e.target.value)}
                        aria-label="End date"
                      />
                    </div>
                  </div>
                </div>
                <div className="mov-desk-filter-panel-actions">
                  <button type="button" className="mov-desk-btn" onClick={resetFilters}>
                    Reset
                  </button>
                  <button type="submit" className="mov-desk-btn mov-desk-btn--primary">
                    Apply
                  </button>
                </div>
              </form>
            ) : null}
          </div>
          <button
            type="button"
            className={`mov-desk-export${exportState !== 'idle' ? ` is-${exportState}` : ''}`}
            onClick={runExport}
            disabled={!movements.length || exportState === 'loading'}
            aria-busy={exportState === 'loading'}
          >
            {exportState === 'loading' ? (
              <>
                <span className="mov-desk-export-spinner" aria-hidden="true" />
                Exporting…
              </>
            ) : exportState === 'done' ? (
              <>
                <HiOutlineCheck style={{ width: 15, height: 15 }} className="mov-desk-export-check" />
                Exported
              </>
            ) : (
              <>
                <HiOutlineArrowDownTray style={{ width: 15, height: 15 }} className="mov-desk-export-arrow" />
                Export CSV
              </>
            )}
          </button>
        </div>
      </div>

      <div className="mov-desk-kpis mov-desk-kpis--3">
        <div className="mov-desk-kpi">
          <span className="mov-desk-kpi-icon mov-desk-kpi-icon--in" aria-hidden="true">
            <HiOutlineArrowDown style={{ width: 20, height: 20 }} />
          </span>
          <div>
            <div className="mov-desk-kpi-label">Total In</div>
            <div className="mov-desk-kpi-value">{Number(computedStats.total_in || 0).toLocaleString()}</div>
          </div>
        </div>
        <div className="mov-desk-kpi">
          <span className="mov-desk-kpi-icon mov-desk-kpi-icon--out" aria-hidden="true">
            <HiOutlineArrowUp style={{ width: 20, height: 20 }} />
          </span>
          <div>
            <div className="mov-desk-kpi-label">Total Out</div>
            <div className="mov-desk-kpi-value">{Number(computedStats.total_out || 0).toLocaleString()}</div>
          </div>
        </div>
        <div className="mov-desk-kpi">
          <span className="mov-desk-kpi-icon mov-desk-kpi-icon--net" aria-hidden="true">
            <HiOutlineCube style={{ width: 20, height: 20 }} />
          </span>
          <div>
            <div className="mov-desk-kpi-label">Net</div>
            <div className={`mov-desk-kpi-value${net > 0 ? ' is-pos' : net < 0 ? ' is-neg' : ''}`}>
              {net > 0 ? '+' : ''}
              {net.toLocaleString()}
            </div>
          </div>
        </div>
      </div>

      <div className="mov-desk-panel">
        <div className="mov-desk-panel-head">
          <h2 className="mov-desk-panel-title">
            Stock movements (
            {movements.length}
            )
          </h2>
        </div>

        <div className="mov-desk-table-wrap">
          <table className="mov-desk-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Product</th>
                <th>Type</th>
                <th className="is-center">Quantity</th>
              </tr>
            </thead>
            <tbody>
              {sortedMovements.length === 0 ? (
                <tr>
                  <td colSpan={4}>
                    <div className="mov-desk-empty">
                      <div className="mov-desk-empty-icon">
                        <HiOutlineClipboardDocumentList style={{ width: 48, height: 48 }} />
                      </div>
                      <p>No stock movements found.</p>
                    </div>
                  </td>
                </tr>
              ) : (
                sortedMovements.map((row, idx) => {
                  const qty = Number(row.quantity || 0);
                  const isOut = String(row.movement_type || '').toLowerCase() === 'out';
                  return (
                    <tr key={row.id != null ? row.id : `${row.created_at}-${idx}`}>
                      <td>
                        <div className="mov-desk-date">{formatDay(row.created_at)}</div>
                        <div className="mov-desk-time">{formatTime(row.created_at)}</div>
                      </td>
                      <td>
                        <div className="mov-desk-product">
                          <MovThumb src={row.image_url || ''} />
                          <div className="mov-desk-product-text">
                            <div className="mov-desk-prod-name">{row.product_name || '—'}</div>
                            <div className="mov-desk-prod-code">{row.product_code || ''}</div>
                          </div>
                        </div>
                      </td>
                      <td>
                        <span
                          className={`mov-desk-type ${isOut ? 'is-out' : 'is-in'}`}
                          title={typeLabel(row.movement_type)}
                        >
                          {isOut ? (
                            <HiOutlineArrowUpTray style={{ width: 16, height: 16 }} aria-hidden="true" />
                          ) : (
                            <HiOutlineArrowDownTray style={{ width: 16, height: 16 }} aria-hidden="true" />
                          )}
                          <span>{typeLabel(row.movement_type)}</span>
                        </span>
                      </td>
                      <td>
                        <div className={`mov-desk-qty${isOut || qty < 0 ? ' is-neg' : ' is-pos'}`}>
                          {isOut && qty > 0 ? '-' : qty > 0 ? '+' : ''}
                          {Math.abs(qty).toLocaleString()}
                        </div>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>

        <div className="mov-desk-footer">
          <span className="mov-desk-footer-meta">
            {sortedMovements.length.toLocaleString()}
            {' '}
            {sortedMovements.length === 1 ? 'movement' : 'movements'}
            {' '}
            (newest first)
          </span>
        </div>
      </div>
    </div>
  );
}
