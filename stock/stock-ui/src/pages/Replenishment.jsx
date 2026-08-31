import React, { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import {
  HiOutlineArrowDownTray,
  HiOutlineArrowLeft,
  HiOutlineCamera,
  HiOutlineBuildingOffice2,
  HiOutlineCheck,
  HiOutlineCheckCircle,
  HiOutlineDocumentText,
  HiOutlineGlobeAlt,
  HiOutlineMagnifyingGlass,
  HiOutlineQuestionMarkCircle,
  HiOutlineShoppingCart,
  HiOutlineXMark,
} from 'react-icons/hi2';
import './replenishment-desk.css';

function formatDate(value) {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return String(value);
  return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function RowThumb({ src }) {
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
      <span className="repl-desk-thumb is-empty" aria-hidden="true">
        <HiOutlineCamera style={{ width: 16, height: 16 }} />
      </span>
    );
  }

  return (
    <span className={`repl-desk-thumb${loaded ? ' is-loaded' : ' is-loading'}`} aria-busy={!loaded}>
      {!loaded ? <span className="repl-desk-thumb-skeleton" aria-hidden="true" /> : null}
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

function PoTypeModal({ order, urls, onClose }) {
  const [navigating, setNavigating] = useState(null); // 'internal' | 'abroad' | null

  useEffect(() => {
    if (!order) return undefined;
    const onKey = (e) => {
      if (e.key === 'Escape' && !navigating) onClose();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [order, onClose, navigating]);

  if (!order) return null;

  const q = Math.max(1, Number(order.qty) || 1);
  const extra =
    `&product_name=${encodeURIComponent(order.productName || '')}`
    + `&product_code=${encodeURIComponent(order.productCode || '')}`;

  const go = (kind) => {
    if (navigating) return;
    setNavigating(kind);
    const href = kind === 'internal'
      ? `${urls.createDomestic}?product_id=${order.productId}&qty=${q}${extra}`
      : `${urls.createDomestic}?purchase_type=import&product_id=${order.productId}&qty=${q}${extra}`;
    // Let React paint the loading state before navigation.
    window.setTimeout(() => {
      window.location.href = href;
    }, 30);
  };

  return createPortal(
    <div
      className="repl-desk-modal-backdrop"
      role="presentation"
      onClick={() => {
        if (!navigating) onClose();
      }}
    >
      <div
        className="repl-desk-modal repl-desk-po-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="repl-po-modal-title"
        aria-busy={!!navigating}
        onClick={(e) => e.stopPropagation()}
      >
        <button
          type="button"
          className="repl-desk-modal-close"
          aria-label="Close"
          disabled={!!navigating}
          onClick={onClose}
        >
          <HiOutlineXMark style={{ width: 18, height: 18 }} />
        </button>

        {navigating ? (
          <div className="repl-desk-po-loading">
            <span className="repl-desk-po-spinner" aria-hidden="true" />
            <h3 id="repl-po-modal-title" className="repl-desk-modal-title">
              Opening
              {' '}
              {navigating === 'internal' ? 'internal' : 'abroad'}
              {' '}
              PO…
            </h3>
            <p className="repl-desk-modal-text">
              Preparing the purchase order form. This can take a moment.
            </p>
          </div>
        ) : (
          <>
            <div className="repl-desk-po-icon" aria-hidden="true">
              <HiOutlineQuestionMarkCircle style={{ width: 28, height: 28 }} />
            </div>
            <h3 id="repl-po-modal-title" className="repl-desk-modal-title">
              Create purchase order
            </h3>
            <p className="repl-desk-modal-text">
              {order.productName
                ? `Choose a PO type for ${order.productName} (qty ${q}).`
                : 'Which PO type do you want?'}
            </p>

            <div className="repl-desk-po-actions">
              <button
                type="button"
                className="repl-desk-po-btn repl-desk-po-btn--internal"
                onClick={() => go('internal')}
              >
                <HiOutlineBuildingOffice2 style={{ width: 18, height: 18 }} />
                Internal
              </button>
              <button
                type="button"
                className="repl-desk-po-btn repl-desk-po-btn--abroad"
                onClick={() => go('abroad')}
              >
                <HiOutlineGlobeAlt style={{ width: 18, height: 18 }} />
                Abroad
              </button>
              <button type="button" className="repl-desk-po-btn repl-desk-po-btn--cancel" onClick={onClose}>
                Cancel
              </button>
            </div>
          </>
        )}
      </div>
    </div>,
    document.body,
  );
}

function DemandPanel({ product, cacheEntry, loading }) {
  const pending = Number(product.pending_demand ?? 0);
  const name = product.name || '';

  if (loading) {
    return (
      <div className="repl-desk-detail-panel">
        <h4>Invoices / orders driving replenishment</h4>
        <div className="repl-desk-detail-msg">Loading invoices…</div>
      </div>
    );
  }

  if (cacheEntry?.error) {
    return (
      <div className="repl-desk-detail-panel">
        <h4>Invoices / orders driving replenishment</h4>
        <div className="repl-desk-detail-msg">{cacheEntry.error}</div>
      </div>
    );
  }

  const items = cacheEntry?.items || [];
  if (!items.length) {
    const reason = pending > 0
      ? 'No matching sales orders or invoices were found for this product. They may be on a different company database or already fulfilled.'
      : 'No open sales invoices were found for this product. It may be listed due to low stock or reorder level only.';
    return (
      <div className="repl-desk-detail-panel">
        <h4>Invoices / orders driving replenishment</h4>
        <div className="repl-desk-detail-msg">
          {reason}
          {name ? <span className="repl-desk-muted"> ({name})</span> : null}
        </div>
      </div>
    );
  }

  return (
    <div className="repl-desk-detail-panel">
      <h4>Invoices / orders driving replenishment</h4>
      <div className="repl-desk-table-wrap">
        <table className="repl-desk-invoice-table">
          <thead>
            <tr>
              <th>Invoice</th>
              <th>Sales order</th>
              <th>Customer</th>
              <th className="is-center">Qty</th>
              <th>Status</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            {items.map((item, idx) => (
              <tr key={`${item.invoice_id || 0}-${item.order_id || 0}-${idx}`}>
                <td>
                  {item.invoice_id ? (
                    <a href={item.invoice_url} target="_blank" rel="noopener noreferrer">
                      {item.invoice_number || `INV-${item.invoice_id}`}
                    </a>
                  ) : (
                    <span className="repl-desk-muted">Not invoiced</span>
                  )}
                </td>
                <td>
                  {item.order_id ? (
                    <a href={item.order_url} target="_blank" rel="noopener noreferrer">
                      {item.order_number || `SO-${item.order_id}`}
                    </a>
                  ) : (
                    '—'
                  )}
                </td>
                <td>{item.customer_name || '—'}</td>
                <td className="is-center">{item.line_qty}</td>
                <td>{item.order_status || item.invoice_status || '—'}</td>
                <td>{formatDate(item.invoice_date || item.order_date)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function documentEmbedUrl(row) {
  let url = '';
  if (row?.invoice_print_url) url = row.invoice_print_url;
  else if (row?.invoice_url) {
    url = String(row.invoice_url).replace('/invoices/view.php', '/invoices/print.php');
  } else {
    url = row?.order_url || '';
  }
  if (!url) return '';
  if (url.includes('/invoices/print.php') && !/[?&]embed=1(?:&|$)/.test(url)) {
    url += (url.includes('?') ? '&' : '?') + 'embed=1';
  }
  return url;
}

function ReferenceInvoices({ productName = '', references = [], demandSources = [] }) {
  const [open, setOpen] = useState(false);
  const [preview, setPreview] = useState(null);
  const [previewLoading, setPreviewLoading] = useState(false);
  const [downloadState, setDownloadState] = useState('idle'); // idle | loading | done
  const iframeRef = useRef(null);
  const downloadTimerRef = useRef(null);
  const count = references.length;

  const closeModal = () => {
    if (downloadTimerRef.current) {
      clearTimeout(downloadTimerRef.current);
      downloadTimerRef.current = null;
    }
    setPreview(null);
    setPreviewLoading(false);
    setDownloadState('idle');
    setOpen(false);
  };

  const openPreview = (next) => {
    if (downloadTimerRef.current) {
      clearTimeout(downloadTimerRef.current);
      downloadTimerRef.current = null;
    }
    setDownloadState('idle');
    setPreviewLoading(true);
    setPreview(next);
  };

  const downloadPreviewPdf = () => {
    if (downloadState === 'loading') return;
    const win = iframeRef.current?.contentWindow;
    setDownloadState('loading');
    try {
      if (win && typeof win.downloadPDF === 'function') {
        win.downloadPDF();
        return;
      }
      if (preview?.src) {
        const openUrl = preview.src.replace(/([?&])embed=1(&|$)/, '$1').replace(/[?&]$/, '');
        window.open(
          openUrl.includes('download=') ? openUrl : `${openUrl}${openUrl.includes('?') ? '&' : '?'}download=1`,
          '_blank',
          'noopener,noreferrer',
        );
      }
      // Fallback path (no iframe callback): brief live wait then done
      if (downloadTimerRef.current) clearTimeout(downloadTimerRef.current);
      downloadTimerRef.current = setTimeout(() => {
        setDownloadState('done');
        downloadTimerRef.current = setTimeout(() => {
          setDownloadState('idle');
          downloadTimerRef.current = null;
        }, 1400);
      }, 1200);
    } catch {
      setDownloadState('idle');
    }
  };

  useEffect(() => () => {
    if (downloadTimerRef.current) clearTimeout(downloadTimerRef.current);
  }, []);

  useEffect(() => {
    if (!open || !preview) return undefined;

    const onMessage = (event) => {
      if (event.origin !== window.location.origin) return;
      const data = event.data || {};
      if (data.type === 'delivery-doc-pdf-start') {
        setDownloadState('loading');
        return;
      }
      if (data.type !== 'delivery-doc-pdf') return;
      if (downloadTimerRef.current) {
        clearTimeout(downloadTimerRef.current);
        downloadTimerRef.current = null;
      }
      if (data.ok) {
        setDownloadState('done');
        downloadTimerRef.current = setTimeout(() => {
          setDownloadState('idle');
          downloadTimerRef.current = null;
        }, 1600);
      } else {
        setDownloadState('idle');
      }
    };

    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  }, [open, preview]);

  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => {
      if (e.key !== 'Escape') return;
      if (preview) {
        setPreview(null);
        setPreviewLoading(false);
        setDownloadState('idle');
      } else closeModal();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, preview]);

  if (!count) {
    return <span className="repl-desk-muted">—</span>;
  }

  const rows = demandSources.length
    ? demandSources
    : references.map((ref) => ({
        invoice_id: ref.type === 'invoice' ? ref.id : 0,
        invoice_number: ref.type === 'invoice' ? ref.label : '',
        invoice_url: ref.type === 'invoice' ? ref.url : '',
        invoice_print_url: ref.type === 'invoice' ? ref.print_url || '' : '',
        order_id: ref.type === 'order' ? ref.id : 0,
        order_number: ref.type === 'order' ? ref.label : '',
        order_url: ref.type === 'order' ? ref.url : '',
        customer_name: '',
        line_qty: '',
        order_status: '',
        invoice_status: '',
        invoice_date: '',
        order_date: '',
      }));

  const modal = open
    ? createPortal(
      <div
        className="repl-desk-modal-backdrop"
        role="presentation"
        onClick={closeModal}
      >
        <div
          className={`repl-desk-modal${preview ? ' is-preview' : ''}`}
          role="dialog"
          aria-modal="true"
          aria-labelledby="repl-inv-modal-title"
          onClick={(e) => e.stopPropagation()}
        >
          <button
            type="button"
            className="repl-desk-modal-close"
            aria-label="Close"
            onClick={closeModal}
          >
            <HiOutlineXMark style={{ width: 18, height: 18 }} />
          </button>

          {preview ? (
            <>
              <div className="repl-desk-modal-preview-bar">
                <button
                  type="button"
                  className="repl-desk-modal-back"
                  onClick={() => {
                    setPreview(null);
                    setPreviewLoading(false);
                  }}
                >
                  <HiOutlineArrowLeft style={{ width: 16, height: 16 }} />
                  Back to list
                </button>
                <h3 id="repl-inv-modal-title" className="repl-desk-modal-title repl-desk-modal-title--inline">
                  {preview.title}
                </h3>
                {preview.src && preview.src.includes('/invoices/print.php') ? (
                  <button
                    type="button"
                    className={`repl-desk-modal-download${downloadState !== 'idle' ? ` is-${downloadState}` : ''}`}
                    onClick={downloadPreviewPdf}
                    disabled={previewLoading || downloadState === 'loading'}
                    aria-busy={downloadState === 'loading'}
                  >
                    {downloadState === 'loading' ? (
                      <>
                        <span className="repl-desk-dl-spinner" aria-hidden="true" />
                        Preparing…
                      </>
                    ) : downloadState === 'done' ? (
                      <>
                        <HiOutlineCheck style={{ width: 16, height: 16 }} className="repl-desk-dl-check" />
                        Downloaded
                      </>
                    ) : (
                      <>
                        <HiOutlineArrowDownTray style={{ width: 16, height: 16 }} className="repl-desk-dl-arrow" />
                        Download PDF
                      </>
                    )}
                  </button>
                ) : null}
              </div>
              <div className={`repl-desk-modal-frame-wrap${previewLoading ? ' is-loading' : ''}`}>
                {previewLoading ? (
                  <div className="repl-desk-inv-skeleton" aria-hidden="true" aria-busy="true">
                    <div className="repl-desk-inv-skeleton-paper">
                      <div className="repl-desk-inv-skeleton-top">
                        <div className="repl-desk-inv-skeleton-col">
                          <span className="repl-desk-inv-bone repl-desk-inv-bone--title" />
                          <span className="repl-desk-inv-bone repl-desk-inv-bone--line" />
                          <span className="repl-desk-inv-bone repl-desk-inv-bone--line short" />
                          <span className="repl-desk-inv-bone repl-desk-inv-bone--line medium" />
                        </div>
                        <div className="repl-desk-inv-skeleton-col is-right">
                          <span className="repl-desk-inv-bone repl-desk-inv-bone--logo" />
                          <span className="repl-desk-inv-bone repl-desk-inv-bone--line short" />
                          <span className="repl-desk-inv-bone repl-desk-inv-bone--line medium" />
                          <span className="repl-desk-inv-bone repl-desk-inv-bone--line" />
                        </div>
                      </div>
                      <div className="repl-desk-inv-skeleton-customer">
                        <span className="repl-desk-inv-bone repl-desk-inv-bone--line medium" />
                        <span className="repl-desk-inv-bone repl-desk-inv-bone--line" />
                        <span className="repl-desk-inv-bone repl-desk-inv-bone--line short" />
                        <span className="repl-desk-inv-bone repl-desk-inv-bone--line medium" />
                      </div>
                      <div className="repl-desk-inv-skeleton-table">
                        <span className="repl-desk-inv-bone repl-desk-inv-bone--row head" />
                        <span className="repl-desk-inv-bone repl-desk-inv-bone--row" />
                        <span className="repl-desk-inv-bone repl-desk-inv-bone--row" />
                        <span className="repl-desk-inv-bone repl-desk-inv-bone--row" />
                        <span className="repl-desk-inv-bone repl-desk-inv-bone--row" />
                        <span className="repl-desk-inv-bone repl-desk-inv-bone--row" />
                      </div>
                    </div>
                  </div>
                ) : null}
                <iframe
                  ref={iframeRef}
                  className={`repl-desk-modal-frame${previewLoading ? ' is-pending' : ''}`}
                  title={preview.title}
                  src={preview.src}
                  onLoad={() => setPreviewLoading(false)}
                />
              </div>
            </>
          ) : (
            <>
              <div className="repl-desk-modal-icon" aria-hidden="true">
                <HiOutlineDocumentText style={{ width: 22, height: 22 }} />
              </div>
              <h3 id="repl-inv-modal-title" className="repl-desk-modal-title">
                Related invoices
              </h3>
              <p className="repl-desk-modal-text">
                {productName
                  ? `${count} document${count === 1 ? '' : 's'} linked to ${productName}`
                  : `${count} related document${count === 1 ? '' : 's'}`}
              </p>
              <ul className="repl-desk-modal-list">
                {rows.map((row, idx) => {
                  const invoiceLabel = row.invoice_id
                    ? (row.invoice_number || `INV-${row.invoice_id}`)
                    : '';
                  const orderLabel = row.order_id
                    ? (row.order_number || `SO-${row.order_id}`)
                    : '';
                  const embedSrc = documentEmbedUrl(row);
                  const primaryLabel = invoiceLabel || orderLabel || 'Document';
                  return (
                    <li key={`${row.invoice_id || 0}-${row.order_id || 0}-${idx}`}>
                      {embedSrc ? (
                        <button
                          type="button"
                          className="repl-desk-modal-list-btn"
                          onClick={() => openPreview({ title: primaryLabel, src: embedSrc })}
                        >
                          <span className="repl-desk-modal-list-name">{primaryLabel}</span>
                          <span className="repl-desk-modal-list-meta">
                            {[
                              orderLabel && invoiceLabel ? orderLabel : '',
                              row.customer_name || '',
                              row.line_qty !== '' && row.line_qty != null ? `Qty ${row.line_qty}` : '',
                              formatDate(row.invoice_date || row.order_date),
                            ].filter(Boolean).join(' · ')}
                          </span>
                        </button>
                      ) : (
                        <span>
                          <span className="repl-desk-modal-list-name">{primaryLabel}</span>
                        </span>
                      )}
                    </li>
                  );
                })}
              </ul>
            </>
          )}
        </div>
      </div>,
      document.body,
    )
    : null;

  return (
    <>
      <button
        type="button"
        className="repl-desk-inv-btn"
        title={`${count} related invoice${count === 1 ? '' : 's'}`}
        aria-label={`Show ${count} related invoices`}
        onClick={(e) => {
          e.stopPropagation();
          setPreview(null);
          setOpen(true);
        }}
      >
        <HiOutlineDocumentText style={{ width: 16, height: 16 }} aria-hidden="true" />
        <span>{count}</span>
      </button>
      {modal}
    </>
  );
}

export default function Replenishment({ data }) {
  const {
    items = [],
    initialSearch = '',
    invoicesApiUrl = 'replenishment.php?action=invoices',
    flashMessage = '',
    flashType = '',
    urls = {},
  } = data || {};

  const links = {
    purchases: urls.purchases || '../purchases/index.php',
    createDomestic: urls.createDomestic || '../purchases/domestic_create.php',
    createImport: urls.createImport || '../purchases/create.php',
  };

  const [query, setQuery] = useState(initialSearch || '');
  const [expandedId, setExpandedId] = useState(null);
  const [loadingId, setLoadingId] = useState(null);
  const [orderDraft, setOrderDraft] = useState(null);
  const [invoiceCache, setInvoiceCache] = useState(() => {
    const seeded = {};
    (items || []).forEach((item) => {
      if (Array.isArray(item.demand_sources)) {
        seeded[String(item.id)] = { error: '', items: item.demand_sources };
      }
    });
    return seeded;
  });
  const flashShown = useRef(false);

  useEffect(() => {
    if (flashShown.current || !flashMessage) return;
    flashShown.current = true;
    if (flashType === 'error') {
      if (window.StockAlert?.error) window.StockAlert.error(flashMessage);
      else if (window.Swal) {
        window.Swal.fire({
          toast: true,
          position: 'top-end',
          icon: 'error',
          title: flashMessage,
          showConfirmButton: false,
          timer: 3500,
        });
      }
      return;
    }
    if (window.StockAlert?.success) window.StockAlert.success(flashMessage);
    else if (window.Swal) {
      window.Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: flashMessage,
        showConfirmButton: false,
        timer: 3500,
      });
    }
  }, [flashMessage, flashType]);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return items;
    return items.filter((item) => {
      const name = String(item.name || '').toLowerCase();
      const code = String(item.product_code || '').toLowerCase();
      return name.includes(q) || code.includes(q);
    });
  }, [items, query]);

  const loadInvoices = async (product) => {
    const productId = String(product.id);
    if (invoiceCache[productId]) return;
    setLoadingId(productId);
    try {
      const sep = invoicesApiUrl.includes('?') ? '&' : '?';
      const res = await fetch(
        `${invoicesApiUrl}${sep}product_id=${encodeURIComponent(productId)}`,
        { credentials: 'same-origin' },
      );
      const payload = await res.json().catch(() => null);
      if (!payload?.ok) {
        setInvoiceCache((prev) => ({
          ...prev,
          [productId]: { error: payload?.error || 'Could not load invoices.', items: [] },
        }));
        return;
      }
      setInvoiceCache((prev) => ({
        ...prev,
        [productId]: { error: '', items: Array.isArray(payload.items) ? payload.items : [] },
      }));
    } catch {
      setInvoiceCache((prev) => ({
        ...prev,
        [productId]: { error: 'Could not load invoices. Please try again.', items: [] },
      }));
    } finally {
      setLoadingId(null);
    }
  };

  const toggleRow = (product) => {
    const id = Number(product.id);
    if (expandedId === id) {
      setExpandedId(null);
      return;
    }
    setExpandedId(id);
    loadInvoices(product);
  };

  return (
    <>
    <div className="repl-desk">
      <div className="repl-desk-toolbar">
        <div className="repl-desk-toolbar-search">
          <div className="repl-desk-search">
            <HiOutlineMagnifyingGlass
              className="repl-desk-search-icon"
              style={{ width: 16, height: 16 }}
              aria-hidden="true"
            />
            <input
              type="search"
              className="repl-desk-search-input"
              placeholder="Search name or code"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              aria-label="Search replenishment list"
            />
            {query ? (
              <button
                type="button"
                className="repl-desk-search-clear"
                onClick={() => setQuery('')}
                aria-label="Clear search"
              >
                <HiOutlineXMark style={{ width: 14, height: 14 }} />
              </button>
            ) : null}
          </div>
        </div>
        <div className="repl-desk-actions">
          <a href={links.purchases} className="repl-desk-btn repl-desk-btn--primary">
            Purchase orders
          </a>
        </div>
      </div>

      <div className="repl-desk-panel">
        <div className="repl-desk-panel-head">
          <div className="repl-desk-panel-title">
            <h2>Replenishment list</h2>
            <span>
              {filtered.length}
              {' '}
              item
              {filtered.length === 1 ? '' : 's'}
            </span>
          </div>
        </div>

        <div className="repl-desk-table-wrap">
          <table className="repl-desk-table">
            <thead>
              <tr>
                <th>Product</th>
                <th>Invoices</th>
                <th className="is-center">Stock</th>
                <th className="is-right">Options</th>
              </tr>
            </thead>
            <tbody>
              {filtered.length === 0 ? (
                <tr>
                  <td colSpan={4}>
                    <div className="repl-desk-empty">
                      <div className="repl-desk-empty-icon">
                        <HiOutlineCheckCircle style={{ width: 48, height: 48 }} />
                      </div>
                      <h3>{query ? 'No matching products' : 'No negative stock'}</h3>
                      <p>
                        {query
                          ? 'Try a different name or product code.'
                          : 'All products are at zero or above in the warehouse.'}
                      </p>
                    </div>
                  </td>
                </tr>
              ) : (
                filtered.map((item) => {
                  const stock = Number(item.current_stock ?? 0);
                  const pending = Number(item.pending_demand ?? 0);
                  const net = stock - pending;
                  const orderQty = net < 0 ? Math.abs(net) : 0;
                  const isOpen = expandedId === Number(item.id);
                  const cacheKey = String(item.id);

                  return (
                    <React.Fragment key={item.id}>
                      <tr
                        className={`repl-desk-row${isOpen ? ' is-expanded' : ''}`}
                        tabIndex={0}
                        role="button"
                        aria-expanded={isOpen}
                        onClick={() => toggleRow(item)}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            toggleRow(item);
                          }
                        }}
                      >
                        <td>
                          <div className="repl-desk-product">
                            <RowThumb src={item.image_url || ''} />
                            <div className="min-w-0">
                              <div className="repl-desk-name">{item.name || 'Unnamed'}</div>
                              <div className="repl-desk-code">
                                CODE:
                                {' '}
                                {item.product_code || '—'}
                              </div>
                            </div>
                          </div>
                        </td>
                        <td onClick={(e) => e.stopPropagation()}>
                          <ReferenceInvoices
                            productName={item.name || ''}
                            references={item.references || []}
                            demandSources={item.demand_sources || []}
                          />
                        </td>
                        <td>
                          <div className="repl-desk-stock">{stock}</div>
                        </td>
                        <td
                          onClick={(e) => e.stopPropagation()}
                          onKeyDown={(e) => e.stopPropagation()}
                        >
                          <div className="repl-desk-options">
                            {stock < 0 ? (
                              <button
                                type="button"
                                className="repl-desk-order-btn"
                                onClick={() => setOrderDraft({
                                  productId: item.id,
                                  qty: orderQty || Math.abs(stock) || 1,
                                  productName: item.name,
                                  productCode: item.product_code,
                                })}
                              >
                                <HiOutlineShoppingCart style={{ width: 14, height: 14 }} />
                                Order
                              </button>
                            ) : null}
                          </div>
                        </td>
                      </tr>
                      {isOpen ? (
                        <tr className="repl-desk-detail">
                          <td colSpan={4}>
                            <DemandPanel
                              product={item}
                              cacheEntry={invoiceCache[cacheKey]}
                              loading={loadingId === cacheKey && !invoiceCache[cacheKey]}
                            />
                          </td>
                        </tr>
                      ) : null}
                    </React.Fragment>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <PoTypeModal order={orderDraft} urls={links} onClose={() => setOrderDraft(null)} />
    </>
  );
}
