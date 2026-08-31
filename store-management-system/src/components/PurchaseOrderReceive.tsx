import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  BadgeCheck,
  CheckCircle2,
  ChevronDown,
  ClipboardList,
  CloudUpload,
  Loader2,
  Package,
  PackageCheck,
  PackageOpen,
  Paperclip,
  Search,
  Truck,
  X,
} from 'lucide-react';
import { fetchPurchaseOrder, fetchPurchaseOrders, receivePurchaseOrder } from '../api';
import LineRowActions from './LineRowActions';
import PurchaseOrderDetailsModal from './PurchaseOrderDetailsModal';
import StatusPopup from './StatusPopup';
import type { PurchaseOrderAttachment, PurchaseOrderLine, PurchaseOrderSummary } from '../types';

interface PurchaseOrderReceiveProps {
  warehouseId: number;
  onReceived: () => Promise<void>;
}

function orderKey(order: PurchaseOrderSummary): string {
  return `${order.source}:${order.id}`;
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

export default function PurchaseOrderReceive({ warehouseId, onReceived }: PurchaseOrderReceiveProps) {
  const [orders, setOrders] = useState<PurchaseOrderSummary[]>([]);
  const [selectedKey, setSelectedKey] = useState('');
  const [selectedOrder, setSelectedOrder] = useState<PurchaseOrderSummary | null>(null);
  const [lines, setLines] = useState<PurchaseOrderLine[]>([]);
  const [poAttachments, setPoAttachments] = useState<PurchaseOrderAttachment[]>([]);
  const [receiveQty, setReceiveQty] = useState<Record<string, string>>({});
  const [notes, setNotes] = useState('');
  const [attachments, setAttachments] = useState<File[]>([]);
  const [dragOver, setDragOver] = useState(false);
  const [poSearch, setPoSearch] = useState('');
  const [dropdownOpen, setDropdownOpen] = useState(false);
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
  const dropdownRef = useRef<HTMLDivElement | null>(null);
  const searchInputRef = useRef<HTMLInputElement | null>(null);
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

  useEffect(() => {
    if (!dropdownOpen) return undefined;

    const onPointerDown = (event: MouseEvent) => {
      if (!dropdownRef.current?.contains(event.target as Node)) {
        setDropdownOpen(false);
      }
    };
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setDropdownOpen(false);
    };

    document.addEventListener('mousedown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    const focusTimer = window.setTimeout(() => searchInputRef.current?.focus(), 50);

    return () => {
      document.removeEventListener('mousedown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
      window.clearTimeout(focusTimer);
    };
  }, [dropdownOpen]);

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

  const loadOrderDetail = useCallback(
    async (key: string) => {
      if (!key) {
        setSelectedOrder(null);
        setLines([]);
        setPoAttachments([]);
        setReceiveQty({});
        return;
      }

      const order = orders.find((o) => orderKey(o) === key);
      if (!order) return;

      setLoadingDetail(true);
      setError(null);
      try {
        const data = await fetchPurchaseOrder(order.id, order.source);
        setSelectedOrder(data.order);
        setLines(data.lines);
        setPoAttachments(data.attachments);
        const defaults: Record<string, string> = {};
        for (const line of data.lines) {
          if (line.qtyRemaining > 0) {
            defaults[line.lineId] = String(line.qtyRemaining);
          }
        }
        setReceiveQty(defaults);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load purchase order');
        setSelectedOrder(null);
        setLines([]);
        setPoAttachments([]);
        setReceiveQty({});
      } finally {
        setLoadingDetail(false);
      }
    },
    [orders]
  );

  useEffect(() => {
    if (selectedKey) {
      loadOrderDetail(selectedKey);
    }
  }, [selectedKey, loadOrderDetail]);

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
        title: 'Nothing to record',
        message: 'Enter at least one quantity to record as delivered.',
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
      });
      setSelectedKey('');
      setSelectedOrder(null);
      setLines([]);
      setPoAttachments([]);
      setReceiveQty({});
      setNotes('');
      setAttachments([]);
      setPoSearch('');
      setDropdownOpen(false);
      setStatusPopup({
        title: 'Delivery recorded',
        message: result.message,
        tone: 'success',
      });
      await loadOrders();
    } catch (err) {
      setStatusPopup({
        title: 'Could not record delivery',
        message: err instanceof Error ? err.message : 'Failed to receive purchase order',
        tone: 'error',
      });
    } finally {
      setSaving(false);
    }
  };

  const fillAllRemaining = () => {
    const next: Record<string, string> = {};
    for (const line of lines) {
      if (line.qtyRemaining > 0) {
        next[line.lineId] = String(line.qtyRemaining);
      }
    }
    setReceiveQty(next);
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

  const selectOrder = (key: string) => {
    setSelectedKey(key);
    setDropdownOpen(false);
    setPoSearch('');
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
      <div className="sms-incoming-copy">
        <h3 className="sms-incoming-title">
          <ClipboardList className="w-5 h-5 text-indigo-600" />
          Record delivery from purchase order
        </h3>
        <p className="sms-incoming-sub">
          Record what the supplier delivered. Stock is not added yet — the store manager confirms quantities into this warehouse.
        </p>
      </div>

      {error && <div className="sms-alert sms-alert-error">{error}</div>}

      <form onSubmit={handleReceive} className="sms-incoming-stack">
        <div className="sms-incoming-field">
          <label className="sms-field-label" htmlFor="sms-po-dropdown-trigger">
            Purchase order *
          </label>

          <div
            className={`sms-po-dropdown${dropdownOpen ? ' is-open' : ''}`}
            ref={dropdownRef}
          >
            <button
              id="sms-po-dropdown-trigger"
              type="button"
              className="sms-po-dropdown-trigger"
              aria-haspopup="listbox"
              aria-expanded={dropdownOpen}
              disabled={loadingOrders}
              onClick={() => setDropdownOpen((open) => !open)}
            >
              <span className="sms-po-dropdown-trigger-main">
                {loadingOrders ? (
                  <span className="sms-po-dropdown-placeholder">Loading purchase orders…</span>
                ) : selectedOrder ? (
                  <>
                    <span className="sms-po-picker-ref">
                      {selectedOrder.poNumber || `PO #${selectedOrder.id}`}
                    </span>
                    <span className="sms-po-dropdown-trigger-sub">
                      {selectedOrder.supplierName} · {selectedOrder.remainingQty} units pending
                    </span>
                  </>
                ) : (
                  <span className="sms-po-dropdown-placeholder">Select purchase order...</span>
                )}
              </span>
              <span className="sms-po-dropdown-trigger-aside">
                {selectedOrder && !dropdownOpen && (
                  <span
                    className="sms-po-dropdown-clear"
                    role="button"
                    tabIndex={0}
                    aria-label="Clear selected purchase order"
                    onClick={(e) => {
                      e.stopPropagation();
                      clearSelection();
                    }}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        e.stopPropagation();
                        clearSelection();
                      }
                    }}
                  >
                    <X className="w-3.5 h-3.5" />
                  </span>
                )}
                <ChevronDown className={`sms-po-dropdown-chevron${dropdownOpen ? ' is-open' : ''}`} />
              </span>
            </button>

            {dropdownOpen && (
              <div className="sms-po-dropdown-menu" role="listbox" aria-label="Purchase orders">
                <div className="sms-incoming-search sms-incoming-search--dropdown">
                  <Search className="sms-incoming-search-icon" aria-hidden="true" />
                  <input
                    ref={searchInputRef}
                    type="search"
                    className="sms-incoming-search-input"
                    placeholder="Search PO number or supplier..."
                    value={poSearch}
                    onChange={(e) => setPoSearch(e.target.value)}
                    aria-label="Search purchase orders"
                  />
                </div>

                <div className="sms-po-dropdown-meta">
                  {filteredOrders.length} of {orders.length} waiting to receive
                </div>

                {filteredOrders.length === 0 ? (
                  <div className="sms-incoming-empty sms-incoming-empty--menu">
                    No purchase orders match this search.
                  </div>
                ) : (
                  <div className="sms-po-picker sms-po-picker--dropdown">
                    {filteredOrders.map((order) => {
                      const key = orderKey(order);
                      const active = selectedKey === key;
                      return (
                        <button
                          key={key}
                          type="button"
                          role="option"
                          aria-selected={active}
                          className={`sms-po-picker-item${active ? ' is-active' : ''}`}
                          onClick={() => selectOrder(key)}
                        >
                          <div className="sms-po-picker-top">
                            <span className="sms-po-picker-ref">
                              {order.poNumber || `PO #${order.id}`}
                            </span>
                            {active && <CheckCircle2 className="w-4 h-4 text-indigo-600 shrink-0" />}
                          </div>
                          <div className="sms-po-picker-supplier">
                            {order.supplierName || 'Unknown supplier'}
                          </div>
                          <div className="sms-po-picker-meta">
                            <span
                              className={receiveStatusClass(
                                order.receiveStatus || (order.receivedQty && order.receivedQty > 0
                                  ? 'Partially received'
                                  : 'Pending')
                              )}
                            >
                              {order.receiveStatus ||
                                (order.receivedQty && order.receivedQty > 0
                                  ? 'Partially received'
                                  : 'Pending')}
                            </span>
                            <span>{order.remainingQty} units pending</span>
                            {order.createdAt ? <span>{formatDate(order.createdAt)}</span> : null}
                          </div>
                        </button>
                      );
                    })}
                  </div>
                )}
              </div>
            )}
          </div>
        </div>

        <section className="sms-incoming-panel sms-incoming-panel--detail sms-incoming-panel--full">
          {!selectedOrder && !loadingDetail ? (
            <div className="sms-incoming-empty sms-incoming-empty--tall">
              <PackageCheck className="w-10 h-10 text-slate-300 mb-2" />
              <div className="font-semibold text-slate-700">Select a purchase order</div>
              <p className="text-sm text-slate-500 mt-1">
                Click the dropdown above to choose a PO and load receive lines.
              </p>
            </div>
          ) : (
            <>
              {selectedOrder && (
                <div className="sms-incoming-selected">
                  <div className="sms-incoming-selected-main">
                    <div className="sms-po-details-hero-icon sms-po-details-hero-icon--violet">
                      <ClipboardList className="w-5 h-5" />
                    </div>
                    <div>
                      <div className="sms-po-picker-ref">
                        {selectedOrder.poNumber || `PO #${selectedOrder.id}`}
                      </div>
                      <div className="sms-po-picker-supplier">
                        <Truck className="w-3.5 h-3.5 inline text-indigo-500 mr-1" />
                        {selectedOrder.supplierName || 'Unknown supplier'}
                      </div>
                    </div>
                  </div>
                  <div className="sms-incoming-selected-meta">
                    <div className="sms-incoming-chip">
                      <span className="sms-po-details-icon sms-po-details-icon--emerald">
                        <BadgeCheck className="w-3.5 h-3.5" />
                      </span>
                      <span
                        className={receiveStatusClass(
                          selectedOrder.receiveStatus || 'Pending'
                        )}
                      >
                        {selectedOrder.receiveStatus || 'Pending'}
                      </span>
                    </div>
                    <div className="sms-incoming-chip">
                      <span className="sms-po-details-icon sms-po-details-icon--sky">
                        <PackageOpen className="w-3.5 h-3.5" />
                      </span>
                      <span className="sms-po-pill">{selectedOrder.purchaseType || 'stock'}</span>
                    </div>
                    <div className="sms-incoming-chip sms-incoming-chip--plain">
                      <span className="sms-po-details-icon sms-po-details-icon--amber">
                        <PackageCheck className="w-3.5 h-3.5" />
                      </span>
                      <span className="text-xs text-slate-600 font-semibold">
                        {selectedOrder.lineCount} line{selectedOrder.lineCount === 1 ? '' : 's'} ·{' '}
                        {selectedOrder.remainingQty} remaining
                      </span>
                    </div>
                  </div>
                </div>
              )}

              {loadingDetail ? (
                <div className="sms-incoming-empty">
                  <Loader2 className="w-5 h-5 animate-spin text-indigo-500" />
                  Loading PO lines…
                </div>
              ) : lines.length > 0 ? (
                <>
                  <div className="sms-po-lines-toolbar">
                    <span className="text-sm font-semibold text-slate-700 flex items-center gap-2">
                      <span className="sms-po-details-icon sms-po-details-icon--indigo">
                        <Package className="w-3.5 h-3.5" />
                      </span>
                      Line items delivered
                    </span>
                    <div className="sms-po-lines-toolbar-actions">
                      <button
                        type="button"
                        onClick={fillAllRemaining}
                        className="sms-desk-btn sms-desk-btn-secondary sms-desk-btn-sm"
                      >
                        Fill all remaining
                      </button>
                    </div>
                  </div>

                  <div className="sms-table-wrap sms-incoming-lines">
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
                                  setReceiveQty((prev) => ({ ...prev, [line.lineId]: e.target.value }))
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

                  <div className="sms-incoming-footer">
                    <div className="sms-incoming-footer-fields">
                      <div className="sms-incoming-notes">
                        <label className="sms-field-label" htmlFor="sms-receipt-notes">
                          Receipt notes
                        </label>
                        <input
                          id="sms-receipt-notes"
                          type="text"
                          value={notes}
                          onChange={(e) => setNotes(e.target.value)}
                          className="sms-input"
                          placeholder="Delivery note, GRN reference, condition…"
                        />
                      </div>

                      <div className="sms-incoming-attachments">
                        <label className="sms-field-label">
                          <Paperclip className="w-3.5 h-3.5 inline mr-1" />
                          Delivery attachments
                        </label>
                        <input
                          ref={fileInputRef}
                          id="sms-delivery-attachments"
                          type="file"
                          accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*"
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
                          <span className="sms-dropzone-title">Upload a file</span>
                          <span className="sms-dropzone-sub">Click to browse, or drag &amp; drop files here</span>
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

                    {poAttachments.length > 0 && (
                      <div className="sms-incoming-linked-bar">
                        <span className="sms-incoming-linked-label">
                          <Paperclip className="w-3.5 h-3.5" />
                          On this PO
                        </span>
                        <div className="sms-incoming-linked-chips">
                          {poAttachments.map((file) => (
                            <a
                              key={file.id}
                              href={file.url}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="sms-incoming-file-chip"
                            >
                              {file.name || 'Attachment'}
                              {file.kind === 'invoice' ? (
                                <span className="sms-po-pill">Invoice</span>
                              ) : null}
                            </a>
                          ))}
                        </div>
                      </div>
                    )}

                    <div className="sms-incoming-footer-actions">
                      <p className="sms-incoming-footer-hint">
                        Stock stays pending until the store manager confirms.
                      </p>
                      <button type="submit" disabled={saving} className="sms-btn-primary sms-btn-rounded">
                        {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Truck className="w-4 h-4" />}
                        Record delivery for store
                      </button>
                    </div>
                  </div>
                </>
              ) : (
                <div className="sms-incoming-empty">
                  This purchase order has no remaining lines to receive.
                </div>
              )}
            </>
          )}
        </section>
      </form>

      {detailsOpen && selectedOrder && (
        <PurchaseOrderDetailsModal
          order={selectedOrder}
          lines={lines}
          attachments={poAttachments}
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
