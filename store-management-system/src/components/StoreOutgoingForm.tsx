import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ArrowUpCircle,
  ChevronDown,
  CloudUpload,
  Gift,
  Loader2,
  Package,
  Paperclip,
  RefreshCw,
  Search,
  ShoppingBag,
  X,
} from 'lucide-react';
import { dispatchInvoice, fetchInvoiceDetail, fetchPendingInvoices, recordSampleStockOut } from '../api';
import StatusPopup, { type StatusPopupTone } from './StatusPopup';
import type { InvoiceLine, PendingInvoice, Product } from '../types';

interface StoreOutgoingFormProps {
  warehouseId: number;
  products: Product[];
  preselectedProductId?: string | null;
  initialKind?: OutgoingKind;
  onRecorded: () => Promise<void>;
}

export type OutgoingKind = 'sample' | 'sold';

interface SampleSelection {
  productId: string;
  quantity: string;
}

const SAMPLE_REASONS = [
  'Sample / promotional giveaway',
  'Demo unit',
  'Internal sample',
  'Other sample',
];

function InvoiceLineThumb({ line }: { line: InvoiceLine }) {
  const [failed, setFailed] = useState(false);
  const showImage = Boolean(line.imageUrl) && !failed;

  return (
    <div className="sms-product-thumb sms-po-details-thumb">
      {showImage ? (
        <img
          src={line.imageUrl}
          alt={line.productName}
          loading="lazy"
          onError={() => setFailed(true)}
        />
      ) : (
        <Package className="w-5 h-5 text-slate-400" aria-hidden="true" />
      )}
    </div>
  );
}

function ProductThumb({ product, compact = false }: { product: Product; compact?: boolean }) {
  const [failed, setFailed] = useState(false);
  const showImage = Boolean(product.imageUrl) && !failed;

  return (
    <div className={`sms-product-thumb${compact ? ' sms-product-thumb--sm' : ' sms-po-details-thumb'}`}>
      {showImage ? (
        <img
          src={product.imageUrl}
          alt={product.name}
          loading="lazy"
          onError={() => setFailed(true)}
        />
      ) : (
        <Package className={compact ? 'w-4 h-4 text-slate-400' : 'w-5 h-5 text-slate-400'} aria-hidden="true" />
      )}
    </div>
  );
}

/**
 * Outgoing products form - sample giveaways or sold (invoice) releases.
 * Sample releases use the signed-in user's account signature automatically.
 */
export default function StoreOutgoingForm({
  warehouseId,
  products,
  preselectedProductId = null,
  initialKind = 'sold',
  onRecorded,
}: StoreOutgoingFormProps) {
  const [kind, setKind] = useState<OutgoingKind>(initialKind);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Sample fields
  const [sampleSelections, setSampleSelections] = useState<SampleSelection[]>([]);
  const [sampleDropdownOpen, setSampleDropdownOpen] = useState(false);
  const [sampleSearch, setSampleSearch] = useState('');
  const [sampleCheckedIds, setSampleCheckedIds] = useState<string[]>([]);
  const [reason, setReason] = useState(SAMPLE_REASONS[0]);
  const [notes, setNotes] = useState('');
  const [issuerName, setIssuerName] = useState('');
  const [receipts, setReceipts] = useState<File[]>([]);

  // Sold / invoice fields
  const [invoices, setInvoices] = useState<PendingInvoice[]>([]);
  const [selectedInvoiceId, setSelectedInvoiceId] = useState('');
  const [invoiceLines, setInvoiceLines] = useState<InvoiceLine[]>([]);
  const [releaseQty, setReleaseQty] = useState<Record<string, string>>({});
  const [invoiceNotes, setInvoiceNotes] = useState('');
  const [loadingInvoices, setLoadingInvoices] = useState(false);
  const [loadingInvoiceDetail, setLoadingInvoiceDetail] = useState(false);
  const [dragOver, setDragOver] = useState(false);
  const [statusPopup, setStatusPopup] = useState<{
    title: string;
    message: string;
    tone: StatusPopupTone;
  } | null>(null);
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const sampleDropdownRef = useRef<HTMLDivElement | null>(null);
  const sampleSearchRef = useRef<HTMLInputElement | null>(null);

  const sortedProducts = useMemo(
    () => [...products].sort((a, b) => a.name.localeCompare(b.name)),
    [products]
  );
  const sampleSelectableProducts = useMemo(() => {
    const selected = new Set(sampleSelections.map((item) => item.productId));
    const query = sampleSearch.trim().toLowerCase();
    return sortedProducts.filter((product) => {
      if (selected.has(product.id)) return false;
      if (!query) return true;
      return (
        product.name.toLowerCase().includes(query) ||
        (product.sku || '').toLowerCase().includes(query)
      );
    });
  }, [sampleSearch, sampleSelections, sortedProducts]);

  const addReceiptFiles = useCallback((fileList: FileList | File[] | null) => {
    if (!fileList) return;
    const next = Array.from(fileList).filter((f) => f && f.size > 0);
    if (next.length === 0) return;
    setReceipts((prev) => {
      const merged = [...prev];
      for (const file of next) {
        const exists = merged.some(
          (f) => f.name === file.name && f.size === file.size && f.lastModified === file.lastModified
        );
        if (!exists) merged.push(file);
      }
      return merged;
    });
  }, []);

  const removeReceipt = useCallback((index: number) => {
    setReceipts((prev) => prev.filter((_, i) => i !== index));
  }, []);

  const attachmentDropzone = (
    <div className="sms-incoming-attachments">
      <label className="sms-field-label">
        <Paperclip className="w-3.5 h-3.5 inline mr-1" />
        Receipt / supporting attachments <span className="sms-field-optional">(optional)</span>
      </label>
      <input
        ref={fileInputRef}
        type="file"
        accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*"
        multiple
        className="sms-sr-only"
        onChange={(e) => {
          addReceiptFiles(e.target.files);
          e.target.value = '';
        }}
      />
      <button
        type="button"
        className={`sms-dropzone${dragOver ? ' is-dragover' : ''}`}
        onClick={() => fileInputRef.current?.click()}
        onDragEnter={(e) => {
          e.preventDefault();
          e.stopPropagation();
          setDragOver(true);
        }}
        onDragOver={(e) => {
          e.preventDefault();
          e.stopPropagation();
          setDragOver(true);
        }}
        onDragLeave={(e) => {
          e.preventDefault();
          e.stopPropagation();
          setDragOver(false);
        }}
        onDrop={(e) => {
          e.preventDefault();
          e.stopPropagation();
          setDragOver(false);
          addReceiptFiles(e.dataTransfer.files);
        }}
      >
        <CloudUpload className="sms-dropzone-icon" aria-hidden="true" />
        <span className="sms-dropzone-title">Upload a file</span>
        <span className="sms-dropzone-sub">Click to browse, or drag &amp; drop files here</span>
      </button>
      {receipts.length > 0 && (
        <ul className="sms-dropzone-files">
          {receipts.map((f, index) => (
            <li key={`${f.name}-${f.size}-${f.lastModified}`}>
              <span className="sms-dropzone-file-name">{f.name}</span>
              <button
                type="button"
                className="sms-dropzone-remove"
                onClick={() => removeReceipt(index)}
                aria-label={`Remove ${f.name}`}
              >
                <X className="w-3.5 h-3.5" />
              </button>
            </li>
          ))}
        </ul>
      )}
      <p className="sms-field-hint">
        Optional. First file is the primary supporting receipt. Additional files are stored with the release.
      </p>
    </div>
  );

  React.useEffect(() => {
    setKind(initialKind);
  }, [initialKind]);

  useEffect(() => {
    if (!sampleDropdownOpen) return;
    const onPointerDown = (event: MouseEvent) => {
      if (!sampleDropdownRef.current?.contains(event.target as Node)) {
        setSampleDropdownOpen(false);
      }
    };
    document.addEventListener('mousedown', onPointerDown);
    return () => document.removeEventListener('mousedown', onPointerDown);
  }, [sampleDropdownOpen]);

  useEffect(() => {
    if (!sampleDropdownOpen) return;
    const timer = window.setTimeout(() => sampleSearchRef.current?.focus(), 0);
    return () => window.clearTimeout(timer);
  }, [sampleDropdownOpen]);

  useEffect(() => {
    if (!preselectedProductId) return;
    setSampleSelections((prev) => {
      if (prev.some((item) => item.productId === preselectedProductId)) return prev;
      if (!products.some((product) => product.id === preselectedProductId)) return prev;
      return [...prev, { productId: preselectedProductId, quantity: '' }];
    });
  }, [preselectedProductId, products]);

  const refreshInvoices = React.useCallback(async () => {
    if (kind !== 'sold') return;
    try {
      const list = await fetchPendingInvoices(warehouseId, 'pending');
      setInvoices(list);
    } catch {
      // Keep current list on soft refresh failure.
    }
  }, [kind, warehouseId]);

  React.useEffect(() => {
    if (kind !== 'sold') return;
    let cancelled = false;
    const load = async () => {
      setLoadingInvoices(true);
      setError(null);
      try {
        const list = await fetchPendingInvoices(warehouseId, 'pending');
        if (!cancelled) setInvoices(list);
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Failed to load invoices');
      } finally {
        if (!cancelled) setLoadingInvoices(false);
      }
    };
    load();

    const onVisible = () => {
      if (document.visibilityState === 'visible') {
        void refreshInvoices();
      }
    };
    window.addEventListener('focus', refreshInvoices);
    document.addEventListener('visibilitychange', onVisible);
    const poll = window.setInterval(() => {
      void refreshInvoices();
    }, 15000);

    return () => {
      cancelled = true;
      window.removeEventListener('focus', refreshInvoices);
      document.removeEventListener('visibilitychange', onVisible);
      window.clearInterval(poll);
    };
  }, [kind, warehouseId, refreshInvoices]);

  React.useEffect(() => {
    if (!selectedInvoiceId) {
      setInvoiceLines([]);
      setReleaseQty({});
      return;
    }
    let cancelled = false;
    (async () => {
      setLoadingInvoiceDetail(true);
      setError(null);
      try {
        const data = await fetchInvoiceDetail(warehouseId, selectedInvoiceId);
        if (cancelled) return;
        setInvoiceLines(data.lines);
        const salesperson = (data.invoice.salespersonName || '').trim();
        if (salesperson) {
          setIssuerName(salesperson);
        }
        const defaults: Record<string, string> = {};
        for (const line of data.lines) {
          // Pre-fill with the invoiced qty; user can still reduce it.
          // Even if on hand is 0 we show the invoiced amount so they know what's expected.
          defaults[line.productId] = String(line.qtyInvoiced);
        }
        setReleaseQty(defaults);
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Failed to load invoice');
      } finally {
        if (!cancelled) setLoadingInvoiceDetail(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [selectedInvoiceId, warehouseId]);

  const addSampleProducts = useCallback((productIds: string[]) => {
    const uniqueIds = Array.from(new Set(productIds.filter(Boolean)));
    if (uniqueIds.length === 0) {
      alert('Select at least one product.');
      return;
    }

    const missing = uniqueIds.filter((id) => !products.some((product) => product.id === id));
    if (missing.length > 0) {
      alert('One or more selected products were not found.');
      return;
    }

    setSampleSelections((prev) => {
      const existing = new Set(prev.map((item) => item.productId));
      const next = [...prev];
      for (const productId of uniqueIds) {
        if (!existing.has(productId)) {
          next.push({ productId, quantity: '' });
        }
      }
      return next;
    });
    setSampleCheckedIds([]);
    setSampleDropdownOpen(false);
    setSampleSearch('');
  }, [products]);

  const toggleSampleChecked = useCallback((productId: string) => {
    setSampleCheckedIds((prev) =>
      prev.includes(productId) ? prev.filter((id) => id !== productId) : [...prev, productId]
    );
  }, []);

  const updateSampleQuantity = useCallback((productId: string, quantity: string) => {
    setSampleSelections((prev) => prev.map((item) => (item.productId === productId ? { ...item, quantity } : item)));
  }, []);

  const removeSampleProduct = useCallback((productId: string) => {
    setSampleSelections((prev) => prev.filter((item) => item.productId !== productId));
  }, []);

  const resetSample = () => {
    setSampleSelections([]);
    setSampleCheckedIds([]);
    setSampleSearch('');
    setSampleDropdownOpen(false);
    setNotes('');
    setReceipts([]);
  };

  const handleSampleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (sampleSelections.length === 0) {
      alert('Add at least one product.');
      return;
    }
    const items: Record<string, number> = {};
    for (const item of sampleSelections) {
      const product = products.find((p) => p.id === item.productId);
      const qty = Number(item.quantity);
      if (!product) {
        alert('One of the selected products no longer exists.');
        return;
      }
      if (!qty || qty <= 0) {
        alert(`Enter a valid quantity for ${product.name}.`);
        return;
      }
      if (qty > product.stock) {
        alert(`Only ${product.stock} ${product.unit} available for ${product.name}.`);
        return;
      }
      items[item.productId] = qty;
    }
    if (!issuerName.trim()) {
      alert('Enter the invoice / document issuer name.');
      return;
    }

    setSaving(true);
    setError(null);
    try {
      await recordSampleStockOut(warehouseId, {
        items,
        reason,
        notes: notes.trim(),
        issuerName: issuerName.trim(),
        receipts,
      });
      alert('Sample outgoing recorded.');
      resetSample();
      await onRecorded();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to record sample out');
    } finally {
      setSaving(false);
    }
  };

  const handleSoldSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedInvoiceId) {
      setStatusPopup({
        title: 'Invoice required',
        message: 'Select a sales invoice before releasing stock.',
        tone: 'error',
      });
      return;
    }
    const items: Record<string, number> = {};
    for (const [id, raw] of Object.entries(releaseQty)) {
      const qty = Number(raw);
      if (qty > 0) items[id] = qty;
    }
    if (Object.keys(items).length === 0) {
      setStatusPopup({
        title: 'Quantity required',
        message: 'Enter at least one quantity to release.',
        tone: 'error',
      });
      return;
    }
    if (!issuerName.trim()) {
      setStatusPopup({
        title: 'Issuer required',
        message: 'Enter the invoice issuer name.',
        tone: 'error',
      });
      return;
    }

    for (const [productId, qty] of Object.entries(items)) {
      const line = invoiceLines.find((entry) => entry.productId === productId);
      if (!line) continue;
      if (qty > line.qtyInvoiced) {
        setStatusPopup({
          title: 'Quantity too high',
          message: `Cannot release ${qty} of ${line.productName}. Invoiced quantity is ${line.qtyInvoiced}.`,
          tone: 'error',
        });
        return;
      }
      if (qty > line.currentStock) {
        setStatusPopup({
          title: 'Insufficient stock',
          message:
            `${line.productName} has ${line.currentStock} ${line.unit} on hand, but release quantity is ${qty}. ` +
            'Add stock or reduce the release quantity before continuing.',
          tone: 'error',
        });
        return;
      }
    }

    setSaving(true);
    setError(null);
    try {
      const result = await dispatchInvoice(warehouseId, {
        invoiceId: selectedInvoiceId,
        items,
        notes: invoiceNotes.trim(),
        supportingDocument: receipts[0] ?? null,
        invoiceDocument: receipts[1] ?? null,
        issuerName: issuerName.trim(),
        extraReceipts: receipts.slice(2),
      });
      setStatusPopup({
        title: 'Stock released',
        message: result.message + (result.deliveryNumber ? ` (${result.deliveryNumber})` : ''),
        tone: 'success',
      });
      setSelectedInvoiceId('');
      setInvoiceNotes('');
      setIssuerName('');
      setReceipts([]);
      await onRecorded();
    } catch (err) {
      setStatusPopup({
        title: 'Release failed',
        message: err instanceof Error ? err.message : 'Failed to release sold products',
        tone: 'error',
      });
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="sms-form-shell">
      <div className="sms-form-hero">
        <div>
          <h2 className="sms-form-title">Record outgoing products</h2>
          <p className="sms-form-sub">
            Log sample giveaways or sold products.
          </p>
        </div>
      </div>

      <div className="sms-form-mode-toggle" role="tablist" aria-label="Outgoing type">
        <button
          type="button"
          role="tab"
          aria-selected={kind === 'sold'}
          className={`sms-desk-btn sms-btn-rounded${kind === 'sold' ? ' sms-desk-btn-primary' : ' sms-desk-btn-secondary'}`}
          onClick={() => setKind('sold')}
        >
          <ShoppingBag className="w-4 h-4" />
          <span>Sold product (invoice)</span>
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={kind === 'sample'}
          className={`sms-desk-btn sms-btn-rounded${kind === 'sample' ? ' sms-desk-btn-primary' : ' sms-desk-btn-secondary'}`}
          onClick={() => setKind('sample')}
        >
          <Gift className="w-4 h-4" />
          <span>Sample product</span>
        </button>
      </div>

      {error && <div className="sms-desk-flash sms-desk-flash-error">{error}</div>}

      <div className="sms-form-card">
        {kind === 'sample' ? (
          <form onSubmit={handleSampleSubmit} className="sms-movement-form">
            <div className="sms-movement-form-head" style={{ padding: 0, border: 'none' }}>
              <h3 className="sms-table-title flex items-center gap-2">
                <Gift className="w-4 h-4 text-amber-600" />
                Sample outgoing
              </h3>
              <p className="sms-table-meta">Remove stock for samples, demos, or promotional units.</p>
            </div>

            <div className="sms-movement-grid">
              <div className="sms-movement-notes">
                <label className="sms-field-label" htmlFor="sms-sample-product-trigger">
                  Product *
                </label>
                <div
                  className={`sms-po-dropdown${sampleDropdownOpen ? ' is-open' : ''}`}
                  ref={sampleDropdownRef}
                >
                  <button
                    id="sms-sample-product-trigger"
                    type="button"
                    className="sms-po-dropdown-trigger"
                    aria-haspopup="listbox"
                    aria-expanded={sampleDropdownOpen}
                    onClick={() => setSampleDropdownOpen((open) => !open)}
                  >
                    <span className="sms-po-dropdown-trigger-main">
                      <span className="sms-po-dropdown-placeholder">
                        {sampleSelections.length > 0
                          ? 'Add another product...'
                          : 'Select product...'}
                      </span>
                    </span>
                    <span className="sms-po-dropdown-trigger-aside">
                      <ChevronDown className={`sms-po-dropdown-chevron${sampleDropdownOpen ? ' is-open' : ''}`} />
                    </span>
                  </button>

                  {sampleDropdownOpen && (
                    <div className="sms-po-dropdown-menu" role="listbox" aria-label="Products" aria-multiselectable="true">
                      <div className="sms-incoming-search sms-incoming-search--dropdown">
                        <Search className="sms-incoming-search-icon" aria-hidden="true" />
                        <input
                          ref={sampleSearchRef}
                          type="search"
                          className="sms-incoming-search-input"
                          placeholder="Search product name or SKU..."
                          value={sampleSearch}
                          onChange={(e) => setSampleSearch(e.target.value)}
                          aria-label="Search products"
                        />
                      </div>

                      <div className="sms-po-dropdown-meta sms-product-picker-meta">
                        <span>
                          {sampleSelectableProducts.length} product
                          {sampleSelectableProducts.length === 1 ? '' : 's'} available
                          {sampleCheckedIds.length > 0 ? ` - ${sampleCheckedIds.length} selected` : ''}
                        </span>
                        {sampleSelectableProducts.length > 0 && (
                          <button
                            type="button"
                            className="sms-product-picker-select-all"
                            onClick={() => {
                              const visibleIds = sampleSelectableProducts.map((product) => product.id);
                              const allVisibleSelected = visibleIds.every((id) => sampleCheckedIds.includes(id));
                              setSampleCheckedIds((prev) => {
                                if (allVisibleSelected) {
                                  return prev.filter((id) => !visibleIds.includes(id));
                                }
                                return Array.from(new Set([...prev, ...visibleIds]));
                              });
                            }}
                          >
                            {sampleSelectableProducts.every((product) => sampleCheckedIds.includes(product.id))
                              ? 'Clear visible'
                              : 'Select visible'}
                          </button>
                        )}
                      </div>

                      {sampleSelectableProducts.length === 0 ? (
                        <div className="sms-incoming-empty sms-incoming-empty--menu">
                          No products match this search.
                        </div>
                      ) : (
                        <div className="sms-po-picker sms-po-picker--dropdown">
                          {sampleSelectableProducts.map((product) => {
                            const checked = sampleCheckedIds.includes(product.id);
                            return (
                              <label
                                key={product.id}
                                className={`sms-po-picker-item sms-product-picker-item${checked ? ' is-active' : ''}`}
                              >
                                <input
                                  type="checkbox"
                                  className="sms-product-picker-checkbox"
                                  checked={checked}
                                  onChange={() => toggleSampleChecked(product.id)}
                                  aria-label={`Select ${product.name}`}
                                />
                                <ProductThumb product={product} compact />
                                <span className="sms-product-picker-copy">
                                  <span className="sms-po-picker-ref">{product.name}</span>
                                  <span className="sms-po-dropdown-trigger-sub">
                                    {product.sku || '-'} - {product.stock} {product.unit} on hand
                                  </span>
                                </span>
                              </label>
                            );
                          })}
                        </div>
                      )}

                      <div className="sms-product-picker-actions">
                        <button
                          type="button"
                          className="sms-btn-secondary sms-btn-rounded"
                          onClick={() => {
                            setSampleCheckedIds([]);
                            setSampleDropdownOpen(false);
                            setSampleSearch('');
                          }}
                        >
                          Cancel
                        </button>
                        <button
                          type="button"
                          className="sms-btn-primary sms-btn-rounded"
                          disabled={sampleCheckedIds.length === 0}
                          onClick={() => addSampleProducts(sampleCheckedIds)}
                        >
                          Add selected{sampleCheckedIds.length > 0 ? ` (${sampleCheckedIds.length})` : ''}
                        </button>
                      </div>
                    </div>
                  )}
                </div>
                <p className="sms-field-hint">Tick one or more products, then click Add selected.</p>
              </div>
              <div>
                <label className="sms-field-label">Reason *</label>
                <select required value={reason} onChange={(e) => setReason(e.target.value)} className="sms-input">
                  {SAMPLE_REASONS.map((r) => (
                    <option key={r} value={r}>
                      {r}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label className="sms-field-label">Issuer name *</label>
                <input
                  type="text"
                  required
                  value={issuerName}
                  onChange={(e) => setIssuerName(e.target.value)}
                  className="sms-input"
                  placeholder="Name of person issuing / authorizing"
                />
              </div>
              <div className="sms-movement-notes">
                <label className="sms-field-label">Notes</label>
                <input
                  type="text"
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  className="sms-input"
                  placeholder="Recipient, campaign, or reference..."
                />
              </div>
            </div>

            {sampleSelections.length > 0 && (
              <div className="sms-table-wrap sms-po-lines-table" style={{ margin: 0 }}>
                <table className="sms-table sms-inventory-table">
                  <thead>
                    <tr>
                      <th className="sms-col-image">Image</th>
                      <th>Product</th>
                      <th className="text-center">On hand</th>
                      <th className="text-center">Qty out</th>
                      <th className="text-center">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    {sampleSelections.map((item) => {
                      const product = products.find((p) => p.id === item.productId);
                      if (!product) return null;
                      return (
                        <tr key={item.productId}>
                          <td className="sms-col-image">
                            <ProductThumb product={product} />
                          </td>
                          <td>
                            <div className="font-semibold">{product.name}</div>
                            <div className="sms-product-meta">
                              <span className="sms-sku">{product.sku || '-'}</span>
                            </div>
                          </td>
                          <td className="text-center font-mono">
                            {product.stock} {product.unit}
                          </td>
                          <td className="text-center">
                            <input
                              type="number"
                              min="1"
                              max={product.stock}
                              value={item.quantity}
                              onChange={(e) => updateSampleQuantity(item.productId, e.target.value)}
                              className="sms-input sms-po-qty-input"
                              placeholder="Units"
                            />
                          </td>
                          <td className="text-center">
                            <button
                              type="button"
                              className="sms-btn-secondary sms-btn-sm"
                              onClick={() => removeSampleProduct(item.productId)}
                            >
                              Remove
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}

            {attachmentDropzone}

            <div className="sms-movement-actions">
              <button type="submit" disabled={saving} className="sms-btn-primary sms-btn-out">
                {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <ArrowUpCircle className="w-4 h-4" />}
                Record sample out
              </button>
            </div>
          </form>
        ) : (
          <form onSubmit={handleSoldSubmit} className="sms-movement-form">
            <div className="sms-movement-form-head" style={{ padding: 0, border: 'none' }}>
              <h3 className="sms-table-title flex items-center gap-2">
                <ShoppingBag className="w-4 h-4 text-indigo-600" />
                Sold product release
              </h3>
              <p className="sms-table-meta">
                Release stock against a sales invoice.
              </p>
            </div>

            <div>
              <div className="sms-field-label-row">
                <label className="sms-field-label" htmlFor="sms-sales-invoice-select">
                  Sales invoice *
                </label>
                <button
                  type="button"
                  className="sms-product-picker-select-all"
                  onClick={() => {
                    void refreshInvoices();
                  }}
                  disabled={loadingInvoices}
                >
                  <RefreshCw className={`w-3.5 h-3.5 inline mr-1${loadingInvoices ? ' animate-spin' : ''}`} />
                  Refresh
                </button>
              </div>
              {loadingInvoices && invoices.length === 0 ? (
                <div className="sms-input flex items-center gap-2 text-slate-500">
                  <Loader2 className="w-4 h-4 animate-spin" />
                  Loading invoices...
                </div>
              ) : (
                <select
                  id="sms-sales-invoice-select"
                  required
                  value={selectedInvoiceId}
                  onChange={(e) => setSelectedInvoiceId(e.target.value)}
                  onFocus={() => {
                    void refreshInvoices();
                  }}
                  className="sms-input"
                >
                  <option value="">
                    {invoices.length === 0
                      ? 'No invoices awaiting store release'
                      : 'Select invoice...'}
                  </option>
                  {invoices.map((inv) => (
                    <option key={inv.id} value={inv.id}>
                      {inv.invoiceNumber} - {inv.customerName}
                      {inv.invoiceStatus ? ` (${inv.invoiceStatus})` : ''}
                    </option>
                  ))}
                </select>
              )}
              <p className="sms-field-hint">
                Shows draft, unpaid, and paid sales invoices that have not been store-released yet. List refreshes automatically.
              </p>
            </div>

            {loadingInvoiceDetail && (
              <div className="sms-table-empty py-6">
                <Loader2 className="w-6 h-6 animate-spin text-blue-600" />
              </div>
            )}

            {!loadingInvoiceDetail && invoiceLines.length > 0 && (
              <div className="sms-table-wrap sms-po-lines-table" style={{ margin: 0 }}>
                <table className="sms-table sms-inventory-table">
                  <thead>
                    <tr>
                      <th className="sms-col-image">Image</th>
                      <th>Product</th>
                      <th className="text-center">Invoiced</th>
                      <th className="text-center">On hand</th>
                      <th className="text-center">Release qty</th>
                    </tr>
                  </thead>
                  <tbody>
                    {invoiceLines.map((line) => (
                      <tr key={line.productId}>
                        <td className="sms-col-image">
                          <InvoiceLineThumb line={line} />
                        </td>
                        <td>
                          <div className="font-semibold">{line.productName}</div>
                          <div className="sms-product-meta">
                            <span className="sms-sku">{line.productSku || '-'}</span>
                          </div>
                        </td>
                        <td className="text-center font-mono">{line.qtyInvoiced}</td>
                        <td className={`text-center font-mono${line.currentStock <= 0 ? ' text-rose-600' : ''}`}>
                          {line.currentStock}
                        </td>
                        <td className="text-center">
                          <input
                            type="number"
                            min="0"
                            max={Math.max(0, line.qtyInvoiced)}
                            value={releaseQty[line.productId] ?? ''}
                            onChange={(e) =>
                              setReleaseQty((prev) => ({ ...prev, [line.productId]: e.target.value }))
                            }
                            className="sms-input sms-po-qty-input"
                          />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            <div className="sms-movement-grid">
              <div>
                <label className="sms-field-label">Invoice issuer name *</label>
                <input
                  type="text"
                  required
                  value={issuerName}
                  onChange={(e) => setIssuerName(e.target.value)}
                  className="sms-input"
                  placeholder="Name of invoice issuer"
                />
              </div>
              <div>
                <label className="sms-field-label">Release notes</label>
                <input
                  type="text"
                  value={invoiceNotes}
                  onChange={(e) => setInvoiceNotes(e.target.value)}
                  className="sms-input"
                  placeholder="Handover notes..."
                />
              </div>
            </div>

            {attachmentDropzone}

            <div className="sms-movement-actions">
              <button type="submit" disabled={saving || !selectedInvoiceId} className="sms-btn-primary sms-btn-out sms-btn-rounded">
                {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <ArrowUpCircle className="w-4 h-4" />}
                Release
              </button>
            </div>
          </form>
        )}
      </div>

      {statusPopup && (
        <StatusPopup
          title={statusPopup.title}
          message={statusPopup.message}
          tone={statusPopup.tone}
          confirmLabel={statusPopup.tone === 'success' ? 'Done' : 'OK'}
          onClose={() => setStatusPopup(null)}
        />
      )}
    </div>
  );
}
