import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { CheckCircle2, ExternalLink, FileText, Loader2, Package, Paperclip, Search, Truck } from 'lucide-react';
import { dispatchInvoice, fetchInvoiceDetail, fetchPendingInvoices } from '../api';
import type { InvoiceLine, PendingInvoice } from '../types';

interface InvoiceDispatchProps {
  warehouseId: number;
  onDispatched: () => Promise<void>;
}

type InvoiceFilter = 'pending' | 'all';

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

export default function InvoiceDispatch({ warehouseId, onDispatched }: InvoiceDispatchProps) {
  const [invoices, setInvoices] = useState<PendingInvoice[]>([]);
  const [listFilter, setListFilter] = useState<InvoiceFilter>('pending');
  const [searchTerm, setSearchTerm] = useState('');
  const [selectedId, setSelectedId] = useState('');
  const [lines, setLines] = useState<InvoiceLine[]>([]);
  const [selectedInvoice, setSelectedInvoice] = useState<PendingInvoice | null>(null);
  const [releaseQty, setReleaseQty] = useState<Record<string, string>>({});
  const [notes, setNotes] = useState('');
  const [supportingDocument, setSupportingDocument] = useState<File | null>(null);
  const [invoiceDocument, setInvoiceDocument] = useState<File | null>(null);
  const [loading, setLoading] = useState(true);  const [loadingDetail, setLoadingDetail] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const loadInvoices = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const list = await fetchPendingInvoices(warehouseId, listFilter === 'pending' ? 'pending' : 'all');
      setInvoices(list);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load invoices');
    } finally {
      setLoading(false);
    }
  }, [warehouseId, listFilter]);

  useEffect(() => {
    loadInvoices();
  }, [loadInvoices]);

  useEffect(() => {
    if (!selectedId) {
      setLines([]);
      setSelectedInvoice(null);
      setReleaseQty({});
      setSupportingDocument(null);
      setInvoiceDocument(null);
      return;    }

    (async () => {
      setLoadingDetail(true);
      setError(null);
      try {
        const data = await fetchInvoiceDetail(warehouseId, selectedId);
        setSelectedInvoice(data.invoice);
        setLines(data.lines);
        const defaults: Record<string, string> = {};
        for (const line of data.lines) {
          const max = Math.min(line.qtyInvoiced, line.currentStock);
          if (max > 0) {
            defaults[line.productId] = String(max);
          }
        }
        setReleaseQty(defaults);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load invoice');
        setLines([]);
        setSelectedInvoice(null);
      } finally {
        setLoadingDetail(false);
      }
    })();
  }, [selectedId, warehouseId]);

  const filteredInvoices = useMemo(() => {
    const q = searchTerm.trim().toLowerCase();
    if (!q) return invoices;
    return invoices.filter(
      (inv) =>
        inv.invoiceNumber.toLowerCase().includes(q) ||
        inv.customerName.toLowerCase().includes(q) ||
        inv.customerPhone.toLowerCase().includes(q)
    );
  }, [invoices, searchTerm]);

  const formatDate = (iso: string) => {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  };

  const formatMoney = (amount: number) =>
    amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  const handleSelectInvoice = (inv: PendingInvoice) => {
    if (inv.dispatchStatus === 'released') return;
    setSelectedId(inv.id);
    setNotes('');
    setSupportingDocument(null);
    setInvoiceDocument(null);
  };
  const handleDispatch = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedId) return;

    const items: Record<string, number> = {};
    for (const [productId, raw] of Object.entries(releaseQty)) {
      const qty = Number(raw);
      if (qty > 0) items[productId] = qty;
    }

    if (Object.keys(items).length === 0) {
      alert('Enter at least one quantity to release.');
      return;
    }

    setSaving(true);
    try {
      const result = await dispatchInvoice(warehouseId, {
        invoiceId: selectedId,
        items,
        notes: notes.trim(),
        supportingDocument,
        invoiceDocument,
      });
      alert(result.message);
      setSelectedId('');
      setNotes('');
      setSupportingDocument(null);
      setInvoiceDocument(null);      await onDispatched();
      await loadInvoices();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to release invoice');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="sms-po-receive">
      <div className="sms-movement-form-head">
        <h3 className="sms-table-title flex items-center gap-2">
          <FileText className="w-4 h-4 text-indigo-600" />
          Release stock against invoice
        </h3>
        <p className="sms-table-meta">
          Sales invoices appear here when created. Verify that the salesperson has collected the goods before releasing stock.
        </p>
      </div>

      {error && <div className="sms-alert sms-alert-error m-4 mb-0">{error}</div>}

      <div className="sms-invoice-list-toolbar px-4 pt-2">
        <div className="sms-in-source-toggle">
          <button
            type="button"
            className={`sms-in-source-btn ${listFilter === 'pending' ? 'active' : ''}`}
            onClick={() => {
              setListFilter('pending');
              setSelectedId('');
            }}
          >
            Awaiting release
          </button>
          <button
            type="button"
            className={`sms-in-source-btn ${listFilter === 'all' ? 'active' : ''}`}
            onClick={() => {
              setListFilter('all');
              setSelectedId('');
            }}
          >
            All invoices
          </button>
        </div>
        <div className="sms-search-wrap sms-invoice-search">
          <Search className="sms-search-icon" />
          <input
            type="text"
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            placeholder="Search invoice # or customer..."
            className="sms-input sms-search-input"
          />
        </div>
      </div>

      {loading ? (
        <div className="sms-table-empty py-10">
          <Loader2 className="w-6 h-6 animate-spin text-blue-600" />
          <span>Loading sales invoices...</span>
        </div>
      ) : filteredInvoices.length === 0 ? (
        <div className="sms-po-empty m-4">
          {listFilter === 'pending'
            ? 'No sales invoices are waiting for warehouse release. When Sales creates an invoice, it will appear here for you to verify pickup.'
            : 'No sales invoices found.'}
        </div>
      ) : (
        <div className="sms-table-wrap sms-invoice-list-table mx-4">
          <table className="sms-table">
            <thead>
              <tr>
                <th>Invoice</th>
                <th>Customer</th>
                <th>Date</th>
                <th className="text-right">Total</th>
                <th>Items</th>
                <th>Sales status</th>
                <th>Warehouse</th>
                <th className="text-right">Action</th>
              </tr>
            </thead>
            <tbody>
              {filteredInvoices.map((inv) => {
                const isSelected = selectedId === inv.id;
                const awaiting = inv.dispatchStatus === 'awaiting_release';

                return (
                  <tr key={inv.id} className={isSelected ? 'sms-invoice-row-selected' : ''}>
                    <td>
                      <div className="font-semibold text-slate-900">{inv.invoiceNumber}</div>
                    </td>
                    <td>
                      <div className="text-slate-800">{inv.customerName}</div>
                      {inv.customerPhone && (
                        <div className="text-xs text-slate-500">{inv.customerPhone}</div>
                      )}
                    </td>
                    <td className="whitespace-nowrap text-slate-600">{formatDate(inv.invoiceDate)}</td>
                    <td className="text-right font-mono">{formatMoney(inv.totalAmount)}</td>
                    <td className="text-center">{inv.lineCount}</td>
                    <td>
                      <span className="sms-category-pill">{inv.invoiceStatus || 'created'}</span>
                    </td>
                    <td>
                      {awaiting ? (
                        <span className="sms-badge sms-badge-low">Awaiting release</span>
                      ) : (
                        <span className="sms-badge sms-badge-out">Released</span>
                      )}
                      {inv.deliveryNumber && (
                        <div className="text-xs text-slate-500 mt-1">{inv.deliveryNumber}</div>
                      )}
                    </td>
                    <td className="text-right">
                      {awaiting ? (
                        <button
                          type="button"
                          onClick={() => handleSelectInvoice(inv)}
                          className={`sms-btn-secondary sms-btn-sm ${isSelected ? 'active' : ''}`}
                        >
                          <CheckCircle2 className="w-3.5 h-3.5" />
                          Verify pickup
                        </button>
                      ) : (
                        <span className="text-xs text-slate-400">Done</span>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {selectedId && (
        <form onSubmit={handleDispatch} className="sms-movement-form sms-movement-form-bordered mt-4">
          {selectedInvoice && (
            <div className="sms-po-summary px-5 pt-4">
              <span className="sms-po-pill">{selectedInvoice.invoiceNumber}</span>
              <span className="sms-po-pill">{selectedInvoice.customerName}</span>
              {selectedInvoice.viewInvoiceUrl && (
                <a
                  href={selectedInvoice.viewInvoiceUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="sms-invoice-view-link"
                >
                  <ExternalLink className="w-3.5 h-3.5" />
                  View system invoice
                </a>
              )}
            </div>
          )}
          {loadingDetail ? (
            <div className="sms-table-empty py-8">
              <Loader2 className="w-6 h-6 animate-spin text-blue-600" />
              <span>Loading invoice lines...</span>
            </div>
          ) : lines.length === 0 ? (
            <div className="sms-po-empty m-4">
              This invoice has no stock products linked to the warehouse catalogue. Ask Procurement to align product codes.
            </div>
          ) : (
            <>
              <div className="sms-table-wrap sms-po-lines-table">
                <table className="sms-table sms-inventory-table">
                  <thead>
                    <tr>
                      <th className="sms-col-image">Image</th>
                      <th>Product</th>
                      <th className="text-center">Invoiced</th>
                      <th className="text-center">In stock</th>
                      <th className="text-center">Release qty</th>
                    </tr>
                  </thead>
                  <tbody>
                    {lines.map((line) => (
                      <tr key={line.productId}>
                        <td className="sms-col-image">
                          <InvoiceLineThumb line={line} />
                        </td>
                        <td>
                          <div className="font-semibold text-slate-900">{line.productName}</div>
                          <div className="sms-product-meta">
                            <span className="sms-sku">{line.productSku}</span>
                          </div>
                        </td>
                        <td className="text-center font-mono">{line.qtyInvoiced}</td>
                        <td className="text-center font-mono text-slate-600">{line.currentStock}</td>
                        <td className="text-center">
                          <input
                            type="number"
                            min="0"
                            max={Math.min(line.qtyInvoiced, line.currentStock)}
                            value={releaseQty[line.productId] ?? ''}
                            disabled={line.currentStock <= 0}
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

              <div className="sms-release-docs px-5">
                <div className="sms-release-docs-head">
                  <Paperclip className="w-4 h-4 text-slate-500" />
                  <span>Attach documents before confirming release</span>
                </div>
                <div className="sms-release-docs-grid">
                  <div>
                    <label className="sms-field-label">
                      Supporting document <span className="sms-field-optional">(optional)</span>
                    </label>
                    <p className="sms-field-hint">Signed handover note, delivery receipt, or photo of goods collected</p>
                    <input
                      type="file"
                      accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*"
                      onChange={(e) => setSupportingDocument(e.target.files?.[0] ?? null)}
                      className="sms-file-input"
                    />
                    {supportingDocument && (
                      <p className="sms-file-name">{supportingDocument.name}</p>
                    )}
                  </div>
                  <div>
                    <label className="sms-field-label">Signed invoice copy (if needed)</label>
                    <p className="sms-field-hint">Upload stamped or signed invoice when the salesperson brings a physical copy</p>
                    <input
                      type="file"
                      accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*"
                      onChange={(e) => setInvoiceDocument(e.target.files?.[0] ?? null)}
                      className="sms-file-input"
                    />
                    {invoiceDocument && <p className="sms-file-name">{invoiceDocument.name}</p>}
                  </div>
                </div>
              </div>

              <div className="sms-movement-notes px-5">                <label className="sms-field-label">Handover notes</label>
                <input
                  type="text"
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  className="sms-input"
                  placeholder="Salesperson name, vehicle, or collection details..."
                />
              </div>

              <div className="sms-movement-actions px-5 pb-5">
                <button
                  type="submit"
                  disabled={saving}
                  className="sms-btn-primary sms-btn-out"
                >                  {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Truck className="w-4 h-4" />}
                  Confirm goods collected & release stock
                </button>
              </div>
            </>
          )}
        </form>
      )}
    </div>
  );
}
