import { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import {
  HiOutlineCalculator,
  HiOutlineCheckCircle,
  HiOutlineCube,
  HiOutlineDocumentText,
  HiOutlineExclamationTriangle,
  HiOutlineEye,
  HiOutlineQuestionMarkCircle,
  HiOutlineClipboardDocumentList,
  HiOutlineShoppingCart,
  HiOutlineStar,
  HiOutlineXMark,
  HiStar,
} from 'react-icons/hi2';

const TABS = [
  { id: 'overview', label: 'Overview' },
  { id: 'sales', label: 'Sales History' },
  { id: 'vouchers', label: 'Payment Vouchers' },
  { id: 'purchase_orders', label: 'Purchasing Order' },
  { id: 'reconciliation', label: 'Reconciliation' },
  { id: 'info', label: 'Replenishment Info' },
];

function formatDate(value) {
  if (!value) return 'ï¿½';
  const d = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return String(value);
  return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatNum(value, digits = 0) {
  const n = Number(value);
  if (!Number.isFinite(n)) return 'ï¿½';
  return n.toLocaleString(undefined, {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits > 0 ? digits : 2,
  });
}

function money(value, currency = 'TZS') {
  const n = Number(value);
  if (!Number.isFinite(n)) return `${currency} 0.00`;
  return `${currency} ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function statusPill(status) {
  const s = String(status || '').toLowerCase();
  if (['confirmed', 'invoiced', 'paid', 'delivered', 'completed'].includes(s)) {
    return { label: status ? String(status).replace(/_/g, ' ') : 'Confirmed', cls: 'is-ok' };
  }
  if (['cancelled', 'canceled', 'failed', 'rejected'].includes(s)) {
    return { label: String(status).replace(/_/g, ' '), cls: 'is-bad' };
  }
  return { label: status ? String(status).replace(/_/g, ' ') : 'Open', cls: 'is-muted' };
}

function dateKey(value) {
  if (!value) return '';
  const d = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return '';
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function RowThumb({ src }) {
  const [failed, setFailed] = useState(false);
  if (!src || failed) {
    return <span className="repl-trace-thumb is-empty" aria-hidden="true"><HiOutlineCube /></span>;
  }
  return (
    <span className="repl-trace-thumb">
      <img src={src} alt="" onError={() => setFailed(true)} />
    </span>
  );
}

export default function ProductTraceModal({
  product,
  investigateApiUrl = 'replenishment.php?action=investigate',
  referenceToggleUrl = '/toggle-voucher-reference.php',
  onClose,
}) {
  const [tab, setTab] = useState('overview');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [data, setData] = useState(null);
  const [pendingStarId, setPendingStarId] = useState(0);
  const [previewPoId, setPreviewPoId] = useState(0);
  const [previewLoading, setPreviewLoading] = useState(false);
  const [draftFilters, setDraftFilters] = useState({
    from: '',
    to: '',
    customer: '',
    type: '',
    warehouse: '',
  });
  const [filters, setFilters] = useState(draftFilters);

  useEffect(() => {
    if (!product?.id) return undefined;
    let alive = true;
    setLoading(true);
    setError('');
    setTab('overview');
    setPreviewPoId(0);
    setPreviewLoading(false);
    ;(async () => {
      try {
        const sep = investigateApiUrl.includes('?') ? '&' : '?';
        const res = await fetch(
          `${investigateApiUrl}${sep}product_id=${encodeURIComponent(product.id)}`,
          { credentials: 'same-origin', headers: { Accept: 'application/json' } },
        );
        const payload = await res.json().catch(() => null);
        if (!alive) return;
        if (!payload?.ok || !payload.data) {
          setError(payload?.error || 'Could not load investigation data.');
          setData(null);
          return;
        }
        setData(payload.data);
        const salesDates = (payload.data.sales_history || [])
          .map((row) => dateKey(row.date))
          .filter(Boolean);
        const voucherDates = (payload.data.supplier_invoices || [])
          .map((row) => dateKey(row.invoice_date || row.order_date))
          .filter(Boolean);
        const poDates = (payload.data.purchase_orders || [])
          .map((row) => dateKey(row.created_at))
          .filter(Boolean);
        const dates = [...salesDates, ...voucherDates, ...poDates].sort();
        const next = {
          from: dates[0] || '',
          to: dates[dates.length - 1] || '',
          customer: '',
          type: '',
          warehouse: '',
        };
        setDraftFilters(next);
        setFilters(next);
      } catch {
        if (alive) {
          setError('Network error while loading investigation data.');
          setData(null);
        }
      } finally {
        if (alive) setLoading(false);
      }
    })();
    return () => { alive = false };
  }, [product?.id, investigateApiUrl]);

  useEffect(() => {
    const onKey = (e) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
  }, [onClose]);

  const productInfo = data?.product || {
    name: product?.name,
    product_code: product?.product_code,
    image_url: product?.image_url,
    current_stock: product?.current_stock,
    reorder_level: product?.reorder_level ?? 10,
    needs_replenishment: Number(product?.current_stock ?? 0) < 0,
  };

  const filteredSales = useMemo(() => {
    const rows = data?.sales_history || [];
    return rows.filter((row) => {
      const key = dateKey(row.date);
      if (filters.from && key && key < filters.from) return false;
      if (filters.to && key && key > filters.to) return false;
      if (filters.customer && String(row.customer_name || '') !== filters.customer) return false;
      return true;
    });
  }, [data, filters]);

  const filteredVouchers = useMemo(() => {
    const rows = data?.supplier_invoices || [];
    return rows.filter((row) => {
      const key = dateKey(row.invoice_date || row.order_date);
      if (filters.from && key && key < filters.from) return false;
      if (filters.to && key && key > filters.to) return false;
      if (tab === 'vouchers' && filters.customer) {
        const payee = String(row.customer_name || '');
        if (payee !== filters.customer) return false;
      }
      return true;
    });
  }, [data, filters, tab]);

  const filteredPurchaseOrders = useMemo(() => {
    const rows = data?.purchase_orders || [];
    return rows.filter((row) => {
      const key = dateKey(row.created_at);
      if (filters.from && key && key < filters.from) return false;
      if (filters.to && key && key > filters.to) return false;
      if (filters.customer && String(row.supplier_name || '') !== filters.customer) return false;
      if (filters.type) {
        const typeLabel = String(row.purchase_type || '').toLowerCase();
        if (typeLabel !== String(filters.type).toLowerCase()) return false;
      }
      return true;
    });
  }, [data, filters]);

  const previewPo = useMemo(
    () => (data?.purchase_orders || []).find((row) => Number(row.id) === Number(previewPoId)) || null,
    [data, previewPoId],
  );

  const salesQty = filteredSales.reduce((sum, row) => sum + Number(row.quantity || 0), 0);
  const salesInvoices = new Set(
    filteredSales.map((r) => r.invoice_id || r.invoice_number || r.order_id).filter(Boolean),
  ).size;
  const currency = data?.sales_summary?.currency || 'TZS';
  const recon = data?.reconciliation || {};
  const stock = Number(productInfo.current_stock ?? 0);
  const threshold = Number(productInfo.reorder_level ?? 0);
  const voucherPayees = useMemo(() => {
    const names = new Set();
    (data?.supplier_invoices || []).forEach((row) => {
      const name = String(row.customer_name || '').trim();
      if (name) names.add(name);
    });
    return Array.from(names).sort((a, b) => a.localeCompare(b));
  }, [data]);

  const poSuppliers = useMemo(() => {
    const names = new Set();
    (data?.purchase_orders || []).forEach((row) => {
      const name = String(row.supplier_name || '').trim();
      if (name) names.add(name);
    });
    return Array.from(names).sort((a, b) => a.localeCompare(b));
  }, [data]);

  const filterPeople = tab === 'vouchers'
    ? voucherPayees
    : (tab === 'purchase_orders' ? poSuppliers : (data?.filters?.customers || []));
  const filterPeopleLabel = tab === 'vouchers'
    ? 'All Payees'
    : (tab === 'purchase_orders' ? 'All Suppliers' : 'All Customers');

  const applyFilters = () => setFilters({ ...draftFilters });
  const resetFilters = () => {
    const salesDates = (data?.sales_history || []).map((row) => dateKey(row.date)).filter(Boolean);
    const voucherDates = (data?.supplier_invoices || [])
      .map((row) => dateKey(row.invoice_date || row.order_date))
      .filter(Boolean);
    const poDates = (data?.purchase_orders || [])
      .map((row) => dateKey(row.created_at))
      .filter(Boolean);
    const dates = [...salesDates, ...voucherDates, ...poDates].sort();
    const next = {
      from: dates[0] || '',
      to: dates[dates.length - 1] || '',
      customer: '',
      type: '',
      warehouse: '',
    };
    setDraftFilters(next);
    setFilters(next);
  };

  const openPoPreview = (row) => {
    const id = Number(row?.id || 0);
    if (!id) return;
    if (previewPoId === id) {
      setPreviewPoId(0);
      setPreviewLoading(false);
      return;
    }
    setPreviewPoId(id);
    setPreviewLoading(true);
  };

  const applyVoucherStar = (voucherId, marked) => {
    setData((prev) => {
      if (!prev) return prev;
      const rows = Array.isArray(prev.supplier_invoices) ? prev.supplier_invoices : [];
      return {
        ...prev,
        supplier_invoices: rows.map((row) => (
          Number(row.invoice_id) === Number(voucherId)
            ? { ...row, is_reference: marked }
            : row
        )),
      };
    });
  };

  const toggleVoucherStar = async (row) => {
    const voucherId = Number(row?.invoice_id || 0);
    if (!voucherId || pendingStarId) return;
    const previous = !!row.is_reference;
    setPendingStarId(voucherId);
    applyVoucherStar(voucherId, !previous);
    try {
      const res = await fetch(referenceToggleUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `voucher_id=${encodeURIComponent(voucherId)}`,
      });
      const payload = await res.json().catch(() => null);
      if (!payload?.ok) {
        throw new Error(payload?.error || 'Could not save the mark.');
      }
      applyVoucherStar(voucherId, parseInt(String(payload.is_reference ?? '0'), 10) === 1);
    } catch {
      applyVoucherStar(voucherId, previous);
    } finally {
      setPendingStarId(0);
    }
  };

  const showOverview = tab === 'overview';
  const showSales = tab === 'overview' || tab === 'sales';
  const showVouchers = tab === 'overview' || tab === 'vouchers';
  const showPurchaseOrders = tab === 'purchase_orders';
  const showRecon = tab === 'overview' || tab === 'reconciliation';
  const showInfo = tab === 'overview' || tab === 'info';

  return createPortal(
    <div className="repl-trace" role="dialog" aria-modal="true" aria-labelledby="repl-trace-title">
      <button type="button" className="repl-trace__backdrop" aria-label="Close" onClick={onClose} />
      <div className="repl-trace__panel">
        <header className="repl-trace__head">
          <div>
            <h2 id="repl-trace-title">Product Sales Trace &amp; Stock Investigation</h2>
            <p>View sales, payment vouchers and the reason why this product appears on the replenishment list.</p>
          </div>
          <button type="button" className="repl-trace__x" onClick={onClose} aria-label="Close">
            <HiOutlineXMark />
          </button>
        </header>

        <div className="repl-trace__summary">
          <div className="repl-trace__card repl-trace__card--product">
            <RowThumb src={productInfo.image_url || ''} />
            <div className="repl-trace__product-meta">
              <div className="repl-trace__pname">{productInfo.name || 'Unnamed'}</div>
              <div className="repl-trace__pcode">{productInfo.product_code || 'ï¿½'}</div>
            </div>
            <div
              className={`repl-trace__status-flag${productInfo.needs_replenishment ? ' is-need' : ' is-ok'}`}
              title={productInfo.needs_replenishment ? 'Needs replenishment' : 'Stock OK'}
            >
              {productInfo.needs_replenishment ? 'Needs replenishment' : 'OK'}
            </div>
          </div>
          <div className="repl-trace__metrics">
            <div className={`repl-trace__card${stock < 0 ? ' is-neg' : ''}`}>
              <span>Current stock</span>
              <strong className={stock < 0 ? 'is-neg' : ''}>{formatNum(stock)}</strong>
            </div>
            <div className="repl-trace__card">
              <span>Threshold</span>
              <strong>{formatNum(threshold)}</strong>
            </div>
          </div>
        </div>

        <nav className="repl-trace__tabs" aria-label="Investigation sections">
          {TABS.map((t) => (
            <button
              key={t.id}
              type="button"
              className={tab === t.id ? 'is-active' : ''}
              onClick={() => setTab(t.id)}
            >
              {t.label}
            </button>
          ))}
        </nav>

        <div className="repl-trace__filters">
          <label>
            <span className="sr-only">From</span>
            <input
              type="date"
              value={draftFilters.from}
              onChange={(e) => setDraftFilters((f) => ({ ...f, from: e.target.value }))}
            />
          </label>
          <span className="repl-trace__dash">-</span>
          <label>
            <span className="sr-only">To</span>
            <input
              type="date"
              value={draftFilters.to}
              onChange={(e) => setDraftFilters((f) => ({ ...f, to: e.target.value }))}
            />
          </label>
          <select
            value={draftFilters.customer}
            onChange={(e) => setDraftFilters((f) => ({ ...f, customer: e.target.value }))}
          >
            <option value="">{filterPeopleLabel}</option>
            {filterPeople.map((c) => (
              <option key={c} value={c}>{c}</option>
            ))}
          </select>
          <button type="button" className="repl-trace__btn" onClick={resetFilters}>Reset</button>
          <button type="button" className="repl-trace__btn repl-trace__btn--primary" onClick={applyFilters}>
            Apply Filters
          </button>
        </div>

        <div className="repl-trace__body">
          {loading ? (
            <div className="repl-trace__loading">Loading investigationï¿½</div>
          ) : error ? (
            <div className="repl-trace__error">
              <HiOutlineExclamationTriangle />
              <span>{error}</span>
            </div>
          ) : (
            <div className={`repl-trace__layout${tab === 'overview' ? '' : ' is-single'}`}>
              <div className="repl-trace__main">
                {showSales ? (
                  <section className="repl-trace__section">
                    <header className="repl-trace__section-head">
                      <HiOutlineShoppingCart aria-hidden="true" />
                      <h3>Sales History</h3>
                    </header>
                    {showOverview ? (
                      <div className="repl-trace__kpi-row">
                        <div>
                          <span>Total Quantity Sold</span>
                          <strong>{formatNum(salesQty)}</strong>
                        </div>
                        <div>
                          <span>Total Invoices</span>
                          <strong>{formatNum(salesInvoices)}</strong>
                        </div>
                        <div>
                          <span>Total Sales Value</span>
                          <strong>{money(data?.sales_summary?.total_sales_value || 0, currency)}</strong>
                        </div>
                      </div>
                    ) : null}
                    <div className="repl-trace__table-wrap">
                      <table className="repl-trace__table">
                        <thead>
                          <tr>
                            <th>Date</th>
                            <th>Invoice No.</th>
                            <th>Customer</th>
                            <th className="is-right">Quantity</th>
                            <th>Status</th>
                            <th>Recorded By</th>
                            <th>Actions</th>
                          </tr>
                        </thead>
                        <tbody>
                          {filteredSales.length === 0 ? (
                            <tr>
                              <td colSpan={7} className="is-empty">No sales rows for the selected filters.</td>
                            </tr>
                          ) : (
                            filteredSales.map((row, idx) => {
                              const pill = statusPill(row.status);
                              const href = row.invoice_url || row.order_url || '';
                              const label = row.invoice_number || row.order_number || 'ï¿½';
                              return (
                                <tr key={`${row.invoice_id}-${row.order_id}-${idx}`}>
                                  <td>{formatDate(row.date)}</td>
                                  <td>
                                    {href ? (
                                      <a href={href} target="_blank" rel="noopener noreferrer">{label}</a>
                                    ) : label}
                                  </td>
                                  <td>{row.customer_name || 'ï¿½'}</td>
                                  <td className="is-right">{formatNum(row.quantity)}</td>
                                  <td><span className={`repl-trace-pill ${pill.cls}`}>{pill.label}</span></td>
                                  <td>{row.recorded_by || 'ï¿½'}</td>
                                  <td>
                                    {href ? (
                                      <a className="repl-trace-view" href={href} target="_blank" rel="noopener noreferrer">
                                        <HiOutlineEye /> View
                                      </a>
                                    ) : 'ï¿½'}
                                  </td>
                                </tr>
                              );
                            })
                          )}
                        </tbody>
                        {filteredSales.length > 0 ? (
                          <tfoot>
                            <tr>
                              <td colSpan={3}>Total Sold (Selected Period)</td>
                              <td className="is-right">{formatNum(salesQty)}</td>
                              <td colSpan={3} />
                            </tr>
                          </tfoot>
                        ) : null}
                      </table>
                    </div>
                  </section>
                ) : null}

                {showPurchaseOrders ? (
                  <section className="repl-trace__section">
                    <header className="repl-trace__section-head">
                      <HiOutlineClipboardDocumentList aria-hidden="true" />
                      <h3>Purchasing Order</h3>
                    </header>
                    <p className="repl-trace__hint">
                      Purchase orders that include this product. Select Preview to open the PO document here.
                    </p>
                    <div className="repl-trace__table-wrap">
                      <table className="repl-trace__table">
                        <thead>
                          <tr>
                            <th>Date</th>
                            <th>PO No.</th>
                            <th>Supplier</th>
                            <th>Type</th>
                            <th className="is-right">Ordered</th>
                            <th className="is-right">Received</th>
                            <th>Status</th>
                            <th>Actions</th>
                          </tr>
                        </thead>
                        <tbody>
                          {filteredPurchaseOrders.length === 0 ? (
                            <tr>
                              <td colSpan={8} className="is-empty">
                                No purchase orders were found for this product.
                              </td>
                            </tr>
                          ) : (
                            filteredPurchaseOrders.map((row) => {
                              const pill = statusPill(row.status);
                              const typeLabel = String(row.purchase_type || 'domestic')
                                .replace(/_/g, ' ')
                                .replace(/\b\w/g, (c) => c.toUpperCase());
                              const active = Number(previewPoId) === Number(row.id);
                              return (
                                <tr key={`${row.source}-${row.id}`} className={active ? 'is-active-row' : ''}>
                                  <td>{formatDate(row.created_at)}</td>
                                  <td>
                                    {row.view_url ? (
                                      <a href={row.view_url} target="_blank" rel="noopener noreferrer">
                                        {row.po_number || `PO-${row.id}`}
                                      </a>
                                    ) : (row.po_number || `PO-${row.id}`)}
                                  </td>
                                  <td>{row.supplier_name || '—'}</td>
                                  <td>{typeLabel}</td>
                                  <td className="is-right">{formatNum(row.qty_ordered)}</td>
                                  <td className="is-right">{formatNum(row.qty_received)}</td>
                                  <td><span className={`repl-trace-pill ${pill.cls}`}>{pill.label}</span></td>
                                  <td>
                                    <div className="repl-trace-po-actions">
                                      <button
                                        type="button"
                                        className={`repl-trace-view-btn${active ? ' is-active' : ''}`}
                                        onClick={() => openPoPreview(row)}
                                      >
                                        <HiOutlineEye aria-hidden="true" />
                                        {active ? 'Hide' : 'Preview'}
                                      </button>
                                      {row.view_url ? (
                                        <a
                                          className="repl-trace-view"
                                          href={row.view_url}
                                          target="_blank"
                                          rel="noopener noreferrer"
                                        >
                                          Open
                                        </a>
                                      ) : null}
                                    </div>
                                  </td>
                                </tr>
                              );
                            })
                          )}
                        </tbody>
                      </table>
                    </div>

                    {previewPo ? (
                      <div className="repl-trace-po-preview">
                        <div className="repl-trace-po-preview__bar">
                          <div>
                            <strong>{previewPo.po_number || `PO-${previewPo.id}`}</strong>
                            <span>
                              {previewPo.supplier_name ? ` · ${previewPo.supplier_name}` : ''}
                              {previewPo.status ? ` · ${String(previewPo.status).replace(/_/g, ' ')}` : ''}
                            </span>
                          </div>
                          <div className="repl-trace-po-preview__bar-actions">
                            {previewPo.view_url ? (
                              <a href={previewPo.view_url} target="_blank" rel="noopener noreferrer">
                                Open full page
                              </a>
                            ) : null}
                            <button
                              type="button"
                              className="repl-trace__btn"
                              onClick={() => {
                                setPreviewPoId(0);
                                setPreviewLoading(false);
                              }}
                            >
                              Close preview
                            </button>
                          </div>
                        </div>
                        <div className={`repl-trace-po-preview__frame-wrap${previewLoading ? ' is-loading' : ''}`}>
                          {previewLoading ? (
                            <div className="repl-trace-po-preview__loading">Loading purchase order…</div>
                          ) : null}
                          <iframe
                            title={`Purchase order ${previewPo.po_number || previewPo.id}`}
                            className={`repl-trace-po-preview__frame${previewLoading ? ' is-pending' : ''}`}
                            src={previewPo.preview_url || previewPo.view_url}
                            onLoad={() => setPreviewLoading(false)}
                          />
                        </div>
                      </div>
                    ) : null}
                  </section>
                ) : null}

                {showVouchers ? (
                  <section className="repl-trace__section">
                    <header className="repl-trace__section-head">
                      <HiOutlineDocumentText aria-hidden="true" />
                      <h3>Payment Vouchers</h3>
                    </header>
                    {filteredVouchers.length ? (
                      <p className="repl-trace__hint">
                        Open the voucher to see the supplier invoice.
                      </p>
                    ) : null}
                    <div className="repl-trace__table-wrap">
                      <table className="repl-trace__table">
                        <thead>
                          <tr>
                            <th className="is-center">Checked</th>
                            <th>Payment voucher</th>
                            <th>Our invoice</th>
                            <th>Payee</th>
                            <th>Status</th>
                            <th>Date</th>
                          </tr>
                        </thead>
                        <tbody>
                          {filteredVouchers.length === 0 ? (
                            <tr>
                              <td colSpan={6} className="is-empty">
                                No payment voucher is attached to this product&apos;s sales invoices.
                              </td>
                            </tr>
                          ) : (
                            filteredVouchers.map((row, idx) => (
                              <tr key={`${row.invoice_id || 0}-${row.order_id || 0}-${idx}`}>
                                <td className="is-center">
                                  <button
                                    type="button"
                                    className={`repl-desk-star${row.is_reference ? ' is-marked' : ''}`}
                                    title={row.is_reference
                                      ? 'Checked. Click to clear. This is the same star as on the payment voucher.'
                                      : 'Mark this voucher as checked. This is the same star as on the payment voucher.'}
                                    aria-label={row.is_reference ? 'Clear checked mark' : 'Mark voucher as checked'}
                                    aria-pressed={!!row.is_reference}
                                    disabled={!row.invoice_id || pendingStarId === Number(row.invoice_id)}
                                    onClick={(e) => {
                                      e.stopPropagation();
                                      toggleVoucherStar(row);
                                    }}
                                  >
                                    {row.is_reference
                                      ? <HiStar aria-hidden="true" />
                                      : <HiOutlineStar aria-hidden="true" />}
                                  </button>
                                </td>
                                <td>
                                  {row.invoice_id ? (
                                    <a href={row.invoice_url} target="_blank" rel="noopener noreferrer">
                                      {row.invoice_number || `PV-${row.invoice_id}`}
                                    </a>
                                  ) : (
                                    <span className="repl-trace__muted">No voucher</span>
                                  )}
                                </td>
                                <td>{row.our_invoice || row.order_number || 'ï¿½'}</td>
                                <td>{row.customer_name || 'ï¿½'}</td>
                                <td>{row.order_status || row.invoice_status || 'ï¿½'}</td>
                                <td>{formatDate(row.invoice_date || row.order_date)}</td>
                              </tr>
                            ))
                          )}
                        </tbody>
                      </table>
                    </div>
                  </section>
                ) : null}

                {showRecon && tab !== 'overview' ? (
                  <section className="repl-trace__section">
                    <header className="repl-trace__section-head">
                      <HiOutlineCalculator aria-hidden="true" />
                      <h3>Stock Reconciliation</h3>
                    </header>
                    <ReconBlock recon={recon} />
                  </section>
                ) : null}

                {showInfo && tab === 'info' ? (
                  <section className="repl-trace__section">
                    <header className="repl-trace__section-head">
                      <HiOutlineQuestionMarkCircle aria-hidden="true" />
                      <h3>Replenishment Info</h3>
                    </header>
                    <p className="repl-trace__why-text">{data?.why_on_list || 'ï¿½'}</p>
                    <p className="repl-trace__hint">
                      Creating a purchase order does not remove this product from the list until warehouse stock is
                      received or adjusted to zero or above.
                    </p>
                  </section>
                ) : null}
              </div>

              {tab === 'overview' ? (
                <aside className="repl-trace__side">
                  <section className="repl-trace__side-card">
                    <h4>Key Information</h4>
                    <dl>
                      <div><dt>Product</dt><dd>{productInfo.name || 'ï¿½'}</dd></div>
                      <div><dt>Code</dt><dd>{productInfo.product_code || 'ï¿½'}</dd></div>
                      <div>
                        <dt>Current Stock</dt>
                        <dd className={stock < 0 ? 'is-neg' : ''}>{formatNum(stock)}</dd>
                      </div>
                      <div><dt>Threshold</dt><dd>{formatNum(threshold)}</dd></div>
                    </dl>
                  </section>

                  <section className="repl-trace__side-card">
                    <h4>
                      <HiOutlineQuestionMarkCircle aria-hidden="true" />
                      Why this product is on the list?
                    </h4>
                    <p>{data?.why_on_list || 'ï¿½'}</p>
                  </section>

                  {showRecon ? (
                    <section className="repl-trace__side-card">
                      <h4>
                        <HiOutlineCalculator aria-hidden="true" />
                        Stock Reconciliation
                      </h4>
                      <ReconBlock recon={recon} compact />
                    </section>
                  ) : null}
                </aside>
              ) : null}
            </div>
          )}
        </div>

        <footer className="repl-trace__foot">
          <button type="button" className="repl-trace__btn repl-trace__btn--primary" onClick={onClose}>
            Close
          </button>
        </footer>
      </div>
    </div>,
    document.body,
  );
}

function ReconBlock({ recon, compact = false }) {
  const rows = [
    ['Opening Stock', recon.opening_stock],
    ['Goods Received', recon.goods_received],
    ['Sales (Deductions)', recon.sales_deductions],
    ['Returns In', recon.returns_in],
    ['Adjustments', recon.adjustments],
    ['Transfers In/Out', recon.transfers_net],
  ];
  return (
    <div className={`repl-trace-recon${compact ? ' is-compact' : ''}`}>
      <ul>
        {rows.map(([label, value]) => (
          <li key={label}>
            <span>{label}</span>
            <strong className={Number(value) < 0 ? 'is-neg' : ''}>{formatNum(value)}</strong>
          </li>
        ))}
      </ul>
      <div className="repl-trace-recon__totals">
        <div>
          <span>Expected Stock</span>
          <strong className={Number(recon.expected_stock) < 0 ? 'is-neg' : ''}>
            {formatNum(recon.expected_stock)}
          </strong>
        </div>
        <div>
          <span>Current Stock</span>
          <strong className={Number(recon.current_stock) < 0 ? 'is-neg' : ''}>
            {formatNum(recon.current_stock)}
          </strong>
        </div>
      </div>
      {recon.has_discrepancy ? (
        <div className="repl-trace-recon__msg is-bad">
          <HiOutlineExclamationTriangle aria-hidden="true" />
          <span>Discrepancy found. Expected stock does not match the current warehouse balance.</span>
        </div>
      ) : (
        <div className="repl-trace-recon__msg is-ok">
          <HiOutlineCheckCircle aria-hidden="true" />
          <span>No discrepancy found. The stock balance matches the calculated value.</span>
        </div>
      )}
    </div>
  );
}
