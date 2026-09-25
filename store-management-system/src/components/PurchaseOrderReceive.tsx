import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ArrowLeft,
  BadgeCheck,
  CloudUpload,
  FileText,
  Loader2,
  Package,
  PackageCheck,
  PackagePlus,
  Search,
  Truck,
  X,
} from 'lucide-react';
import { fetchPurchaseOrder, fetchPurchaseOrders, receivePurchaseOrder } from '../api';
import LineRowActions from './LineRowActions';
import LinkedDocumentsPanel from './LinkedDocumentsPanel';
import PurchaseOrderDetailsModal from './PurchaseOrderDetailsModal';
import StatusPopup from './StatusPopup';
import type { LinkedPaymentVoucher, PurchaseOrderAttachment, PurchaseOrderLine, PurchaseOrderSummary } from '../types';

interface PurchaseOrderReceiveProps {
  warehouseId: number;
  /** When true, accepted quantities go straight into warehouse stock. */
  confirmToStock?: boolean;
  onReceived: () => Promise<void>;
}

function orderKey(order: PurchaseOrderSummary): string {
  return `${order.source}:${order.id}`;
}

function readSelectedPoKeyFromUrl(): string {
  const params = new URLSearchParams(window.location.search);
  const poId = String(params.get('po_id') || params.get('po') || '').trim();
  if (!poId) return '';
  const source = String(params.get('po_source') || 'stocks').trim() || 'stocks';
  return `${source}:${poId}`;
}

function syncSelectedPoUrl(key: string) {
  const params = new URLSearchParams(window.location.search);
  if (!key) {
    params.delete('po_id');
    params.delete('po');
    params.delete('po_source');
  } else {
    const sep = key.indexOf(':');
    const source = sep >= 0 ? key.slice(0, sep) : 'stocks';
    const poId = sep >= 0 ? key.slice(sep + 1) : key;
    if (poId) {
      params.set('po_id', poId);
      params.set('po_source', source || 'stocks');
      params.delete('po');
      if (!params.get('view')) {
        params.set('view', 'receive');
      }
    }
  }
  const qs = params.toString();
  const next = `${window.location.pathname}${qs ? `?${qs}` : ''}${window.location.hash}`;
  const current = `${window.location.pathname}${window.location.search}${window.location.hash}`;
  if (next !== current) {
    window.history.replaceState({}, '', next);
  }
}

function formatDate(iso: string): string {
  if (!iso) return '';
  const d = new Date(iso.includes('T') ? iso : `${iso}T12:00:00`);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function lineReceiveStatus(line: PurchaseOrderLine): string {
  if (line.receiveStatus) return line.receiveStatus;
  if (line.qtyOrdered > 0 && line.qtyRemaining <= 0) return 'Received';
  if (line.qtyReceived > 0 && line.qtyRemaining > 0) return 'Partially received';
  return 'Pending';
}

function receiveStatusClass(status: string): string {
  const key = status.toLowerCase();
  if (key === 'received') return 'sms-receive-status sms-receive-status--received';
  if (key.includes('partial')) return 'sms-receive-status sms-receive-status--partial';
  return 'sms-receive-status sms-receive-status--pending';
}

function LineProductThumb({ line }: { line: PurchaseOrderLine }) {
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
        <Package className="w-5 h-5 text-indigo-400" aria-hidden="true" />
      )}
    </div>
  );
}

export default function PurchaseOrderReceive({
  warehouseId,
  confirmToStock = true,
  onReceived,
}: PurchaseOrderReceiveProps) {
  const [orders, setOrders] = useState<PurchaseOrderSummary[]>([]);
  const [selectedKey, setSelectedKey] = useState(() => readSelectedPoKeyFromUrl());
  const [selectedOrder, setSelectedOrder] = useState<PurchaseOrderSummary | null>(null);
  const [lines, setLines] = useState<PurchaseOrderLine[]>([]);
  const [poAttachments, setPoAttachments] = useState<PurchaseOrderAttachment[]>([]);
  const [linkedVouchers, setLinkedVouchers] = useState<LinkedPaymentVoucher[]>([]);
  const [receiveQty, setReceiveQty] = useState<Record<string, string>>({});
  const [notes, setNotes] = useState('');
  const [attachments, setAttachments] = useState<File[]>([]);
  const [dragOver, setDragOver] = useState(false);
  const [poSearch, setPoSearch] = useState('');
  const [loadingOrders, setLoadingOrders] = useState(true);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [detailsOpen, setDetailsOpen] = useState(false);
  const [statusPopup, setStatusPopup] = useState<{
    title: string;
    message: string;
    tone: 'success' | 'error' | 'info';
  } | null>(null);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const loadOrders = useCallback(async () => {
    setLoadingOrders(true);
    setError(null);
    try {
      const list = await fetchPurchaseOrders();
      setOrders(list);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load purchase orders');
    } finally {
      setLoadingOrders(false);
    }
  }, []);

  useEffect(() => {
    loadOrders();
  }, [loadOrders]);

  const filteredOrders = useMemo(() => {
    const q = poSearch.trim().toLowerCase();
    if (!q) return orders;
    return orders.filter((order) => {
      const hay = [
        order.poNumber,
        order.supplierName,
        order.status,
        order.purchaseType,
        String(order.remainingQty),
      ]
        .join(' ')
        .toLowerCase();
      return hay.includes(q);
    });
  }, [orders, poSearch]);

  const loadOrderDetail = useCallback(async (key: string) => {
    if (!key) {
      setSelectedOrder(null);
      setLines([]);
      setPoAttachments([]);
      setLinkedVouchers([]);
      setReceiveQty({});
      return;
    }

    const sep = key.indexOf(':');
    const source = (sep >= 0 ? key.slice(0, sep) : 'stocks') || 'stocks';
    const poId = sep >= 0 ? key.slice(sep + 1) : key;
    if (!poId) return;

    setLoadingDetail(true);
    setError(null);
    try {
      const data = await fetchPurchaseOrder(poId, source);
      setSelectedOrder(data.order);
      setLines(data.lines);
      setPoAttachments(data.attachments);
      setLinkedVouchers(data.linkedVouchers);
      // Keep deep-link URL on the resolved source (API may fall back stocks↔legacy).
      syncSelectedPoUrl(orderKey(data.order));
      const defaults: Record<string, string> = {};
      for (const line of data.lines) {
        if (line.qtyRemaining > 0) {
          defaults[line.lineId] = String(line.qtyRemaining);
        }
      }
      setReceiveQty(defaults);
    } catch (err) {
      // Drop stale deep-link so the waiting list stays usable.
      setSelectedKey('');
      setSelectedOrder(null);
      setLines([]);
      setPoAttachments([]);
      setLinkedVouchers([]);
      setReceiveQty({});
      setError(
        err instanceof Error
          ? err.message
          : 'Failed to load purchase order'
      );
    } finally {
      setLoadingDetail(false);
    }
  }, []);

  useEffect(() => {
    if (selectedKey) {
      void loadOrderDetail(selectedKey);
    } else {
      setSelectedOrder(null);
      setLines([]);
      setPoAttachments([]);
      setLinkedVouchers([]);
      setReceiveQty({});
    }
  }, [selectedKey, loadOrderDetail]);

  useEffect(() => {
    syncSelectedPoUrl(selectedKey);
  }, [selectedKey]);

  const handleReceive = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedOrder) return;

    const payload: Record<string, number> = {};
    for (const [lineId, raw] of Object.entries(receiveQty)) {
      const qty = Number(raw);
      if (qty > 0) payload[lineId] = qty;
    }

    if (Object.keys(payload).length === 0) {
      setStatusPopup({
        title: 'Nothing to accept',
        message: confirmToStock
          ? 'Enter at least one quantity to accept into stock.'
          : 'Enter at least one quantity to record as delivered.',
        tone: 'info',
      });
      return;
    }

    setSaving(true);
    try {
      const result = await receivePurchaseOrder(warehouseId, {
        poId: selectedOrder.id,
        source: selectedOrder.source,
        receiveQty: payload,
        notes: notes.trim(),
        attachments,
        confirmToStock,
      });
      setSelectedKey('');
      setSelectedOrder(null);
      setLines([]);
      setPoAttachments([]);
      setLinkedVouchers([]);
      setReceiveQty({});
      setNotes('');
      setAttachments([]);
      setPoSearch('');
      setStatusPopup({
        title: confirmToStock ? 'Accepted into stock' : 'Delivery recorded',
        message: result.message,
        tone: 'success',
      });
      await loadOrders();
      if (confirmToStock) {
        await onReceived();
      }
    } catch (err) {
      setStatusPopup({
        title: confirmToStock ? 'Could not accept into stock' : 'Could not record delivery',
        message: err instanceof Error ? err.message : 'Failed to receive purchase order',
        tone: 'error',
      });
    } finally {
      setSaving(false);
    }
  };

  const clearSelection = () => {
    setSelectedKey('');
    setSelectedOrder(null);
    setLines([]);
    setReceiveQty({});
    setNotes('');
    setPoSearch('');
    setDetailsOpen(false);
  };

  const addAttachmentFiles = useCallback((fileList: FileList | File[] | null) => {
    const next = Array.from(fileList ?? []);
    if (next.length === 0) return;
    setAttachments((prev) => {
      const merged = [...prev];
      for (const file of next) {
        const exists = merged.some((f) => f.name === file.name && f.size === file.size && f.lastModified === file.lastModified);
        if (!exists) merged.push(file);
      }
      return merged;
    });
  }, []);

  const removeAttachment = (index: number) => {
    setAttachments((prev) => prev.filter((_, i) => i !== index));
  };

  return (
    <div className="sms-incoming-layout">
      {error && <div className="sms-alert sms-alert-error">{error}</div>}

      <form onSubmit={handleReceive} className="sms-incoming-stack">
          {!selectedOrder && !loadingDetail ? (
            <section className="sms-receive-card sms-receive-card--list">
            <div className="sms-po-open-list">
              <div className="sms-po-open-list-head">
                <div className="sms-po-open-list-title-row">
                  <div className="font-semibold text-slate-800">
                    {loadingOrders
                      ? 'Loading purchase orders…'
                      : `${filteredOrders.length} purchase order${filteredOrders.length === 1 ? '' : 's'} waiting`}
                  </div>
                  <div className="sms-incoming-search sms-incoming-search--inline">
                    <Search className="sms-incoming-search-icon" aria-hidden="true" />
                    <input
                      type="search"
                      className="sms-incoming-search-input"
                      placeholder="Search PO number or supplier..."
                      value={poSearch}
                      onChange={(e) => setPoSearch(e.target.value)}
                      aria-label="Search purchase orders"
                    />
                  </div>
                </div>
                <p className="text-sm text-slate-500 mt-1">
                  {confirmToStock
                    ? 'Select a PO below to review products and accept or reject quantities into stock.'
                    : 'Select a PO below to record supplier delivery for the store.'}
                </p>
              </div>
              {loadingOrders ? (
                <div className="sms-incoming-empty">
                  <Loader2 className="w-5 h-5 animate-spin text-indigo-500" />
                </div>
              ) : filteredOrders.length === 0 ? (
                <div className="sms-incoming-empty sms-incoming-empty--tall">
                  <PackageCheck className="w-10 h-10 text-slate-300 mb-2" />
                  <div className="font-semibold text-slate-700">No open purchase orders</div>
                  <p className="text-sm text-slate-500 mt-1">
                    When procurement creates receivable purchase orders, they will appear here.
                  </p>
                </div>
              ) : (
                <div className="sms-po-open-table-wrap">
                  <table className="sms-po-open-table">
                    <thead>
                      <tr>
                        <th scope="col">PO number</th>
                        <th scope="col">Supplier</th>
                        <th scope="col">Status</th>
                        <th scope="col" className="sms-po-open-table-num">
                          Units pending
                        </th>
                        <th scope="col">Created</th>
                        <th scope="col" className="sms-po-open-table-action">
                          <span className="sr-only">Open</span>
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {filteredOrders.map((order) => {
                        const key = orderKey(order);
                        const status =
                          order.receiveStatus ||
                          (order.receivedQty && order.receivedQty > 0
                            ? 'Partially received'
                            : 'Pending');
                        return (
                          <tr
                            key={key}
                            className="sms-po-open-table-row"
                            onClick={() => setSelectedKey(key)}
                          >
                            <td className="sms-po-open-table-po">
                              {order.poNumber || `PO #${order.id}`}
                            </td>
                            <td>{order.supplierName || 'Unknown supplier'}</td>
                            <td>
                              <span className={receiveStatusClass(status)}>{status}</span>
                            </td>
                            <td className="sms-po-open-table-num">
                              {Number(order.remainingQty || 0).toLocaleString()}
                            </td>
                            <td className="sms-po-open-table-date">
                              {order.createdAt ? formatDate(order.createdAt) : '—'}
                            </td>
                            <td className="sms-po-open-table-action">
                              <button
                                type="button"
                                className="sms-desk-btn sms-desk-btn-secondary sms-btn-rounded sms-po-open-table-btn"
                                onClick={(e) => {
                                  e.stopPropagation();
                                  setSelectedKey(key);
                                }}
                              >
                                Open
                              </button>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
            </section>
          ) : (
            <>
              {selectedOrder && (
                <div className="sms-receive-meta-bar" aria-label="Purchase order summary">
                  <span className="sms-receive-meta-chip sms-receive-meta-chip--ok">
                    <BadgeCheck className="w-3.5 h-3.5" aria-hidden="true" />
                    {selectedOrder.receiveStatus || 'Pending'}
                  </span>
                  <span className="sms-receive-meta-chip sms-receive-meta-chip--po">
                    <Truck className="w-3.5 h-3.5" aria-hidden="true" />
                    PO: {selectedOrder.poNumber || `#${selectedOrder.id}`}
                  </span>
                  <span className="sms-receive-meta-chip sms-receive-meta-chip--qty">
                    <PackageCheck className="w-3.5 h-3.5" aria-hidden="true" />
                    {selectedOrder.lineCount} item{selectedOrder.lineCount === 1 ? '' : 's'} ·{' '}
                    {selectedOrder.remainingQty} remaining
                  </span>
                </div>
              )}

              {loadingDetail ? (
                <section className="sms-receive-card">
                  <div className="sms-incoming-empty">
                    <Loader2 className="w-5 h-5 animate-spin text-indigo-500" />
                    Loading PO lines…
                  </div>
                </section>
              ) : lines.length > 0 ? (
                <>
                  <section className="sms-receive-card">
                    <header className="sms-receive-card-head">
                      <span className="sms-receive-card-icon" aria-hidden="true">
                        <FileText className="w-4 h-4" />
                      </span>
                      <h3 className="sms-receive-card-title">Receive Information</h3>
                    </header>

                    <div className="sms-table-wrap sms-incoming-lines sms-incoming-lines--flush">
                      <table className="sms-table sms-inventory-table">
                        <thead>
                          <tr>
                            <th className="sms-col-image">Image</th>
                            <th>Product</th>
                            <th className="text-center">Ordered</th>
                            <th className="text-center">Delivered</th>
                            <th className="text-center">Remaining</th>
                            <th>Status</th>
                            <th className="text-center">Deliver now</th>
                            <th className="text-right sms-col-actions">Actions</th>
                          </tr>
                        </thead>
                        <tbody>
                          {lines.map((line) => {
                            const status = lineReceiveStatus(line);
                            return (
                              <tr key={line.lineId}>
                                <td className="sms-col-image">
                                  <LineProductThumb line={line} />
                                </td>
                                <td>
                                  <div className="font-semibold text-slate-900">{line.productName}</div>
                                  <div className="sms-product-meta">
                                    <span className="sms-sku">{line.productSku || '—'}</span>
                                  </div>
                                </td>
                                <td className="text-center font-mono">{line.qtyOrdered}</td>
                                <td className="text-center font-mono text-slate-500">{line.qtyReceived}</td>
                                <td className="text-center font-mono font-semibold text-emerald-600">
                                  {line.qtyRemaining}
                                </td>
                                <td>
                                  <span className={receiveStatusClass(status)}>{status}</span>
                                </td>
                                <td className="text-center">
                                  <input
                                    type="number"
                                    min="0"
                                    max={line.qtyRemaining}
                                    value={receiveQty[line.lineId] ?? ''}
                                    disabled={line.qtyRemaining <= 0}
                                    onChange={(e) =>
                                      setReceiveQty((prev) => ({
                                        ...prev,
                                        [line.lineId]: e.target.value,
                                      }))
                                    }
                                    className="sms-input sms-po-qty-input"
                                  />
                                </td>
                                <td className="text-right sms-col-actions">
                                  <LineRowActions onView={() => setDetailsOpen(true)} />
                                </td>
                              </tr>
                            );
                          })}
                        </tbody>
                      </table>
                    </div>
                  </section>

                  <LinkedDocumentsPanel
                    linkedVouchers={linkedVouchers}
                    poAttachments={poAttachments}
                  />

                  <section className="sms-receive-card">
                    <header className="sms-receive-card-head">
                      <span className="sms-receive-card-icon" aria-hidden="true">
                        <Package className="w-4 h-4" />
                      </span>
                      <h3 className="sms-receive-card-title">Receive Items</h3>
                    </header>

                    <div className="sms-incoming-footer-fields">
                      <div className="sms-incoming-notes">
                        <label className="sms-field-label" htmlFor="sms-receipt-notes">
                          Received Notes
                        </label>
                        <textarea
                          id="sms-receipt-notes"
                          value={notes}
                          onChange={(e) => setNotes(e.target.value)}
                          className="sms-input sms-incoming-notes-area"
                          rows={2}
                          placeholder="e.g. Delivery note, reference, condition…"
                        />
                      </div>

                      <div className="sms-incoming-attachments">
                        <label className="sms-field-label" htmlFor="sms-delivery-attachments">
                          Attach File (Optional)
                        </label>
                        <p className="sms-incoming-attach-hint">PDF, JPG, PNG, or DOC up to 10MB</p>
                        <input
                          ref={fileInputRef}
                          id="sms-delivery-attachments"
                          type="file"
                          accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,application/pdf,image/*"
                          multiple
                          className="sms-sr-only"
                          onChange={(e) => {
                            addAttachmentFiles(e.target.files);
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
                            addAttachmentFiles(e.dataTransfer.files);
                          }}
                        >
                          <CloudUpload className="sms-dropzone-icon" aria-hidden="true" />
                          <span className="sms-dropzone-title">
                            Click to browse or drag &amp; drop file here
                          </span>
                        </button>
                        {attachments.length > 0 && (
                          <ul className="sms-dropzone-files">
                            {attachments.map((f, index) => (
                              <li key={`${f.name}-${f.size}-${f.lastModified}`}>
                                <span className="sms-dropzone-file-name">{f.name}</span>
                                <button
                                  type="button"
                                  className="sms-dropzone-remove"
                                  aria-label={`Remove ${f.name}`}
                                  onClick={() => removeAttachment(index)}
                                >
                                  <X className="w-3.5 h-3.5" />
                                </button>
                              </li>
                            ))}
                          </ul>
                        )}
                      </div>
                    </div>
                  </section>

                  <div className="sms-incoming-footer-actions sms-incoming-footer-actions--outside">
                    <button
                      type="button"
                      className="sms-desk-btn sms-desk-btn-secondary sms-btn-rounded"
                      onClick={() => setSelectedKey('')}
                    >
                      <ArrowLeft className="w-4 h-4" />
                      Back
                    </button>
                    <button type="submit" disabled={saving} className="sms-btn-primary sms-btn-rounded">
                      {saving ? (
                        <Loader2 className="w-4 h-4 animate-spin" />
                      ) : confirmToStock ? (
                        <PackagePlus className="w-4 h-4" />
                      ) : (
                        <Truck className="w-4 h-4" />
                      )}
                      {confirmToStock ? 'Accept into Stock' : 'Record delivery for store'}
                    </button>
                  </div>
                </>
              ) : (
                <section className="sms-receive-card">
                  <div className="sms-incoming-empty">
                    This purchase order has no remaining lines to receive.
                  </div>
                </section>
              )}
            </>
          )}
      </form>

      {detailsOpen && selectedOrder && (
        <PurchaseOrderDetailsModal
          order={selectedOrder}
          lines={lines}
          attachments={poAttachments}
          linkedVouchers={linkedVouchers}
          onClose={() => setDetailsOpen(false)}
        />
      )}

      {statusPopup && (
        <StatusPopup
          title={statusPopup.title}
          message={statusPopup.message}
          tone={statusPopup.tone}
          confirmLabel={statusPopup.tone === 'success' ? 'Done' : 'OK'}
          onClose={() => {
            const wasSuccess = statusPopup.tone === 'success';
            setStatusPopup(null);
            if (wasSuccess) {
              void onReceived();
            }
          }}
        />
      )}
    </div>
  );
}
